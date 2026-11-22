<?php

namespace Disembark;

/**
 * WP-CLI commands. Everything the dashboard does to move a site has a command
 * here too, so a person or an AI agent with SSH can run a whole move without a
 * browser. Every command runs unattended: --yes skips the confirmation, and
 * --format=json prints one JSON object on stdout (progress goes to stderr).
 * Without --yes, a command that would change the site refuses to wait on a
 * prompt when stdin isn't a terminal.
 */
class Command {

    /** @var bool */
    private $json = false;

    /**
     * Prints this site's connection token.
     *
     * ## OPTIONS
     *
     * [--generate]
     * : Generate a new token first. Anything holding the old one (the CLI,
     * another site's pull or push) stops working.
     */
    public function token( $args, $assoc_args ) {
        if ( isset( $assoc_args['generate'] ) ) {
            Token::regenerate();
            \WP_CLI::success( "New token generated and saved." );
        }
        $token = Token::get();
        \WP_CLI::log( $token );
    }

    /**
     * Prints this site's connect line: what another site (or the Disembark
     * CLI) needs to pull from it.
     *
     * ## EXAMPLES
     *
     *     $ wp disembark connect-line
     *     disembark connect https://example.com 9fXc...
     */
    public function cli_info( $args, $assoc_args ) {
        $token    = Token::get();
        $home_url = home_url();
        \WP_CLI::log( "disembark connect $home_url $token" );
    }

