# Changelog

## Unreleased

### Changed
* **A new dashboard, without Vue or Vuetify:** Tools → Disembark is rebuilt as plain JavaScript with a custom design. Nothing loads from a CDN any more (Vue, Vuetify, axios, Prism, Material icons and Google Fonts are gone); every script, style and font ships with the plugin. Dialogs became sections: **Back up**, **Restore**, **Files**, **Database** and **Settings**, with light and dark themes. The REST API is unchanged, so the CLI works as before.

### Added
* **Restore points:** Restore lists every saved rollback point (what it was for, when, how big, whether it's been rolled back to) with **Roll back** and **Delete**. New endpoints: `GET /restore-points` and `POST /restore-points/delete`; snapshots take an optional `label`.
* **Search & replace:** Database → **Search & replace** rewrites text across the database with the restore engine (serialized PHP and JSON stay valid). Preview shows the cells, rows and tables that would change with before/after samples; replacing saves a rollback point first and offers Undo. New endpoint: `POST /search-replace` (with `dry_run`).

### Improved
* **Back up:** a step-by-step scan with live counts, a summary of what's included, and commands that update as you leave files or tables out.
* **Restore:** a confirmation before anything changes, a step list (with the full log under "Show details"), the rollback point shown the moment it exists, and "Start another restore" after a rollback.
* **Files:** find any file by path, see the largest items in a folder, and edit with Tab and ⌘S. Files open as raw text, so a JSON file is no longer reformatted when edited. Edit and rename are hidden when file edits are disabled on the site.
* **Database:** editing a row saves only the fields you changed (an untouched `NULL` stays `NULL`), and adding a row leaves empty fields out so auto-increment keys and column defaults apply. Deleting a row asks first.
* **Search & replace pages in key order:** the engine (used by restores too) now reads rows in primary-key order, so a large table can't skip or repeat rows between pages.
* **Settings:** the connection token is masked until you choose to show it, and cleanup keeps restore points unless you ask for them to go too.

## **v2.9.0** - November 17, 2026

### Security
* **Database dumps never reach the web root:** The restore deploy step now refuses every database dump, not just Disembark's own `database-*.sql`: any `.sql` file at the site root (such as a `disembark sync` mirror's `database.sql`) and Disembark's working folders are skipped, even when a client lists them explicitly. CLI 2.8.0 restoring a sync mirror deployed its `database.sql` publicly.
* **Multisite takes a super admin:** On multisite, Disembark (which reads and writes every table and file of the network) now requires a super admin. A subsite administrator's `manage_options` no longer grants access, and the token moved to network options so it no longer shows on a subsite's Settings → All Options screen. The main site's existing token is carried over.
* **File editor honors `DISALLOW_FILE_EDIT` and `DISALLOW_FILE_MODS`:** Saving or renaming files through Disembark is refused when either is set, matching WordPress's own editors.
* **Working files are guarded:** Folders under `uploads/disembark` get blank index files so they can't be listed. A live-site restore deletes each dump, zip and manifest it pulls from the source's uploads as soon as it is downloaded, and drops the source's token from the destination once the database is pulled; finalizing or rolling back removes the rest of the pull state.
* **Backup session ids are sanitized:** A session id can no longer climb out of `uploads/disembark` (e.g. to delete `wp-config.php` through `/cleanup-file`).

