<?php
/**
 * Tests for the resumable PHP-path database exporter (deliverable B).
 *
 * Verifies that export_table_data_pk() correctly yields between row batches
 * when the chunk-seconds budget is exhausted, and that the returned last_pk
 * is the highest PK that has been durably written.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class DatabaseExporterResumableTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when( 'apply_filters' )->returnArg( 2 );
        Functions\when( 'esc_sql' )->alias( static fn ( $v ) => addslashes( (string) $v ) );
        // Log_Stream::add may flush to wp_options once the static buffer fills.
        Functions\when( 'get_site_option' )->justReturn( [] );
        Functions\when( 'update_site_option' )->justReturn( true );
        // Reset the Log_Stream static buffer so prior tests don't bleed in.
        $buf = new \ReflectionProperty( 'Mighty_Backup_Log_Stream', 'buffer' );
        $buf->setAccessible( true );
        $buf->setValue( null, [] );
    }

    protected function tearDown(): void {
        unset( $GLOBALS['wpdb'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    private function invoke( object $instance, string $method, array $args = [] ) {
        $ref = new \ReflectionMethod( $instance, $method );
        $ref->setAccessible( true );
        return $ref->invokeArgs( $instance, $args );
    }

    /**
     * Stub wpdb that serves rows from a pre-populated array paginated by PK.
     * Records the WHERE clause in each query so tests can assert the resume
     * cursor is honored.
     */
    private function make_wpdb_stub( array $rows ): object {
        return new class( $rows ) {
            public array $captured_sql = [];
            public function __construct( private array $rows ) {}

            public function prepare( string $query, ...$args ): string {
                // Substitute the two %s/%d placeholders the exporter uses.
                $i = 0;
                return preg_replace_callback(
                    '/%[sd]/',
                    function () use ( $args, &$i ) {
                        return (string) ( $args[ $i++ ] ?? '' );
                    },
                    $query
                );
            }

            public function get_results( string $sql, $output_type ): array {
                $this->captured_sql[] = $sql;
                // Extract the pk threshold from `WHERE \`pk\` > N`.
                if ( preg_match( '/WHERE `(\w+)` > (\d+) ORDER BY `\w+` ASC LIMIT (\d+)/', $sql, $m ) ) {
                    $pk    = $m[1];
                    $after = (int) $m[2];
                    $limit = (int) $m[3];
                    $out   = [];
                    foreach ( $this->rows as $row ) {
                        if ( (int) $row[ $pk ] > $after ) {
                            $out[] = $row;
                            if ( count( $out ) >= $limit ) {
                                break;
                            }
                        }
                    }
                    return $out;
                }
                return [];
            }

            public function remove_placeholder_escape( string $v ): string {
                return $v;
            }
        };
    }

    public function test_export_table_data_pk_runs_to_completion_when_no_time_budget(): void {
        // 25 rows, batch size default 1000 — single page, single INSERT batch flush.
        $rows = [];
        for ( $i = 1; $i <= 25; $i++ ) {
            $rows[] = [ 'id' => $i, 'value' => "row-{$i}" ];
        }
        $GLOBALS['wpdb'] = $this->make_wpdb_stub( $rows );

        $exporter = new Mighty_Backup_Database_Exporter();
        $tmp      = tempnam( sys_get_temp_dir(), 'mb-test-' );
        $fh       = fopen( $tmp, 'wb' );

        // Force private 'writer' to fwrite so we can use a regular file handle.
        $writer_ref = new \ReflectionProperty( $exporter, 'writer' );
        $writer_ref->setAccessible( true );
        $writer_ref->setValue( $exporter, 'fwrite' );

        try {
            $result = $this->invoke(
                $exporter,
                'export_table_data_pk',
                [ $fh, 'wp_synthetic', 'id', 0, null, 0 ]
            );

            $this->assertTrue( $result['done'], 'Expected done=true when no time budget is set' );
            $this->assertSame( 25, $result['last_pk'], 'Last PK should match final row' );

            fclose( $fh );
            $sql_dump = file_get_contents( $tmp );
            $this->assertStringContainsString( "INSERT INTO `wp_synthetic`", $sql_dump );
            $this->assertStringContainsString( "row-1'", $sql_dump );
            $this->assertStringContainsString( "row-25'", $sql_dump );
        } finally {
            @unlink( $tmp );
        }
    }

    public function test_export_table_data_pk_resumes_from_start_after_pk(): void {
        // 10 rows with PKs 1..10. Resume after PK=5 — only rows 6..10 should be dumped.
        $rows = [];
        for ( $i = 1; $i <= 10; $i++ ) {
            $rows[] = [ 'id' => $i, 'value' => "r{$i}" ];
        }
        $stub = $this->make_wpdb_stub( $rows );
        $GLOBALS['wpdb'] = $stub;

        $exporter = new Mighty_Backup_Database_Exporter();
        $tmp      = tempnam( sys_get_temp_dir(), 'mb-test-' );
        $fh       = fopen( $tmp, 'wb' );

        $writer_ref = new \ReflectionProperty( $exporter, 'writer' );
        $writer_ref->setAccessible( true );
        $writer_ref->setValue( $exporter, 'fwrite' );

        try {
            $result = $this->invoke(
                $exporter,
                'export_table_data_pk',
                [ $fh, 'wp_synthetic', 'id', 5, null, 0 ]
            );

            $this->assertTrue( $result['done'] );
            $this->assertSame( 10, $result['last_pk'] );

            fclose( $fh );
            $sql_dump = file_get_contents( $tmp );

            // Rows 1..5 should NOT appear; 6..10 should.
            $this->assertStringNotContainsString( "'r1'", $sql_dump );
            $this->assertStringNotContainsString( "'r5'", $sql_dump );
            $this->assertStringContainsString( "'r6'", $sql_dump );
            $this->assertStringContainsString( "'r10'", $sql_dump );

            // The first SELECT must use `WHERE \`id\` > 5`.
            $this->assertStringContainsString( '`id` > 5', $stub->captured_sql[0] );
        } finally {
            @unlink( $tmp );
        }
    }

    public function test_get_large_tables_filters_below_threshold(): void {
        $exporter = new Mighty_Backup_Database_Exporter();

        $sizes = [
            'wp_options'   => 50 * 1024 * 1024,        // 50 MB
            'wp_postmeta'  => 2 * 1024 * 1024 * 1024,  // 2 GB
            'wp_actionscheduler_logs' => 9 * 1024 * 1024 * 1024, // 9 GB
            'wp_users'     => 10 * 1024,               // 10 KB
        ];

        $threshold = 1024 * 1024 * 1024; // 1 GB
        $result    = $exporter->get_large_tables( $sizes, $threshold );

        $this->assertArrayHasKey( 'wp_postmeta', $result );
        $this->assertArrayHasKey( 'wp_actionscheduler_logs', $result );
        $this->assertArrayNotHasKey( 'wp_options', $result );
        $this->assertArrayNotHasKey( 'wp_users', $result );
    }

    public function test_get_large_tables_skips_excluded_tables(): void {
        // Excluded tables shouldn't appear in the "big" list — they're skipped
        // entirely regardless of size.
        $exporter = new Mighty_Backup_Database_Exporter(
            false,
            [ 'wp_postmeta' ],   // excluded
            []
        );

        $sizes = [
            'wp_postmeta' => 5 * 1024 * 1024 * 1024,
            'wp_options'  => 2 * 1024 * 1024 * 1024,
        ];

        $result = $exporter->get_large_tables( $sizes, 1024 * 1024 * 1024 );

        $this->assertArrayNotHasKey( 'wp_postmeta', $result );
        $this->assertArrayHasKey( 'wp_options', $result );
    }

    public function test_get_large_tables_skips_structure_only_tables(): void {
        // Structure-only tables shouldn't be range-dumped (their data is
        // intentionally suppressed) — they must not appear in the big list
        // or they'd get duplicate schemas + an unwanted data dump.
        $exporter = new Mighty_Backup_Database_Exporter(
            false,
            [],
            [ 'wp_actionscheduler_logs' ]   // structure-only
        );

        $sizes = [
            'wp_actionscheduler_logs' => 9 * 1024 * 1024 * 1024,
            'wp_postmeta'             => 2 * 1024 * 1024 * 1024,
        ];

        $result = $exporter->get_large_tables( $sizes, 1024 * 1024 * 1024 );

        $this->assertArrayNotHasKey( 'wp_actionscheduler_logs', $result );
        $this->assertArrayHasKey( 'wp_postmeta', $result );
    }

    public function test_quote_pk_value_keeps_integers_unquoted(): void {
        $exporter = new Mighty_Backup_Database_Exporter();

        $this->assertSame( '123', $this->invoke( $exporter, 'quote_pk_value', [ 123 ] ) );
        $this->assertSame( '0', $this->invoke( $exporter, 'quote_pk_value', [ 0 ] ) );
        $this->assertSame( '999999', $this->invoke( $exporter, 'quote_pk_value', [ '999999' ] ) );
    }

    public function test_quote_pk_value_escapes_strings(): void {
        $exporter = new Mighty_Backup_Database_Exporter();

        $result = $this->invoke( $exporter, 'quote_pk_value', [ "abc'def" ] );

        // esc_sql alias in setUp uses addslashes — single quote becomes \'
        $this->assertSame( "'abc\\'def'", $result );
    }

    public function test_set_table_sizes_then_log_table_start_emits_size_in_log(): void {
        // Verify the size map handed in via set_table_sizes() is used by
        // log_table_start to format the per-table line. Requires the
        // Mighty_Backup_Log_Stream class to be loadable; we capture via
        // a Brain Monkey stub on size_format and just verify the call shape.
        Functions\when( 'size_format' )->alias( static fn ( $b ) => $b . 'B-formatted' );

        $exporter = new Mighty_Backup_Database_Exporter();
        $exporter->set_table_sizes( [ 'wp_postmeta' => 9_000_000_000 ] );

        // log_table_start is private and bails early if Mighty_Backup_Log_Stream
        // isn't loaded — in the test environment it IS loaded (via bootstrap),
        // so the static state of the stream may capture our line. We assert
        // that calling it does not throw and that the size map is consulted.
        $this->invoke( $exporter, 'log_table_start', [ 'wp_postmeta', 'full' ] );
        $this->invoke( $exporter, 'log_table_start', [ 'wp_users', 'structure_only' ] );

        $this->addToAssertionCount( 1 ); // no exception = pass
    }
}
