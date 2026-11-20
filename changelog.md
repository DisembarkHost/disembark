# Changelog

## Unreleased

### Changed
* **A new dashboard, without Vue or Vuetify:** Tools → Disembark is rebuilt as plain JavaScript with its own design and a new mark (a freighter in a coral disc). Nothing loads from a CDN any more (Vue, Vuetify, axios, Prism, Material icons and Google Fonts are gone); every script, style and font ships with the plugin. It has four tabs, with light and dark themes:
  * **Move:** every move is from here to there. Pick where the site comes from (this site, another site, a backup zip) and where it goes (this site, another site, a new or replaced subsite or tenant), and Move works out whether that's a pull, a push or a restore. It asks for the one thing it needs (a connect line or a zip) and shows the one warning that matters.
  * **Backups:** download a backup zip, the CLI command, restore points and earlier scans.
  * **Browse:** this site's files and database tables.
  * **Settings:** the connect line, the AI agent prompt, the CLI, quiet mode, storage and appearance.

  A move runs as a voyage: a harbor scene where the boat sails as each step finishes, any question it needs answered (which site, where it goes, what to bring) right under it, and an arrival with what to do next beside the checks. The REST API is unchanged, so the CLI works as before, and older links (`#pull`, `#restore`, `#files`) open the tab that holds them now.
* **Notices from other plugins stay out of the way:** WordPress moves every admin notice (update nags, "rate us" and usage-tracking prompts, license warnings) under the first heading on the page, which was the middle of the dashboard. Tools → Disembark now gathers them into a **notices** button in the header that opens a tray; their buttons and dismiss links still work there, and the bell turns red when one of them is an error.

