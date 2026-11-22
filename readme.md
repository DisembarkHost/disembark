# Disembark

Move a WordPress site anywhere: to a new host, a staging copy, a backup zip, or in and out of a multisite network or a WP Freighter install. Disembark copies the files and database, rewrites every old address, and saves a restore point before it changes anything, so every move can be undone.

## What it does

Tools → Disembark has four tabs:

- **Move:** pick where a site comes from (this site, another site, a backup zip) and where it goes (this site, another site, a new or replaced subsite or tenant). Disembark works out whether that's a pull, a push or a restore:
  - **Pull** copies another live site into this one, server to server. Paste its connect line.
  - **Push** sends this site to another one, which saves its own restore point first.
  - **Restore** brings a backup zip in: Disembark's own, or another host's downloadable backup (Kinsta, WP Engine and others with the site's files and a .sql dump). When it isn't clear which dump is the site's database, it asks.

  Before copying anything you choose what to bring (old backups, caches and log tables are left behind by default). A move checks that it can finish before it starts and checks the result when it's done. It keeps going through a dropped connection and can be resumed if it stops.
- **Backups:** download a backup zip in the browser, see every restore point and roll back to any of them, or pick up an earlier scan.
- **Browse:** look through the site's files and database tables, edit a file or a row, and fix old addresses with search and replace.
- **Settings:** the connect line, a ready-made prompt for AI agents, the CLI, quiet mode and storage.

**Bringing a site into a network or WP Freighter install trusts it like a super admin.** Its plugins, themes and uploads join the install's shared folders, so its code runs with access to every site there. Only bring in sites you'd trust with the whole install.

**Disembark on one site of an install can reach the whole install.** On a network only super admins use it. On a WP Freighter install, a tenant's administrators and its connect line back up that tenant by default, but can ask for another site or all of them, as they could already read any file or table through Browse. Give a tenant's admin login or connect line only to someone you'd trust with the whole install.

**Quiet mode** keeps a staging or dev copy of a live store from reaching real customers. Emails are held, scheduled jobs paused, checkout closed, and payment and marketing APIs blocked.

Everything ships with the plugin: no outside scripts, fonts or accounts. It's free and MIT licensed.

## From the terminal

Every move also runs over SSH with WP-CLI: `wp disembark pull`, `restore` (a backup zip on the server), `restore-points`, `rollback`, `search-replace`, `quiet-mode`, `verify` and more. Every command runs unattended with `--yes` and `--format=json`, for scripts and AI agents.

The [Disembark CLI](https://github.com/DisembarkHost/disembark-cli) backs a site up to your own machine (`disembark backup`), keeps a local mirror (`disembark sync`) and restores (`disembark restore`, with `--rollback`).

## Installation

1. Download `disembark.zip` from the [latest release](https://github.com/DisembarkHost/disembark/releases/latest).
2. In WordPress, go to **Plugins → Add New → Upload Plugin**, upload the zip and activate it.
3. Open **Tools → Disembark**.

Updates arrive through WordPress's own Updates screen.
