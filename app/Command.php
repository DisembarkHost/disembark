<?php

namespace Disembark;

class Command {
    
    public function token( $args, $assoc_args ) {
        if ( isset( $assoc_args['generate'] ) ) {
            $token = wp_generate_password( 42, false );
            update_option( "disembark_token", $token );
            \WP_CLI::success( "New token generated and saved." );
        }
        $token = Token::get();
        \WP_CLI::log( $token );
    }

    public function cli_info( $args, $assoc_args ) {
        $token    = Token::get();
        $home_url = home_url();
        \WP_CLI::log( "disembark connect $home_url $token" );
    }

}