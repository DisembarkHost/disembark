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

    public static function base_dir() {
        return wp_upload_dir()['basedir'] . '/disembark';
    }

    /**
     * Creates $dir if needed and guards it plus every folder between it and
     * the Disembark base folder.
     */
    public static function ensure_dir( $dir ) {
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        $base = self::base_dir();
        self::protect( $base );
        $dir = rtrim( $dir, '/' );
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
     * The base folder as a path relative to the web root, for the scan's
     * default excludes (custom UPLOADS / Bedrock layouts move it).
     */
    public static function relative_base_dir() {
        $web_root = rtrim( str_replace( '\\', '/', dirname( WP_CONTENT_DIR ) ), '/' );
        $base     = str_replace( '\\', '/', self::base_dir() );
        if ( strpos( $base, $web_root . '/' ) === 0 ) {
            return substr( $base, strlen( $web_root ) + 1 );
        }
        return 'wp-content/uploads/disembark';
    }
}
