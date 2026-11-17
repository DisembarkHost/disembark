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
        if ( isset( $request['token'] ) && self::token_valid( (string) $request['token'], isset( $request['import_id'] ) ? (string) $request['import_id'] : '' ) ) {
            return true;
        }
        // Artificial delay on failure to slow down brute force scripts
        sleep(1);
        return false;
    }

}
