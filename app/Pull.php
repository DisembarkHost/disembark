<?php

namespace Disembark;

/**
 * Server-to-server "pull" restore: the destination fetches a backup directly
 * from a live source site's Disembark REST API (the same endpoints the CLI
 * uses), stages it, and hands off to the Import pipeline. Browser-driven in
 * short steps so no single request has to transfer a whole site.
 *
 * State for a pull lives in the import directory as pull.json. Its secrets
 * (the source's full-access token, and the session folder name that its
 * exports sit under on the source) are kept apart in pull-secret.php, which
 * the web server never serves (see Import::write_protected()).
 */
class Pull {

    private $import;
    private $import_id;
    private $staging_public;
    private $state_file;
    private $secret_file;

    /** State keys that only ever live in pull-secret.php. */
    const SECRETS = [ 'source_token', 'backup_token' ];

    public function __construct( $import_id = '' ) {
        $this->import         = new Import( $import_id );
        $this->import_id      = $this->import->get_import_id();
        $base                 = Storage::base_dir() . '/_import_' . $this->import_id;
        $this->staging_public = $base . '/staging/public';
        $this->state_file     = $base . '/pull.json';
        $this->secret_file    = $base . '/pull-secret.php';
    }

    /** Writes JSON-encoded $data behind the exit line, leaving any existing file intact if encoding fails. */
    private static function write_protected_json( $file, $data ) {
        $encoded = wp_json_encode( $data );
        if ( ! is_string( $encoded ) ) {
            return false;
        }
        return Import::write_protected( $file, $encoded );
    }

    public function get_import_id() {
        return $this->import_id;
    }

    private function state() {
        $s = file_exists( $this->state_file ) ? json_decode( (string) file_get_contents( $this->state_file ), true ) : [];
        $s = is_array( $s ) ? $s : [];
        // A pull started by an older build kept them in pull.json; the next
        // save moves them.
        $secret = json_decode( (string) Import::read_protected( $this->secret_file ), true );
        if ( is_array( $secret ) ) {
            $s = array_merge( $s, array_intersect_key( $secret, array_flip( self::SECRETS ) ) );
        }
        return $s;
    }

    private function save_state( $state ) {
        Storage::ensure_dir( dirname( $this->state_file ) );
        $secret = array_intersect_key( (array) $state, array_flip( self::SECRETS ) );
        if ( $secret ) {
            self::write_protected_json( $this->secret_file, $secret );
        } else {
            @unlink( $this->secret_file );
        }
        $encoded = wp_json_encode( array_diff_key( (array) $state, $secret ) );
        if ( is_string( $encoded ) ) {
            file_put_contents( $this->state_file, $encoded );
        }
    }

    /**
     * The id the source is told for this pull (its preflight takes one).
     * Never this site's import id: that names the staging folder here, which
     * a source must not be able to find.
     */
    private function source_session() {
        $state = $this->state();
        if ( empty( $state['session'] ) || ! preg_match( '/^[a-f0-9]{24,64}$/', (string) $state['session'] ) ) {
            $state['session'] = bin2hex( random_bytes( 16 ) );
            $this->save_state( $state );
        }
        return $state['session'];
    }

    /**
     * Whether to verify TLS certificates (and reject internal/unsafe URLs) on
     * the pull channel. Defaults ON — this channel carries the source's
     * full-access token and pulls executable files and SQL, so a MITM here is
     * code execution on the destination. Opt out for known self-signed or
     * local sources via DISEMBARK_DEV_MODE or the disembark_pull_sslverify
     * filter.
     */
    private function sslverify() {
        $verify = ! ( defined( 'DISEMBARK_DEV_MODE' ) && DISEMBARK_DEV_MODE );
        return (bool) apply_filters( 'disembark_pull_sslverify', $verify );
    }

    /**
     * URL of one of the source's Disembark REST endpoints, honoring its
     * permalink style.
     */
    private function source_endpoint( $endpoint ) {
        $state = $this->state();
        $base  = rtrim( $state['source_url'], '/' );
        $style = $state['rest_style'] ?? 'pretty';
        return $base . ( ( $style === 'rest_route' )
            ? '/?rest_route=/disembark/v1' . $endpoint
            : '/wp-json/disembark/v1' . $endpoint );
    }

    /**
     * A GET whose parameters (the token among them) ride in a JSON body: a
     * POST the source's REST server routes as GET. Falls back to a plain GET
     * with the query string only when the override was lost on the way: the
     * source's REST API then has no POST route there and says so
     * (rest_no_route). Any other answer, a 404 or 405 included, is the
     * source's real one, and the token never goes into a URL for it.
     */
    private function get_with_body( $url, $args ) {
        $args['headers']['X-HTTP-Method-Override'] = 'GET';
        $resp = $this->http( 'POST', $url, $args );
        if ( is_wp_error( $resp ) || (int) wp_remote_retrieve_response_code( $resp ) !== 404 ) {
            return $resp;
        }
        $error = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
        if ( ! is_array( $error ) || ( $error['code'] ?? '' ) !== 'rest_no_route' ) {
            return $resp;
        }
        $query = (array) json_decode( (string) $args['body'], true );
        unset( $args['body'], $args['headers']['X-HTTP-Method-Override'] );
        return $this->http( 'GET', $url . ( strpos( $url, '?' ) !== false ? '&' : '?' ) . http_build_query( $query ), $args );
    }

    /** Largest answer the source's API may send (file downloads have their own limit). */
    const MAX_RESPONSE_BYTES = 67108864;

    /**
     * Every request this pull makes to the source. With verification on
     * (see sslverify()): TLS checked, and the address must be a public one,
     * every hop. WordPress's own check misses link-local (169.254/16, cloud
     * metadata) and shared (100.64/10) addresses, and resolves the name again
     * when connecting, so a name that answers differently the second time
     * (DNS rebinding) could still reach an internal service. So each host is
     * resolved and checked here, cURL is pinned to the checked address, and
     * every redirect is checked the same way. The answer is capped in size.
     */
    private function http( $method, $url, $args = [] ) {
        $verify                     = $this->sslverify();
        $args['method']             = $method;
        $args['sslverify']          = $verify;
        $args['reject_unsafe_urls'] = $verify;
        if ( empty( $args['limit_response_size'] ) ) {
            $args['limit_response_size'] = self::MAX_RESPONSE_BYTES;
        }
        if ( ! $verify ) {
            return wp_remote_request( $url, $args );
        }
        $ip = self::public_address( $url );
        if ( is_wp_error( $ip ) ) {
            return $ip;
        }
        $current = [ 'url' => $url, 'ip' => $ip ];
        $pin     = function ( $handle ) use ( &$current ) {
            $parts = wp_parse_url( $current['url'] );
            $port  = (int) ( $parts['port'] ?? ( strtolower( (string) ( $parts['scheme'] ?? '' ) ) === 'https' ? 443 : 80 ) );
            $host  = trim( (string) ( $parts['host'] ?? '' ), '[]' );
            if ( $host !== '' && defined( 'CURLOPT_RESOLVE' ) && ! filter_var( $host, FILTER_VALIDATE_IP ) ) {
                curl_setopt( $handle, CURLOPT_RESOLVE, [ "{$host}:{$port}:" . ( strpos( $current['ip'], ':' ) !== false ? '[' . $current['ip'] . ']' : $current['ip'] ) ] );
            }
        };
        $redirect = function ( &$location ) use ( &$current ) {
            $ip = self::public_address( (string) $location );
            if ( is_wp_error( $ip ) ) {
                throw self::request_exception( $ip->get_error_message() );
            }
            $current = [ 'url' => (string) $location, 'ip' => $ip ];
        };
        add_action( 'http_api_curl', $pin, 99 );
        add_action( 'requests-requests.before_redirect', $redirect, 10, 1 );
        try {
            $resp = wp_remote_request( $url, $args );
        } finally {
            remove_action( 'http_api_curl', $pin, 99 );
            remove_action( 'requests-requests.before_redirect', $redirect, 10 );
        }
        return $resp;
    }

    /**
     * A request that timed out or whose connection dropped (cURL 7, 18, 28,
     * 52, 55, 56): the source may answer the next one. Never a refused
     * address, which stays an error.
     */
    private static function dropped( $error ) {
        return is_wp_error( $error ) && $error->get_error_code() === 'http_request_failed'
            && (bool) preg_match( '/cURL error (?:7|18|28|52|55|56):|timed out/i', $error->get_error_message() );
    }

    /** The exception WordPress turns into a WP_Error when thrown inside a request. */
    private static function request_exception( $message ) {
        if ( class_exists( '\WpOrg\Requests\Exception' ) ) {
            return new \WpOrg\Requests\Exception( $message, 'disembark_unsafe_url' );
        }
        return new \Requests_Exception( $message, 'disembark_unsafe_url' );
    }

