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
        if ( ! is_dir( dirname( $this->state_file ) ) ) {
            mkdir( dirname( $this->state_file ), 0755, true );
        }
        file_put_contents( $this->state_file, wp_json_encode( $state ) );
    }

    /**
     * Calls the source site's Disembark REST API, honoring its permalink style.
     */
    private function source_request( $endpoint, $payload = [], $method = 'POST', $timeout = 120 ) {
        $state = $this->state();
        $base  = rtrim( $state['source_url'], '/' );
        $style = $state['rest_style'] ?? 'pretty';
        $path  = ( $style === 'rest_route' )
            ? '/?rest_route=/disembark/v1' . $endpoint
            : '/wp-json/disembark/v1' . $endpoint;
        $url = $base . $path;

        $body = array_merge(
            [ 'token' => $state['source_token'] ?? '', 'backup_token' => $state['backup_token'] ?? '' ],
            $payload
        );
        $args = [ 'timeout' => $timeout, 'sslverify' => false, 'headers' => [ 'Content-Type' => 'application/json' ] ];

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
            return new \WP_Error( 'source_error', "Source responded {$code} for {$endpoint}.", [ 'status' => 502, 'body' => substr( $raw, 0, 300 ) ] );
        }
        $decoded = json_decode( $raw );
        // Some endpoints return a raw URL string, not JSON.
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return trim( $raw );
        }
        return $decoded;
    }

    /**
     * Downloads a URL to a local path (streamed to disk).
     */
    private function download_to( $url, $dest_path ) {
        $tmp = download_url( $url, 600 );
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
     * Verifies the source is reachable and records the connection. Detects the
     * source's permalink style and captures its home URL / prefix / tables.
     */
    public function connect( $source_url, $source_token ) {
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
            $resp = wp_remote_get( $source_url . $path, [ 'timeout' => 60, 'sslverify' => false ] );
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
        if ( is_array( $db ) ) {
            foreach ( $db as $row ) {
                $db_size += isset( $row->size ) ? (int) $row->size : 0;
            }
        }

        $state = $this->state();
        $state['source_home']   = $home;
        $state['source_prefix'] = $prefix;
        $this->save_state( $state );

        // Stamp source metadata into staging so the import reads it like a backup.
        if ( ! is_dir( $this->staging_public ) ) {
            mkdir( $this->staging_public, 0755, true );
        }
        file_put_contents(
            $this->staging_public . '/disembark-source.json',
            wp_json_encode( [ 'home_url' => $home, 'site_url' => $home, 'db_prefix' => $prefix ] )
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
            $tmp = download_url( $chunk->url, 300 );
            if ( is_wp_error( $tmp ) ) {
                return $tmp;
            }
            $list = json_decode( file_get_contents( $tmp ) );
            @unlink( $tmp );
            if ( is_array( $list ) ) {
                foreach ( $list as $f ) {
                    if ( is_object( $f ) && ! empty( $f->name ) ) {
                        $files[] = [ 'name' => $f->name, 'size' => $f->size ?? 0 ];
                    }
                }
            }
        }
        $state = $this->state();
        $state['file_list'] = $this->staging_dir() . '/file-list.json';
        file_put_contents( $state['file_list'], wp_json_encode( $files ) );
        $state['total_files'] = count( $files );
        $this->save_state( $state );
        return [ 'status' => 'ready', 'total_files' => count( $files ) ];
    }

    private function staging_dir() {
        return dirname( $this->staging_public ); // .../staging
    }

    /**
     * Pulls one batch of files: asks the source to zip them, downloads the zip,
     * and extracts into staging. Returns cumulative progress.
     */
    public function fetch_files( $offset, $count ) {
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
        if ( is_wp_error( $dl ) ) {
            return $dl;
        }
        $extracted = $this->extract_into_public( $zip_path );
        @unlink( $zip_path );
        if ( is_wp_error( $extracted ) ) {
            return $extracted;
        }

        $fetched = min( $offset + $count, $total );
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
        $url = $this->source_request( '/export-database-batch', [ 'tables' => $tables ], 'POST', 1800 );
        if ( is_wp_error( $url ) ) {
            return $url;
        }
        if ( ! is_string( $url ) || ! preg_match( '#^https?://#', $url ) ) {
            return new \WP_Error( 'export_failed', 'Source did not return a database URL.', [ 'status' => 502 ] );
        }
        $dest = $this->staging_public . '/database-pull.sql';
        $dl   = $this->download_to( $url, $dest );
        if ( is_wp_error( $dl ) ) {
            return $dl;
        }
        return [ 'ok' => true, 'tables' => count( $tables ), 'bytes' => filesize( $dest ) ];
    }

    /**
     * Extracts a zip into staging/public, skipping zip-slip entry names.
     */
    private function extract_into_public( $zip_path ) {
        if ( ! is_dir( $this->staging_public ) ) {
            mkdir( $this->staging_public, 0755, true );
        }
        $zip = new \ZipArchive();
        if ( $zip->open( $zip_path ) !== true ) {
            if ( ! class_exists( 'PclZip' ) ) {
                require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
            }
            $pcl = new \PclZip( $zip_path );
            if ( $pcl->extract( PCLZIP_OPT_PATH, $this->staging_public ) == 0 ) {
                return new \WP_Error( 'extract_failed', 'Could not extract batch zip.', [ 'status' => 500 ] );
            }
            return true;
        }
        $safe = [];
        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $name = $zip->getNameIndex( $i );
            if ( $name !== false && strpos( $name, '..' ) === false && $name[0] !== '/' ) {
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
    public function cleanup_pull_state() {
        @unlink( $this->state_file );
        $list = $this->staging_dir() . '/file-list.json';
        @unlink( $list );
        return [ 'ok' => true ];
    }
}
