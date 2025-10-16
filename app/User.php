<?php

namespace Disembark;

class User {

    public static function allowed( $request ) {
        if ( isset($request['token']) && $request['token'] == Token::get() ) {
            return true;
        }
        return false;
    }

}