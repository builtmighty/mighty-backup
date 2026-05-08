<?php
/**
 * Backup scheduler — manages WP-Cron events for automated backups.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Mighty_Backup_Scheduler {

    private const CRON_HOOK           = 'mighty_backup_scheduled';
    private const RETENTION_CRON_HOOK = 'mighty_backup_retention';
    public const  LAST_RETENTION_RUN  = 'bm_last_retention_run';

    /**
     * Hook into WordPress to listen for the cron events.
     */
    public function init(): void {
        add_action( self::CRON_HOOK, [ $this, 'run_scheduled_backup' ] );
        add_action( self::RETENTION_CRON_HOOK, [ $this, 'run_retention' ] );
    }

    /**
     * Schedule both cron events (backup + retention).
     */
    public function schedule(): void {
        $switched = $this->maybe_switch_to_main_site();

        try {
            // Backup cron — frequency configurable via settings.
            if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
                $settings  = new Mighty_Backup_Settings();
                $frequency = $settings->get( 'schedule_frequency', 'daily' );
                $time      = $settings->get( 'schedule_time', '03:00' );

                $next_run = $this->calculate_next_run( $time );

                wp_schedule_event( $next_run, $frequency, self::CRON_HOOK );
            }

            // Retention cron — always daily, decoupled from backup success so a
            // streak of failed backups doesn't let old objects accumulate on
            // Spaces. Fires a few hours after the typical backup window.
            if ( ! wp_next_scheduled( self::RETENTION_CRON_HOOK ) ) {
                $retention_next = strtotime( 'tomorrow 06:00', current_time( 'timestamp' ) );
                wp_schedule_event( $retention_next, 'daily', self::RETENTION_CRON_HOOK );
            }
        } finally {
            if ( $switched ) {
                restore_current_blog();
            }
        }
    }

    /**
     * Remove both scheduled cron events.
     */
    public function unschedule(): void {
        $switched = $this->maybe_switch_to_main_site();

        try {
            foreach ( [ self::CRON_HOOK, self::RETENTION_CRON_HOOK ] as $hook ) {
                $timestamp = wp_next_scheduled( $hook );
                if ( $timestamp ) {
                    wp_unschedule_event( $timestamp, $hook );
                }
                wp_clear_scheduled_hook( $hook );
            }
        } finally {
            if ( $switched ) {
                restore_current_blog();
            }
        }
    }

    /**
     * Reschedule (called when settings change).
     */
    public function reschedule(): void {
        $this->unschedule();
        $this->schedule();
    }

    /**
     * Run the scheduled backup (called by WP-Cron).
     *
     * This just schedules the Action Scheduler chain — the actual work
     * happens in separate, time-limited background actions.
     */
    public function run_scheduled_backup(): void {
        // On multisite, only the main site should run scheduled backups.
        if ( is_multisite() && get_current_blog_id() !== get_main_site_id() ) {
            return;
        }

        // Dev mode: skip scheduled backups when the site URL has changed.
        if ( Mighty_Backup_Dev_Mode::is_dev_mode() ) {
            error_log( 'Mighty Backup: Scheduled backup skipped — dev mode active (site URL mismatch).' );
            return;
        }

        try {
            $manager = new Mighty_Backup_Manager();
            $manager->schedule( 'full', 'scheduled' );
        } catch ( \Exception $e ) {
            error_log( 'Mighty Backup: Failed to schedule backup — ' . $e->getMessage() );
        }
    }

    /**
     * Get the next scheduled run time.
     *
     * @return int|false Unix timestamp or false if not scheduled.
     */
    public function get_next_run() {
        return wp_next_scheduled( self::CRON_HOOK );
    }

    /**
     * Run retention prune (called by WP-Cron, daily).
     *
     * Independent of the backup chain — exists so that a streak of failed
     * backups doesn't let old objects accumulate on Spaces. The in-chain
     * `step_cleanup` is still the optimal moment to prune (right after a fresh
     * backup succeeds); this is the safety net for everything else.
     *
     * Records the result in the LAST_RETENTION_RUN site option for the
     * Schedule tab "Retention last ran" display.
     */
    public function run_retention(): void {
        // On multisite, only the main site runs the cron.
        if ( is_multisite() && get_current_blog_id() !== get_main_site_id() ) {
            return;
        }

        $settings        = new Mighty_Backup_Settings();
        $retention_count = (int) $settings->get( 'retention_count', 7 );

        $result = [
            'timestamp'         => time(),
            'databases_deleted' => 0,
            'files_deleted'     => 0,
            'error'             => null,
        ];

        try {
            if ( ! mighty_backup_has_sdk() ) {
                throw new \RuntimeException( 'AWS SDK not available — cannot reach DigitalOcean Spaces.' );
            }

            $client    = new Mighty_Backup_Spaces_Client( $settings );
            $retention = new Mighty_Backup_Retention_Manager( $client, $retention_count );
            $pruned    = $retention->prune();

            $result['databases_deleted'] = (int) ( $pruned['databases_deleted'] ?? 0 );
            $result['files_deleted']     = (int) ( $pruned['files_deleted'] ?? 0 );

            Mighty_Backup_Log_Stream::add( sprintf(
                'Retention cron pruned %d database backup(s) and %d file backup(s) (keeping %d).',
                $result['databases_deleted'],
                $result['files_deleted'],
                $retention_count
            ) );
        } catch ( \Throwable $e ) {
            $result['error'] = $e->getMessage();
            Mighty_Backup_Log_Stream::add( 'Retention cron failed: ' . $e->getMessage() );
        }

        update_site_option( self::LAST_RETENTION_RUN, $result );
    }

    /**
     * On multisite, switch to the main site so cron events are registered there.
     *
     * @return bool True if a blog switch occurred and restore_current_blog() is needed.
     */
    private function maybe_switch_to_main_site(): bool {
        if ( is_multisite() && get_current_blog_id() !== get_main_site_id() ) {
            switch_to_blog( get_main_site_id() );
            return true;
        }
        return false;
    }

    /**
     * Calculate the next run timestamp based on the configured time.
     *
     * @param string $time Time in HH:MM format.
     * @return int Unix timestamp.
     */
    private function calculate_next_run( string $time ): int {
        $parts = explode( ':', $time );
        $hour  = (int) ( $parts[0] ?? 3 );
        $min   = (int) ( $parts[1] ?? 0 );

        // Use WordPress timezone (Settings > General).
        $now  = current_time( 'timestamp' );
        $today = strtotime( "today {$hour}:{$min}", $now );

        // If the time has already passed today, schedule for tomorrow.
        if ( $today <= $now ) {
            return strtotime( 'tomorrow ' . sprintf( '%02d:%02d', $hour, $min ), $now );
        }

        return $today;
    }
}
