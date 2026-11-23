<?php

namespace Disembark;

/**
 * Where Disembark keeps its working files (uploads/disembark) and how those
 * folders are named and guarded. Backups, dumps, staged restores and rollback
 * points all live here, inside the public uploads tree, so every folder gets a
 * blank index file to stop directory listings (an .htaccess "Options -Indexes"
 * is deliberately not used: hosts that disallow that override answer 500 for
 * the whole folder, which would break backup downloads).
 */
class Storage {

    /**
     * The folder everything Disembark keeps lives in: the main site's uploads
     * folder plus /disembark, worked out from WordPress's constants alone
     * (UPLOADS, WP_CONTENT_DIR, which is how WP Freighter gives each tenant
     * its own folder), never from the upload_path option. A move replaces
     * the options table with the source's, so a source with its own
     * upload_path used to move this folder halfway through: the next
     * request looked for the move's state, its token guard and every
     * restore point in a folder that didn't hold them, and the rollback was
     * gone. Fixed per request; folders an earlier version kept under a
     * custom upload_path move here once (Run::upgrade_storage()).
     */
    public static function base_dir() {
        static $dir = null;
        if ( $dir === null ) {
            $dir = self::uploads()['basedir'];
        }
        return $dir . '/disembark';
    }

    /**
     * The main site's uploads folder and address as WordPress works them out
     * with no upload_path or upload_url_path set. The folder is cached for
     * the request; the address is worked out each time (it follows siteurl,
     * which a move changes).
     */
    private static function uploads() {
        static $dir = null;
        $switched = is_multisite() && get_current_blog_id() !== get_main_site_id();
        if ( $switched ) {
            switch_to_blog( get_main_site_id() );
        }
        $blank = function () {
            return '';
        };
        add_filter( 'pre_option_upload_path', $blank, PHP_INT_MAX );
        add_filter( 'pre_option_upload_url_path', $blank, PHP_INT_MAX );
        try {
            $uploads = function_exists( '_wp_upload_dir' ) ? _wp_upload_dir() : wp_upload_dir( null, false, true );
        } finally {
            remove_filter( 'pre_option_upload_path', $blank, PHP_INT_MAX );
            remove_filter( 'pre_option_upload_url_path', $blank, PHP_INT_MAX );
            if ( $switched ) {
                restore_current_blog();
            }
        }
        if ( $dir === null ) {
            $dir = untrailingslashit( (string) $uploads['basedir'] );
        }
        return [ 'basedir' => $dir, 'baseurl' => untrailingslashit( (string) $uploads['baseurl'] ) ];
    }

    /**
     * Where an earlier version kept this folder when it differs from
     * base_dir(): under the upload_path option (or an upload_dir filter).
     * '' when it's the same folder.
     */
    public static function legacy_base_dir() {
        $switched = is_multisite() && get_current_blog_id() !== get_main_site_id();
        if ( $switched ) {
            switch_to_blog( get_main_site_id() );
        }
        try {
            $legacy = untrailingslashit( (string) wp_upload_dir( null, false )['basedir'] ) . '/disembark';
        } finally {
            if ( $switched ) {
                restore_current_blog();
            }
        }
        $norm = function ( $p ) {
            $p = rtrim( wp_normalize_path( $p ), '/' );
            $r = @realpath( $p );
            return $r ? rtrim( wp_normalize_path( $r ), '/' ) : $p;
        };
        return $norm( $legacy ) === $norm( self::base_dir() ) ? '' : $legacy;
    }

    /**
     * The base folder's URL: the uploads address with no upload_url_path.
     * WordPress builds that from siteurl (UPLOADS set) or WP_CONTENT_URL, so
     * when basedir sits under the web root and the address doesn't end in
     * that same path, the URL is built from the site address instead.
     */
    public static function base_url() {
        $uploads = self::uploads();
        $url     = $uploads['baseurl'];
        $root    = rtrim( str_replace( '\\', '/', ABSPATH ), '/' );
        $dir     = rtrim( str_replace( '\\', '/', $uploads['basedir'] ), '/' );
        if ( strpos( $dir, $root . '/' ) === 0 ) {
            $rel  = '/' . substr( $dir, strlen( $root ) + 1 );
            $path = rtrim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
            if ( substr( $path, -strlen( $rel ) ) !== $rel ) {
                $switched = is_multisite() && get_current_blog_id() !== get_main_site_id();
                if ( $switched ) {
                    switch_to_blog( get_main_site_id() );
                }
                $url = untrailingslashit( site_url( $rel ) );
                if ( $switched ) {
                    restore_current_blog();
                }
            }
        }
        return $url . '/disembark';
    }

