<?php

namespace Disembark;

class User {

    /**
     * Who may use Disembark as a logged-in user. Disembark reads and writes
     * every table and file of the install, which on multisite is the whole
     * network, so there it takes a super admin; a subsite admin's
     * manage_options isn't enough.
     */
    public static function can_manage() {
        if ( is_multisite() ) {
            return is_super_admin();
        }
        return current_user_can( 'manage_options' );
    }

    /**
     * The capability for the Tools → Disembark menu entry.
     */
    public static function menu_capability() {
        return is_multisite() ? 'manage_network_options' : 'manage_options';
    }

    /**
     * Whether the file editor (save / rename) may write. Honors WordPress's
     * own switches for its theme and plugin editors.
     */
    public static function file_edits_allowed() {
        if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
            return false;
        }
        if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
            return false;
        }
        return true;
    }

    /**
     * True when $token is the site's Disembark token, or, during a restore,
     * the token the destination had when that import began (see
     * Import::token_guard_matches()).
     */
    public static function token_valid( $token, $import_id = '' ) {
        if ( ! is_string( $token ) || $token === '' ) {
            return false;
        }
        if ( hash_equals( (string) Token::get(), $token ) ) {
            return true;
        }
        return $import_id !== '' && Import::token_guard_matches( (string) $import_id, $token );
    }

    /** The header the dashboard sends the token in (kept out of URLs and logs). */
    const TOKEN_HEADER = 'X-Disembark-Token';

    /**
     * The Disembark token a request carries: the token parameter (body or
     * query, as the CLI and other sites send it) or the X-Disembark-Token
     * header (the dashboard). '' when there is none.
     */
    public static function request_token( $request = null ) {
        if ( $request !== null && isset( $request['token'] ) && is_string( $request['token'] ) && $request['token'] !== '' ) {
            return $request['token'];
        }
        $header = $request instanceof \WP_REST_Request ? (string) $request->get_header( 'x_disembark_token' ) : '';
        if ( $header === '' && isset( $_SERVER['HTTP_X_DISEMBARK_TOKEN'] ) && is_string( $_SERVER['HTTP_X_DISEMBARK_TOKEN'] ) ) {
            $header = $_SERVER['HTTP_X_DISEMBARK_TOKEN'];
        }
        return trim( $header );
    }

    public static function allowed( $request ) {
        // Preferred: a real WordPress user with the capability to manage the
        // site. This is true when the request authenticated as an administrator
        // via an application password (Basic Auth) or via the admin cookie plus
        // a valid REST nonce. Access follows the user's capabilities.
        if ( self::can_manage() ) {
            return true;
        }
        // Fallback: the per-site Disembark token. Kept for restrictive hosts
        // that strip the Authorization header, where application passwords can't
        // be used. See User::allowed() notes in the readme.
        $token = self::request_token( $request );
        if ( $token !== '' && self::token_valid( $token, isset( $request['import_id'] ) && is_string( $request['import_id'] ) ? $request['import_id'] : '' ) ) {
            return true;
        }
        // No delay on failure: a sleep() here held a PHP worker for every
        // refused request, so anyone could tie the site up, and a 42-character
        // random token can't be guessed anyway.
        return false;
    }

}
