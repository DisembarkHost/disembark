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
            $token = wp_generate_password( 42, false );
            Token::set( $token );
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
     * [--yes]
     * : Don't ask for confirmation.
     *
     * [--bring-host-files]
     * : Also deploy the source's host files (mu-plugins and cache drop-ins).
     *
     * [--catch-up]
     * : Copy only files that are new or changed since this site's last pull
     * from the same source (files removed there are removed here; the
     * database is copied in full). Falls back to everything when there was
     * no earlier pull.
     *
     * [--resume=<id>]
     * : Resume a pull that stopped (see `wp disembark unfinished`), or
     * `latest`. Finished steps are replayed; it carries on from where it
     * stopped with the options it started with.
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
     */
    public function pull( $args, $assoc_args ) {
        $this->json = ( $assoc_args['format'] ?? 'text' ) === 'json';
        @set_time_limit( 0 );

        $resuming = ! empty( $assoc_args['resume'] );
        if ( $resuming ) {
            $move = $this->find_unfinished( (string) $assoc_args['resume'] );
            if ( ( $move['move']['client'] ?? '' ) !== 'cli' ) {
                $this->fail( 'That move was started in the dashboard. Resume it from Tools → Disembark → Pull, or discard it with: wp disembark discard ' . $move['id'] );
            }
            $import_id  = $move['id'];
            // The same options as the first run, so its steps line up.
            $assoc_args = array_merge( (array) ( $move['move']['args'] ?? [] ), [ 'yes' => true, 'format' => $assoc_args['format'] ?? 'text' ] );
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
        $summary = [ 'ok' => false, 'source' => $source['url'], 'import_id' => $import_id ];

        $this->say( '[1/8] Checking this site' );
        if ( ! $resuming ) {
            $pre = $import->preflight();
            $this->confirm( $assoc_args, 'Replace ' . untrailingslashit( (string) $pre['home_url'] ) . " with {$source['url']}? A rollback point is saved first, and afterwards you log in with the source site's credentials." );
        } else {
            $this->say( "      Resuming {$import_id} (a pull from {$source['url']}): finished steps are replayed." );
        }
        // From here every step is recorded: a pull that stops can be resumed.
        $this->steps_import = $import_id;
        $this->resume_hint  = $import_id;
        $pre         = $this->check( $this->step( function () use ( $import ) { return $import->preflight(); } ), 'preflight' );
        $dest_prefix = (string) ( $pre['db_prefix'] ?? '' );
        $dest_home   = untrailingslashit( (string) ( $pre['home_url'] ?? home_url() ) );
        $summary['destination'] = $dest_home;

        $this->say( '[2/8] Bringing the site over' );
        $move_args = array_intersect_key( $assoc_args, array_flip( [ 'skip', 'skip-rows', 'template', 'bring-everything', 'skip-quiet-mode', 'bring-host-files', 'catch-up' ] ) );
        $c = $this->check( $this->step( function () use ( $pull, $source, $move_args ) {
            return $pull->connect( $source['url'], $source['token'], [ 'client' => 'cli', 'kind' => 'pull', 'args' => $move_args ], ! empty( $move_args['catch-up'] ) );
        } ), 'connect' );
        $src_home = untrailingslashit( (string) ( $c['source_home'] ?? '' ) );
        $this->say( '      Connected to ' . ( $src_home ?: $source['url'] ) . ' (' . (int) ( $c['table_count'] ?? 0 ) . ' tables).' );
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
            $this->say( '      Catching up: ' . number_format( (int) $cu['changed'] ) . ' files new or changed since the last pull (' . wp_date( 'Y-m-d H:i', (int) $cu['since'] ) . '), ' . number_format( (int) $cu['removed'] ) . ' removed on the source, ' . number_format( (int) $cu['unchanged'] ) . ' unchanged.' . ( ! empty( $cu['approx'] ) ? ' (The source sends no file times, so files were compared by size.)' : '' ) );
        } elseif ( isset( $fin['catch_up'] ) ) {
            $this->say( '      Nothing is remembered from an earlier pull of this source, so everything comes across.' );
        }
        $this->choose( $pull, $assoc_args, $summary );
        $fetched = 0;
        do {
            $r       = $this->check( $this->step( function () use ( $pull, $fetched ) { return $pull->fetch_files( $fetched, 1000 ); } ), 'files' );
            $fetched = (int) $r['fetched'];
            $this->say( '      Copied ' . number_format( $fetched ) . ' of ' . number_format( (int) $r['total'] ) . ' files' );
        } while ( empty( $r['done'] ) );
        $this->say( '      Copying the database' );
        $db = $this->check( $this->step( function () use ( $pull ) { return $pull->fetch_database(); } ), 'database' );
        $this->say( '      Database copied (' . (int) ( $db['tables'] ?? 0 ) . ' tables, ' . size_format( (int) ( $db['bytes'] ?? 0 ) ) . ').' );

        $this->say( '[3/8] Reading the backup' );
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

        $this->say( '[4/8] Saving a rollback point' );
        $label = ( ! empty( $summary['catch_up'] ) ? 'Before catch-up from ' : 'Before pull from ' ) . wp_parse_url( $source['url'], PHP_URL_HOST );
        $this->check( $this->step( function () use ( $import, $label ) { return $import->snapshot( $label ); } ), 'snapshot' );
        $summary['rollback_id'] = $import_id;
        $summary['undo']        = "wp disembark rollback {$import_id}";
        $this->say( "      Saved. Undo any time with: wp disembark rollback {$import_id}" );

        // Quiet mode goes on before the files and database arrive, so no
        // request in between can run the source's jobs or send its emails.
        $quiet = empty( $assoc_args['skip-quiet-mode'] ) && $src_home !== '' && Quiet::address( $src_home ) !== Quiet::address( $dest_home );
        if ( $quiet ) {
            $this->check( $this->step( function () use ( $src_home, $import_id ) { return Quiet::enable( $src_home, $import_id ); } ), 'quiet-mode', $import_id );
            $summary['quiet_mode'] = [ 'on' => true, 'off' => 'wp disembark quiet-mode off' ];
            $this->say( '      Quiet mode on: emails held, scheduled jobs paused, checkout closed, payment and marketing APIs blocked.' );
        }

        $this->say( '[5/8] Putting files in place' );
        $keep = empty( $assoc_args['bring-host-files'] );
        $dep  = $this->check( $this->step( function () use ( $import, $keep ) { return $import->deploy_files( [ '*' ], $keep ); } ), 'files', $import_id );
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
        if ( ! empty( $dep['host_files_kept'] ) ) {
            $this->say( '      Left out (host files; this site keeps its own): ' . implode( ', ', $dep['host_files_kept'] ) );
        }
        if ( ! empty( $dep['errors'] ) ) {
            $this->fail( count( $dep['errors'] ) . ' files could not be put in place (first: ' . $dep['errors'][0] . '). Stopped before the database import.', 'files', $import_id );
        }

        if ( ! empty( $info['sql_file'] ) ) {
            $this->say( '[6/8] Importing the database' );
            $sql_file = $info['sql_file'];
            $sq = $this->check( $this->step( function () use ( $import, $sql_file, $src_prefix, $dest_prefix ) { return $import->import_staged_sql( $sql_file, $src_prefix, $dest_prefix ); } ), 'database', $import_id );
            $summary['statements'] = (int) $sq['statements_executed'];
            if ( ! empty( $sq['errors'] ) ) {
                $this->fail( 'The database import reported ' . count( $sq['errors'] ) . ' errors (first: ' . $sq['errors'][0] . '). Stopped before rewriting URLs.', 'database', $import_id );
            }
            $this->say( '      ' . number_format( (int) $sq['statements_executed'] ) . ' statements executed.' );
            if ( $src_prefix !== '' && $dest_prefix !== '' && $src_prefix !== $dest_prefix ) {
                $rp = $this->check( $this->step( function () use ( $import, $src_prefix, $dest_prefix ) { return $import->remap_table_prefix( $src_prefix, $dest_prefix ); } ), 'prefix', $import_id );
                $summary['prefix'] = [ 'from' => $src_prefix, 'to' => $dest_prefix ];
                $this->say( "      Table prefix remapped {$src_prefix} → {$dest_prefix} (" . (int) ( $rp['keys_changed'] ?? 0 ) . ' keys).' );
            }

            $this->say( '[7/8] Rewriting URLs' );
            if ( $src_home !== '' && $src_home !== $dest_home ) {
                $sr = $this->check( $this->step( function () use ( $import, $src_home, $dest_home ) {
                    $result = $import->search_replace( $src_home, $dest_home );
                    return is_wp_error( $result ) ? $result : array_merge( $result, $import->rewrite_files( Import::replacement_pairs( $src_home, $dest_home ) ) );
                } ), 'urls', $import_id );
                $summary['urls'] = [
                    'from'            => $src_home,
                    'to'              => $dest_home,
                    'cells_changed'   => (int) ( $sr['cells_changed'] ?? 0 ),
                    'files_rewritten' => (int) ( $sr['files_rewritten'] ?? 0 ),
                ];
                $this->say( "      {$src_home} → {$dest_home}: " . number_format( (int) ( $sr['cells_changed'] ?? 0 ) ) . ' cells, ' . (int) ( $sr['files_rewritten'] ?? 0 ) . ' files under uploads.' );
                if ( ! empty( $sr['files_mentioning_total'] ) ) {
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
            $this->say( '[6/8] No database in the backup' );
        }

        $this->say( '[8/8] Finishing up' );
        $this->check( $this->step( function () use ( $import ) { return $import->finalize(); } ), 'finalize', $import_id );
        $this->resume_hint = '';

        $summary['ok']   = true;
        $summary['note'] = "User accounts are now the source's: log in with the source site's credentials.";
        if ( $this->json ) {
            \WP_CLI::line( wp_json_encode( $summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
            return;
        }
        \WP_CLI::success( "Pulled {$source['url']} into {$dest_home}. Log in with the source site's credentials." );
        \WP_CLI::log( "Undo: wp disembark rollback {$import_id}" );
        if ( $quiet ) {
            \WP_CLI::log( 'Quiet mode is on (this is a copy). Lift it with: wp disembark quiet-mode off' );
        }
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
                'rolled_back' => $p['rolled_back_at'] ? wp_date( 'Y-m-d H:i', $p['rolled_back_at'] ) : '',
                'size'        => size_format( $p['size'] ),
            ];
        }, $points );
        \WP_CLI\Utils\format_items( 'table', $rows, [ 'id', 'label', 'created', 'rolled_back', 'size' ] );
    }

    /**
     * Puts this site back as it was before a restore, push or search & replace.
     *
     * The database is replaced with the rollback point's snapshot, and files a
     * restore changed are put back. Anything changed since is lost.
     *
     * ## OPTIONS
     *
     * <id>
     * : A rollback point id from `wp disembark restore-points`, or "latest".
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
        $point      = $this->find_point( $args[0] );
        $this->confirm( $assoc_args, 'Roll ' . home_url() . " back to \"{$point['label']}\"? Anything changed since is lost." );
        @set_time_limit( 0 );
        $this->say( 'Rolling back…' );
        $r = $this->check( ( new Import( $point['id'] ) )->rollback(), 'rollback' );
        if ( $this->json ) {
            \WP_CLI::line( wp_json_encode( array_merge( [ 'ok' => true, 'id' => $point['id'] ], (array) $r ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
            return;
        }
        if ( ! empty( $r['errors'] ) ) {
            \WP_CLI::warning( count( $r['errors'] ) . ' problems: ' . implode( '; ', array_slice( $r['errors'], 0, 3 ) ) );
        }
        $relogin = preg_match( '/^Before (restore|pull|push)/i', $point['label'] ) || ! empty( $point['deployed'] );
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
     * : Only these tables (comma-separated).
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
        list( $from, $to ) = $args;
        if ( $from === '' ) {
            $this->fail( 'Enter the text to search for.' );
        }
        $tables = array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $assoc_args['tables'] ?? '' ) ) ) ) );
        $dry    = ! empty( $assoc_args['dry-run'] );
        @set_time_limit( 0 );
        $out = [];
        if ( ! $dry ) {
            $this->confirm( $assoc_args, "Replace \"{$from}\" with \"{$to}\" across " . ( $tables ? implode( ', ', $tables ) : 'every table' ) . '? A rollback point is saved first.' );
            $id = substr( bin2hex( random_bytes( 12 ) ), 0, 24 );
            $this->say( 'Saving a rollback point…' );
            $this->check( ( new Import( $id ) )->snapshot( "Before search & replace: {$from} → " . ( $to === '' ? '(nothing)' : $to ) ), 'snapshot' );
            $out['rollback_id'] = $id;
            $out['undo']        = "wp disembark rollback {$id}";
        }
        $r   = $this->check( ( new Import() )->search_replace( $from, $to, $tables, $dry ), 'search-replace', $out['rollback_id'] ?? '' );
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
        \WP_CLI::success( number_format( (int) $r['cells_changed'] ) . ' cells changed in ' . number_format( (int) $r['rows_changed'] ) . " rows. Undo: wp disembark rollback {$out['rollback_id']}" );
    }

    // ------------------------------------------------------------------ helpers

    /** Parses "<url> <token>" or "disembark connect <url> <token>". */
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
        $sel    = $this->check( $this->step( function () use ( $pull, $choice ) { return $pull->select( $choice['skip_paths'], $choice['skip_names'], $choice['skip_rows'] ); } ), 'scan' );

        $this->say( '      ' . self::plural( (int) $sel['files'], 'file' ) . ' to copy (' . size_format( (int) $sel['bytes'] ) . ').' );
        $left = $sel['left_out'];
        if ( $left['files'] || $left['rows'] ) {
            $this->say( '      Leaving behind ' . self::plural( (int) $left['files'], 'file' ) . ' (' . size_format( (int) $left['bytes'] ) . ')' . ( $left['rows'] ? ' and the rows of ' . self::plural( count( $left['rows'] ), 'table' ) : '' ) . ':' );
            foreach ( $contents['suggestions'] as $s ) {
                if ( $ids === null ? $s['default'] : in_array( $s['id'], (array) $ids, true ) ) {
                    $this->say( '        ' . $s['label'] . ' (' . size_format( $s['bytes'] + $s['table_bytes'] ) . ')' );
                }
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
                if ( empty( $p['rolled_back_at'] ) ) {
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

    /**
     * Runs one step of a move through Steps, so it's recorded: a resumed
     * move replays finished steps instead of running them again. Results
     * come back as plain arrays either way.
     */
    private function step( $work ) {
        if ( $this->steps_import === '' ) {
            $result = $work();
        } else {
            $rid    = substr( $this->steps_import, 0, 24 ) . sprintf( '%08x', ++$this->steps_seq );
            $result = Steps::run( $this->steps_import, $rid, $work );
        }
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        if ( $result instanceof \WP_REST_Response ) {
            $result = $result->get_data();
        }
        return json_decode( (string) wp_json_encode( $result ), true );
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
     * [--yes]
     * : Don't ask for confirmation.
     */
    public function discard( $args, $assoc_args ) {
        $move = $this->find_unfinished( (string) ( $args[0] ?? '' ) );
        $this->confirm( $assoc_args, $move['rollback_point'] ? 'Roll this site back to before the move, then delete what it copied and its rollback point?' : 'Delete what this move copied so far? Nothing on this site has changed.' );
        $import      = new Import( $move['id'] );
        $rolled_back = false;
        if ( $move['rollback_point'] ) {
            $r = $import->rollback();
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
            ] ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
        }
        \WP_CLI::error( $message );
    }
}
