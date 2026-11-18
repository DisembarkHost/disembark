<?php

namespace Disembark;

/**
 * Makes each step of a move safe to retry. The dashboard sends a request_id
 * with every step and resends the same id when a request is lost (a network
 * switch, a gateway timeout). The server then:
 *
 * - runs the step at most once at a time: the running request holds an flock
 *   on the step's lock file, and a retry that arrives meanwhile waits for it
 *   instead of starting the same work twice;
 * - remembers a finished step's result, so a retry gets that answer back
 *   rather than doing the work again;
 * - keeps running when the browser goes away (ignore_user_abort).
 *
 * The lock is released by the OS if PHP dies, so a crashed step can be run
 * again. Failed steps aren't remembered: a retry runs them again.
 *
 * Records live with the import (uploads/disembark/_import_<id>/steps) as
 * .php files that exit before their JSON, so the web server never serves
 * them.
 */
class Steps {

    /** How long a retry waits for the running copy before answering "still running". */
    const WAIT_SECONDS = 25;

    /** Routes that take part: every import step, and quiet mode (turned on mid-move). */
    public static function covers( $route ) {
        if ( in_array( $route, [ '/disembark/v1/import/resume', '/disembark/v1/import/unfinished', '/disembark/v1/import/last-pulls' ], true ) ) {
            return false;
        }
        return strpos( $route, '/disembark/v1/import/' ) === 0 || $route === '/disembark/v1/quiet-mode';
    }

    /**
     * rest_dispatch_request filter: runs a step that carries a request_id
     * under its lock, or replays its result.
     */
    public static function dispatch( $result, $request, $route, $handler ) {
        if ( $result !== null || $request->get_method() !== 'POST' || ! self::covers( $route ) ) {
            return $result;
        }
        $request_id = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $request->get_param( 'request_id' ) ) );
        $import_id  = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $request->get_param( 'import_id' ) ) );
        if ( strlen( $request_id ) < 16 || $import_id === '' || empty( $handler['callback'] ) || ! is_callable( $handler['callback'] ) ) {
            return $result;
        }
        // Never replay a result to someone who couldn't run the step. The
        // callback checks again; this only guards the replay.
        if ( ! User::allowed( $request ) ) {
            return $result;
        }
        return self::run( $import_id, $request_id, function () use ( $handler, $request ) {
            return call_user_func( $handler['callback'], $request );
        } );
    }

    public static function run( $import_id, $request_id, callable $work ) {
        $dir = Storage::base_dir() . "/_import_{$import_id}/steps";
        Storage::ensure_dir( $dir );
        $lock_file   = "{$dir}/{$request_id}.lock";
        $result_file = "{$dir}/{$request_id}.php";

        $lock = @fopen( $lock_file, 'c' );
        if ( ! $lock ) {
            // No lock possible (read-only uploads?): behave as before.
            return $work();
        }
        $deadline = time() + self::WAIT_SECONDS;
        while ( ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
            if ( time() >= $deadline ) {
                fclose( $lock );
                return new \WP_REST_Response( [ 'code' => 'disembark_step_running', 'running' => true, 'message' => 'This step is still running on the server.' ], 202 );
            }
            sleep( 1 );
        }

        $saved = self::saved( $result_file );
        if ( $saved !== null ) {
            flock( $lock, LOCK_UN );
            fclose( $lock );
            return new \WP_REST_Response( $saved['data'], (int) $saved['status'] );
        }

        // Finish the step even if the browser that asked for it is gone.
        ignore_user_abort( true );
        $response = $work();

        if ( ! is_wp_error( $response ) ) {
            $response = rest_ensure_response( $response );
            if ( $response->get_status() < 400 ) {
                $body = wp_json_encode( [ 'status' => $response->get_status(), 'data' => $response->get_data() ] );
                if ( is_string( $body ) ) {
                    $tmp = $result_file . '.tmp';
                    if ( @file_put_contents( $tmp, "<?php exit; ?>\n" . $body ) !== false ) {
                        @rename( $tmp, $result_file );
                    }
                }
            }
        }
        flock( $lock, LOCK_UN );
        fclose( $lock );
        return $response;
    }

    /**
     * Every finished step of an import: { request_id: response data }. A
     * resumed move replays these instead of asking for them one by one.
     */
    public static function results( $import_id ) {
        $out = [];
        foreach ( (array) glob( Storage::base_dir() . "/_import_{$import_id}/steps/*.php" ) as $file ) {
            if ( basename( $file ) === 'index.php' ) {
                continue;
            }
            $saved = self::saved( $file );
            if ( $saved !== null && (int) $saved['status'] < 300 ) {
                $out[ basename( $file, '.php' ) ] = $saved['data'];
            }
        }
        return $out;
    }

    private static function saved( $file ) {
        if ( ! is_file( $file ) ) {
            return null;
        }
        $raw   = (string) file_get_contents( $file );
        $start = strpos( $raw, "\n" );
        $saved = $start === false ? null : json_decode( substr( $raw, $start + 1 ), true );
        return is_array( $saved ) && isset( $saved['status'] ) && array_key_exists( 'data', $saved ) ? $saved : null;
    }
}
