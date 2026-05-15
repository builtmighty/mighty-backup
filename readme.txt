=== Mighty Backup ===
Contributors: tylerjohnsondesign
Donate link: https://builtmighty.com
Tags: digital ocean, spaces, backups
Requires at least: 6.0
Tested up to: 6.7
Stable tag: 2.13.0
Requires PHP: 8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automated site backups to DigitalOcean Spaces. Creates nightly and on-demand backups of the database and file system for use with the staged-loader Codespace pipeline.

== Description ==

Automated site backups to DigitalOcean Spaces. Creates nightly and on-demand backups of the database and file system for use with the staged-loader Codespace pipeline.

== Frequently Asked Questions ==

== Screenshots ==

== Changelog ==

= 2.13.0 =
* Added mid-table resumable PHP database export — `export_table_data_pk()` now time-checks between SQL batches and persists `{current_table, current_table_pk, current_table_last_pk}` in `db_export` state so a single large table can be sliced across multiple Action Scheduler chunks; survives any reasonable `max_execution_time` cap (the load-bearing fix for 9 GB single-table sites on hosts like Kinsta)
* Added range-chunked mysqldump path — tables exceeding `db_large_table_threshold_mb` (default 1 GB) are dumped as a sequence of `mysqldump --where='pk > A AND pk <= B'` invocations sized adaptively from the previous range's wall-time; small tables continue to flow through a single mysqldump invocation, and streamlined mode automatically falls back to the chunked PHP path so order/log filtering still applies
* Added pre-flight per-table size snapshot — one `information_schema` query at the start of a chunked export populates the live log with `Table wp_postmeta: 9.0 GB` per table-start, making "stuck on big table" obvious in the UI
* Added Advanced settings disclosure in the Schedule tab — `Chunk Seconds` (10–300, default 30) and `Large-Table Threshold` (128–10240 MB, default 1024) exposed via the existing form; the long-standing `mighty_backup_db_chunk_seconds` filter still wins over the option for `wp-config.php` overrides
* Added error-translator remediation hints for `Maximum execution time of N seconds exceeded` and "table has no primary key" — both deep-link into Settings → Schedule with concrete next steps (raise Chunk Seconds, mark structure-only, or add a PK)
* Fixed `get_large_tables()` skipping structure-only tables — a 9 GB log table marked structure-only would previously have been range-dumped, producing duplicate `CREATE TABLE` statements and exporting the data the user explicitly suppressed
* Fixed CLI `coerce_value` honoring the new `max` field in `KEY_META` — `wp mighty-backup settings set db_chunk_seconds 9999` is now rejected with a clear out-of-range message, matching the web UI clamp
* Fixed `finalize_mysqldump_chunked()` no longer appending the PHP-path postamble (`SET FOREIGN_KEY_CHECKS=1; COMMIT;`) when the placeholder-sanitization filter is disabled — mysqldump's own state-restore block stays authoritative

= 2.12.0 =
* Added onboarding wizard (`admin/views/onboarding-wizard.php`) — five-step chip flow covering Storage Credentials, GitHub Integration, Backup Schedule, Codespace Settings, and Notifications, so new installs see a guided setup instead of an empty settings page
* Added error translator (`includes/class-error-translator.php`) — maps raw exception messages and AWS error codes to human-readable explanations with remediation hints and deep-links into the relevant settings tab, surfaced across the live log, backup history, and AJAX response banners
* Added backup history logger (`includes/class-logger.php`) — custom database table records every backup with type, trigger source, status, timing, file sizes, remote object keys, and error messages for audit/troubleshooting
* Added per-table database export controls — settings page lets you exclude specific tables outright or export them structure-only (schema, no rows); new tables are included by default unless explicitly opted out
* Added tar archive post-verification — file archiver walks the tar.gz end-to-end after creation (shell `tar -tzf` when available, streaming PHP gzip+tar header parser as fallback) to catch archive desync before upload to Spaces
* Fixed database exporter respecting per-table overrides — exclusions and structure-only flags now propagate through the chunked export path, with skipped tables removed from progress counts so the bar no longer overshoots