    /**
     * Moves what an earlier version kept under a custom upload_path into
     * base_dir(), a few seconds at a time. Renamed where it can be; across
     * disks, copied file by file and each original deleted once copied, so
     * a later call carries on. True when nothing is left to move. An entry
     * whose name is already taken here is left where it is.
     */
    public static function adopt_legacy( $deadline ) {
        $legacy = self::legacy_base_dir();
        if ( $legacy === '' || ! is_dir( $legacy ) || is_link( $legacy ) ) {
            return true;
        }
        $base = self::base_dir();
        self::ensure_dir( $base );
        $done = true;
        foreach ( (array) scandir( $legacy ) as $name ) {
            if ( $name === '.' || $name === '..' || $name === 'index.php' || $name === 'index.html' || $name === 'upgrade.lock' ) {
                continue;
            }
            if ( microtime( true ) >= $deadline ) {
                return false;
            }
            $from = "{$legacy}/{$name}";
            $to   = "{$base}/{$name}";
            if ( is_link( $from ) ) {
                continue;
            }
            if ( is_link( $to ) || ( file_exists( $to ) && ! is_dir( $to ) ) ) {
                continue;
            }
            if ( ! file_exists( $to ) && @rename( $from, $to ) ) {
                continue;
            }
            if ( is_dir( $from ) && ( ! file_exists( $to ) || is_dir( $to ) ) ) {
                if ( ! self::copy_tree( $from, $to, $deadline ) ) {
                    $done = false;
                }
            } elseif ( is_file( $from ) && ! file_exists( $to ) ) {
                $tmp = $base . '/.' . $name . '.tmp.' . getmypid();
                if ( @copy( $from, $tmp ) && @rename( $tmp, $to ) ) {
                    @unlink( $from );
                } else {
                    @unlink( $tmp );
                    $done = false;
                }
            }
        }
        $entries = @scandir( $legacy );
        if ( $done && is_array( $entries ) && array_diff( $entries, [ '.', '..', 'index.php', 'index.html', 'upgrade.lock' ] ) === [] ) {
            foreach ( [ 'index.php', 'index.html', 'upgrade.lock' ] as $name ) {
                @unlink( "{$legacy}/{$name}" );
            }
            @rmdir( $legacy );
        }
        return $done;
    }

    /** Copies $from into $to (creating it), deleting each original once copied. Entries already in $to are never overwritten. */
    private static function copy_tree( $from, $to, $deadline ) {
        if ( is_link( $to ) || ( file_exists( $to ) && ! is_dir( $to ) ) ) {
            return true;
        }
        if ( ! is_dir( $to ) && ! wp_mkdir_p( $to ) ) {
            return false;
        }
        $done = true;
        foreach ( (array) scandir( $from ) as $name ) {
            if ( $name === '.' || $name === '..' ) {
                continue;
            }
            if ( microtime( true ) >= $deadline ) {
                return false;
            }
            $a = "{$from}/{$name}";
            $b = "{$to}/{$name}";
            if ( is_link( $a ) ) {
                @unlink( $a );
            } elseif ( is_dir( $a ) ) {
                if ( ! is_link( $b ) && ( ! file_exists( $b ) || is_dir( $b ) ) ) {
                    $done = self::copy_tree( $a, $b, $deadline ) && $done;
                }
            } elseif ( file_exists( $b ) || is_link( $b ) ) {
                if ( is_file( $b ) && ! is_link( $b ) && @filesize( $a ) === @filesize( $b ) ) {
                    @unlink( $a );
                }
            } else {
                $tmp = $to . '/.' . $name . '.tmp.' . getmypid();
                if ( @copy( $a, $tmp ) && @rename( $tmp, $b ) ) {
                    @unlink( $a );
                } else {
                    @unlink( $tmp );
                    $done = false;
                }
            }
        }
        if ( $done ) {
            @rmdir( $from );
        }
        return $done;
    }

