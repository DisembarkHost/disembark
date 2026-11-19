<?php

namespace Disembark;

/**
 * Checks after a move: did it actually work? Three questions:
 *
 * - Rows: does every table hold as many rows as the source exported? Counted
 *   right after the import swapped the tables in (Import::check_rows()).
 * - Pages: do the home page, the login page, the newest post of each public
 *   post type and an archive of each taxonomy load, without a PHP error,
 *   without redirecting off the site, and without the old address in them?
 *   When one fails with a PHP error, the error log names the plugin or theme.
 * - Leftovers: does the old address still appear anywhere in the database?
 *   (A table without a primary key, which the rewrite can't update, is the
 *   usual reason.)
 *
 * Runs after a move finishes (the dashboard, `wp disembark pull`), and again
 * any time with `wp disembark verify`. The result is saved with the move.
 */
class Verify {

    const MAX_PAGES = 14;

    /** Saved by Import::finalize(): what the checks need once staging is gone. */
    public static function save_context( $import_path, $source_home, $login_url ) {
        Storage::ensure_dir( $import_path );
        file_put_contents( $import_path . '/verify.json', wp_json_encode( [
            'source_home' => untrailingslashit( (string) $source_home ),
            'home'        => untrailingslashit( home_url() ),
            'login_url'   => (string) $login_url,
            'finished_at' => time(),
        ] ) );
    }