    /**
     * Pulls another site running Disembark into this one, server to server.
     *
     * Runs the same steps as Tools → Disembark → Pull:
     * saves a rollback point, copies files and database across, remaps the
     * table prefix and rewrites the old address. This site's wp-config.php,
     * its own Disembark, and its host setup (mu-plugins it already has, mu-plugins
     * that belong to a hosting platform, and the object-cache.php,
     * advanced-cache.php and db.php drop-ins) are kept.
     *
     * Afterwards the user accounts are the source's: log in with the source
     * site's credentials.
     *
     * ## OPTIONS
     *
     * [<source>...]
     * : The source's URL and token, or its whole connect line
     * ("disembark connect <url> <token>"), from its Tools → Disembark → Settings
     * or `wp disembark connect-line`.
     *
     * [--max-retries=<n>]
     * : How many times a step is tried again after the source or this host
     * fails (429, 502, 503, 504, a dropped connection). Default 20; 0 for no
     * limit.
     *
     * [--max-retry-minutes=<n>]
     * : How long a step keeps trying after such a failure. Default 15; 0 for
     * no limit. With both 0 it keeps trying, as the dashboard does.
     *
     * [--yes]
     * : Don't ask for confirmation.
     *
     * [--bring-host-files]
     * : Also deploy the source's host files (mu-plugins and cache drop-ins).
     *
     * [--catch-up]
     * : Copy only files that are new or changed since this site's last pull
     * from the same source (files removed there are removed here), and only
     * the tables that changed on either side since then (see
     * --full-database). Falls back to everything when there was no earlier
     * pull.
     *
     * [--resume=<id>]
     * : Resume a pull that stopped (see `wp disembark unfinished`), or
     * `latest`. Finished steps are replayed; it carries on from where it
     * stopped with the options it started with.
     *
     * [--full-database]
     * : With --catch-up: copy every table. By default a catch-up leaves
     * alone the tables that haven't changed on either side since the last
     * pull (compared by fingerprint), and copies the rest.
     *
     * [--site=<site>]
     * : Which site of a multisite network or a WP Freighter install to
     * bring: a blog ID, a Freighter tenant ID or "main", or the site's
     * address. Required when the source address is the install's main
     * address (without it, the pull stops and lists the sites). The site
     * arrives as a standalone site: its own tables, its members (and the
     * network's super admins, as administrators), its uploads moved to
     * wp-content/uploads, and only the plugins and themes it uses. A source
     * address that reaches one site (a subsite, a mapped tenant domain)
     * brings that site without this. Moving a whole install as one isn't
     * supported yet.
     *
     * [--into=<where>]
     * : When this site is the main site of a multisite network or a WP
     * Freighter install: bring the source in as a new subsite or tenant
     * ("new"), or replace the subsite or tenant with this ID, instead of
     * replacing this site. Its tables, uploads and settings stay its own;
     * plugins and themes are added to the shared folders when missing (never
     * replacing ones other sites run); on a network the incoming users join
     * the network's (matched by email). Rolling back removes a new site
     * completely. Quiet mode stays off, since it would cover every site.
     *
     * [--slug=<slug>]
     * : With --into=new on a network: the new subsite's path or subdomain
     * (defaults to the source's).
     *
     * [--domain=<domain>]
     * : With --into=new on a WP Freighter install with domain mapping: the
     * new tenant's domain (defaults to the source's).
     *
     * [--shared-tables]
     * : With --site on a network: bring the rows of network-wide tables
     * (plugin tables with no copy per site) too. By default they come across
     * empty, since they hold every site's data.
     *
     * [--skip=<paths>]
     * : Folders or files to leave behind, comma-separated, relative to the web
     * root (for example wp-content/uploads/2014,wp-content/big-export.csv).
     *
     * [--skip-rows=<tables>]
     * : Tables to bring empty, comma-separated (with or without the table
     * prefix). Their structure comes across; their rows stay behind.
     *
     * [--template=<name>]
     * : Start from a saved "What to bring" template (made in Tools → Disembark
     * → Pull) instead of the suggested skips.
     *
     * [--bring-everything]
     * : Leave nothing behind. By default the suggested skips that match the
     * source are left out: old backup archives (UpdraftPlus and others),
     * caches, error logs, email/redirect/WooCommerce logs, Wordfence's
     * firewall files and traffic data. Logs and data tables come across
     * empty rather than missing.
     *
     * [--[no-]super-admins]
     * : With --site on a network: the network's super admins who have no
     * role on that site come across as its administrators (on by default;
     * --no-super-admins leaves them out). Super admins with a role there come
     * either way. Never made super admins anywhere.
     *
     * [--bring-root[=<names>]]
     * : With --site (one site of a network or WP Freighter install, not its
     * main site): also bring the install's own files in its web root (scripts,
     * other apps, notes, .htaccess: anything at the top level that isn't
     * WordPress core or the site's content). They stay behind by default.
     * Without a value, all of them; or top-level names, comma-separated
     * (for example --bring-root=.htaccess,invites). The install's
     * wp-config.php never comes.
     *
     * [--skip-checks]
     * : Go ahead even when the checks before the move find a problem that
     * would stop it (not enough disk space, a database user that can't create
     * tables, table names that would be too long).
     *
     * [--notify=<to>]
     * : Email address(es) and/or https:// webhook URLs (Slack, Discord or any
     * URL that takes JSON), comma-separated, told when the pull finishes or
     * stops. Sent even from a copy in quiet mode.
     *
     * [--skip-verify]
     * : Don't run the checks after the move (row counts, loading a few pages,
     * looking for the old address).
     *
     * [--skip-quiet-mode]
     * : Don't turn on quiet mode. By default a pull from another address turns
     * it on, so the copy can't email customers, run the source's scheduled
     * jobs, take orders or call payment and marketing APIs. It stands down
     * by itself if this site later moves to another address (such as going
     * live on the real domain); `wp disembark quiet-mode off` lifts it sooner.
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: text
     * options:
     *   - text
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     $ wp disembark pull https://old-site.com 9fXc... --yes
     *     $ wp disembark pull disembark connect https://old-site.com 9fXc... --yes --format=json
     *     $ wp disembark pull https://staging.example.com 9fXc... --yes --skip-quiet-mode
     *     $ wp disembark pull https://network.example.com 9fXc... --site=shop.network.example.com --yes
     *     $ wp disembark pull https://freighter.example.com 9fXc... --site=8 --yes
     */
    public function pull( $args, $assoc_args ) {
        $this->json = ( $assoc_args['format'] ?? 'text' ) === 'json';
        $this->retry_limits( $assoc_args );
        @set_time_limit( 0 );

        $resuming = ! empty( $assoc_args['resume'] );
        if ( $resuming ) {
            $move = $this->find_unfinished( (string) $assoc_args['resume'] );
            if ( ( $move['move']['client'] ?? '' ) !== 'cli' ) {
                $this->fail( 'That move was started in the dashboard. Resume it from Tools → Disembark → Pull, or discard it with: wp disembark discard ' . $move['id'] );
            }
            $import_id  = $move['id'];
            // The same options as the first run, so its steps line up.
            // Flags about how this run reports or checks may change on a resume.
            $now        = array_intersect_key( $assoc_args, array_flip( [ 'skip-checks', 'skip-verify', 'notify' ] ) );
            $assoc_args = array_merge( (array) ( $move['move']['args'] ?? [] ), $now, [ 'yes' => true, 'format' => $assoc_args['format'] ?? 'text' ] );
            $source     = [ 'url' => $move['source'], 'token' => '' ];
            @unlink( Storage::base_dir() . "/_import_{$import_id}/cancel" );
        } else {
            $source = self::parse_connect( $args );
            if ( ! $source ) {
                $this->fail( 'Pass the source URL and token, or its connect line: wp disembark pull https://old-site.com <token>' );
            }
            $import_id = substr( bin2hex( random_bytes( 12 ) ), 0, 24 );
        }
        $import  = new Import( $import_id );
        $pull    = new Pull( $import_id );
        if ( $resuming && ( $kept = Pace::recall( $import_id ) ) ) {
            $this->pace = array_merge( $this->pace, array_intersect_key( $kept, $this->pace ) );
        }
        $summary = [ 'ok' => false, 'source' => $source['url'], 'import_id' => $import_id ];
        $started = time();
        if ( ! empty( $assoc_args['notify'] ) ) {
            $set = Notify::set( $import_id, (string) $assoc_args['notify'] );
            if ( is_wp_error( $set ) ) {
                $this->fail( $set->get_error_message() );
            }
        }
        $this->notify = [ 'import' => $import_id, 'kind' => 'pull', 'source' => $source['url'], 'destination' => home_url(), 'started' => $started ];

        $this->say( '[1/9] Checking this site' );
        // In a subsite's or tenant's context, the pull would replace the
        // install's shared files: refused before anything is copied.
        if ( $refused = Import::sqlite_refusal() ?: Import::here_refusal() ) {
            $this->fail( $refused->get_error_message(), 'preflight' );
        }
        if ( ! $resuming ) {
            $pre = $import->preflight();
            $this->confirm( $assoc_args, ! empty( $assoc_args['into'] )
                ? "Bring {$source['url']} into " . ( $assoc_args['into'] === 'new' ? 'a new subsite or tenant' : 'subsite or tenant ' . $assoc_args['into'] . ' (replacing it)' ) . ' here? A rollback point is saved first.'
                : 'Replace ' . untrailingslashit( (string) $pre['home_url'] ) . " with {$source['url']}? A rollback point is saved first, and " . ( is_multisite() ? 'the network keeps its users (the incoming site\'s users join them).' : "afterwards you log in with the source site's credentials." ) );
        } else {
            $this->say( "      Resuming {$import_id} (a pull from {$source['url']}): finished steps are replayed." );
        }
        // From here every step is recorded. A pull that stops can be resumed
        // once it has connected (that's when it's listed as unfinished).
        $this->steps_import = $import_id;
        $this->resume_hint  = $resuming ? $import_id : '';
        $pre         = $this->check( $this->step( function () use ( $import ) { return $import->preflight(); } ), 'preflight' );
        $dest_prefix = (string) ( $pre['db_prefix'] ?? '' );
        $dest_home   = untrailingslashit( (string) ( $pre['home_url'] ?? home_url() ) );
        $summary['destination'] = $dest_home;
        $into = (string) ( $assoc_args['into'] ?? '' );
        if ( $into !== '' ) {
            if ( ! empty( $assoc_args['catch-up'] ) ) {
                $this->fail( '--catch-up works on this site\'s own files, so it can\'t be combined with --into.' );
            }
            $t = $this->check( $this->step( function () use ( $import, $into, $assoc_args ) {
                return $import->set_target( $into, [ 'slug' => (string) ( $assoc_args['slug'] ?? '' ), 'domain' => (string) ( $assoc_args['domain'] ?? '' ) ] );
            } ), 'preflight' );
            if ( ! empty( $t['target'] ) ) {
                $this->say( '      It goes into ' . $t['target']['label'] . '. The rest of this ' . ( $t['target']['kind'] === 'multisite' ? 'network' : 'install' ) . ' stays as it is.' );
            }
        } elseif ( ( $install = Scope::install() ) && ! $resuming ) {
            $this->say( '      This is the main site of ' . ( $install['kind'] === 'multisite' ? 'a multisite network' : 'a WP Freighter install' ) . ', and the pull replaces it. To bring the source in as a new ' . ( $install['kind'] === 'multisite' ? 'subsite' : 'tenant' ) . ' instead, use --into=new.' );
        }

        $this->say( '[2/9] Bringing the site over' );
        $move_args = array_intersect_key( $assoc_args, array_flip( [ 'skip', 'skip-rows', 'template', 'bring-everything', 'bring-root', 'super-admins', 'skip-quiet-mode', 'bring-host-files', 'catch-up', 'full-database', 'site', 'shared-tables', 'into', 'slug', 'domain' ] ) );
        $c = $this->check( $this->step( function () use ( $pull, $source, $move_args ) {
            return $pull->connect( $source['url'], $source['token'], [ 'client' => 'cli', 'kind' => 'pull', 'args' => $move_args ], ! empty( $move_args['catch-up'] ), isset( $move_args['site'] ) ? (string) $move_args['site'] : null, ! empty( $move_args['shared-tables'] ) );
        } ), 'connect' );
        // The main address of a network or WP Freighter install: which of
        // its sites? Moving the whole install as one isn't built (see
        // Import::whole_install_refusal()), so --site names one, and
        // nothing is left to resume.
        if ( ! empty( $c['install'] ) && empty( $c['scope'] ) ) {
            $install = (array) $c['install'];
            $whole   = $import->whole_install_refusal();
            $import->cancel( 'cleanup' );
            $sites = [];
            foreach ( (array) ( $install['sites'] ?? [] ) as $site ) {
                $sites[] = '--site=' . $site['id'] . ' ' . $site['url'] . ( (string) ( $site['name'] ?? '' ) !== '' ? ' (' . $site['name'] . ')' : '' );
            }
            $what = $install['kind'] === 'multisite' ? 'a multisite network' : 'a WP Freighter install';
            $this->fail( ( isset( $move_args['site'] ) && strtolower( trim( (string) $move_args['site'] ) ) === 'all' && $whole
                ? $whole->get_error_message()
                : 'The source is ' . $what . ' with ' . self::plural( (int) ( $install['total'] ?? count( $sites ) ), 'site' ) . '. Name the one to bring with --site=<id> (moving the whole ' . ( $install['kind'] === 'multisite' ? 'network' : 'install' ) . ' as one isn\'t supported yet). Nothing here was changed.' )
                . ' Its sites: ' . implode( '; ', array_slice( $sites, 0, 50 ) ) . ( count( $sites ) > 50 ? '; …' : '' ), 'connect' );
        }
        $this->resume_hint = $import_id;
        $src_home = untrailingslashit( (string) ( $c['source_home'] ?? '' ) );
        $this->say( '      Connected to ' . ( $src_home ?: $source['url'] ) . ' (' . (int) ( $c['table_count'] ?? 0 ) . ' tables).' );
        $this->report_scope( $c, $summary, ! empty( $move_args['shared-tables'] ) );
        $scan = function ( $step, $chunk = 0 ) use ( $pull ) {
            return $this->check( $this->step( function () use ( $pull, $step, $chunk ) { return $pull->scan_step( $step, $chunk ); } ), 'scan' );
        };
        $scan( 'initiate' );
        for ( $i = 0; $i < 100000; $i++ ) {
            $s = $scan( 'scan' );
            if ( ( $s['status'] ?? '' ) === 'scan_complete' ) {
                break;
            }
        }
        $chunks = $scan( 'chunkify' );
        for ( $n = 1; $n <= (int) ( $chunks['total_chunks'] ?? 0 ); $n++ ) {
            $scan( 'process_chunk', $n );
        }
        $fin = $scan( 'finalize' );
        if ( ! empty( $fin['catch_up'] ) ) {
            $cu = $fin['catch_up'];
            $summary['catch_up'] = $cu;
            $since = ' since the last pull (' . wp_date( 'Y-m-d H:i', (int) $cu['since'] ) . ')';
            $this->say( '      Catching up: ' . ( ! isset( $cu['left_before'] ) || $cu['left_before'] === null
                // Remembered by an older build: left-behind files can't be told apart.
                ? self::plural( (int) $cu['changed'], 'file' ) . ' new, changed or left behind' . $since
                : self::plural( (int) $cu['changed'], 'file' ) . ' new or changed' . $since . ( $cu['left_before'] ? ', ' . self::plural( (int) $cu['left_before'], 'file' ) . ' that pull left behind (What to bring decides again)' : '' ) )
                . ', ' . number_format( (int) $cu['removed'] ) . ' removed on the source, ' . number_format( (int) $cu['unchanged'] ) . ' unchanged.' . ( ! empty( $cu['approx'] ) ? ' (The source sends no file times, so files were compared by size.)' : '' ) );
        } elseif ( isset( $fin['catch_up'] ) ) {
            $this->say( '      Nothing is remembered from an earlier pull of this source, so everything comes across.' );
        }
        $this->choose( $pull, $assoc_args, $summary );
        $this->report_checks( Checks::before_move( $import_id ), $summary, ! empty( $assoc_args['skip-checks'] ) );
        // Batches sized to the source as it goes (see Pace).
        $this->pull_files_paced( $pull );
        $this->say( '      Copying the database' );
        $db = $this->pull_database_paced( $pull, ! empty( $assoc_args['catch-up'] ) && empty( $assoc_args['full-database'] ) );
        $plan = (array) ( $db['plan'] ?? [] );
        if ( ! empty( $plan['nofp'] ) ) {
            $this->say( '      Every table comes across: ' . $plan['nofp'] . '.' );
        } elseif ( $plan ) {
            $this->say( '      Compared with the last pull: ' . self::plural( (int) $plan['copied'], 'table' ) . ' to copy, ' . number_format( count( (array) $plan['skipped'] ) ) . ' unchanged on both sides.' );
            foreach ( (array) $plan['changed'] as $table => $why ) {
                $this->say( "        {$table}: {$why}." );
            }
        }
        if ( ! empty( $db['single'] ) ) {
            $this->say( '      The source runs an older Disembark, so its database came in one request.' );
        }
        $this->say( '      Database copied (' . (int) ( $db['tables'] ?? 0 ) . ' tables, ' . size_format( (int) ( $db['bytes'] ?? 0 ) ) . ').' );

        $this->say( '[3/9] Reading the backup' );
        $info       = $this->check( $this->step( function () use ( $import ) { return $import->staged_info(); } ), 'read' );
        $src_prefix = (string) ( $info['source']['db_prefix'] ?? '' );
        if ( $src_home === '' ) {
            $src_home = untrailingslashit( (string) ( $info['source']['home_url'] ?? '' ) );
        }
        if ( ! empty( $info['multisite'] ) ) {
            $summary['multisite_constants'] = $info['multisite'];
            $this->say( '      The source is a multisite network. This site keeps its own wp-config.php, so add these constants to it:' );
            foreach ( $info['multisite'] as $line ) {
                $this->say( '        ' . $line );
            }
        }

        $this->say( '[4/9] Saving a rollback point' );
        // Named for the site that came over (one site of an install is
        // reached through the install's address).
        $label = ( ! empty( $summary['catch_up'] ) ? 'Before catch-up from ' : 'Before pull from ' ) . wp_parse_url( $src_home !== '' ? $src_home : $source['url'], PHP_URL_HOST );
        $snap = $this->check( $this->budgeted( function ( $r ) { return 'Saved ' . (int) $r['tables_done'] . ' of ' . (int) $r['tables_total'] . ' tables'; }, function ( $budget ) use ( $import, $label ) { return $import->snapshot( $label, $budget ); } ), 'snapshot' );
        $summary['rollback_id'] = $import_id;
        $summary['undo']        = "wp disembark rollback {$import_id}";
        $this->say( "      Saved. Undo any time with: wp disembark rollback {$import_id}" );
        $target = (array) ( $snap['target'] ?? [] );
        if ( $target ) {
            $summary['into'] = $target;
            $dest_home       = untrailingslashit( (string) $target['home'] );
            $dest_prefix     = (string) $target['prefix'];
            $summary['destination'] = $dest_home;
            $this->say( '      ' . ( ! empty( $target['created'] ) ? 'Created ' : 'Replacing ' ) . ( $target['kind'] === 'multisite' ? 'subsite ' : 'tenant ' ) . $target['id'] . ' at ' . $dest_home . ' (tables ' . $dest_prefix . '*)' . ( ! empty( $target['created'] ) ? '; the rollback removes it.' : '.' ) );
        }

        // Quiet mode goes on before the files and database arrive, so no
        // request in between can run the source's jobs or send its emails.
        // (Not for a subsite or tenant: it would cover every site here.)
        $quiet = ! $target && empty( $assoc_args['skip-quiet-mode'] ) && $src_home !== '' && Quiet::address( $src_home ) !== Quiet::address( $dest_home );
        if ( $quiet ) {
            $this->check( $this->step( function () use ( $src_home, $import_id ) { return Quiet::enable( $src_home, $import_id ); } ), 'quiet-mode', $import_id );
            $summary['quiet_mode'] = [ 'on' => true, 'off' => 'wp disembark quiet-mode off' ];
            $this->say( '      Quiet mode on: emails held, scheduled jobs paused, checkout closed, payment and marketing APIs blocked.' );
        }

        $this->say( '[5/9] Putting files in place' );
        $keep = empty( $assoc_args['bring-host-files'] );
        $dep  = $this->check( $this->budgeted( function ( $r ) { return 'Placed ' . number_format( (int) $r['placed'] ) . ' of ' . number_format( (int) $r['total'] ) . ' files'; }, function ( $budget ) use ( $import, $keep ) { return $import->deploy_files( [ '*' ], $keep, $budget ); } ), 'files', $import_id );
        if ( ! empty( $dep['removed'] ) ) {
            $summary['files_removed'] = (int) $dep['removed'];
            $this->say( '      Removed ' . self::plural( (int) $dep['removed'], 'file' ) . ' that ' . ( (int) $dep['removed'] === 1 ? 'is' : 'are' ) . ' gone from the source (kept in the rollback point).' );
        }
        $summary['files_deployed']  = (int) $dep['deployed'];
        $summary['host_files_kept'] = $dep['host_files_kept'];
        if ( ! empty( $dep['loaders_disabled'] ) ) {
            $summary['loaders_disabled'] = $dep['loaders_disabled'];
            $this->say( '      Disabled firewall loaders that point at files not on this server: ' . implode( ', ', $dep['loaders_disabled'] ) );
        }
        $this->say( '      Placed ' . number_format( (int) $dep['deployed'] ) . ' files.' );
        if ( ! empty( $dep['shared_kept'] ) ) {
            $summary['shared_kept'] = $dep['shared_kept'];
            $this->say( '      Kept the versions already here of ' . self::plural( count( $dep['shared_kept'] ), 'shared plugin or theme folder' ) . ' (other sites run them): ' . implode( ', ', array_map( 'basename', array_slice( $dep['shared_kept'], 0, 8 ) ) ) . ( count( $dep['shared_kept'] ) > 8 ? ', …' : '' ) );
        }
        if ( ! empty( $dep['host_files_kept'] ) ) {
            $this->say( '      Left out (host files; this site keeps its own): ' . implode( ', ', $dep['host_files_kept'] ) );
        }
        if ( ! empty( $dep['errors'] ) ) {
            $this->fail( count( $dep['errors'] ) . ' files could not be put in place (first: ' . $dep['errors'][0] . '). Stopped before the database import.', 'files', $import_id );
        }

        if ( ! empty( $info['sql_file'] ) ) {
            $this->say( '[6/9] Importing the database' );
            $sql_file = $info['sql_file'];
            $sq = $this->check( $this->budgeted( function ( $r ) { return 'Imported ' . (int) floor( 100 * $r['bytes_done'] / max( 1, $r['bytes_total'] ) ) . '%'; }, function ( $budget ) use ( $import, $sql_file, $src_prefix, $dest_prefix ) { return $import->import_staged_sql( $sql_file, $src_prefix, $dest_prefix, $budget ); } ), 'database', $import_id );
            $summary['statements'] = (int) $sq['statements_executed'];
            foreach ( (array) ( $sq['converted'] ?? [] ) as $conversion => $tables ) {
                $summary['converted'][ $conversion ] = (int) $tables;
                $this->say( "      This database server doesn't support all of the source's table settings, so {$conversion} in " . self::plural( (int) $tables, 'table' ) . '.' );
            }
            if ( ! empty( $sq['definers_removed'] ) ) {
                $summary['definers_removed'] = (int) $sq['definers_removed'];
                $this->say( '      Removed the source server\'s DEFINER account from ' . (int) $sq['definers_removed'] . ' views, triggers or routines.' );
            }
            if ( ! empty( $sq['errors'] ) ) {
                $this->fail( 'The database import reported ' . count( $sq['errors'] ) . ' errors (first: ' . $sq['errors'][0] . '). ' . ( ! empty( $sq['database_unchanged'] ) ? 'This site\'s database was not changed: the import works on copies of the tables and only swaps them in when every statement succeeds.' : 'Stopped before rewriting URLs.' ), 'database', $import_id );
            }
            $this->say( '      ' . number_format( (int) $sq['statements_executed'] ) . ' statements executed, ' . self::plural( (int) ( $sq['tables_swapped'] ?? 0 ), 'table' ) . ' swapped in.' );
            if ( ! empty( $sq['tables_other_sites'] ) ) {
                $this->say( '      Left out ' . self::plural( (int) $sq['tables_other_sites'], 'table' ) . ' that belong to other sites of this install.' );
            }
            if ( ! empty( $sq['tables_left_out'] ) ) {
                $this->say( '      Left out ' . self::plural( (int) $sq['tables_left_out'], 'table' ) . ' without the source\'s prefix (' . $src_prefix . '), so nothing else here is touched.' );
            }
            if ( ! empty( $sq['left_out_words'] ) ) {
                $summary['left_out'] = $sq['left_out'] ?? [];
                $this->say( '      ! ' . $sq['left_out_words'] );
            }
            $brought = $pull->super_admins_brought();
            if ( $brought ) {
                $summary['super_admins'] = $brought;
                $this->say( '      Brought ' . self::plural( count( $brought ), 'super admin' ) . ' as administrators: ' . implode( ', ', $brought ) . '.' );
            }
            if ( isset( $sq['users'] ) && ( $sq['users']['created'] || $sq['users']['matched'] ) ) {
                $summary['users'] = $sq['users'];
                foreach ( (array) ( $sq['users']['renamed'] ?? [] ) as $r ) {
                    $this->say( "      The login {$r['from']} ({$r['email']}) " . ( $r['why'] ?? 'was taken' ) . " on this network, so that user logs in as {$r['to']}." );
                }
                foreach ( (array) ( $sq['users']['unattributed'] ?? [] ) as $r ) {
                    $this->say( "      ! {$r['login']} ({$r['email']}) couldn't be added ({$r['error']}): their posts, comments and orders here have no author now." );
                }
                if ( ! empty( $sq['users']['orphaned'] ) ) {
                    $this->say( '      ' . self::plural( (int) $sq['users']['orphaned'], 'row' ) . ' named a user the source no longer has, so they have no author now (not someone else here).' );
                }
                $this->say( '      ' . ( empty( $sq['target'] ) ? 'The network keeps its users. ' : '' ) . 'Users joined the network: ' . self::plural( (int) $sq['users']['created'], 'new account' ) . ', ' . self::plural( (int) $sq['users']['matched'], 'existing account' ) . ' matched by email' . ( $sq['users']['renumbered'] ? ' (' . self::plural( (int) $sq['users']['renumbered'], 'row' ) . ' of posts, comments and orders renumbered to match)' : '' ) . '.' );
            }
            if ( $src_prefix !== '' && $dest_prefix !== '' && $src_prefix !== $dest_prefix ) {
                $rp = $this->check( $this->step( function () use ( $import, $src_prefix, $dest_prefix ) { return $import->remap_table_prefix( $src_prefix, $dest_prefix ); } ), 'prefix', $import_id );
                $summary['prefix'] = [ 'from' => $src_prefix, 'to' => $dest_prefix ];
                $this->say( "      Table prefix remapped {$src_prefix} → {$dest_prefix}" . ( ! empty( $rp['keys_changed'] ) ? ' (' . self::plural( (int) $rp['keys_changed'], 'user and role key' ) . ').' : '.' ) );
            }

            $this->say( '[7/9] Rewriting URLs' );
            $paths = Import::path_pairs( $info['source'] ?? null ) + Import::scope_pairs( $info['source'] ?? null );
            if ( ( $src_home !== '' && $src_home !== $dest_home ) || $paths ) {
                $from = $src_home !== '' ? $src_home : $dest_home;
                $sr   = $this->check( $this->budgeted( function ( $r ) { return 'Rewriting table ' . ( (int) $r['tables_done'] + 1 ) . ' of ' . (int) $r['tables_total']; }, function ( $budget ) use ( $import, $from, $dest_home ) {
                    return $import->move_search_replace( $from, $dest_home, $budget );
                } ), 'urls', $import_id );
                foreach ( (array) ( $sr['paths'] ?? [] ) as $old_path => $new_path ) {
                    $summary['paths'][ $old_path ] = $new_path;
                    $this->say( "      Server path {$old_path} → {$new_path}" );
                }
                $summary['urls'] = [
                    'from'            => $src_home,
                    'to'              => $dest_home,
                    'cells_changed'   => (int) ( $sr['cells_changed'] ?? 0 ),
                    'files_rewritten' => (int) ( $sr['files_rewritten'] ?? 0 ),
                ];
                $this->say( '      ' . ( $from !== $dest_home ? "{$src_home} → {$dest_home} (every http, https and www spelling): " : 'Same address: ' ) . number_format( (int) ( $sr['cells_changed'] ?? 0 ) ) . ' cells, ' . (int) ( $sr['files_rewritten'] ?? 0 ) . ' files under uploads.' );
                if ( ! empty( $sr['files_too_big_total'] ) ) {
                    $summary['urls']['files_too_big'] = (array) ( $sr['files_too_big'] ?? [] );
                    $this->say( '      ! ' . self::plural( (int) $sr['files_too_big_total'], 'file' ) . ' under uploads ' . ( (int) $sr['files_too_big_total'] === 1 ? 'mentions' : 'mention' ) . " {$src_home} but " . ( (int) $sr['files_too_big_total'] === 1 ? 'is' : 'are' ) . ' over 5 MB, so left as is: ' . implode( ', ', array_slice( (array) ( $sr['files_too_big'] ?? [] ), 0, 8 ) ) . ( (int) $sr['files_too_big_total'] > 8 ? ', …' : '' ) );
                }
                if ( ! empty( $sr['files_mentioning_total'] ) ) {
                    // Under urls as restore has it; code_mentions stays for 3.0 readers.
                    $summary['urls']['files_mentioning_source'] = (array) ( $sr['files_mentioning_source'] ?? [] );
                    $summary['code_mentions'] = $sr['files_mentioning_source'] ?? [];
                    $this->say( '      ' . (int) $sr['files_mentioning_total'] . " theme or plugin files still mention {$src_home} (left as is):" );
                    foreach ( (array) ( $sr['files_mentioning_source'] ?? [] ) as $file ) {
                        $this->say( "        {$file}" );
                    }
                }
            } else {
                $this->say( '      Same address, nothing to rewrite.' );
            }
        } else {
            $this->say( '[6/9] No database in the backup' );
        }

        // Tables a catch-up left alone are checked on the source once more
        // (copied again if they changed meanwhile), and both sides'
        // fingerprints are kept for the next catch-up.
        if ( ! empty( $info['sql_file'] ) ) {
            $from = $src_home !== '' ? $src_home : $dest_home;
            $fp   = $this->budgeted( function ( $r ) { return (string) ( $r['what'] ?? '' ); }, function ( $budget ) use ( $pull, $from, $dest_home ) { return $pull->settle( $budget, $from, $dest_home ); } );
            if ( ! is_wp_error( $fp ) && empty( $fp['none'] ) ) {
                foreach ( (array) ( $fp['recopied'] ?? [] ) as $table ) {
                    $this->say( "      {$table} changed during the catch-up, copied again." );
                }
                if ( ! empty( $fp['skip'] ) && empty( $fp['nofp'] ) ) {
                    $summary['tables'] = [ 'copied' => (int) $fp['copied'], 'skipped' => (array) $fp['skipped'], 'recopied' => (array) $fp['recopied'], 'soft' => (array) $fp['soft'] ];
                    $this->say( '      ' . self::plural( (int) $fp['copied'], 'table' ) . ' copied, ' . number_format( count( (array) $fp['skipped'] ) ) . ' unchanged and skipped' . ( $fp['skipped'] ? ': ' . implode( ', ', (array) $fp['skipped'] ) : '' ) . '.' );
                    foreach ( (array) $fp['soft'] as $table => $why ) {
                        $this->say( "      Copied {$table} rather than compare it: {$why}." );
                    }
                }
            } elseif ( is_wp_error( $fp ) ) {
                $this->fail( $fp->get_error_message(), 'database', $import_id );
            }
        }

        $this->say( '[8/9] Finishing up' );
        $fin  = $this->check( $this->step( function () use ( $import ) { return $import->finalize(); } ), 'finalize', $import_id );
        $tidy = (array) ( $fin['tidy'] ?? [] );
        if ( ! empty( $tidy['caches_cleared'] ) ) {
            $summary['caches_cleared'] = array_keys( (array) $tidy['caches_cleared'] );
            $this->say( '      Cleared page caches so nothing serves the old site: ' . implode( ', ', $summary['caches_cleared'] ) . '.' );
        }
        if ( ! empty( $tidy['tables_removed'] ) ) {
            $gone = (array) $tidy['tables_removed'];
            $summary['tables_removed'] = $gone;
            $this->say( '      Removed ' . self::plural( count( $gone ), 'table' ) . ' the incoming site doesn\'t have (kept in the restore point): ' . implode( ', ', array_slice( $gone, 0, 8 ) ) . ( count( $gone ) > 8 ? ', …' : '' ) . '.' );
        }
        foreach ( (array) ( $tidy['plugins_turned_off'] ?? [] ) as $plugin ) {
            $summary['plugins_turned_off'][] = $plugin['plugin'];
            $this->say( "      Turned off {$plugin['name']}: {$plugin['why']}." );
        }
        if ( ! empty( $tidy['login_url']['url'] ) ) {
            $summary['login_url'] = $tidy['login_url']['url'];
            $this->say( "      The login page is moved by {$tidy['login_url']['plugin']}: {$tidy['login_url']['url']}" );
        }
        $this->resume_hint = '';

        $lines = [ number_format( (int) ( $summary['files_deployed'] ?? 0 ) ) . ' files placed, ' . number_format( (int) ( $summary['statements'] ?? 0 ) ) . ' database statements run.' ];
        if ( empty( $assoc_args['skip-verify'] ) ) {
            $this->say( '[9/9] Checking the result' );
            $verify = Verify::run( $import_id );
            if ( ! is_wp_error( $verify ) ) {
                $summary['verify'] = $this->report_verify( $verify );
                $lines[]           = $verify['ok'] ? 'Checks after the move: all good.' : 'Checks after the move found ' . self::plural( (int) $verify['problems'], 'problem' ) . ': run wp disembark verify ' . $import_id . ' on the site for details.';
            }
        }
        if ( ! empty( $summary['login_url'] ) ) {
            $lines[] = 'Log in at ' . $summary['login_url'];
        }
        Notify::send( $import_id, 'finished', [ 'kind' => 'pull', 'source' => $source['url'], 'destination' => $dest_home, 'rollback_id' => $import_id, 'seconds' => time() - $started, 'lines' => $lines ] );
        $this->notify = [];

        $summary['ok']   = true;
        $summary['note'] = is_multisite() && empty( $target ) ? "The network kept its users; the source's users joined them (an account already here keeps its own password)." : "User accounts are now the source's: log in with the source site's credentials.";
        if ( $this->json ) {
            \WP_CLI::line( wp_json_encode( $summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
            return;
        }
        \WP_CLI::success( ! empty( $target )
            ? "Pulled {$source['url']} into " . ( $target['kind'] === 'multisite' ? 'subsite ' : 'tenant ' ) . "{$target['id']} at {$dest_home}." . ( $target['kind'] === 'multisite' ? ' Its users log in with their own passwords (an account that was already here keeps its own).' : " Log in to it with the source site's credentials." )
            : "Pulled {$source['url']} into {$dest_home}. " . ( is_multisite() ? "The network kept its users; the source's users joined them (an account already here keeps its own password)." : "Log in with the source site's credentials." ) );
        \WP_CLI::log( "Undo: wp disembark rollback {$import_id}" );
        if ( $quiet ) {
            \WP_CLI::log( 'Quiet mode is on (this is a copy). Lift it with: wp disembark quiet-mode off' );
        }
    }

    /**
     * Restores a backup zip that is on this server into this site.
     *
     * Runs the same steps as Tools → Disembark → Move with a backup zip:
     * saves a rollback point, puts the files in place, imports the database,
     * remaps the table prefix and rewrites the old address. Takes Disembark's
     * own backup zips and other hosts' downloadable backups (Kinsta's, WP
     * Engine's and others with the site's files and a .sql dump). This site's
     * wp-config.php, its own Disembark and its host setup are kept.
     *
     * The database is found on its own when it's clear which dump is the
     * site's (Disembark's own, the only .sql at the zip's root or at the root
     * of its public/ folder, or the one named for DB_NAME or holding the
     * site's options table). Otherwise the restore stops before changing
     * anything and lists the dumps it found: name one with --database. A zip
     * with no database is restored only with --files-only.
     *
     * ## OPTIONS
     *
     * <zip>
     * : Path to the backup zip on this server.
     *
     * [--database=<path>]
     * : The database dump to restore, as its path in the zip (for example
     * mysql/shop.sql). Needed when the zip holds several and it isn't clear
     * which is the site's, or when the only ones are deep in its folders.
     *
     * [--files-only]
     * : Restore the files and leave this site's database as it is. Needed
     * when the zip has no database, so a restore never ends as files only by
     * accident.
     *
     * [--into=<where>]
     * : When this site is the main site of a multisite network or a WP
     * Freighter install: bring the backup in as a new subsite or tenant
     * ("new"), or replace the subsite or tenant with this ID, instead of
     * replacing this site. Its tables, uploads and settings stay its own;
     * plugins and themes are added to the shared folders when missing (never
     * replacing ones other sites run); on a network the incoming users join
     * the network's (matched by email). Rolling back removes a new site
     * completely. Quiet mode stays off, since it would cover every site.
     *
     * [--slug=<slug>]
     * : With --into=new on a network: the new subsite's path or subdomain
     * (defaults to the backup's).
     *
     * [--domain=<domain>]
     * : With --into=new on a WP Freighter install with domain mapping: the
     * new tenant's domain (defaults to the backup's).
     *
     * [--max-retries=<n>]
     * : How many times a step is tried again after the source or this host
     * fails (429, 502, 503, 504, a dropped connection). Default 20; 0 for no
     * limit.
     *
     * [--max-retry-minutes=<n>]
     * : How long a step keeps trying after such a failure. Default 15; 0 for
     * no limit. With both 0 it keeps trying, as the dashboard does.
     *
     * [--yes]
     * : Don't ask for confirmation.
     *
     * [--source-url=<url>]
     * : The address the backup came from, when neither the zip nor its
     * database says (the address rewrite runs from it).
     *
     * [--bring-host-files]
     * : Also put the backup's host files in place (mu-plugins and cache
     * drop-ins).
     *
     * [--notify=<to>]
     * : Email address(es) and/or https:// webhook URLs (Slack, Discord or any
     * URL that takes JSON), comma-separated, told when the restore finishes or
     * stops. Sent even from a copy in quiet mode.
     *
     * [--skip-checks]
     * : Go ahead even when the checks before the move find a problem that
     * would stop it.
     *
     * [--skip-verify]
     * : Don't run the checks after the move.
     *
     * [--skip-quiet-mode]
     * : Don't turn on quiet mode. By default a restore from another address
     * turns it on (see `wp disembark quiet-mode`).
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: text
     * options:
     *   - text
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     $ wp disembark restore ~/backups/example-com.zip --yes
     *     $ wp disembark restore ~/kinsta-backup.zip --yes --format=json
     *     $ wp disembark restore ~/cpanel-backup.zip --database=mysql/example_wp.sql --yes
     *     $ wp disembark restore ~/files.zip --files-only --yes
     *     $ wp disembark restore ~/backups/shop.zip --into=new --slug=shop --yes
     */
    public function restore( $args, $assoc_args ) {
        $this->json = ( $assoc_args['format'] ?? 'text' ) === 'json';
        $this->retry_limits( $assoc_args );
        @set_time_limit( 0 );
        $zip = (string) ( $args[0] ?? '' );
        if ( $zip === '' || ! is_file( $zip ) ) {
            $this->fail( "No zip at {$zip}." );
        }
        $zip = (string) realpath( $zip );
        if ( ! empty( $assoc_args['files-only'] ) && isset( $assoc_args['database'] ) ) {
            $this->fail( '--database and --files-only don\'t go together: pick one.' );
        }
        if ( $refused = Import::sqlite_refusal() ?: Import::here_refusal() ) {
            $this->fail( $refused->get_error_message(), 'preflight' );
        }
        $import_id = substr( bin2hex( random_bytes( 12 ) ), 0, 24 );
        $import    = new Import( $import_id );
        $summary   = [ 'ok' => false, 'source' => $zip, 'import_id' => $import_id ];
        $started   = time();
        if ( ! empty( $assoc_args['notify'] ) ) {
            $set = Notify::set( $import_id, (string) $assoc_args['notify'] );
            if ( is_wp_error( $set ) ) {
                $this->fail( $set->get_error_message() );
            }
        }
        $this->notify = [ 'import' => $import_id, 'kind' => 'restore', 'source' => basename( $zip ), 'destination' => home_url(), 'started' => $started ];
        // Before anything is saved, a stop leaves nothing behind.
        $stop = function ( $message, $step ) use ( $import ) {
            if ( $this->notify ) {
                Notify::send( $this->notify['import'], 'failed', [
                    'kind'        => $this->notify['kind'],
                    'source'      => $this->notify['source'],
                    'destination' => $this->notify['destination'],
                    'step'        => $step,
                    'error'       => $message . ' Nothing on this site was changed.',
                    'seconds'     => time() - (int) $this->notify['started'],
                ] );
                $this->notify = [];
            }
            $import->cancel( 'cleanup' );
            $this->fail( $message . ' Nothing on this site was changed.', $step );
        };

        $this->say( '[1/9] Checking this site' );
        $pre         = $this->check( $import->preflight(), 'preflight' );
        $dest_prefix = (string) ( $pre['db_prefix'] ?? '' );
        $dest_home   = untrailingslashit( (string) ( $pre['home_url'] ?? home_url() ) );
        $summary['destination'] = $dest_home;
        $into = (string) ( $assoc_args['into'] ?? '' );
        $this->confirm( $assoc_args, $into !== ''
            ? 'Bring the backup in ' . basename( $zip ) . ' into ' . ( $into === 'new' ? 'a new subsite or tenant' : 'subsite or tenant ' . $into . ' (replacing it)' ) . ' here? A rollback point is saved first.'
            : 'Replace ' . $dest_home . ' with the backup in ' . basename( $zip ) . '? A rollback point is saved first, and ' . ( is_multisite() ? 'the network keeps its users (the incoming site\'s users join them).' : 'afterwards you log in with the backup\'s credentials.' ) );
        if ( $into !== '' ) {
            $t = $import->set_target( $into, [ 'slug' => (string) ( $assoc_args['slug'] ?? '' ), 'domain' => (string) ( $assoc_args['domain'] ?? '' ) ] );
            if ( is_wp_error( $t ) ) {
                $stop( $t->get_error_message(), 'preflight' );
            }
            if ( ! empty( $t['target'] ) ) {
                $this->say( '      It goes into ' . $t['target']['label'] . '. The rest of this ' . ( $t['target']['kind'] === 'multisite' ? 'network' : 'install' ) . ' stays as it is.' );
            }
        } elseif ( $install = Scope::install() ) {
            $this->say( '      This is the main site of ' . ( $install['kind'] === 'multisite' ? 'a multisite network' : 'a WP Freighter install' ) . ', and the restore replaces it. To bring the backup in as a new ' . ( $install['kind'] === 'multisite' ? 'subsite' : 'tenant' ) . ' instead, use --into=new.' );
        }

        $this->say( '[2/9] Unpacking ' . basename( $zip ) . ' (' . size_format( (int) filesize( $zip ) ) . ')' );
        $t0 = microtime( true );
        $st = $import->stage_zip_file( $zip );
        if ( is_wp_error( $st ) ) {
            $stop( $st->get_error_message(), 'stage' );
        }
        $this->say( '      ' . number_format( (int) $st['files_staged'] ) . ' files unpacked in ' . round( microtime( true ) - $t0 ) . 's.' );
        $checks = Checks::before_move( $import_id );
        if ( ! empty( $checks['refused'] ) ) {
            $stop( (string) $checks['refused'], 'checks' );
        }
        if ( ! empty( $checks['blocked'] ) && empty( $assoc_args['skip-checks'] ) ) {
            if ( $this->notify ) {
                $blocks = array_filter( (array) ( $checks['checks'] ?? [] ), function ( $c ) { return $c['level'] === 'block'; } );
                Notify::send( $this->notify['import'], 'failed', [
                    'kind'        => $this->notify['kind'],
                    'source'      => $this->notify['source'],
                    'destination' => $this->notify['destination'],
                    'step'        => 'checks',
                    'error'       => 'Checks before the move found ' . self::plural( count( $blocks ), 'problem' ) . ' that would stop it: ' . implode( '; ', array_column( $blocks, 'title' ) ) . '.',
                    'seconds'     => time() - (int) $this->notify['started'],
                ] );
                $this->notify = [];
            }
            $import->cancel( 'cleanup' );
        }
        $this->report_checks( $checks, $summary, ! empty( $assoc_args['skip-checks'] ), false );

        $this->say( '[3/9] Reading the backup' );
        $choice = ! empty( $assoc_args['files-only'] ) ? 'none' : ( isset( $assoc_args['database'] ) ? trim( (string) $assoc_args['database'] ) : null );
        if ( $choice !== null && $choice !== 'none' ) {
            $chosen = $import->choose_database( $choice, 'named with --database' );
            if ( is_wp_error( $chosen ) ) {
                $info  = $import->staged_info();
                $found = array_merge( array_column( (array) ( $info['database']['candidates'] ?? [] ), 'file' ), (string) ( $info['sql_file'] ?? '' ) !== '' ? [ $info['sql_file'] ] : [] );
                $stop( $chosen->get_error_message() . ( $found ? ' It has: ' . implode( ', ', $found ) . '.' : ' It has none.' ), 'read' );
            }
            $choice = null;
        }
        $info = $import->staged_info( $choice );
        if ( is_wp_error( $info ) ) {
            $stop( $info->get_error_message(), 'read' );
        }
        $info = json_decode( (string) wp_json_encode( $info ), true );
        if ( ! empty( $info['refused'] ) ) {
            $stop( (string) $info['refused'], 'read' );
        }
        $db = (array) $info['database'];
        if ( ( $db['ask'] ?? '' ) === 'none' ) {
            $stop( 'This zip has no database Disembark can use (no .sql or .sql.gz file in it). To restore its files only, run this again with --files-only.', 'read' );
        }
        if ( ( $db['ask'] ?? '' ) === 'pick' ) {
            $lines = [];
            foreach ( (array) $db['candidates'] as $c ) {
                $lines[] = self::describe_dump( $c );
            }
            $stop( 'This zip holds ' . self::plural( count( $lines ), 'database dump' ) . ( count( $lines ) === 1 ? ' inside the site\'s files' : ' and it isn\'t clear which is the site\'s' ) . '. Name one with --database=<path>, or restore the files only with --files-only. Found: ' . implode( '; ', $lines ) . '.', 'read' );
        }
        $summary['database'] = $info['sql_file'] ? [ 'file' => $info['sql_file'], 'why' => $db['why'] ] : null;
        $this->say( '      ' . number_format( (int) $info['files'] ) . ' files staged. ' . ( $info['sql_file'] ? 'Database: ' . $info['sql_file'] . ' (' . $db['why'] . ').' : 'No database: files only (--files-only).' ) );
        $src_prefix = (string) ( $info['source']['db_prefix'] ?? '' );
        $src_home   = untrailingslashit( (string) ( $assoc_args['source-url'] ?? ( $info['source']['home_url'] ?? '' ) ) );
        if ( $info['sql_file'] ) {
            $this->say( '      From ' . ( $src_home !== '' ? $src_home : 'an address its database will tell' ) . ( $src_prefix !== '' ? ", tables {$src_prefix}*" : '' ) . '.' );
        }
        if ( ! empty( $info['multisite'] ) ) {
            $summary['multisite_constants'] = $info['multisite'];
            $this->say( '      The source is a multisite network. This site keeps its own wp-config.php, so add these constants to it:' );
            foreach ( $info['multisite'] as $line ) {
                $this->say( '        ' . $line );
            }
        }

        $this->say( '[4/9] Saving a rollback point' );
        $snap = $this->check( $this->budgeted( function ( $r ) { return 'Saved ' . (int) $r['tables_done'] . ' of ' . (int) $r['tables_total'] . ' tables'; }, function ( $budget ) use ( $import, $zip ) { return $import->snapshot( 'Before restore of ' . basename( $zip ), $budget ); } ), 'snapshot' );
        $summary['rollback_id'] = $import_id;
        $summary['undo']        = "wp disembark rollback {$import_id}";
        $this->say( "      Saved. Undo any time with: wp disembark rollback {$import_id}" );
        $target = (array) ( $snap['target'] ?? [] );
        if ( $target ) {
            $summary['into']             = $target;
            $dest_home                   = untrailingslashit( (string) $target['home'] );
            $dest_prefix                 = (string) $target['prefix'];
            $summary['destination']      = $dest_home;
            $this->notify['destination'] = $dest_home;
            $this->say( '      ' . ( ! empty( $target['created'] ) ? 'Created ' : 'Replacing ' ) . ( $target['kind'] === 'multisite' ? 'subsite ' : 'tenant ' ) . $target['id'] . ' at ' . $dest_home . ' (tables ' . $dest_prefix . '*)' . ( ! empty( $target['created'] ) ? '; the rollback removes it.' : '.' ) );
        }

        // Quiet mode goes on before the files and database arrive (or, when
        // only the imported database tells the old address, right after it).
        // (Not for a subsite or tenant: it would cover every site here.)
        $quiet    = false;
        $go_quiet = function ( $from ) use ( &$quiet, &$summary, $assoc_args, $dest_home, $import_id, $target ) {
            if ( $quiet || $target || ! empty( $assoc_args['skip-quiet-mode'] ) || $from === '' || Quiet::address( $from ) === Quiet::address( $dest_home ) ) {
                return;
            }
            $this->check( Quiet::enable( $from, $import_id ), 'quiet-mode', $import_id );
            $quiet = true;
            $summary['quiet_mode'] = [ 'on' => true, 'off' => 'wp disembark quiet-mode off' ];
            $this->say( '      Quiet mode on: emails held, scheduled jobs paused, checkout closed, payment and marketing APIs blocked.' );
        };
        $go_quiet( $src_home );

        $this->say( '[5/9] Putting files in place' );
        $keep = empty( $assoc_args['bring-host-files'] );
        $dep  = $this->check( $this->budgeted( function ( $r ) { return 'Placed ' . number_format( (int) $r['placed'] ) . ' of ' . number_format( (int) $r['total'] ) . ' files'; }, function ( $budget ) use ( $import, $keep ) { return $import->deploy_files( [ '*' ], $keep, $budget ); } ), 'files', $import_id );
        $summary['files_deployed']  = (int) $dep['deployed'];
        $summary['host_files_kept'] = $dep['host_files_kept'] ?? [];
        $this->say( '      Placed ' . number_format( (int) $dep['deployed'] ) . ' files.' );
        if ( ! empty( $dep['removed'] ) ) {
            $summary['files_removed'] = (int) $dep['removed'];
            $this->say( '      Removed ' . self::plural( (int) $dep['removed'], 'file' ) . ' that ' . ( (int) $dep['removed'] === 1 ? 'is' : 'are' ) . ' gone from the backup (kept in the rollback point).' );
        }
        if ( ! empty( $dep['loaders_disabled'] ) ) {
            $summary['loaders_disabled'] = $dep['loaders_disabled'];
            $this->say( '      Disabled firewall loaders that point at files not on this server: ' . implode( ', ', $dep['loaders_disabled'] ) );
        }
        if ( ! empty( $dep['shared_kept'] ) ) {
            $summary['shared_kept'] = $dep['shared_kept'];
            $this->say( '      Kept the versions already here of ' . self::plural( count( $dep['shared_kept'] ), 'shared plugin or theme folder' ) . ' (other sites run them): ' . implode( ', ', array_map( 'basename', array_slice( $dep['shared_kept'], 0, 8 ) ) ) . ( count( $dep['shared_kept'] ) > 8 ? ', …' : '' ) );
        }
        if ( ! empty( $dep['host_files_kept'] ) ) {
            $this->say( '      Left out (host files; this site keeps its own): ' . implode( ', ', $dep['host_files_kept'] ) );
        }
        if ( ! empty( $dep['errors'] ) ) {
            $this->fail( count( $dep['errors'] ) . ' files could not be put in place (first: ' . $dep['errors'][0] . '). Stopped before the database import.', 'files', $import_id );
        }

        if ( $info['sql_file'] ) {
            $this->say( '[6/9] Importing the database' );
            $sql_file = (string) $info['sql_file'];
            $t0 = microtime( true );
            $sq = $this->check( $this->budgeted( function ( $r ) { return 'Imported ' . (int) floor( 100 * $r['bytes_done'] / max( 1, $r['bytes_total'] ) ) . '%'; }, function ( $budget ) use ( $import, $sql_file, $src_prefix, $dest_prefix ) { return $import->import_staged_sql( $sql_file, $src_prefix, $dest_prefix, $budget ); } ), 'database', $import_id );
            $summary['statements'] = (int) $sq['statements_executed'];
            $summary['database_seconds'] = (int) round( microtime( true ) - $t0 );
            foreach ( (array) ( $sq['converted'] ?? [] ) as $conversion => $tables ) {
                $summary['converted'][ $conversion ] = (int) $tables;
                $this->say( "      This database server doesn't support all of the source's table settings, so {$conversion} in " . self::plural( (int) $tables, 'table' ) . '.' );
            }
            if ( ! empty( $sq['definers_removed'] ) ) {
                $summary['definers_removed'] = (int) $sq['definers_removed'];
                $this->say( '      Removed the source server\'s DEFINER account from ' . (int) $sq['definers_removed'] . ' views, triggers or routines.' );
            }
            if ( ! empty( $sq['errors'] ) ) {
                $this->fail( 'The database import reported ' . count( $sq['errors'] ) . ' errors (first: ' . $sq['errors'][0] . '). ' . ( ! empty( $sq['database_unchanged'] ) ? 'This site\'s database was not changed: the import works on copies of the tables and only swaps them in when every statement succeeds.' : 'Stopped before rewriting URLs.' ), 'database', $import_id );
            }
            $this->say( '      ' . number_format( (int) $sq['statements_executed'] ) . ' statements executed, ' . self::plural( (int) ( $sq['tables_swapped'] ?? 0 ), 'table' ) . ' swapped in (' . $summary['database_seconds'] . 's).' );
            if ( ! empty( $sq['tables_other_sites'] ) ) {
                $summary['tables_other_sites'] = (int) $sq['tables_other_sites'];
                $this->say( '      Left out ' . self::plural( (int) $sq['tables_other_sites'], 'table' ) . ' that belong to other sites of this install.' );
            }
            if ( ! empty( $sq['tables_left_out'] ) ) {
                $summary['tables_left_out'] = (int) $sq['tables_left_out'];
                $this->say( '      Left out ' . self::plural( (int) $sq['tables_left_out'], 'table' ) . ' without the source\'s prefix (' . $src_prefix . '), so nothing else here is touched.' );
            }
            if ( ! empty( $sq['left_out_words'] ) ) {
                $summary['left_out'] = $sq['left_out'] ?? [];
                $this->say( '      ! ' . $sq['left_out_words'] );
            }
            if ( isset( $sq['users'] ) && ( $sq['users']['created'] || $sq['users']['matched'] ) ) {
                $summary['users'] = $sq['users'];
                foreach ( (array) ( $sq['users']['renamed'] ?? [] ) as $r ) {
                    $this->say( "      The login {$r['from']} ({$r['email']}) " . ( $r['why'] ?? 'was taken' ) . " on this network, so that user logs in as {$r['to']}." );
                }
                foreach ( (array) ( $sq['users']['unattributed'] ?? [] ) as $r ) {
                    $this->say( "      ! {$r['login']} ({$r['email']}) couldn't be added ({$r['error']}): their posts, comments and orders here have no author now." );
                }
                if ( ! empty( $sq['users']['orphaned'] ) ) {
                    $this->say( '      ' . self::plural( (int) $sq['users']['orphaned'], 'row' ) . ' named a user the source no longer has, so they have no author now (not someone else here).' );
                }
                $this->say( '      ' . ( empty( $sq['target'] ) ? 'The network keeps its users. ' : '' ) . 'Users joined the network: ' . self::plural( (int) $sq['users']['created'], 'new account' ) . ', ' . self::plural( (int) $sq['users']['matched'], 'existing account' ) . ' matched by email' . ( $sq['users']['renumbered'] ? ' (' . self::plural( (int) $sq['users']['renumbered'], 'row' ) . ' of posts, comments and orders renumbered to match)' : '' ) . '.' );
            }
            if ( $src_home === '' && ! empty( $sq['source_home'] ) ) {
                $src_home = untrailingslashit( (string) $sq['source_home'] );
                $this->say( "      The database is {$src_home}'s." );
                $go_quiet( $src_home );
            }
            if ( $src_prefix !== '' && $dest_prefix !== '' && $src_prefix !== $dest_prefix ) {
                $rp = $this->check( $import->remap_table_prefix( $src_prefix, $dest_prefix ), 'prefix', $import_id );
                $summary['prefix'] = [ 'from' => $src_prefix, 'to' => $dest_prefix ];
                $this->say( "      Table prefix remapped {$src_prefix} → {$dest_prefix}" . ( ! empty( $rp['keys_changed'] ) ? ' (' . self::plural( (int) $rp['keys_changed'], 'user and role key' ) . ').' : '.' ) );
            }

            $this->say( '[7/9] Rewriting URLs' );
            $paths = Import::path_pairs( $info['source'] ?? null ) + Import::scope_pairs( $info['source'] ?? null );
            if ( ( $src_home !== '' && $src_home !== $dest_home ) || $paths ) {
                $from = $src_home !== '' ? $src_home : $dest_home;
                $sr   = $this->check( $this->budgeted( function ( $r ) { return 'Rewriting table ' . ( (int) $r['tables_done'] + 1 ) . ' of ' . (int) $r['tables_total']; }, function ( $budget ) use ( $import, $from, $dest_home ) {
                    return $import->move_search_replace( $from, $dest_home, $budget );
                } ), 'urls', $import_id );
                $summary['urls'] = [ 'from' => $src_home, 'to' => $dest_home, 'cells_changed' => (int) ( $sr['cells_changed'] ?? 0 ), 'files_rewritten' => (int) ( $sr['files_rewritten'] ?? 0 ) ];
                $this->say( '      ' . ( $from !== $dest_home ? "{$src_home} → {$dest_home} (every http, https and www spelling): " : 'Same address: ' ) . number_format( (int) ( $sr['cells_changed'] ?? 0 ) ) . ' cells, ' . (int) ( $sr['files_rewritten'] ?? 0 ) . ' files under uploads.' );
                if ( ! empty( $sr['files_too_big_total'] ) ) {
                    $summary['urls']['files_too_big'] = (array) ( $sr['files_too_big'] ?? [] );
                    $this->say( '      ! ' . self::plural( (int) $sr['files_too_big_total'], 'file' ) . ' under uploads ' . ( (int) $sr['files_too_big_total'] === 1 ? 'mentions' : 'mention' ) . " {$src_home} but " . ( (int) $sr['files_too_big_total'] === 1 ? 'is' : 'are' ) . ' over 5 MB, so left as is: ' . implode( ', ', array_slice( (array) ( $sr['files_too_big'] ?? [] ), 0, 8 ) ) . ( (int) $sr['files_too_big_total'] > 8 ? ', …' : '' ) );
                }
                if ( ! empty( $sr['files_mentioning_total'] ) ) {
                    $summary['urls']['files_mentioning_source'] = (array) ( $sr['files_mentioning_source'] ?? [] );
                    $this->say( '      ' . (int) $sr['files_mentioning_total'] . " theme or plugin files still mention {$src_home} (left as is): " . implode( ', ', array_slice( (array) ( $sr['files_mentioning_source'] ?? [] ), 0, 8 ) ) . ( (int) $sr['files_mentioning_total'] > 8 ? ', …' : '' ) );
                }
            } else {
                $this->say( '      ' . ( $src_home === '' ? 'The backup doesn\'t say its address (pass --source-url to rewrite it).' : 'Same address, nothing to rewrite.' ) );
            }
        } else {
            $this->say( '[6/9] No database: this site keeps its own (--files-only)' );
        }

        $this->say( '[8/9] Finishing up' );
        $fin  = $this->check( $import->finalize(), 'finalize', $import_id );
        $tidy = (array) ( $fin['tidy'] ?? [] );
        if ( ! empty( $tidy['caches_cleared'] ) ) {
            $summary['caches_cleared'] = array_keys( (array) $tidy['caches_cleared'] );
            $this->say( '      Cleared page caches so nothing serves the old site: ' . implode( ', ', $summary['caches_cleared'] ) . '.' );
        }
        if ( ! empty( $tidy['tables_removed'] ) ) {
            $gone = (array) $tidy['tables_removed'];
            $summary['tables_removed'] = $gone;
            $this->say( '      Removed ' . self::plural( count( $gone ), 'table' ) . ' the incoming site doesn\'t have (kept in the restore point): ' . implode( ', ', array_slice( $gone, 0, 8 ) ) . ( count( $gone ) > 8 ? ', …' : '' ) . '.' );
        }
        foreach ( (array) ( $tidy['plugins_turned_off'] ?? [] ) as $plugin ) {
            $summary['plugins_turned_off'][] = $plugin['plugin'];
            $this->say( "      Turned off {$plugin['name']}: {$plugin['why']}." );
        }
        if ( ! empty( $tidy['login_url']['url'] ) ) {
            $summary['login_url'] = $tidy['login_url']['url'];
            $this->say( "      The login page is moved by {$tidy['login_url']['plugin']}: {$tidy['login_url']['url']}" );
        }

        $lines = [ number_format( (int) ( $summary['files_deployed'] ?? 0 ) ) . ' files placed' . ( $info['sql_file'] ? ', ' . number_format( (int) ( $summary['statements'] ?? 0 ) ) . ' database statements run.' : ' (files only).' ) ];
        if ( empty( $assoc_args['skip-verify'] ) ) {
            $this->say( '[9/9] Checking the result' );
            $verify = Verify::run( $import_id );
            if ( ! is_wp_error( $verify ) ) {
                $summary['verify'] = $this->report_verify( $verify );
                $lines[]           = $verify['ok'] ? 'Checks after the move: all good.' : 'Checks after the move found ' . self::plural( (int) $verify['problems'], 'problem' ) . ': run wp disembark verify ' . $import_id . ' on the site for details.';
            }
        }
        if ( ! empty( $summary['login_url'] ) ) {
            $lines[] = 'Log in at ' . $summary['login_url'];
        }
        Notify::send( $import_id, 'finished', [ 'kind' => 'restore', 'source' => basename( $zip ), 'destination' => $dest_home, 'rollback_id' => $import_id, 'seconds' => time() - $started, 'lines' => $lines ] );
        $this->notify = [];

        $summary['ok']      = true;
        $summary['seconds'] = time() - $started;
        if ( ! $info['sql_file'] ) {
            $summary['note'] = 'Files only; the database is this site\'s own.';
        } elseif ( ! empty( $target ) ) {
            $summary['note'] = $target['kind'] === 'multisite'
                ? 'Its users joined the network and log in with their own passwords (an account that was already here keeps its own).'
                : "User accounts are now the backup's: log in to it with the backup's credentials.";
        } else {
            $summary['note'] = is_multisite()
                ? "The network kept its users; the backup's users joined them (an account already here keeps its own password)."
                : "User accounts are now the backup's: log in with the backup's credentials.";
        }
        if ( $this->json ) {
            \WP_CLI::line( wp_json_encode( $summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
            return;
        }
        if ( ! empty( $target ) ) {
            \WP_CLI::success( 'Restored ' . basename( $zip ) . ' into ' . ( $target['kind'] === 'multisite' ? 'subsite ' : 'tenant ' ) . "{$target['id']} at {$dest_home}" . ( $info['sql_file'] ? ( $target['kind'] === 'multisite' ? '. Its users log in with their own passwords (an account that was already here keeps its own).' : '. Log in to it with the backup\'s credentials.' ) : ' (files only; the database is this site\'s own).' ) );
        } else {
            \WP_CLI::success( 'Restored ' . basename( $zip ) . " into {$dest_home}" . ( $info['sql_file'] ? ( is_multisite() ? '. The network kept its users; the backup\'s users joined them (an account already here keeps its own password).' : '. Log in with the backup\'s credentials.' ) : ' (files only; the database is this site\'s own).' ) );
        }
        \WP_CLI::log( "Undo: wp disembark rollback {$import_id}" );
        if ( $quiet ) {
            \WP_CLI::log( 'Quiet mode is on (this is a copy). Lift it with: wp disembark quiet-mode off' );
        }
    }

    /** One line about a database dump found in a zip (see Import::database_info()). */
    private static function describe_dump( $c ) {
        $bits = [ size_format( (int) $c['size'] ) ];
        if ( empty( $c['usable'] ) ) {
            $bits[] = 'not usable: ' . ( $c['note'] !== '' ? $c['note'] : 'not a database dump' );
        } else {
            if ( $c['prefix'] !== '' ) {
                $bits[] = "tables {$c['prefix']}*";
            }
            $bits[] = $c['options'] !== '' ? "options table {$c['options']}" : ( ! empty( $c['whole'] ) ? 'no options table' : 'no options table in the first ' . size_format( (int) $c['looked'] ) );
            if ( $c['home'] !== '' ) {
                $bits[] = $c['home'];
            }
        }
        return $c['file'] . ' (' . implode( ', ', $bits ) . ( ! empty( $c['guess'] ) ? '; the best guess' : '' ) . ')';
    }

    /**
     * Shows, turns on or turns off quiet mode.
     *
     * Quiet mode keeps a copy of a site from reaching the real world: emails
     * are held (and listed here), WP-Cron and Action Scheduler are paused,
     * WooCommerce checkout is closed and its webhooks paused, and payment,
     * marketing, shipping-label and email APIs are blocked. A pull from
     * another address turns it on. It's bound to the address it was turned on
     * for, and stands down by itself if the site moves to another one.
     *
     * ## OPTIONS
     *
     * [<action>]
     * : What to do.
     * ---
     * default: status
     * options:
     *   - status
     *   - on
     *   - off
     *   - emails
     * ---
     *
     * [--source=<url>]
     * : With "on": the site this one is a copy of, shown in notices.
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: text
     * options:
     *   - text
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     $ wp disembark quiet-mode
     *     $ wp disembark quiet-mode emails
     *     $ wp disembark quiet-mode off
     *
     * @subcommand quiet-mode
     */
    public function quiet_mode( $args, $assoc_args ) {
        $this->json = ( $assoc_args['format'] ?? 'text' ) === 'json';
        $action     = $args[0] ?? 'status';
        if ( $action === 'on' ) {
            $status = $this->check( Quiet::enable( (string) ( $assoc_args['source'] ?? '' ) ), 'quiet-mode' );
        } elseif ( $action === 'off' ) {
            $status = $this->check( Quiet::disable(), 'quiet-mode' );
        } else {
            $status = Quiet::status( true );
        }
        if ( $this->json ) {
            \WP_CLI::line( wp_json_encode( $status, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
            return;
        }
        if ( $action === 'emails' ) {
            if ( empty( $status['held'] ) ) {
                \WP_CLI::log( 'No held emails.' );
                return;
            }
            $rows = array_map( function ( $m ) {
                return [ 'time' => wp_date( 'Y-m-d H:i', $m['time'] ), 'to' => $m['to'], 'subject' => $m['subject'] ];
            }, $status['held'] );
            \WP_CLI\Utils\format_items( 'table', $rows, [ 'time', 'to', 'subject' ] );
            if ( $status['held_total'] > count( $rows ) ) {
                \WP_CLI::log( number_format( $status['held_total'] ) . ' held in all; the newest ' . count( $rows ) . ' are kept.' );
            }
            return;
        }
        if ( ! $status['on'] ) {
            \WP_CLI::log( $action === 'off' ? 'Quiet mode is off.' : 'Quiet mode is off. Turn it on with: wp disembark quiet-mode on' );
            return;
        }
        if ( ! $status['active'] ) {
            \WP_CLI::log( 'Quiet mode is standing down: it was turned on for ' . $status['home'] . ' and this site has moved to ' . home_url() . '. Remove it with: wp disembark quiet-mode off' );
            return;
        }
        \WP_CLI::log( 'Quiet mode is on' . ( $status['source'] ? ' (a copy of ' . $status['source'] . ')' : '' ) . ': emails held, scheduled jobs paused, checkout closed, payment and marketing APIs blocked.' );
        \WP_CLI::log( number_format( $status['held_total'] ) . ( $status['held_total'] === 1 ? ' email' : ' emails' ) . ' held so far (list them: wp disembark quiet-mode emails).' );
        foreach ( $status['blocked'] as $b ) {
            \WP_CLI::log( "Blocked {$b['count']} " . ( $b['count'] === 1 ? 'call' : 'calls' ) . " to {$b['label']} ({$b['host']})." );
        }
        \WP_CLI::log( 'Lift it with: wp disembark quiet-mode off' );
    }

    /**
     * Lists rollback points, newest first.
     *
     * Every restore, push and search & replace saves one before it changes
     * anything.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - ids
     * ---
     *
     * @subcommand restore-points
     */
    public function restore_points( $args, $assoc_args ) {
        $format = $assoc_args['format'] ?? 'table';
        $points = Import::restore_points();
        if ( $format === 'json' ) {
            \WP_CLI::line( wp_json_encode( $points, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
            return;
        }
        if ( $format === 'ids' ) {
            \WP_CLI::line( implode( ' ', wp_list_pluck( $points, 'id' ) ) );
            return;
        }
        if ( empty( $points ) ) {
            \WP_CLI::log( 'No rollback points.' );
            return;
        }
        $rows = array_map( function ( $p ) {
            return [
                'id'          => $p['id'],
                'label'       => $p['label'],
                'created'     => wp_date( 'Y-m-d H:i', $p['created_at'] ),
                'rolled_back' => $p['rolled_back_at'] ? wp_date( 'Y-m-d H:i', $p['rolled_back_at'] ) : ( ! empty( $p['rolled_back_legacy'] ) ? 'yes (older version)' : '' ),
                'files'       => ! $p['files'] && ! $p['deployed'] ? '-' : ( ! empty( $p['files_spent'] ) ? 'put back' : 'kept' ),
                'size'        => size_format( $p['size'] ),
            ];
        }, $points );
        \WP_CLI\Utils\format_items( 'table', $rows, [ 'id', 'label', 'created', 'rolled_back', 'files', 'size' ] );
    }

    /**
     * Puts this site back as it was before a restore, push or search & replace.
     *
     * The database is replaced with the rollback point's snapshot, and the
     * files the move replaced come back while the ones it added go. Restore
     * points made after this one are undone first, newest first, so the site
     * is as it was then. Anything changed since is lost.
     *
     * A rollback moves the point's saved files back into place, so they go
     * back once: a point whose files were already put back (the files column
     * of `wp disembark restore-points` says so) rolls back its database only.
     *
     * ## OPTIONS
     *
     * <id>
     * : A rollback point id from `wp disembark restore-points`, or "latest".
     *
     * [--database-only]
     * : Put back the database only and leave the files as they are now. The
     * point keeps its saved files, so a later rollback can still put them back.
     *
     * [--only]
     * : Undo this restore point alone, leaving the ones made after it as they
     * are.
     *
     * [--max-retries=<n>]
     * : How many times a step is tried again after the source or this host
     * fails (429, 502, 503, 504, a dropped connection). Default 20; 0 for no
     * limit.
     *
     * [--max-retry-minutes=<n>]
     * : How long a step keeps trying after such a failure. Default 15; 0 for
     * no limit. With both 0 it keeps trying, as the dashboard does.
     *
     * [--yes]
     * : Don't ask for confirmation.
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: text
     * options:
     *   - text
     *   - json
     * ---
     */
    public function rollback( $args, $assoc_args ) {
        $this->json = ( $assoc_args['format'] ?? 'text' ) === 'json';
        $this->retry_limits( $assoc_args );
        $point      = $this->find_point( $args[0] );
        $files      = empty( $assoc_args['database-only'] );
        $through    = empty( $assoc_args['only'] );
        $meta       = (array) Import::rollback_meta( Storage::base_dir() . "/_import_{$point['id']}/rollback" );
        $later      = $through ? Import::later_points( $meta, $files ) : [];
        $what       = $files ? 'database and files' : 'database only (files stay as they are)';
        // A subsite or tenant a move made goes whole, files and all, even
        // database only (see Import::rollback_run()).
        $makes = [];
        foreach ( array_merge( $later, [ [ 'id' => $point['id'], 'label' => $point['label'] ] ] ) as $step ) {
            $m = $step['id'] === $point['id'] ? $meta : (array) Import::rollback_meta( Storage::base_dir() . "/_import_{$step['id']}/rollback" );
            if ( ! empty( $m['target']['created'] ) ) {
                $makes[] = $step['label'];
            }
        }
        if ( ! $files && $makes ) {
            $what = 'database only (files stay as they are, except that ' . ( count( $makes ) === 1 ? 'the subsite or tenant the move "' . $makes[0] . '" made goes whole, its files included' : 'the subsites or tenants the moves ' . implode( ', ', array_map( function ( $l ) { return '"' . $l . '"'; }, $makes ) ) . ' made go whole, their files included' ) . ')';
        }
        if ( $files && ! empty( $point['files_spent'] ) ) {
            $what = 'database only: an earlier rollback already put this point\'s files back';
        }
        $this->say( "Rolling back to \"{$point['label']}\" (" . wp_date( 'Y-m-d H:i', $point['created_at'] ) . "): {$what}." );
        if ( $later ) {
            $this->say( 'First undoing ' . self::plural( count( $later ), 'later restore point' ) . ', newest first:' );
            foreach ( $later as $l ) {
                $this->say( '  - ' . wp_date( 'Y-m-d H:i', $l['created_at'] ) . "  {$l['label']}" );
            }
        }
        $steps = array_merge( $later, [ [ 'id' => $point['id'], 'label' => $point['label'] ] ] );
        foreach ( ( new Import( $point['id'] ) )->rollback_notes( $steps ) as $note ) {
            \WP_CLI::warning( $note );
        }
        $this->confirm( $assoc_args, 'Roll ' . home_url() . " back to \"{$point['label']}\"" . ( $later ? ', undoing ' . self::plural( count( $later ), 'later move' ) . ' first' : '' ) . '? Anything changed since is lost.' );
        @set_time_limit( 0 );
        $this->say( 'Rolling back…' );
        $import = new Import( $point['id'] );
        $opts   = [ 'files' => $files ];
        $r      = $this->check( $this->budgeted( function ( $x ) { return ( isset( $x['step'] ) ? '[' . (int) $x['step'] . '/' . (int) $x['steps'] . '] ' . $x['label'] . ': ' : '' ) . ( ( $x['phase'] ?? '' ) === 'files' ? 'Putting files back: ' . (int) $x['files_done'] . ' of ' . (int) $x['files_total'] : ( ( $x['phase'] ?? '' ) === 'next' ? 'Starting' : 'Putting the database back' ) ); }, function ( $budget ) use ( $import, $opts, $through ) { return $through ? $import->rollback_through( $budget, $opts ) : $import->rollback( $budget, $opts ); } ), 'rollback' );
        unset( $r['done'] );
        $has_errors = ! empty( $r['errors'] );
        if ( $this->json ) {
            \WP_CLI::line( wp_json_encode( array_merge( [ 'ok' => ! $has_errors, 'id' => $point['id'] ], (array) $r ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
            if ( $has_errors ) {
                \WP_CLI::error( 'Rollback reported ' . self::plural( count( $r['errors'] ), 'problem' ) . ': ' . implode( '; ', array_slice( $r['errors'], 0, 3 ) ) );
            }
            return;
        }
        // A restore point from before 3.0's scope fix holds other sites' tables.
        foreach ( (array) ( $r['warnings'] ?? [] ) as $line ) {
            \WP_CLI::log( $line );
        }
        if ( $has_errors ) {
            $this->fail( 'Rollback reported ' . self::plural( count( $r['errors'] ), 'problem' ) . ': ' . implode( '; ', array_slice( $r['errors'], 0, 3 ) ), 'rollback' );
        }
        $relogin = ( preg_match( '/^Before (restore|pull|push)/i', $point['label'] ) || ! empty( $point['deployed'] ) ) && empty( $r['logins_kept'] );
        \WP_CLI::success( 'Rolled back to "' . $point['label'] . '".' . ( $relogin ? ' User accounts are back to this site\'s own, so log in with the credentials from before.' : '' ) );
    }

    /**
     * Deletes a rollback point (its database snapshot and saved files).
     *
     * ## OPTIONS
     *
     * <id>
     * : A rollback point id from `wp disembark restore-points`, or "all".
     *
     * [--yes]
     * : Don't ask for confirmation.
     *
     * @subcommand delete-restore-point
     */
    public function delete_restore_point( $args, $assoc_args ) {
        $ids = $args[0] === 'all' ? wp_list_pluck( Import::restore_points(), 'id' ) : [ $this->find_point( $args[0] )['id'] ];
        if ( empty( $ids ) ) {
            \WP_CLI::log( 'No rollback points.' );
            return;
        }
        $this->confirm( $assoc_args, 'Delete ' . count( $ids ) . ' rollback point' . ( count( $ids ) === 1 ? '' : 's' ) . '? That way back is gone for good.' );
        foreach ( $ids as $id ) {
            $this->check( ( new Import( $id ) )->delete_restore_point(), 'delete' );
        }
        \WP_CLI::success( 'Deleted ' . count( $ids ) . ' rollback point' . ( count( $ids ) === 1 ? '' : 's' ) . '.' );
    }

    /**
     * Replaces text across the database, safely inside serialized PHP values
     * and JSON (the engine a restore uses). Saves a rollback point first.
     *
     * ## OPTIONS
     *
     * <from>
     * : Text to find.
     *
     * <to>
     * : Replacement.
     *
     * [--dry-run]
     * : Count what would change and show samples. Changes nothing.
     *
     * [--tables=<tables>]
     * : Only these tables (comma-separated). By default, this site's own
     * tables: on a network or WP Freighter install, not the other sites'
     * (nor another WordPress sharing the database).
     *
     * [--all-tables]
     * : Every table in the database.
     *
     * [--max-retries=<n>]
     * : How many times a step is tried again after the source or this host
     * fails (429, 502, 503, 504, a dropped connection). Default 20; 0 for no
     * limit.
     *
     * [--max-retry-minutes=<n>]
     * : How long a step keeps trying after such a failure. Default 15; 0 for
     * no limit. With both 0 it keeps trying, as the dashboard does.
     *
     * [--yes]
     * : Don't ask for confirmation.
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: text
     * options:
     *   - text
     *   - json
     * ---
     *
     * @subcommand search-replace
     */
    public function search_replace( $args, $assoc_args ) {
        $this->json = ( $assoc_args['format'] ?? 'text' ) === 'json';
        $this->retry_limits( $assoc_args );
        list( $from, $to ) = $args;
        if ( $from === '' ) {
            $this->fail( 'Enter the text to search for.' );
        }
        global $wpdb;
        $tables = array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $assoc_args['tables'] ?? '' ) ) ) ) );
        $named  = (bool) $tables;
        if ( ! $tables ) {
            $tables = ! empty( $assoc_args['all-tables'] ) ? (array) $wpdb->get_col( 'SHOW TABLES' ) : Import::own_tables();
        }
        $dry    = ! empty( $assoc_args['dry-run'] );
        @set_time_limit( 0 );
        $out = [];
        if ( ! $dry ) {
            $this->confirm( $assoc_args, "Replace \"{$from}\" with \"{$to}\" across " . ( $named ? implode( ', ', $tables ) : ( ! empty( $assoc_args['all-tables'] ) ? 'every table in the database' : 'this site\'s tables' ) ) . '? A rollback point is saved first.' );
            // The restore point holds the tables that would change, and the
            // replace runs on those (both a piece at a time, see Pace).
            $preview = $this->check( ( new Import() )->search_replace( $from, $to, $tables, true ), 'search-replace' );
            $tables  = array_keys( (array) ( $preview['by_table'] ?? [] ) );
            $id      = substr( bin2hex( random_bytes( 12 ) ), 0, 24 );
            if ( $tables ) {
                $this->say( 'Saving a rollback point…' );
                $this->check( $this->budgeted( function () { return 'Saving the rollback point'; }, function ( $budget ) use ( $id, $from, $to, $tables ) {
                    return ( new Import( $id ) )->snapshot( "Before search & replace: {$from} → " . ( $to === '' ? '(nothing)' : $to ), $budget, $tables );
                } ), 'snapshot' );
                $out['rollback_id'] = $id;
                $out['undo']        = "wp disembark rollback {$id}";
            }
        }
        if ( ! $dry && ! $tables ) {
            $r = [ 'tables' => 0, 'rows_changed' => 0, 'cells_changed' => 0, 'by_table' => [], 'samples' => [] ];
        } elseif ( ! $dry ) {
            $r = $this->check( $this->budgeted( function ( $x ) { return 'Replacing in table ' . ( (int) $x['tables_done'] + 1 ) . ' of ' . (int) $x['tables_total']; }, function ( $budget ) use ( $id, $from, $to, $tables ) {
                return ( new Import( $id ) )->search_replace( $from, $to, $tables, false, [ 'budget' => $budget ] );
            } ), 'search-replace', $out['rollback_id'] ?? '' );
            unset( $r['done'] );
        } else {
            $r = $this->check( ( new Import() )->search_replace( $from, $to, $tables, $dry ), 'search-replace' );
        }
        $out = array_merge( [ 'ok' => true, 'dry_run' => $dry ], $r, $out );
        if ( $this->json ) {
            \WP_CLI::line( wp_json_encode( $out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
            return;
        }
        foreach ( (array) ( $r['by_table'] ?? [] ) as $table => $cells ) {
            \WP_CLI::log( sprintf( '  %-40s %s', $table, number_format( $cells ) ) );
        }
        if ( $dry ) {
            foreach ( array_slice( (array) ( $r['samples'] ?? [] ), 0, 5 ) as $s ) {
                \WP_CLI::log( "\n  {$s['table']}.{$s['column']} ({$s['key']})\n    - {$s['before']}\n    + {$s['after']}" );
            }
            \WP_CLI::success( number_format( (int) $r['cells_changed'] ) . ' cells in ' . number_format( (int) $r['rows_changed'] ) . ' rows would change. Nothing was changed.' );
            return;
        }
        $undo = ! empty( $out['rollback_id'] ) ? " Undo: wp disembark rollback {$out['rollback_id']}" : '';
        \WP_CLI::success( number_format( (int) $r['cells_changed'] ) . ' cells changed in ' . number_format( (int) $r['rows_changed'] ) . ' rows.' . $undo );
    }

    // ------------------------------------------------------------------ helpers

    /** Parses "<url> <token>" or "disembark connect <url> <token>". */
    /** Says which site of a network or WP Freighter install comes across. */
    private function report_scope( $c, &$summary, $shared_rows = false ) {
        $install = (array) ( $c['install'] ?? [] );
        $scope   = (array) ( $c['scope'] ?? [] );
        if ( $scope ) {
            $summary['site'] = [ 'id' => $scope['site'], 'kind' => $scope['kind'], 'home' => $scope['home'], 'prefix' => $scope['prefix'] ];
            $this->say( '      Bringing one site: ' . $scope['label'] . '. It arrives as a standalone site.' );
            if ( isset( $scope['users'] ) && $scope['users'] !== null ) {
                $this->say( '      ' . self::plural( (int) $scope['users'], 'user' ) . ( (int) $scope['users'] === 1 ? ' comes' : ' come' ) . ' across: its members, the authors of its posts, and the network\'s super admins (as administrators; --no-super-admins leaves out the ones with no role there).' );
            }
            $left = (array) ( $scope['left_out'] ?? [] );
            if ( ! empty( $left['plugins'] ) || ! empty( $left['themes'] ) ) {
                $this->say( '      Left out ' . self::plural( count( (array) ( $left['plugins'] ?? [] ) ), 'plugin' ) . ' and ' . self::plural( count( (array) ( $left['themes'] ?? [] ) ), 'theme' ) . ' that this site doesn\'t use.' );
            }
            if ( ! empty( $scope['shared_tables'] ) ) {
                $summary['site']['shared_tables'] = $scope['shared_tables'];
                $this->say( '      ' . self::plural( count( $scope['shared_tables'] ), 'network-wide table' ) . ' ' . ( count( $scope['shared_tables'] ) === 1 ? 'comes' : 'come' ) . ' across ' . ( $shared_rows ? 'with every site\'s rows' : 'empty (--shared-tables brings their rows)' ) . ': ' . implode( ', ', array_slice( $scope['shared_tables'], 0, 8 ) ) . ( count( $scope['shared_tables'] ) > 8 ? ', …' : '' ) );
            }
            return;
        }
    }

    /**
     * "What to bring" for the CLI: the suggested skips (or a template, or
     * nothing with --bring-everything), plus --skip and --skip-rows.
     */
    private function choose( $pull, $assoc_args, &$summary ) {
        $contents = $this->check( $this->step( function () use ( $pull ) { return $pull->contents(); } ), 'scan' );
        $prefix   = strtolower( (string) $contents['prefix'] );
        $split    = function ( $key ) use ( $assoc_args ) {
            return array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $assoc_args[ $key ] ?? '' ) ) ) ) );
        };
        $paths = $split( 'skip' );
        $rows  = array_map( function ( $t ) use ( $prefix ) {
            return ( $prefix !== '' && stripos( $t, $prefix ) === 0 ) ? substr( $t, strlen( $prefix ) ) : $t;
        }, $split( 'skip-rows' ) );
        $ids = null;
        if ( ! empty( $assoc_args['template'] ) ) {
            $template = Templates::find( $assoc_args['template'] );
            if ( ! $template ) {
                $names = array_column( Templates::all(), 'name' );
                $this->fail( "No template named \"{$assoc_args['template']}\"." . ( $names ? ' Saved: ' . implode( ', ', $names ) . '.' : ' None are saved yet.' ), 'scan' );
            }
            $ids   = $template['suggestions'];
            $paths = array_merge( $template['paths'], $paths );
            $rows  = array_merge( $template['rows'], $rows );
        }
        if ( ! empty( $assoc_args['bring-everything'] ) ) {
            $ids = [];
        }
        $choice = Skips::resolve( $contents, $ids, $paths, $rows );
        // The install's own root files (see Skips::install_root()) stay
        // behind unless --bring-root asks for them, all or by name.
        $root = [];
        foreach ( $contents['suggestions'] as $s ) {
            if ( $s['id'] === 'install-root' ) {
                $root = $s['paths'];
            }
        }
        $brought = [];
        if ( isset( $assoc_args['bring-root'] ) && $root ) {
            $all_root = in_array( $assoc_args['bring-root'], [ true, '' ], true );
            $brought  = $all_root ? $root : array_values( array_intersect( $root, $split( 'bring-root' ) ) );
            $choice['skip_paths'] = array_values( array_diff( $choice['skip_paths'], $brought ) );
            if ( ! $all_root ) {
                foreach ( array_diff( $split( 'bring-root' ), $root ) as $unknown ) {
                    $this->say( "      --bring-root: {$unknown} isn't one of the install's root files here (" . implode( ', ', $root ) . ').' );
                }
            }
        }
        // The network's super admins with no role on the site (see Scope).
        $super_on = ! isset( $assoc_args['super-admins'] ) || ! in_array( $assoc_args['super-admins'], [ false, 'false', '0', 'no' ], true );
        $sel    = $this->check( $this->step( function () use ( $pull, $choice, $super_on ) { return $pull->select( $choice['skip_paths'], $choice['skip_names'], $choice['skip_rows'], $super_on ); } ), 'scan' );
        if ( array_key_exists( 'super_admins', $contents ) ) {
            $added = $contents['super_admins'];
            if ( $added === null ) {
                $this->say( '      The source runs an older Disembark: the network\'s super admins come across as administrators' . ( $super_on ? '.' : ', so --no-super-admins can\'t leave them out.' ) );
            } elseif ( $added ) {
                $this->say( '      ' . ( $super_on ? 'Bringing ' : 'Leaving out ' ) . self::plural( count( $added ), 'network super admin' ) . ' with no role on this site' . ( $super_on ? ' (as administrators)' : '' ) . ': ' . implode( ', ', $added ) . '.' . ( $super_on ? ' --no-super-admins leaves them out.' : '' ) );
            }
        }

        $this->say( '      ' . self::plural( (int) $sel['files'], 'file' ) . ' to copy (' . size_format( (int) $sel['bytes'] ) . ').' );
        $left = $sel['left_out'];
        if ( $left['files'] || $left['rows'] ) {
            $this->say( '      Leaving behind ' . self::plural( (int) $left['files'], 'file' ) . ' (' . size_format( (int) $left['bytes'] ) . ')' . ( $left['rows'] ? ' and the rows of ' . self::plural( count( $left['rows'] ), 'table' ) : '' ) . ':' );
            foreach ( $contents['suggestions'] as $s ) {
                if ( $ids === null ? $s['default'] : in_array( $s['id'], (array) $ids, true ) ) {
                    if ( $s['id'] === 'install-root' ) {
                        $kept = array_values( array_diff( $s['paths'], $brought ) );
                        if ( $kept ) {
                            $this->say( '        ' . $s['label'] . ': ' . implode( ', ', array_slice( $kept, 0, 12 ) ) . ( count( $kept ) > 12 ? ', …' : '' ) . ' (--bring-root brings them, or --bring-root=<names> some)' );
                        }
                        continue;
                    }
                    $this->say( '        ' . $s['label'] . ' (' . size_format( $s['bytes'] + $s['table_bytes'] ) . ')' );
                }
            }
            if ( $brought ) {
                $this->say( '      Bringing the install\'s root files you asked for: ' . implode( ', ', $brought ) . '.' );
            }
            foreach ( $paths as $p ) {
                $this->say( '        ' . $p );
            }
            $matched = array_map( 'strtolower', (array) $left['rows'] );
            foreach ( $rows as $r ) {
                if ( in_array( strtolower( $prefix . $r ), $matched, true ) ) {
                    $this->say( '        rows of ' . $prefix . $r );
                }
            }
        }
        $off = array_filter( $contents['suggestions'], function ( $s ) use ( $ids ) {
            return ! ( $ids === null ? $s['default'] : in_array( $s['id'], (array) $ids, true ) );
        } );
        if ( $off ) {
            $this->say( '      Also could leave behind (pass --skip / --skip-rows): ' . implode( '; ', array_map( function ( $s ) {
                return $s['label'] . ' (' . implode( ', ', array_merge( $s['paths'], $s['tables'] ) ) . ')';
            }, $off ) ) );
        }
        $summary['left_out'] = [
            'files'      => (int) $left['files'],
            'bytes'      => (int) $left['bytes'],
            'paths'      => $left['paths'],
            'names'      => $left['names'],
            'empty_tables' => $left['rows'],
        ];
    }

    private static function parse_connect( $args ) {
        $parts = array_values( array_filter( preg_split( '/\s+/', trim( implode( ' ', (array) $args ) ) ) ) );
        if ( count( $parts ) >= 2 && strtolower( $parts[0] ) === 'disembark' && strtolower( $parts[1] ) === 'connect' ) {
            $parts = array_slice( $parts, 2 );
        }
        if ( count( $parts ) !== 2 ) {
            return null;
        }
        list( $url, $token ) = $parts;
        if ( ! preg_match( '#^https?://#i', $url ) ) {
            if ( strpos( $url, '.' ) === false ) {
                return null;
            }
            $url = 'https://' . $url;
        }
        if ( ! preg_match( '/^[A-Za-z0-9]{20,}$/', $token ) ) {
            return null;
        }
        return [ 'url' => untrailingslashit( $url ), 'token' => $token ];
    }

    /** The rollback point for $id, or the newest one not rolled back for "latest". */
    private function find_point( $id ) {
        $points = Import::restore_points();
        if ( $id === 'latest' ) {
            foreach ( $points as $p ) {
                if ( empty( $p['rolled_back_at'] ) && empty( $p['rolled_back_legacy'] ) ) {
                    return $p;
                }
            }
            $this->fail( 'No rollback point to go back to.' );
        }
        foreach ( $points as $p ) {
            if ( $p['id'] === $id ) {
                return $p;
            }
        }
        if ( preg_match( '/^[a-f0-9]+$/', (string) $id ) && is_array( $meta = Import::rollback_meta( Storage::base_dir() . "/_import_{$id}/rollback" ) ) && ( $elsewhere = Import::point_elsewhere( $meta ) ) ) {
            $this->fail( $elsewhere );
        }
        $this->fail( "No rollback point with id {$id}. List them with: wp disembark restore-points" );
    }

    /** Asks before changing the site, unless --yes; never waits on a pipe. */
    private function confirm( $assoc_args, $question ) {
        if ( ! empty( $assoc_args['yes'] ) ) {
            return;
        }
        if ( function_exists( 'posix_isatty' ) && ! @posix_isatty( STDIN ) ) {
            $this->fail( 'This changes the site and there is no terminal to confirm in. Run it again with --yes.' );
        }
        \WP_CLI::confirm( $question );
    }

    /** Progress: stdout for people, stderr when stdout carries JSON. */
    private function say( $message ) {
        if ( $this->json ) {
            fwrite( STDERR, $message . "\n" );
            return;
        }
        \WP_CLI::log( $message );
    }

    /** Returns $result, or stops with its message when it's a WP_Error. */
    /** The import whose steps are recorded (see step()), and the next step's number. */
    private $steps_import = '';
    private $steps_seq    = 0;
    /** Set while a pull can be resumed: failures say how. */
    private $resume_hint  = '';
    /** The running move, for the notice a failure sends (see Notify). */
    private $notify       = [];

    /**
     * Prints the checks before a move; stops on a refusal or blocking check
     * unless $go_anyway (refusals can never be overridden).
     */
    private function report_checks( $result, &$summary, $go_anyway, $resumable = true ) {
        $marks = [ 'block' => '✗', 'warn' => '!', 'info' => '·' ];
        foreach ( (array) ( $result['checks'] ?? [] ) as $check ) {
            $summary['checks'][] = [ 'level' => $check['level'], 'id' => $check['id'], 'title' => $check['title'] ];
            $this->say( '      ' . $marks[ $check['level'] ] . ' ' . $check['title'] . ( $check['detail'] !== '' ? ': ' . $check['detail'] : '' ) );
        }
        if ( ! empty( $result['refused'] ) ) {
            $this->resume_hint = '';
            $this->fail( (string) $result['refused'], 'checks' );
        }
        if ( empty( $result['blocked'] ) ) {
            return;
        }
        if ( $go_anyway ) {
            $this->say( '      Going ahead anyway (--skip-checks).' );
            return;
        }
        $blocks = array_filter( (array) $result['checks'], function ( $c ) { return $c['level'] === 'block'; } );
        $hint   = $resumable
            ? 'Fix it and resume, or resume with --skip-checks to go ahead anyway.'
            : 'Fix it and run restore again, or pass --skip-checks.';
        $this->fail( 'Checks before the move found ' . self::plural( count( $blocks ), 'problem' ) . ' that would stop it: ' . implode( '; ', array_column( $blocks, 'title' ) ) . '. Nothing on this site has changed. ' . $hint, 'checks' );
    }

    /** Prints the checks after a move; returns their JSON summary. */
    private function report_verify( $v ) {
        $rows = $v['rows'] ?? null;
        if ( is_array( $rows ) ) {
            if ( empty( $rows['mismatched'] ) ) {
                $this->say( '      ✓ ' . self::plural( (int) $rows['checked'], 'table' ) . ' hold every row the source exported (' . number_format( (int) $rows['rows'] ) . ' rows).' );
            }
            foreach ( (array) $rows['mismatched'] as $m ) {
                $this->say( "      ✗ {$m['table']} has " . ( $m['actual'] === null ? 'gone missing' : number_format( (int) $m['actual'] ) . ' rows' ) . '; the source exported ' . number_format( (int) $m['expected'] ) . '.' );
            }
        } else {
            $this->say( '      · The source doesn\'t report row counts (an older Disembark, or another host\'s dump), so rows weren\'t compared.' );
        }
        $pages = (array) ( $v['pages'] ?? [] );
        if ( ! empty( $pages['note'] ) ) {
            $this->say( '      · ' . $pages['note'] );
        } else {
            $fine = 0;
            foreach ( (array) ( $pages['list'] ?? [] ) as $p ) {
                if ( ! empty( $p['problem'] ) ) {
                    $this->say( "      ✗ {$p['label']} ({$p['url']}): {$p['problem']}" );
                } else {
                    $fine++;
                }
                if ( ! empty( $p['old_address'] ) ) {
                    $this->say( "      ! {$p['label']} still mentions the old address " . self::plural( (int) $p['old_address'], 'time' ) . '.' );
                }
            }
            foreach ( (array) ( $pages['culprits'] ?? [] ) as $c ) {
                $this->say( '        PHP error' . ( $c['component'] !== '' ? " in {$c['component']}" : '' ) . ": {$c['message']}" );
            }
            if ( $fine ) {
                $this->say( '      ✓ ' . self::plural( $fine, 'page' ) . ' loaded fine.' );
            }
        }
        $left = $v['leftovers'] ?? null;
        if ( is_array( $left ) && ! empty( $left['rows'] ) ) {
            $tables = [];
            foreach ( (array) $left['tables'] as $table => $info ) {
                $tables[] = $table . ' (' . number_format( (int) $info['rows'] ) . ( $info['no_primary_key'] ? ', no primary key, so the rewrite skipped it' : '' ) . ')';
            }
            $this->say( '      ! The old address is still in ' . self::plural( (int) $left['rows'], 'row' ) . ': ' . implode( ', ', array_slice( $tables, 0, 6 ) ) . '.' );
            foreach ( array_slice( (array) $left['samples'], 0, 3 ) as $sample ) {
                $this->say( "        {$sample['table']}.{$sample['column']}" . ( $sample['key'] !== '' ? " ({$sample['key']})" : '' ) . ": {$sample['excerpt']}" );
            }
        } elseif ( is_array( $left ) && empty( $left['note'] ) ) {
            $this->say( '      ✓ The old address is gone from the database.' );
        }
        return [
            'ok'        => (bool) $v['ok'],
            'problems'  => (int) $v['problems'],
            'rows'      => $rows ? [ 'checked' => (int) $rows['checked'], 'mismatched' => $rows['mismatched'] ] : null,
            'pages'     => array_map( function ( $p ) { return array_intersect_key( $p, array_flip( [ 'label', 'url', 'status', 'ms', 'problem', 'old_address' ] ) ); }, (array) ( $pages['list'] ?? [] ) ),
            'culprits'  => $pages['culprits'] ?? [],
            'leftovers' => is_array( $left ) ? [ 'rows' => (int) $left['rows'], 'tables' => $left['tables'] ] : null,
        ];
    }

    /**
     * Checks a finished move: row counts against the source's, a few pages
     * loaded (home, login, the newest post of each type, an archive of each
     * taxonomy) for PHP errors and redirects, and the database searched for
     * the old address.
     *
     * ## OPTIONS
     *
     * [<id>]
     * : The move (its rollback id). Defaults to the latest.
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: text
     * options:
     *   - text
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     $ wp disembark verify
     *     $ wp disembark verify 62bcb2c4539c63e4d8c82629 --format=json
     */
    public function verify( $args, $assoc_args ) {
        $this->json = ( $assoc_args['format'] ?? 'text' ) === 'json';
        $id         = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) ( $args[0] ?? '' ) ) );
        if ( $id === '' ) {
            $id = Verify::latest();
            if ( $id === '' ) {
                $this->fail( 'No finished move to check on this site (rolled-back moves don\'t count).' );
            }
        }
        $this->say( "Checking move {$id}" );
        $v = $this->check( Verify::run( $id ), 'verify' );
        $summary = $this->report_verify( $v );
        if ( $this->json ) {
            \WP_CLI::line( wp_json_encode( $summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
            return;
        }
        if ( $v['ok'] ) {
            \WP_CLI::success( 'No problems found.' );
        } else {
            \WP_CLI::warning( 'Found ' . self::plural( (int) $v['problems'], 'problem' ) . '.' );
        }
    }

    /**
     * Runs one step of a move through Steps, so it's recorded: a resumed
     * move replays finished steps instead of running them again. Results
     * come back as plain arrays either way.
     */
    private function step( $work ) {
        if ( $this->steps_import === '' ) {
            $result = $work();
        } else {
            // A source that is busy or slow for a moment is asked again.
            list( $result ) = $this->run_step( substr( $this->steps_import, 0, 24 ) . sprintf( '%08x', ++$this->steps_seq ), $work );
        }
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        if ( $result instanceof \WP_REST_Response ) {
            $result = $result->get_data();
        }
        return json_decode( (string) wp_json_encode( $result ), true );
    }

    /* ------------------------------------------------------------------
     * Pace to the host (see Pace): the pull's batches sized to the source
     * as it goes, failed requests tried again, long steps in pieces.
     * ---------------------------------------------------------------- */

    /** files, bytes (per file batch), db (bytes per database batch), budget (seconds). */
    private $pace = [ 'files' => 400, 'bytes' => 50331648, 'db' => 16777216, 'budget' => 0 ];
    private $pace_busy  = 0;
    private $pace_pause = 0;
    /** True when the most recent run_step() replayed a cached result from Steps. */
    private $last_step_replayed = false;

    const PACE_LIMITS       = [ 'files' => [ 50, 5000 ], 'bytes' => [ 4194304, 536870912 ], 'db' => [ 1048576, 134217728 ] ];
    const MAX_RETRIES       = 20;
    const MAX_RETRY_SECONDS = 900;
    /** How long a step keeps trying after the source or this host fails (see retry_limits()); 0 is no limit. */
    private $max_retries       = self::MAX_RETRIES;
    private $max_retry_seconds = self::MAX_RETRY_SECONDS;
    /** The failures in a row so far, [ n, at ], or null after an answer (see run_step()). */
    private $fail_streak = null;

    /**
     * Runs one request of a paced step (request id: the step's place in
     * the move, then the request's within the step), again with the same
     * id after a failure the source or this host may get past. $on_fail($error, $n)
     * returns 'retry' or 'split'. Returns [ result, seconds, failures, replayed ],
     * with 'split' as the result when asked to split.
     */
    private function paced_step( $s, $k, $work, $on_fail = null ) {
        return $this->run_step( substr( $this->steps_import, 0, 24 ) . sprintf( '%08x%08x', $s, $k ), $work, $on_fail );
    }

    /** Runs one recorded step under $rid, again after a failure worth trying again (see paced_step()). */
    private function run_step( $rid, $work, $on_fail = null ) {
        for ( $n = 0; ; ) {
            $t0       = microtime( true );
            $replayed = $n === 0 && $this->steps_import !== '' && is_file( Storage::base_dir() . "/_import_{$this->steps_import}/steps/{$rid}.php" );
            // Outside a pull (a rollback, a search and replace), nothing is recorded to replay.
            $result = $this->steps_import === '' ? $work() : Steps::run( $this->steps_import, $rid, $work );
            if ( $result instanceof \WP_REST_Response ) {
                $result = $result->get_data();
            }
            if ( ! is_wp_error( $result ) ) {
                // An answer ends the run of failures the limits count.
                $this->fail_streak = null;
                $this->last_step_replayed = $replayed;
                if ( ! $replayed ) {
                    $this->pace_busy  = 0;
                    $this->pace_pause = 0;
                }
                return [ json_decode( (string) wp_json_encode( $result ), true ), microtime( true ) - $t0, $n, $replayed ];
            }
            $this->last_step_replayed = false;
            $status = (int) ( $result->get_error_data()['status'] ?? 0 );
            // Only what the source (or the network to it) may get past.
            if ( ! in_array( $status, [ 429, 502, 503, 504 ], true ) || in_array( $result->get_error_code(), [ 'cancelled', 'unsafe_url', 'invalid_url' ], true ) ) {
                return [ $result, 0, $n, false ];
            }
            $n++;
            // The limits count failures in a row across the whole move: a
            // batch that fails is split, and its pieces carry the count on.
            if ( $this->fail_streak === null ) {
                $this->fail_streak = [ 'n' => 0, 'at' => microtime( true ) ];
            }
            $this->fail_streak['n']++;
            if ( $this->retries_spent( $this->fail_streak['n'], $this->fail_streak['at'], $result ) ) {
                return [ $result, 0, $n, false ];
            }
            $this->pace_busy = in_array( $status, [ 429, 503 ], true ) ? $this->pace_busy + 1 : 0;
            if ( $this->pace_busy >= 5 ) {
                $secs = [ 30, 60, 120, 240, 300 ][ min( $this->pace_pause++, 4 ) ];
                $this->say( "      Slow host: it answered busy {$this->pace_busy} times in a row. Waiting {$secs}s and trying again." );
                sleep( $secs );
                $this->pace_busy = 0;
            }
            $what = $on_fail ? $on_fail( $result, $n ) : 'retry';
            if ( $what === 'split' ) {
                return [ 'split', 0, $n, false ];
            }
            if ( $what === 'stop' ) {
                return [ $result, 0, $n, false ];
            }
            if ( $this->retries_spent( $this->fail_streak['n'], $this->fail_streak['at'], $result, false ) ) {
                return [ $result, 0, $n, false ];
            }
            $wait = min( 30, pow( 2, $n - 1 ) ) * ( 0.7 + mt_rand( 0, 600 ) / 1000 );
            $this->say( '      ' . $result->get_error_message() . ' Trying again in ' . max( 1, (int) round( $wait ) ) . 's (attempt ' . ( $this->fail_streak['n'] + 1 ) . ( $this->max_retries > 0 ? ' of ' . ( $this->max_retries + 1 ) : '' ) . ').' );
            usleep( (int) ( $wait * 1000000 ) );
        }
    }

    /**
     * --max-retries and --max-retry-minutes: how many times, and for how
     * long, a step is tried again after the source or this host fails
     * (429, 502, 503, 504, a dropped connection). 0 lifts that limit, so
     * both 0 keep trying the way the dashboard does.
     */
    private function retry_limits( $assoc_args ) {
        foreach ( [ 'max-retries' => 'max_retries', 'max-retry-minutes' => 'max_retry_seconds' ] as $flag => $prop ) {
            if ( ! isset( $assoc_args[ $flag ] ) ) {
                continue;
            }
            // A bare --max-retries arrives as true, which isn't a number.
            $v = is_bool( $assoc_args[ $flag ] ) ? '' : trim( (string) $assoc_args[ $flag ] );
            if ( ! preg_match( '/^\d+$/', $v ) ) {
                $this->fail( "--{$flag} takes a whole number (0 for no limit), as in --{$flag}=5." );
            }
            $this->$prop = $flag === 'max-retry-minutes' ? (int) $v * 60 : (int) $v;
        }
    }

    /** Whether a failing step has used up its retries (see retry_limits()), saying so when it has. */
    private function retries_spent( $n, $started, $error, $count_tries = true ) {
        $secs  = microtime( true ) - $started;
        $tries = $count_tries && $this->max_retries > 0 && $n > $this->max_retries;
        $time  = $this->max_retry_seconds > 0 && $secs >= $this->max_retry_seconds;
        if ( ! $tries && ! $time ) {
            return false;
        }
        $took = $secs < 90 ? max( 1, (int) round( $secs ) ) . 's' : (int) round( $secs / 60 ) . ' min';
        $why  = rtrim( is_wp_error( $error ) ? $error->get_error_message() : 'it kept failing', '. ' );
        $this->say( '      Gave up after ' . ( (int) $n === 1 ? '1 try' : number_format( (int) $n ) . ' tries' ) . " in {$took} ({$why}). --max-retries and --max-retry-minutes set how long to keep trying; 0 keeps trying." );
        return true;
    }

    /** A step that works until its budget, asked again until done (see Pace). */
    private function budgeted( $label, $work ) {
        $s = ++$this->steps_seq;
        $k = 0;
        do {
            list( $r ) = $this->paced_step( $s, ++$k, function () use ( $work ) { return $work( $this->pace['budget'] > 0 ? $this->pace['budget'] : Pace::default_budget() ); }, function ( $e ) {
                $status = (int) ( $e->get_error_data()['status'] ?? 0 );
                if ( $status === 504 && $e->get_error_code() !== 'source_unreachable' ) {
                    $cur  = $this->pace['budget'] > 0 ? $this->pace['budget'] : Pace::default_budget();
                    $next = max( 5, floor( $cur / 2 ) );
                    if ( $next < $cur ) {
                        $this->pace['budget'] = $next;
                        $this->say( "      Asking for {$next}s of work per request from now on." );
                    }
                }
                return 'retry';
            } );
            if ( is_wp_error( $r ) ) {
                return $r;
            }
            if ( isset( $r['done'] ) && $r['done'] === false && $label ) {
                $this->say( '      ' . $label( $r ) );
            }
        } while ( isset( $r['done'] ) && $r['done'] === false );
        return $r;
    }

    /** The source cut the request off or didn't answer in time: too much asked at once (a busy answer is luck). */
    private static function size_error( $e ) {
        $data = (array) $e->get_error_data();
        $st   = (int) ( $data['source_status'] ?? 0 );
        return $e->get_error_code() === 'source_unreachable' || ( $e->get_error_code() === 'source_error' && ( in_array( $st, [ 500, 502, 504 ], true ) || $st >= 520 ) );
    }

    private function adapt( $key, $secs, $failures ) {
        if ( $failures ) {
            return;
        }
        $lim = self::PACE_LIMITS[ $key ];
        $f   = $secs < 4 ? 1.5 : ( $secs < 8 ? 1.25 : ( $secs > 18 ? 0.5 : ( $secs > 12 ? 0.8 : 1 ) ) );
        $this->pace[ $key ] = (int) max( $lim[0], min( $lim[1], round( $this->pace[ $key ] * $f ) ) );
    }

    private function halve( $key ) {
        $lim = self::PACE_LIMITS[ $key ];
        $this->pace[ $key ] = (int) max( $lim[0], round( $this->pace[ $key ] / 2 ) );
    }

    /** The pull's files, in batches sized to the source (see Pull::fetch_files_paced()). */
    private function pull_files_paced( $pull ) {
        $s      = ++$this->steps_seq;
        $k      = 0;
        $done   = [];
        $total  = null;
        $forced = [];
        $shown  = 0;
        while ( true ) {
            $task = null;
            while ( $forced && ! $task ) {
                $f = array_shift( $forced );
                if ( ! Pace::ranges_cover( $done, $f['offset'], $f['offset'] + $f['count'] ) ) {
                    $task = $f;
                }
            }
            if ( ! $task ) {
                if ( $total === null ) {
                    $task = [ 'offset' => 0, 'count' => $this->pace['files'] ];
                } else {
                    $gap = Pace::ranges_gap( $done, $total );
                    if ( ! $gap ) {
                        break;
                    }
                    $task = [ 'offset' => $gap[0], 'count' => min( $gap[1] - $gap[0], $this->pace['files'] ) ];
                }
            }
            if ( ! empty( $task['range'] ) ) {
                list( $r ) = $this->paced_step( $s, ++$k, function () use ( $pull, $task ) {
                    return $pull->fetch_file_part( $task['offset'], $this->pace['budget'] > 0 ? $this->pace['budget'] : Pace::default_budget() );
                } );
                if ( is_wp_error( $r ) && $r->get_error_code() === 'no_ranges' ) {
                    $forced = array_merge( [ [ 'offset' => $task['offset'], 'count' => 1, 'whole' => true ] ], $forced );
                    continue;
                }
                if ( ! is_wp_error( $r ) && empty( $r['file_done'] ) ) {
                    $forced = array_merge( [ $task ], $forced );
                    $this->say( '      A large file in pieces: ' . size_format( (int) ( $r['got'] ?? 0 ) ) . ' of ' . size_format( (int) ( $r['size'] ?? 0 ) ) );
                }
            } else {
                $bytes = (int) ( $task['bytes'] ?? $this->pace['bytes'] );
                $cut   = false;
                list( $r, $secs, $failures ) = $this->paced_step( $s, ++$k, function () use ( $pull, $task, $bytes ) {
                    return $pull->fetch_files_paced( $task['offset'], $task['count'], $bytes, $this->pace['budget'] > 0 ? $this->pace['budget'] : Pace::default_budget() );
                }, function ( $e, $n ) use ( $task, &$cut ) {
                    $cut = $cut || self::size_error( $e );
                    // One file the source keeps refusing (a firewall, a file
                    // it can't read) stops the pull, resumable, instead of
                    // being asked for again forever.
                    if ( ! empty( $task['whole'] ) ) {
                        return $n >= 6 ? 'stop' : 'retry';
                    }
                    return $n >= 2 ? 'split' : 'retry';
                } );
                if ( is_wp_error( $r ) && ! empty( $task['whole'] ) ) {
                    $name = $pull->file_name( $task['offset'] );
                    $this->fail( 'The source kept refusing file ' . number_format( $task['offset'] + 1 ) . ( $name !== '' ? " ({$name})" : '' ) . ': ' . $r->get_error_message() . ( $name !== '' ? " Resume to try again, or start over with --skip={$name} to leave it behind." : ' Resume to try again.' ), 'files' );
                }
                if ( $r === 'split' ) {
                    if ( $task['count'] > 1 ) {
                        $half = (int) ceil( $task['count'] / 2 );
                        $this->say( '      A batch of ' . self::plural( (int) $task['count'], 'file' ) . ' failed twice. Splitting it in two.' );
                        if ( $cut ) {
                            $this->halve( 'files' );
                            $this->halve( 'bytes' );
                        }
                        $forced = array_merge( [ [ 'offset' => $task['offset'], 'count' => $half, 'bytes' => (int) ( $bytes / 2 ) ], [ 'offset' => $task['offset'] + $half, 'count' => $task['count'] - $half, 'bytes' => (int) ( $bytes / 2 ) ] ], $forced );
                    } else {
                        $this->say( '      File ' . number_format( $task['offset'] + 1 ) . ' failed twice in a batch. Fetching it in pieces.' );
                        $forced = array_merge( [ [ 'offset' => $task['offset'], 'count' => 1, 'range' => true ] ], $forced );
                    }
                    continue;
                }
                if ( ! is_wp_error( $r ) && ! $this->last_step_replayed ) {
                    $this->adapt( 'files', $secs, $failures );
                    $this->adapt( 'bytes', $secs, $failures );
                }
            }
            $r = $this->check( $r, 'files' );
            $total = (int) $r['total'];
            foreach ( (array) ( $r['ranges'] ?? [] ) as $range ) {
                $done = Pace::ranges_add( $done, $range[0], $range[1] );
            }
            $have = min( $total, Pace::ranges_count( $done ) );
            if ( $have !== $shown ) {
                $shown = $have;
                $this->say( '      Copied ' . number_format( $have ) . ' of ' . number_format( $total ) . ' files (' . number_format( $this->pace['files'] ) . ' a batch)' );
            }
            if ( ! $this->last_step_replayed ) {
                Pace::remember( $this->steps_import, $this->pace );
            }
        }
        return [ 'fetched' => $total, 'total' => $total ];
    }

    /** The pull's database, a batch per request (see Pull::fetch_database_paced()). */
    private function pull_database_paced( $pull, $skip_unchanged = false ) {
        $s = ++$this->steps_seq;
        $k = 0;
        do {
            $cut = false;
            list( $r, $secs, $failures ) = $this->paced_step( $s, ++$k, function () use ( $pull, $skip_unchanged ) {
                return $pull->fetch_database_paced( $this->pace['budget'] > 0 ? $this->pace['budget'] : Pace::default_budget(), $this->pace['db'], true, $skip_unchanged );
            }, function ( $e, $n ) use ( &$cut ) {
                $cut = $cut || self::size_error( $e );
                if ( $n >= 2 && $cut ) {
                    $this->halve( 'db' );
                    $this->say( '      A database batch failed twice. Smaller ones from now on (' . size_format( $this->pace['db'] ) . ').' );
                }
                return 'retry';
            } );
            $r = $this->check( $r, 'database' );
            if ( ! $this->last_step_replayed ) {
                $this->adapt( 'db', $secs, $failures );
                Pace::remember( $this->steps_import, $this->pace );
            }
            if ( isset( $r['done'] ) && $r['done'] === false && ( $r['phase'] ?? '' ) === 'compare' ) {
                $this->say( '      Comparing tables with the last pull (' . $r['side'] . '): ' . (int) $r['compared'] . ' of ' . (int) $r['total'] );
            } elseif ( isset( $r['done'] ) && $r['done'] === false ) {
                $this->say( '      Copied ' . (int) $r['tables_done'] . ' of ' . (int) $r['total_tables'] . ' tables (' . size_format( (int) $r['bytes'] ) . ')' );
            }
        } while ( isset( $r['done'] ) && $r['done'] === false );
        return $r;
    }

    private static function plural( $n, $word ) {
        return number_format( $n ) . ' ' . $word . ( $n === 1 ? '' : 's' );
    }

    private function find_unfinished( $id ) {
        $list = Pull::unfinished();
        if ( $id === 'latest' ) {
            $list = array_values( array_filter( $list, function ( $m ) { return ( $m['move']['client'] ?? '' ) === 'cli'; } ) );
            if ( $list ) {
                return $list[0];
            }
        }
        foreach ( $list as $m ) {
            if ( $m['id'] === $id ) {
                return $m;
            }
        }
        $this->fail( "No unfinished move {$id}. List them with: wp disembark unfinished" );
    }

    /**
     * Lists moves that stopped before finishing.
     *
     * A pull or push that stopped (a dropped SSH session, a closed tab, an
     * error) keeps its progress. Resume one started here with
     * `wp disembark pull --resume=<id>`, one started in the dashboard from
     * Tools → Disembark → Pull, or throw one away with `wp disembark discard <id>`.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     * ---
     */
    public function unfinished( $args, $assoc_args ) {
        $list = Pull::unfinished();
        if ( ( $assoc_args['format'] ?? 'table' ) === 'json' ) {
            \WP_CLI::line( wp_json_encode( $list, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) );
            return;
        }
        if ( ! $list ) {
            \WP_CLI::log( 'No unfinished moves.' );
            return;
        }
        $rows = array_map( function ( $m ) {
            $where = $m['rollback_point'] ? 'after rollback point (site may be partly changed)' : ( $m['database'] ? 'files and database copied' : "{$m['files_fetched']}/{$m['files_total']} files" );
            $cli   = ( $m['move']['client'] ?? '' ) === 'cli';
            return [
                'id'      => $m['id'],
                'source'  => $m['source'],
                'started' => $m['started'] ? wp_date( 'Y-m-d H:i', $m['started'] ) : '',
                'stopped' => $where,
                'resume'  => $cli ? "wp disembark pull --resume={$m['id']}" : 'dashboard: Tools → Disembark → Pull',
            ];
        }, $list );
        \WP_CLI\Utils\format_items( 'table', $rows, [ 'id', 'source', 'started', 'stopped', 'resume' ] );
    }

    /**
     * Throws away an unfinished move: rolls the site back first if it got
     * past its rollback point, then deletes what it copied.
     *
     * ## OPTIONS
     *
     * <id>
     * : The move's id, from `wp disembark unfinished`.
     *
     * [--max-retries=<n>]
     * : How many times a step is tried again after the source or this host
     * fails (429, 502, 503, 504, a dropped connection). Default 20; 0 for no
     * limit.
     *
     * [--max-retry-minutes=<n>]
     * : How long a step keeps trying after such a failure. Default 15; 0 for
     * no limit. With both 0 it keeps trying, as the dashboard does.
     *
     * [--yes]
     * : Don't ask for confirmation.
     */
    public function discard( $args, $assoc_args ) {
        $this->retry_limits( $assoc_args );
        $move = $this->find_unfinished( (string) ( $args[0] ?? '' ) );
        $this->confirm( $assoc_args, $move['rollback_point'] ? 'Roll this site back to before the move, then delete what it copied and its rollback point?' : 'Delete what this move copied so far? Nothing on this site has changed.' );
        $import      = new Import( $move['id'] );
        $rolled_back = false;
        if ( $move['rollback_point'] ) {
            $r = $this->budgeted( function () { return 'Rolling back'; }, function ( $budget ) use ( $import ) { return $import->rollback( $budget ); } );
            if ( is_wp_error( $r ) || ! empty( $r['errors'] ) ) {
                \WP_CLI::error( 'The rollback reported errors; the rollback point is kept. Try: wp disembark rollback ' . $move['id'] );
            }
            $rolled_back = true;
        }
        $import->cancel( 'cleanup', $rolled_back );
        \WP_CLI::success( $rolled_back ? 'Rolled back and cleaned up.' : 'Discarded.' );
    }

    private function check( $result, $step, $rollback_id = '' ) {
        if ( is_wp_error( $result ) ) {
            $this->fail( $result->get_error_message(), $step, $rollback_id );
        }
        return is_object( $result ) ? (array) $result : $result;
    }

    private function fail( $message, $step = '', $rollback_id = '' ) {
        if ( $this->notify ) {
            Notify::send( $this->notify['import'], $message === 'Cancelled.' ? 'cancelled' : 'failed', [
                'kind'        => $this->notify['kind'],
                'source'      => $this->notify['source'],
                'destination' => $this->notify['destination'],
                'step'        => $step,
                'error'       => $message,
                'rollback_id' => $rollback_id,
                'resume'      => $this->resume_hint !== '' ? "wp disembark pull --resume={$this->resume_hint}" : '',
                'seconds'     => time() - (int) $this->notify['started'],
            ] );
            $this->notify = [];
        }
        if ( $rollback_id !== '' ) {
            $message .= " This site may be partly changed. Undo with: wp disembark rollback {$rollback_id}";
        }
        if ( $this->resume_hint !== '' ) {
            $message .= " Resume with: wp disembark pull --resume={$this->resume_hint}";
        }
        if ( $this->json ) {
            \WP_CLI::line( wp_json_encode( array_filter( [
                'ok'          => false,
                'step'        => $step,
                'error'       => $message,
                'rollback_id' => $rollback_id,
                'undo'        => $rollback_id !== '' ? "wp disembark rollback {$rollback_id}" : '',
                'resume'      => $this->resume_hint !== '' ? "wp disembark pull --resume={$this->resume_hint}" : '',
            ], function ( $value ) { return $value !== ''; } ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
        }
        \WP_CLI::error( $message );
    }
}
