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

    /**
     * Who to notify, with the webhook URLs (a Slack or Discord webhook is a
     * secret). A .php file that exits first, so a direct request for it under
     * uploads shows nothing. $legacy: the notify.json older versions wrote.
     */
    private static function file( $import_id, $legacy = false ) {
        $import_id = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $import_id ) );
        return $import_id === '' ? '' : Storage::base_dir() . "/_import_{$import_id}/notify." . ( $legacy ? 'json' : 'php' );
    }

    private static function write( $import_id, $data ) {
        if ( Import::write_protected( self::file( $import_id ), (string) wp_json_encode( $data ) ) ) {
            @unlink( self::file( $import_id, true ) );
        }
    }

    /**
     * Moves a notify.json an older build wrote (a .json with the exit line
     * in front, which a web server serves as text, webhook URLs and all)
     * to notify.php, and deletes it. Safe to run again: the .php is written
     * whole first, and a .json beside a .php is only the leftover of a run
     * that stopped. True when no notify.json is left.
     */
    public static function protect_legacy( $import_id ) {
        $old = self::file( $import_id, true );
        if ( $old === '' || ! is_file( $old ) ) {
            return true;
        }
        $new = self::file( $import_id );
        if ( ! is_file( $new ) ) {
            $raw = (string) file_get_contents( $old );
            if ( strpos( $raw, '<?php' ) === 0 ) {
                $raw = (string) substr( $raw, (int) strpos( $raw, "\n" ) + 1 );
            }
            if ( ! is_array( json_decode( $raw, true ) ) || ! Import::write_protected( $new, $raw ) ) {
                return false;
            }
        }
        return @unlink( $old );
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
            @unlink( self::file( $import_id, true ) );
            return [ 'ok' => true, 'targets' => $targets ];
        }
        self::write( $import_id, [ 'targets' => $targets, 'sent' => [] ] );
        return [ 'ok' => true, 'targets' => $targets ];
    }

    private static function read( $import_id ) {
        $file = self::file( $import_id );
        if ( $file !== '' && ! is_file( $file ) ) {
            $file = self::file( $import_id, true ); // set by an older version mid-move
        }
        if ( $file === '' || ! is_file( $file ) ) {
            return null;
        }
        $raw  = (string) file_get_contents( $file );
        if ( strpos( $raw, '<?php' ) === 0 ) {
            $raw = (string) substr( $raw, (int) strpos( $raw, "\n" ) + 1 );
        }
        $data = json_decode( $raw, true );
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
        self::write( $import_id, $data );
        return [ 'sent' => true, 'results' => $results ];
    }

    /**
     * Sends a notice directly to $to without creating an import folder on disk
     * (for refusals that happen before a move starts or is confirmed).
     */
    public static function send_to( $to, $event, $details = [] ) {
        $targets = self::parse( $to );
        if ( is_wp_error( $targets ) || ( ! $targets['emails'] && ! $targets['webhooks'] ) ) {
            return [ 'sent' => false, 'reason' => 'no one to notify' ];
        }
        $event = in_array( $event, [ 'finished', 'failed', 'cancelled' ], true ) ? $event : 'finished';
        list( $subject, $text ) = self::message( $event, (array) $details );
        $results = [];
        foreach ( $targets['emails'] as $email ) {
            $results[ $email ] = self::email( $email, $subject, $text );
        }
        foreach ( $targets['webhooks'] as $url ) {
            $results[ $url ] = self::webhook( $url, $event, $text, (array) $details );
        }
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
        // wp_safe_remote_post: a webhook URL can't point at this server or
        // the private network behind it (and redirects are checked too).
        $response = wp_safe_remote_post( $url, [ 'timeout' => 15, 'headers' => [ 'Content-Type' => 'application/json' ], 'body' => wp_json_encode( $body ) ] );
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
