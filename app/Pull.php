<?php

namespace Disembark;

/**
 * Server-to-server "pull" restore: the destination fetches a backup directly
 * from a live source site's Disembark REST API (the same endpoints the CLI
 * uses), stages it, and hands off to the Import pipeline. Browser-driven in
 * short steps so no single request has to transfer a whole site.
 *
 * State for a pull lives in the import directory as pull.json.
 */
class Pull {

    private $import;
    private $import_id;
    private $staging_public;
    private $state_file;

    public function __construct( $import_id = '' ) {
        $this->import         = new Import( $import_id );
        $this->import_id      = $this->import->get_import_id();
        $base                 = wp_upload_dir()['basedir'] . '/disembark/_import_' . $this->import_id;
        $this->staging_public = $base . '/staging/public';
        $this->state_file     = $base . '/pull.json';
    }

    public function get_import_id() {
        return $this->import_id;
    }

    private function state() {
        if ( file_exists( $this->state_file ) ) {
            $s = json_decode( file_get_contents( $this->state_file ), true );
            if ( is_array( $s ) ) {
                return $s;
            }
        }
        return [];
    }

    private function save_state( $state ) {
        Storage::ensure_dir( dirname( $this->state_file ) );
        file_put_contents( $this->state_file, wp_json_encode( $state ) );
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
     * Calls the source site's Disembark REST API, honoring its permalink style.
     */
    private function source_request( $endpoint, $payload = [], $method = 'POST', $timeout = 120 ) {
        $state = $this->state();
        $url   = $this->source_endpoint( $endpoint );

        $body = array_merge(
            [ 'token' => $state['source_token'] ?? '', 'backup_token' => $state['backup_token'] ?? '' ],
            $payload
        );
        $verify = $this->sslverify();
        $args   = [
            'timeout'            => $timeout,
            'sslverify'          => $verify,
            'reject_unsafe_urls' => $verify,
            'headers'            => [ 'Content-Type' => 'application/json' ],
        ];

        if ( strtoupper( $method ) === 'GET' ) {
            $sep  = ( strpos( $url, '?' ) !== false ) ? '&' : '?';
            $resp = wp_remote_get( $url . $sep . http_build_query( $body ), $args );
        } else {
            $args['body'] = wp_json_encode( $body );
            $resp = wp_remote_post( $url, $args );
        }

        if ( is_wp_error( $resp ) ) {
            return $resp;
        }
        $code = wp_remote_retrieve_response_code( $resp );
        $raw  = wp_remote_retrieve_body( $resp );
        if ( $code !== 200 ) {
            // Don't echo the remote body back to the browser — it can contain
            // internal details from the source (or whatever answered for it).
            return new \WP_Error( 'source_error', "Source responded {$code} for {$endpoint}.", [ 'status' => 502 ] );
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
    private function guarded_download( $url ) {
        if ( ! is_string( $url ) || ! preg_match( '#^https?://#i', $url ) ) {
            return new \WP_Error( 'invalid_url', 'Refusing to download a non-http(s) URL from the source.', [ 'status' => 502 ] );
        }
        $verify = $this->sslverify();
        if ( $verify && ! wp_http_validate_url( $url ) ) {
            return new \WP_Error( 'invalid_url', 'Refusing to download an unsafe URL from the source.', [ 'status' => 502 ] );
        }
        if ( ! $verify ) {
            add_filter( 'https_ssl_verify', '__return_false' );
            add_filter( 'http_request_host_is_external', '__return_true' );
        }
        $tmp = download_url( $url, 600 );
        if ( ! $verify ) {
            remove_filter( 'https_ssl_verify', '__return_false' );
            remove_filter( 'http_request_host_is_external', '__return_true' );
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
    private function fetch_source_file( $url ) {
        $tmp = $this->guarded_download( $url );
        if ( ! is_wp_error( $tmp ) || $tmp->get_error_code() === 'invalid_url' ) {
            return $tmp;
        }
        $streamed = $this->stream_from_source( $url );
        return is_wp_error( $streamed ) ? $tmp : $streamed;
    }

    /**
     * Downloads one of the source's files through its /stream-file endpoint
     * (token-authenticated, every v2 source has it). Only for URLs on the
     * source's own host. Returns the temp path or WP_Error.
     */
    private function stream_from_source( $url ) {
        $state = $this->state();
        $base  = $state['source_url'] ?? '';
        $host  = is_string( $url ) ? strtolower( (string) parse_url( $url, PHP_URL_HOST ) ) : '';
        if ( $host === '' || $host !== strtolower( (string) parse_url( $base, PHP_URL_HOST ) ) ) {
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

        $tmp = wp_tempnam( basename( $relative ) );
        if ( ! $tmp ) {
            return new \WP_Error( 'download_failed', 'Could not create a temporary file.', [ 'status' => 500 ] );
        }
        $verify = $this->sslverify();
        $resp   = wp_remote_post( $this->source_endpoint( '/stream-file' ), [
            'timeout'            => 600,
            'stream'             => true,
            'filename'           => $tmp,
            'sslverify'          => $verify,
            'reject_unsafe_urls' => $verify,
            'headers'            => [ 'Content-Type' => 'application/json' ],
            'body'               => wp_json_encode( [ 'token' => $state['source_token'] ?? '', 'file' => $relative ] ),
        ] );
        if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) {
            @unlink( $tmp );
            $code = is_wp_error( $resp ) ? $resp->get_error_message() : wp_remote_retrieve_response_code( $resp );
            return new \WP_Error( 'download_failed', "Source could not stream {$relative} ({$code}).", [ 'status' => 502 ] );
        }
        return $tmp;
    }

    /**
     * Downloads a URL to a local path (streamed to disk).
     */
    private function download_to( $url, $dest_path ) {
        $tmp = $this->fetch_source_file( $url );
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
    public function connect( $source_url, $source_token, $move = [], $catch_up = false ) {
        $source_url = rtrim( $source_url, '/' );
        if ( ! preg_match( '#^https?://#', $source_url ) ) {
            $source_url = 'https://' . $source_url;
        }

        // Probe pretty route, then plain-permalink form.
        $style = null;
        foreach ( [ 'pretty', 'rest_route' ] as $candidate ) {
            $path = ( $candidate === 'rest_route' )
                ? '/?rest_route=/disembark/v1/database&token=' . rawurlencode( $source_token )
                : '/wp-json/disembark/v1/database?token=' . rawurlencode( $source_token );
            $verify = $this->sslverify();
            $resp   = wp_remote_get( $source_url . $path, [ 'timeout' => 60, 'sslverify' => $verify, 'reject_unsafe_urls' => $verify ] );
            if ( ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) === 200 ) {
                $tables = json_decode( wp_remote_retrieve_body( $resp ) );
                if ( ! empty( $tables ) ) {
                    $style = $candidate;
                    break;
                }
            }
        }
        if ( $style === null ) {
            return new \WP_Error( 'connect_failed', 'Could not reach the source or the token was rejected. Check the URL, token, and that Disembark is active on the source.', [ 'status' => 400 ] );
        }

        // Persist the connection (with a fresh source-side session token).
        $this->save_state( [
            'source_url'   => $source_url,
            'source_token' => $source_token,
            'rest_style'   => $style,
            'backup_token' => substr( bin2hex( random_bytes( 12 ) ), 0, 12 ),
            'started'      => time(),
            'move'         => is_array( $move ) ? $move : [],
            'catch_up'     => (bool) $catch_up,
        ] );

        // Source metadata (v2.8 sources expose /import/preflight).
        $pre = $this->source_request( '/import/preflight', [ 'import_id' => $this->import_id ], 'POST' );
        $home = $prefix = ''; $tables = []; $db_size = 0;
        if ( is_object( $pre ) ) {
            $home   = rtrim( $pre->home_url ?? '', '/' );
            $prefix = $pre->db_prefix ?? '';
            $tables = $pre->tables ?? [];
        }
        // Table sizes (for a size estimate) from /database.
        $db = $this->source_request( '/database', [], 'GET' );
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
        $state['source_home']   = $home;
        $state['source_prefix'] = $prefix;
        $state['tables']        = $table_info;
        $this->save_state( $state );

        // Stamp source metadata into staging so the import reads it like a backup.
        if ( ! is_dir( $this->staging_public ) ) {
            mkdir( $this->staging_public, 0755, true );
        }
        file_put_contents(
            $this->staging_public . '/disembark-source.json',
            wp_json_encode( [ 'home_url' => $home, 'site_url' => $home, 'db_prefix' => $prefix, 'abspath' => (string) ( $pre->abspath ?? '' ), 'content_dir' => (string) ( $pre->content_dir ?? '' ) ] )
        );

        return [
            'ok'          => true,
            'import_id'   => $this->import_id,
            'source_home' => $home,
            'db_prefix'   => $prefix,
            'table_count' => is_array( $tables ) ? count( $tables ) : 0,
            'db_size'     => $db_size,
        ];
    }

    /**
     * Drives one step of the source's manifest generation. Steps mirror the
     * source contract: initiate -> scan (repeat until scan_complete) ->
     * chunkify -> process_chunk (1..total) -> finalize. On finalize the file
     * list is downloaded and cached locally.
     */
    public function scan_step( $step, $chunk = 0 ) {
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
        $result = [ 'status' => 'ready', 'source_files' => count( $files ) ];

        // Catch-up: keep only files that are new or changed since the last
        // pull from this source; files that were brought then and are gone
        // from the source now are removed here (deploy_files, with a backup
        // for rollback).
        $last = ! empty( $state['catch_up'] ) ? self::last_pull( $state['source_home'] ?? $state['source_url'] ) : null;
        if ( $last ) {
            $before  = (array) $last['files'];
            $changed = [];
            $kept    = [];
            $approx  = false;
            foreach ( $files as $f ) {
                $old = $before[ $f['name'] ] ?? null;
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
            $state['catch_up_info'] = [ 'since' => (int) $last['at'], 'changed' => count( $changed ), 'unchanged' => count( $kept ), 'removed' => count( $removed ), 'approx' => $approx ];
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
                $out[] = [ 'source' => (string) $data['source'], 'at' => (int) $data['at'], 'files' => count( (array) $data['files'] ) ];
            }
        }
        usort( $out, function ( $a, $b ) { return $b['at'] - $a['at']; } );
        return $out;
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
        $source = (string) ( $state['source_home'] ?? '' ) ?: (string) ( $state['source_url'] ?? '' );
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
            file_put_contents( $rollback . '/pull-memory.json', wp_json_encode( [ 'file' => self::pull_file( $source ), 'previous' => $previous ] ) );
        }
        $body = wp_json_encode( [ 'source' => untrailingslashit( $source ), 'at' => time(), 'files' => $files ] );
        $tmp  = self::pull_file( $source ) . '.tmp';
        if ( ! is_string( $body ) || @file_put_contents( $tmp, "<?php exit; ?>\n" . $body ) === false ) {
            return false;
        }
        return @rename( $tmp, self::pull_file( $source ) );
    }

    /** Called by a rollback: puts back what this site remembered before the move. */
    public static function restore_memory( $rollback_path ) {
        $saved = $rollback_path . '/pull-memory.json';
        if ( ! is_file( $saved ) ) {
            return;
        }
        $memory = json_decode( (string) file_get_contents( $saved ), true );
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
            $has_point  = is_file( $dir . '/rollback/meta.json' );
            $touched    = max( (int) @filemtime( $dir ), (int) @filemtime( $dir . '/steps' ), (int) @filemtime( $state_file ) );
            if ( ! is_file( $state_file ) ) {
                continue;
            }
            if ( ! $has_point && $touched < time() - WEEK_IN_SECONDS ) {
                ( new Import( $id ) )->cancel( 'cleanup' );
                continue;
            }
            $state = json_decode( (string) file_get_contents( $state_file ), true );
            $state = is_array( $state ) ? $state : [];
            $out[] = [
                'id'             => $id,
                'source'         => (string) ( $state['source_home'] ?? '' ) ?: (string) ( $state['source_url'] ?? '' ),
                'started'        => (int) ( $state['started'] ?? 0 ),
                'touched'        => $touched,
                'move'           => (array) ( $state['move'] ?? [] ),
                'catch_up'       => ! empty( $state['catch_up'] ),
                'files_total'    => (int) ( $state['total_files'] ?? 0 ),
                'files_fetched'  => (int) ( $state['fetched'] ?? 0 ),
                'database'       => ! empty( $state['db_done'] ),
                'rollback_point' => $has_point,
            ];
        }
        usort( $out, function ( $a, $b ) { return $b['touched'] - $a['touched']; } );
        return $out;
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
        $pre = $this->source_request( '/import/preflight', [ 'import_id' => $this->import_id ], 'POST' );
        $tables = ( is_object( $pre ) && ! empty( $pre->tables ) ) ? $pre->tables : [];
        if ( empty( $tables ) ) {
            return new \WP_Error( 'no_tables', 'Source returned no tables to export.', [ 'status' => 502 ] );
        }
        // Tables whose rows stay behind (see select()). A source that predates
        // schema_only sends the rows anyway; the import drops them then.
        $state       = $this->state();
        $schema_only = array_values( (array) ( $state['skip_rows'] ?? [] ) );
        $url = $this->source_request( '/export-database-batch', [ 'tables' => $tables, 'schema_only' => $schema_only ], 'POST', 1800 );
        if ( is_wp_error( $url ) ) {
            return $url;
        }
        if ( ! is_string( $url ) || ! preg_match( '#^https?://#', $url ) ) {
            return new \WP_Error( 'export_failed', 'Source did not return a database URL.', [ 'status' => 502 ] );
        }
        $dest = $this->staging_public . '/database-pull.sql';
        $dl   = $this->download_to( $url, $dest );
        $this->cleanup_source_file( $url );
        if ( is_wp_error( $dl ) ) {
            return $dl;
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
        return is_array( $files ) ? $files : [];
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
        return Skips::analyze( $files, (array) ( $state['tables'] ?? [] ), (string) ( $state['source_prefix'] ?? '' ) );
    }

    /**
     * Applies "What to bring": leaves out skip_paths (folders or files),
     * files named one of skip_names anywhere, and the rows of skip_rows
     * (those tables still come across, empty). Safe to call again: it always
     * starts from the full scan.
     */
    public function select( $skip_paths = [], $skip_names = [], $skip_rows = [] ) {
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
        return [ 'files' => count( $kept ), 'bytes' => $bytes, 'left_out' => $state['left_out'] ];
    }

    /**
     * Extracts a zip into staging/public, skipping zip-slip entry names.
     */
    private function extract_into_public( $zip_path ) {
        if ( ! is_dir( $this->staging_public ) ) {
            mkdir( $this->staging_public, 0755, true );
        }
        $zip = class_exists( 'ZipArchive' ) ? new \ZipArchive() : null;
        if ( ! $zip || $zip->open( $zip_path ) !== true ) {
            if ( ! class_exists( 'PclZip' ) ) {
                require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
            }
            $pcl = new \PclZip( $zip_path );
            // Same zip-slip filtering as the ZipArchive branch — without the
            // guard PclZip would honour "../" entry names.
            if ( $pcl->extract( PCLZIP_OPT_PATH, $this->staging_public, PCLZIP_CB_PRE_EXTRACT, 'Disembark\\pclzip_pre_extract_guard' ) == 0 ) {
                return new \WP_Error( 'extract_failed', 'Could not extract batch zip.', [ 'status' => 500 ] );
            }
            return true;
        }
        $safe = [];
        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $name = $zip->getNameIndex( $i );
            if ( $name !== false && Import::is_safe_relative_path( $name ) ) {
                $safe[] = $name;
            }
        }
        if ( ! empty( $safe ) ) {
            $zip->extractTo( $this->staging_public, $safe );
        }
        $zip->close();
        return true;
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
        $this->source_request( '/cleanup', [ 'backup_token' => $state['backup_token'] ], 'GET', 30 );
        return true;
    }

    public function cleanup_pull_state() {
        @unlink( $this->state_file );
        $list = $this->staging_dir() . '/file-list.json';
        @unlink( $list );
        return [ 'ok' => true ];
    }
}
