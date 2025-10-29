# Changelog

## 2.3.0 - 2025-10-28

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

## v2.2.1 - October 27, 2025
* **Fix:** Database listings for new `.sql.txt` extension

## v2.2.0 - October 26, 2025

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

## v2.1.0 - October 23rd 2025

* **New Feature:** Added a "CLI Commands" panel to the main interface. The `disembark backup` command shown in this panel now dynamically updates to include all file (`-x "path"`) and database table (`--exclude-tables=...`) exclusions selected in the UI.
* **Security/Improvement:** Changed the `/stream-file` REST endpoint from `GET` to `POST`. The file path and token are now sent in the request body instead of query parameters, improving security.
* **Improvement:** Updated the File Explorer's "Preview" and "Download" features to work with the new `POST` streaming endpoint. File downloads are now handled via JavaScript to support the new method.
* **UI/UX:** Replaced the "Connection Info" menu with a new "Tools" menu. This new menu provides a helper command for installing the Disembark CLI and retains the "Cleanup Temporary Files" functionality.
* **Dev:** Added a new `delete_backup_file` method and a corresponding `/cleanup-file` REST endpoint to allow for the deletion of individual backup files (e.g., `files-1.zip`, `database.zip`) via the API.

## v2.0.0 - October 16th 2025

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

## v1.2.1 - October 13th 2025
* Fixed bug with file section introduced with v1.2.0
* Changed directory scan limit from 25 to 75
* Changed file chuck limit from 100mb to 150mb

## v1.2.0 - October 12th 2025
* Refactored the file scanning and manifest generation into a step-by-step process that uses significantly less memory, improving support for very large websites.
* Added `PclZip` as a fallback for creating zip archives, increasing compatibility with hosting environments where the `ZipArchive` PHP extension is not enabled.
* Introduced a new REST endpoint for securely streaming individual files directly from the server.

## v1.1.0 - October 8th 2025
* Added the ability to exclude files and folders from file backups.
* Added a new REST endpoint to retrieve a complete file manifest without generating manifest files.
* Refactored and expanded the default list of excluded files and backup directories.

## v1.0.7 - September 12th 2025
* Fallback for `view details` link on bad response

## v1.0.6 - July 24th 2025
* WordPress version bump
* Exclude WP Engine protected file `wp-content/mysql.sql`.

## v1.0.5 - January 18th 2025
* New WP-CLI commands: `wp disembark backup-url`, `wp disembark cli-info` and `wp disembark token [--generate]`
* Tweaked plugin updater priority

## v1.0.4 - January 17th 2025
* WordPress version bump
* If updater check fails use local plugin manifest

## v1.0.3 - June 27th 2024
* Exclude Disembark directory and others from backup zip
* Zip large database tables individually

## v1.0.2 - June 11th 2024
* New advanced options. Ability to backup only files or database. Ability to include certain database tables or certain files or paths.
* New instructions for Disembark CLI
* Analyze site when pasting site URL and token
* Improve backup progress
* Split large database tables into smaller exports
* Fix endpoints `cleanup` and `download` to only respond with plain text
* Cleanup unused backup code

## v1.0.1 - June 7th 2024
* Improved database exports for [Local](https://localwp.com)
* Cleanup endpoint to purge `uploads/disembark` folder after successful download.
* One click connection string to [Disembark](https://disembark.host)

## v1.0.0 - June 5th 2024
* Initial release of Disembark. Allows for full site WordPress backups to be made from [Disembark.host](Disembark.host)