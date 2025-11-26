<?php

namespace Disembark;

class User {

    public static function allowed( $request ) {
        if ( isset($request['token']) && hash_equals( Token::get(), $request['token'] ) ) {
            return true;
        }
        // Artificial delay on failure to slow down brute force scripts
        sleep(1); 
        return false;
    }

}