= 2.10.0 =
* Added independent daily retention cron (`mighty_backup_retention`) — decouples cleanup from backup success so a streak of failed nightly backups can no longer let old objects accumulate on Spaces; the in-chain `step_cleanup` is preserved as the optimal "right after a fresh backup" prune
* Added "Retention last ran" diagnostic line on the Schedule tab — shows when retention last fired and how many database/file backups were removed (or the error message if it failed)
* Added "Last synced" status under the "Push as Codespaces Secret" button — persists owner/repo/timestamp from the most recent successful push (manual or auto) and renders via `human_time_diff()`; updates immediately on successful push without a page reload
* Added 32-core / 256 GB GitHub Codespaces tier to the devcontainer sizing logic — sites with raw size between 107 GB and 213 GB now correctly map to 32-core instead of falling back to a 16-core warning; size warning threshold raised accordingly
* Fixed `calculate_site_disk_size()` silently returning 0 (and thus selecting the smallest 4-core tier) when the iterator hit any unreadable subdirectory — the recursive walker now uses `CATCH_GET_CHILD` to skip unreadable subtrees, per-entry stat errors are caught individually, and root-inaccessibility surfaces as a `RuntimeException` instead of a phantom 0
* Fixed `cpus_to_disk_gb()` mapping for 2-core (now correctly reports 32 GB instead of 16 GB) and added explicit 32-core (256 GB) arm — only affects PR-body disk-size hints, but no longer misleads
* Removed the show/reveal toggle from the GitHub PAT field — the token now stays masked at all times (DO Spaces Access Key and Secret Key fields keep their toggles)

= 2.9.0 =
* Fixed `wpdb::placeholder_escape()` corruption at the source — the PHP database export path now strips `{<64-hex>}` placeholder tokens from every row before writing, so backups created from sites with `%` characters in user data no longer bake the session hash into the dump
* Added serialize-aware sanitize pass to the mysqldump and streamlined-hybrid export paths — strips persisted `{HASH}` tokens from the dump on the way out, recomputing `s:N:"…"` length prefixes; gated by the new `mighty_backup_sanitize_placeholder_hashes` filter (default true) so debugging admins can capture an unmodified dump
* Added `wp mighty-backup repair placeholders [--dry-run] [--no-backup-first]` — scans `options`, `posts`, and core `*meta` tables for persisted placeholder tokens, takes a pre-flight backup, repairs in place via raw `UPDATE` (bypassing `update_option()` so `%` is not re-escaped), and flushes object/page caches (LiteSpeed, WP Rocket, W3TC, plus the new `mighty_backup_after_repair_flush` action)
* Added authed `GET /wp-json/mighty-backup/v1/healthcheck` REST endpoint — Bearer-token authenticated like `/codespace-config`, exposes a `placeholder_hash_corruption` summary (count + sample location) so monitoring and the codespace bootstrap can detect persisted hashes BEFORE they end up in a backup
* Added automatic push of `BM_BOOTSTRAP_KEY` to the configured GitHub repo as a Codespaces secret — fires when a new API key is generated or when GitHub owner/repo/PAT change in the Devcontainer tab; silent best-effort, errors logged to the live backup log without blocking the originating action
* Fixed devcontainer hostRequirements update overwriting sibling fields — `cpus` is now patched in place, preserving any `memory`/`storage` keys that the template declares

= 2.8.0 =
* Added `wp mighty-backup api-key push-secret` — pushes `BM_BOOTSTRAP_KEY` to the configured GitHub repo as an encrypted Codespaces or Actions secret using libsodium sealed-box encryption against the repo's public key

= 2.7.1 =
* Fixed tar exit code 1 ("file changed as we read it") being treated as fatal failure during file archival — exit 1 is a non-fatal warning expected on live sites with active caches, sessions, and logs; only exit code 2+ is now treated as failure

= 2.7.0 =
* Fixed mysqldump false-failure on MariaDB hosts — the MariaDB `mysqldump` shim prints a deprecation warning to stderr on every call; the plugin no longer treats non-empty stderr as failure (gates on exit code only)
* Added `mariadb-dump` binary detection — prefers `mariadb-dump` over `mysqldump` when available, avoiding the deprecation warning entirely and forward-compatible with MariaDB dropping the mysqldump shim
* Added `set -o pipefail` to the mysqldump-to-gzip pipe so dump failures propagate correctly through the pipe

