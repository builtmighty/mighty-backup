<?php
/**
 * Retention manager — prunes old backups from DO Spaces based on configured limits.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Mighty_Backup_Retention_Manager {

    private Mighty_Backup_Spaces_Client $client;
    private int $retention_count;

    public function __construct( Mighty_Backup_Spaces_Client $client, int $retention_count ) {
        $this->client          = $client;
        $this->retention_count = max( 1, $retention_count );
    }

    /**
     * Prune old backups beyond the retention limit.
     *
     * @return array Summary of what was deleted.
     */
    public function prune(): array {
        $db_deleted    = 0;
        $files_deleted = 0;

        try {
            $db_deleted = $this->prune_prefix( 'databases/' );
        } catch ( \Exception $e ) {
            Mighty_Backup_Log_Stream::add( 'Retention cleanup failed for databases: ' . $e->getMessage() );
        }

        try {
            $files_deleted = $this->prune_prefix( 'files/' );
        } catch ( \Exception $e ) {
            Mighty_Backup_Log_Stream::add( 'Retention cleanup failed for files: ' . $e->getMessage() );
        }

        return [
            'databases_deleted' => $db_deleted,
            'files_deleted'     => $files_deleted,
        ];
    }

    /**
     * Prune objects under a specific prefix.
     *
     * @param string $prefix Sub-path (e.g., "databases/" or "files/").
     * @return int Number of objects deleted.
     */
    private function prune_prefix( string $prefix ): int {
        $objects = $this->client->list_objects( $prefix );

        if ( empty( $objects ) ) {
            // Zero objects under a configured prefix usually means client_path
            // was renamed and old uploads now live under a forgotten prefix
            // (which will never get pruned). Surface this in the live log so
            // operators can spot the bill-leak before it grows.
            Mighty_Backup_Log_Stream::add( sprintf(
                'Retention: no objects under %s — verify client_path setting is current (a rename orphans the old prefix).',
                $prefix
            ) );
            return 0;
        }

        // Sort by key descending. The key embeds the backup timestamp
        // (backup-{Y-m-d-His}.{sql,tar}.gz) which is lexicographically
        // monotonic, unlike S3's LastModified which can shift under
        // uploader clock skew or re-uploads of the same key.
        usort( $objects, static function ( $a, $b ) {
            return strcmp( (string) ( $b['Key'] ?? '' ), (string) ( $a['Key'] ?? '' ) );
        } );

        if ( count( $objects ) <= $this->retention_count ) {
            return 0;
        }

        // Keep the first N (newest by key-embedded timestamp), delete the rest.
        // Additional safety: refuse to delete anything LastModified within the
        // last hour — even if key-sort thinks it's stale, a freshly-uploaded
        // object shouldn't be reaped (catches edge cases like a key whose
        // timestamp portion was hand-edited or clock-skewed at upload).
        $to_delete = array_slice( $objects, $this->retention_count );
        $cutoff    = time() - 3600;
        $keys      = [];
        foreach ( $to_delete as $obj ) {
            $lm_ts = isset( $obj['LastModified'] ) ? strtotime( (string) $obj['LastModified'] ) : false;
            if ( $lm_ts !== false && $lm_ts >= $cutoff ) {
                Mighty_Backup_Log_Stream::add( sprintf(
                    'Retention: refusing to delete %s — uploaded < 1h ago (clock skew?)',
                    $obj['Key'] ?? '?'
                ) );
                continue;
            }
            $keys[] = $obj['Key'];
        }

        if ( empty( $keys ) ) {
            return 0;
        }

        $this->client->delete_objects( $keys );

        return count( $keys );
    }
}
