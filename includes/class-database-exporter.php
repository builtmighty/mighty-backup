<?php
/**
 * Database exporter — mysqldump with gzip (preferred) or pure PHP fallback.
 *
 * Supports "Streamlined Mode" for lighter exports:
 *   - mysqldump handles bulk tables (with --ignore-table for special ones)
 *   - PHP appends filtered order tables and structure-only log tables
 *   - Falls back to full PHP export when mysqldump is unavailable
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Mighty_Backup_Database_Exporter {

    private int $batch_size;
    private int $insert_batch = 100;
    private bool $streamlined;

    /**
     * User-configured per-table overrides (assoc maps for O(1) lookup).
     * Tables absent from both maps are exported normally.
     */
    private array $excluded_tables = [];
    private array $structure_only_tables = [];

    /**
     * Pre-flight table sizes (table_name => bytes). Populated once before the
     * first chunk via set_table_sizes() and used by the chunk loop to emit
     * per-table progress lines. Empty when running outside the chunked path.
     */
    private array $table_sizes = [];

    /**
     * Write function — 'gzwrite' for gzip handles, 'fwrite' for plain files.
     * Toggled by export_tables_chunk() / finalize_export() for the chunked path.
     */
    private string $writer = 'gzwrite';

    /**
     * Number of wpdb placeholder-escape tokens stripped during this export.
     * wpdb returns values from get_results() with literal '%' replaced by a
     * 64-hex session hash; we must strip these before writing or they get
     * persisted permanently on re-import.
     */
    private int $placeholder_strips = 0;

    public function __construct(
        bool $streamlined = false,
        array $excluded_tables = [],
        array $structure_only_tables = []
    ) {
        $this->batch_size            = (int) apply_filters( 'mighty_backup_db_batch_size', 1000 );
        $this->streamlined           = $streamlined;
        $this->excluded_tables       = array_fill_keys( array_values( $excluded_tables ), true );
        $this->structure_only_tables = array_fill_keys( array_values( $structure_only_tables ), true );
    }

    /**
     * Hand the exporter a pre-flight size map so the chunk loop can log each
     * table's size as it starts. Backup Manager captures this once on the first
     * chunk and replays it into a fresh exporter instance on each subsequent
     * chunk. Missing entries are tolerated (size logged as unknown).
     *
     * @param array<string, int> $sizes Map of table_name => bytes.
     */
    public function set_table_sizes( array $sizes ): void {
        $this->table_sizes = $sizes;
    }

    /**
     * Filter a size map to tables exceeding a byte threshold. Used by Backup
     * Manager to decide whether the mysqldump path needs --where range
     * chunking, and to drive the list of big-table loops. Excluded and
     * structure-only tables are dropped — both already have their data
     * suppressed by the chunked mysqldump path's small-tables invocation,
     * and treating them as "big" would dump their data anyway via the range
     * loop, contradicting the user's setting.
     *
     * @param array<string, int> $sizes      Map of table_name => bytes.
     * @param int                $threshold  Minimum size to be considered "large", in bytes.
     * @return array<string, int> Same map, filtered, preserving original order.
     */
    public function get_large_tables( array $sizes, int $threshold ): array {
        $out = [];
        foreach ( $sizes as $table => $bytes ) {
            if ( $bytes < $threshold ) {
                continue;
            }
            if ( isset( $this->excluded_tables[ $table ] ) ) {
                continue;
            }
            if ( isset( $this->structure_only_tables[ $table ] ) ) {
                continue;
            }
            $out[ $table ] = $bytes;
        }
        return $out;
    }

    /**
     * Look up MIN/MAX of a table's primary key. Used once per big table to
     * bound the range-chunking loop. Returns nulls if the table is empty.
     *
     * @return array{min: mixed, max: mixed}
     */
    public function get_pk_bounds( string $table, string $pk_column ): array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row(
            "SELECT MIN(`{$pk_column}`) AS min_pk, MAX(`{$pk_column}`) AS max_pk FROM `{$table}`",
            ARRAY_A
        );
        return [
            'min' => $row['min_pk'] ?? null,
            'max' => $row['max_pk'] ?? null,
        ];
    }

    /**
     * Public detection of a single-column primary key. Exposed for the chunked
     * mysqldump path so Backup Manager can decide upfront whether a big table
     * can be range-chunked at all.
     */
    public function get_table_primary_key( string $table ): ?string {
        return $this->get_primary_key( $table );
    }

    /**
     * Invoke mysqldump for every table except those in $ignore_tables, appending
     * the dump to $raw_path. Used by the chunked mysqldump path to clear all
     * small tables in a single invocation, leaving big tables for ranged dumps.
     *
     * Structure-only and excluded tables are appended to $ignore_tables before
     * the mysqldump call; structure-only tables get their CREATE statement
     * written separately afterward.
     */
    public function dump_small_tables_via_mysqldump(
        string $raw_path,
        array $ignore_tables,
        array $structure_only_tables = []
    ): void {
        $bin       = $this->get_dump_binary();
        $defaults  = $this->write_mysql_defaults();
        $err_path  = $raw_path . '.err';
        $all_ignore = array_unique( array_merge(
            $ignore_tables,
            $structure_only_tables,
            array_keys( $this->excluded_tables )
        ) );
        $ignore_flags = $this->build_ignore_flags( $all_ignore );

        try {
            $command = sprintf(
                '%s --defaults-extra-file=%s --single-transaction --quick --skip-lock-tables --set-charset '
                . '--default-character-set=utf8mb4 --no-tablespaces '
                . '%s %s >> %s 2>%s',
                escapeshellcmd( $bin ),
                escapeshellarg( $defaults ),
                $ignore_flags,
                escapeshellarg( DB_NAME ),
                escapeshellarg( $raw_path ),
                escapeshellarg( $err_path )
            );

            exec( $command, $output, $return_code );

            $stderr = file_exists( $err_path ) ? (string) file_get_contents( $err_path ) : '';
            @unlink( $err_path );
            $stderr = $this->filter_dump_stderr( $stderr );

            if ( $return_code !== 0 ) {
                throw new \Exception( "{$bin} (small-tables dump) failed (exit {$return_code}): {$stderr}" );
            }

            // Append CREATE TABLE for each structure-only table.
            foreach ( $structure_only_tables as $table ) {
                $this->dump_table_schema_only_via_mysqldump( $raw_path, $table );
            }
        } finally {
            @unlink( $defaults );
        }
    }

    /**
     * Append a single table's CREATE statement (no data) to $raw_path. Invoked
     * once per big table at the top of its range loop so the ranged INSERTs
     * have a target schema, and once per structure-only table after the small
     * batch dump.
     */
    public function dump_table_schema_only_via_mysqldump( string $raw_path, string $table ): void {
        $bin      = $this->get_dump_binary();
        $defaults = $this->write_mysql_defaults();
        $err_path = $raw_path . '.err';

        try {
            $command = sprintf(
                '%s --defaults-extra-file=%s --no-data --single-transaction --skip-lock-tables --set-charset '
                . '--default-character-set=utf8mb4 --no-tablespaces '
                . '%s %s >> %s 2>%s',
                escapeshellcmd( $bin ),
                escapeshellarg( $defaults ),
                escapeshellarg( DB_NAME ),
                escapeshellarg( $table ),
                escapeshellarg( $raw_path ),
                escapeshellarg( $err_path )
            );

            exec( $command, $output, $return_code );

            $stderr = file_exists( $err_path ) ? (string) file_get_contents( $err_path ) : '';
            @unlink( $err_path );
            $stderr = $this->filter_dump_stderr( $stderr );

            if ( $return_code !== 0 ) {
                throw new \Exception( "{$bin} (schema-only for {$table}) failed (exit {$return_code}): {$stderr}" );
            }
        } finally {
            @unlink( $defaults );
        }
    }

    /**
     * Dump one PK range of a single table as INSERT statements (no DDL),
     * appending to $raw_path. The caller chooses the start/end bounds and
     * uses the returned elapsed time to size the next range adaptively.
     *
     * @return array{elapsed: float} Wall-time spent inside mysqldump for adaptive range sizing.
     */
    public function dump_table_range_via_mysqldump(
        string $raw_path,
        string $table,
        string $pk_column,
        $start_after_pk,
        $end_pk_inclusive
    ): array {
        $bin      = $this->get_dump_binary();
        $defaults = $this->write_mysql_defaults();
        $err_path = $raw_path . '.err';

        $where = sprintf(
            '`%s` > %s AND `%s` <= %s',
            $pk_column,
            $this->quote_pk_value( $start_after_pk ),
            $pk_column,
            $this->quote_pk_value( $end_pk_inclusive )
        );

        $started = microtime( true );

        try {
            $command = sprintf(
                '%s --defaults-extra-file=%s --no-create-info --skip-add-drop-table --single-transaction '
                . '--quick --skip-lock-tables --set-charset --default-character-set=utf8mb4 --no-tablespaces '
                . '--where=%s %s %s >> %s 2>%s',
                escapeshellcmd( $bin ),
                escapeshellarg( $defaults ),
                escapeshellarg( $where ),
                escapeshellarg( DB_NAME ),
                escapeshellarg( $table ),
                escapeshellarg( $raw_path ),
                escapeshellarg( $err_path )
            );

            exec( $command, $output, $return_code );

            $stderr = file_exists( $err_path ) ? (string) file_get_contents( $err_path ) : '';
            @unlink( $err_path );
            $stderr = $this->filter_dump_stderr( $stderr );

            if ( $return_code !== 0 ) {
                throw new \Exception( "{$bin} (range dump of {$table}) failed (exit {$return_code}): {$stderr}" );
            }

            return [ 'elapsed' => microtime( true ) - $started ];
        } finally {
            @unlink( $defaults );
        }
    }

    /**
     * Quote a primary-key value safely for inclusion in a --where clause.
     * Integers stay numeric; anything else is single-quoted with escaping.
     */
    private function quote_pk_value( $value ): string {
        global $wpdb;
        if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) {
            return (string) $value;
        }
        return "'" . esc_sql( (string) $value ) . "'";
    }

    /**
     * Finalize a chunked mysqldump export: sanitize wpdb placeholder tokens if
     * configured, then gzip raw → output. Does NOT call finalize_export — the
     * PHP-path postamble (`SET FOREIGN_KEY_CHECKS=1; COMMIT;`) would be tacked
     * on after mysqldump's own state-restore block, which is redundant and
     * could land outside an active transaction.
     */
    public function finalize_mysqldump_chunked( string $raw_path, string $output_path ): int {
        $sanitize = (bool) apply_filters( 'mighty_backup_sanitize_placeholder_hashes', true );

        if ( $sanitize ) {
            $this->sanitize_and_gzip( $raw_path, $output_path, $this->get_gzip_level(), [] );
        } else {
            // Stream raw → gzip directly, no sanitization pass.
            $gz = gzopen( $output_path, 'wb' . $this->get_gzip_level() );
            if ( ! $gz ) {
                throw new \Exception( "Failed to open output file for compression: {$output_path}" );
            }
            $in = fopen( $raw_path, 'rb' );
            if ( ! $in ) {
                gzclose( $gz );
                throw new \Exception( "Failed to read raw SQL: {$raw_path}" );
            }
            try {
                while ( ! feof( $in ) ) {
                    $buf = fread( $in, 65536 );
                    if ( $buf === false || $buf === '' ) {
                        break;
                    }
                    gzwrite( $gz, $buf );
                }
            } finally {
                fclose( $in );
                gzclose( $gz );
            }
        }

        @unlink( $raw_path );
        $size = filesize( $output_path );
        return $size !== false ? (int) $size : 0;
    }

    /**
     * Write the SQL preamble to a fresh raw file. Called once at the start of
     * the chunked mysqldump path. The mysqldump output itself doesn't include
     * the same `SET FOREIGN_KEY_CHECKS=0` framing we use for the PHP path, so
     * we prepend ours and rely on `--no-tablespaces`/`--set-charset` for the
     * rest.
     */
    public function write_chunked_mysqldump_preamble( string $raw_path ): void {
        $fh = fopen( $raw_path, 'wb' );
        if ( ! $fh ) {
            throw new \Exception( "Failed to open raw SQL file for writing: {$raw_path}" );
        }
        $this->writer = 'fwrite';
        $this->write_preamble( $fh );
        $this->writer = 'gzwrite';
        fclose( $fh );
    }

    /**
     * Resolve a table's export mode based on user overrides.
     *
     * @return string 'skip', 'structure_only', or 'full'.
     */
    private function table_mode( string $table ): string {
        if ( isset( $this->excluded_tables[ $table ] ) ) {
            return 'skip';
        }
        if ( isset( $this->structure_only_tables[ $table ] ) ) {
            return 'structure_only';
        }
        return 'full';
    }

    /**
     * Whether any per-table override is configured.
     */
    private function has_table_overrides(): bool {
        return ! empty( $this->excluded_tables ) || ! empty( $this->structure_only_tables );
    }

    /**
     * Emit one log line as a table begins exporting in the chunked path, with
     * its on-disk size when known. Makes "stuck on big table" obvious in the
     * live log and gives admins a signal for which tables to mark structure-only.
     */
    private function log_table_start( string $table, string $mode ): void {
        if ( ! class_exists( 'Mighty_Backup_Log_Stream' ) ) {
            return;
        }
        $size_str = isset( $this->table_sizes[ $table ] ) && $this->table_sizes[ $table ] > 0
            ? size_format( $this->table_sizes[ $table ] )
            : '';
        $suffix = $mode === 'structure_only' ? ' — structure only' : '';
        $line   = $size_str
            ? "Table {$table}: {$size_str}{$suffix}"
            : "Table {$table}{$suffix}";
        Mighty_Backup_Log_Stream::add( $line );
    }

    /**
     * Write data to the active handle using the current writer function.
     */
    private function write( $handle, string $data ): void {
        ( $this->writer )( $handle, $data );
    }

    /**
     * Export the database to a gzipped SQL file.
     *
     * @param string $output_path Absolute path for the output .sql.gz file.
     * @return int File size in bytes.
     * @throws \Exception On export failure.
     */
    public function export( string $output_path ): int {
        if ( $this->streamlined ) {
            if ( $this->can_use_mysqldump() ) {
                $size = $this->export_streamlined_hybrid( $output_path );
            } else {
                $size = $this->export_streamlined_php( $output_path );
            }
        } elseif ( $this->can_use_mysqldump() ) {
            $size = $this->export_with_mysqldump( $output_path );
        } else {
            $size = $this->export_with_php( $output_path );
        }

        $this->emit_placeholder_warning();

        return $size;
    }

    /**
     * Log a warning if the PHP path stripped placeholder-escape tokens during
     * this export. Non-zero strips mean the production DB has '%' characters
     * being round-tripped through wpdb — harmless in itself, but the warning
     * is also a hint that the DB may already contain persisted '{HASH}'
     * tokens that the mysqldump path would propagate. Admin can run
     * `wp mighty-backup repair placeholders --dry-run` to check.
     */
    private function emit_placeholder_warning(): void {
        if ( $this->placeholder_strips > 0 && class_exists( 'Mighty_Backup_Log_Stream' ) ) {
            Mighty_Backup_Log_Stream::add( sprintf(
                'Stripped %d wpdb placeholder-escape token(s) from PHP-path rows. ' .
                'If the production DB has persisted hashes, run `wp mighty-backup repair placeholders --dry-run` to check.',
                $this->placeholder_strips
            ) );
        }
    }

    // ──────────────────────────────────────────────
    //  Chunked export API (used by Backup Manager)
    // ──────────────────────────────────────────────

    /**
     * Determine which export method will be used without running the export.
     *
     * @return string 'mysqldump', 'php', 'streamlined_hybrid', or 'streamlined_php'.
     */
    public function get_export_method(): string {
        if ( $this->streamlined ) {
            return $this->can_use_mysqldump() ? 'streamlined_hybrid' : 'streamlined_php';
        }
        return $this->can_use_mysqldump() ? 'mysqldump' : 'php';
    }

    /**
     * Get the ordered list of all database tables, minus any the user
     * has set to skip. The chunked loop iterates this list, so skipped
     * tables never appear in progress counts or per-table logs.
     *
     * @return string[] Table names.
     */
    public function get_table_list(): array {
        $tables = $this->get_tables();
        if ( empty( $this->excluded_tables ) ) {
            return $tables;
        }
        return array_values( array_filter(
            $tables,
            fn( $t ) => ! isset( $this->excluded_tables[ $t ] )
        ) );
    }

    /**
     * Get the streamlined special table configuration (log tables, order tables, legacy flag).
     *
     * @return array{log_tables: string[], order_tables: array<string, string>, legacy: bool}
     */
    public function get_streamlined_config(): array {
        return $this->get_streamlined_special_tables();
    }

    /**
     * Export a chunk of tables to an uncompressed SQL file (append mode).
     *
     * Called repeatedly by the Backup Manager across multiple Action Scheduler
     * actions. Each invocation processes tables until the time threshold is
     * reached, then returns so the next action can continue. Mid-table
     * resumption is supported for tables with a single-column primary key via
     * the $resume parameter — see export_table().
     *
     * @param string     $raw_path          Path to the raw .sql file (created/appended).
     * @param string[]   $tables            Full ordered list of table names.
     * @param int        $start_index       Index of the first table to process in this chunk. When $resume is non-null, $tables[$start_index] is the table being resumed.
     * @param int        $chunk_seconds     Max seconds before yielding (0 = no limit).
     * @param bool       $write_preamble    Whether to write the SQL preamble (first chunk only).
     * @param array|null $streamlined_config Cached result of get_streamlined_config(), or null for full export.
     * @param array|null $resume            {pk_column, last_pk} to resume the table at $start_index mid-export, or null for fresh-table start.
     * @return array{next_index: int, partial: ?array{pk_column: string, last_pk: mixed}} next_index is where the next chunk should resume; partial is non-null only when the chunk paused mid-table and the next chunk must re-enter $tables[next_index] with the resume token.
     */
    public function export_tables_chunk(
        string $raw_path,
        array $tables,
        int $start_index,
        int $chunk_seconds,
        bool $write_preamble,
        ?array $streamlined_config = null,
        ?array $resume = null
    ): array {
        $fh = fopen( $raw_path, 'ab' );
        if ( ! $fh ) {
            throw new \Exception( "Failed to open raw SQL file for writing: {$raw_path}" );
        }

        $this->writer = 'fwrite';

        try {
            if ( $write_preamble ) {
                $this->write_preamble( $fh );
            }

            $started_at  = time();
            $total       = count( $tables );
            $order_ids   = null;
            $order_item_ids = null;

            // Pre-fetch order IDs if streamlined mode needs them.
            if ( $streamlined_config ) {
                if ( ! empty( $streamlined_config['order_tables'] ) || ! empty( $streamlined_config['legacy'] ) ) {
                    $order_ids      = $this->get_recent_order_ids();
                    $order_item_ids = $this->get_order_item_ids( $order_ids );
                }
            }

            for ( $i = $start_index; $i < $total; $i++ ) {
                $table = $tables[ $i ];
                $mode  = $this->table_mode( $table );

                if ( $mode === 'skip' ) {
                    // Defensive — get_table_list() already filters these out,
                    // but a caller may have passed a raw table list.
                    if ( $chunk_seconds > 0 && ( time() - $started_at ) >= $chunk_seconds ) {
                        fclose( $fh );
                        $this->writer = 'gzwrite';
                        $this->emit_placeholder_warning();
                        return [ 'next_index' => $i + 1, 'partial' => null ];
                    }
                    continue;
                }

                // Resume token only applies to the first iteration's table — and
                // only if it matches. After that, $resume is consumed.
                $table_resume = ( $i === $start_index && $resume !== null && ( $resume['table'] ?? null ) === $table )
                    ? $resume
                    : null;

                if ( $table_resume === null ) {
                    $this->log_table_start( $table, $mode );
                } else {
                    // Resuming a partially-exported table; log progress.
                    if ( class_exists( 'Mighty_Backup_Log_Stream' ) ) {
                        Mighty_Backup_Log_Stream::add( sprintf(
                            'Resuming %s from %s > %s',
                            $table,
                            $table_resume['pk_column'],
                            (string) $table_resume['last_pk']
                        ) );
                    }
                }

                if ( $mode === 'structure_only' ) {
                    $this->export_table_structure_only( $fh, $table );
                } elseif ( $streamlined_config ) {
                    // Streamlined per-table path is not resumable in this pass —
                    // its filtered set is held in PHP memory and ordering is not
                    // guaranteed by PK. Run single-shot, ignore time budget.
                    $this->export_table_chunked_streamlined(
                        $fh, $table, $streamlined_config, $order_ids ?? [], $order_item_ids ?? []
                    );
                } else {
                    $result = $this->export_table(
                        $fh, $table, $table_resume, $started_at, $chunk_seconds
                    );

                    if ( ! $result['done'] ) {
                        // Paused mid-table; persist resume token and bail.
                        fclose( $fh );
                        $this->writer = 'gzwrite';
                        $this->emit_placeholder_warning();
                        return [
                            'next_index' => $i,
                            'partial'    => [
                                'table'     => $table,
                                'pk_column' => $result['pk_column'],
                                'last_pk'   => $result['last_pk'],
                            ],
                        ];
                    }
                }

                // Check time threshold after each completed table.
                if ( $chunk_seconds > 0 && ( time() - $started_at ) >= $chunk_seconds ) {
                    fclose( $fh );
                    $this->writer = 'gzwrite';
                    $this->emit_placeholder_warning();
                    return [ 'next_index' => $i + 1, 'partial' => null ];
                }
            }

            fclose( $fh );
            $this->writer = 'gzwrite';
            $this->emit_placeholder_warning();
            return [ 'next_index' => $total, 'partial' => null ];

        } catch ( \Throwable $e ) {
            fclose( $fh );
            $this->writer = 'gzwrite';
            throw $e;
        }
    }

    /**
     * Finalize a chunked export: write postamble and compress to gzip.
     *
     * @param string $raw_path    Path to the uncompressed .sql file.
     * @param string $output_path Path for the output .sql.gz file.
     * @return int File size of the compressed output in bytes.
     * @throws \Exception On failure.
     */
    public function finalize_export( string $raw_path, string $output_path ): int {
        // Append postamble to raw file.
        $fh = fopen( $raw_path, 'ab' );
        if ( ! $fh ) {
            throw new \Exception( "Failed to open raw SQL file for finalization: {$raw_path}" );
        }
        $this->writer = 'fwrite';
        $this->write_postamble( $fh );
        $this->writer = 'gzwrite';
        fclose( $fh );

        // Stream-compress raw SQL into gzip output (same pattern as export_streamlined_hybrid).
        $gzip_level = $this->get_gzip_level();
        $gz = gzopen( $output_path, 'wb' . $gzip_level );
        if ( ! $gz ) {
            throw new \Exception( "Failed to open output file for compression: {$output_path}" );
        }

        try {
            $fh = fopen( $raw_path, 'rb' );
            if ( ! $fh ) {
                throw new \Exception( "Failed to read raw SQL file: {$raw_path}" );
            }
            while ( ! feof( $fh ) ) {
                $chunk = fread( $fh, 65536 ); // 64 KB chunks.
                if ( $chunk !== false && $chunk !== '' ) {
                    gzwrite( $gz, $chunk );
                }
            }
            fclose( $fh );
        } finally {
            gzclose( $gz );
            @unlink( $raw_path );
        }

        $size = filesize( $output_path );
        if ( $size === false || $size === 0 ) {
            throw new \Exception( 'Chunked export produced an empty file.' );
        }

        return $size;
    }

    /**
     * Route a single table through the correct streamlined export method.
     *
     * Used by export_tables_chunk() for per-table dispatch in streamlined mode.
     */
    private function export_table_chunked_streamlined(
        $handle,
        string $table,
        array $config,
        array $order_ids,
        array $order_item_ids
    ): void {
        global $wpdb;

        if ( in_array( $table, $config['log_tables'], true ) ) {
            $this->export_table_structure_only( $handle, $table );
        } elseif ( isset( $config['order_tables'][ $table ] ) ) {
            $id_column = $config['order_tables'][ $table ];
            $id_list   = ( $id_column === 'order_item_id' ) ? $order_item_ids : $order_ids;
            $this->export_table_filtered( $handle, $table, $id_column, $id_list );
        } elseif ( ! empty( $config['legacy'] ) && $table === $wpdb->posts ) {
            $this->export_table_posts_streamlined( $handle, $table, $order_ids );
        } elseif ( ! empty( $config['legacy'] ) && $table === $wpdb->postmeta ) {
            $this->export_table_postmeta_streamlined( $handle, $table, $order_ids );
        } else {
            $this->export_table( $handle, $table );
        }
    }

    // ──────────────────────────────────────────────
    //  Internal helpers
    // ──────────────────────────────────────────────

    /**
     * Return the dump binary to use — prefers `mariadb-dump` when available.
     *
     * Modern MariaDB ships `mysqldump` as a compatibility shim that prints a
     * deprecation warning to stderr on every call. Using `mariadb-dump` avoids
     * the warning entirely and is forward-compatible.
     */
    private function get_dump_binary(): string {
        static $bin = null;
        if ( $bin !== null ) {
            return $bin;
        }
        exec( 'command -v mariadb-dump 2>/dev/null', $out, $rc );
        $bin = ( $rc === 0 && ! empty( $out[0] ) ) ? 'mariadb-dump' : 'mysqldump';
        return $bin;
    }

    /**
     * Strip known-benign warnings from dump stderr output.
     *
     * MariaDB's mysqldump shim unconditionally prints a deprecation notice
     * to stderr on every invocation. It's harmless but previously caused
     * false-failure exceptions. Other stderr content is preserved for
     * diagnostic value.
     */
    private function filter_dump_stderr( string $stderr ): string {
        $stderr = preg_replace(
            '/^mysqldump: Deprecated program name\..*$\n?/m',
            '',
            $stderr
        );
        return trim( (string) $stderr );
    }

    /**
     * Check if a usable dump binary (mariadb-dump or mysqldump) is available.
     */
    private function can_use_mysqldump(): bool {
        if ( ! function_exists( 'exec' ) ) {
            return false;
        }

        $disabled = explode( ',', ini_get( 'disable_functions' ) );
        $disabled = array_map( 'trim', $disabled );
        if ( in_array( 'exec', $disabled, true ) ) {
            return false;
        }

        exec( 'command -v mariadb-dump 2>/dev/null', $mariadb_out, $mariadb_rc );
        if ( $mariadb_rc === 0 && ! empty( $mariadb_out[0] ) ) {
            return true;
        }

        exec( 'command -v mysqldump 2>/dev/null', $mysql_out, $mysql_rc );
        return $mysql_rc === 0 && ! empty( $mysql_out[0] );
    }

    // ──────────────────────────────────────────────
    //  Full export paths (original behavior)
    // ──────────────────────────────────────────────

    /**
     * Export using mysqldump (or mariadb-dump) piped to gzip (fast, low memory).
     *
     * Wraps the pipe in `bash -c 'set -o pipefail; ...'` so dump failures
     * propagate through to gzip's exit code — otherwise gzip would mask
     * upstream failures by exiting 0 on truncated input.
     */
    private function export_with_mysqldump( string $output_path ): int {
        $gzip_level = $this->get_gzip_level();
        $err_path   = $output_path . '.err';
        $defaults   = $this->write_mysql_defaults();
        $bin        = $this->get_dump_binary();
        $sanitize   = (bool) apply_filters( 'mighty_backup_sanitize_placeholder_hashes', true );

        // Force the two-stage path when overrides are present so we can
        // append CREATE TABLE statements for structure-only tables after
        // mysqldump finishes.
        $has_overrides = $this->has_table_overrides();
        if ( $has_overrides ) {
            $sanitize = true;
        }
        $raw_path = $sanitize ? $output_path . '.raw.sql' : null;

        $ignore_flags = $this->build_ignore_flags(
            array_merge(
                array_keys( $this->excluded_tables ),
                array_keys( $this->structure_only_tables )
            )
        );
        $structure_only_list = array_keys( $this->structure_only_tables );

        try {
            if ( $sanitize ) {
                // Two-stage: dump to uncompressed temp, sanitize → gzip.
                $command = sprintf(
                    '%s --defaults-extra-file=%s --single-transaction --quick --skip-lock-tables --set-charset '
                    . '--default-character-set=utf8mb4 --no-tablespaces '
                    . '%s %s > %s 2>%s',
                    escapeshellcmd( $bin ),
                    escapeshellarg( $defaults ),
                    $ignore_flags,
                    escapeshellarg( DB_NAME ),
                    escapeshellarg( $raw_path ),
                    escapeshellarg( $err_path )
                );
            } else {
                $pipe = sprintf(
                    '%s --defaults-extra-file=%s --single-transaction --quick --skip-lock-tables --set-charset '
                    . '--default-character-set=utf8mb4 --no-tablespaces '
                    . '%s %s 2>%s | gzip -%d > %s',
                    escapeshellcmd( $bin ),
                    escapeshellarg( $defaults ),
                    $ignore_flags,
                    escapeshellarg( DB_NAME ),
                    escapeshellarg( $err_path ),
                    $gzip_level,
                    escapeshellarg( $output_path )
                );
                $command = 'bash -c ' . escapeshellarg( 'set -o pipefail; ' . $pipe );
            }

            Mighty_Backup_Log_Stream::add( 'Using ' . $bin . ' for database export' );

            exec( $command, $output, $return_code );

            $stderr = file_exists( $err_path ) ? (string) file_get_contents( $err_path ) : '';
            @unlink( $err_path );
            $stderr = $this->filter_dump_stderr( $stderr );

            if ( $return_code !== 0 ) {
                if ( $raw_path ) {
                    @unlink( $raw_path );
                }
                throw new \Exception( "{$bin} failed (exit {$return_code}): {$stderr}" );
            }

            if ( $sanitize ) {
                $this->sanitize_and_gzip( $raw_path, $output_path, $gzip_level, $structure_only_list );
                @unlink( $raw_path );
            }

            $size = filesize( $output_path );
            if ( $size === false || $size === 0 ) {
                throw new \Exception( $bin . ' produced an empty file — check database credentials.' );
            }

            return $size;
        } finally {
            @unlink( $defaults );
        }
    }

    /**
     * Build a string of `--ignore-table=DB_NAME.<table>` flags for the dump command.
     *
     * @param string[] $tables Table names to ignore.
     */
    private function build_ignore_flags( array $tables ): string {
        $flags = '';
        foreach ( array_unique( $tables ) as $table ) {
            $flags .= ' --ignore-table=' . escapeshellarg( DB_NAME . '.' . $table );
        }
        return $flags;
    }

    /**
     * Sanitize a raw SQL dump (stripping any wpdb placeholder-escape tokens)
     * while streaming it into a gzip output file. Used by the mysqldump
     * paths to repair `{HASH}` corruption in production data without
     * modifying production itself.
     */
    private function sanitize_and_gzip(
        string $raw_path,
        string $output_path,
        int $gzip_level,
        array $structure_only_tables = []
    ): void {
        $in = fopen( $raw_path, 'rb' );
        if ( ! $in ) {
            throw new \Exception( "Failed to read mysqldump output: {$raw_path}" );
        }
        $gz = gzopen( $output_path, 'wb' . $gzip_level );
        if ( ! $gz ) {
            fclose( $in );
            throw new \Exception( "Failed to open output file for compression: {$output_path}" );
        }

        $repaired = 0;
        try {
            while ( ( $line = fgets( $in ) ) !== false ) {
                if ( strpos( $line, '{' ) !== false ) {
                    $sanitized = Mighty_Backup_Placeholder_Repair::sanitize_string( $line );
                    if ( $sanitized !== $line ) {
                        ++$repaired;
                        $line = $sanitized;
                    }
                }
                gzwrite( $gz, $line );
            }

            // Append CREATE TABLE statements for structure-only tables. These were
            // excluded from mysqldump (via --ignore-table) so their schemas wouldn't
            // otherwise appear in the dump.
            if ( ! empty( $structure_only_tables ) ) {
                // Temporarily route the writer to gzwrite for the helper.
                $previous_writer = $this->writer;
                $this->writer    = 'gzwrite';
                try {
                    gzwrite( $gz, "\n-- Structure-only tables (excluded from data dump per user setting)\n" );
                    gzwrite( $gz, "SET FOREIGN_KEY_CHECKS = 0;\n\n" );
                    foreach ( $structure_only_tables as $table ) {
                        $this->export_table_structure_only( $gz, $table );
                    }
                    gzwrite( $gz, "\nSET FOREIGN_KEY_CHECKS = 1;\n" );
                } finally {
                    $this->writer = $previous_writer;
                }
            }
        } finally {
            fclose( $in );
            gzclose( $gz );
        }

        if ( $repaired > 0 ) {
            Mighty_Backup_Log_Stream::add( sprintf(
                'Sanitized %d line(s) containing wpdb placeholder hashes from the dump.',
                $repaired
            ) );
        }
    }

    /**
     * Export using pure PHP via $wpdb (fallback when mysqldump is unavailable).
     */
    private function export_with_php( string $output_path ): int {
        global $wpdb;

        if ( ! function_exists( 'gzopen' ) ) {
            throw new \Exception( 'The zlib PHP extension is required for database export.' );
        }

        $gzip_level = $this->get_gzip_level();
        $gz = gzopen( $output_path, 'wb' . $gzip_level );
        if ( ! $gz ) {
            throw new \Exception( "Failed to open output file: {$output_path}" );
        }

        try {
            $this->write_preamble( $gz );
            $tables      = $this->get_tables();
            $table_count = count( $tables );

            Mighty_Backup_Log_Stream::add( 'Using PHP fallback for database export (' . $table_count . ' tables)' );

            foreach ( $tables as $i => $table ) {
                $mode = $this->table_mode( $table );
                if ( $mode === 'skip' ) {
                    continue;
                }
                Mighty_Backup_Log_Stream::add( 'Exporting table ' . ( $i + 1 ) . '/' . $table_count . ': ' . $table );
                if ( $mode === 'structure_only' ) {
                    $this->export_table_structure_only( $gz, $table );
                } else {
                    $this->export_table( $gz, $table );
                }
            }

            $this->write_postamble( $gz );
        } finally {
            gzclose( $gz );
        }

        $size = filesize( $output_path );
        if ( $size === false ) {
            throw new \Exception( "Failed to read output file size: {$output_path}" );
        }

        return $size;
    }

    // ──────────────────────────────────────────────
    //  Streamlined export paths
    // ──────────────────────────────────────────────

    /**
     * Streamlined hybrid: mysqldump for bulk tables, PHP for special tables.
     *
     * 1. Runs mysqldump (uncompressed) to a temp file.
     * 2. Streams the mysqldump output + PHP special tables through a single gzip handle.
     *
     * This avoids concatenated gzip members (RFC 1952) which some tools don't support.
     */
    private function export_streamlined_hybrid( string $output_path ): int {
        $special = $this->get_streamlined_special_tables();
        $gzip_level = $this->get_gzip_level();

        // Apply user overrides on top of the streamlined config:
        // - "skip"            → drop from any streamlined category; always ignored by mysqldump.
        // - "structure_only"  → ignored by mysqldump and added to log_tables for the structure append.
        foreach ( $this->excluded_tables as $excluded => $_ ) {
            $special['log_tables']   = array_values( array_diff( $special['log_tables'], [ $excluded ] ) );
            unset( $special['order_tables'][ $excluded ] );
        }
        foreach ( $this->structure_only_tables as $struct => $_ ) {
            unset( $special['order_tables'][ $struct ] );
            if ( ! in_array( $struct, $special['log_tables'], true ) ) {
                $special['log_tables'][] = $struct;
            }
        }

        // Step 1: mysqldump everything except special tables to a temp SQL file.
        $ignore_tables = array_merge(
            $special['log_tables'],
            array_keys( $special['order_tables'] ),
            array_keys( $this->excluded_tables )
        );

        // If legacy mode, also exclude posts and postmeta from mysqldump.
        if ( ! empty( $special['legacy'] ) ) {
            global $wpdb;
            $ignore_tables[] = $wpdb->posts;
            $ignore_tables[] = $wpdb->postmeta;
        }

        $ignore_flags = $this->build_ignore_flags( $ignore_tables );

        $bin = $this->get_dump_binary();
        Mighty_Backup_Log_Stream::add( 'Using ' . $bin . ' (streamlined) for bulk tables' );

        $raw_path = $output_path . '.raw.sql';
        $err_path = $output_path . '.err';
        $defaults = $this->write_mysql_defaults();

        try {
            $command = sprintf(
                '%s --defaults-extra-file=%s --single-transaction --quick --skip-lock-tables --set-charset '
                . '--default-character-set=utf8mb4 --no-tablespaces '
                . '%s %s > %s 2>%s',
                escapeshellcmd( $bin ),
                escapeshellarg( $defaults ),
                $ignore_flags,
                escapeshellarg( DB_NAME ),
                escapeshellarg( $raw_path ),
                escapeshellarg( $err_path )
            );

            exec( $command, $output, $return_code );
        } finally {
            @unlink( $defaults );
        }

        $stderr = file_exists( $err_path ) ? (string) file_get_contents( $err_path ) : '';
        @unlink( $err_path );
        $stderr = $this->filter_dump_stderr( $stderr );

        if ( $return_code !== 0 ) {
            @unlink( $raw_path );
            throw new \Exception( "{$bin} failed (exit {$return_code}): {$stderr}" );
        }

        // Step 2: Stream mysqldump SQL + special tables through a single gzip handle.
        $gz = gzopen( $output_path, 'wb' . $gzip_level );
        if ( ! $gz ) {
            @unlink( $raw_path );
            throw new \Exception( "Failed to open output file for writing: {$output_path}" );
        }

        $sanitize = (bool) apply_filters( 'mighty_backup_sanitize_placeholder_hashes', true );

        try {
            // Stream the raw mysqldump SQL into the gzip handle. When the
            // sanitize filter is on (default), each line is checked for
            // wpdb placeholder hashes and repaired before being written.
            $fh = fopen( $raw_path, 'rb' );
            if ( ! $fh ) {
                throw new \Exception( "Failed to read mysqldump output: {$raw_path}" );
            }

            if ( $sanitize ) {
                $repaired = 0;
                while ( ( $line = fgets( $fh ) ) !== false ) {
                    if ( strpos( $line, '{' ) !== false ) {
                        $sanitized = Mighty_Backup_Placeholder_Repair::sanitize_string( $line );
                        if ( $sanitized !== $line ) {
                            ++$repaired;
                            $line = $sanitized;
                        }
                    }
                    $this->write( $gz, $line );
                }
                if ( $repaired > 0 ) {
                    Mighty_Backup_Log_Stream::add( sprintf(
                        'Sanitized %d line(s) containing wpdb placeholder hashes from the dump.',
                        $repaired
                    ) );
                }
            } else {
                while ( ! feof( $fh ) ) {
                    $chunk = fread( $fh, 65536 ); // 64 KB chunks.
                    if ( $chunk !== false && $chunk !== '' ) {
                        $this->write( $gz, $chunk );
                    }
                }
            }
            fclose( $fh );

            // Append special tables via PHP into the same gzip stream.
            Mighty_Backup_Log_Stream::add( 'Appending filtered/structure-only tables via PHP' );
            $this->export_streamlined_special_tables( $gz, $special );
        } finally {
            gzclose( $gz );
            @unlink( $raw_path );
        }

        $size = filesize( $output_path );
        if ( $size === false || $size === 0 ) {
            throw new \Exception( 'Streamlined export produced an empty file.' );
        }

        return $size;
    }

    /**
     * Streamlined PHP-only: full PHP export with per-table routing.
     */
    private function export_streamlined_php( string $output_path ): int {
        if ( ! function_exists( 'gzopen' ) ) {
            throw new \Exception( 'The zlib PHP extension is required for database export.' );
        }

        $special    = $this->get_streamlined_special_tables();
        $gzip_level = $this->get_gzip_level();
        $gz = gzopen( $output_path, 'wb' . $gzip_level );
        if ( ! $gz ) {
            throw new \Exception( "Failed to open output file: {$output_path}" );
        }

        try {
            global $wpdb;

            $this->write_preamble( $gz );
            $tables      = $this->get_tables();
            $table_count = count( $tables );

            Mighty_Backup_Log_Stream::add( 'Using PHP fallback for streamlined export (' . $table_count . ' tables)' );

            $log_tables   = $special['log_tables'];
            $order_tables = $special['order_tables'];
            $is_legacy    = ! empty( $special['legacy'] );

            $order_ids      = $this->get_recent_order_ids();
            $order_item_ids = $this->get_order_item_ids( $order_ids );

            foreach ( $tables as $i => $table ) {
                $mode = $this->table_mode( $table );
                if ( $mode === 'skip' ) {
                    continue;
                }
                Mighty_Backup_Log_Stream::add( 'Exporting table ' . ( $i + 1 ) . '/' . $table_count . ': ' . $table );
                if ( $mode === 'structure_only' ) {
                    // User override wins over streamlined rules.
                    $this->export_table_structure_only( $gz, $table );
                } elseif ( in_array( $table, $log_tables, true ) ) {
                    // Structure only for log tables.
                    $this->export_table_structure_only( $gz, $table );
                } elseif ( isset( $order_tables[ $table ] ) ) {
                    // Filtered export for order tables.
                    $id_column  = $order_tables[ $table ];
                    $id_list    = ( $id_column === 'order_item_id' ) ? $order_item_ids : $order_ids;
                    $this->export_table_filtered( $gz, $table, $id_column, $id_list );
                } elseif ( $is_legacy && $table === $wpdb->posts ) {
                    $this->export_table_posts_streamlined( $gz, $table, $order_ids );
                } elseif ( $is_legacy && $table === $wpdb->postmeta ) {
                    $this->export_table_postmeta_streamlined( $gz, $table, $order_ids );
                } else {
                    // Normal full export.
                    $this->export_table( $gz, $table );
                }
            }

            $this->write_postamble( $gz );
        } finally {
            gzclose( $gz );
        }

        $size = filesize( $output_path );
        if ( $size === false ) {
            throw new \Exception( "Failed to read output file size: {$output_path}" );
        }

        return $size;
    }

    /**
     * Export the special tables (log + order) for the streamlined hybrid path.
     */
    private function export_streamlined_special_tables( $gz, array $special ): void {
        global $wpdb;

        $this->write( $gz, "\n-- Streamlined export: filtered tables\n" );
        $this->write( $gz, "SET FOREIGN_KEY_CHECKS = 0;\n\n" );

        // Log tables (and any user "structure_only" entries merged in): structure only.
        foreach ( $special['log_tables'] as $table ) {
            if ( isset( $this->excluded_tables[ $table ] ) ) {
                continue;
            }
            $this->export_table_structure_only( $gz, $table );
        }

        // Order tables: filtered by recent order IDs.
        $order_ids      = $this->get_recent_order_ids();
        $order_item_ids = $this->get_order_item_ids( $order_ids );

        foreach ( $special['order_tables'] as $table => $id_column ) {
            if ( isset( $this->excluded_tables[ $table ] ) ) {
                continue;
            }
            if ( isset( $this->structure_only_tables[ $table ] ) ) {
                $this->export_table_structure_only( $gz, $table );
                continue;
            }
            $id_list = ( $id_column === 'order_item_id' ) ? $order_item_ids : $order_ids;
            $this->export_table_filtered( $gz, $table, $id_column, $id_list );
        }

        // Legacy posts/postmeta handling — honor user overrides.
        if ( ! empty( $special['legacy'] ) ) {
            $this->emit_streamlined_legacy_table( $gz, $wpdb->posts, $order_ids, 'posts' );
            $this->emit_streamlined_legacy_table( $gz, $wpdb->postmeta, $order_ids, 'postmeta' );
        }

        $this->write( $gz, "\nSET FOREIGN_KEY_CHECKS = 1;\nCOMMIT;\n" );
    }

    /**
     * Emit a legacy streamlined table (posts/postmeta) while honoring user overrides.
     */
    private function emit_streamlined_legacy_table( $gz, string $table, array $order_ids, string $which ): void {
        if ( isset( $this->excluded_tables[ $table ] ) ) {
            return;
        }
        if ( isset( $this->structure_only_tables[ $table ] ) ) {
            $this->export_table_structure_only( $gz, $table );
            return;
        }
        if ( $which === 'posts' ) {
            $this->export_table_posts_streamlined( $gz, $table, $order_ids );
        } else {
            $this->export_table_postmeta_streamlined( $gz, $table, $order_ids );
        }
    }

    // ──────────────────────────────────────────────
    //  Streamlined helper methods
    // ──────────────────────────────────────────────

    /**
     * Identify tables that need special handling in streamlined mode.
     *
     * @return array{log_tables: string[], order_tables: array<string, string>, legacy: bool}
     */
    private function get_streamlined_special_tables(): array {
        global $wpdb;

        $all_tables = $this->get_tables();
        $prefix     = $wpdb->prefix;

        // Log tables — structure only, no data.
        $log_tables = [];
        $action_scheduler_tables = [
            $prefix . 'actionscheduler_actions',
            $prefix . 'actionscheduler_claims',
            $prefix . 'actionscheduler_groups',
            $prefix . 'actionscheduler_logs',
        ];

        foreach ( $all_tables as $table ) {
            $is_log = false;

            // Action Scheduler tables.
            if ( in_array( $table, $action_scheduler_tables, true ) ) {
                $is_log = true;
            }

            // Plugin log table.
            if ( $table === $prefix . 'bm_backup_log' ) {
                $is_log = true;
            }

            // Tables ending in _log or _logs.
            if ( preg_match( '/_logs?$/', $table ) ) {
                $is_log = true;
            }

            // Filterable.
            $is_log = (bool) apply_filters( 'mighty_backup_is_log_table', $is_log, $table );

            if ( $is_log ) {
                $log_tables[] = $table;
            }
        }

        // Order tables — filtered by recent order IDs.
        $order_tables = [];

        // HPOS tables.
        $hpos_tables = [
            $prefix . 'wc_orders'                  => 'id',
            $prefix . 'wc_orders_meta'              => 'order_id',
            $prefix . 'wc_order_addresses'          => 'order_id',
            $prefix . 'wc_order_operational_data'   => 'order_id',
        ];

        $has_hpos = $this->table_exists( $prefix . 'wc_orders' );

        if ( $has_hpos ) {
            foreach ( $hpos_tables as $table => $id_col ) {
                if ( $this->table_exists( $table ) ) {
                    $order_tables[ $table ] = $id_col;
                }
            }
        }

        // Shared WooCommerce tables (present regardless of HPOS).
        $shared_tables = [
            $prefix . 'woocommerce_order_items'    => 'order_id',
            $prefix . 'woocommerce_order_itemmeta' => 'order_item_id',
            $prefix . 'wc_order_stats'             => 'order_id',
        ];

        foreach ( $shared_tables as $table => $id_col ) {
            if ( $this->table_exists( $table ) ) {
                $order_tables[ $table ] = $id_col;
            }
        }

        // Filterable.
        $order_tables = (array) apply_filters( 'mighty_backup_order_table_config', $order_tables );

        // Legacy flag: orders live in wp_posts when HPOS table doesn't exist.
        $legacy = ! $has_hpos;

        return [
            'log_tables'   => $log_tables,
            'order_tables' => $order_tables,
            'legacy'       => $legacy,
        ];
    }

    /**
     * Get order IDs from the last N days.
     *
     * @return array<int> Order IDs.
     */
    private function get_recent_order_ids(): array {
        global $wpdb;

        $days     = (int) apply_filters( 'mighty_backup_streamlined_days', 90 );
        $cutoff   = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
        $prefix   = $wpdb->prefix;

        // Try HPOS table first.
        if ( $this->table_exists( $prefix . 'wc_orders' ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            return array_map( 'intval', $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT id FROM `{$prefix}wc_orders` WHERE date_created_gmt >= %s",
                    $cutoff
                )
            ) );
        }

        // Fall back to legacy wp_posts.
        return array_map( 'intval', $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM `{$wpdb->posts}` WHERE post_type IN ('shop_order', 'shop_order_refund') AND post_date_gmt >= %s",
                $cutoff
            )
        ) );
    }

    /**
     * Get order item IDs for a set of order IDs.
     *
     * @param array<int> $order_ids
     * @return array<int> Order item IDs.
     */
    private function get_order_item_ids( array $order_ids ): array {
        global $wpdb;

        if ( empty( $order_ids ) ) {
            return [];
        }

        $prefix = $wpdb->prefix;
        $table  = $prefix . 'woocommerce_order_items';

        if ( ! $this->table_exists( $table ) ) {
            return [];
        }

        $item_ids = [];
        $chunks   = array_chunk( $order_ids, 500 );

        foreach ( $chunks as $chunk ) {
            $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $results = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT order_item_id FROM `{$table}` WHERE order_id IN ({$placeholders})",
                    ...$chunk
                )
            );
            $item_ids = array_merge( $item_ids, array_map( 'intval', $results ) );
        }

        return $item_ids;
    }

    /** @var array<string, true>|null Cached set of all table names. */
    private ?array $table_cache = null;

    /**
     * Check if a table exists in the database (uses a cached SHOW TABLES).
     */
    private function table_exists( string $table ): bool {
        if ( $this->table_cache === null ) {
            $this->table_cache = array_fill_keys( $this->get_tables(), true );
        }

        return isset( $this->table_cache[ $table ] );
    }

    // ──────────────────────────────────────────────
    //  Table export methods
    // ──────────────────────────────────────────────

    /**
     * Export a table's structure only (CREATE TABLE, no data).
     */
    private function export_table_structure_only( $gz, string $table ): void {
        global $wpdb;

        $this->write( $gz, "--\n-- Table: `{$table}` (structure only — streamlined)\n--\n\n" );
        $this->write( $gz, "DROP TABLE IF EXISTS `{$table}`;\n" );

        $create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
        if ( ! $create || empty( $create[1] ) ) {
            $this->write( $gz, "-- WARNING: Could not get CREATE TABLE for `{$table}`\n\n" );
            return;
        }
        $this->write( $gz, $create[1] . ";\n\n" );
    }

    /**
     * Export a table filtered by an ID column and allowed ID list.
     *
     * Exports structure + only rows where $id_column IN ($allowed_ids).
     */
    private function export_table_filtered( $gz, string $table, string $id_column, array $allowed_ids ): void {
        global $wpdb;

        // Structure first.
        $this->write( $gz, "--\n-- Table: `{$table}` (filtered — streamlined)\n--\n\n" );
        $this->write( $gz, "DROP TABLE IF EXISTS `{$table}`;\n" );

        $create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
        if ( ! $create || empty( $create[1] ) ) {
            $this->write( $gz, "-- WARNING: Could not get CREATE TABLE for `{$table}`\n\n" );
            return;
        }
        $this->write( $gz, $create[1] . ";\n\n" );

        // No data if no matching IDs.
        if ( empty( $allowed_ids ) ) {
            $this->write( $gz, "-- No matching rows for streamlined export.\n\n" );
            return;
        }

        // Export data in chunks to avoid oversized queries.
        $chunks      = array_chunk( $allowed_ids, 500 );
        $pk_column   = $this->get_primary_key( $table );
        $binary_cols = $this->get_binary_columns( $table );

        foreach ( $chunks as $chunk ) {
            $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

            if ( $pk_column ) {
                // PK-paginated within the chunk.
                $last_id       = 0;
                $insert_buffer = [];

                while ( true ) {
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $rows = $wpdb->get_results(
                        $wpdb->prepare(
                            "SELECT * FROM `{$table}` WHERE `{$id_column}` IN ({$placeholders}) AND `{$pk_column}` > %d ORDER BY `{$pk_column}` ASC LIMIT %d",
                            ...array_merge( $chunk, [ $last_id, $this->batch_size ] )
                        ),
                        ARRAY_A
                    );

                    if ( empty( $rows ) ) {
                        break;
                    }

                    foreach ( $rows as $row ) {
                        $last_id = $row[ $pk_column ];
                        $insert_buffer[] = $this->build_values_string( $row, $binary_cols );

                        if ( count( $insert_buffer ) >= $this->insert_batch ) {
                            $this->flush_inserts( $gz, $table, $insert_buffer );
                            $insert_buffer = [];
                        }
                    }

                    unset( $rows );
                }

                if ( ! empty( $insert_buffer ) ) {
                    $this->flush_inserts( $gz, $table, $insert_buffer );
                }
            } else {
                // Offset-based for tables without a PK.
                $offset        = 0;
                $batch_size    = 500;
                $insert_buffer = [];

                while ( true ) {
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $rows = $wpdb->get_results(
                        $wpdb->prepare(
                            "SELECT * FROM `{$table}` WHERE `{$id_column}` IN ({$placeholders}) LIMIT %d OFFSET %d",
                            ...array_merge( $chunk, [ $batch_size, $offset ] )
                        ),
                        ARRAY_A
                    );

                    if ( empty( $rows ) ) {
                        break;
                    }

                    foreach ( $rows as $row ) {
                        $insert_buffer[] = $this->build_values_string( $row, $binary_cols );

                        if ( count( $insert_buffer ) >= $this->insert_batch ) {
                            $this->flush_inserts( $gz, $table, $insert_buffer );
                            $insert_buffer = [];
                        }
                    }

                    $offset += $batch_size;
                    unset( $rows );
                }

                if ( ! empty( $insert_buffer ) ) {
                    $this->flush_inserts( $gz, $table, $insert_buffer );
                }
            }
        }

        $this->write( $gz, "\n" );
    }

    /**
     * Streamlined export for wp_posts (legacy order storage).
     *
     * Exports all non-order posts + only recent order posts.
     */
    private function export_table_posts_streamlined( $gz, string $table, array $order_ids ): void {
        global $wpdb;

        $this->write( $gz, "--\n-- Table: `{$table}` (streamlined — legacy orders)\n--\n\n" );
        $this->write( $gz, "DROP TABLE IF EXISTS `{$table}`;\n" );

        $create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
        if ( ! $create || empty( $create[1] ) ) {
            $this->write( $gz, "-- WARNING: Could not get CREATE TABLE for `{$table}`\n\n" );
            return;
        }
        $this->write( $gz, $create[1] . ";\n\n" );

        // Pass 1: All non-order posts.
        $binary_cols   = $this->get_binary_columns( $table );
        $last_id       = 0;
        $insert_buffer = [];

        while ( true ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM `{$table}` WHERE post_type NOT IN ('shop_order', 'shop_order_refund') AND ID > %d ORDER BY ID ASC LIMIT %d",
                    $last_id,
                    $this->batch_size
                ),
                ARRAY_A
            );

            if ( empty( $rows ) ) {
                break;
            }

            foreach ( $rows as $row ) {
                $last_id = $row['ID'];
                $insert_buffer[] = $this->build_values_string( $row, $binary_cols );

                if ( count( $insert_buffer ) >= $this->insert_batch ) {
                    $this->flush_inserts( $gz, $table, $insert_buffer );
                    $insert_buffer = [];
                }
            }

            unset( $rows );
        }

        if ( ! empty( $insert_buffer ) ) {
            $this->flush_inserts( $gz, $table, $insert_buffer );
        }

        // Pass 2: Recent order posts only.
        if ( ! empty( $order_ids ) ) {
            $chunks = array_chunk( $order_ids, 500 );

            foreach ( $chunks as $chunk ) {
                $placeholders  = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
                $last_id       = 0;
                $insert_buffer = [];

                while ( true ) {
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $rows = $wpdb->get_results(
                        $wpdb->prepare(
                            "SELECT * FROM `{$table}` WHERE post_type IN ('shop_order', 'shop_order_refund') AND ID IN ({$placeholders}) AND ID > %d ORDER BY ID ASC LIMIT %d",
                            ...array_merge( $chunk, [ $last_id, $this->batch_size ] )
                        ),
                        ARRAY_A
                    );

                    if ( empty( $rows ) ) {
                        break;
                    }

                    foreach ( $rows as $row ) {
                        $last_id = $row['ID'];
                        $insert_buffer[] = $this->build_values_string( $row, $binary_cols );

                        if ( count( $insert_buffer ) >= $this->insert_batch ) {
                            $this->flush_inserts( $gz, $table, $insert_buffer );
                            $insert_buffer = [];
                        }
                    }

                    unset( $rows );
                }

                if ( ! empty( $insert_buffer ) ) {
                    $this->flush_inserts( $gz, $table, $insert_buffer );
                }
            }
        }

        $this->write( $gz, "\n" );
    }

    /**
     * Streamlined export for wp_postmeta (legacy order storage).
     *
     * Exports all postmeta except for old (excluded) order IDs.
     */
    private function export_table_postmeta_streamlined( $gz, string $table, array $recent_order_ids ): void {
        global $wpdb;

        $this->write( $gz, "--\n-- Table: `{$table}` (streamlined — legacy orders)\n--\n\n" );
        $this->write( $gz, "DROP TABLE IF EXISTS `{$table}`;\n" );

        $create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
        if ( ! $create || empty( $create[1] ) ) {
            $this->write( $gz, "-- WARNING: Could not get CREATE TABLE for `{$table}`\n\n" );
            return;
        }
        $this->write( $gz, $create[1] . ";\n\n" );

        // Get all old order IDs (orders NOT in the recent set).
        $all_order_ids = array_map( 'intval', $wpdb->get_col(
            "SELECT ID FROM `{$wpdb->posts}` WHERE post_type IN ('shop_order', 'shop_order_refund')"
        ) );

        $old_order_ids = array_diff( $all_order_ids, $recent_order_ids );
        unset( $all_order_ids );

        if ( empty( $old_order_ids ) ) {
            // No old orders to exclude — export everything normally.
            $this->export_table_data_pk( $gz, $table, 'meta_id' );
            $this->write( $gz, "\n" );
            return;
        }

        // Export postmeta excluding old order post_ids, using PK pagination.
        // We chunk the exclusion list and use NOT IN to keep memory bounded.
        $binary_cols   = $this->get_binary_columns( $table );
        $last_id       = 0;
        $insert_buffer = [];

        // Paginate through postmeta by PK and skip rows where post_id is in the old set.
        $old_set = array_flip( $old_order_ids );

        while ( true ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM `{$table}` WHERE meta_id > %d ORDER BY meta_id ASC LIMIT %d",
                    $last_id,
                    $this->batch_size
                ),
                ARRAY_A
            );

            if ( empty( $rows ) ) {
                break;
            }

            foreach ( $rows as $row ) {
                $last_id = $row['meta_id'];

                // Skip postmeta belonging to old orders.
                if ( isset( $old_set[ (int) $row['post_id'] ] ) ) {
                    continue;
                }

                $insert_buffer[] = $this->build_values_string( $row, $binary_cols );

                if ( count( $insert_buffer ) >= $this->insert_batch ) {
                    $this->flush_inserts( $gz, $table, $insert_buffer );
                    $insert_buffer = [];
                }
            }

            unset( $rows );
        }

        if ( ! empty( $insert_buffer ) ) {
            $this->flush_inserts( $gz, $table, $insert_buffer );
        }

        $this->write( $gz, "\n" );
    }

    // ──────────────────────────────────────────────
    //  Shared methods (used by both full and streamlined paths)
    // ──────────────────────────────────────────────

    /**
     * Write a temporary MySQL defaults file for mysqldump authentication.
     *
     * Avoids MYSQL_PWD environment variable which is visible in /proc and
     * deprecated in MySQL 8.0.
     *
     * @return string Path to the temporary .cnf file. Caller must unlink when done.
     */
    private function write_mysql_defaults(): string {
        $path     = get_temp_dir() . 'bm-backup-my-' . wp_generate_password( 8, false ) . '.cnf';
        $contents = "[client]\n"
            . 'user=' . DB_USER . "\n"
            . 'password=' . DB_PASSWORD . "\n"
            . 'host=' . DB_HOST . "\n";
        file_put_contents( $path, $contents );
        chmod( $path, 0600 );
        return $path;
    }

    /**
     * Get the configured gzip compression level.
     */
    private function get_gzip_level(): int {
        return max( 1, min( 9, (int) apply_filters( 'mighty_backup_db_gzip_level', 3 ) ) );
    }

    /**
     * Write SQL preamble (character set, modes, etc).
     */
    private function write_preamble( $gz ): void {
        $header = "-- Mighty Backup\n"
                . "-- Generated: " . gmdate( 'Y-m-d H:i:s' ) . " UTC\n"
                . "-- Plugin Version: " . MIGHTY_BACKUP_VERSION . "\n"
                . ( $this->streamlined ? "-- Mode: Streamlined\n" : '' )
                . "\nSET NAMES utf8mb4;\n"
                . "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n"
                . "SET FOREIGN_KEY_CHECKS = 0;\n"
                . "SET AUTOCOMMIT = 0;\n\n";
        $this->write( $gz, $header );
    }

    /**
     * Write SQL postamble.
     */
    private function write_postamble( $gz ): void {
        $footer = "\nSET FOREIGN_KEY_CHECKS = 1;\n"
                . "COMMIT;\n";
        $this->write( $gz, $footer );
    }

    /**
     * Get all tables in the database.
     */
    private function get_tables(): array {
        global $wpdb;
        return $wpdb->get_col( 'SHOW TABLES' );
    }

    /**
     * Export a single table (structure + data).
     */
    /**
     * Export a single table (DDL + data). Resumable across Action Scheduler
     * chunks for tables with a single-column primary key.
     *
     * When $resume is null (fresh start): writes DROP/CREATE preamble, detects
     * the primary key, and begins data export from the start. When $resume is
     * non-null: skips the preamble (already written in a prior chunk) and
     * continues data export past $resume['last_pk'].
     *
     * Time-bounding (via $started_at + $chunk_seconds > 0) only applies to the
     * keyset-paginated PK path. Tables without a single-column PK run to
     * completion regardless of budget — pause-and-resume isn't safe with
     * LIMIT/OFFSET pagination under live writes.
     *
     * @param array|null  $resume         {pk_column, last_pk} for mid-table resume, or null for fresh start.
     * @param int|null    $started_at     Wall-time anchor; null disables time-bounding.
     * @param int         $chunk_seconds  Soft time budget; 0 disables.
     * @return array{done: bool, pk_column: ?string, last_pk: mixed} pk_column/last_pk are populated when done=false so the caller can persist resume state.
     */
    private function export_table(
        $gz,
        string $table,
        ?array $resume = null,
        ?int $started_at = null,
        int $chunk_seconds = 0
    ): array {
        global $wpdb;

        $resuming  = $resume !== null && isset( $resume['pk_column'], $resume['last_pk'] );
        $pk_column = $resuming ? $resume['pk_column'] : $this->get_primary_key( $table );

        if ( ! $resuming ) {
            $this->write( $gz, "--\n-- Table: `{$table}`\n--\n\n" );
            $this->write( $gz, "DROP TABLE IF EXISTS `{$table}`;\n" );

            $create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
            if ( ! $create || empty( $create[1] ) ) {
                $this->write( $gz, "-- WARNING: Could not get CREATE TABLE for `{$table}`\n\n" );
                return [ 'done' => true, 'pk_column' => null, 'last_pk' => null ];
            }
            $this->write( $gz, $create[1] . ";\n\n" );
        }

        if ( $pk_column ) {
            $start_after = $resuming ? $resume['last_pk'] : 0;
            $result      = $this->export_table_data_pk(
                $gz, $table, $pk_column, $start_after, $started_at, $chunk_seconds
            );

            if ( ! $result['done'] ) {
                // Paused mid-table — leave the trailing newline off; it'll be
                // appended when the table actually completes. SQL is valid
                // either way since each flushed INSERT ends with ';'.
                return [
                    'done'      => false,
                    'pk_column' => $pk_column,
                    'last_pk'   => $result['last_pk'],
                ];
            }
        } else {
            // No single-column PK — run to completion in this chunk. The
            // chunk-time budget is ignored here; LIMIT/OFFSET pagination can't
            // safely resume under concurrent writes.
            if ( $chunk_seconds > 0 && class_exists( 'Mighty_Backup_Log_Stream' ) ) {
                Mighty_Backup_Log_Stream::add(
                    "Table {$table}: no primary key — exporting single-shot (cannot resume mid-table)"
                );
            }
            $this->export_table_data_offset( $gz, $table );
        }

        $this->write( $gz, "\n" );

        return [ 'done' => true, 'pk_column' => $pk_column, 'last_pk' => null ];
    }

    /**
     * Detect the primary key column for a table.
     *
     * @return string|null Column name, or null if no single-column PK.
     */
    private function get_primary_key( string $table ): ?string {
        global $wpdb;

        $keys = $wpdb->get_results(
            $wpdb->prepare(
                "SHOW KEYS FROM `{$table}` WHERE Key_name = %s",
                'PRIMARY'
            ),
            ARRAY_A
        );

        if ( count( $keys ) === 1 ) {
            return $keys[0]['Column_name'];
        }

        return null;
    }

    /**
     * Export table data using primary-key-based pagination. Resumable across
     * Action Scheduler chunks: pass $start_after_pk to continue past a prior
     * chunk's last-written row, and pass $chunk_seconds > 0 to bail out cleanly
     * when the wall-time budget is exhausted between batches.
     *
     * @param mixed       $start_after_pk PK threshold; only rows with PK > this are exported. Use 0 to start from the beginning.
     * @param int|null    $started_at     Wall-time anchor for the time budget (null disables time-bounding).
     * @param int         $chunk_seconds  Soft time budget; 0 disables (run to completion).
     * @return array{done: bool, last_pk: mixed} done=true means the table is fully exported.
     */
    private function export_table_data_pk(
        $gz,
        string $table,
        string $pk_column,
        $start_after_pk = 0,
        ?int $started_at = null,
        int $chunk_seconds = 0
    ): array {
        global $wpdb;

        $binary_cols   = $this->get_binary_columns( $table );
        $last_id       = $start_after_pk;
        $insert_buffer = [];
        $time_bounded  = $started_at !== null && $chunk_seconds > 0;

        while ( true ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM `{$table}` WHERE `{$pk_column}` > %s ORDER BY `{$pk_column}` ASC LIMIT %d",
                    $last_id,
                    $this->batch_size
                ),
                ARRAY_A
            );

            if ( empty( $rows ) ) {
                break;
            }

            foreach ( $rows as $row ) {
                $last_id = $row[ $pk_column ];
                $insert_buffer[] = $this->build_values_string( $row, $binary_cols );

                if ( count( $insert_buffer ) >= $this->insert_batch ) {
                    $this->flush_inserts( $gz, $table, $insert_buffer );
                    $insert_buffer = [];
                }
            }

            unset( $rows );

            // Time check between row-batches — only after the inner buffer is
            // flushed so we never split an INSERT statement across chunks. The
            // last_id we return is the highest PK that has been *durably* written.
            if ( $time_bounded && ( time() - $started_at ) >= $chunk_seconds ) {
                if ( ! empty( $insert_buffer ) ) {
                    $this->flush_inserts( $gz, $table, $insert_buffer );
                    $insert_buffer = [];
                }
                return [ 'done' => false, 'last_pk' => $last_id ];
            }
        }

        if ( ! empty( $insert_buffer ) ) {
            $this->flush_inserts( $gz, $table, $insert_buffer );
        }

        return [ 'done' => true, 'last_pk' => $last_id ];
    }

    /**
     * Export table data using LIMIT/OFFSET (fallback for tables without a PK).
     */
    private function export_table_data_offset( $gz, string $table ): void {
        global $wpdb;

        $binary_cols   = $this->get_binary_columns( $table );
        $offset        = 0;
        $batch_size    = 500;
        $insert_buffer = [];

        while ( true ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM `{$table}` LIMIT %d OFFSET %d",
                    $batch_size,
                    $offset
                ),
                ARRAY_A
            );

            if ( empty( $rows ) ) {
                break;
            }

            foreach ( $rows as $row ) {
                $insert_buffer[] = $this->build_values_string( $row, $binary_cols );

                if ( count( $insert_buffer ) >= $this->insert_batch ) {
                    $this->flush_inserts( $gz, $table, $insert_buffer );
                    $insert_buffer = [];
                }
            }

            $offset += $batch_size;
            unset( $rows );
        }

        if ( ! empty( $insert_buffer ) ) {
            $this->flush_inserts( $gz, $table, $insert_buffer );
        }
    }

    /**
     * Build the VALUES string for a single row.
     *
     * @param array $row          Associative row data.
     * @param array $binary_cols  Set of column names that are binary types (keys = names).
     */
    private function build_values_string( array $row, array $binary_cols = [] ): string {
        global $wpdb;

        $values = [];
        foreach ( $row as $key => $value ) {
            if ( is_null( $value ) ) {
                $values[] = 'NULL';
            } elseif ( isset( $binary_cols[ $key ] ) && $value !== '' ) {
                $values[] = '0x' . bin2hex( $value );
            } else {
                if ( is_string( $value ) ) {
                    $stripped = $wpdb->remove_placeholder_escape( $value );
                    if ( $stripped !== $value ) {
                        ++$this->placeholder_strips;
                        $value = $stripped;
                    }
                }
                $values[] = "'" . esc_sql( $value ) . "'";
            }
        }

        return '(' . implode( ',', $values ) . ')';
    }

    /**
     * Get the set of binary column names for a table.
     *
     * @return array Column names as keys (for isset() lookups).
     */
    private function get_binary_columns( string $table ): array {
        global $wpdb;

        $columns = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A );
        $binary  = [];

        foreach ( $columns as $col ) {
            if ( preg_match( '/binary|blob|bit/i', $col['Type'] ) ) {
                $binary[ $col['Field'] ] = true;
            }
        }

        return $binary;
    }

    /**
     * Write a multi-row INSERT statement to the gzip stream.
     */
    private function flush_inserts( $gz, string $table, array $values_strings ): void {
        $sql = "INSERT INTO `{$table}` VALUES\n"
             . implode( ",\n", $values_strings )
             . ";\n";
        $this->write( $gz, $sql );
    }
}