= 2.6.0 =
* Fixed DigitalOcean Spaces secret key not saving on initial save (double-sanitization bug when option did not yet exist) — same fix applied to the GitHub PAT
* Added target-branch selector for devcontainer update PRs — branches are fetched from GitHub during version check and presented as a dropdown (defaulting to the repo's default branch)
* Added `wp mighty-backup settings list|get|set` WP-CLI commands for managing all plugin settings from the command line, with transparent encryption of the DO Spaces secret and GitHub PAT and masked output unless `--show-secret(s)` is passed
* Added `wp mighty-backup api-key generate|show|delete` WP-CLI commands for managing the Codespace bootstrap API key (`bm_backup_api_key`) from the command line
* Added `wp mighty-backup devcontainer check|update` WP-CLI commands for checking the .devcontainer version and creating update PRs (with `--branch` targeting and `--yes` for unattended runs)

= 2.5.0 =
* Fixed backups hanging at "waiting for background processing" in both admin UI and WP-CLI
* Backup actions are now processed directly during status polling, removing dependency on WP-Cron and Action Scheduler's async dispatcher
* Prevents stale Action Scheduler claims from blocking backup execution

= 2.4.0 =
* Chunked database export — PHP-based DB exports now split across multiple Action Scheduler actions for reliable large database backups
* Time-bounded chunks (default 30 seconds per action, filterable via `mighty_backup_db_chunk_seconds`)
* Sub-progress reporting during DB export (shows table count and smooth progress bar interpolation)
* Fixed GitHub update checker URL — plugin updates now work correctly
* Stored update checker instance and set branch to `main` for correct fallback behavior
* Fixed admin UI step indicator pills not highlighting during backup (was referencing wrong property)
* Devcontainer size check now runs automatically during version check — detects when site has outgrown its Codespace tier
* Devcontainer resize creates a targeted PR updating only `hostRequirements.cpus` when version is current but machine is too small
* Codespace tier selection now includes 20% disk headroom
* Devcontainer hostRequirements now sets only `cpus` (storage is implicit with core count on GitHub Codespaces)

= 2.3.0 =
* Devcontainer updates now set hostRequirements (cpus/storage) in devcontainer.json based on site disk size (excluding uploads)
* Added admin warning notice on settings page when site exceeds the 128 GB GitHub Codespace limit
* PR body now includes Codespace tier and site size information

= 2.2.0 =
* Renamed plugin label to "MightyBackup" across admin menu and settings page
* Promoted admin menu item from Settings submenu to a top-level menu item with a cloud icon

= 2.1.0 =
* Added public REST API health-check endpoint (GET /wp-json/mighty-backup/v1/check)
* Added "Check API Health" button on the Codespace settings tab for one-click API reachability testing

= 2.0.0 =
* Renamed plugin from "BuiltMighty Site Backup" to "Mighty Backup"
* Renamed all classes, functions, constants, hooks, filters, and slugs to use the new `Mighty_Backup` / `mighty_backup` naming convention
* Updated WP-CLI command from `bm-backup` to `mighty-backup`
* Updated REST API namespace from `bm-backup/v1` to `mighty-backup/v1`
* Existing database options and table names are preserved — no data migration needed
* Encryption constant `MIGHTY_BACKUP_SECRET` replaces `BM_BACKUP_SECRET` (old constant still accepted for backwards compatibility)

= 1.16.0 =
* Excluded backup plugin directories from file backups (UpdraftPlus, All-in-One WP Migration, Duplicator)
* Excluded production drop-in files from file backups (object-cache.php, advanced-cache.php)

= 1.15.0 =
* Fixed Pressable backups missing plugins and themes — ABSPATH on Pressable points to shared WordPress core (/wordpress/core/X.Y.Z/) while wp-content lives at /srv/htdocs/wp-content; archiver now detects when WP_CONTENT_DIR is outside ABSPATH and archives both locations
* Initialized GitHub update checker — plugin now auto-updates from GitHub releases
* Fixed backup log persisting after completion — log stream now cleared on dismiss
* Added diagnostic logging for archive root paths (helps debug hosting-specific issues)

= 1.14.0 =
* Security: Replaced MYSQL_PWD environment variable with --defaults-extra-file for mysqldump authentication (no longer visible in /proc on shared hosting)
* Fixed binary/BLOB column handling in PHP database export — binary data now exported as hex literals instead of string escaping
* Fixed symlink getRealPath() returning false on broken symlinks — now gracefully skipped with log message
* Added S3 minimum part size enforcement (5 MB floor) to prevent cryptic upload errors from filtered values
* Added empty client_path guard on download endpoint for defense-in-depth path traversal protection
* Improved retention manager — database and file prefix cleanup now independent; partial failures no longer block the other prefix
* Fixed misleading timezone comment in scheduler (uses WordPress timezone, not server timezone)

= 1.13.0 =
* Devcontainer version check now treats missing version field as outdated instead of erroring
* Fixed cross-repo blob SHA issue in devcontainer install/update — blobs are now copied to the target repo before tree creation

= 1.12.0 =
* Fixed stderr redirection in mysqldump — errors no longer silently corrupt SQL dumps
* Added tar safety checks — files > 8 GB or paths > 255 chars are skipped with log warning
* Added --timeout flag to WP-CLI run command (default 6 hours)
* Extracted shared mighty_backup_is_authorized_user() to eliminate code duplication
* Fixed symlink following in file archiver — prevents infinite loops from circular symlinks
* Fixed gzip concatenation in streamlined hybrid export — single gzip stream for compatibility
* Freed large temporary arrays in postmeta streamlined export

= 1.11.0 =
* Full plugin audit — performance hardening and security fixes
* Buffered log stream writes (10x fewer DB queries during backup)
* Lowered default gzip compression level from 6 to 3 for faster exports
* Batched table existence checks (single SHOW TABLES query instead of 8)
* Increased default upload part size from 10 MB to 25 MB
* Added static cache for settings reads across instances
* Added is_authorized_user() check to devcontainer AJAX handlers
* Replaced deprecated openssl_random_pseudo_bytes() with random_bytes()
* Clear live log on backup cancel
* Wrapped scheduler blog switching in try/finally for safety
* Added input validation for schedule_day and schedule_time
* Removed dead code ($old_chunks variable)

= 1.10.0 =
* Added live log stream — real-time backup progress display in admin UI
* Dark terminal-style log box with timestamped entries and auto-scroll
* Incremental log fetching via AJAX polling (since-index tracking)
* Log messages added throughout backup pipeline (DB export, file archive, upload, cleanup)
* Per-table progress logging for PHP database export paths
* Collapsible log box with expand/collapse toggle

= 1.9.0 =
* Added Streamlined Mode for lighter database exports
* Streamlined mode filters WooCommerce orders to last 90 days
* Log tables exported as structure only (no data) in streamlined mode
* Hybrid mysqldump + PHP export path for streamlined mode
* Fixed multisite Network Admin settings page not appearing
* Fixed secret key not saving on multisite (double-sanitization bug)
* Fixed AJAX URL for multisite network admin context

= 1.8.0 =
* Security hardening pass
* Added Codespace bootstrap key system (one-secret setup)
* Added REST API endpoint for Codespace configuration
* Added Devcontainer version check and PR creation
* Added Dev Mode detection (prevents dev sites from overwriting production backups)
* Added WP-CLI commands (run, status, cancel, list, prune, test, dev-mode)
* Added disk space pre-check before backup
* Added email notifications on backup failure

= 1.5.0 =
* Renamed "Client Path" field to "GitHub Repository" — accepts full GitHub URLs, extracts repo slug automatically
* API endpoint now returns `repository` key instead of `client_path`

= 1.3.0 =
* Added cancel-in-progress backup button in admin UI
* Added developer filters for batch size, gzip level, upload part size, concurrency, and retries
* Added action hooks before/after each backup pipeline step and on completion/failure
* Restricted settings page access to authorized email domains (builtmighty.com)
* Credential fields are now write-only — values never rendered to page source
* Optional MIGHTY_BACKUP_SECRET constant in wp-config.php adds extra encryption pepper
* Added PHPUnit test suite (30 tests covering settings, backup manager, retention, and logger)
* Improved admin accessibility (aria-live, role=progressbar, aria-valuenow)

= 1.2.0 =
* Added README.md with full documentation
* Added manual workflow_dispatch trigger to release ZIP workflow

= 1.1.0 =
* Added GitHub plugin updates workflow

= 1.0.0 =
* Initial launch
