<?php

namespace Disembark;

/**
 * Saved "What to bring" choices, reusable on the next move. Each template is
 * { name, suggestions: [ids], paths: [extra paths], rows: [extra table names
 * without the prefix], saved }.
 *
 * Kept in a file (uploads/disembark/templates.php), not the database: a pull
 * replaces this site's database, and the templates should outlive it. The
 * file exits before its JSON so the web server never serves it.
 */
class Templates {

    private static function file() {
        return Storage::base_dir() . '/templates.php';
    }

    public static function all() {
        $file = self::file();
        if ( ! is_file( $file ) ) {
            return [];
        }
        $raw  = (string) file_get_contents( $file );
        $nl   = strpos( $raw, "\n" );
        $list = $nl === false ? null : json_decode( substr( $raw, $nl + 1 ), true );
        return is_array( $list ) ? array_values( $list ) : [];
    }

    public static function find( $name ) {
        foreach ( self::all() as $t ) {
            if ( strcasecmp( $t['name'], (string) $name ) === 0 ) {
                return $t;
            }
        }
        return null;
    }

    public static function save( $template ) {
        $name = trim( sanitize_text_field( (string) ( $template['name'] ?? '' ) ) );
        if ( $name === '' ) {
            return new \WP_Error( 'missing_name', 'Give the template a name.', [ 'status' => 400 ] );
        }
        $clean = function ( $list ) {
            return array_values( array_unique( array_filter( array_map( function ( $v ) { return trim( (string) $v, " /\t\n" ); }, (array) $list ) ) ) );
        };
        $entry = [
            'name'        => function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 80, 'UTF-8' ) : substr( $name, 0, 80 ),
            'suggestions' => $clean( $template['suggestions'] ?? [] ),
            'paths'       => $clean( $template['paths'] ?? [] ),
            'rows'        => $clean( $template['rows'] ?? [] ),
            'saved'       => time(),
        ];
        $list = array_values( array_filter( self::all(), function ( $t ) use ( $entry ) {
            return strcasecmp( $t['name'], $entry['name'] ) !== 0;
        } ) );
        $list[] = $entry;
        return self::write( $list ) ? $list : new \WP_Error( 'write_failed', 'Couldn\'t save the template in the uploads folder.', [ 'status' => 500 ] );
    }

    public static function delete( $name ) {
        $list = array_values( array_filter( self::all(), function ( $t ) use ( $name ) {
            return strcasecmp( $t['name'], (string) $name ) !== 0;
        } ) );
        return self::write( $list ) ? $list : new \WP_Error( 'write_failed', 'Couldn\'t update the templates file.', [ 'status' => 500 ] );
    }

    private static function write( $list ) {
        Storage::ensure_dir( Storage::base_dir() );
        $json = wp_json_encode( array_values( $list ) );
        if ( ! is_string( $json ) ) {
            return false;
        }
        $tmp = self::file() . '.tmp';
        if ( @file_put_contents( $tmp, "<?php exit; ?>\n" . $json ) === false ) {
            @unlink( $tmp );
            return false;
        }
        if ( ! @rename( $tmp, self::file() ) ) {
            @unlink( $tmp );
            return false;
        }
        return true;
    }
}
