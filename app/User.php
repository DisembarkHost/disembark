<?php

namespace Disembark;

class User {

    public static function allowed( $request ) {
        if ( isset($request['token']) && hash_equals( Token::get(), $request['token'] ) ) {
            return true;
        }
        return false;
    }

}