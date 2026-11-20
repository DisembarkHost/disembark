<?php

namespace Disembark;

/**
 * Quiet mode: keeps a copy of a site from reaching the real world. It's an
 * mu-plugin (mu-plugin/disembark-quiet-mode.php, written to the site's
 * mu-plugins folder) that holds emails, pauses WP-Cron and Action Scheduler,
 * closes WooCommerce checkout, pauses webhooks and blocks payment, marketing,
 * shipping-label and email APIs.
 *
 * It's bound to the address it was turned on for: once the site moves to
 * another address (a copy that went live on the real domain) it stands down
 * on its own.
 */
class Quiet {

    const FILE = 'disembark-quiet-mode.php';

    public static function path() {
        $dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
        return rtrim( wp_normalize_path( $dir ), '/' ) . '/' . self::FILE;
    }

    /** The settings written into the mu-plugin, or null when it isn't there. */
    public static function config() {
        $file = self::path();
        if ( ! is_file( $file ) ) {
            return null;
        }
        $code = (string) file_get_contents( $file );
        if ( ! preg_match( "/const CONFIG = '([A-Za-z0-9+\\/=]*)';/", $code, $m ) ) {
            return [];
        }
        $config = json_decode( (string) base64_decode( $m[1] ), true );
        return is_array( $config ) ? $config : [];
    }

    public static function address( $url ) {
        $url = strtolower( trim( (string) $url ) );
        $url = preg_replace( '#^[a-z]+://#', '', $url );
        $url = preg_replace( '#^www\.#', '', $url );
        return rtrim( $url, '/' );
    }

    /**
     * Turns quiet mode on for this site's current address. $source is the
     * site this one is a copy of (shown in notices). Turning it on again
     * keeps who first turned it on, so rolling back a later pull doesn't lift
     * a quiet mode that was already there.
     */
    public static function enable( $source = '', $import_id = '' ) {
        $existing = self::config();
        $config   = [
            'home'       => self::current_home(),
            'source'     => untrailingslashit( (string) $source ),
            'since'      => (int) ( $existing['since'] ?? 0 ) ?: time(),
            'enabled_by' => (string) ( $existing['enabled_by'] ?? $import_id ),
            'import_id'  => (string) $import_id,
            // During a restore the database briefly holds the source's address,
            // so quiet mode applies unconditionally until finalize binds it.
            'pending'    => $import_id !== '',
        ];
        if ( $existing && $config['source'] === '' ) {
            $config['source'] = (string) ( $existing['source'] ?? '' );
        }
        $written = self::write( $config );
        return is_wp_error( $written ) ? $written : self::status();
    }

    /**
     * Binds a pending quiet mode to the address the restore ended with. Called
     * by finalize; a no-op when quiet mode is off or already bound.
     */
    public static function bind() {
        $config = self::config();
        if ( empty( $config['pending'] ) ) {
            return false;
        }
        $config['pending'] = false;
        $config['home']    = self::current_home();
        // Turned on by this restore: the imported database may carry another
        // copy's held-email and blocked-call logs. Start this one clean.
        if ( ( $config['enabled_by'] ?? '' ) !== '' && $config['enabled_by'] === ( $config['import_id'] ?? '' ) ) {
            delete_option( 'disembark_quiet_held' );
            delete_option( 'disembark_quiet_blocked' );
        }
        return ! is_wp_error( self::write( $config ) );
    }

    /**
     * The home address straight from the database (a restore just rewrote
     * it). Quiet mode covers the whole install, so on a network this is the
     * main site's address and on a WP Freighter install the primary site's,
     * the same address the mu-plugin compares with on every subsite or tenant.
     */
    private static function current_home() {
        global $wpdb;
        $table = $wpdb->options;
        if ( is_multisite() ) {
            $table = $wpdb->get_blog_prefix( get_main_site_id() ) . 'options';
        } elseif ( ( $primary = Scope::freighter_primary() ) !== '' ) {
            $table = $primary . 'options';
        }
        $home = $wpdb->get_var( "SELECT option_value FROM `" . str_replace( '`', '``', $table ) . "` WHERE option_name = 'home' LIMIT 1" );
        return untrailingslashit( (string) ( $home ?: get_option( 'home' ) ) );
    }

