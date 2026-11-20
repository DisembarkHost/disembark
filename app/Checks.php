<?php

namespace Disembark;

/**
 * Checks before a move changes anything: the problems that would otherwise
 * stop it halfway (a full disk, a database user that can't create tables, a
 * table name that's too long after the prefix change) and the ones worth
 * knowing first (older plugin versions replacing newer ones, a PHP version
 * jump, a host that keeps parts of the site read-only).
 *
 * Each check has a level: "block" stops the move unless the person carries
 * on anyway, "warn" and "info" are shown and the move continues. Runs on the
 * destination once it knows what's coming: for a pull, after "What to bring"
 * and before any file is copied; for a backup zip, once it's unpacked.
 */
class Checks {

    public static function before_move( $import_id ) {
        global $wpdb;
        $import_id = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $import_id ) );
        $dir       = Storage::base_dir() . "/_import_{$import_id}";
        $pull      = is_file( "{$dir}/pull.json" ) ? json_decode( (string) file_get_contents( "{$dir}/pull.json" ), true ) : null;
        $pull      = is_array( $pull ) ? $pull : null;
        $import    = new Import( $import_id );
        $source    = json_decode( (string) wp_json_encode( $import->staged_source() ), true );
        $source    = is_array( $source ) ? $source : [];
        $profile   = $pull['source_profile'] ?? ( $source['profile'] ?? null );
        $profile   = is_array( $profile ) ? $profile : null;

        $checks = [];
        $add    = function ( $level, $id, $title, $detail = '' ) use ( &$checks ) {
            $checks[] = [ 'level' => $level, 'id' => $id, 'title' => $title, 'detail' => $detail ];
        };

        $space = self::disk( $dir, $pull, $add );
        self::database_user( $add );
        $target = Target::load( $dir );
        if ( $pull && ! empty( $pull['tables'] ) ) {
            // Into a subsite or tenant: its prefix (a new one's, at its longest).
            $prefix = $target ? ( $target->prefix !== '' ? $target->prefix : ( $target->kind === 'multisite' ? $wpdb->base_prefix . '9999_' : 'stacked_9999_' ) ) : $wpdb->prefix;
            self::table_names( array_column( (array) $pull['tables'], 'name' ), (string) ( $pull['source_prefix'] ?? '' ), $prefix, $add );
        }
        self::writable( $add );
        if ( $profile ) {
            self::versions( $profile, $add, $import_id );
            // Into a subsite or tenant, this site's own plugins say nothing
            // about the site arriving (shared ones are kept, and reported).
            if ( ! $target ) {
                self::plugins( $profile, $add );
            }
        }
        self::environment( $profile, $add );
        if ( ! empty( $source['captured'] ) && ( $age = time() - (int) strtotime( (string) $source['captured'] ) ) > 180 * DAY_IN_SECONDS ) {
            $add( 'warn', 'backup_age', 'This backup is ' . floor( $age / DAY_IN_SECONDS ) . ' days old', 'It was taken ' . wp_date( 'F j, Y', strtotime( (string) $source['captured'] ) ) . '. Anything that changed on the site since then is not in it.' );
        }

        /**
         * Filters the checks before a move: a host or add-on can add its own.
         * Each is [ level => block|warn|info, id, title, detail ].
         */
        $checks = array_values( array_filter( (array) apply_filters( 'disembark_move_checks', $checks, $import_id, $pull, $profile ), function ( $c ) {
            return is_array( $c ) && isset( $c['level'], $c['title'] ) && in_array( $c['level'], [ 'block', 'warn', 'info' ], true );
        } ) );
        foreach ( $checks as &$c ) {
            $c += [ 'id' => '', 'detail' => '' ];
        }
        unset( $c );
        $levels = array_column( $checks, 'level' );
        return [
            'checks'  => $checks,
            'blocked' => in_array( 'block', $levels, true ),
            'space'   => $space,
            'profile' => (bool) $profile,
        ];
    }

    /* ------------------------------------------------------------ Disk */

    /**
     * Room for everything the move writes before it finishes: the files and
     * database still to download, this site's files the move replaces (kept
     * in the rollback point), this site's database (the rollback point's
     * dump) and a second copy of the incoming tables (the import builds them
     * beside the live ones before swapping).
     */
    private static function disk( $dir, $pull, $add ) {
        global $wpdb;
        $web_root = wp_normalize_path( dirname( WP_CONTENT_DIR ) );
        $download = 0;
        $replaced = 0;
        $incoming = 0;
        $db_dump  = 0;
        $db_in    = 0;

        if ( $pull && ! empty( $pull['file_list'] ) && is_file( $pull['file_list'] ) ) {
            $files   = (array) json_decode( (string) file_get_contents( $pull['file_list'] ), true );
            $fetched = (int) ( $pull['fetched'] ?? 0 );
            foreach ( $files as $i => $f ) {
                $size      = (int) ( $f['size'] ?? 0 );
                $incoming += $size;
                if ( $i >= $fetched ) {
                    $download += $size;
                }
                $path = $web_root . '/' . ltrim( (string) ( $f['name'] ?? '' ), '/' );
                if ( is_file( $path ) ) {
                    $replaced += (int) @filesize( $path );
                }
            }
            $skip = array_flip( (array) ( $pull['skip_rows'] ?? [] ) );
            foreach ( (array) ( $pull['tables'] ?? [] ) as $t ) {
                if ( ! isset( $skip[ $t['name'] ?? '' ] ) ) {
                    $db_in += (int) ( $t['size'] ?? 0 );
                }
            }
            if ( empty( $pull['db_done'] ) ) {
                $db_dump = $db_in;
            }
        } else {
            $root = is_dir( "{$dir}/staging/public" ) ? "{$dir}/staging/public" : "{$dir}/staging";
            if ( is_dir( $root ) ) {
                try {
                    $it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
                    foreach ( $it as $file ) {
                        if ( ! $file->isFile() ) {
                            continue;
                        }
                        $rel = ltrim( str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) ) ), '/' );
                        if ( preg_match( '/^database-.*\.sql$/', $rel ) ) {
                            $db_in += $file->getSize();
                            continue;
                        }
                        $incoming += $file->getSize();
                        // Runnable files are staged under a safe name; size up the real one.
                        if ( method_exists( '\Disembark\Import', 'unstaged_name' ) ) {
                            $rel = Import::unstaged_name( $rel );
                        }
                        if ( is_file( $web_root . '/' . $rel ) ) {
                            $replaced += (int) @filesize( $web_root . '/' . $rel );
                        }
                    }
                } catch ( \Throwable $e ) {
                    // Unreadable staging: the move itself will say so.
                }
            }
        }
        $here = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = %s', DB_NAME ) );
        $need = $download + $db_dump + $replaced + $here + $db_in;
        $free = function_exists( 'disk_free_space' ) ? @disk_free_space( Storage::base_dir() ) : false;

        $space = [ 'free' => $free === false ? null : (int) $free, 'need' => $need, 'download' => $download + $db_dump, 'rollback' => $replaced + $here, 'database_copy' => $db_in, 'incoming_files' => $incoming ];
        if ( $free === false ) {
            $add( 'info', 'disk_unknown', 'Free disk space couldn\'t be measured', 'This host doesn\'t report it. The move needs about ' . size_format( $need ) . ' while it runs.' );
            return $space;
        }
        $parts = array_filter( [
            $download + $db_dump ? size_format( $download + $db_dump ) . ' still to download' : '',
            $replaced + $here ? size_format( $replaced + $here ) . ' for the rollback point (this site\'s current files and database)' : '',
            $db_in ? size_format( $db_in ) . ' for the incoming tables, built beside the live ones' : '',
        ] );
        $detail = 'The move needs about ' . size_format( $need ) . ': ' . implode( ', ', $parts ) . '. ' . size_format( $free ) . ' is free.';
        if ( $free < $need ) {
            $add( 'block', 'disk_space', 'Not enough disk space', $detail . ' Free up space, or leave more behind in "What to bring".' );
        } elseif ( $free < $need * 1.2 ) {
            $add( 'warn', 'disk_space', 'Disk space is tight', $detail );
        }
        return $space;
    }

    /* -------------------------------------------------------- Database */

    /** The import creates, renames and drops tables: try exactly that. */
    private static function database_user( $add ) {
        global $wpdb;
        $a = '_dsk_check_' . substr( md5( uniqid( '', true ) ), 0, 8 );
        $b = $a . '_b';
        $suppress = $wpdb->suppress_errors( true );
        $steps    = [
            'create' => "CREATE TABLE `{$a}` (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB",
            'rename' => "RENAME TABLE `{$a}` TO `{$b}`",
            'drop'   => "DROP TABLE `{$b}`",
        ];
        $failed = '';
        foreach ( $steps as $what => $sql ) {
            if ( $wpdb->query( $sql ) === false ) {
                $failed = $what . ': ' . substr( (string) $wpdb->last_error, 0, 200 );
                break;
            }
        }
        $wpdb->query( "DROP TABLE IF EXISTS `{$a}`" );
        $wpdb->query( "DROP TABLE IF EXISTS `{$b}`" );
        $wpdb->suppress_errors( $suppress );
        if ( $failed !== '' ) {
            $add( 'block', 'database_user', 'The database user can\'t create, rename or drop tables', 'The import needs all three. The server said: ' . $failed );
        }
    }

    private static function table_names( $tables, $source_prefix, $prefix, $add ) {
        global $wpdb;
        $too_long = [];
        $names    = [];
        foreach ( $tables as $table ) {
            $table = (string) $table;
            $new   = ( $source_prefix !== '' && strpos( $table, $source_prefix ) === 0 ) ? $prefix . substr( $table, strlen( $source_prefix ) ) : $table;
            if ( strlen( $new ) > 64 ) {
                $too_long[] = $new;
            }
            $names[] = $new;
        }
        if ( $too_long ) {
            $add( 'block', 'table_names', 'Table names would be too long', 'With this site\'s table prefix (' . $prefix . '), ' . implode( ', ', array_slice( $too_long, 0, 5 ) ) . ' would be over the 64 characters MySQL allows.' );
        }
        $lower = (string) $wpdb->get_var( "SELECT @@lower_case_table_names" );
        if ( $lower !== '' && $lower !== '0' ) {
            $groups = [];
            foreach ( $names as $name ) {
                $groups[ strtolower( $name ) ][] = $name;
            }
            $clashes = array_filter( $groups, function ( $g ) { return count( $g ) > 1; } );
            $upper   = array_filter( $names, function ( $n ) { return $n !== strtolower( $n ); } );
            if ( $clashes ) {
                $add( 'block', 'table_case', 'Tables differ only in upper and lower case', 'This database server stores table names in lower case, so ' . implode( ' and ', reset( $clashes ) ) . ' would be the same table.' );
            } elseif ( $upper ) {
                $add( 'warn', 'table_case', 'Some table names will be stored in lower case', 'This database server stores table names in lower case: ' . implode( ', ', array_slice( $upper, 0, 5 ) ) . '. Plugins usually don\'t mind.' );
            }
        }
    }

    /* ----------------------------------------------------------- Files */

    private static function writable( $add ) {
        $uploads = wp_upload_dir( null, false );
        $dirs    = [ 'wp-content' => WP_CONTENT_DIR, 'plugins' => WP_PLUGIN_DIR, 'themes' => get_theme_root(), 'uploads' => (string) ( $uploads['basedir'] ?? '' ) ];
        $locked  = [];
        foreach ( $dirs as $label => $path ) {
            if ( $path !== '' && is_dir( $path ) && ! wp_is_writable( $path ) ) {
                $locked[] = $label;
            }
        }
        if ( $locked ) {
            $add( 'block', 'read_only', 'Folders this move writes to are read-only', 'PHP can\'t write to ' . implode( ', ', $locked ) . ' here, so files can\'t be put in place.' );
        } elseif ( ! wp_is_writable( ABSPATH ) ) {
            $add( 'warn', 'read_only_root', 'The site\'s root folder is read-only', 'WordPress\'s own files and root files such as .htaccess can\'t be replaced; wp-content can.' );
        }
    }

    /* -------------------------------------------------------- Versions */

    /**
     * The PHP version this site's pages run on. WP-CLI often runs a different
     * PHP than the web server, so from the CLI the site is asked over HTTP.
     * Null when that doesn't answer.
     */
    private static function web_php_version( $import_id ) {
        if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
            return PHP_VERSION;
        }
        $verify   = ! ( defined( 'DISEMBARK_DEV_MODE' ) && DISEMBARK_DEV_MODE );
        $response = wp_remote_post( rest_url( 'disembark/v1/import/preflight' ), [
            'timeout'   => 20,
            'sslverify' => $verify,
            'headers'   => [ 'Content-Type' => 'application/json' ],
            'body'      => wp_json_encode( [ 'token' => Token::get(), 'import_id' => $import_id ] ),
        ] );
        $data = is_wp_error( $response ) ? null : json_decode( (string) wp_remote_retrieve_body( $response ), true );
        return is_array( $data ) && ! empty( $data['php_version'] ) ? (string) $data['php_version'] : null;
    }

    private static function versions( $profile, $add, $import_id = '' ) {
        $src_php = (string) ( $profile['php_version'] ?? '' );
        $web_php = $src_php !== '' ? self::web_php_version( $import_id ) : null;
        if ( $src_php !== '' && $web_php ) {
            $a = implode( '.', array_slice( explode( '.', $src_php ), 0, 2 ) );
            $b = implode( '.', array_slice( explode( '.', $web_php ), 0, 2 ) );
            if ( version_compare( $b, $a, '<' ) ) {
                $add( 'warn', 'php_older', "This server runs an older PHP ({$b}) than the source ({$a})", 'Plugins and themes that need a newer PHP will fail here.' );
            } elseif ( (int) $b > (int) $a ) {
                $add( 'warn', 'php_newer', "PHP goes from {$a} to {$b}", 'Plugins or themes written for PHP ' . (int) $a . ' may not run on PHP ' . (int) $b . '. The checks after the move load a few pages to see.' );
            }
        }
        $src_wp = (string) ( $profile['wp_version'] ?? '' );
        $here   = (string) get_bloginfo( 'version' );
        if ( $src_wp !== '' && version_compare( $src_wp, $here, '<' ) ) {
            $add( 'warn', 'wp_older', "WordPress goes back from {$here} to {$src_wp}", 'The move brings the source\'s WordPress files with it.' );
        }
        if ( ! empty( $profile['multisite'] ) && ! is_multisite() ) {
            $add( 'warn', 'multisite', 'The source is a multisite network; this site isn\'t', 'This site keeps its own wp-config.php, so the network\'s constants have to be added there after the move (the run lists them).' );
        }
    }

    /** Older plugin versions replacing newer ones, and plugins that end up inactive. */
    private static function plugins( $profile, $add ) {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $theirs = (array) ( $profile['plugins'] ?? [] );
        $ours   = (array) get_plugins();
        $active = (array) get_option( 'active_plugins', [] );
        $own    = plugin_basename( dirname( __DIR__ ) . '/disembark.php' );
        $older  = [];
        $idle   = [];
        foreach ( $ours as $file => $data ) {
            // Disembark itself, and WP Freighter on an install's main site:
            // both stay on whatever the source runs (see Import::keep_install_rows()).
            if ( $file === $own || ( $file === 'wp-freighter/wp-freighter.php' && Scope::freighter_primary() !== '' ) ) {
                continue;
            }
            if ( isset( $theirs[ $file ] ) ) {
                $v_here  = (string) ( $data['Version'] ?? '' );
                $v_there = (string) ( $theirs[ $file ]['version'] ?? '' );
                if ( $v_here !== '' && $v_there !== '' && version_compare( $v_there, $v_here, '<' ) ) {
                    $older[] = self::plain_name( $data['Name'] ?? $file ) . " {$v_here} → {$v_there}";
                }
            } elseif ( in_array( $file, $active, true ) ) {
                $idle[] = self::plain_name( $data['Name'] ?? $file );
            }
        }
        if ( $older ) {
            $add( 'warn', 'plugin_downgrades', 'Older plugin versions will replace newer ones', implode( ', ', array_slice( $older, 0, 8 ) ) . ( count( $older ) > 8 ? ', and ' . ( count( $older ) - 8 ) . ' more' : '' ) . '. The source\'s copies come across as they are; update them afterwards if this site needs the newer ones.' );
        }
        if ( $idle ) {
            $add( 'info', 'plugins_inactive', 'Plugins active here that the source doesn\'t have', implode( ', ', array_slice( $idle, 0, 8 ) ) . ( count( $idle ) > 8 ? ', and ' . ( count( $idle ) - 8 ) . ' more' : '' ) . ' stay installed but won\'t be active afterwards: the source\'s database decides what\'s active.' );
        }
    }

    /** A plugin's name as plain text: some headers spell "&" as "&amp;", and the checks print as text. */
    private static function plain_name( $name ) {
        return html_entity_decode( (string) $name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    }

    /* ------------------------------------------------------ Environment */

    private static function environment( $profile, $add ) {
        $memory = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
        if ( $memory > 0 && $memory < 128 * MB_IN_BYTES ) {
            $add( 'warn', 'memory', 'PHP has ' . size_format( $memory ) . ' of memory here', 'Large tables and big plugins may run out. 256 MB is a comfortable minimum.' );
        }
        if ( defined( 'PANTHEON_ENVIRONMENT' ) ) {
            $add( 'warn', 'host_pantheon', 'Pantheon keeps code read-only outside SFTP mode', 'Switch this environment to SFTP mode first, or only uploads can be written.' );
        }
        if ( defined( 'ATOMIC_SITE_ID' ) || defined( 'IS_ATOMIC' ) ) {
            $add( 'warn', 'host_wpcom', 'WordPress.com manages parts of this site', 'Its own plugins and drop-ins stay; some files can\'t be replaced.' );
        }
        // A prepend inside the site (Wordfence's wordfence-waf.php and the
        // like) belongs to this site's firewall; one outside it is the host's.
        $prepend = wp_normalize_path( (string) ini_get( 'auto_prepend_file' ) );
        $root    = wp_normalize_path( dirname( WP_CONTENT_DIR ) );
        if ( $prepend !== '' && strpos( $prepend, $root . '/' ) === 0 ) {
            $add( 'info', 'prepend', 'A firewall loads before WordPress here', substr( $prepend, strlen( $root ) + 1 ) . ' runs before every request (usually Wordfence or another firewall). It stays after the move; if the incoming site doesn\'t use that firewall, remove its line from .user.ini or .htaccess.' );
        }
    }
}
