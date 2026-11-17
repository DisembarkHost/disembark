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
     * Runs the same steps as Tools → Disembark → Restore → From a live site:
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
     * <source>...
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
     */
    public function pull( $args, $assoc_args ) {
        $this->json = ( $assoc_args['format'] ?? 'text' ) === 'json';
        $source     = self::parse_connect( $args );
        if ( ! $source ) {
            $this->fail( 'Pass the source URL and token, or its connect line: wp disembark pull https://old-site.com <token>' );
        }
        @set_time_limit( 0 );

        $import_id = substr( bin2hex( random_bytes( 12 ) ), 0, 24 );
        $import    = new Import( $import_id );
        $pull      = new Pull( $import_id );
        $summary   = [ 'ok' => false, 'source' => $source['url'] ];

        $this->say( '[1/8] Checking this site' );
        $pre         = $import->preflight();
        $dest_prefix = (string) ( $pre['db_prefix'] ?? '' );
        $dest_home   = untrailingslashit( (string) ( $pre['home_url'] ?? home_url() ) );
        $summary['destination'] = $dest_home;

        $this->confirm( $assoc_args, "Replace {$dest_home} with {$source['url']}? A rollback point is saved first, and afterwards you log in with the source site's credentials." );

        $this->say( '[2/8] Bringing the site over' );
        $c = $this->check( $pull->connect( $source['url'], $source['token'] ), 'connect' );
        $src_home = untrailingslashit( (string) ( $c['source_home'] ?? '' ) );
        $this->say( '      Connected to ' . ( $src_home ?: $source['url'] ) . ' (' . (int) ( $c['table_count'] ?? 0 ) . ' tables).' );
        $this->check( $pull->scan_step( 'initiate' ), 'scan' );
        for ( $i = 0; $i < 100000; $i++ ) {
            $s = $this->check( $pull->scan_step( 'scan' ), 'scan' );
            if ( ( $s['status'] ?? '' ) === 'scan_complete' ) {
                break;
            }
        }
        $chunks = $this->check( $pull->scan_step( 'chunkify' ), 'scan' );
        for ( $n = 1; $n <= (int) ( $chunks['total_chunks'] ?? 0 ); $n++ ) {
            $this->check( $pull->scan_step( 'process_chunk', $n ), 'scan' );
        }
        $fin = $this->check( $pull->scan_step( 'finalize' ), 'scan' );
        $this->say( '      ' . number_format( (int) ( $fin['total_files'] ?? 0 ) ) . ' files to copy.' );
        $fetched = 0;
        do {
            $r       = $this->check( $pull->fetch_files( $fetched, 1000 ), 'files' );
            $fetched = (int) $r['fetched'];
            $this->say( '      Copied ' . number_format( $fetched ) . ' of ' . number_format( (int) $r['total'] ) . ' files' );
        } while ( empty( $r['done'] ) );
        $this->say( '      Copying the database' );
        $db = $this->check( $pull->fetch_database(), 'database' );
        $this->say( '      Database copied (' . (int) ( $db['tables'] ?? 0 ) . ' tables, ' . size_format( (int) ( $db['bytes'] ?? 0 ) ) . ').' );

        $this->say( '[3/8] Reading the backup' );
        $info       = $import->staged_info();
        $src_prefix = (string) ( $info['source']->db_prefix ?? '' );
        if ( $src_home === '' ) {
            $src_home = untrailingslashit( (string) ( $info['source']->home_url ?? '' ) );
        }
        if ( ! empty( $info['multisite'] ) ) {
            $summary['multisite_constants'] = $info['multisite'];
            $this->say( '      The source is a multisite network. This site keeps its own wp-config.php, so add these constants to it:' );
            foreach ( $info['multisite'] as $line ) {
                $this->say( '        ' . $line );
            }
        }

        $this->say( '[4/8] Saving a rollback point' );
        $this->check( $import->snapshot( 'Before pull from ' . wp_parse_url( $source['url'], PHP_URL_HOST ) ), 'snapshot' );
        $summary['rollback_id'] = $import_id;
        $summary['undo']        = "wp disembark rollback {$import_id}";
        $this->say( "      Saved. Undo any time with: wp disembark rollback {$import_id}" );

        $this->say( '[5/8] Putting files in place' );
        $dep = $this->check( $import->deploy_files( [ '*' ], empty( $assoc_args['bring-host-files'] ) ), 'files', $import_id );
        $summary['files_deployed']  = (int) $dep['deployed'];
        $summary['host_files_kept'] = $dep['host_files_kept'];
        $this->say( '      Placed ' . number_format( (int) $dep['deployed'] ) . ' files.' );
        if ( ! empty( $dep['host_files_kept'] ) ) {
            $this->say( '      Left out (host files; this site keeps its own): ' . implode( ', ', $dep['host_files_kept'] ) );
        }
        if ( ! empty( $dep['errors'] ) ) {
            $this->fail( count( $dep['errors'] ) . ' files could not be put in place (first: ' . $dep['errors'][0] . '). Stopped before the database import.', 'files', $import_id );
        }

        if ( ! empty( $info['sql_file'] ) ) {
            $this->say( '[6/8] Importing the database' );
            $sq = $this->check( $import->import_staged_sql( $info['sql_file'], $src_prefix, $dest_prefix ), 'database', $import_id );
            $summary['statements'] = (int) $sq['statements_executed'];
            if ( ! empty( $sq['errors'] ) ) {
                $this->fail( 'The database import reported ' . count( $sq['errors'] ) . ' errors (first: ' . $sq['errors'][0] . '). Stopped before rewriting URLs.', 'database', $import_id );
            }
            $this->say( '      ' . number_format( (int) $sq['statements_executed'] ) . ' statements executed.' );
            if ( $src_prefix !== '' && $dest_prefix !== '' && $src_prefix !== $dest_prefix ) {
                $rp = $this->check( $import->remap_table_prefix( $src_prefix, $dest_prefix ), 'prefix', $import_id );
                $summary['prefix'] = [ 'from' => $src_prefix, 'to' => $dest_prefix ];
                $this->say( "      Table prefix remapped {$src_prefix} → {$dest_prefix} (" . (int) ( $rp['keys_changed'] ?? 0 ) . ' keys).' );
            }

            $this->say( '[7/8] Rewriting URLs' );
            if ( $src_home !== '' && $src_home !== $dest_home ) {
                $sr = $this->check( $import->search_replace( $src_home, $dest_home ), 'urls', $import_id );
                $sr = array_merge( $sr, $import->rewrite_files( Import::replacement_pairs( $src_home, $dest_home ) ) );
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
        $this->check( $import->finalize(), 'finalize', $import_id );

        $summary['ok']   = true;
        $summary['note'] = "User accounts are now the source's: log in with the source site's credentials.";
        if ( $this->json ) {
            \WP_CLI::line( wp_json_encode( $summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
            return;
        }
        \WP_CLI::success( "Pulled {$source['url']} into {$dest_home}. Log in with the source site's credentials." );
        \WP_CLI::log( "Undo: wp disembark rollback {$import_id}" );
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
        if ( $this->json ) {
            \WP_CLI::line( wp_json_encode( array_filter( [
                'ok'          => false,
                'step'        => $step,
                'error'       => $message,
                'rollback_id' => $rollback_id,
                'undo'        => $rollback_id !== '' ? "wp disembark rollback {$rollback_id}" : '',
            ] ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
        }
        \WP_CLI::error( $message );
    }
}
