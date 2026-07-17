<?php

namespace Disembark;

class User {

    public static function allowed( $request ) {
        // Preferred: a real WordPress user with the capability to manage the
        // site. This is true when the request authenticated as an administrator
        // via an application password (Basic Auth) or via the admin cookie plus
        // a valid REST nonce. Access follows the user's capabilities.
        if ( current_user_can( 'manage_options' ) ) {
            return true;
        }
        // Fallback: the per-site Disembark token. Kept for restrictive hosts
        // that strip the Authorization header, where application passwords can't
        // be used. See User::allowed() notes in the readme.
        if ( isset( $request['token'] ) && hash_equals( Token::get(), (string) $request['token'] ) ) {
            return true;
        }
        // Artificial delay on failure to slow down brute force scripts
        sleep(1);
        return false;
    }

}