    /**
     * Creates $dir if needed and guards it plus every folder between it and
     * the Disembark base folder.
     */
    public static function ensure_dir( $dir ) {
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        $base = rtrim( wp_normalize_path( self::base_dir() ), '/' );
        self::protect( $base );
        $dir = rtrim( wp_normalize_path( (string) $dir ), '/' );
        while ( strlen( $dir ) > strlen( $base ) && strpos( $dir, $base . '/' ) === 0 ) {
            self::protect( $dir );
            $dir = dirname( $dir );
        }
    }

    public static function protect( $dir ) {
        if ( ! is_dir( $dir ) ) {
            return;
        }
        foreach ( [ 'index.php' => "<?php\n// Silence is golden.\n", 'index.html' => '' ] as $name => $body ) {
            if ( ! file_exists( "{$dir}/{$name}" ) ) {
                @file_put_contents( "{$dir}/{$name}", $body );
            }
        }
    }

    /**
     * A new backup session id: 128 random bits as 32 hex characters. The id
     * names a folder of dumps and zips under uploads, so it has to be
     * unguessable; the server mints it rather than trusting the browser's.
     */
    public static function new_session() {
        return bin2hex( random_bytes( 16 ) );
    }

    /**
     * Backup session ids are folder names under the base folder (hex, or
     * "rollback-<hex>" for snapshots). Anything else is stripped so a
     * caller-supplied id can never climb out of it.
     */
    public static function sanitize_session( $session ) {
        return preg_replace( '/[^a-z0-9-]/', '', strtolower( (string) $session ) );
    }

    /**
     * Where an earlier version kept the base folder (a custom upload_path),
     * relative to the web root, so a scan leaves out anything not yet moved.
     * The base folder's own path when there is no such folder.
     */
    public static function relative_legacy_base_dir() {
        $legacy = self::legacy_base_dir();
        if ( $legacy === '' ) {
            return self::relative_base_dir();
        }
        $rel = self::rel_to_web_root( $legacy );
        return $rel !== null ? $rel : self::relative_base_dir();
    }

    /**
     * The base folder as a path relative to the web root, for the scan's
     * default excludes (custom UPLOADS / Bedrock layouts move it).
     */
    public static function relative_base_dir() {
        $rel = self::rel_to_web_root( self::base_dir() );
        return $rel !== null ? $rel : 'wp-content/uploads/disembark';
    }

    /**
     * Makes $path relative to the web root: checks ABSPATH first when
     * WP_CONTENT_DIR sits inside ABSPATH (so Freighter dedicated tenants at
     * ABSPATH . 'content/8' return 'content/8/uploads/disembark', not
     * '8/uploads/disembark'), falling back to dirname(WP_CONTENT_DIR) for
     * Bedrock-style layouts where WP_CONTENT_DIR sits outside ABSPATH.
     */
    private static function rel_to_web_root( $path ) {
        $path    = str_replace( '\\', '/', (string) $path );
        $abspath = rtrim( str_replace( '\\', '/', ABSPATH ), '/' );
        $content = rtrim( str_replace( '\\', '/', WP_CONTENT_DIR ), '/' );
        $roots   = [];
        if ( $abspath !== '' && strpos( $content, $abspath . '/' ) === 0 ) {
            $roots[] = $abspath;
        }
        $parent = rtrim( str_replace( '\\', '/', dirname( WP_CONTENT_DIR ) ), '/' );
        if ( $parent !== '' && ! in_array( $parent, $roots, true ) ) {
            $roots[] = $parent;
        }
        foreach ( $roots as $web_root ) {
            if ( strpos( $path, $web_root . '/' ) === 0 ) {
                return substr( $path, strlen( $web_root ) + 1 );
            }
        }
        return null;
    }
}
