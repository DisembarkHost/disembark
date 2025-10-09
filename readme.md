# Disembark Connector

Connector plugin for [Disembark](https://disembark.host).

## Usage

- Download and install [latest version of Disembark Connector](https://github.com/DisembarkHost/disembark-connector/releases) on any WordPress site.
- From `wp-admin/plugins.php` select "View details" next to Disembark Connector and select "Launch Disembark with your token"
- After backup completed, copy command into terminal or over SSH to generate the backup zip.

### Changelog

**v1.1.0** - October 8th 2025
- Added the ability to exclude files and folders from file backups.
- Added a new REST endpoint to retrieve a complete file manifest without generating manifest files.
- Refactored and expanded the default list of excluded files and backup directories.

**v1.0.7** - September 12th 2025
- Fallback for `view details` link on bad response

**v1.0.6** - July 24th 2025
- WordPress version bump
- Exclude WP Engine protected file `wp-content/mysql.sql`.

**v1.0.5** - January 18th 2025
- New WP-CLI commands: `wp disembark backup-url`, `wp disembark cli-info` and `wp disembark token [--generate]`
- Tweaked plugin updater priority

**v1.0.4** - January 17th 2025
- WordPress version bump
- If updater check fails use local plugin manifest

**v1.0.3** - June 27th 2024
- Exclude Disembark directory and others from backup zip
- Zip large database tables individually

**v1.0.2** - June 11th 2024
- New advanced options. Ability to backup only files or database. Ability to include certain database tables or certain files or paths.
- New instructions for Disembark CLI
- Analyze site when pasting site URL and token
- Improve backup progress
- Split large database tables into smaller exports
- Fix endpoints `cleanup` and `download` to only respond with plain text
- Cleanup unused backup code

**v1.0.1** - June 7th 2024
- Improved database exports for [Local](https://localwp.com)
- Cleanup endpoint to purge `uploads/disembark` folder after successful download.
- One click connection string to [Disembark](https://disembark.host)

**v1.0.0** - June 5th 2024
- Initial release of Disembark. Allows for full site WordPress backups to be made from [Disembark.host](Disembark.host)
