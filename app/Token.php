<?php

namespace Disembark;

class Token {

    public static function get() {
        $token = get_option( "disembark_token" );
        if ( empty( $token ) ) {
            $token = wp_generate_password( 42, false );
            update_option( "disembark_token", $token );
        }
        return $token;
    }

}