    public static function run( $import_id ) {
        $import_id = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $import_id ) );
        $dir       = Storage::base_dir() . "/_import_{$import_id}";
        if ( $import_id === '' || ! is_dir( $dir ) ) {
            return new \WP_Error( 'not_found', 'No move with that id.', [ 'status' => 404 ] );
        }
        if ( self::rolled_back( $dir ) ) {
            return new \WP_Error( 'rolled_back', 'That move was rolled back, so there\'s nothing of it to check.', [ 'status' => 409 ] );
        }
        $context = is_file( "{$dir}/verify.json" ) ? json_decode( (string) file_get_contents( "{$dir}/verify.json" ), true ) : null;
        $context = is_array( $context ) ? $context : [ 'source_home' => '', 'home' => untrailingslashit( home_url() ), 'login_url' => '' ];
        $rows    = is_file( "{$dir}/rows-check.json" ) ? json_decode( (string) file_get_contents( "{$dir}/rows-check.json" ), true ) : null;

        $old   = (string) ( $context['source_home'] ?? '' );
        $moved = $old !== '' && Quiet::address( $old ) !== Quiet::address( home_url() );
        $since = time();
        $pages = self::pages( $moved ? $old : '', (string) ( $context['login_url'] ?? '' ) );
        if ( array_filter( (array) $pages['list'], function ( $p ) { return ! empty( $p['fatal'] ); } ) ) {
            $pages['culprits'] = self::fatal_culprits( $since - 2 );
        }
        $leftovers = $moved ? self::leftovers( $old ) : null;

        $problems = 0;
        $problems += is_array( $rows ) ? count( (array) ( $rows['mismatched'] ?? [] ) ) : 0;
        $problems += count( array_filter( (array) $pages['list'], function ( $p ) { return ! empty( $p['problem'] ); } ) );
        $problems += ( $leftovers && $leftovers['rows'] ) ? 1 : 0;

        $result = [
            'ok'         => $problems === 0,
            'problems'   => $problems,
            'rows'       => is_array( $rows ) ? $rows : null,
            'pages'      => $pages,
            'leftovers'  => $leftovers,
            'old_home'   => $moved ? $old : '',
            'checked_at' => time(),
        ];
        file_put_contents( "{$dir}/verify-result.json", wp_json_encode( $result ) );
        return $result;
    }

    private static function rolled_back( $dir ) {
        $meta = is_file( "{$dir}/rollback/meta.json" ) ? json_decode( (string) file_get_contents( "{$dir}/rollback/meta.json" ), true ) : null;
        return is_array( $meta ) && ! empty( $meta['rolled_back_at'] );
    }

    /** The newest finished move that hasn't been rolled back, or ''. */
    public static function latest() {
        $id     = '';
        $newest = 0;
        foreach ( (array) glob( Storage::base_dir() . '/_import_*/verify.json' ) as $file ) {
            if ( self::rolled_back( dirname( $file ) ) || filemtime( $file ) <= $newest ) {
                continue;
            }
            $newest = filemtime( $file );
            $id     = substr( basename( dirname( $file ) ), strlen( '_import_' ) );
        }
        return $id;
    }

    /* ----------------------------------------------------------- Pages */

    /** The pages worth loading: home, login, one per post type and taxonomy. */
    private static function sample_urls( $login_url ) {
        $urls = [ 'Home page' => home_url( '/' ), 'Login page' => $login_url !== '' ? $login_url : wp_login_url() ];
        foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $type ) {
            if ( $type->name === 'attachment' || count( $urls ) >= self::MAX_PAGES - 3 ) {
                continue;
            }
            $posts = get_posts( [ 'post_type' => $type->name, 'post_status' => 'publish', 'numberposts' => 1, 'orderby' => 'date', 'order' => 'DESC', 'suppress_filters' => true, 'has_password' => false ] );
            if ( $posts ) {
                $link = get_permalink( $posts[0] );
                if ( $link ) {
                    $urls[ 'Newest ' . strtolower( (string) $type->labels->singular_name ) ] = $link;
                }
            }
        }
        foreach ( get_taxonomies( [ 'public' => true ], 'objects' ) as $tax ) {
            if ( $tax->name === 'post_format' || count( $urls ) >= self::MAX_PAGES ) {
                continue;
            }
            $terms = get_terms( [ 'taxonomy' => $tax->name, 'number' => 1, 'hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC' ] );
            if ( is_array( $terms ) && $terms ) {
                $link = get_term_link( $terms[0] );
                if ( is_string( $link ) ) {
                    $urls[ (string) $tax->labels->singular_name . ' archive' ] = $link;
                }
            }
        }
        return array_unique( $urls );
    }

    private static function pages( $old_home, $login_url ) {
        $list    = [];
        $reached = 0;
        $host    = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
        $verify  = ! ( defined( 'DISEMBARK_DEV_MODE' ) && DISEMBARK_DEV_MODE );
        foreach ( self::sample_urls( $login_url ) as $label => $url ) {
            $start = microtime( true );
            $hops  = 0;
            $page  = [ 'label' => $label, 'url' => $url ];
            do {
                $response = wp_remote_get( $url, [ 'timeout' => 25, 'redirection' => 0, 'sslverify' => $verify, 'reject_unsafe_urls' => false, 'headers' => [ 'Cache-Control' => 'no-cache' ], 'user-agent' => 'Disembark/verify' ] );
                if ( is_wp_error( $response ) ) {
                    break;
                }
                $code     = (int) wp_remote_retrieve_response_code( $response );
                $location = (string) wp_remote_retrieve_header( $response, 'location' );
                if ( $code >= 300 && $code < 400 && $location !== '' && $hops < 3 ) {
                    $next = \WP_Http::make_absolute_url( $location, $url );
                    $to   = strtolower( (string) wp_parse_url( $next, PHP_URL_HOST ) );
                    if ( $to !== '' && $to !== $host ) {
                        $page['redirect'] = $next;
                        break;
                    }
                    $url = $next;
                    $hops++;
                    continue;
                }
                break;
            } while ( true );
            $page['ms'] = (int) round( ( microtime( true ) - $start ) * 1000 );

            if ( is_wp_error( $response ) ) {
                $page['status']  = 0;
                $page['error']   = $response->get_error_message();
                $list[]          = $page;
                continue;
            }
            $reached++;
            $page['status'] = $code;
            $body           = (string) wp_remote_retrieve_body( $response );
            if ( ! empty( $page['redirect'] ) ) {
                $page['problem'] = 'Redirects off this site, to ' . $page['redirect'];
            } elseif ( preg_match( '/There has been a critical error on this website|<b>(?:Fatal|Parse) error<\/b>:|PHP (?:Fatal|Parse) error|Error establishing a database connection/i', $body ) || $code >= 500 ) {
                $page['problem'] = $code >= 500 ? "Answers {$code}" . ( stripos( $body, 'critical error' ) !== false ? ' (a PHP error)' : '' ) : 'Shows a PHP error';
                $page['fatal']   = true;
                // Where errors are displayed, the page names the file.
                if ( preg_match( '#(?:Fatal error|Parse error|Uncaught|Error)\b.{0,400}?/wp-content/(plugins|mu-plugins|themes)/([^/\s<"\']+)#is', $body, $c ) ) {
                    $page['culprit'] = ( $c[1] === 'themes' ? 'theme ' : ( $c[1] === 'mu-plugins' ? 'must-use plugin ' : 'plugin ' ) ) . preg_replace( '/\.php$/', '', $c[2] );
                    $page['problem'] .= ': a PHP error in ' . $page['culprit'];
                }
            } elseif ( $code === 404 ) {
                $page['problem'] = 'Not found (404). If links 404 across the site, re-save Settings → Permalinks.';
            } elseif ( $code >= 400 && ! in_array( $code, [ 401, 403 ], true ) ) {
                $page['problem'] = "Answers {$code}";
            }
            if ( $old_home !== '' && $body !== '' ) {
                $mentions = self::count_mentions( $body, $old_home );
                if ( $mentions ) {
                    $page['old_address'] = $mentions;
                }
            }
            $list[] = $page;
        }
        $out = [ 'list' => $list, 'loopback' => $reached > 0 ];
        if ( ! $reached && $list ) {
            // The server couldn't reach itself at all: a host that blocks
            // loopback requests, not a broken site.
            foreach ( $out['list'] as &$p ) {
                unset( $p['problem'] );
            }
            unset( $p );
            $out['note'] = 'This server can\'t load its own pages (' . ( $list[0]['error'] ?? 'no answer' ) . '), so pages weren\'t checked. Open the site in a browser to check it.';
        } else {
            foreach ( $out['list'] as &$p ) {
                if ( ! empty( $p['error'] ) ) {
                    $p['problem'] = 'Couldn\'t load: ' . $p['error'];
                }
            }
            unset( $p );
        }
        return $out;
    }

    /** Spellings of the old address worth counting: //host in plain, JSON and URL-encoded form. */
    private static function needles( $old_home ) {
        $host = strtolower( (string) wp_parse_url( $old_home, PHP_URL_HOST ) );
        if ( $host === '' ) {
            return [];
        }
        $hosts   = [ $host, stripos( $host, 'www.' ) === 0 ? substr( $host, 4 ) : 'www.' . $host ];
        $needles = [];
        foreach ( $hosts as $h ) {
            // The new address can contain the old one (old-site.com moving to
            // old-site.com.staging.dev): those spellings can't tell them apart.
            if ( stripos( home_url(), '//' . $h ) !== false ) {
                continue;
            }
            array_push( $needles, '//' . $h, '\/\/' . $h, '%2F%2F' . $h, '%2f%2f' . $h );
        }
        return array_values( array_unique( $needles ) );
    }

    private static function count_mentions( $text, $old_home ) {
        $count = 0;
        foreach ( self::needles( $old_home ) as $needle ) {
            $count += substr_count( strtolower( $text ), strtolower( $needle ) );
        }
        return $count;
    }

    /**
     * The plugin or theme behind PHP fatal errors logged since $since, from
     * the PHP error log and WordPress's debug.log.
     */
    private static function fatal_culprits( $since ) {
        $logs = array_unique( array_filter( [ (string) ini_get( 'error_log' ), WP_CONTENT_DIR . '/debug.log', ABSPATH . 'error_log' ], function ( $f ) { return $f !== '' && @is_file( $f ) && @is_readable( $f ); } ) );
        $found = [];
        foreach ( $logs as $log ) {
            $size = (int) @filesize( $log );
            $fh   = @fopen( $log, 'r' );
            if ( ! $fh ) {
                continue;
            }
            fseek( $fh, max( 0, $size - 262144 ) );
            $tail = (string) stream_get_contents( $fh );
            fclose( $fh );
            foreach ( preg_split( '/\R/', $tail ) as $line ) {
                if ( ! preg_match( '/^\[([^\]]+)\]\s+PHP (Fatal|Parse) error:\s*(.+)$/', $line, $m ) ) {
                    continue;
                }
                $at = strtotime( $m[1] );
                if ( $at === false || $at < $since ) {
                    continue;
                }
                $component = '';
                if ( preg_match( '#/wp-content/(plugins|mu-plugins|themes)/([^/\s]+)#', $m[3], $c ) ) {
                    $component = ( $c[1] === 'themes' ? 'theme ' : ( $c[1] === 'mu-plugins' ? 'must-use plugin ' : 'plugin ' ) ) . preg_replace( '/\.php$/', '', $c[2] );
                }
                $found[ $component . '|' . $m[3] ] = [ 'component' => $component, 'message' => substr( $m[3], 0, 300 ) ];
            }
        }
        return array_slice( array_values( $found ), -3 );
    }

    /* ------------------------------------------------------- Leftovers */

    /**
     * Rows that still mention the old address, per table, with a sample.
     * The search runs in the database (LIKE), so it's quick even on large
     * sites. Disembark's own records (held emails and the like) don't count.
     */
    private static function leftovers( $old_home ) {
        global $wpdb;
        $needles = self::needles( $old_home );
        $out     = [ 'rows' => 0, 'tables' => [], 'samples' => [] ];
        if ( ! $needles ) {
            $out['note'] = 'The new address contains the old one, so leftovers can\'t be told apart.';
            return $out;
        }
        $text = '/char|text|json|blob/i';
        foreach ( (array) $wpdb->get_results( 'SHOW FULL TABLES', ARRAY_N ) as $t ) {
            if ( strtoupper( (string) ( $t[1] ?? '' ) ) === 'VIEW' || strpos( $t[0], Import::SHADOW_PREFIX ) === 0 ) {
                continue;
            }
            $table   = $t[0];
            $q       = '`' . str_replace( '`', '``', $table ) . '`';
            $columns = (array) $wpdb->get_results( "SHOW COLUMNS FROM {$q}", ARRAY_A );
            $keys    = [];
            $where   = [];
            foreach ( $columns as $col ) {
                if ( $col['Key'] === 'PRI' ) {
                    $keys[] = $col['Field'];
                }
                if ( preg_match( $text, (string) $col['Type'] ) ) {
                    foreach ( $needles as $needle ) {
                        $where[] = $wpdb->prepare( '`' . str_replace( '`', '``', $col['Field'] ) . '` LIKE %s', '%' . $wpdb->esc_like( $needle ) . '%' );
                    }
                }
            }
            if ( ! $where ) {
                continue;
            }
            $cond = '(' . implode( ' OR ', $where ) . ')';
            if ( $table === $wpdb->options ) {
                $cond .= " AND option_name NOT LIKE 'disembark\\_%' AND option_name NOT LIKE '\\_transient%' AND option_name NOT LIKE '\\_site\\_transient%'";
            }
            $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$q} WHERE {$cond}" );
            if ( ! $count ) {
                continue;
            }
            $out['rows'] += $count;
            $out['tables'][ $table ] = [ 'rows' => $count, 'no_primary_key' => ! $keys ];
            if ( count( $out['samples'] ) < 8 ) {
                $row = $wpdb->get_row( "SELECT * FROM {$q} WHERE {$cond} LIMIT 1", ARRAY_A );
                foreach ( (array) $row as $column => $value ) {
                    if ( ! is_string( $value ) ) {
                        continue;
                    }
                    foreach ( $needles as $needle ) {
                        $pos = stripos( $value, $needle );
                        if ( $pos !== false ) {
                            $where_key = $keys ? implode( ', ', array_map( function ( $k ) use ( $row ) { return $k . '=' . $row[ $k ]; }, $keys ) ) : '';
                            $out['samples'][] = [ 'table' => $table, 'column' => $column, 'key' => $where_key, 'excerpt' => ( $pos > 40 ? '…' : '' ) . substr( $value, max( 0, $pos - 40 ), 120 ) . '…' ];
                            break 2;
                        }
                    }
                }
            }
        }
        arsort( $out['tables'] );
        return $out;
    }
}