### Added
* **Restore points:** Backups lists every saved restore point (what it was for, when, how big, whether it's been rolled back to) with **Roll back** and **Delete**. New endpoints: `GET /restore-points` and `POST /restore-points/delete`; snapshots take an optional `label`.
* **Back up in the browser:** Backups → **Download a backup zip** builds a zip of your choices on the server in batches (with progress and Cancel) and downloads it, no CLI needed. It uses the CLI backup layout (`public/`, `database-*.sql`, `disembark-source.json`), so it restores from the dashboard or with `disembark restore`. Afterwards it offers to delete the zip from the server. New endpoint: `POST /backup/archive` (`files`, `database`, `finish` steps; needs ZipArchive).
* **Push to another site:** Move, from **This site** to **Another site**, sends this site to another site running Disembark (staging to live, or onto a new host). Paste the destination's connect line; the destination pulls everything from this site server-to-server, saves a rollback point first, and the push can be undone from here. The browser drives the destination's own import API (WordPress REST answers CORS), so no new endpoints were needed. When a host blocks cross-site requests, the error explains how to pull from the destination instead.
* **Pull from another site:** Move, from **Another site** to **This site**, the mirror of Push for when you're logged in to the destination. Paste the source's connect line and this site copies it in server-to-server, with the restore point, the voyage and **Undo pull** you get from a restore.
* **What to bring:** a pull or push now pauses after scanning the source and shows what it holds before anything is copied: every folder with its size, every table with its size and rows, and the things a move can usually leave behind, ticked: archives and dumps in the site root, page and asset caches, PHP error logs, WooCommerce/Action Scheduler, email, redirect and 404 logs, Wordfence's firewall files and traffic data, and other security plugin logs (activity logs, visitor statistics, shopping sessions and image-optimizer originals are offered unticked). Untick any folder to leave it behind, or any table to bring it empty (its structure comes across, its rows don't, so plugins that expect it keep working). Choices can be saved as **templates** ("Woo staging copy") and picked on the next move; they're kept in `uploads/disembark`, so a pull that replaces the database keeps them. `wp disembark pull` applies the suggested skips too, with `--skip=<paths>`, `--skip-rows=<tables>`, `--template=<name>` and `--bring-everything`, and reports them under `left_out`. Extend the list with the `disembark_suggested_skips` filter. New endpoints: `POST /import/pull/contents`, `POST /import/pull/select`, `GET`/`POST /templates`; `export-database-batch` takes `schema_only`.
* **Cancel a move:** Pull, Push and Restore have a **Cancel** button while they run. Before the rollback point nothing on the site has changed, so Cancel deletes what was copied so far; after it, Cancel rolls the site back first, then deletes the copied files and the rollback point. A long step on the server (placing files, importing the database) stops early instead of running to the end. New endpoint: `POST /import/cancel` (`action=stop|cleanup`).
* **Resume a move that stopped:** a pull or push that stops partway (a closed tab, a dropped SSH session, an error) keeps its progress. The Move tab lists it with **Resume** and **Discard** (which rolls back first if the move got past its rollback point); `wp disembark unfinished` lists them in the terminal, `wp disembark pull --resume=<id|latest>` resumes one started there, and `wp disembark discard <id>` throws one away. Resuming replays the steps that already finished, in seconds, and carries on from the first one that didn't: a 248,000-file pull that stopped at file 112,000 picks up at 112,000. Moves left unfinished for a week with no rollback point are cleaned up. New endpoints: `GET /import/unfinished`, `POST /import/resume`.
* **Catch-up pulls:** after a pull, this site remembers the source's files (size and modification time). The next pull from the same source offers **Only copy what changed since the last pull** (`--catch-up` in the CLI): files new or changed on the source come across, files the last pull brought that are gone from the source are removed here (backed up in the rollback point first), and the database is copied again in full. Use it for the final pull before a switch-over: freeze the source, catch up in a fraction of the time, switch. A rollback also puts back what this site remembered, so the next catch-up sees the rolled-back files as changed. Sources on an older Disembark don't report file times; then files are compared by size, and the run says so. New endpoint: `GET /import/last-pulls`; the source's scan now records each file's modification time.
* **Firewall loaders no longer take down the copy:** Wordfence, NinjaFirewall and similar firewalls load through `auto_prepend_file` in `.user.ini`, `php.ini` or `.htaccess`, with an absolute path on the old server. After a move that line pointed at a file that didn't exist and every request failed. A move now comments out a prepend whose file isn't on the new server, and lists it (`loaders_disabled` in the CLI's JSON).
* **WP-CLI commands for every move:** run a whole migration over SSH, no browser. `wp disembark pull <url> <token>` (or the whole connect line) pulls another Disembark site into this one with the same steps as Tools → Disembark → Pull; `wp disembark restore-points`, `wp disembark rollback <id|latest>`, `wp disembark delete-restore-point <id|all>` and `wp disembark search-replace <from> <to> [--dry-run]` cover the rest, and `wp disembark connect-line` prints this site's connect line. Every command runs unattended: `--yes` skips the confirmation, `--format=json` prints one JSON summary on stdout (progress goes to stderr), and without `--yes` a command refuses rather than waiting on a prompt when there's no terminal. Built for AI agents as much as people.
* **Hand it to an AI agent:** Settings has a ready-to-paste prompt for Claude Code, Cursor or Codex with this site's connect line and a link to the agent instructions at disembark.host/disembark.md.
* **Quiet mode for copies:** a staging or dev copy of a live store can't reach real customers. A pull, restore or `wp disembark pull` from another address turns it on (Pull and Restore have a **Keep this copy quiet** switch, on by default; Push has one for the destination, off by default; `wp disembark pull` takes `--skip-quiet-mode`). It goes on right after the rollback point is saved, before the files and database arrive. While it's on: every email is held, not sent, and listed in Settings, where you can open it (Disembark replaces `wp_mail()` before any plugin loads, so SMTP plugins can't route around it). WP-Cron runs nothing, even when a server cron requests `wp-cron.php`, and Action Scheduler's async runner is off. WooCommerce offers no payment methods, refuses classic and block (Store API) checkouts and pauses webhooks. Calls to live payment APIs (Stripe, PayPal, WooPayments, Braintree, Square, Authorize.net), marketing and catalog syncs (Mailchimp, Meta, Google Merchant Center and Ads, Klaviyo, HubSpot), shipping labels (UPS, FedEx, ShipStation, EasyPost, ShipEngine) and email APIs fail fast, so refunding a copied order can't refund real money (extend the list with the `disembark_quiet_mode_blocked_hosts` filter). Search engines are discouraged. It's an mu-plugin (`mu-plugins/disembark-quiet-mode.php`) that keeps working if Disembark is deactivated, with a banner and admin-bar badge. It's bound to the address it was turned on for: once the site moves to another address (a copy going live on the real domain) it stands down by itself. Lift it sooner in Settings or with `wp disembark quiet-mode off`; a rollback lifts the quiet mode its restore turned on, and it never travels in a backup. New: `wp disembark quiet-mode [status|on|off|emails]` and `GET`/`POST /quiet-mode`.
* **Search & replace:** Browse → Database → **Search and replace** rewrites text across the database with the restore engine (serialized PHP and JSON stay valid). Preview shows the cells, rows and tables that would change with before/after samples; replacing saves a rollback point first and offers Undo. New endpoint: `POST /search-replace` (with `dry_run`).

* **Checks before a move:** once a pull knows what's coming (after "What to bring", before any file is copied) or a backup zip is unpacked, the destination checks that the move can finish, and stops before anything changes when it can't:
  * free disk space for the files and database still to download, the rollback point and the second copy of the incoming tables;
  * a database user that can create, rename and drop tables;
  * table names that would pass MySQL's 64 characters with this site's prefix, and names that differ only in case on servers that store them in lower case;
  * folders PHP can't write to.

  It also warns about older plugin versions replacing newer ones (pushing an old staging site over a live one), PHP or WordPress going backwards, low PHP memory, Pantheon and WordPress.com, backups over 180 days old and a firewall loaded before WordPress. The dashboard asks before going on past a blocking check; `wp disembark pull` stops (resume with `--skip-checks` to go ahead anyway) and lists them under `checks`. Add your own with the `disembark_move_checks` filter. New endpoint: `POST /import/checks`; the source's preflight now reports its versions, plugins and theme.
* **Checks after a move:** a move now ends by checking its result, in a new **Checking the result** step:
  * every imported table holds as many rows as the source exported (the source writes each table's count into the export, and the import counts right after swapping the tables in);
  * the home page, the login page, the newest post of each public post type and an archive of each taxonomy load without a PHP error, a 404 or a redirect off the site, with the plugin or theme behind a PHP error named where the page or the error log says;
  * the old address is gone from the database (a table without a primary key, which the rewrite can't update, shows up here) and from those pages.

  The dashboard shows the result under the move; the CLI prints it and adds `verify` to the JSON (`--skip-verify` to leave it out). `wp disembark verify [<id>]` runs the checks again on a finished move. New endpoint: `POST /import/verify`.
* **One site out of a multisite network or a WP Freighter install:** pull, push or back up a single subsite or Freighter site (the main site or a tenant) as a standalone site. Pulling a subsite's or a mapped tenant's own address brings that site; the install's main address asks which site to bring (the dashboard shows a **Which site?** picker, `wp disembark pull` takes `--site=<id|address|main|all>`), and **Backups** has a site selector. What comes across:
  * the site's own tables only. A network subsite's users are filtered from the network tables: its members, the authors of its posts, and the network's super admins, who arrive as administrators. Roles and settings that belong to other sites are dropped;
  * network-wide plugin tables come across empty, since they hold every site's rows (`--shared-tables` brings the rows). The network's own tables and domain-mapping tables (Mercator, WordPress MU Domain Mapping, Ultimate Multisite) stay behind;
  * the site's uploads (`uploads/sites/5`, `blogs.dir/5/files`, Freighter's `content/<id>`) move to `wp-content/uploads` or `wp-content`. Their addresses are rewritten wherever they were served from, including mapped domains, and their old server paths too;
  * plugins and themes from a folder the sites share come across only when this site uses them. Network-activated plugins become active plugins, and WP Freighter is switched off on the copy.

  WP Multi Network installs use the subsite's own network. New endpoint: `POST /import/pull/site`; source endpoints take `site`; `export-database-batch` takes `shared_rows`.
* **Bring a site into a network or a WP Freighter install:** on the main site of a multisite network or a WP Freighter install, a pull, push or restore now asks **Where should it go?**: a new subsite or tenant, an existing one to replace, or the main site as before (`wp disembark pull … --into=new|<id>`, with `--slug` for a new subsite's path and `--domain` for a new tenant's domain). Any site can arrive this way: a standalone site, a backup zip, or one site brought out of another network or install, so a Freighter tenant can become a subsite and a subsite a tenant. What it does:
  * the new site is created right after the rollback point is saved;
  * the import touches only that site's tables (a table without the source's prefix is left out) and its folders (`uploads/sites/<id>`, `content/<id>`);
  * plugins and themes are added to the shared folders when missing, never replacing a version other sites run. Core files, mu-plugins and drop-ins stay as they are;
  * on a network, the incoming users join the network's users: matched by email, otherwise added with a free login. The site's posts, comments and WooCommerce orders are renumbered to match, and network-activated plugins stay network-wide;
  * the address and folder rewrite and the checks after the move cover that site alone. Quiet mode stays off, since it would cover every site;
  * the rollback point holds only that site's tables plus the network's users or Freighter's registry, so rolling back removes a new site completely (folders included) and puts a replaced one back.

  New endpoint: `POST /import/target`.
* **Notifications:** **Notify me** on a running move (or `--notify=<email or URL>` in the CLI) sends an email, a Slack or Discord message, or JSON to any https:// webhook when the move finishes, stops or is cancelled: with how long it took, the checks' result, and how to undo or resume. Emails go out even from a copy in quiet mode (through PHP's mail(), around the held wp_mail()). New endpoint: `POST /import/notify`.

### Improved
* **Moving a site that uses plain permalinks:** once its database was imported, `/wp-json/` stopped reaching the REST API, so a dashboard restore, pull or push quietly skipped the prefix remap and the address rewrite. The dashboard now switches to `?rest_route=` when the REST API answers with a page.
* **A failed database import no longer leaves a half-written site:** the import now builds each table as a copy beside the live one (`_dsk_<table>`) and only swaps the copies in, table by table in milliseconds, once every statement has succeeded. An import that errors, runs out of disk or is cancelled drops the copies, and the site's database stays exactly as it was (the error says so). Tables with foreign keys (WooCommerce's download log, for one) get the same treatment: the copy's keys take temporary names, and each gets its own name back after the swap. Keys elsewhere that point at a swapped table keep working. The import needs room for a second copy of the incoming tables while it runs, and a full disk is now reported as such.
* **Moves between MySQL 8 and MariaDB:** a table, column or engine this database server doesn't have is converted to its nearest match on import: `utf8mb4_0900_ai_ci` (MySQL 8's default) becomes `utf8mb4_unicode_520_ci`, case-sensitive collations become `_bin`, `utf8mb3` becomes `utf8` on servers that predate the name, and engines such as Aria become InnoDB. Views, triggers and routines lose the source server's `DEFINER` account (and run as `SQL SECURITY INVOKER`), which otherwise refuses to import or breaks the view. The run lists each conversion (`converted` and `definers_removed` in the CLI's JSON).
* **Views move as views:** a database view was exported like a table, rows included, and importing those rows wrote them into the table behind the view. A view is now exported as its definition only and created after the tables are in place; rows an older source sends for a view are skipped. Rollback points and their check handle views too, and a rollback drops views the move added.
* **Every spelling of the old address is rewritten:** a move now replaces `http://` and `https://`, with and without `www.`, scheme-relative `//old-site.com`, and an international domain's Unicode and `xn--` forms, so old links in content point at the new site instead of the old one. Also new: the `&#x2F;` and `&#47;` slash entities some editors write, and addresses inside base64-encoded builder content: WPBakery's `[vc_raw_html]` and `[vc_raw_js]`, Avada's `[fusion_code]`, and base64 values such as BeTheme's page items and Brizy's editor data (decoded, rewritten, encoded again). Database → Search & replace keeps rewriting exactly what you type.
* **The old server's paths are rewritten:** cache, security, backup and log plugins store absolute paths (`/home/old/public_html/wp-content/…`). A move to a new host now rewrites the source's `ABSPATH` and content folder to this site's, even when the address stays the same. Sources report their paths from this version on (`paths` in the CLI's JSON).
* **Tidying up after a move:** finishing a move now
  * empties page and asset caches left on disk (WP Rocket, WP Super Cache, W3 Total Cache, WP Fastest Cache, Breeze, Cache Enabler, Comet Cache, FlyingPress, Swift Performance, Autoptimize, Hummingbird, LiteSpeed, Divi and Beaver Builder), which the destination's own `advanced-cache.php` would otherwise keep serving as the old site, and asks the running cache plugin or host (Kinsta, WP Engine, SiteGround, LiteSpeed, Nginx Helper) to purge;
  * resets PHP's opcode cache after the files are placed, so hosts that don't recheck file times run the new code;
  * finds a login page moved by WPS Hide Login, Rename wp-login.php, Perfmatters, All In One WP Security or Solid Security, says where it is, and points **Log in again** there (`login_url` in the CLI's JSON);
  * on a copy at a new address with quiet mode on, turns off login captchas whose keys only work on the old domain (Simple Cloudflare Turnstile, reCAPTCHA and hCaptcha plugins, Wordfence's login reCAPTCHA), so the copy doesn't refuse every login;
  * on a copy served over plain `http://`, turns off force-SSL plugins (Really Simple Security, WP Force SSL and others), their `.htaccess` redirect blocks and WooCommerce's forced-SSL checkout.

  Each step is listed in the run (`caches_cleared` and `plugins_turned_off` in the CLI's JSON). A rollback puts back the plugins and settings it changed.
* **Rolling back removes the folders a move added:** a rollback deleted the files a pull brought in but left their folders behind, so a rolled-back plugin still showed as an empty folder.
* **A move keeps the destination's host setup:** a restore or push no longer deploys the source's host files over the destination. mu-plugins the destination already has are kept as they are, mu-plugins that belong to a hosting platform (WP Engine, Kinsta, GoDaddy, Pantheon, WordPress.com, Bluehost and others) are left out, and so are the `object-cache.php`, `advanced-cache.php` and `db.php` drop-ins, which point at the old host's services. The site's own mu-plugins still come along. The run lists what it left out. `deploy-files` takes `keep_host_files: false` (and `wp disembark pull` takes `--bring-host-files`) to bring everything.
* **Multisite sources are flagged:** a restore keeps the destination's own `wp-config.php`, so when the backup comes from a multisite network the run now lists the `define()` lines to add there.
* **Backups:** a step-by-step scan with live counts, a summary of what's included, and commands that update as you leave files or tables out.
* **A restore no longer swaps out the Disembark doing the restore:** the backup carries the source's copy of Disembark, often a different version, and deploying it over the destination's meant the database import, URL rewrite and finalize steps ran the source's code. The running plugin's folder is now skipped, so the destination keeps its own Disembark.
* **Percent-encoded URLs are rewritten on restore ([#9](https://github.com/DisembarkHost/disembark/issues/9)):** search & replace now also matches the URL-encoded spelling of the old address (`https%3A%2F%2F…`, lowercase hex, and double-encoded), each rewritten in its own encoding. A redirect like `https://example.com/go?next=https%3A%2F%2Fold-site.com…` keeps its own address while the embedded target moves to the new site, and serialized lengths stay correct.
* **Copied stylesheets no longer point at the old site ([#10](https://github.com/DisembarkHost/disembark/issues/10)):** after the database rewrite, a restore also rewrites the old address in text files it brought in under the uploads folder (`.css`, `.js`, `.json`, `.html`, `.svg`, `.xml`, `.txt` up to 5 MB) — where page builders keep generated CSS — and clears Elementor's generated CSS so it rebuilds. Theme and plugin files that still mention the old address are listed after the restore but never edited. CSS hex escapes (`http\3a \2f \2f …`) are not rewritten. Rolling back undoes the file changes too.
* **Zips download on hosts like Kinsta:** ZipArchive writes archives with owner-only permissions, so where the web server runs as a different user than PHP (Kinsta, for one) a zip's link answered 404 — folder downloads and browser backups failed there. Finished zips now get normal file permissions, and the dashboard checks a download link first and falls back to streaming the file through `/stream-file` (which now also accepts a form post) when the host won't serve it.
* **A consistent database copy from a busy site:** pulls, CLI backups and rollback snapshots now read every table inside one consistent snapshot (`START TRANSACTION WITH CONSISTENT SNAPSHOT`, InnoDB), so a store taking orders during the export can't leave an order in one table and its items missing from another. Reads only; nothing is locked.
* **Rolling back a pull no longer ends in a fatal error:** plugins the pull brought in were deleted by the rollback but still loaded in that request, and their shutdown hooks autoloaded classes from the deleted files (on hosts that display errors, that landed in the rollback's response).
* **Resuming after a failed database import works:** a step that answered with an error list (such as a database import that reported errors) was remembered as finished, so resuming replayed the same failure instead of running the import again.
* **Moves survive a lost connection:** switch from wifi to a hotspot, close the laptop lid for a minute, or hit a gateway timeout mid-step, and a pull, push or restore carries on by itself. Every step is sent with a request id; the server runs each step once, keeps going when the browser goes away, and answers a resent request with that step's result (or waits for it while it's still running), so a retry never runs the database export or import twice. The dashboard keeps resending through dropped connections and 502/503/504 answers for up to 15 minutes and says in the log when it lost and regained the connection. Destinations on an older Disembark get the previous three quick retries.
* **Push reaches destinations on plain permalinks:** Push probed the destination's `/wp-json/` first, and a site on plain permalinks answers that with its front page, which the browser reports as a cross-site failure, so Push gave up before trying `?rest_route=`. It now tries both.
* **Moves:** a confirmation before anything changes, the voyage with the full log under "Show details", the restore point shown the moment it exists, and "Start another move" after a cancel or a rollback.
* **Browse → Files:** find any file by path, see the largest items in a folder, and edit with Tab and ⌘S. Files open as raw text, so a JSON file is no longer reformatted when edited. Edit and rename are hidden when file edits are disabled on the site.
* **Browse → Database:** editing a row saves only the fields you changed (an untouched `NULL` stays `NULL`), and adding a row leaves empty fields out so auto-increment keys and column defaults apply. Deleting a row asks first.
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