    /**
     * The address $url's host resolves to, when every address it resolves to
     * is public; else WP_Error. A host a site allows with WordPress's
     * http_request_host_is_external filter is let through as it is there.
     */
    private static function public_address( $url ) {
        $host = trim( strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) ), '[]' );
        if ( $host === '' || ! preg_match( '#^https?://#i', (string) $url ) ) {
            return new \WP_Error( 'unsafe_url', 'Refusing a non-http(s) address from the source.', [ 'status' => 502 ] );
        }
        if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
            $ips = [ $host ];
        } else {
            $ips = (array) @gethostbynamel( $host );
            if ( function_exists( 'dns_get_record' ) ) {
                foreach ( (array) @dns_get_record( $host, DNS_AAAA ) as $record ) {
                    if ( ! empty( $record['ipv6'] ) ) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }
            $ips = array_values( array_filter( $ips ) );
        }
        if ( ! $ips ) {
            return new \WP_Error( 'unsafe_url', "Could not resolve {$host}.", [ 'status' => 502 ] );
        }
        if ( apply_filters( 'http_request_host_is_external', false, $host, $url ) ) {
            return $ips[0];
        }
        foreach ( $ips as $ip ) {
            if ( self::internal_ip( $ip ) ) {
                return new \WP_Error( 'unsafe_url', "Refusing to connect to {$host}: it points at an internal address ({$ip}).", [ 'status' => 502 ] );
            }
        }
        return $ips[0];
    }

    /** Whether $url's host is on this computer or a private network by its name or address (localhost, *.local, *.test, a private IP). */
    public static function local_address( $url ) {
        $host = trim( strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) ), '[]' );
        if ( $host === '' ) {
            return false;
        }
        if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
            return self::internal_ip( $host );
        }
        return $host === 'localhost' || (bool) preg_match( '/\.(?:localhost|local|test|internal|lan|home\.arpa)$/', $host );
    }

    /** Whether a 4-byte IPv4 address is private, loopback, link-local, shared (CGNAT), reserved or multicast. */
    private static function internal_ipv4_bytes( $bin ) {
        $o = array_values( unpack( 'C4', $bin ) );
        return $o[0] === 0                                                // 0.0.0.0/8
            || $o[0] === 10                                               // 10.0.0.0/8
            || ( $o[0] === 100 && $o[1] >= 64 && $o[1] <= 127 )           // 100.64.0.0/10 (CGNAT)
            || $o[0] === 127                                              // 127.0.0.0/8 (loopback)
            || ( $o[0] === 169 && $o[1] === 254 )                         // 169.254.0.0/16 (link-local)
            || ( $o[0] === 172 && $o[1] >= 16 && $o[1] <= 31 )            // 172.16.0.0/12
            || ( $o[0] === 192 && $o[1] === 0 && $o[2] === 0 )            // 192.0.0.0/24
            || ( $o[0] === 192 && $o[1] === 0 && $o[2] === 2 )            // 192.0.2.0/24 (TEST-NET-1)
            || ( $o[0] === 192 && $o[1] === 168 )                         // 192.168.0.0/16
            || ( $o[0] === 198 && ( $o[1] === 18 || $o[1] === 19 ) )      // 198.18.0.0/15 (benchmarking)
            || ( $o[0] === 198 && $o[1] === 51 && $o[2] === 100 )         // 198.51.100.0/24 (TEST-NET-2)
            || ( $o[0] === 203 && $o[1] === 0 && $o[2] === 113 )          // 203.0.113.0/24 (TEST-NET-3)
            || $o[0] >= 224;                                              // 224.0.0.0/3 (multicast + reserved + broadcast)
    }

    /** Whether $ip is private, loopback, link-local, shared (CGNAT), reserved or multicast. */
    private static function internal_ip( $ip ) {
        $bin = @inet_pton( trim( (string) $ip ) );
        if ( $bin === false ) {
            return true;
        }
        if ( strlen( $bin ) === 4 ) {
            return self::internal_ipv4_bytes( $bin );
        }
        if ( strlen( $bin ) !== 16 ) {
            return true;
        }
        $b = array_values( unpack( 'C16', $bin ) );
        // Unspecified (::) and loopback (::1).
        if ( $bin === str_repeat( "\x00", 16 ) || $bin === str_repeat( "\x00", 15 ) . "\x01" ) {
            return true;
        }
        // Unique local (fc00::/7), link-local (fe80::/10), site-local (fec0::/10), multicast (ff00::/8).
        if ( ( $b[0] & 0xfe ) === 0xfc || ( $b[0] === 0xfe && ( $b[1] & 0xc0 ) === 0x80 ) || ( $b[0] === 0xfe && ( $b[1] & 0xc0 ) === 0xc0 ) || $b[0] === 0xff ) {
            return true;
        }
        // Discard-only (100::/64) and documentation (2001:db8::/32).
        if ( substr( $bin, 0, 8 ) === "\x01\x00\x00\x00\x00\x00\x00\x00" || substr( $bin, 0, 4 ) === "\x20\x01\x0d\xb8" ) {
            return true;
        }
        // Embedded IPv4: IPv4-mapped (::ffff:0:0/96), IPv4-compatible (::/96), NAT64 (64:ff9b::/96).
        $prefix12 = substr( $bin, 0, 12 );
        if ( $prefix12 === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff"
            || $prefix12 === str_repeat( "\x00", 12 )
            || $prefix12 === "\x00\x64\xff\x9b\x00\x00\x00\x00\x00\x00\x00\x00"
        ) {
            return self::internal_ipv4_bytes( substr( $bin, 12, 4 ) );
        }
        // 6to4 (2002::/16): embedded IPv4 sits in bytes 2..5.
        if ( $b[0] === 0x20 && $b[1] === 0x02 ) {
            return self::internal_ipv4_bytes( substr( $bin, 2, 4 ) );
        }
        return false;
    }

    /**
     * Firewalls in front of some sources (Patchstack on Kinsta, for one)
     * refuse any request whose body names wp-config.php, which a whole-site
     * file list always does, and the pull stalled on that batch. Once the
     * source has answered 403 and then taken the same request packed, every
     * request of this pull goes packed: its fields base64-encoded in one
     * "packed" field beside the token, unpacked by the source before routing
     * (see Run::unpack_body()). With $set, records that it works.
     */
    private function packed( $set = false ) {
        $flag = dirname( $this->state_file ) . '/packed';
        if ( $set ) {
            @file_put_contents( $flag, '1' );
        }
        return file_exists( $flag );
    }

    /** A JSON request body for the source, packed when that's needed (see packed()). */
    private function source_body( array $body, $pack = null ) {
        if ( ! ( $pack ?? $this->packed() ) ) {
            return wp_json_encode( $body );
        }
        $token = $body['token'] ?? '';
        unset( $body['token'] );
        return wp_json_encode( [ 'token' => $token, 'packed' => base64_encode( (string) wp_json_encode( $body ) ) ] );
    }

    /**
     * POSTs to the source's /stream-file, packed when needed, and packed once
     * more after a 403 (see packed()). Returns the temp path or WP_Error.
     */
    private function stream_post( array $body, $timeout ) {
        $args = [ 'timeout' => $timeout, 'headers' => [ 'Content-Type' => 'application/json' ], 'body' => $this->source_body( $body ) ];
        $tmp  = $this->download( 'POST', $this->source_endpoint( '/stream-file' ), $args );
        if ( is_wp_error( $tmp ) && ! $this->packed() && (int) ( $tmp->get_error_data()['source_status'] ?? 0 ) === 403 ) {
            $args['body'] = $this->source_body( $body, true );
            $again        = $this->download( 'POST', $this->source_endpoint( '/stream-file' ), $args );
            if ( ! is_wp_error( $again ) ) {
                $this->packed( true );
            }
            return $again;
        }
        return $tmp;
    }

    /**
     * Calls the source site's Disembark REST API, honoring its permalink style.
     *
     * The token always travels in the request body, never in a URL (where
     * access logs, CDNs and proxies on both sides keep it). A GET-only route
     * is reached with a POST that WordPress's REST server treats as a GET
     * (X-HTTP-Method-Override, a core feature every source has). Only when
     * something in between drops that header, the request is repeated the
     * old way, with the token in the query string.
     */
    private function source_request( $endpoint, $payload = [], $method = 'POST', $timeout = 120 ) {
        $state = $this->state();
        $url   = $this->source_endpoint( $endpoint );

        $body = array_merge(
            [ 'token' => $state['source_token'] ?? '', 'backup_token' => $state['backup_token'] ?? '' ],
            // One site of a network or WP Freighter install (see Scope).
            isset( $state['site'] ) && $state['site'] !== '' ? [ 'site' => (string) $state['site'] ] : [],
            // Leaving the network's super admins out (see select()).
            isset( $state['super_admins'] ) && $state['super_admins'] === false ? [ 'super_admins' => '0' ] : [],
            $payload
        );
        $args = [
            'timeout' => $timeout,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => $this->source_body( $body ),
        ];

        // A source on a cheap host fails a request now and then (a 503, a
        // dropped connection): asked again twice, after a second and two.
        $send = function () use ( $method, $url, &$args, $timeout ) {
            for ( $try = 0; ; $try++ ) {
                if ( strtoupper( $method ) === 'GET' ) {
                    $resp = $this->get_with_body( $url, $args );
                } else {
                    $resp = $this->http( 'POST', $url, $args );
                }
                $busy = ! is_wp_error( $resp ) && in_array( (int) wp_remote_retrieve_response_code( $resp ), [ 429, 503 ], true );
                if ( $try >= 2 || ! ( $busy || ( is_wp_error( $resp ) && self::dropped( $resp ) && $timeout <= 120 ) ) ) {
                    return $resp;
                }
                sleep( $try + 1 );
            }
        };
        $resp = $send();
        // Refused by a firewall in front of the source? Once more, packed.
        if ( ! $this->packed() && ! is_wp_error( $resp ) && (int) wp_remote_retrieve_response_code( $resp ) === 403 ) {
            $args['body'] = $this->source_body( $body, true );
            $again        = $send();
            if ( ! is_wp_error( $again ) && (int) wp_remote_retrieve_response_code( $again ) === 200 ) {
                $this->packed( true );
                $resp = $again;
            }
        }

        if ( is_wp_error( $resp ) ) {
            // Timed out or dropped: worth trying again, so a 504 (the
            // client paces the move to it, see Pace).
            if ( self::dropped( $resp ) ) {
                return new \WP_Error( 'source_unreachable', "The source didn't answer {$endpoint} in time (" . substr( $resp->get_error_message(), 0, 160 ) . ').', [ 'status' => 504 ] );
            }
            return $resp;
        }
        $code = wp_remote_retrieve_response_code( $resp );
        $raw  = wp_remote_retrieve_body( $resp );
        if ( $code !== 200 ) {
            // Don't echo the remote body back to the browser — it can contain
            // internal details from the source (or whatever answered for it).
            // A source that is busy (503, 429) says so to the client, which
            // waits before asking again.
            return new \WP_Error( 'source_error', "Source responded {$code} for {$endpoint}.", [ 'status' => in_array( (int) $code, [ 429, 503 ], true ) ? (int) $code : 502, 'source_status' => (int) $code ] );
        }
        $decoded = json_decode( $raw );
        // Some endpoints return a raw URL string, not JSON.
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return trim( $raw );
        }
        return $decoded;
    }

    /**
     * Downloads a URL to a temp file with the pull channel's safety posture:
     * http(s) only, unsafe/internal hosts rejected, TLS verified — unless
     * verification is opted out (see sslverify()). The URLs handed here come
     * from the source's responses, so they are untrusted input. Returns the
     * temp path or WP_Error.
     */
    private function guarded_download( $url, $timeout = 600 ) {
        if ( ! is_string( $url ) || ! preg_match( '#^https?://#i', $url ) ) {
            return new \WP_Error( 'invalid_url', 'Refusing to download a non-http(s) URL from the source.', [ 'status' => 502 ] );
        }
        if ( $this->sslverify() && ( ! wp_http_validate_url( $url ) || is_wp_error( self::public_address( $url ) ) ) ) {
            return new \WP_Error( 'invalid_url', 'Refusing to download an unsafe URL from the source.', [ 'status' => 502 ] );
        }
        return $this->download( 'GET', $url, [ 'timeout' => $timeout ] );
    }

    /**
     * Streams an answer from the source to a temp file, never more than the
     * disk here can take (the sizes the source declares aren't trusted).
     * Returns the temp path or WP_Error.
     */
    private function download( $method, $url, $args ) {
        $name = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
        $tmp  = wp_tempnam( $name !== '' ? $name : 'disembark-download' );
        if ( ! $tmp ) {
            return new \WP_Error( 'download_failed', 'Could not create a temporary file.', [ 'status' => 500 ] );
        }
        $free  = function_exists( 'disk_free_space' ) ? @disk_free_space( dirname( $tmp ) ) : false;
        $limit = $free === false ? 0 : (int) ( $free - 64 * MB_IN_BYTES );
        if ( $free !== false && $limit <= 0 ) {
            @unlink( $tmp );
            return new \WP_Error( 'disk_full', 'Not enough disk space here for the next file from the source.', [ 'status' => 507 ] );
        }
        $args += [ 'timeout' => 600 ];
        $args['stream']              = true;
        $args['filename']            = $tmp;
        $args['limit_response_size'] = $limit > 0 ? $limit : PHP_INT_MAX;
        $resp = $this->http( $method, $url, $args );
        if ( is_wp_error( $resp ) || (int) wp_remote_retrieve_response_code( $resp ) !== 200 ) {
            @unlink( $tmp );
            return is_wp_error( $resp ) ? $resp : new \WP_Error( 'download_failed', 'Source responded ' . wp_remote_retrieve_response_code( $resp ) . ' for ' . $name . '.', [ 'status' => 502, 'source_status' => (int) wp_remote_retrieve_response_code( $resp ) ] );
        }
        clearstatcache( true, $tmp );
        if ( $limit > 0 && filesize( $tmp ) >= $limit ) {
            @unlink( $tmp );
            return new \WP_Error( 'disk_full', 'Not enough disk space here for ' . $name . ' from the source.', [ 'status' => 507 ] );
        }
        return $tmp;
    }

    /**
     * Downloads a file the source made for this pull (export, zip, manifest
     * chunk) to a temp file. Tries the file's URL first; when that fails —
     * hosts often refuse direct downloads of database dumps, for example any
     * URL containing ".sql." — falls back to the source's /stream-file
     * endpoint, which serves the same file through PHP. Returns the temp path
     * or WP_Error.
     */
    private function fetch_source_file( $url, $timeout = 600 ) {
        $tmp = $this->guarded_download( $url, $timeout );
        if ( ! is_wp_error( $tmp ) || in_array( $tmp->get_error_code(), [ 'invalid_url', 'unsafe_url', 'disk_full' ], true ) ) {
            return $tmp;
        }
        $streamed = $this->stream_from_source( $url, $timeout );
        return is_wp_error( $streamed ) ? $tmp : $streamed;
    }

    /**
     * Downloads one of the source's files through its /stream-file endpoint
     * (token-authenticated, every v2 source has it). Only for URLs on the
     * source's own host. Returns the temp path or WP_Error.
     */
    private function stream_from_source( $url, $timeout = 600 ) {
        $state = $this->state();
        $host  = is_string( $url ) ? strtolower( (string) parse_url( $url, PHP_URL_HOST ) ) : '';
        // The source's own address, or (one site of a network or WP
        // Freighter install) the install's main one: its export files sit
        // in the main site's folder and are linked from that address, which
        // for a subdomain or mapped subsite is another host.
        // Of those on the file's host, the one whose path the file's starts
        // with, the longest (a subfolder subsite under the install's path).
        $base     = '';
        $url_path = rawurldecode( (string) parse_url( (string) $url, PHP_URL_PATH ) );
        foreach ( [ $state['source_url'] ?? '', $state['install']['home'] ?? '', $state['scope']['install_home'] ?? '' ] as $candidate ) {
            if ( $host === '' || ! is_string( $candidate ) || $candidate === '' || $host !== strtolower( (string) parse_url( $candidate, PHP_URL_HOST ) ) ) {
                continue;
            }
            $cp      = rtrim( (string) parse_url( $candidate, PHP_URL_PATH ), '/' );
            $matches = $cp === '' || strpos( $url_path, $cp . '/' ) === 0;
            $bp      = rtrim( (string) parse_url( $base, PHP_URL_PATH ), '/' );
            $base_ok = $base !== '' && ( $bp === '' || strpos( $url_path, $bp . '/' ) === 0 );
            if ( $base === '' || ( $matches && ( ! $base_ok || strlen( $cp ) > strlen( $bp ) ) ) ) {
                $base = $candidate;
            }
        }
        if ( $base === '' ) {
            return new \WP_Error( 'invalid_url', 'Refusing to stream a file from another host.', [ 'status' => 502 ] );
        }
        // /stream-file takes a path relative to the source's root folder.
        $path      = rawurldecode( (string) parse_url( $url, PHP_URL_PATH ) );
        $base_path = rtrim( (string) parse_url( $base, PHP_URL_PATH ), '/' );
        if ( $base_path !== '' && strpos( $path, $base_path . '/' ) === 0 ) {
            $path = substr( $path, strlen( $base_path ) );
        }
        $relative = ltrim( $path, '/' );
        if ( $relative === '' ) {
            return new \WP_Error( 'invalid_url', 'Source file URL has no path.', [ 'status' => 502 ] );
        }

        $tmp = $this->stream_post( [ 'token' => $state['source_token'] ?? '', 'file' => $relative ], $timeout );
        if ( is_wp_error( $tmp ) ) {
            return $tmp->get_error_code() === 'disk_full' ? $tmp : new \WP_Error( 'download_failed', "Source could not stream {$relative} (" . $tmp->get_error_message() . ').', [ 'status' => 502 ] );
        }
        return $tmp;
    }

    /**
     * Downloads a URL to a local path (streamed to disk).
     */
    private function download_to( $url, $dest_path, $timeout = 600 ) {
        $tmp = $this->fetch_source_file( $url, $timeout );
        if ( is_wp_error( $tmp ) ) {
            return $tmp;
        }
        if ( ! is_dir( dirname( $dest_path ) ) ) {
            mkdir( dirname( $dest_path ), 0755, true );
        }
        if ( ! @rename( $tmp, $dest_path ) ) {
            copy( $tmp, $dest_path );
            @unlink( $tmp );
        }
        return true;
    }

    /**
     * Deletes a file this pull made in its session folder on the source
     * (export dumps, batch zips, manifest chunks). Those sit in the source's
     * public uploads folder and are a full copy of its database and files, so
     * each goes as soon as it is downloaded. Uses /cleanup-file, which every
     * v2 source has and which only touches this pull's own session folder.
     * Best effort: a failure never fails the pull.
     */
    private function cleanup_source_file( $url ) {
        $path = is_string( $url ) ? parse_url( $url, PHP_URL_PATH ) : '';
        $name = $path ? basename( $path ) : '';
        if ( $name === '' ) {
            return;
        }
        $this->source_request( '/cleanup-file', [ 'file_name' => $name ], 'POST', 30 );
    }

    /**
     * Verifies the source is reachable and records the connection. Detects the
     * source's permalink style and captures its home URL / prefix / tables.
     */
    /**
     * $move: how the move was started, kept so it can be resumed the same
     * way: [ client => dashboard|cli, quiet, label, args (cli) ].
     * $catch_up: copy only files that changed since the last pull from this
     * source (see cache_file_list()).
     */
    public function connect( $source_url, $source_token, $move = [], $catch_up = false, $site = null, $shared_rows = false ) {
        // A subsite or tenant can't be replaced from inside it (see
        // Import::here_refusal()): refused before anything is copied.
        if ( $refused = ( new Import( $this->import_id ) )->refused() ) {
            return $refused;
        }
        $source_url = rtrim( $source_url, '/' );
        if ( ! preg_match( '#^https?://#', $source_url ) ) {
            $source_url = 'https://' . $source_url;
        }
        // A resume passes no token: the one this move connected with, kept
        // in pull-secret.php, when it's the same source.
        if ( (string) $source_token === '' ) {
            $kept = $this->state();
            if ( ! empty( $kept['source_token'] ) && rtrim( (string) ( $kept['source_url'] ?? '' ), '/' ) === $source_url ) {
                $source_token = (string) $kept['source_token'];
            }
        }
        $r = $this->connect_run( $source_url, $source_token, $move, $catch_up, $site, $shared_rows );
        // A connect that failed after it saved the connection (the source's
        // preflight, the site it named, a busy source) leaves nothing
        // behind: no move listed as unfinished that can't be resumed, and
        // not the source's token on disk for a week.
        if ( is_wp_error( $r ) && is_file( $this->state_file ) && ! Import::has_restore_point( dirname( $this->state_file ) ) ) {
            $this->import->cancel( 'cleanup' );
        }
        return $r;
    }

    private function connect_run( $source_url, $source_token, $move, $catch_up, $site, $shared_rows ) {

        // Probe pretty route, then plain-permalink form, with the token in
        // the body (see get_with_body()). Only for a style that explicitly
        // answered 404 rest_no_route (meaning something in between stripped
        // X-HTTP-Method-Override) do we retry with GET and the token in the
        // query string.
        $style       = null;
        $allow_query = [];
        foreach ( [ 'body', 'query' ] as $how ) {
            foreach ( [ 'pretty', 'rest_route' ] as $candidate ) {
                if ( $how === 'query' && empty( $allow_query[ $candidate ] ) ) {
                    continue;
                }
                $url = $source_url . ( $candidate === 'rest_route' ? '/?rest_route=/disembark/v1/database' : '/wp-json/disembark/v1/database' );
                for ( $try = 0; $try < 3; $try++ ) {
                    $resp = $how === 'body'
                        ? $this->http( 'POST', $url, [ 'timeout' => 60, 'headers' => [ 'Content-Type' => 'application/json', 'X-HTTP-Method-Override' => 'GET' ], 'body' => wp_json_encode( [ 'token' => $source_token ] ) ] )
                        : $this->http( 'GET', $url . ( $candidate === 'rest_route' ? '&' : '?' ) . 'token=' . rawurlencode( $source_token ), [ 'timeout' => 60 ] );
                    // Busy for a moment: ask again rather than try another address.
                    if ( is_wp_error( $resp ) || ! in_array( (int) wp_remote_retrieve_response_code( $resp ), [ 429, 503 ], true ) ) {
                        break;
                    }
                    sleep( $try + 1 );
                }
                if ( ! is_wp_error( $resp ) ) {
                    $code = (int) wp_remote_retrieve_response_code( $resp );
                    if ( $code === 200 ) {
                        $tables = json_decode( wp_remote_retrieve_body( $resp ) );
                        if ( ! empty( $tables ) && is_array( $tables ) ) {
                            $style = $candidate;
                            break 2;
                        }
                    } elseif ( $how === 'body' && $code === 404 ) {
                        $error = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
                        if ( is_array( $error ) && ( $error['code'] ?? '' ) === 'rest_no_route' ) {
                            $allow_query[ $candidate ] = true;
                        }
                    }
                }
            }
        }
        if ( $style === null ) {
            // A site on someone's computer or private network can't be
            // reached from a live host (a push from it has this site
            // download from it).
            if ( self::local_address( $source_url ) && ! self::local_address( home_url() ) ) {
                $src = (string) wp_parse_url( $source_url, PHP_URL_HOST );
                return new \WP_Error( 'source_local', $src . ' is on a computer or private network that this site can\'t reach, so it can\'t be pulled from here (and can\'t push here). Send it as a backup zip instead: download one in Disembark on ' . $src . ' (Backups → Download a backup zip) and restore it here (Move → A backup zip). A push that uploads from a local site is planned.', [ 'status' => 400 ] );
            }
            return new \WP_Error( 'connect_failed', 'Could not reach the source or the token was rejected. Check the URL, token, and that Disembark is active on the source.', [ 'status' => 400 ] );
        }

        // Persist the connection (with a fresh source-side session token).
        $this->save_state( [
            'source_url'   => $source_url,
            'source_token' => $source_token,
            'rest_style'   => $style,
            'backup_token' => bin2hex( random_bytes( 16 ) ),
            'session'      => bin2hex( random_bytes( 16 ) ),
            'started'      => time(),
            'move'         => is_array( $move ) ? $move : [],
            'catch_up'     => (bool) $catch_up,
            'shared_rows'  => (bool) $shared_rows,
            // Which site of this install started it (see unfinished()).
            'owner'        => self::owner(),
        ] );

        // Source metadata (v2.8 sources expose /import/preflight).
        $pre = $this->source_request( '/import/preflight', [ 'import_id' => $this->source_session() ], 'POST' );
        if ( is_wp_error( $pre ) && in_array( (int) ( $pre->get_error_data()['status'] ?? 0 ), [ 429, 502, 503, 504 ], true ) ) {
            return $pre; // busy for longer than the retries: the client asks again
        }

        // A network or WP Freighter install: bring one site of it when one
        // was named, or when the address reached one site (a subsite, a
        // tenant). The install's main address brings the whole install.
        $install = is_object( $pre ) && ! empty( $pre->install ) ? json_decode( (string) wp_json_encode( $pre->install ), true ) : null;
        $state   = $this->state();
        $state['install'] = $install;
        $this->save_state( $state );
        $site = $site === null ? null : trim( (string) $site );
        if ( $site === null && $install && (string) $install['current'] !== (string) $install['main'] ) {
            $site = (string) $install['current'];
        }
        // An install with one site so far (a network with only its main
        // site, a WP Freighter install with no tenants yet): that site.
        if ( $site === null && $install && count( (array) ( $install['sites'] ?? [] ) ) === 1 ) {
            $site = (string) $install['main'];
        }
        if ( $site !== null && $site !== '' && strtolower( $site ) !== 'all' ) {
            if ( ! $install ) {
                return new \WP_Error( 'not_shared', 'The source is a single site, so there is no site to choose (--site).', [ 'status' => 400 ] );
            }
            $pre = $this->use_site( $site );
            if ( is_wp_error( $pre ) ) {
                return $pre;
            }
        }
        return $this->load_source( $pre );
    }

    /**
     * Narrows the pull to one site of the source's install. Returns the
     * source's preflight for that site, or WP_Error.
     */
    private function use_site( $site ) {
        $state         = $this->state();
        $state['site'] = (string) $site;
        $this->save_state( $state );
        $pre = $this->source_request( '/import/preflight', [ 'import_id' => $this->source_session() ], 'POST' );
        if ( is_wp_error( $pre ) || ! is_object( $pre ) || empty( $pre->scope->site ) ) {
            $state = $this->state();
            unset( $state['site'] );
            $this->save_state( $state );
            if ( is_wp_error( $pre ) && $pre->get_error_code() !== 'source_error' ) {
                return $pre;
            }
            return new \WP_Error( 'site_unavailable', is_wp_error( $pre )
                ? 'The source could not export site ' . $site . '. Check the site ID (Tools → Disembark on the source lists them).'
                : 'The source runs an older Disembark that can only export the whole install. Update Disembark there to bring one site.', [ 'status' => 400 ] );
        }
        $state         = $this->state();
        $state['site'] = (string) $pre->scope->site;
        $this->save_state( $state );
        return $pre;
    }

    /**
     * Picks which site of a network or WP Freighter install to bring ("all"
     * for the whole install), after connect and before the scan.
     */
    public function choose_site( $site ) {
        $state = $this->state();
        if ( empty( $state['source_token'] ) ) {
            return new \WP_Error( 'not_connected', 'Connect to the source first.', [ 'status' => 400 ] );
        }
        if ( ! empty( $state['file_list'] ) ) {
            return new \WP_Error( 'already_scanned', 'The site can only be chosen before the source is scanned.', [ 'status' => 409 ] );
        }
        $site = trim( (string) $site );
        if ( $site === '' || strtolower( $site ) === 'all' ) {
            // The whole install as one isn't built (see Import::whole_install_refusal()).
            return $this->import->whole_install_refusal() ?: new \WP_Error( 'whole_install', 'Choose one site of the source.', [ 'status' => 409 ] );
        }
        $pre = $this->use_site( $site );
        return is_wp_error( $pre ) ? $pre : $this->load_source( $pre );
    }

    /** Records what the source's preflight and /database said, and stamps staging. */
    private function load_source( $pre ) {
        $home = $prefix = ''; $tables = []; $db_size = 0;
        if ( is_object( $pre ) ) {
            $home   = rtrim( $pre->home_url ?? '', '/' );
            $prefix = $pre->db_prefix ?? '';
            $tables = $pre->tables ?? [];
        }
        // Table sizes (for a size estimate) from /database.
        $db = $this->source_request( '/database', [], 'GET' );
        if ( is_wp_error( $db ) && in_array( (int) ( $db->get_error_data()['status'] ?? 0 ), [ 429, 502, 503, 504 ], true ) ) {
            return $db;
        }
        $table_info = [];
        if ( is_array( $db ) ) {
            foreach ( $db as $row ) {
                $db_size += isset( $row->size ) ? (int) $row->size : 0;
                if ( ! empty( $row->table ) ) {
                    $table_info[] = [ 'name' => (string) $row->table, 'size' => (int) ( $row->size ?? 0 ), 'rows' => (int) ( $row->row_count ?? 0 ) ];
                }
            }
        }

        $state = $this->state();
        $scope = is_object( $pre ) && ! empty( $pre->scope ) ? json_decode( (string) wp_json_encode( $pre->scope ), true ) : null;
        $state['source_home']    = $home;
        $state['source_prefix']  = $prefix;
        $state['source_profile'] = is_object( $pre ) && isset( $pre->profile ) ? json_decode( (string) wp_json_encode( $pre->profile ), true ) : null;
        $state['scope']          = $scope;
        $state['tables']        = $table_info;
        $this->save_state( $state );

        // Stamp source metadata into staging so the import reads it like a backup.
        if ( ! is_dir( $this->staging_public ) ) {
            mkdir( $this->staging_public, 0755, true );
        }
        file_put_contents(
            $this->staging_public . '/disembark-source.json',
            wp_json_encode( [ 'home_url' => $home, 'site_url' => $home, 'db_prefix' => $prefix, 'abspath' => (string) ( $pre->abspath ?? '' ), 'content_dir' => (string) ( $pre->content_dir ?? '' ), 'profile' => $state['source_profile'], 'scope' => $scope ] )
        );

        $install = $state['install'] ?? null;
        return [
            'ok'          => true,
            'import_id'   => $this->import_id,
            'source_home' => $home,
            'db_prefix'   => $prefix,
            'table_count' => is_array( $tables ) ? count( $tables ) : 0,
            'db_size'     => $db_size,
            'install'     => $install,
            'scope'       => $scope,
        ];
    }

    /**
     * The address a pull is remembered under (for catch-up pulls). Sites of a
     * WP Freighter install without domain mapping share one address, so the
     * site's ID tells them apart.
     */
    private static function memory_source( $state ) {
        $source = (string) ( $state['source_home'] ?? '' ) ?: (string) ( $state['source_url'] ?? '' );
        $scope  = (array) ( $state['scope'] ?? [] );
        if ( ! empty( $scope['site'] ) && Quiet::address( $source ) === Quiet::address( (string) ( $scope['install_home'] ?? '' ) ) && ! in_array( (string) $scope['site'], [ 'main', (string) ( $state['install']['main'] ?? '' ) ], true ) ) {
            $source .= ' (site ' . $scope['site'] . ')';
        }
        return $source;
    }

    /**
     * Drives one step of the source's manifest generation. Steps mirror the
     * source contract: initiate -> scan (repeat until scan_complete) ->
     * chunkify -> process_chunk (1..total) -> finalize. On finalize the file
     * list is downloaded and cached locally.
     */
    public function scan_step( $step, $chunk = 0 ) {
        // Nothing is copied for a move that can't land (a whole install as one).
        // It can never go on, so it isn't left to resume either.
        if ( $step === 'initiate' && ( $refused = $this->import->refused() ) ) {
            $this->import->cancel( 'cleanup' );
            return $refused;
        }
        $payload = [ 'step' => $step ];
        if ( $step === 'process_chunk' ) {
            $payload['chunk'] = $chunk;
        }
        $resp = $this->source_request( '/regenerate-manifest', $payload, 'POST', 300 );
        if ( is_wp_error( $resp ) ) {
            return $resp;
        }

        if ( $step === 'finalize' ) {
            return $this->cache_file_list( $resp );
        }
        // Return the source's response verbatim for the browser loop.
        return is_object( $resp ) ? (array) $resp : [ 'status' => 'ok' ];
    }

    /**
     * Downloads each manifest chunk JSON from the source and flattens it into a
     * single local file list (name + size), cached for the fetch loop.
     */
    private function cache_file_list( $manifest ) {
        if ( ! is_array( $manifest ) ) {
            return new \WP_Error( 'bad_manifest', 'Source returned no manifest.', [ 'status' => 502 ] );
        }
        $files = [];
        foreach ( $manifest as $chunk ) {
            if ( ! is_object( $chunk ) || empty( $chunk->url ) ) {
                continue;
            }
            $tmp = $this->fetch_source_file( $chunk->url );
            if ( is_wp_error( $tmp ) ) {
                return $tmp;
            }
            $list = json_decode( file_get_contents( $tmp ) );
            @unlink( $tmp );
            $this->cleanup_source_file( $chunk->url );
            if ( is_array( $list ) ) {
                foreach ( $list as $f ) {
                    if ( is_object( $f ) && ! empty( $f->name ) ) {
                        $entry = [ 'name' => $f->name, 'size' => $f->size ?? 0 ];
                        if ( isset( $f->mtime ) ) {
                            $entry['mtime'] = (int) $f->mtime;
                        }
                        $files[] = $entry;
                    }
                }
            }
        }
        $this->source_request( '/cleanup-file', [ 'file_name' => 'manifest.json' ], 'POST', 30 );
        $state  = $this->state();
        $files  = $this->movable( $files );
        $result = [ 'status' => 'ready', 'source_files' => count( $files ) ];

        // Catch-up: keep only files that are new or changed since the last
        // pull from this source; files that were brought then and are gone
        // from the source now are removed here (deploy_files, with a backup
        // for rollback).
        $last = ! empty( $state['catch_up'] ) ? self::last_pull( self::memory_source( $state ) ) : null;
        // Only while this site still holds that pull's files: a pull from
        // another source since (another subsite of the same network, say)
        // replaced them, so everything comes across again.
        if ( $last && ! self::newest_pull( $last ) ) {
            $last = null;
        }
        if ( $last ) {
            $before  = (array) $last['files'];
            $changed = [];
            $kept    = [];
            $approx  = false;
            // Files the last pull left behind aren't on this site, so they
            // count as not here yet, but they aren't new: told apart.
            $left    = isset( $last['left_out'] ) ? (array) $last['left_out'] : null;
            $behind  = 0;
            foreach ( $files as $f ) {
                $old = $before[ $f['name'] ] ?? null;
                if ( $old === null && $left !== null && self::was_left_out( $f['name'], $left ) ) {
                    $behind++;
                }
                if ( $old === null || (int) $old[0] !== (int) $f['size'] ) {
                    $changed[] = $f;
                    continue;
                }
                if ( isset( $f['mtime'], $old[1] ) && (int) $old[1] !== (int) $f['mtime'] ) {
                    $changed[] = $f;
                    continue;
                }
                if ( ! isset( $f['mtime'], $old[1] ) ) {
                    $approx = true; // an older source sends no times: compared by size only
                }
                $kept[ $f['name'] ] = $old;
            }
            $now     = array_flip( array_column( $files, 'name' ) );
            $removed = array_values( array_filter( array_keys( $before ), function ( $name ) use ( $now ) { return ! isset( $now[ $name ] ); } ) );
            file_put_contents( dirname( $this->state_file ) . '/unchanged.json', wp_json_encode( $kept ) );
            file_put_contents( dirname( $this->state_file ) . '/delete-list.json', wp_json_encode( $removed ) );
            $state['catch_up_info'] = [ 'since' => (int) $last['at'], 'changed' => count( $changed ) - $behind, 'left_before' => $left === null ? null : $behind, 'unchanged' => count( $kept ), 'removed' => count( $removed ), 'approx' => $approx ];
            $result['catch_up']     = $state['catch_up_info'];
            $files                  = $changed;
        } elseif ( ! empty( $state['catch_up'] ) ) {
            $result['catch_up'] = false; // nothing remembered for this source: everything comes across
        }

        $state['file_list'] = $this->staging_dir() . '/file-list.json';
        file_put_contents( $state['file_list'], wp_json_encode( $files ) );
        $state['total_files'] = count( $files );
        $this->save_state( $state );
        $result['total_files'] = count( $files );
        return $result;
    }

    /* ------------------------------------------------------------------
     * What this site remembers about past pulls, per source address: the
     * source's files that are on this site, with size and modification
     * time, so the next pull can copy only what changed. Kept as files in
     * uploads/disembark/pulls (a pull replaces the database).
     * ------------------------------------------------------------------ */

    private static function pulls_dir() {
        return Storage::base_dir() . '/pulls';
    }

    private static function pull_file( $source ) {
        return self::pulls_dir() . '/' . md5( Quiet::address( $source ) ) . '.php';
    }

    /** [ source, at, files: { name: [size, mtime|null] } ] or null. */
    /** Whether $name sat in what a pull left behind ({ paths, names }). */
    private static function was_left_out( $name, $left ) {
        $names = array_flip( array_map( 'strval', (array) ( $left['names'] ?? [] ) ) );
        return isset( $names[ basename( (string) $name ) ] ) || Skips::inside( (string) $name, array_map( 'strval', (array) ( $left['paths'] ?? [] ) ) );
    }

    public static function last_pull( $source ) {
        $file = self::pull_file( $source );
        if ( ! is_file( $file ) ) {
            return null;
        }
        $raw  = (string) file_get_contents( $file );
        $nl   = strpos( $raw, "\n" );
        $data = $nl === false ? null : json_decode( substr( $raw, $nl + 1 ), true );
        return is_array( $data ) && isset( $data['files'] ) ? $data : null;
    }

    /** Sources this site has pulled from: [ { source, at, files } ], newest first. */
    public static function last_pulls() {
        $out = [];
        foreach ( (array) glob( self::pulls_dir() . '/*.php' ) as $file ) {
            if ( basename( $file ) === 'index.php' ) {
                continue;
            }
            $raw  = (string) file_get_contents( $file );
            $nl   = strpos( $raw, "\n" );
            $data = $nl === false ? null : json_decode( substr( $raw, $nl + 1 ), true );
            if ( is_array( $data ) && ! empty( $data['source'] ) ) {
                $out[] = [ 'source' => (string) $data['source'], 'at' => (int) $data['at'], 'files' => count( (array) $data['files'] ), 'via' => (string) ( $data['via'] ?? '' ), 'site' => (string) ( $data['site'] ?? '' ) ];
            }
        }
        usort( $out, function ( $a, $b ) { return $b['at'] - $a['at']; } );
        // Only the newest can catch up: an older one's files were replaced
        // by the pulls since (see newest_pull()).
        foreach ( $out as $i => $p ) {
            $out[ $i ]['current'] = $i === 0;
        }
        return $out;
    }

    /** Whether $memory is this site's newest pull, so its files are still the ones here. */
    private static function newest_pull( $memory ) {
        $newest = self::last_pulls()[0] ?? null;
        return $newest && Quiet::address( (string) $newest['source'] ) === Quiet::address( (string) ( $memory['source'] ?? '' ) ) && (int) $newest['at'] === (int) ( $memory['at'] ?? 0 );
    }

    /**
     * Called by finalize: remembers the source's files now on this site
     * (unchanged ones from a catch-up, plus what this pull brought).
     */
    public function remember_files() {
        $state = $this->state();
        if ( empty( $state['file_list'] ) || ! is_file( $state['file_list'] ) ) {
            return false;
        }
        $source = self::memory_source( $state );
        if ( $source === '' ) {
            return false;
        }
        $files     = [];
        $unchanged = dirname( $this->state_file ) . '/unchanged.json';
        if ( is_file( $unchanged ) ) {
            $files = (array) json_decode( (string) file_get_contents( $unchanged ), true );
        }
        foreach ( (array) json_decode( (string) file_get_contents( $state['file_list'] ), true ) as $f ) {
            $files[ $f['name'] ] = [ (int) $f['size'], isset( $f['mtime'] ) ? (int) $f['mtime'] : null ];
        }
        Storage::ensure_dir( self::pulls_dir() );
        // A rollback of this move puts the previous memory back (see
        // restore_memory()), so a later catch-up doesn't skip files the
        // rollback returned to their old versions.
        $rollback = dirname( $this->state_file ) . '/rollback';
        if ( is_dir( $rollback ) ) {
            $previous = is_file( self::pull_file( $source ) ) ? (string) file_get_contents( self::pull_file( $source ) ) : null;
            self::write_protected_json( $rollback . '/pull-memory.php', [ 'file' => self::pull_file( $source ), 'previous' => $previous ] );
        }
        $memory = [ 'source' => untrailingslashit( $source ), 'at' => time(), 'files' => $files ];
        // One site of a network or WP Freighter install: the connect line it
        // came through (the install's address) and which site, so the
        // dashboard offers a catch-up when that connect line is pasted again.
        $via = untrailingslashit( (string) ( $state['source_url'] ?? '' ) );
        if ( $via !== '' && Quiet::address( $via ) !== Quiet::address( $source ) && ! empty( $state['site'] ) ) {
            $memory['via'] = $via;
        }
        // Which site, the main one too, so the dashboard can tell a main
        // site's pull from a subsite's through the same connect line.
        if ( ! empty( $state['site'] ) ) {
            $memory['site'] = (string) $state['site'];
        }
        // What "What to bring" left behind, so the next catch-up can say
        // those files aren't new.
        $memory['left_out'] = [ 'paths' => (array) ( $state['left_out']['paths'] ?? [] ), 'names' => (array) ( $state['left_out']['names'] ?? [] ) ];
        // Both sides' table fingerprints, for skipping unchanged tables next time.
        $fps = json_decode( (string) Import::read_protected( dirname( $this->state_file ) . '/fingerprints.php' ), true );
        if ( is_array( $fps ) && ! empty( $fps['tables'] ) ) {
            $memory['tables']     = $fps['tables'];
            $memory['all_tables'] = (array) ( $fps['all'] ?? [] );
        }
        $body = wp_json_encode( $memory );
        $tmp  = self::pull_file( $source ) . '.tmp';
        if ( ! is_string( $body ) || @file_put_contents( $tmp, "<?php exit; ?>\n" . $body ) === false ) {
            return false;
        }
        return @rename( $tmp, self::pull_file( $source ) );
    }

    /**
     * The plain files an older build left in a move's folder ($dir), moved
     * behind the exit line: the source's token and backup session in
     * pull.json (2.9 kept them there until the database step, and a pull
     * that stopped kept them for good), and a restore point's pull memory
     * (pull-memory.json). The protected copy is written whole before the
     * plain one changes, so a run that stops is finished by the next.
     * True when nothing is left to move.
     */
    public static function protect_legacy_state( $dir ) {
        $ok    = true;
        $state = $dir . '/pull.json';
        $data  = is_file( $state ) ? json_decode( (string) file_get_contents( $state ), true ) : null;
        if ( is_array( $data ) && ( $secret = array_intersect_key( $data, array_flip( self::SECRETS ) ) ) ) {
            $kept = json_decode( (string) Import::read_protected( $dir . '/pull-secret.php' ), true );
            $ok   = self::write_protected_json( $dir . '/pull-secret.php', array_merge( $secret, is_array( $kept ) ? $kept : [] ) );
            if ( $ok ) {
                $tmp  = $state . '.tmp';
                $body = wp_json_encode( array_diff_key( $data, $secret ) );
                $ok   = is_string( $body ) && @file_put_contents( $tmp, $body ) !== false && @rename( $tmp, $state );
                if ( ! $ok ) {
                    @unlink( $tmp );
                }
            }
        }
        $memory = $dir . '/rollback/pull-memory.json';
        if ( is_file( $memory ) ) {
            if ( is_file( $dir . '/rollback/pull-memory.php' ) || Import::write_protected( $dir . '/rollback/pull-memory.php', (string) file_get_contents( $memory ) ) ) {
                @unlink( $memory );
            } else {
                $ok = false;
            }
        }
        return $ok;
    }

    /** Called by a rollback: puts back what this site remembered before the move. */
    public static function restore_memory( $rollback_path ) {
        $saved = $rollback_path . '/pull-memory.php';
        if ( ! is_file( $saved ) ) {
            $saved = $rollback_path . '/pull-memory.json'; // an older build's
        }
        if ( ! is_file( $saved ) ) {
            return;
        }
        $memory = json_decode( (string) Import::read_protected( $saved ), true );
        if ( ! is_array( $memory ) || ! array_key_exists( 'previous', $memory ) ) {
            return;
        }
        $file   = (string) ( $memory['file'] ?? '' );
        if ( $file === '' || strpos( wp_normalize_path( $file ), wp_normalize_path( self::pulls_dir() ) . '/' ) !== 0 ) {
            return;
        }
        if ( $memory['previous'] === null ) {
            @unlink( $file );
        } else {
            file_put_contents( $file, (string) $memory['previous'] );
        }
        @unlink( $saved );
    }

    /**
     * Moves that stopped before finishing (their pull state is still on
     * disk): what they were, how far they got, whether a rollback point was
     * saved. Moves abandoned for a week with no rollback point are removed.
     */
    public static function unfinished() {
        $out = [];
        foreach ( (array) glob( Storage::base_dir() . '/_import_*', GLOB_ONLYDIR ) as $dir ) {
            $id         = substr( basename( $dir ), strlen( '_import_' ) );
            $state_file = $dir . '/pull.json';
            if ( ! is_file( $state_file ) ) {
                continue;
            }
            $has_point  = Import::has_restore_point( $dir );
            // The newest change to the move's own files, its steps and its
            // staging folder: a step that's running writes one of them.
            $touched    = max( (int) @filemtime( $dir ), (int) @filemtime( $dir . '/steps' ), (int) @filemtime( $dir . '/staging' ), (int) @filemtime( $dir . '/staging/public' ) );
            foreach ( (array) glob( $dir . '/*', GLOB_NOSORT ) as $f ) {
                $touched = max( $touched, (int) @filemtime( $f ) );
            }
            $state = json_decode( (string) file_get_contents( $state_file ), true );
            $state = is_array( $state ) ? $state : [];
            // Another site's move (a network's subsite, a WP Freighter
            // tenant sharing this folder): that site lists it.
            if ( isset( $state['owner'] ) && (string) $state['owner'] !== self::owner() ) {
                continue;
            }
            if ( ! $has_point && $touched < time() - WEEK_IN_SECONDS ) {
                ( new Import( $id ) )->cancel( 'cleanup' );
                continue;
            }
            $ago   = max( 0, time() - $touched );
            $out[] = [
                'id'             => $id,
                'source'         => (string) ( $state['source_home'] ?? '' ) ?: (string) ( $state['source_url'] ?? '' ),
                'started'        => (int) ( $state['started'] ?? 0 ),
                'touched'        => $touched,
                'move'           => (array) ( $state['move'] ?? [] ),
                'catch_up'       => ! empty( $state['catch_up'] ),
                'files_total'    => (int) ( $state['total_files'] ?? 0 ),
                'files_fetched'  => is_file( $dir . '/fetched.php' ) ? Pace::ranges_count( json_decode( (string) Import::read_protected( $dir . '/fetched.php' ), true ) ) : (int) ( $state['fetched'] ?? 0 ),
                'database'       => ! empty( $state['db_done'] ),
                'rollback_point' => $has_point,
                // Tables of other sites its restore point holds (one from
                // before 3.0's scope fix), which a rollback leaves alone.
                'left_alone'     => $has_point ? ( new Import( $id ) )->rollback_leaves() : 0,
                // Past its restore point, it's kept until it's resumed,
                // discarded or rolled back, however long that takes.
                'idle_days'      => (int) floor( $ago / DAY_IN_SECONDS ),
                // Worked on in the last two minutes: something (another tab,
                // a terminal, a push from the source) may still be running
                // it, so Resume, Discard and Roll back wait or ask.
                'active'         => $ago < 120,
                'active_ago'     => $ago,
            ];
        }
        usort( $out, function ( $a, $b ) { return $b['touched'] - $a['touched']; } );
        return $out;
    }

    /** This site within its install, for telling moves apart in a shared folder: "blog:<id>" on a network, the table prefix elsewhere. */
    public static function owner() {
        global $wpdb;
        return is_multisite() ? 'blog:' . get_current_blog_id() : 'prefix:' . (string) $wpdb->prefix;
    }

    private function staging_dir() {
        return dirname( $this->staging_public ); // .../staging
    }

    /**
     * Pulls one batch of files: asks the source to zip them, downloads the zip,
     * and extracts into staging. Returns cumulative progress.
     */
    public function fetch_files( $offset, $count ) {
        if ( $this->import->cancelled() ) {
            return new \WP_Error( 'cancelled', 'Cancelled.', [ 'status' => 409 ] );
        }
        $state = $this->state();
        if ( empty( $state['file_list'] ) || ! file_exists( $state['file_list'] ) ) {
            return new \WP_Error( 'no_file_list', 'Run the scan step first.', [ 'status' => 400 ] );
        }
        $all   = json_decode( file_get_contents( $state['file_list'] ), true );
        $total = count( $all );
        $batch = array_slice( $all, $offset, $count );
        if ( empty( $batch ) ) {
            return [ 'fetched' => $total, 'total' => $total, 'done' => true ];
        }

        $url = $this->source_request( '/zip-sync-files', [ 'files' => $batch ], 'POST', 1800 );
        if ( is_wp_error( $url ) ) {
            return $url;
        }
        if ( ! is_string( $url ) || ! preg_match( '#^https?://#', $url ) ) {
            return new \WP_Error( 'zip_failed', 'Source did not return a zip URL.', [ 'status' => 502 ] );
        }

        $zip_path = $this->staging_dir() . '/pull-batch.zip';
        $dl = $this->download_to( $url, $zip_path );
        $this->cleanup_source_file( $url );
        if ( is_wp_error( $dl ) ) {
            return $dl;
        }
        $extracted = $this->extract_into_public( $zip_path );
        @unlink( $zip_path );
        if ( is_wp_error( $extracted ) ) {
            return $extracted;
        }

        $fetched = min( $offset + $count, $total );
        $state   = $this->state();
        $state['fetched'] = $fetched;
        $this->save_state( $state );
        return [ 'fetched' => $fetched, 'total' => $total, 'done' => $fetched >= $total ];
    }

    /**
     * Pulls the database: asks the source to export all tables, downloads the
     * dump into staging as database-pull.sql.
     */
    public function fetch_database() {
        $pre = $this->source_request( '/import/preflight', [ 'import_id' => $this->source_session() ], 'POST' );
        $tables = ( is_object( $pre ) && ! empty( $pre->tables ) ) ? $pre->tables : [];
        if ( empty( $tables ) ) {
            return new \WP_Error( 'no_tables', 'Source returned no tables to export.', [ 'status' => 502 ] );
        }
        // Tables whose rows stay behind (see select()). A source that predates
        // schema_only sends the rows anyway; the import drops them then.
        $state       = $this->state();
        $schema_only = array_values( (array) ( $state['skip_rows'] ?? [] ) );
        $url = $this->source_request( '/export-database-batch', [ 'tables' => $tables, 'schema_only' => $schema_only, 'shared_rows' => ! empty( $state['shared_rows'] ) ], 'POST', 1800 );
        if ( is_wp_error( $url ) ) {
            return $url;
        }
        if ( ! is_string( $url ) || ! preg_match( '#^https?://#', $url ) ) {
            return new \WP_Error( 'export_failed', 'Source did not return a database URL.', [ 'status' => 502 ] );
        }
        // Staged behind the exit line (see Import::staged_name()).
        $dest = $this->staging_public . '/' . Import::staged_name( 'database-pull.sql' );
        $tmp  = $this->fetch_source_file( $url );
        $this->cleanup_source_file( $url );
        if ( is_wp_error( $tmp ) ) {
            return $tmp;
        }
        wp_mkdir_p( $this->staging_public );
        $saved = Import::copy_protected( $tmp, $dest );
        @unlink( $tmp );
        if ( ! $saved ) {
            return new \WP_Error( 'write_failed', 'Could not save the database in staging (is the disk full?).', [ 'status' => 500 ] );
        }
        // The database is the last thing pulled: clear this pull's working
        // folder on the source, then drop the source's full-access token,
        // which isn't needed from here on.
        $this->cleanup_source();
        $state = $this->state();
        unset( $state['source_token'] );
        $state['db_done'] = true;
        $this->save_state( $state );
        return [ 'ok' => true, 'tables' => count( $tables ), 'bytes' => filesize( $dest ) ];
    }

    /* ------------------------------------------------------------------
     * Paced pulls (see Pace)
     *
     * The client sizes each batch to the host: how many files (and bytes)
     * the source zips at once, how much of the database it exports at once.
     * Batches may run side by side and in any order, so each one is
     * extracted in a folder of its own and its files moved into staging
     * whole (a file in staging is never half written), and the batches that
     * arrived are kept as ranges of the file list. Every answer carries
     * those ranges: the client takes what is left from them, never from
     * what it asked for, so a resumed move or a replayed answer can't skip
     * a file.
     * ---------------------------------------------------------------- */

    /** The file indexes that have arrived: [ [start, end), ... ]. */
    private function fetched_ranges() {
        $ranges = json_decode( (string) Import::read_protected( dirname( $this->state_file ) . '/fetched.php' ), true );
        return is_array( $ranges ) ? $ranges : [];
    }

    /** Records [start, end) as arrived (under a lock: batches run side by side). Returns the ranges. */
    private function record_fetched( $start, $end ) {
        $dir  = dirname( $this->state_file );
        $lock = @fopen( $dir . '/fetched.lock', 'c' );
        if ( $lock ) {
            flock( $lock, LOCK_EX );
        }
        $ranges = Pace::ranges_add( $this->fetched_ranges(), $start, $end );
        self::write_protected_json( $dir . '/fetched.php', $ranges );
        if ( $lock ) {
            flock( $lock, LOCK_UN );
            fclose( $lock );
        }
        return $ranges;
    }

    /** What a paced fetch answers besides its own details: overall progress. */
    private function fetch_progress( $total, $ranges = null ) {
        $ranges  = $ranges === null ? $this->fetched_ranges() : $ranges;
        $fetched = min( (int) $total, Pace::ranges_count( $ranges ) );
        return [ 'fetched' => $fetched, 'total' => (int) $total, 'done' => $fetched >= (int) $total, 'ranges' => $ranges ];
    }

    /** The path of file $offset in the source's file list ('' when there is none). */
    public function file_name( $offset ) {
        $all = $this->file_list();
        return is_wp_error( $all ) ? '' : (string) ( $all[ (int) $offset ]['name'] ?? '' );
    }

    /** The scanned file list (after "What to bring"), or WP_Error. */
    private function file_list() {
        $state = $this->state();
        if ( empty( $state['file_list'] ) || ! file_exists( $state['file_list'] ) ) {
            return new \WP_Error( 'no_file_list', 'Run the scan step first.', [ 'status' => 400 ] );
        }
        $all = json_decode( (string) file_get_contents( $state['file_list'] ), true );
        return is_array( $all ) ? $all : [];
    }

    /** A source request that timed out or dropped, as a 504 the client tries again; other errors as they are. */
    private static function paced_error( $error ) {
        return self::dropped( $error ) ? new \WP_Error( 'source_unreachable', 'The source took too long to answer (' . substr( $error->get_error_message(), 0, 160 ) . ').', [ 'status' => 504 ] ) : $error;
    }

    /**
     * One batch: files $offset.. (at most $count, and no more than
     * $max_bytes once there's one), zipped by the source, downloaded and
     * extracted. Within $budget seconds where it can (the source's zip and
     * the download get only what is left of it).
     */
    public function fetch_files_paced( $offset, $count, $max_bytes, $budget ) {
        if ( $this->import->cancelled() ) {
            return new \WP_Error( 'cancelled', 'Cancelled.', [ 'status' => 409 ] );
        }
        $deadline = Pace::deadline( $budget );
        $all      = $this->file_list();
        if ( is_wp_error( $all ) ) {
            return $all;
        }
        $total  = count( $all );
        $offset = max( 0, (int) $offset );
        $count  = max( 1, (int) $count );
        $batch  = [];
        $bytes  = 0;
        for ( $i = $offset; $i < min( $total, $offset + $count ); $i++ ) {
            $size = (int) ( $all[ $i ]['size'] ?? 0 );
            if ( $batch && $max_bytes > 0 && $bytes + $size > $max_bytes ) {
                break;
            }
            $batch[] = $all[ $i ];
            $bytes  += $size;
        }
        if ( ! $batch ) {
            return [ 'offset' => $offset, 'count' => 0, 'bytes' => 0 ] + $this->fetch_progress( $total );
        }

        $url = $this->source_request( '/zip-sync-files', [ 'files' => array_values( $batch ) ], 'POST', max( 10, (int) ceil( Pace::left( $deadline ) ) ) );
        if ( is_wp_error( $url ) ) {
            return self::paced_error( $url );
        }
        if ( ! is_string( $url ) || ! preg_match( '#^https?://#', $url ) ) {
            return new \WP_Error( 'zip_failed', 'Source did not return a zip URL.', [ 'status' => 502 ] );
        }
        $id   = bin2hex( random_bytes( 8 ) );
        $work = $this->staging_dir() . '/_batches';
        wp_mkdir_p( $work );
        $zip_path = "{$work}/{$id}.zip";
        $dl       = $this->download_to( $url, $zip_path, max( 10, (int) ceil( Pace::left( $deadline ) ) ) );
        $this->cleanup_source_file( $url );
        if ( is_wp_error( $dl ) ) {
            @unlink( $zip_path );
            return self::paced_error( $dl );
        }
        $dir  = "{$work}/{$id}";
        $done = Import::extract_zip_into( $zip_path, $dir, [ 'strict' => true ] );
        @unlink( $zip_path );
        if ( is_wp_error( $done ) ) {
            self::remove_tree( $dir );
            return new \WP_Error( 'extract_failed', 'Could not extract a batch from the source: ' . $done->get_error_message(), [ 'status' => 500 ] );
        }
        $moved = self::move_tree( $dir, $this->staging_public );
        self::remove_tree( $dir );
        if ( is_wp_error( $moved ) ) {
            return $moved;
        }
        $ranges = $this->record_fetched( $offset, $offset + count( $batch ) );
        return [ 'offset' => $offset, 'count' => count( $batch ), 'bytes' => $bytes ] + $this->fetch_progress( $total, $ranges );
    }

    /**
     * One file in byte ranges, for a file no batch could bring (too big or
     * too slow for the source to zip in time): read straight from the
     * source's /stream-file a piece at a time, until $budget seconds are
     * spent; the next call carries on from the byte it reached.
     */
    public function fetch_file_part( $offset, $budget, $chunk = 0 ) {
        if ( $this->import->cancelled() ) {
            return new \WP_Error( 'cancelled', 'Cancelled.', [ 'status' => 409 ] );
        }
        $deadline = Pace::deadline( $budget );
        $all      = $this->file_list();
        if ( is_wp_error( $all ) ) {
            return $all;
        }
        $total = count( $all );
        $file  = $all[ (int) $offset ] ?? null;
        if ( ! $file ) {
            return [ 'file_done' => true ] + $this->fetch_progress( $total );
        }
        $state = $this->state();
        $name  = (string) $file['name'];
        if ( ! empty( $state['site'] ) || ! Import::is_safe_relative_path( $name ) ) {
            return new \WP_Error( 'no_ranges', 'This file can only come across in a batch.', [ 'status' => 400 ] );
        }
        $chunk = (int) $chunk > 0 ? max( 256 * KB_IN_BYTES, min( 64 * MB_IN_BYTES, (int) $chunk ) ) : 8 * MB_IN_BYTES;
        $work  = $this->staging_dir() . '/_parts';
        wp_mkdir_p( $work );
        $key        = md5( $name );
        $part       = "{$work}/{$key}.part";
        $state_file = "{$work}/{$key}.php";
        $got        = max( 0, (int) ( json_decode( (string) Import::read_protected( $state_file ), true )['got'] ?? 0 ) );
        clearstatcache( true, $part );
        $got        = is_file( $part ) ? min( $got, (int) @filesize( $part ) ) : 0;
        $h          = @fopen( $part, 'c+b' );
        if ( ! $h ) {
            return new \WP_Error( 'write_failed', 'Could not write to staging (is the disk full?).', [ 'status' => 500 ] );
        }
        ftruncate( $h, $got );
        fseek( $h, $got );
        $size = (int) ( $file['size'] ?? 0 );
        $eof  = false;
        do {
            $tmp = $this->stream_post( [ 'token' => $state['source_token'] ?? '', 'file' => $name, 'offset' => $got, 'length' => $chunk ], max( 10, (int) ceil( Pace::left( $deadline ) ) ) );
            if ( is_wp_error( $tmp ) ) {
                fclose( $h );
                return self::paced_error( $tmp );
            }
            $in = @fopen( $tmp, 'rb' );
            $n  = $in ? stream_copy_to_stream( $in, $h ) : false;
            if ( $in ) {
                fclose( $in );
            }
            @unlink( $tmp );
            if ( $n === false ) {
                fclose( $h );
                return new \WP_Error( 'write_failed', 'Could not write to staging (is the disk full?).', [ 'status' => 500 ] );
            }
            fflush( $h );
            $got += $n;
            self::write_protected_json( $state_file, [ 'got' => $got ] );
            $eof = $n < $chunk || ( $size > 0 && $got >= $size );
        } while ( ! $eof && microtime( true ) < $deadline );
        fclose( $h );
        if ( ! $eof ) {
            return [ 'file_done' => false, 'got' => $got, 'size' => $size ] + $this->fetch_progress( $total );
        }

        // Whole: into staging under its staged name (see Import::staged_name()).
        $dest = $this->staging_public . '/' . Import::staged_name( $name );
        if ( ! self::make_dir( dirname( $dest ) ) ) {
            return new \WP_Error( 'write_failed', 'Could not write to staging (is the disk full?).', [ 'status' => 500 ] );
        }
        if ( Import::staged_name( $name ) !== $name ) {
            $tmp = $dest . '.tmp';
            $in  = @fopen( $part, 'rb' );
            $out = $in ? @fopen( $tmp, 'wb' ) : false;
            $ok  = $in && $out && fwrite( $out, Import::PHP_EXIT ) !== false && stream_copy_to_stream( $in, $out ) !== false;
            if ( $in ) {
                fclose( $in );
            }
            if ( $out ) {
                $ok = fclose( $out ) && $ok;
            }
            $ok = $ok && @rename( $tmp, $dest );
            if ( $ok ) {
                @unlink( $part );
            } else {
                @unlink( $tmp );
            }
        } else {
            $ok = @rename( $part, $dest );
        }
        if ( ! $ok ) {
            return new \WP_Error( 'write_failed', 'Could not write to staging (is the disk full?).', [ 'status' => 500 ] );
        }
        @unlink( $state_file );
        $ranges = $this->record_fetched( (int) $offset, (int) $offset + 1 );
        return [ 'file_done' => true, 'got' => $got, 'size' => $size ] + $this->fetch_progress( $total, $ranges );
    }

    /** mkdir -p that doesn't mind another request making the same folder at the same moment. */
    private static function make_dir( $dir ) {
        if ( is_dir( $dir ) ) {
            return true;
        }
        @mkdir( $dir, 0755, true );
        clearstatcache( true, $dir );
        return is_dir( $dir );
    }

    /** Moves every file under $from to the same place under $to (each one whole, by rename). */
    private static function move_tree( $from, $to ) {
        if ( ! is_dir( $from ) ) {
            return true;
        }
        $it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $from, \RecursiveDirectoryIterator::SKIP_DOTS ) );
        foreach ( $it as $file ) {
            if ( ! $file->isFile() ) {
                continue;
            }
            $rel  = ltrim( str_replace( '\\', '/', substr( $file->getPathname(), strlen( $from ) ) ), '/' );
            $dest = $to . '/' . $rel;
            if ( ! self::make_dir( dirname( $dest ) ) || ! @rename( $file->getPathname(), $dest ) ) {
                return new \WP_Error( 'write_failed', "Could not move {$rel} into staging (is the disk full?).", [ 'status' => 500 ] );
            }
        }
        return true;
    }

    private static function remove_tree( $dir ) {
        if ( ! is_dir( $dir ) ) {
            return;
        }
        $it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
        foreach ( $it as $item ) {
            $item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() );
        }
        @rmdir( $dir );
    }

    /**
     * The database, one batch per call: a group of tables up to
     * $batch_bytes, or, for a table bigger than that, a part of it by
     * primary key (a source that runs 3.0 or later). The dump is put
     * together in staging behind the exit line (database-pull.part.php),
     * and only takes its staged name (database-pull.sql.php) when whole.
     * A source that can't export by parts gets the old single request.
     */
    public function fetch_database_paced( $budget, $batch_bytes, $fingerprints = false, $skip_unchanged = false ) {
        $deadline   = Pace::deadline( $budget );
        $batch_bytes = max( 256 * KB_IN_BYTES, (int) $batch_bytes );
        $state_file = dirname( $this->state_file ) . '/database-state.php';
        $parts_dir  = $this->staging_public . '/.disembark-db-parts';
        $st         = json_decode( (string) Import::read_protected( $state_file ), true );
        // Behind the exit line from the first byte. A pull an older build
        // started keeps its plain names to the end.
        $guarded    = ! is_array( $st ) || ! empty( $st['guarded'] );
        $part_file  = $parts_dir . ( $guarded ? '/database-pull.part.php' : '/database-pull.sql.part' );
        $dest       = $this->staging_public . '/' . ( $guarded ? Import::staged_name( 'database-pull.sql' ) : 'database-pull.sql' );
        $state      = $this->state();
        $schema_only = array_values( (array) ( $state['skip_rows'] ?? [] ) );
        if ( ! is_array( $st ) ) {
            foreach ( [ Import::staged_name( 'database-pull.sql' ), 'database-pull.sql' ] as $name ) {
                if ( ! empty( $state['db_done'] ) && file_exists( $this->staging_public . '/' . $name ) ) {
                    return [ 'done' => true, 'ok' => true, 'tables' => 0, 'bytes' => filesize( $this->staging_public . '/' . $name ), 'plan' => self::plan_summary( $this->plan() ) ];
                }
            }
            $pre = $this->source_request( '/import/preflight', [ 'import_id' => $this->source_session() ], 'POST', max( 10, (int) ceil( Pace::left( $deadline ) ) ) );
            if ( is_wp_error( $pre ) ) {
                return self::paced_error( $pre );
            }
            $tables = ( is_object( $pre ) && ! empty( $pre->tables ) ) ? array_values( (array) $pre->tables ) : [];
            if ( empty( $tables ) ) {
                return new \WP_Error( 'no_tables', 'Source returned no tables to export.', [ 'status' => 502 ] );
            }
            if ( empty( $pre->pacing ) ) {
                // An older source: the whole database in one request.
                $old = $this->fetch_database();
                $out = is_wp_error( $old ) ? self::paced_error( $old ) : $old + [ 'done' => true, 'single' => true ];
                if ( ! is_wp_error( $out ) && $fingerprints && $skip_unchanged && ! empty( $state['catch_up'] ) ) {
                    $out['plan'] = [ 'copied' => count( $tables ), 'skipped' => [], 'soft' => [], 'changed' => [], 'nofp' => 'the source runs an older Disembark without table fingerprints, so every table was copied' ];
                }
                return $out;
            }
            $info = [];
            foreach ( (array) ( $state['tables'] ?? [] ) as $t ) {
                $info[ (string) $t['name'] ] = [ (int) ( $t['size'] ?? 0 ), (int) ( $t['rows'] ?? 0 ) ];
            }
            $st = [ 't' => 0, 'part' => null, 'bytes' => strlen( Import::PHP_EXIT ), 'tables' => [], 'guarded' => 1 ];
            foreach ( $tables as $name ) {
                $st['tables'][] = [ (string) $name, $info[ (string) $name ][0] ?? 0, $info[ (string) $name ][1] ?? 0 ];
            }
            // Fingerprints first (see Fingerprint): always taken before the
            // export, so a table that changes in between is copied again
            // next time rather than skipped by mistake.
            $st['fp'] = $fingerprints && ! $this->import->target() ? $this->plan_init( $pre, $st['tables'], $schema_only, $skip_unchanged ) : null;
            wp_mkdir_p( $parts_dir );
            @file_put_contents( $part_file, Import::PHP_EXIT );
            self::write_protected_json( $state_file, $st );
        }
        $save = function () use ( &$st, $state_file ) {
            self::write_protected_json( $state_file, $st );
        };
        if ( ! empty( $st['fp'] ) && $st['fp']['phase'] !== 'done' ) {
            $step = $this->plan_step( $st, $deadline, $save );
            if ( is_wp_error( $step ) ) {
                return $step;
            }
            $save();
            if ( $st['fp']['phase'] !== 'done' ) {
                return [ 'done' => false, 'phase' => 'compare', 'side' => $st['fp']['phase'] === 'dst' ? 'destination' : 'source', 'compared' => count( $st['fp']['src'] ) + count( $st['fp']['why'] ) + count( $st['fp']['dst'] ), 'total' => count( $st['fp']['large'] ) ];
            }
        }
        $count   = count( $st['tables'] );
        $timeout = max( 10, (int) ceil( Pace::left( $deadline ) ) );
        $batch   = [];
        if ( (int) $st['t'] < $count ) {
            $batch = $this->export_batch( $st, $part_file, $batch_bytes, $schema_only, $state, $timeout );
            if ( is_wp_error( $batch ) ) {
                return $batch;
            }
            $save();
        }
        if ( $st['t'] < $count ) {
            return [ 'done' => false, 'tables_done' => (int) $st['t'], 'total_tables' => $count, 'bytes' => (int) $st['bytes'], 'batch' => $batch, 'table' => $st['tables'][ $st['t'] ][0] ];
        }

        // Whole: the dump takes its name, and the source's session and token
        // go, unless skipped tables still have to be checked once more at
        // the end (see settle()).
        if ( ! @rename( $part_file, $dest ) ) {
            return new \WP_Error( 'write_failed', 'Could not save the database in staging (is the disk full?).', [ 'status' => 500 ] );
        }
        @rmdir( $parts_dir );
        @unlink( $state_file );
        $plan = $this->plan();
        if ( empty( $plan['skipped'] ) ) {
            $this->cleanup_source();
        }
        $state = $this->state();
        if ( empty( $plan['skipped'] ) ) {
            unset( $state['source_token'] );
        }
        $state['db_done'] = true;
        $this->save_state( $state );
        return [ 'done' => true, 'ok' => true, 'tables' => $count, 'bytes' => filesize( $dest ), 'batch' => $batch, 'plan' => self::plan_summary( $plan ) ];
    }

    /**
     * Exports one batch from the source into $part_file: a group of the
     * tables in $st (from $st['t'], up to $batch_bytes), or a part of a
     * table bigger than that, by primary key. Moves $st on. Returns what
     * the batch held, or WP_Error.
     */
    private function export_batch( array &$st, $part_file, $batch_bytes, $schema_only, $state, $timeout ) {
        $skip  = array_flip( $schema_only );
        $count = count( $st['tables'] );
        $t     = (int) $st['t'];
        list( $name, $size, $rows ) = $st['tables'][ $t ];
        $big = $size > $batch_bytes && $rows > 0 && ! isset( $skip[ $name ] );
        if ( $st['part'] !== null || $big ) {
            // Part of one large table, by primary key.
            $per   = max( 200, (int) floor( $batch_bytes / max( 1, $size / max( 1, $rows ) ) ) );
            $prev  = (array) ( $st['part'] ?? [] );
            $resp  = $this->source_request( '/export-database-batch', [ 'tables' => [ $name ], 'schema_only' => $schema_only, 'shared_rows' => ! empty( $state['shared_rows'] ), 'part' => [ 'after' => $prev['key'] ?? null, 'offset' => (int) ( $prev['offset'] ?? 0 ), 'rows' => $per, 'head' => $st['part'] === null ] ], 'POST', $timeout );
            if ( is_wp_error( $resp ) ) {
                return self::paced_error( $resp );
            }
            if ( ! is_object( $resp ) || empty( $resp->url ) || ! is_string( $resp->url ) ) {
                return new \WP_Error( 'export_failed', 'Source did not return a part of ' . $name . '.', [ 'status' => 502 ] );
            }
            $tail = '';
            $part = [ 'key' => $resp->key ?? null, 'offset' => (int) ( $resp->offset ?? 0 ), 'rows' => (int) ( $prev['rows'] ?? 0 ) + (int) ( $resp->rows ?? 0 ) ];
            if ( ! empty( $resp->done ) ) {
                $tail = '/*!40000 ALTER TABLE ' . Backup::ident( $name ) . " ENABLE KEYS */;\nSET @disembark_rows = '" . Backup::db_escape( $name ) . ':' . $part['rows'] . "';\n";
            }
            $added = $this->append_source_file( $resp->url, $part_file, (int) $st['bytes'], $tail, $timeout );
            if ( is_wp_error( $added ) ) {
                return $added;
            }
            $st['bytes'] = $added;
            $st['part']  = empty( $resp->done ) ? $part : null;
            $st['t']     = empty( $resp->done ) ? $t : $t + 1;
            return [ 'tables' => 1, 'part' => true, 'rows' => (int) ( $resp->rows ?? 0 ) ];
        }
        // A group of tables, up to the batch size.
        $group = [];
        $sum   = 0;
        for ( $i = $t; $i < $count && count( $group ) < 100; $i++ ) {
            list( $n, $sz, $rw ) = $st['tables'][ $i ];
            $is_big = $sz > $batch_bytes && $rw > 0 && ! isset( $skip[ $n ] );
            if ( $group && ( $is_big || $sum + $sz > $batch_bytes ) ) {
                break;
            }
            $group[] = $n;
            $sum    += $sz;
        }
        $url = $this->source_request( '/export-database-batch', [ 'tables' => $group, 'schema_only' => $schema_only, 'shared_rows' => ! empty( $state['shared_rows'] ) ], 'POST', $timeout );
        if ( is_wp_error( $url ) ) {
            return self::paced_error( $url );
        }
        if ( ! is_string( $url ) || ! preg_match( '#^https?://#', $url ) ) {
            return new \WP_Error( 'export_failed', 'Source did not return a database URL.', [ 'status' => 502 ] );
        }
        $added = $this->append_source_file( $url, $part_file, (int) $st['bytes'], '', $timeout );
        if ( is_wp_error( $added ) ) {
            return $added;
        }
        $st['bytes'] = $added;
        $st['t']     = $t + count( $group );
        return [ 'tables' => count( $group ), 'part' => false, 'bytes' => $sum ];
    }

    /* ------------------------------------------------------------------
     * Skipping unchanged tables on a catch-up (see Fingerprint)
     *
     * A table is left as it is only when the source's fingerprint matches
     * the one it had at the end of the last pull AND this site's matches
     * the one it had then: each side is only ever compared with itself.
     * Anything in doubt (small, a view, no primary key, an older source,
     * nothing recorded last time, a fingerprint that failed) is copied.
     * Skipped tables are fingerprinted on the source once more after the
     * import, and copied again if they changed meanwhile.
     * ---------------------------------------------------------------- */

    private function plan_file() {
        return dirname( $this->state_file ) . '/db-plan.php';
    }

    /** This pull's table plan (see plan_step()), or null. */
    public function plan() {
        $plan = json_decode( (string) Import::read_protected( $this->plan_file() ), true );
        return is_array( $plan ) ? $plan : null;
    }

    private function save_plan( $plan ) {
        self::write_protected_json( $this->plan_file(), $plan );
    }

    /** What the client is told about the plan. */
    private static function plan_summary( $plan ) {
        if ( ! is_array( $plan ) || empty( $plan['skip'] ) ) {
            return null;
        }
        return [ 'copied' => count( (array) $plan['copied'] ), 'skipped' => array_values( (array) $plan['skipped'] ), 'soft' => (object) ( $plan['soft'] ?? [] ), 'changed' => (object) ( $plan['changed'] ?? [] ), 'nofp' => (string) ( $plan['nofp'] ?? '' ) ];
    }

    /**
     * The incoming site's tables by the names they have here (lowercase
     * keys): every table the source has, copied this time or not (a catch-up
     * leaves unchanged ones alone). Empty when there's no pull to go by.
     */
    public function incoming_tables() {
        $out = [];
        foreach ( (array) ( $this->state()['tables'] ?? [] ) as $t ) {
            $name = (string) ( $t['name'] ?? '' );
            if ( $name !== '' ) {
                $out[ strtolower( $this->dest_name( $name ) ) ] = true;
            }
        }
        return $out;
    }

    /** The name a source table gets here (the import swaps the prefix). */
    private function dest_name( $table ) {
        global $wpdb;
        $old = (string) ( $this->state()['source_prefix'] ?? '' );
        $new = (string) $wpdb->prefix;
        return $old !== '' && $new !== '' && $old !== $new && strpos( $table, $old ) === 0 ? $new . substr( $table, strlen( $old ) ) : $table;
    }

    private function plan_init( $pre, $tables, $schema_only, $skip_unchanged ) {
        $state  = $this->state();
        $skip   = array_flip( (array) $schema_only );
        $large  = [];
        $map    = [];
        foreach ( $tables as list( $name, $size ) ) {
            $map[ $name ] = $this->dest_name( $name );
            if ( $size >= Fingerprint::MIN_BYTES && ! isset( $skip[ $name ] ) ) {
                $large[] = $name;
            }
        }
        $nofp = '';
        if ( ! empty( $pre->scope ) ) {
            $nofp = 'the source is one site of a network or WP Freighter install, so every table was copied';
        } elseif ( empty( $pre->fingerprint ) ) {
            $nofp = 'the source runs an older Disembark without table fingerprints, so every table was copied';
        }
        $then = [];
        $last = ! empty( $state['catch_up'] ) ? self::last_pull( self::memory_source( $state ) ) : null;
        if ( $last && ! empty( $last['tables'] ) && self::newest_pull( $last ) ) {
            $then = [ 'tables' => (array) $last['tables'], 'all' => (array) ( $last['all_tables'] ?? [] ) ];
        }
        return [
            'phase' => $nofp === '' && $large ? 'src' : 'decide',
            'skip'  => (bool) $skip_unchanged && ! empty( $state['catch_up'] ),
            'large' => $large,
            'map'   => $map,
            'all'   => array_keys( $map ),
            'then'  => $then,
            'nofp'  => $nofp,
            'src'   => [],
            'dst'   => [],
            'why'   => [],
            'cur'   => [],
            'dcur'  => [],
        ];
    }

    /**
     * Fingerprints $tables on the source until $deadline, from $cur (moved
     * on). A request that fails is counted in the cursor before it's sent,
     * so a slice that keeps failing gets smaller and then gives up on its
     * table (see Fingerprint::run()). Results go into $src / $why. Returns
     * true when done, false when out of time, or WP_Error to try again.
     */
    private function source_fingerprints( $tables, array &$cur, array &$src, array &$why, $deadline, $save ) {
        $tables = array_values( $tables );
        do {
            $t = (int) ( $cur['t'] ?? 0 );
            if ( $t >= count( $tables ) ) {
                return true;
            }
            $tries = (int) ( $cur['tries'] ?? 0 );
            $slice = (int) ( $cur['slice'] ?? Fingerprint::SLICE );
            // Mirror Fingerprint::run()'s halving here too: if the source
            // keeps dying before answering, its own halving never runs.
            if ( $tries >= 2 ) {
                if ( $slice <= Fingerprint::MIN_SLICE ) {
                    $why[ $tables[ $t ] ] = 'fingerprinting it didn\'t finish in time';
                    $cur = [
                        't'      => $t + 1,
                        'key'    => null,
                        'rows'   => 0,
                        's1'     => '0',
                        's2'     => '0',
                        'struct' => '',
                        'slice'  => Fingerprint::SLICE,
                        'tries'  => 0,
                        'b'      => (int) ( $cur['b'] ?? Pace::MAX_BUDGET ),
                        'fails'  => (int) ( $cur['fails'] ?? 0 ),
                    ];
                    $save();
                    if ( $cur['t'] >= count( $tables ) ) {
                        return true;
                    }
                    continue;
                }
                $cur['slice'] = max( Fingerprint::MIN_SLICE, (int) ( $slice / 2 ) );
                $cur['tries'] = 0;
                $tries        = 0;
            }
            // The source's own host may stop it sooner than this one stops
            // us: after a failed request it gets half the time ('b' stays here).
            $budget       = (int) min( max( 2, floor( Pace::left( $deadline ) ) - 1 ), (int) ( $cur['b'] ?? Pace::MAX_BUDGET ) );
            if ( $tries > 0 ) {
                $budget = max( 2, (int) floor( $budget / 2 ) );
            }
            $sent         = array_diff_key( $cur, [ 'b' => 1, 'fails' => 1 ] );
            $cur['tries'] = $tries + 1;
            $cur['b']     = $budget;
            $save();
            $resp = $this->source_request( '/fingerprint', [ 'tables' => $tables, 'cur' => $sent ?: (object) [], 'budget' => $budget ], 'POST', max( 10, (int) ceil( Pace::left( $deadline ) ) + 5 ) );
            if ( is_wp_error( $resp ) ) {
                $data          = $resp->get_error_data();
                $source_status = is_array( $data ) ? (int) ( $data['source_status'] ?? 0 ) : 0;
                $dropped       = $resp->get_error_code() === 'source_unreachable' || self::dropped( $resp );
                $transient     = ( $source_status === 0 && $dropped ) || in_array( $source_status, [ 429, 502, 503, 504 ], true );
                $fails         = (int) ( $cur['fails'] ?? 0 ) + 1;
                if ( $transient && $fails < 15 ) {
                    $cur['fails'] = $fails;
                    $save();
                    return self::paced_error( $resp );
                }
                // Never fails the move: whatever is left is copied.
                foreach ( array_slice( $tables, $t ) as $table ) {
                    $why[ $table ] = 'the source couldn\'t fingerprint it (' . substr( $resp->get_error_message(), 0, 100 ) . ')';
                }
                $cur['t'] = count( $tables );
                $save();
                return true;
            }
            $resp = json_decode( (string) wp_json_encode( $resp ), true );
            if ( ! is_array( $resp ) || ( ! array_key_exists( 'results', $resp ) && ! array_key_exists( 'done', $resp ) ) ) {
                foreach ( array_slice( $tables, $t ) as $table ) {
                    $why[ $table ] = 'the source returned an unexpected fingerprint response';
                }
                $cur['t'] = count( $tables );
                $save();
                return true;
            }
            foreach ( (array) ( $resp['results'] ?? [] ) as $table => $r ) {
                if ( isset( $r['fp'] ) ) {
                    $src[ $table ] = (string) $r['fp'];
                } else {
                    $why[ $table ] = (string) ( $r['unknown'] ?? 'unknown' );
                }
            }
            $cur          = (array) ( $resp['cur'] ?? [] ) + [ 'b' => $budget ];
            $cur['fails'] = 0;
            $save();
            if ( ! empty( $resp['done'] ) ) {
                return true;
            }
        } while ( Pace::left( $deadline ) > 3 );
        return false;
    }

    /** Fingerprints $tables here until $deadline (see source_fingerprints()). */
    private static function local_fingerprints( $tables, array &$cur, array &$out, array &$why, $deadline, $save ) {
        $r = Fingerprint::run( $tables, $cur, $deadline, function ( $c ) use ( &$cur, $save ) {
            $cur = $c;
            $save();
        } );
        foreach ( $r['results'] as $table => $one ) {
            if ( isset( $one['fp'] ) ) {
                $out[ $table ] = $one['fp'];
            } else {
                $why[ $table ] = $one['unknown'];
            }
        }
        $cur = $r['cur'];
        return $r['done'];
    }

    /** One call's worth of the plan: source fingerprints, this site's, then the decision. */
    private function plan_step( array &$st, $deadline, $save ) {
        $fp = &$st['fp'];
        if ( $fp['phase'] === 'src' ) {
            $done = $this->source_fingerprints( $fp['large'], $fp['cur'], $fp['src'], $fp['why'], $deadline, $save );
            if ( is_wp_error( $done ) ) {
                return $done;
            }
            if ( ! $done ) {
                return true;
            }
            $fp['phase'] = $fp['skip'] && ! empty( $fp['then']['tables'] ) ? 'dst' : 'decide';
            $save();
        }
        if ( $fp['phase'] === 'dst' ) {
            // Only tables that could be skipped: unchanged on the source.
            $here = Fingerprint::sizes();
            $todo = [];
            foreach ( $fp['large'] as $t ) {
                $then = $fp['then']['tables'][ $t ] ?? null;
                $d    = $fp['map'][ $t ];
                if ( isset( $fp['src'][ $t ], $then['src'], $then['dst'], $here[ $d ] ) && $then['src'] === $fp['src'][ $t ] && ( $then['as'] ?? $d ) === $d ) {
                    $todo[] = $d;
                }
            }
            $dwhy = [];
            if ( ! self::local_fingerprints( $todo, $fp['dcur'], $fp['dst'], $dwhy, $deadline, $save ) ) {
                foreach ( $dwhy as $d => $reason ) {
                    $fp['dst'][ $d ] = 'unknown: ' . $reason;
                }
                return true;
            }
            foreach ( $dwhy as $d => $reason ) {
                $fp['dst'][ $d ] = 'unknown: ' . $reason;
            }
            $fp['phase'] = 'decide';
        }
        if ( $fp['phase'] === 'decide' ) {
            $this->plan_decide( $st );
            $fp['phase'] = 'done';
        }
        return true;
    }

    /** Decides table by table, saves the plan, and keeps only the tables to copy in the export. */
    private function plan_decide( array &$st ) {
        $fp      = $st['fp'];
        $state   = $this->state();
        $schema  = array_flip( (array) ( $state['skip_rows'] ?? [] ) );
        $large   = array_flip( $fp['large'] );
        $then    = (array) ( $fp['then']['tables'] ?? [] );
        $before  = array_flip( (array) ( $fp['then']['all'] ?? [] ) );
        $copy    = [];
        $skipped = [];
        $soft    = [];
        $changed = [];
        foreach ( $st['tables'] as $row ) {
            $t = $row[0];
            $d = $fp['map'][ $t ];
            if ( ! $fp['skip'] || isset( $schema[ $t ] ) || ! isset( $large[ $t ] ) || $fp['nofp'] !== '' ) {
                $copy[] = $row;
                continue;
            }
            $was = $then[ $t ] ?? null;
            $dst = $fp['dst'][ $d ] ?? null;
            if ( isset( $fp['why'][ $t ] ) ) {
                $soft[ $t ] = $fp['why'][ $t ];
            } elseif ( ! $was ) {
                if ( $before && ! isset( $before[ $t ] ) ) {
                    $changed[ $t ] = 'new on the source';
                } else {
                    $soft[ $t ] = 'no fingerprint from the last pull';
                }
            } elseif ( empty( $was['src'] ) || empty( $was['dst'] ) ) {
                $soft[ $t ] = 'no fingerprint from the last pull';
            } elseif ( $was['src'] !== ( $fp['src'][ $t ] ?? '' ) ) {
                $changed[ $t ] = 'changed on the source';
            } elseif ( $dst === null ) {
                $soft[ $t ] = 'not on this site any more';
            } elseif ( strpos( (string) $dst, 'unknown: ' ) === 0 ) {
                $soft[ $t ] = substr( $dst, 9 ) . ' (here)';
            } elseif ( $dst !== $was['dst'] ) {
                $changed[ $t ] = 'changed on this site';
            } else {
                $skipped[] = $t;
                continue;
            }
            $copy[] = $row;
        }
        $st['tables'] = $copy;
        $st['t']      = 0;
        $this->save_plan( [
            'skip'        => $fp['skip'],
            'skipped'     => $skipped,
            'skipped_dst' => array_values( array_map( function ( $t ) use ( $fp ) { return $fp['map'][ $t ]; }, $skipped ) ),
            'copied'      => array_column( $copy, 0 ),
            'soft'        => $soft,
            'changed'     => $changed,
            'nofp'        => $fp['nofp'],
            'large'       => $fp['large'],
            'map'         => $fp['map'],
            'all'         => $fp['all'],
            'src'         => $fp['src'],
            'src_why'     => $fp['why'],
        ] );
    }

    /** The catch-up recheck's dump: behind the exit line, unless an older build started it. */
    private static function recheck_name( $st ) {
        return ! isset( $st['x'] ) || ! empty( $st['x']['guarded'] ) ? 'recheck.sql.php' : 'recheck.sql';
    }

    /**
     * After the address rewrite: fingerprints the skipped tables on the
     * source once more and copies again any that changed during the move
     * (into the restore point first, then exported, imported and rewritten
     * like the rest), then fingerprints this site's tables and keeps both
     * sides' fingerprints for the next catch-up (see remember_files()).
     * $from / $to: the address rewrite's. Paced like the other steps.
     */
    public function settle( $budget, $from, $to ) {
        $deadline = Pace::deadline( $budget );
        $plan     = $this->plan();
        if ( ! $plan ) {
            return [ 'done' => true, 'none' => true ];
        }
        $dir        = dirname( $this->state_file );
        $state_file = $dir . '/settle-state.php';
        $st         = json_decode( (string) Import::read_protected( $state_file ), true );
        $state      = $this->state();
        if ( ! is_array( $st ) ) {
            $st = [ 'phase' => ! empty( $plan['skipped'] ) && ! empty( $state['source_token'] ) ? 'recheck' : 'cleanup', 'cur' => [], 'src' => [], 'why' => [], 'changed' => [], 'dcur' => [], 'dst' => [], 'dwhy' => [], 'x' => null ];
        }
        $save = function () use ( &$st, $state_file ) {
            self::write_protected_json( $state_file, $st );
        };
        $more = function ( $what, $extra = [] ) use ( &$st, $save ) {
            $save();
            return [ 'done' => false, 'phase' => $st['phase'], 'what' => $what ] + $extra;
        };
        $import = $this->import;
        $parts  = $this->staging_public . '/.disembark-db-parts';

        if ( $st['phase'] === 'recheck' ) {
            if ( empty( $st['cur'] ) ) {
                /** Fires once before the skipped tables are fingerprinted on the source again. */
                do_action( 'disembark_catch_up_recheck', $plan['skipped'], $this->import_id );
            }
            $done = $this->source_fingerprints( $plan['skipped'], $st['cur'], $st['src'], $st['why'], $deadline, $save );
            if ( is_wp_error( $done ) ) {
                return $done;
            }
            if ( ! $done ) {
                return $more( 'Checking the skipped tables on the source once more' );
            }
            foreach ( $plan['skipped'] as $t ) {
                if ( ( $st['src'][ $t ] ?? null ) !== ( $plan['src'][ $t ] ?? '' ) ) {
                    $st['changed'][] = $t;
                }
            }
            $st['phase'] = $st['changed'] ? 'save' : 'cleanup';
            $save();
        }
        if ( $st['phase'] === 'save' ) {
            $saved = $import->snapshot_add( array_map( function ( $t ) use ( $plan ) { return $plan['map'][ $t ]; }, $st['changed'] ), Pace::left( $deadline ) );
            if ( is_wp_error( $saved ) ) {
                return $saved;
            }
            if ( empty( $saved['done'] ) ) {
                return $more( 'Adding the tables that changed to the restore point' );
            }
            $info = [];
            foreach ( (array) ( $state['tables'] ?? [] ) as $t ) {
                $info[ (string) $t['name'] ] = [ (int) ( $t['size'] ?? 0 ), (int) ( $t['rows'] ?? 0 ) ];
            }
            // Behind the exit line, like the pull's own dump.
            $st['x'] = [ 't' => 0, 'part' => null, 'bytes' => strlen( Import::PHP_EXIT ), 'tables' => array_map( function ( $t ) use ( $info ) { return [ $t, $info[ $t ][0] ?? 0, $info[ $t ][1] ?? 0 ]; }, $st['changed'] ), 'guarded' => 1 ];
            wp_mkdir_p( $parts );
            @file_put_contents( $parts . '/' . self::recheck_name( $st ), Import::PHP_EXIT );
            $st['phase'] = 'fetch';
            $save();
        }
        if ( $st['phase'] === 'fetch' ) {
            while ( $st['x']['t'] < count( $st['x']['tables'] ) ) {
                $b = $this->export_batch( $st['x'], $parts . '/' . self::recheck_name( $st ), 16 * MB_IN_BYTES, (array) ( $state['skip_rows'] ?? [] ), $state, max( 10, (int) ceil( Pace::left( $deadline ) ) ) );
                if ( is_wp_error( $b ) ) {
                    return $b;
                }
                $save();
                if ( Pace::left( $deadline ) < 3 ) {
                    return $more( 'Copying the tables that changed again' );
                }
            }
            $st['phase'] = 'import';
            $save();
        }
        if ( $st['phase'] === 'import' ) {
            global $wpdb;
            $sq = $import->import_staged_sql( 'public/.disembark-db-parts/' . self::recheck_name( $st ), (string) ( $state['source_prefix'] ?? '' ), (string) $wpdb->prefix, Pace::left( $deadline ), true );
            if ( is_wp_error( $sq ) ) {
                return $sq;
            }
            if ( ! empty( $sq['errors'] ) ) {
                return new \WP_Error( 'recheck_failed', 'Copying the tables that changed during the catch-up again failed: ' . $sq['errors'][0], [ 'status' => 500 ] );
            }
            if ( isset( $sq['done'] ) && ! $sq['done'] ) {
                return $more( 'Importing the tables that changed again' );
            }
            $st['phase'] = 'rewrite';
            $save();
        }
        if ( $st['phase'] === 'rewrite' ) {
            $sr = $import->rewrite_tables( $from, $to, array_map( function ( $t ) use ( $plan ) { return $plan['map'][ $t ]; }, $st['changed'] ), Pace::left( $deadline ) );
            if ( is_wp_error( $sr ) ) {
                return $sr;
            }
            if ( isset( $sr['done'] ) && ! $sr['done'] ) {
                return $more( 'Rewriting addresses in the tables that changed' );
            }
            @unlink( $parts . '/' . self::recheck_name( $st ) );
            $st['phase'] = 'cleanup';
            $save();
        }
        if ( $st['phase'] === 'cleanup' ) {
            // Done with the source: its session and its token go.
            if ( ! empty( $state['source_token'] ) ) {
                $this->cleanup_source();
                $state = $this->state();
                unset( $state['source_token'] );
                $this->save_state( $state );
            }
            $st['phase'] = 'dst';
            $save();
        }
        if ( $st['phase'] === 'dst' ) {
            $here = Fingerprint::sizes();
            $todo = [];
            foreach ( $plan['large'] as $t ) {
                if ( isset( $here[ $plan['map'][ $t ] ] ) ) {
                    $todo[] = $plan['map'][ $t ];
                }
            }
            if ( ! self::local_fingerprints( $todo, $st['dcur'], $st['dst'], $st['dwhy'], $deadline, $save ) ) {
                return $more( 'Fingerprinting this site\'s tables for next time', [ 'done_tables' => count( $st['dst'] ) + count( $st['dwhy'] ), 'total' => count( $todo ) ] );
            }
            // Kept for next time: each source table's fingerprint (the new
            // one for a table copied again) beside this site's.
            $src = (array) $plan['src'];
            foreach ( $st['changed'] as $t ) {
                $src[ $t ] = $st['src'][ $t ] ?? null; // fingerprinted before its second copy (or unknown)
            }
            $tables = [];
            foreach ( $plan['large'] as $t ) {
                $tables[ $t ] = [ 'src' => $src[ $t ] ?? null, 'dst' => $st['dst'][ $plan['map'][ $t ] ] ?? null, 'as' => $plan['map'][ $t ] ];
            }
            self::write_protected_json( $dir . '/fingerprints.php', [ 'tables' => $tables, 'all' => $plan['all'], 'at' => time() ] );
            @unlink( $state_file );
            $skipped = array_values( array_diff( (array) $plan['skipped'], $st['changed'] ) );
            return [
                'done'      => true,
                'skip'      => ! empty( $plan['skip'] ),
                'skipped'   => $skipped,
                'copied'    => count( (array) $plan['copied'] ) + count( $st['changed'] ),
                'recopied'  => $st['changed'],
                'soft'      => (object) ( (array) $plan['soft'] + array_map( function ( $r ) { return $r . ' (here, for next time)'; }, array_diff_key( $st['dwhy'], array_flip( array_map( function ( $t ) use ( $plan ) { return $plan['map'][ $t ]; }, array_keys( (array) $plan['soft'] ) ) ) ) ) ),
                'nofp'      => (string) $plan['nofp'],
            ];
        }
        return $more( '' );
    }

    /**
     * Downloads a file the source made, appends it (and $tail) to $file at
     * byte $at (anything past it, from a killed call, goes first), and
     * removes it from the source. Returns the new size or WP_Error.
     */
    private function append_source_file( $url, $file, $at, $tail, $timeout ) {
        $tmp = $this->fetch_source_file( $url, $timeout );
        $this->cleanup_source_file( $url );
        if ( is_wp_error( $tmp ) ) {
            return self::paced_error( $tmp );
        }
        $out = @fopen( $file, 'c+b' );
        $in  = @fopen( $tmp, 'rb' );
        $ok  = $out && $in && ftruncate( $out, $at ) && fseek( $out, $at ) === 0 && stream_copy_to_stream( $in, $out ) !== false && ( $tail === '' || fwrite( $out, $tail ) === strlen( $tail ) );
        $size = $ok ? ftell( $out ) : 0;
        if ( $in ) {
            fclose( $in );
        }
        if ( $out ) {
            $ok = fclose( $out ) && $ok;
        }
        @unlink( $tmp );
        if ( ! $ok ) {
            return new \WP_Error( 'write_failed', 'Could not save the database in staging (is the disk full?).', [ 'status' => 500 ] );
        }
        return $size;
    }

    /** The scanned file list, before any choices were applied. */
    private function all_files() {
        $state = $this->state();
        if ( empty( $state['file_list'] ) || ! file_exists( $state['file_list'] ) ) {
            return new \WP_Error( 'no_file_list', 'Run the scan step first.', [ 'status' => 400 ] );
        }
        $all = $state['file_list'] . '.all';
        if ( ! file_exists( $all ) ) {
            copy( $state['file_list'], $all );
        }
        $files = json_decode( (string) file_get_contents( $all ), true );
        return is_array( $files ) ? $this->movable( $files ) : [];
    }

    /**
     * The files this move can use, whatever the source sent (an older one
     * sends more): never an opcode-cache helper (__opcache_*), never the
     * install's wp-config.php with one of its sites, and into a subsite or
     * tenant only wp-content (it never places core or root files, so they
     * aren't fetched).
     */
    private function movable( $files ) {
        $state  = $this->state();
        $scoped = ! empty( $state['scope'] );
        $into   = Target::load( dirname( $this->state_file ) ) !== null;
        return array_values( array_filter( $files, function ( $f ) use ( $scoped, $into ) {
            $name = (string) ( $f['name'] ?? '' );
            if ( strpos( basename( $name ), '__opcache_' ) === 0 || ( $scoped && $name === 'wp-config.php' ) ) {
                return false;
            }
            return ! $into || strpos( $name, 'wp-content/' ) === 0;
        } ) );
    }

    /** Whether the source is one site of an install other than its main site (its web root isn't the site's). */
    private static function scoped_tenant( $state ) {
        $scope = (array) ( $state['scope'] ?? [] );
        return $scope && empty( $scope['main'] ) && (string) ( $scope['site'] ?? '' ) !== 'main';
    }

    /**
     * What the source holds, for "What to bring": totals, folders, tables,
     * and the suggested skips that match it (see Skips).
     */
    public function contents() {
        $files = $this->all_files();
        if ( is_wp_error( $files ) ) {
            return $files;
        }
        $state = $this->state();
        $out = Skips::analyze( $files, (array) ( $state['tables'] ?? [] ), (string) ( $state['source_prefix'] ?? '' ), [ 'install_root' => self::scoped_tenant( $state ) ] );
        // One site of a network: its super admins with no role on it, for the
        // toggle (null: the source predates it, so they always come).
        $scope = (array) ( $state['scope'] ?? [] );
        if ( ( $scope['kind'] ?? '' ) === 'multisite' ) {
            $out['super_admins'] = isset( $scope['super_admins']['added'] ) ? array_values( array_map( 'strval', (array) $scope['super_admins']['added'] ) ) : null;
        }
        return $out;
    }

    /**
     * The super admins this pull brings as administrators (logins): the
     * ones with no role on the site, unless left out. Empty for anything
     * but one site of a network.
     */
    public function super_admins_brought() {
        $state = $this->state();
        $scope = (array) ( $state['scope'] ?? [] );
        if ( ( $scope['kind'] ?? '' ) !== 'multisite' || ( $state['super_admins'] ?? true ) === false ) {
            return [];
        }
        return array_values( array_map( 'strval', (array) ( $scope['super_admins']['added'] ?? [] ) ) );
    }

    /**
     * Applies "What to bring": leaves out skip_paths (folders or files),
     * files named one of skip_names anywhere, and the rows of skip_rows
     * (those tables still come across, empty). Safe to call again: it always
     * starts from the full scan.
     */
    public function select( $skip_paths = [], $skip_names = [], $skip_rows = [], $super_admins = null ) {
        $files = $this->all_files();
        if ( is_wp_error( $files ) ) {
            return $files;
        }
        $state  = $this->state();
        $known  = array_column( (array) ( $state['tables'] ?? [] ), 'name' );
        $rows   = array_values( array_intersect( array_map( 'strval', (array) $skip_rows ), $known ) );
        list( $kept, $left_files, $left_bytes ) = Skips::filter_files( $files, $skip_paths, $skip_names );

        file_put_contents( $state['file_list'], wp_json_encode( $kept ) );
        $state['total_files'] = count( $kept );
        $state['skip_rows']   = $rows;
        // One site of a network: whether its super admins with no role on it
        // come across as administrators (on by default).
        if ( $super_admins !== null ) {
            $state['super_admins'] = (bool) $super_admins;
        }
        $state['left_out']    = [
            'paths'  => Skips::topmost( array_values( array_filter( array_map( function ( $p ) { return trim( (string) $p, '/' ); }, (array) $skip_paths ) ) ) ),
            'names'  => array_values( array_map( 'strval', (array) $skip_names ) ),
            'files'  => $left_files,
            'bytes'  => $left_bytes,
            'rows'   => $rows,
        ];
        $this->save_state( $state );
        // The import reads this to drop those tables' rows (a source that
        // predates schema_only sends them anyway).
        file_put_contents( dirname( $this->state_file ) . '/skip-rows.json', wp_json_encode( $rows ) );

        $bytes = 0;
        foreach ( $kept as $f ) {
            $bytes += (int) ( $f['size'] ?? 0 );
        }
        return [ 'files' => count( $kept ), 'bytes' => $bytes, 'left_out' => $state['left_out'], 'super_admins' => $this->super_admins_brought() ];
    }

    /**
     * Extracts a zip into staging/public, skipping zip-slip entry names.
     */
    private function extract_into_public( $zip_path ) {
        if ( ! is_dir( $this->staging_public ) ) {
            mkdir( $this->staging_public, 0755, true );
        }
        $done = Import::extract_zip_into( $zip_path, $this->staging_public );
        return is_wp_error( $done ) ? new \WP_Error( 'extract_failed', 'Could not extract batch zip.', [ 'status' => 500 ] ) : true;
    }

    /**
     * Cleans up pull scratch state (file list, source token) after finalize.
     */
    /**
     * Asks the source to delete this pull's temporary files (its manifest
     * and export session). Best effort: the source's token is only kept
     * until the database has been pulled.
     */
    public function cleanup_source() {
        $state = $this->state();
        if ( empty( $state['source_token'] ) || empty( $state['backup_token'] ) ) {
            return false;
        }
        // POST on 3.0 sources; a 2.9 source only routes GET (reached with
        // the token kept in the body, see get_with_body()).
        $done = $this->source_request( '/cleanup', [ 'backup_token' => $state['backup_token'] ], 'POST', 30 );
        if ( is_wp_error( $done ) ) {
            $this->source_request( '/cleanup', [ 'backup_token' => $state['backup_token'] ], 'GET', 30 );
        }
        return true;
    }

    public function cleanup_pull_state() {
        @unlink( $this->state_file );
        @unlink( $this->secret_file );
        $list = $this->staging_dir() . '/file-list.json';
        @unlink( $list );
        return [ 'ok' => true ];
    }
}