### Fixed
* **Rollback can no longer empty the database:** Rollback checks the whole snapshot first (files present, sizes unchanged, fully parsable, every table included, export finished) and changes nothing if it isn't sound. Tables are then replaced one at a time instead of all being dropped up front, and the restored site's extra tables are removed only once the snapshot is fully back. The object cache is flushed afterward.
* **Large database imports no longer run out of memory:** Restores and rollbacks read the SQL dump statement by statement instead of loading the whole file and every statement into memory.
* **The dashboard restore stops on errors:** A file that fails to deploy, or a database import that reports errors, now stops the restore before URLs are rewritten, instead of finishing and reporting success. The rollback id is shown as soon as the rollback point exists, and new **Roll back** / **Undo restore** buttons undo a restore from the dashboard.
* **A restore that dies mid-import stays reachable ([#8](https://github.com/DisembarkHost/disembark/issues/8)):** If the database import is cut off (out of memory, a killed worker) after the source's options replaced this site's token, the token the dashboard or CLI holds still works for that restore for 24 hours. Retrying the import or rolling back keeps that original token, and a finished retry writes it back, instead of adopting the source's token from the half-imported options table.
* **`/cleanup` keeps rollback points and other sessions:** With a session id it removes only that session (the CLI's end-of-backup cleanup). Without one it removes every backup session but keeps restore folders and their rollback points unless `include_restore_points` is passed.
* **Files named `0` no longer stop the scan:** The file scan treated an entry named `0` as the end of its folder and skipped the rest of that folder in backups and pulls.
* **Database exports are complete or fail:** Exports read rows in primary-key order (by key range where the table has a single-column key), so pages can't skip or repeat rows. The multi-table export used by CLI backups, pulls and rollback snapshots now fails with an error, instead of returning a partial dump, when a table can't be read or the disk fills.
* **Update checks are cached:** The self-updater fetched the manifest from GitHub on every admin page load; it now checks once a day and waits an hour after a failed check. Defining `DISEMBARK_DEV_MODE` as `false` no longer turns off TLS verification.
* **Database exports no longer carry "sql" in their name:** Exports are now written as `*.db.txt` instead of `*.sql.txt`, since some hosts refuse to serve any URL matching `*.sql.*`. Rollback points saved by earlier versions still load.
* **Pulls get past hosts that block direct downloads:** When the source's server refuses a direct download of an export or zip (a 2.8.0 source still writes `*.sql.txt`), a live-site restore fetches the file through the source's token-authenticated `/stream-file` endpoint instead.
* **Pulls work without the zip extension:** A live-site restore now falls back to PclZip when `ZipArchive` isn't available.
* **Default exclude follows a custom uploads folder:** Backups skip Disembark's own working folder on Bedrock and custom `UPLOADS` layouts too.
* **`/zip-database` works:** It referenced an undefined variable.
* **Cross-prefix restores of mysqldump files:** `DROP TABLE` and `LOCK TABLES` statements that follow a `--` comment (as mysqldump writes them) are now remapped to the new table prefix, as are foreign-key `REFERENCES`. Before, the lock landed on the old-prefix table and every following insert failed.
* **Upload chunks fit the host:** Preflight now reports the largest upload chunk the destination's PHP limits accept (`upload_chunk_bytes`), and the dashboard restore uses it instead of a fixed 20 MB, which hosts with a small `upload_max_filesize` rejected.
* **`/stream-file` range checks:** A negative `offset` or `length` is clamped, and the reported `Content-Length` never exceeds what the file has left.
* **Manifest lists PHP 7.0:** The update manifest claimed PHP 5.6; the plugin needs 7.0 or newer.
* **URLs inside JSON are rewritten on restore ([#6](https://github.com/DisembarkHost/disembark/issues/6)):** Search/replace now also matches the escaped spellings JSON uses for a URL (`http:\/\/…`, JSON stored inside JSON, and `\u002F`), each rewritten in its own encoding so the JSON stays valid. This covers PHP's default `json_encode()` output, including page-builder data such as Elementor's `_elementor_data`.
* **Protected and private object properties are rewritten on restore ([#7](https://github.com/DisembarkHost/disembark/issues/7)):** Serialized values are now rewritten token by token instead of being unserialized and re-serialized, so URLs in non-public properties (including a parent class's private ones) are updated. No restored object is instantiated during the rewrite, so no plugin `__wakeup()`/`__destruct()` code runs, objects whose class isn't loaded or that have readonly properties are handled, and every byte outside a replaced string (floats, references, `Serializable` payloads) is kept exactly as stored.

## **v2.8.0** - July 17, 2026

### Added
* **Restore a Backup (dashboard):** A new "Restore a Backup" flow under Tools → Disembark restores a site from an uploaded backup `.zip` — staged upload (chunked for large archives), a rollback point saved first, file deploy, database import, URL rewrite, and one-click rollback.
* **Restore from a live site (dashboard):** Migrate directly from another Disembark-connected site — the destination pulls files and database from the source's REST API server-to-server, no intermediate download. Paste the source's `disembark connect …` line into either field to fill both the URL and token.
* **Restore / Import REST API:** New endpoints power all restore entry points (dashboard and CLI) — preflight, rollback snapshot, chunked upload with server-side extraction, staged database import, serialize-aware search/replace, prefix remap, finalize, and rollback.
* **Cross-prefix migration:** Restoring into a site with a different `$table_prefix` remaps table names during import and fixes prefix-scoped data afterward (usermeta capabilities, the `user_roles` option), so migrated users keep their roles.
* **Serialize-aware search/replace:** URL/text replacement runs across the database after import and re-serializes PHP serialized values so length prefixes stay valid (widgets, theme mods, nested and doubly-serialized options survive a URL change).

### Improved
* **Authentication:** Endpoints now authorize administrators first (application password or cookie + REST nonce) and fall back to the per-site token, so the dashboard and CLI can use standard WordPress auth. The token is preserved across a database import so a restore stays authenticated through its final steps.
* **Restore session handling:** The database import replaces the users table (ending the current login), so the restore page suppresses the "session expired" modal and finishes with a clean log-in prompt for the restored site's credentials.

### Security
* **Pull channel verifies TLS (behavior change):** Live-site restores verify the source's certificate and refuse internal/non-http(s) URLs by default. For a self-signed or local source, opt out with `define( 'DISEMBARK_DEV_MODE', true )` or the `disembark_pull_sslverify` filter.
* **SQL transforms are statement-anchored:** The prefix remap and DROP-before-CREATE injection apply only to each statement's leading identifier — post content containing backticked table names, `CREATE TABLE` text, or serialized data can never be corrupted by a restore.
* **Token disclosure:** The dashboard shortcode now renders (and prints the site token) only for users who can manage the site, so it can't leak the token on a front-end page. The token-auth REST bypass is scoped strictly to Disembark's own routes.
* **Import hardening:** Import paths are validated against directory traversal, uploaded archives are extracted zip-slip-safe in both the ZipArchive and PclZip paths, web-root/core path checks no longer match sibling directories, and `wp-config.php` cannot be modified through the file editor or a restore.
* **SQL import:** Statement splitting is quote- and comment-aware, so a value containing `;` no longer corrupts an import or rollback.

## **v2.7.0** - January 28, 2026

### Added
* **Database Explorer:** A new full-screen database explorer allows you to browse tables, view their structure and data, and export selected tables directly from the UI.
* **Folder Downloads:** You can now download entire folders as zip archives directly from the File Explorer.
* **Session Resume:** Previous scan sessions are now saved and displayed on the initial screen, allowing you to resume a prior session without re-scanning.
* **CLI Info Command:** Added the `disembark info` command to the CLI panel, which displays connection status, storage usage, and previous sessions.

### Improved
* **UI Polish:** Refined the settings menu, CLI command panel layout, and dark mode styling for the scan info panel.
* **Clipboard Handling:** Improved copy-to-clipboard functionality across the interface.
* **PHP 7 Compatibility:** Added polyfills for better compatibility with PHP 7 environments.
* **Endpoint Responses:** Cleaned up REST API endpoint responses for consistency.

### Fixed
* **HTTPS Behind Proxy:** Fixed an issue where backup URLs were generated with `http://` instead of `https://` on sites running behind a reverse proxy (e.g., load balancers, Cloudflare). The plugin now correctly detects HTTPS from `X-Forwarded-Proto` and other proxy headers.
* **Dark Mode:** Resolved a styling issue affecting the dark mode theme.

## **v2.6.0** - November 25, 2025

### Added
* **Scan History**: The settings menu now displays cached statistics from the last file analysis, including the total number of files found, total size, and the time elapsed since the scan.

### Improved
* **CLI Info Section**: Cleaned up the CLI information area by consolidating installation instructions into a hover menu and refreshing the command block styling.
* **Security**: Hardened API authentication by implementing `hash_equals` for constant-time token comparison, protecting against timing attacks.
* **Concurrency**: Changed the naming convention for temporary synchronization zip files to use unique IDs (`uniqid`) instead of timestamps (`time()`). This prevents filename collisions when multiple file chunks are requested simultaneously.

## **v2.5.0** - November 21, 2025

**Added**
* **NCDU Command Support:** Added a new CLI command generator for `ncdu` (NCurses Disk Usage), allowing users to easily browse remote file system disk usage via the Disembark CLI.
* **Resumable File Streaming:** Updated the `stream_file` REST API endpoint to support `offset` and `length` parameters. This allows for chunked or resumable file downloads, improving reliability for large file transfers.
* **Updater Metadata:** The custom updater now passes `tested` (WordPress version) and `requires_php` fields to the plugin information screen.

**Changed**
* **Backup Workflow:** The "Start Backup" UI flow has been redesigned. Instead of immediately attempting a browser-based backup (which is prone to timeouts), the UI now generates a "Runner" command. This encourages users to run the migration via the terminal using a one-line `curl | bash` command for higher reliability.
* **CLI Command Generation:** The `migrateCommand` logic now dynamically builds a full runner command that includes all selected file and table exclusions directly in the generated string.
* **CLI Sync Command:** Updated the `sync` command format to include the domain as a second argument (`disembark sync <url> "<domain>"`).
* **Dashboard Redesign:** The main dashboard buttons ("Explore Files", "Start Backup") have been reorganized.
* **Tooltips:** Added tooltips to the CLI command block for better clarity on what `connect`, `backup`, `sync`, and `ncdu` commands do.

**Fixed**
* **Cleanup State:** Ensure UI state (`backup_ready`) is properly reset when running the cleanup routine.

## **v2.4.1** - November 11, 2025

### Changed

* **Analysis Performance:** The file analysis process ("Analyze Site") no longer generates MD5 checksums by default. This significantly improves scanning speed on large sites. Checksums are now only generated when explicitly requested by a client, such as for a `disembark sync` CLI command.

## **v2.4.0** - November 10, 2025

### Added
* **Backup Toggles:** Added "Backup Database" and "Backup Files" switches to the main interface, allowing you to easily skip an entire section of the backup.
* **CLI Command Update:** The generated CLI commands in the UI now dynamically add the `--skip-db` and `--skip-files` flags to match the new UI toggles.
* **UI Validation:** Added a check to prevent starting a backup if both the file and database sections are disabled.

### Changed
* **Smarter UI File Zipping:** The "Start Backup" process now filters the file list based on UI exclusions *in the browser* and sends batches of files to be zipped. This correctly respects all UI exclusions and is significantly more efficient than the previous method of re-zipping manifest chunks.
* **UI Polish:** The file and database exclusion lists are now hidden when their respective "Backup" toggles are switched off.

### Fixed
* **Filescan Performance:** Optimized the file scanning step by lowering the operation limit when checksums are enabled, preventing potential timeouts on slower hosts during the analysis phase.
* **UI Polish:** The database backup progress bar is now correctly hidden during a backup if the "Backup Database" option is disabled.

## **v2.3.0** - October 28, 2025

### Added
* **Database Batch Export:** Implemented a new batching system for database exports. The plugin now intelligently groups small tables (under 200MB and 1 million rows) into combined `.sql.txt` files. This dramatically reduces the number of API requests and zip operations, resulting in a much faster database backup.
* **Session ID & Manifest Regeneration:** The UI now displays a **Backup Session ID** after the initial analysis.
* A new "Regenerate Session" refresh icon allows you to update the file manifest with new exclusions *without* re-scanning the entire file system.
* This session ID can be used with the CLI (`--session-id=...`) to reuse the generated manifest.
* **New `sync` CLI Command:** Added the `disembark sync` command to the CLI instructions display, which works with the new session ID feature.
* **Database Row Count:** The database table list now fetches and displays the row count for each table, helping to identify large tables more easily.

### Changed
* **Smarter Backup Start:** The "Start Backup" button will now only regenerate the file manifest if you have changed your file/folder exclusions. If no exclusions have changed, it reuses the existing manifest, making the backup start almost instant.
* **UI Reset on Cleanup:** Clicking the "Cleanup Temporary Files" button now fully resets the plugin's UI to the initial "Analyze Site" screen. This prevents errors from trying to use a stale session after its files have been deleted.
* **Checksums Enabled by Default:** The file scanning process now generates MD5 checksums by default.
* **Improved CLI Copying:** The CLI commands in the UI are now on separate lines, making it easier to copy a single command at a time.

## **v2.2.1** - October 27, 2025
* **Fix:** Database listings for new `.sql.txt` extension

## **v2.2.0** - October 26, 2025

* **New Feature: Decoupled Filesystem Support (e.g., Flywheel)**
    * Reworked the file scanning, zipping, and streaming logic to fully support hosting environments where the WordPress core (`ABSPATH`) and the web root (`dirname(WP_CONTENT_DIR)`) are in separate locations.
    * The file scanner now identifies and scans both the web root and core root if they are different, using a `seen_files` log to prevent duplicates.
    * The zipping process now correctly locates files in either the web root or core root before adding them to the archive.
    * The File Explorer's streaming endpoint has been updated to find and stream files from a separate core directory, ensuring previews and downloads work correctly on decoupled sites.
* **Improvement: Database Export Compatibility**
    * Database export files are now saved with a `.sql.txt` extension instead of `.sql`.
    * This bypasses security rules on certain managed hosts that block the direct download of `.sql` files.
* **Improvement: Added Checksum Generation Support**
    * The `Backup` class can now optionally generate and include `md5_file` checksums in the file manifest during the scan step.
    * The `/regenerate-manifest` REST endpoint was updated to accept an `include_checksums` parameter to trigger this behavior, which is useful for external CLI validation.
* **Dev: New API Endpoints & UI Functionality**
    * Added a `/zip-sync-files` endpoint to create a zip archive from an arbitrary list of files sent from a client.
    * Added a `/regenerate-token` endpoint and a corresponding "Regenerate Token" button in the UI's Tools menu.
    * Added a "Regenerate Session" button in the UI to allow re-running the file manifest generation with the current exclusions without starting a new session.

## **v2.1.0** - October 23rd 2025

* **New Feature:** Added a "CLI Commands" panel to the main interface. The `disembark backup` command shown in this panel now dynamically updates to include all file (`-x "path"`) and database table (`--exclude-tables=...`) exclusions selected in the UI.
* **Security/Improvement:** Changed the `/stream-file` REST endpoint from `GET` to `POST`. The file path and token are now sent in the request body instead of query parameters, improving security.
* **Improvement:** Updated the File Explorer's "Preview" and "Download" features to work with the new `POST` streaming endpoint. File downloads are now handled via JavaScript to support the new method.
* **UI/UX:** Replaced the "Connection Info" menu with a new "Tools" menu. This new menu provides a helper command for installing the Disembark CLI and retains the "Cleanup Temporary Files" functionality.
* **Dev:** Added a new `delete_backup_file` method and a corresponding `/cleanup-file` REST endpoint to allow for the deletion of individual backup files (e.g., `files-1.zip`, `database.zip`) via the API.

## **v2.0.0** - October 16th 2025

* **Plugin Merger:** "Disembark Connector" and "Disembark Interface" have been combined into this single plugin.
* **New Integrated UI:** A full backup interface is now available directly in the WordPress admin under **Tools > Disembark**.
* **New File Explorer:** You can now browse your site's file structure, preview code and images, and download individual files from within the plugin.
* **New Granular Exclusions:** Added a visual interface to exclude specific files, folders, and database tables from your backup.
* **New Dark Mode:** Added a dark mode theme with a toggle that saves your preference.
* **Enhanced Large Site Support:** The file scanning process was rebuilt to use a multi-step, chunked approach to prevent timeouts on very large sites.
* **Improved Workflow:** The UI has been redesigned for a clearer state-driven process: Analyze, then Configure, then Backup.
* **Better Progress Feedback:** The interface now provides more detailed feedback during the site scan and backup.
* **Easy Access to Connection Info:** Your site's connection token and CLI commands are now easily accessible from a menu in the plugin's UI.
* **Upgrade Path:** Users of the "Disembark Connector" can upgrade directly to this plugin and should deactivate the old Connector plugin.

## **v1.2.1** - October 13th 2025
* Fixed bug with file section introduced with v1.2.0
* Changed directory scan limit from 25 to 75
* Changed file chuck limit from 100mb to 150mb

## **v1.2.0** - October 12th 2025
* Refactored the file scanning and manifest generation into a step-by-step process that uses significantly less memory, improving support for very large websites.
* Added `PclZip` as a fallback for creating zip archives, increasing compatibility with hosting environments where the `ZipArchive` PHP extension is not enabled.
* Introduced a new REST endpoint for securely streaming individual files directly from the server.

## **v1.1.0** - October 8th 2025
* Added the ability to exclude files and folders from file backups.
* Added a new REST endpoint to retrieve a complete file manifest without generating manifest files.
* Refactored and expanded the default list of excluded files and backup directories.

## **v1.0.7** - September 12th 2025
* Fallback for `view details` link on bad response

## **v1.0.6** - July 24th 2025
* WordPress version bump
* Exclude WP Engine protected file `wp-content/mysql.sql`.

## **v1.0.5** - January 18th 2025
* New WP-CLI commands: `wp disembark backup-url`, `wp disembark cli-info` and `wp disembark token [--generate]`
* Tweaked plugin updater priority

## **v1.0.4** - January 17th 2025
* WordPress version bump
* If updater check fails use local plugin manifest

## **v1.0.3** - June 27th 2024
* Exclude Disembark directory and others from backup zip
* Zip large database tables individually

## **v1.0.2** - June 11th 2024
* New advanced options. Ability to backup only files or database. Ability to include certain database tables or certain files or paths.
* New instructions for Disembark CLI
* Analyze site when pasting site URL and token
* Improve backup progress
* Split large database tables into smaller exports
* Fix endpoints `cleanup` and `download` to only respond with plain text
* Cleanup unused backup code

## **v1.0.1** - June 7th 2024
* Improved database exports for [Local](https://localwp.com)
* Cleanup endpoint to purge `uploads/disembark` folder after successful download.
* One click connection string to [Disembark](https://disembark.host)

## **v1.0.0** - June 5th 2024
* Initial release of Disembark. Allows for full site WordPress backups to be made from [Disembark.host](Disembark.host)