<?php

namespace Disembark;

/**
 * The per-site Disembark token (full file and database access). On multisite
 * the plugin acts on the whole network, so the token lives in network options:
 * a per-site option would show to every subsite admin on wp-admin/options.php.
 */
class Token {

    const OPTION = 'disembark_token';

    public static function get() {
        $token = self::read();
        if ( empty( $token ) && is_multisite() ) {
            // Adopt the main site's pre-2.9 token so existing connections keep working.
            $legacy = get_blog_option( get_main_site_id(), self::OPTION );
            if ( ! empty( $legacy ) ) {
                self::set( $legacy );
                delete_blog_option( get_main_site_id(), self::OPTION );
                return $legacy;
            }
        }
        if ( empty( $token ) ) {
            $token = wp_generate_password( 42, false );
            self::set( $token );
        }
        return $token;
    }

    /**
     * Replaces the token with a new one and returns it. The old token stops
     * working everywhere, including the copies restores keep so their
     * remaining steps and rollbacks stay authorized (token guards).
     */
    public static function regenerate() {
        $token = wp_generate_password( 42, false );
        self::set( $token );
        if ( method_exists( '\Disembark\Import', 'clear_token_guards' ) ) {
            \Disembark\Import::clear_token_guards();
        }
        return $token;
    }

    /**
     * Reads the stored token without generating one; false if none.
     */
    public static function read() {
        return is_multisite() ? get_site_option( self::OPTION ) : get_option( self::OPTION );
    }

    /**
     * Writes the token straight past any cached copy. A raw SQL import changes
     * the row behind the object cache's back, so update_option() alone could
     * see a stale "unchanged" value and skip the write.
     */
    public static function set( $token ) {
        if ( is_multisite() ) {
            wp_cache_delete( get_current_network_id() . ':' . self::OPTION, 'site-options' );
            wp_cache_delete( get_current_network_id() . ':notoptions', 'site-options' );
            return update_site_option( self::OPTION, $token );
        }
        wp_cache_delete( self::OPTION, 'options' );
        wp_cache_delete( 'alloptions', 'options' );
        wp_cache_delete( 'notoptions', 'options' );
        return update_option( self::OPTION, $token );
    }
}
