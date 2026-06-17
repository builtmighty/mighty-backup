<?php
/**
 * Tests for Mighty_Backup_Manager — state management and cancellation.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class BackupManagerTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( 'get_site_option' )->justReturn( null );
        Functions\when( 'update_site_option' )->justReturn( true );
        Functions\when( 'delete_site_option' )->justReturn( true );
        Functions\when( 'as_unschedule_all_actions' )->justReturn( null );
        Functions\when( 'current_time' )->justReturn( '2026-01-01 03:00:00' );
        Functions\when( 'do_action' )->justReturn( null );
        Functions\when( 'apply_filters' )->returnArg( 2 ); // Return the default value.
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_cancel_returns_false_when_no_backup_running(): void {
        $manager = new Mighty_Backup_Manager();
        $this->assertFalse( $manager->cancel() );
    }

    public function test_cancel_returns_false_for_completed_state(): void {
        Functions\when( 'get_site_option' )->justReturn( [
            'status' => 'completed',
        ] );

        $manager = new Mighty_Backup_Manager();
        $this->assertFalse( $manager->cancel() );
    }

    public function test_cancel_returns_false_for_failed_state(): void {
        Functions\when( 'get_site_option' )->justReturn( [
            'status' => 'failed',
        ] );

        $manager = new Mighty_Backup_Manager();
        $this->assertFalse( $manager->cancel() );
    }

    public function test_cancel_returns_true_for_running_backup(): void {
        Functions\when( 'get_site_option' )->justReturn( [
            'status'           => 'running',
            'log_id'           => null,
            'db_local_path'    => null,
            'files_local_path' => null,
        ] );

        $manager = new Mighty_Backup_Manager();
        $this->assertTrue( $manager->cancel() );
    }

    public function test_cancel_returns_true_for_pending_backup(): void {
        Functions\when( 'get_site_option' )->justReturn( [
            'status'           => 'pending',
            'log_id'           => null,
            'db_local_path'    => null,
            'files_local_path' => null,
        ] );

        $manager = new Mighty_Backup_Manager();
        $this->assertTrue( $manager->cancel() );
    }

    public function test_get_status_returns_idle_when_no_state(): void {
        $manager = new Mighty_Backup_Manager();
        $status  = $manager->get_status();

        $this->assertFalse( $status['active'] );
        $this->assertSame( 'idle', $status['status'] );
    }

    public function test_get_status_returns_active_when_running(): void {
        Functions\when( 'get_site_option' )->justReturn( [
            'status'       => 'running',
            'type'         => 'full',
            'trigger'      => 'manual',
            'timestamp'    => '2026-01-01-030000',
            'current_step' => 'export_db',
            'step_index'   => 1,
            'steps'        => [ 'start', 'export_db', 'archive_files', 'upload_db', 'upload_files', 'cleanup' ],
            'db_file_size'    => null,
            'files_file_size' => null,
            'error'        => null,
            'started_at'   => '2026-01-01 03:00:00',
        ] );

        $manager = new Mighty_Backup_Manager();
        $status  = $manager->get_status();

        $this->assertTrue( $status['active'] );
        $this->assertSame( 'running', $status['status'] );
        $this->assertSame( 'export_db', $status['current_step'] );
        $this->assertSame( 'Exporting database', $status['step_label'] );
    }

    public function test_is_running_returns_false_when_no_state(): void {
        $manager = new Mighty_Backup_Manager();
        $this->assertFalse( $manager->is_running() );
    }

    public function test_is_running_returns_false_for_completed_state(): void {
        Functions\when( 'get_site_option' )->justReturn( [ 'status' => 'completed' ] );
        $manager = new Mighty_Backup_Manager();
        $this->assertFalse( $manager->is_running() );
    }

    public function test_is_running_returns_true_when_running(): void {
        Functions\when( 'get_site_option' )->justReturn( [ 'status' => 'running' ] );
        $manager = new Mighty_Backup_Manager();
        $this->assertTrue( $manager->is_running() );
    }

    public function test_get_state_returns_null_when_no_option(): void {
        $manager = new Mighty_Backup_Manager();
        $this->assertNull( $manager->get_state() );
    }

    public function test_clear_state_does_not_throw(): void {
        // clear_state() calls delete_site_option — confirm it completes without error.
        $manager = new Mighty_Backup_Manager();
        $manager->clear_state();
        $this->assertTrue( true ); // Reached without exception.
    }

    // ──────────────────────────────────────────────
    //  Chunked DB export tests
    // ──────────────────────────────────────────────

    public function test_get_status_shows_db_export_sub_progress(): void {
        Functions\when( 'get_site_option' )->justReturn( [
            'status'       => 'running',
            'type'         => 'full',
            'trigger'      => 'manual',
            'timestamp'    => '2026-01-01-030000',
            'current_step' => 'export_db',
            'step_index'   => 1,
            'steps'        => [ 'start', 'export_db', 'archive_files', 'upload_db', 'upload_files', 'cleanup' ],
            'db_file_size'    => null,
            'files_file_size' => null,
            'error'        => null,
            'started_at'   => '2026-01-01 03:00:00',
            'db_export'    => [
                'tables'          => array_fill( 0, 80, 'wp_table' ),
                'tables_exported' => 40,
                'raw_path'        => '/tmp/test.sql',
                'streamlined_config' => null,
            ],
        ] );

        $manager = new Mighty_Backup_Manager();
        $status  = $manager->get_status();

        $this->assertTrue( $status['active'] );
        $this->assertSame( 'export_db', $status['current_step'] );
        $this->assertSame( 'Exporting database (40/80 tables)', $status['step_label'] );

        // Sub-progress should interpolate between step 1 and step 2 boundaries.
        // Step 2/6 = 33%, Step 1/6 = 17%, halfway through tables => ~25%.
        $this->assertGreaterThan( 17, $status['progress'] );
        $this->assertLessThan( 33, $status['progress'] );
    }

    public function test_get_status_normal_when_no_db_export_state(): void {
        Functions\when( 'get_site_option' )->justReturn( [
            'status'       => 'running',
            'type'         => 'full',
            'trigger'      => 'manual',
            'timestamp'    => '2026-01-01-030000',
            'current_step' => 'export_db',
            'step_index'   => 1,
            'steps'        => [ 'start', 'export_db', 'archive_files', 'upload_db', 'upload_files', 'cleanup' ],
            'db_file_size'    => null,
            'files_file_size' => null,
            'error'        => null,
            'started_at'   => '2026-01-01 03:00:00',
        ] );

        $manager = new Mighty_Backup_Manager();
        $status  = $manager->get_status();

        // Without db_export sub-state, uses standard label and progress.
        $this->assertSame( 'Exporting database', $status['step_label'] );
        $this->assertSame( 33, $status['progress'] ); // 2/6 = 33%
    }

    public function test_get_status_handles_mysqldump_chunked_db_export_shape(): void {
        // Regression guard for the 2.13.0 → 2.13.1 hot-fix: the chunked
        // mysqldump path writes db_export with big_tables / big_tables_index
        // instead of tables / tables_exported. get_status() must branch on
        // method or it fatals on count(null) under PHP 8.
        Functions\when( 'get_site_option' )->justReturn( [
            'status'       => 'running',
            'type'         => 'full',
            'trigger'      => 'manual',
            'timestamp'    => '2026-01-01-030000',
            'current_step' => 'export_db',
            'step_index'   => 1,
            'steps'        => [ 'start', 'export_db', 'archive_files', 'upload_db', 'upload_files', 'cleanup' ],
            'db_file_size'    => null,
            'files_file_size' => null,
            'error'        => null,
            'started_at'   => '2026-01-01 03:00:00',
            'db_export'    => [
                'method'                   => 'mysqldump_chunked',
                'raw_path'                 => '/tmp/test.sql',
                'big_tables'               => [ 'wp_postmeta', 'wp_options' ],
                'big_tables_index'         => 1,
                'current_table_pk'         => 'option_id',
                'current_table_max_pk'     => 100000,
                'current_table_last_pk'    => 25000,
                'current_table_range_size' => 50000,
                'table_sizes'              => [ 'wp_postmeta' => 9_000_000_000, 'wp_options' => 2_000_000_000 ],
                // Notably: no 'tables' or 'tables_exported' key.
            ],
        ] );

        $manager = new Mighty_Backup_Manager();
        $status  = $manager->get_status();

        $this->assertTrue( $status['active'] );
        $this->assertSame( 'export_db', $status['current_step'] );
        $this->assertSame( 'Exporting database (1/2 tables)', $status['step_label'] );

        // Same interpolation math as the PHP-path test: step 2 of 6 sits in
        // the 17-33 band; halfway through (1/2 big tables) lands at ~25.
        $this->assertGreaterThan( 17, $status['progress'] );
        $this->assertLessThan( 33, $status['progress'] );
    }

    // ──────────────────────────────────────────────
    //  State-machine race cluster (PR2)
    // ──────────────────────────────────────────────

    public function test_is_running_returns_true_for_pending_status(): void {
        // Closes the multi-second window between schedule() saving 'pending'
        // and step_start flipping to 'running' during which a concurrent
        // schedule() call could pass the gate and stomp the in-flight state.
        Functions\when( 'get_site_option' )->justReturn( [ 'status' => 'pending' ] );
        $manager = new Mighty_Backup_Manager();
        $this->assertTrue( $manager->is_running() );
    }

    public function test_is_running_returns_true_during_cancelling_tombstone(): void {
        // While cancel() is cleaning up, schedule() must still see the run
        // as active so it doesn't race in and resurrect STATE_OPTION.
        Functions\when( 'get_site_option' )->justReturn( [ 'status' => 'cancelling' ] );
        $manager = new Mighty_Backup_Manager();
        $this->assertTrue( $manager->is_running() );
    }

    public function test_is_running_returns_false_for_completed_failed_and_idle(): void {
        foreach ( [ 'completed', 'failed' ] as $status ) {
            Functions\when( 'get_site_option' )->justReturn( [ 'status' => $status ] );
            $manager = new Mighty_Backup_Manager();
            $this->assertFalse( $manager->is_running(), "status={$status} should not count as running" );
        }
        // No state at all.
        Functions\when( 'get_site_option' )->justReturn( null );
        $manager = new Mighty_Backup_Manager();
        $this->assertFalse( $manager->is_running() );
    }

    public function test_fail_skips_re_save_when_cancel_tombstone_is_set(): void {
        // The race: cancel() writes status='cancelling' and starts cleanup;
        // an in-flight step's catch -> fail() reads the tombstoned state and
        // returns early instead of re-saving 'failed' (which would resurrect
        // STATE_OPTION after cancel just deleted it).
        Functions\when( 'get_site_option' )->justReturn( [
            'status'           => 'cancelling',
            'cancelled_at'     => 1700000000,
            'log_id'           => null,
            'db_local_path'    => null,
            'files_local_path' => null,
            'timestamp'        => '2026-06-17-123000',
        ] );
        $save_calls = 0;
        Functions\when( 'update_site_option' )->alias( function () use ( &$save_calls ) {
            $save_calls++;
            return true;
        } );

        $manager = new Mighty_Backup_Manager();
        $ref     = new \ReflectionMethod( $manager, 'fail' );
        $ref->setAccessible( true );

        // Stale local state (the step handler's view before cancel ran).
        $local_state = [
            'status'           => 'running',
            'log_id'           => null,
            'db_local_path'    => null,
            'files_local_path' => null,
            'timestamp'        => '2026-06-17-123000',
        ];
        $ref->invokeArgs( $manager, [ $local_state, 'pretend error from a yanked-file mid-archive' ] );

        // Must NOT have called save_state (status=failed re-save would
        // overwrite the tombstone).
        $this->assertSame( 0, $save_calls, 'fail() must not save_state when cancel tombstone is set' );
    }

    public function test_get_status_does_not_fatal_on_malformed_db_export_state(): void {
        // Defensive: if db_export is in the chunked shape but big_tables is
        // somehow missing (older state version, hand-edited option, race),
        // get_status() must report 0/0 instead of fataling. The ?? null +
        // is_array() guard is the safety net.
        Functions\when( 'get_site_option' )->justReturn( [
            'status'       => 'running',
            'type'         => 'full',
            'trigger'      => 'manual',
            'timestamp'    => '2026-01-01-030000',
            'current_step' => 'export_db',
            'step_index'   => 1,
            'steps'        => [ 'start', 'export_db', 'archive_files', 'upload_db', 'upload_files', 'cleanup' ],
            'db_file_size'    => null,
            'files_file_size' => null,
            'error'        => null,
            'started_at'   => '2026-01-01 03:00:00',
            'db_export'    => [
                'method'   => 'mysqldump_chunked',
                'raw_path' => '/tmp/test.sql',
                // big_tables / big_tables_index intentionally missing
            ],
        ] );

        $manager = new Mighty_Backup_Manager();
        $status  = $manager->get_status();

        // Renders 0/0 instead of fataling.
        $this->assertSame( 'Exporting database (0/0 tables)', $status['step_label'] );
        // sub_progress is 0 when total_tables is 0; outer progress equals step start.
        $this->assertSame( 17, $status['progress'] );
    }

    public function test_cancel_cleans_up_raw_sql_file(): void {
        $raw_path = tempnam( sys_get_temp_dir(), 'bm-test-' ) . '.sql';
        file_put_contents( $raw_path, 'test data' );

        Functions\when( 'get_site_option' )->justReturn( [
            'status'           => 'running',
            'log_id'           => null,
            'db_local_path'    => null,
            'files_local_path' => null,
            'db_export'        => [
                'tables'          => [],
                'tables_exported' => 0,
                'raw_path'        => $raw_path,
                'streamlined_config' => null,
            ],
        ] );

        $manager = new Mighty_Backup_Manager();
        $result  = $manager->cancel();

        $this->assertTrue( $result );
        $this->assertFileDoesNotExist( $raw_path );
    }
}
