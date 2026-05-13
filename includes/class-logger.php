<?php
/**
 * Backup logger — custom DB table for tracking backup history.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Mighty_Backup_Logger {

    /**
     * Get the log table name.
     */
    public function get_table_name(): string {
        global $wpdb;
        return $wpdb->base_prefix . 'bm_backup_log';
    }

    /**
     * Create the log table on activation.
     */
    public function create_table(): void {
        global $wpdb;
        $table   = $this->get_table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            backup_type VARCHAR(10) NOT NULL DEFAULT 'full',
            trigger_type VARCHAR(10) NOT NULL DEFAULT 'scheduled',
            status VARCHAR(10) NOT NULL DEFAULT 'running',
            started_at DATETIME NOT NULL,
            completed_at DATETIME NULL,
            db_file_size BIGINT UNSIGNED NULL,
            files_file_size BIGINT UNSIGNED NULL,
            db_remote_key VARCHAR(500) NULL,
            files_remote_key VARCHAR(500) NULL,
            error_message TEXT NULL,
            INDEX idx_status (status),
            INDEX idx_started (started_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Create a new log entry and return its ID.
     */
    public function start( string $backup_type, string $trigger_type ): int {
        global $wpdb;
        $wpdb->insert(
            $this->get_table_name(),
            [
                'backup_type'  => $backup_type,
                'trigger_type' => $trigger_type,
                'status'       => 'running',
                'started_at'   => current_time( 'mysql', true ),
            ],
            [ '%s', '%s', '%s', '%s' ]
        );
        return (int) $wpdb->insert_id;
    }

    /**
     * Mark a log entry as completed.
     */
    public function complete( int $log_id, array $data = [] ): void {
        global $wpdb;
        $update = array_merge(
            [
                'status'       => 'completed',
                'completed_at' => current_time( 'mysql', true ),
            ],
            $data
        );
        $wpdb->update( $this->get_table_name(), $update, [ 'id' => $log_id ] );
    }

    /**
     * Mark a log entry as failed.
     */
    public function fail( int $log_id, string $error_message ): void {
        global $wpdb;
        $wpdb->update(
            $this->get_table_name(),
            [
                'status'        => 'failed',
                'completed_at'  => current_time( 'mysql', true ),
                'error_message' => $error_message,
            ],
            [ 'id' => $log_id ]
        );
    }

    /**
     * Get recent log entries.
     */
    public function get_recent( int $limit = 50, int $offset = 0 ): array {
        global $wpdb;
        $table = $this->get_table_name();
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} ORDER BY started_at DESC LIMIT %d OFFSET %d",
                $limit,
                $offset
            ),
            ARRAY_A
        );
    }

    /**
     * Filtered, paginated query of log entries.
     *
     * Accepts:
     *   - status   ('completed'|'failed'|'running'|'' for any)
     *   - type     ('full'|'db'|'files'|'')
     *   - trigger  ('scheduled'|'manual'|'cli'|'')
     *   - after    (YYYY-MM-DD inclusive lower bound on started_at)
     *   - before   (YYYY-MM-DD inclusive upper bound on started_at)
     *   - paged    (1-based page number)
     *   - per_page (rows per page, capped at 200)
     *
     * @return array{items: array, total: int, paged: int, per_page: int, total_pages: int}
     */
    public function query( array $args = [] ): array {
        global $wpdb;

        $defaults = [
            'status'   => '',
            'type'     => '',
            'trigger'  => '',
            'after'    => '',
            'before'   => '',
            'paged'    => 1,
            'per_page' => 20,
        ];
        $args = array_merge( $defaults, $args );

        $args['paged']    = max( 1, (int) $args['paged'] );
        $args['per_page'] = max( 1, min( 200, (int) $args['per_page'] ) );

        $where     = [];
        $params    = [];
        $allowed   = [
            'status'  => [ 'completed', 'failed', 'running' ],
            'type'    => [ 'full', 'db', 'files' ],
            'trigger' => [ 'scheduled', 'manual', 'cli' ],
        ];

        foreach ( $allowed as $key => $valid ) {
            if ( $args[ $key ] !== '' && in_array( $args[ $key ], $valid, true ) ) {
                $where[]  = "{$key} = %s";
                $params[] = $args[ $key ];
            }
        }

        if ( $args['after'] !== '' && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $args['after'] ) ) {
            $where[]  = 'started_at >= %s';
            $params[] = $args['after'] . ' 00:00:00';
        }
        if ( $args['before'] !== '' && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $args['before'] ) ) {
            $where[]  = 'started_at <= %s';
            $params[] = $args['before'] . ' 23:59:59';
        }

        $table     = $this->get_table_name();
        $where_sql = $where ? ' WHERE ' . implode( ' AND ', $where ) : '';

        $count_query = "SELECT COUNT(*) FROM {$table}{$where_sql}";
        $total       = (int) ( $params
            ? $wpdb->get_var( $wpdb->prepare( $count_query, $params ) )
            : $wpdb->get_var( $count_query ) );

        $offset       = ( $args['paged'] - 1 ) * $args['per_page'];
        $list_query   = "SELECT * FROM {$table}{$where_sql} ORDER BY started_at DESC LIMIT %d OFFSET %d";
        $list_params  = array_merge( $params, [ $args['per_page'], $offset ] );
        $items        = $wpdb->get_results( $wpdb->prepare( $list_query, $list_params ), ARRAY_A ) ?: [];

        return [
            'items'       => $items,
            'total'       => $total,
            'paged'       => $args['paged'],
            'per_page'    => $args['per_page'],
            'total_pages' => (int) ceil( $total / $args['per_page'] ),
        ];
    }

    /**
     * Get the most recent completed backup.
     */
    public function get_last_completed(): ?array {
        global $wpdb;
        $table = $this->get_table_name();
        $row   = $wpdb->get_row(
            "SELECT * FROM {$table} WHERE status = 'completed' ORDER BY completed_at DESC LIMIT 1",
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * Get total count of log entries.
     */
    public function get_count(): int {
        global $wpdb;
        $table = $this->get_table_name();
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
    }

    /**
     * Look up entries by primary key.
     *
     * Filters out rows whose status is "running" so callers cannot delete a
     * backup that is still in progress.
     *
     * @param int[] $ids
     * @return array<int, array> Indexed by id.
     */
    public function get_by_ids( array $ids ): array {
        global $wpdb;
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
        if ( ! $ids ) {
            return [];
        }
        $table        = $this->get_table_name();
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $rows         = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id IN ({$placeholders}) AND status != 'running'",
                $ids
            ),
            ARRAY_A
        ) ?: [];

        $out = [];
        foreach ( $rows as $row ) {
            $out[ (int) $row['id'] ] = $row;
        }
        return $out;
    }

    /**
     * Delete entries by primary key.
     *
     * @param int[] $ids
     * @return int Rows actually deleted.
     */
    public function delete_by_ids( array $ids ): int {
        global $wpdb;
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
        if ( ! $ids ) {
            return 0;
        }
        $table        = $this->get_table_name();
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        // Belt-and-suspenders: never delete a running row even if a caller passes it in.
        return (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE id IN ({$placeholders}) AND status != 'running'",
                $ids
            )
        );
    }
}
