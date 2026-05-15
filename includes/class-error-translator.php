<?php
/**
 * Error translator — maps raw exception messages and AWS error codes to
 * human-readable explanations with optional remediation suggestions.
 *
 * Pure mapping (no WordPress / AWS dependencies). Safe to call from any
 * surface that may render an error: live log, backup history, AJAX banners.
 *
 * Return shape:
 *   [
 *     'raw'             => string   The original message, unmodified.
 *     'human'           => string   Friendly explanation. Equals raw on no-match.
 *     'suggestion'      => ?string  Optional remediation tip.
 *     'settings_anchor' => ?string  Optional tab hash (e.g. 'storage') to deep-link.
 *   ]
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Mighty_Backup_Error_Translator {

    /**
     * Translate any error into a human-readable result.
     *
     * @param \Throwable|string $error Either a thrown exception or an already-stringified message.
     */
    public static function translate( $error ): array {
        $raw      = self::raw_message( $error );
        $aws_code = self::aws_error_code( $error );

        // AWS error codes are the most precise signal — check those first.
        if ( $aws_code !== null ) {
            $hit = self::match_aws_code( $aws_code );
            if ( $hit ) {
                return self::build( $raw, $hit );
            }
        }

        // Otherwise scan the raw message text for known patterns.
        foreach ( self::patterns() as $pattern ) {
            if ( @preg_match( $pattern['match'], $raw ) === 1 ) {
                return self::build( $raw, $pattern );
            }
        }

        return [
            'raw'             => $raw,
            'human'           => $raw,
            'suggestion'      => null,
            'settings_anchor' => null,
        ];
    }

    /**
     * Convenience: return just the human-readable line.
     */
    public static function humanize( $error ): string {
        return self::translate( $error )['human'];
    }

    /**
     * Send an AJAX error response containing the translated payload.
     *
     * If the translator matched a known pattern, sends an object so the JS
     * can render the human-readable form with an optional "Show raw error"
     * toggle. If no match, falls back to a plain string for backward compat.
     */
    public static function send_ajax_error( $error ): void {
        $t = self::translate( $error );

        if ( $t['human'] === $t['raw'] ) {
            wp_send_json_error( $t['raw'] );
            return;
        }

        wp_send_json_error( [
            'human'           => $t['human'],
            'raw'             => $t['raw'],
            'suggestion'      => $t['suggestion'],
            'settings_anchor' => $t['settings_anchor'],
        ] );
    }

    /**
     * Extract the raw message string from a Throwable or pass through a string.
     */
    private static function raw_message( $error ): string {
        if ( $error instanceof \Throwable ) {
            return $error->getMessage();
        }
        return (string) $error;
    }

    /**
     * If the throwable carries an AWS error code, return it; otherwise null.
     */
    private static function aws_error_code( $error ): ?string {
        if ( $error instanceof \Throwable && method_exists( $error, 'getAwsErrorCode' ) ) {
            $code = $error->getAwsErrorCode();
            if ( is_string( $code ) && $code !== '' ) {
                return $code;
            }
        }
        return null;
    }

    /**
     * AWS error codes → friendly mappings.
     *
     * Keys are case-sensitive — AWS error codes are PascalCase.
     */
    private static function aws_code_map(): array {
        return [
            'InvalidAccessKeyId' => [
                'human'           => 'DigitalOcean Spaces rejected the access key.',
                'suggestion'      => 'Double-check the Access Key on the Storage tab — make sure you copied it without surrounding whitespace.',
                'settings_anchor' => 'storage',
            ],
            'SignatureDoesNotMatch' => [
                'human'           => 'DigitalOcean Spaces rejected the secret key (signature mismatch).',
                'suggestion'      => 'Re-enter the Secret Key on the Storage tab. The key is encrypted at rest, so leaving it blank keeps the old (wrong) value.',
                'settings_anchor' => 'storage',
            ],
            'NoSuchBucket' => [
                'human'           => 'The configured DigitalOcean Spaces bucket does not exist.',
                'suggestion'      => 'Verify the Bucket and Endpoint on the Storage tab — endpoint should match the region the bucket was created in (e.g. nyc3, sfo3).',
                'settings_anchor' => 'storage',
            ],
            'AccessDenied' => [
                'human'           => 'DigitalOcean Spaces refused the request (access denied).',
                'suggestion'      => 'Confirm the Spaces key has read+write permission on this bucket. Some Spaces keys are scoped to specific buckets.',
                'settings_anchor' => 'storage',
            ],
            'RequestTimeTooSkewed' => [
                'human'           => 'The server clock is too far out of sync with DigitalOcean.',
                'suggestion'      => 'Check that the host clock is set correctly. AWS-compatible APIs reject requests with timestamps off by more than ~15 minutes.',
                'settings_anchor' => null,
            ],
            'NetworkingError' => [
                'human'           => 'Could not reach DigitalOcean Spaces (network error).',
                'suggestion'      => 'Likely a transient network blip — try again. If it persists, check that outbound HTTPS to digitaloceanspaces.com is not blocked.',
                'settings_anchor' => null,
            ],
            'SlowDown' => [
                'human'           => 'DigitalOcean Spaces is rate-limiting requests.',
                'suggestion'      => 'Reduce upload concurrency via the mighty_backup_upload_concurrency filter, or wait a few minutes and retry.',
                'settings_anchor' => null,
            ],
            'EntityTooLarge' => [
                'human'           => 'One of the backup parts exceeded the maximum object size for this bucket.',
                'suggestion'      => 'Reduce part size with the mighty_backup_upload_part_size filter (defaults to 25 MB).',
                'settings_anchor' => null,
            ],
        ];
    }

    /**
     * Free-form pattern matches, in priority order. The first match wins.
     */
    private static function patterns(): array {
        return [
            // --- AWS SDK exception class detection (no error code surfaced) ---
            [
                'match'           => '/MultipartUploadException/i',
                'human'           => 'A multipart upload to DigitalOcean Spaces failed after retries.',
                'suggestion'      => 'Often a transient network issue — re-run the backup. If it keeps failing, lower upload concurrency (mighty_backup_upload_concurrency) or check the host\'s outbound bandwidth.',
                'settings_anchor' => null,
            ],

            // --- mysqldump / mariadb-dump ---
            [
                'match'           => '/Got error:\s*1045/i',
                'human'           => 'mysqldump could not authenticate to the database (error 1045).',
                'suggestion'      => 'The plugin uses the same DB_USER/DB_PASSWORD as WordPress. If those are correct in wp-config.php, the user may lack the PROCESS privilege required by mysqldump.',
                'settings_anchor' => null,
            ],
            [
                'match'           => '/Got error:\s*1044/i',
                'human'           => 'The MySQL user lacks permission to read this database.',
                'suggestion'      => 'Grant the WordPress DB user SELECT, LOCK TABLES, and PROCESS privileges.',
                'settings_anchor' => null,
            ],
            [
                'match'           => '/Access denied for user/i',
                'human'           => 'MySQL/MariaDB denied access during database export.',
                'suggestion'      => 'Verify DB_USER and DB_PASSWORD in wp-config.php match a user with read access to this database.',
                'settings_anchor' => null,
            ],
            [
                'match'           => '/Got packet bigger than/i',
                'human'           => 'A database row exceeded MySQL\'s max_allowed_packet.',
                'suggestion'      => 'Raise max_allowed_packet on the MySQL server (often capped at 1G by default). Look for unusually large rows in posts, postmeta, or options.',
                'settings_anchor' => null,
            ],
            [
                'match'           => '/Unknown table/i',
                'human'           => 'mysqldump was asked to export a table that no longer exists.',
                'suggestion'      => 'A plugin probably dropped a table mid-backup. Re-running the backup should resolve it.',
                'settings_anchor' => null,
            ],
            [
                'match'           => '/Lost connection to (?:MySQL|MariaDB) server/i',
                'human'           => 'Lost the database connection during export.',
                'suggestion'      => 'Common on shared hosts with short wait_timeout values. Try the PHP-based export by ensuring mysqldump is not in PATH, or ask the host to raise wait_timeout.',
                'settings_anchor' => null,
            ],
            [
                'match'           => '/mysqldump: not found|command not found.*mysqldump/i',
                'human'           => 'mysqldump is not installed on this host.',
                'suggestion'      => 'The plugin will fall back to a slower PHP-based export. To re-enable mysqldump, ask the host to install the mariadb-client or mysql-client package.',
                'settings_anchor' => null,
            ],
            [
                'match'           => '/Maximum execution time of \d+ seconds? exceeded|max_execution_time/i',
                'human'           => 'PHP hit its max_execution_time limit during database export.',
                'suggestion'      => 'A single batch of rows took longer than the host\'s PHP time cap. Lower the Chunk Seconds value in Settings → Schedule → Advanced so the exporter yields sooner between batches. If the table is a log or audit table, marking it structure-only in the Database Tables panel skips its data entirely.',
                'settings_anchor' => 'schedule',
            ],
            [
                'match'           => '/no primary key.+cannot resume mid-table|Table \S+ has no primary key/i',
                'human'           => 'A large table without a primary key cannot be resumed mid-export.',
                'suggestion'      => 'Mighty Backup uses the primary key to safely continue exporting big tables across multiple chunks. Either add a primary key to the table, or mark it structure-only / excluded in Settings → Schedule → Database Tables (recommended for log and audit tables).',
                'settings_anchor' => 'schedule',
            ],

            // --- tar / file archive ---
            [
                'match'           => '/No space left on device/i',
                'human'           => 'The host ran out of disk space during the backup.',
                'suggestion'      => 'Free up space in the system temp directory (often /tmp). The plugin needs roughly 1.2× the size of the previous backup\'s temp files.',
                'settings_anchor' => null,
            ],
            [
                'match'           => '/Could not open file for archival/i',
                'human'           => 'A file could not be opened during archive creation.',
                'suggestion'      => 'is_readable() returned true but fopen() failed — usually a permissions race, a broken symlink, or a file locked by another process (e.g. a mysqldump rotation). The backup was aborted to prevent shipping a corrupted archive. Check the live log for the exact file path, then re-run.',
                'settings_anchor' => null,
            ],
            [
                'match'           => '/Short read on .+ header declared/i',
                'human'           => 'A file was truncated mid-archive.',
                'suggestion'      => 'The file shrank between the size check and the read (commonly happens with log files or session files being written by another process). The backup was aborted to prevent a desynced archive. Add the offending path to extra_exclusions if it\'s expected.',
                'settings_anchor' => null,
            ],
            [
                'match'           => '/Size mismatch for .+ header declared/i',
                'human'           => 'A file changed size during archival.',
                'suggestion'      => 'A live file (cache, log, session) was being written while the backup ran. Add it to extra_exclusions, or re-run the backup at a quieter time.',
                'settings_anchor' => null,
            ],
            [
                'match'           => '/Post-archive verification failed/i',
                'human'           => 'The archive failed its structural self-check.',
                'suggestion'      => 'The tar.gz was produced but tar can\'t parse it cleanly. This means a header/data desync slipped through — re-run the backup; if it keeps failing on the same file, exclude that file or escalate to engineering.',
                'settings_anchor' => null,
            ],
            [
                'match'           => '/gzwrite failed/i',
                'human'           => 'Writing the archive to disk failed mid-stream.',
                'suggestion'      => 'Almost always disk-full or a zlib-level I/O error. Free space on the system temp directory and re-run.',
                'settings_anchor' => null,
            ],
            [
                'match'           => '/Permission denied/i',
                'human'           => 'The plugin could not read a file or write to the temp directory (permission denied).',
                'suggestion'      => 'Check that the PHP process can read everything under wp-content and write to the system temp directory.',
                'settings_anchor' => null,
            ],
            [
                'match'           => '/tar: .+ exit code 2/i',
                'human'           => 'tar exited with a fatal error while archiving files.',
                'suggestion'      => 'Common cause: a file that disappeared mid-read, or a path the PHP user cannot access. Check the live log for the offending file.',
                'settings_anchor' => null,
            ],

            // --- GitHub API ---
            [
                'match'           => '/GitHub API.*401|GitHub.*Bad credentials/i',
                'human'           => 'GitHub rejected the Personal Access Token.',
                'suggestion'      => 'Generate a new PAT with the "repo" scope (and "codespaces:secrets" for secret push) and paste it into the Devcontainer tab.',
                'settings_anchor' => 'devcontainer',
            ],
            [
                'match'           => '/X-RateLimit-Remaining:?\s*0|rate limit exceeded/i',
                'human'           => 'GitHub API rate limit exceeded.',
                'suggestion'      => 'Wait an hour (limits reset hourly) or use a PAT instead of unauthenticated access — authenticated requests have 5000/hour vs 60/hour.',
                'settings_anchor' => 'devcontainer',
            ],
            [
                'match'           => '/GitHub API.*403/i',
                'human'           => 'GitHub refused the request (403 Forbidden).',
                'suggestion'      => 'Confirm the PAT has the required scopes for this repository. For organization repos, the PAT may also need SSO authorization.',
                'settings_anchor' => 'devcontainer',
            ],
            [
                'match'           => '/GitHub API.*404|repository.*not found/i',
                'human'           => 'GitHub could not find the configured repository.',
                'suggestion'      => 'Verify GitHub Owner and Repository Slug in the Devcontainer tab — slug is just the repo name, not the full URL.',
                'settings_anchor' => 'devcontainer',
            ],
            [
                'match'           => '/GitHub API.*422/i',
                'human'           => 'GitHub rejected the request as malformed (422).',
                'suggestion'      => 'Usually means a PR already exists for this branch, or the branch name conflicts. Check the repo on GitHub.',
                'settings_anchor' => 'devcontainer',
            ],

            // --- Plugin-level guards (already-friendly, but anchor them) ---
            [
                'match'           => '/A backup is already in progress/i',
                'human'           => 'A backup is already running.',
                'suggestion'      => 'Wait for the in-progress backup to complete, or cancel it from the Backup tab.',
                'settings_anchor' => 'backup',
            ],
            [
                'match'           => '/Plugin not configured/i',
                'human'           => 'DigitalOcean Spaces credentials are missing.',
                'suggestion'      => 'Fill in the Storage tab — at minimum the access key, secret key, endpoint, and bucket.',
                'settings_anchor' => 'storage',
            ],
            [
                'match'           => '/Insufficient disk space/i',
                'human'           => 'Not enough free space in the system temp directory to run this backup.',
                'suggestion'      => 'Clear out /tmp, or move the temp directory to a larger volume via the WP_TEMP_DIR constant.',
                'settings_anchor' => null,
            ],
        ];
    }

    /**
     * Look up an AWS error code → mapping. Returns null on miss.
     */
    private static function match_aws_code( string $code ): ?array {
        $map = self::aws_code_map();
        return $map[ $code ] ?? null;
    }

    /**
     * Assemble the canonical return shape from a raw message and a mapping hit.
     */
    private static function build( string $raw, array $hit ): array {
        return [
            'raw'             => $raw,
            'human'           => $hit['human'],
            'suggestion'      => $hit['suggestion'] ?? null,
            'settings_anchor' => $hit['settings_anchor'] ?? null,
        ];
    }
}