    private static function write( $config ) {
        $template = dirname( __DIR__ ) . '/mu-plugin/' . self::FILE;
        if ( ! is_file( $template ) ) {
            return new \WP_Error( 'quiet_template_missing', 'The quiet mode template is missing from the Disembark plugin.', [ 'status' => 500 ] );
        }
        $code = str_replace(
            "'__DISEMBARK_QUIET_CONFIG__'",
            "'" . base64_encode( wp_json_encode( $config, JSON_UNESCAPED_SLASHES ) ) . "'",
            (string) file_get_contents( $template )
        );

        $file = self::path();
        $dir  = dirname( $file );
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
            return new \WP_Error( 'quiet_write_failed', "Couldn't create the mu-plugins folder ({$dir}) for quiet mode.", [ 'status' => 500 ] );
        }
        $tmp = $file . '.tmp-' . wp_generate_password( 8, false );
        if ( file_put_contents( $tmp, $code ) !== strlen( $code ) || ! rename( $tmp, $file ) ) {
            @unlink( $tmp );
            return new \WP_Error( 'quiet_write_failed', "Couldn't write {$file} for quiet mode.", [ 'status' => 500 ] );
        }
        @chmod( $file, 0644 );
        if ( function_exists( 'opcache_invalidate' ) ) {
            @opcache_invalidate( $file, true );
        }
        return true;
    }

    /** Lifts quiet mode and clears its logs. */
    public static function disable() {
        $file = self::path();
        if ( is_file( $file ) && ! @unlink( $file ) ) {
            return new \WP_Error( 'quiet_remove_failed', "Couldn't remove {$file}. Delete it by hand to lift quiet mode.", [ 'status' => 500 ] );
        }
        if ( function_exists( 'opcache_invalidate' ) ) {
            @opcache_invalidate( $file, true );
        }
        delete_option( 'disembark_quiet_held' );
        delete_option( 'disembark_quiet_blocked' );
        return self::status();
    }

    /** Called by a rollback: lifts quiet mode only if that import turned it on. */
    public static function lift_for_rollback( $import_id ) {
        $config = self::config();
        if ( $config && $import_id !== '' && ( $config['enabled_by'] ?? '' ) === $import_id ) {
            return ! is_wp_error( self::disable() );
        }
        return false;
    }

    /**
     * on: the file is there. active: it applies (the address still matches).
     * With $with_messages, the held emails' list (newest first, no bodies).
     */
    public static function status( $with_messages = false ) {
        $config = self::config();
        $held   = get_option( 'disembark_quiet_held' );
        $held   = is_array( $held ) ? $held : [ 'total' => 0, 'messages' => [] ];
        $status = [
            'on'         => $config !== null,
            'active'     => $config !== null && ( ! empty( $config['pending'] ) || self::address( $config['home'] ?? '' ) === self::address( self::current_home() ) ),
            'pending'    => ! empty( $config['pending'] ),
            'home'       => (string) ( $config['home'] ?? '' ),
            'source'     => (string) ( $config['source'] ?? '' ),
            'since'      => (int) ( $config['since'] ?? 0 ),
            'held_total' => (int) ( $held['total'] ?? 0 ),
            'blocked'    => [],
            'file'       => self::path(),
        ];
        $blocked = get_option( 'disembark_quiet_blocked' );
        foreach ( is_array( $blocked ) ? $blocked : [] as $host => $entry ) {
            $status['blocked'][] = [ 'host' => (string) $host, 'label' => (string) ( $entry['label'] ?? '' ), 'count' => (int) ( $entry['count'] ?? 0 ), 'last' => (int) ( $entry['last'] ?? 0 ) ];
        }
        if ( $with_messages ) {
            $status['held'] = [];
            foreach ( (array) ( $held['messages'] ?? [] ) as $i => $msg ) {
                $status['held'][] = [ 'index' => $i, 'time' => (int) ( $msg['time'] ?? 0 ), 'to' => (string) ( $msg['to'] ?? '' ), 'subject' => (string) ( $msg['subject'] ?? '' ) ];
            }
        }
        return $status;
    }

    /** One held email in full (index 0 is the newest), or WP_Error. */
    public static function held_message( $index ) {
        $held = get_option( 'disembark_quiet_held' );
        $msg  = is_array( $held ) ? ( $held['messages'][ (int) $index ] ?? null ) : null;
        if ( ! is_array( $msg ) ) {
            return new \WP_Error( 'not_found', 'That email is no longer in the list.', [ 'status' => 404 ] );
        }
        return $msg;
    }
}
