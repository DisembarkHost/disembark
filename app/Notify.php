<?php

namespace Disembark;

/**
 * Tells someone when a long move finishes or stops: an email, a webhook, or
 * both (Slack and Discord webhooks get a message they display; any other URL
 * gets the JSON). Set per move, so it survives the move replacing this site's
 * database, and sent at most once per outcome.
 *
 * A copy in quiet mode holds every wp_mail(). This notice is for the person
 * running the move, not a customer, so it goes out through PHP's mail()
 * directly, around quiet mode and around SMTP plugins configured for the
 * source. Off a quiet copy it goes through wp_mail() like any other email.
 */
class Notify {

    private static function file( $import_id ) {
        $import_id = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $import_id ) );
        return $import_id === '' ? '' : Storage::base_dir() . "/_import_{$import_id}/notify.json";
    }

    /** Parses "a@example.com, https://hooks.slack.com/…" into emails and webhook URLs. */
    public static function parse( $to ) {
        $out = [ 'emails' => [], 'webhooks' => [] ];
        foreach ( preg_split( '/[\s,;]+/', (string) $to ) as $item ) {
            $item = trim( $item );
            if ( $item === '' ) {
                continue;
            }
            if ( preg_match( '#^https://#i', $item ) && wp_http_validate_url( $item ) ) {
                $out['webhooks'][] = esc_url_raw( $item );
            } elseif ( is_email( $item ) ) {
                $out['emails'][] = sanitize_email( $item );
            } else {
                return new \WP_Error( 'bad_target', "Not an email address or an https:// webhook: {$item}", [ 'status' => 400 ] );
            }
        }
        return $out;
    }

    public static function set( $import_id, $to ) {
        $file = self::file( $import_id );
        if ( $file === '' ) {
            return new \WP_Error( 'missing_import_id', 'import_id is required.', [ 'status' => 400 ] );
        }
        $targets = self::parse( $to );
        if ( is_wp_error( $targets ) ) {
            return $targets;
        }
        Storage::ensure_dir( dirname( $file ) );
        if ( ! $targets['emails'] && ! $targets['webhooks'] ) {
            @unlink( $file );
            return [ 'ok' => true, 'targets' => $targets ];
        }
        file_put_contents( $file, "<?php exit; ?>\n" . wp_json_encode( [ 'targets' => $targets, 'sent' => [] ] ) );
        return [ 'ok' => true, 'targets' => $targets ];
    }

    private static function read( $import_id ) {
        $file = self::file( $import_id );
        if ( $file === '' || ! is_file( $file ) ) {
            return null;
        }
        $raw  = (string) file_get_contents( $file );
        $data = json_decode( (string) substr( $raw, (int) strpos( $raw, "\n" ) + 1 ), true );
        return is_array( $data ) ? $data : null;
    }

    /**
     * Sends the notice for $event ("finished", "failed" or "cancelled").
     * $details: kind (pull, push, restore), source, destination, error, step,
     * rollback_id, resume, seconds, lines (extra lines for the message).
     */
    public static function send( $import_id, $event, $details = [] ) {
        $data = self::read( $import_id );
        if ( ! $data ) {
            return [ 'sent' => false, 'reason' => 'no one to notify' ];
        }
        $event = in_array( $event, [ 'finished', 'failed', 'cancelled' ], true ) ? $event : 'finished';
        if ( in_array( $event, (array) ( $data['sent'] ?? [] ), true ) ) {
            return [ 'sent' => false, 'reason' => 'already sent' ];
        }
        list( $subject, $text ) = self::message( $event, (array) $details );
        $results = [];
        foreach ( (array) ( $data['targets']['emails'] ?? [] ) as $email ) {
            $results[ $email ] = self::email( $email, $subject, $text );
        }
        foreach ( (array) ( $data['targets']['webhooks'] ?? [] ) as $url ) {
            $results[ $url ] = self::webhook( $url, $event, $text, (array) $details );
        }
        $data['sent'][]           = $event;
        $data['results'][ $event ] = $results;
        file_put_contents( self::file( $import_id ), "<?php exit; ?>\n" . wp_json_encode( $data ) );
        return [ 'sent' => true, 'results' => $results ];
    }

    private static function message( $event, $d ) {
        $kind  = ucfirst( (string) ( $d['kind'] ?? 'move' ) );
        $from  = (string) ( $d['source'] ?? '' );
        $to    = (string) ( $d['destination'] ?? home_url() );
        $what  = $kind . ( $from !== '' ? ' from ' . self::host( $from ) : '' ) . ' into ' . self::host( $to );
        $lines = [];
        if ( $event === 'finished' ) {
            $subject = "✓ {$what} finished";
            $lines[] = "{$what} finished" . ( ! empty( $d['seconds'] ) ? ' in ' . self::duration( (int) $d['seconds'] ) : '' ) . '.';
        } elseif ( $event === 'cancelled' ) {
            $subject = "{$what} was cancelled";
            $lines[] = "{$what} was cancelled.";
        } else {
            $subject = "✗ {$what} stopped";
            $lines[] = "{$what} stopped" . ( ! empty( $d['step'] ) ? ' at the ' . $d['step'] . ' step' : '' ) . '.';
            if ( ! empty( $d['error'] ) ) {
                $lines[] = 'Error: ' . $d['error'];
            }
        }
        foreach ( (array) ( $d['lines'] ?? [] ) as $line ) {
            $lines[] = (string) $line;
        }
        if ( $event === 'failed' && ! empty( $d['resume'] ) ) {
            $lines[] = 'Resume: ' . $d['resume'];
        }
        if ( ! empty( $d['rollback_id'] ) ) {
            $lines[] = 'Undo: wp disembark rollback ' . $d['rollback_id'] . ' (or Tools → Disembark → Restore → Restore points on ' . self::host( $to ) . ')';
        }
        $lines[] = '';
        $lines[] = '— Disembark on ' . self::host( home_url() );
        return [ $subject, implode( "\n", $lines ) ];
    }

    private static function email( $to, $subject, $text ) {
        $quiet = class_exists( 'Disembark_Quiet_Mode', false ) && \Disembark_Quiet_Mode::active();
        if ( ! $quiet ) {
            return (bool) wp_mail( $to, $subject, $text );
        }
        if ( ! class_exists( '\PHPMailer\PHPMailer\PHPMailer', false ) ) {
            require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
            require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
        }
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer( true );
            $mail->isMail();
            $mail->CharSet = 'UTF-8';
            $host = preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
            $mail->setFrom( 'wordpress@' . $host, 'Disembark' );
            $mail->addAddress( $to );
            $mail->Subject = $subject;
            $mail->Body    = $text;
            return $mail->send();
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    private static function webhook( $url, $event, $text, $details ) {
        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        if ( $host === 'hooks.slack.com' ) {
            $body = [ 'text' => $text ];
        } elseif ( in_array( $host, [ 'discord.com', 'discordapp.com' ], true ) ) {
            $body = [ 'content' => substr( $text, 0, 1900 ) ];
        } else {
            $body = [ 'event' => 'disembark.move.' . $event, 'text' => $text, 'site' => home_url(), 'details' => $details ];
        }
        $response = wp_remote_post( $url, [ 'timeout' => 15, 'sslverify' => ! ( defined( 'DISEMBARK_DEV_MODE' ) && DISEMBARK_DEV_MODE ), 'headers' => [ 'Content-Type' => 'application/json' ], 'body' => wp_json_encode( $body ) ] );
        if ( is_wp_error( $response ) ) {
            return $response->get_error_message();
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        return $code < 300 ? true : "HTTP {$code}";
    }

    private static function host( $url ) {
        $host = (string) wp_parse_url( (string) $url, PHP_URL_HOST );
        return $host !== '' ? $host : (string) $url;
    }

    private static function duration( $seconds ) {
        if ( $seconds < 90 ) {
            return $seconds . ' seconds';
        }
        if ( $seconds < 5400 ) {
            return round( $seconds / 60 ) . ' minutes';
        }
        return floor( $seconds / 3600 ) . 'h ' . round( ( $seconds % 3600 ) / 60 ) . 'm';
    }
}
