<?php
/**
 * Tests for the chunked-mysqldump big-table state machine (deliverable C).
 *
 * Covers init_big_table_state's branches without invoking exec(): numeric PK,
 * non-numeric PK, missing PK, and empty table. Range-dump invocation itself
 * (dump_table_range_via_mysqldump) needs a real mysql server to verify and
 * is left for integration testing.
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
     * Build a Mighty_Backup_Database_Exporter subclass whose PK lookups return
     * canned values so init_big_table_state can be exercised without a DB.
     */
    private function make_canned_exporter( array $pk_map, array $bounds_map ): Mighty_Backup_Database_Exporter {
        return new class( $pk_map, $bounds_map ) extends Mighty_Backup_Database_Exporter {
            public function __construct(
                private array $pk_map,
                private array $bounds_map
            ) {
                parent::__construct();
            }
            public function get_table_primary_key( string $table ): ?string {
                return $this->pk_map[ $table ] ?? null;
            }
            public function get_pk_bounds( string $table, string $pk_column ): array {
                return $this->bounds_map[ $table ] ?? [ 'min' => null, 'max' => null ];
            }
        };
    }

    public function test_init_big_table_state_numeric_pk(): void {
        $exporter = $this->make_canned_exporter(
            [ 'wp_postmeta' => 'meta_id' ],
            [ 'wp_postmeta' => [ 'min' => 1, 'max' => 5_000_000 ] ]
        );

        $manager = new Mighty_Backup_Manager();
        $result  = $this->invoke( $manager, 'init_big_table_state', [
            $exporter,
            '/tmp/doesnt-matter.sql',
            [ 'wp_postmeta' ],
            0,
        ] );

        $this->assertSame( 0, $result['index'] );
        $this->assertSame( 'meta_id', $result['pk'] );
        $this->assertSame( 5_000_000, $result['max_pk'] );
        // last_pk is min-1 so first `WHERE pk > last_pk` includes the min row.
        $this->assertSame( 0, $result['last_pk'] );
        $this->assertGreaterThanOrEqual( 100000, $result['range_size'] );
    }

    public function test_init_big_table_state_skips_table_with_no_pk(): void {
        $exporter = $this->make_canned_exporter(
            // wp_legacy has no entry → get_table_primary_key returns null.
            [ 'wp_postmeta' => 'meta_id' ],
            [ 'wp_postmeta' => [ 'min' => 1, 'max' => 100 ] ]
        );

        $manager = new Mighty_Backup_Manager();
        $result  = $this->invoke( $manager, 'init_big_table_state', [
            $exporter,
            '/tmp/doesnt-matter.sql',
            [ 'wp_legacy_no_pk', 'wp_postmeta' ],
            0,
        ] );

        // Should skip wp_legacy_no_pk and land on wp_postmeta at index 1.
        $this->assertSame( 1, $result['index'] );
        $this->assertSame( 'meta_id', $result['pk'] );
    }

    public function test_init_big_table_state_skips_non_numeric_pk(): void {
        $exporter = $this->make_canned_exporter(
            [
                'wp_uuid_table' => 'uuid',
                'wp_postmeta'   => 'meta_id',
            ],
            [
                // UUID-style PK — non-numeric, can't be range-chunked.
                'wp_uuid_table' => [ 'min' => 'aaa-111', 'max' => 'zzz-999' ],
                'wp_postmeta'   => [ 'min' => 1, 'max' => 50 ],
            ]
        );

        $manager = new Mighty_Backup_Manager();
        $result  = $this->invoke( $manager, 'init_big_table_state', [
            $exporter,
            '/tmp/doesnt-matter.sql',
            [ 'wp_uuid_table', 'wp_postmeta' ],
            0,
        ] );

        $this->assertSame( 1, $result['index'], 'UUID-PK table should be skipped' );
        $this->assertSame( 'meta_id', $result['pk'] );
    }

    public function test_init_big_table_state_skips_empty_table(): void {
        $exporter = $this->make_canned_exporter(
            [
                'wp_empty' => 'id',
                'wp_postmeta' => 'meta_id',
            ],
            [
                'wp_empty'    => [ 'min' => null, 'max' => null ],
                'wp_postmeta' => [ 'min' => 1, 'max' => 10 ],
            ]
        );

        $manager = new Mighty_Backup_Manager();
        $result  = $this->invoke( $manager, 'init_big_table_state', [
            $exporter,
            '/tmp/doesnt-matter.sql',
            [ 'wp_empty', 'wp_postmeta' ],
            0,
        ] );

        $this->assertSame( 1, $result['index'] );
        $this->assertSame( 'meta_id', $result['pk'] );
    }

    public function test_init_big_table_state_signals_completion_when_no_rangeable_tables_left(): void {
        $exporter = $this->make_canned_exporter( [], [] );

        $manager = new Mighty_Backup_Manager();
        $result  = $this->invoke( $manager, 'init_big_table_state', [
            $exporter,
            '/tmp/doesnt-matter.sql',
            [ 'wp_a_no_pk', 'wp_b_no_pk' ],
            0,
        ] );

        // No rangeable tables — index advances past the end, signaling "done".
        $this->assertSame( 2, $result['index'] );
        $this->assertNull( $result['pk'] );
        $this->assertNull( $result['max_pk'] );
    }

    public function test_merge_big_table_state_overwrites_per_table_cursors_only(): void {
        $manager = new Mighty_Backup_Manager();

        $existing = [
            'method'                    => 'mysqldump_chunked',
            'raw_path'                  => '/tmp/x.sql',
            'big_tables'                => [ 'wp_postmeta' ],
            'big_tables_index'          => 0,
            'current_table_pk'          => 'meta_id',
            'current_table_max_pk'      => 100,
            'current_table_last_pk'     => 50,
            'current_table_range_size'  => 1000,
            'table_sizes'               => [ 'wp_postmeta' => 9_000_000_000 ],
        ];
        $next = [
            'index'      => 1,
            'pk'         => null,
            'max_pk'     => null,
            'last_pk'    => null,
            'range_size' => 0,
        ];

        $merged = $this->invoke( $manager, 'merge_big_table_state', [ $existing, $next ] );

        // Per-table cursors updated.
        $this->assertSame( 1, $merged['big_tables_index'] );
        $this->assertNull( $merged['current_table_pk'] );
        $this->assertNull( $merged['current_table_max_pk'] );

        // Unchanged keys are preserved.
        $this->assertSame( 'mysqldump_chunked', $merged['method'] );
        $this->assertSame( '/tmp/x.sql', $merged['raw_path'] );
        $this->assertSame( [ 'wp_postmeta' ], $merged['big_tables'] );
        $this->assertSame( [ 'wp_postmeta' => 9_000_000_000 ], $merged['table_sizes'] );
    }
}
