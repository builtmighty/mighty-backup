<?php
/**
 * Tests for the chunked-mysqldump big-table state machine.
 *
 * Covers init_big_table_state's branches without invoking exec(): numeric
 * cursor (PK or UNIQUE / auto_increment), non-numeric cursor, no-cursor
 * (singleshot_full mode), and the empty-table skip. Range-dump invocation
 * itself (dump_table_range_via_mysqldump) needs a real mysql server to verify
 * and is left for integration testing.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class DatabaseExporterRangedDumpTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when( 'get_site_option' )->justReturn( [] );
        Functions\when( 'update_site_option' )->justReturn( true );
        Functions\when( 'delete_site_option' )->justReturn( true );
        Functions\when( 'do_action' )->justReturn( null );
        Functions\when( 'apply_filters' )->returnArg( 2 );
        // Reset the Log_Stream static buffer so prior tests don't bleed in.
        $buf = new \ReflectionProperty( 'Mighty_Backup_Log_Stream', 'buffer' );
        $buf->setAccessible( true );
        $buf->setValue( null, [] );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function invoke( object $instance, string $method, array $args = [] ) {
        $ref = new \ReflectionMethod( $instance, $method );
        $ref->setAccessible( true );
        return $ref->invokeArgs( $instance, $args );
    }

    /**
     * Build a Mighty_Backup_Database_Exporter subclass whose cursor and
     * bounds lookups return canned values so init_big_table_state can be
     * exercised without a DB. Stubs get_table_cursor_column directly
     * (rather than the underlying get_primary_key) because the cursor
     * helper is what init_big_table_state actually calls.
     *
     * @param array<string, ?array{column: string, numeric: bool, source: string}> $cursor_map
     * @param array<string, array{min: mixed, max: mixed}>                         $bounds_map
     */
    private function make_canned_exporter( array $cursor_map, array $bounds_map ): Mighty_Backup_Database_Exporter {
        return new class( $cursor_map, $bounds_map ) extends Mighty_Backup_Database_Exporter {
            public function __construct(
                private array $cursor_map,
                private array $bounds_map
            ) {
                parent::__construct();
            }
            public function get_table_cursor_column( string $table ): ?array {
                return $this->cursor_map[ $table ] ?? null;
            }
            public function get_pk_bounds( string $table, string $pk_column ): array {
                return $this->bounds_map[ $table ] ?? [ 'min' => null, 'max' => null ];
            }
        };
    }

    public function test_init_big_table_state_numeric_pk(): void {
        $exporter = $this->make_canned_exporter(
            [ 'wp_postmeta' => [ 'column' => 'meta_id', 'numeric' => true, 'source' => 'pk' ] ],
            [ 'wp_postmeta' => [ 'min' => 1, 'max' => 5_000_000 ] ]
        );

        $manager = new Mighty_Backup_Manager();
        $result  = $this->invoke( $manager, 'init_big_table_state', [
            $exporter, '/tmp/doesnt-matter.sql', [ 'wp_postmeta' ], 0,
        ] );

        $this->assertSame( 0, $result['index'] );
        $this->assertSame( 'range', $result['mode'] );
        $this->assertSame( 'meta_id', $result['pk'] );
        $this->assertTrue( $result['numeric'] );
        $this->assertSame( 'pk', $result['source'] );
        $this->assertSame( 5_000_000, $result['max_pk'] );
        // last_pk is min-1 so first `WHERE cursor > last_pk` includes the min row.
        $this->assertSame( 0, $result['last_pk'] );
        $this->assertGreaterThanOrEqual( 100000, $result['range_size'] );
    }

    public function test_init_big_table_state_unique_cursor_when_no_pk(): void {
        // "UNIQUE NOT NULL" virtual cursor case — the common WC-extension pattern.
        $exporter = $this->make_canned_exporter(
            [ 'wc_admin_notes' => [ 'column' => 'note_id', 'numeric' => true, 'source' => 'unique' ] ],
            [ 'wc_admin_notes' => [ 'min' => 1, 'max' => 10_000 ] ]
        );

        $manager = new Mighty_Backup_Manager();
        $result  = $this->invoke( $manager, 'init_big_table_state', [
            $exporter, '/tmp/doesnt-matter.sql', [ 'wc_admin_notes' ], 0,
        ] );

        // This previously got skipped-with-warning. Now range-chunked via UNIQUE.
        $this->assertSame( 0, $result['index'] );
        $this->assertSame( 'range', $result['mode'] );
        $this->assertSame( 'unique', $result['source'] );
    }

    public function test_init_big_table_state_falls_through_to_singleshot_when_no_cursor(): void {
        // No PRIMARY KEY, no UNIQUE NOT NULL, no auto_increment.
        // Previously: data SKIPPED with a warning. Now: full single-shot dump.
        $exporter = $this->make_canned_exporter(
            [], // get_table_cursor_column returns null for any table
            []
        );

        $manager = new Mighty_Backup_Manager();
        $result  = $this->invoke( $manager, 'init_big_table_state', [
            $exporter, '/tmp/doesnt-matter.sql', [ 'mu_custom_audit' ], 0,
        ] );

        $this->assertSame( 0, $result['index'] );
        $this->assertSame( 'singleshot_full', $result['mode'] );
        $this->assertNull( $result['pk'] );
        $this->assertNull( $result['max_pk'] );
    }

    public function test_init_big_table_state_non_numeric_cursor_takes_range_mode(): void {
        // VARCHAR / UUID cursor — used to skip data; now takes range mode
        // with the next-upper-bound computed by next_cursor_upper_bound().
        $exporter = $this->make_canned_exporter(
            [ 'wp_uuid_table' => [ 'column' => 'uuid', 'numeric' => false, 'source' => 'pk' ] ],
            [ 'wp_uuid_table' => [ 'min' => 'aaa-111', 'max' => 'zzz-999' ] ]
        );

        $manager = new Mighty_Backup_Manager();
        $result  = $this->invoke( $manager, 'init_big_table_state', [
            $exporter, '/tmp/doesnt-matter.sql', [ 'wp_uuid_table' ], 0,
        ] );

        $this->assertSame( 0, $result['index'] );
        $this->assertSame( 'range', $result['mode'] );
        $this->assertFalse( $result['numeric'] );
        $this->assertSame( 'uuid', $result['pk'] );
        $this->assertSame( 'zzz-999', $result['max_pk'] );
        // last_pk is null for string cursors — sentinel meaning "before min".
        $this->assertNull( $result['last_pk'] );
    }

    public function test_init_big_table_state_skips_empty_table(): void {
        $exporter = $this->make_canned_exporter(
            [
                'wp_empty'    => [ 'column' => 'id', 'numeric' => true, 'source' => 'pk' ],
                'wp_postmeta' => [ 'column' => 'meta_id', 'numeric' => true, 'source' => 'pk' ],
            ],
            [
                'wp_empty'    => [ 'min' => null, 'max' => null ],
                'wp_postmeta' => [ 'min' => 1, 'max' => 10 ],
            ]
        );

        $manager = new Mighty_Backup_Manager();
        $result  = $this->invoke( $manager, 'init_big_table_state', [
            $exporter, '/tmp/doesnt-matter.sql', [ 'wp_empty', 'wp_postmeta' ], 0,
        ] );

        $this->assertSame( 1, $result['index'] );
        $this->assertSame( 'meta_id', $result['pk'] );
    }

    public function test_init_big_table_state_signals_completion_when_all_tables_processed(): void {
        $exporter = $this->make_canned_exporter(
            [ 'wp_only' => [ 'column' => 'id', 'numeric' => true, 'source' => 'pk' ] ],
            [ 'wp_only' => [ 'min' => 1, 'max' => 100 ] ]
        );

        $manager = new Mighty_Backup_Manager();
        // Start past the end of the list.
        $result  = $this->invoke( $manager, 'init_big_table_state', [
            $exporter, '/tmp/doesnt-matter.sql', [ 'wp_only' ], 1,
        ] );

        $this->assertSame( 1, $result['index'] );
        $this->assertSame( 'done', $result['mode'] );
        $this->assertNull( $result['pk'] );
    }

    public function test_merge_big_table_state_propagates_mode_and_numeric_flags(): void {
        $manager = new Mighty_Backup_Manager();

        $existing = [
            'method'                    => 'mysqldump_chunked',
            'raw_path'                  => '/tmp/x.sql',
            'big_tables'                => [ 'wp_postmeta', 'mu_custom_audit' ],
            'big_tables_index'          => 0,
            'current_table_mode'        => 'range',
            'current_table_pk'          => 'meta_id',
            'current_table_numeric'     => true,
            'current_table_source'      => 'pk',
            'current_table_max_pk'      => 100,
            'current_table_last_pk'     => 50,
            'current_table_range_size'  => 1000,
            'table_sizes'               => [ 'wp_postmeta' => 9_000_000_000 ],
        ];
        $next = [
            'index'      => 1,
            'mode'       => 'singleshot_full',
            'pk'         => null,
            'numeric'    => false,
            'source'     => null,
            'max_pk'     => null,
            'last_pk'    => null,
            'range_size' => 0,
        ];

        $merged = $this->invoke( $manager, 'merge_big_table_state', [ $existing, $next ] );

        // Mode + cursor fields updated wholesale.
        $this->assertSame( 1, $merged['big_tables_index'] );
        $this->assertSame( 'singleshot_full', $merged['current_table_mode'] );
        $this->assertNull( $merged['current_table_pk'] );
        $this->assertFalse( $merged['current_table_numeric'] );

        // Top-level state preserved.
        $this->assertSame( 'mysqldump_chunked', $merged['method'] );
        $this->assertSame( '/tmp/x.sql', $merged['raw_path'] );
        $this->assertSame( [ 'wp_postmeta', 'mu_custom_audit' ], $merged['big_tables'] );
        $this->assertSame( [ 'wp_postmeta' => 9_000_000_000 ], $merged['table_sizes'] );
    }
}
