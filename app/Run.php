<?php

namespace DisembarkConnector;

class Run {

    protected $plugin_url  = "";
    protected $plugin_path = "";
    private $token         = "";

    public function __construct( $token = "" ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        if ( defined( 'DISEMBARK_CONNECT_DEV_MODE' ) ) {
            add_filter('https_ssl_verify', '__return_false');
            add_filter('https_local_ssl_verify', '__return_false');
            add_filter('http_request_host_is_external', '__return_true');
        }
        $this->token       = $token;
        $this->plugin_url  = dirname( plugin_dir_url( __FILE__ ) );
        $this->plugin_path = dirname( plugin_dir_path( __FILE__ ) );
        add_action( 'rest_api_init', [ $this, 'disembark_register_rest_endpoints' ] );
        if ( defined( 'WP_CLI' ) && \WP_CLI ) {
            \WP_CLI::add_command( 'disembark', new class {}, [
                'shortdesc' => 'Disembarks helper commands.',
            ] );
            \WP_CLI::add_command( 'disembark token', [ 'DisembarkConnector\Command', "token" ]  );
            \WP_CLI::add_command( 'disembark cli-info', [ 'DisembarkConnector\Command', 'cli_info' ] );
            \WP_CLI::add_command( 'disembark backup-url', [ 'DisembarkConnector\Command', 'backup_url' ] );
        }
    }

    function disembark_register_rest_endpoints() {

        register_rest_route(
            'disembark/v1', '/database', [
                'methods'  => 'GET',
                'callback' => [ $this, 'database' ]
            ]
        );
        register_rest_route(
            'disembark/v1', '/files', [
                'methods'  => 'GET',
                'callback' => [ $this, 'files' ]
            ]
        );
        register_rest_route(
            'disembark/v1', '/regenerate-manifest', [
                'methods'  => 'POST',
                'callback' => [ $this, 'regenerate_manifest' ]
            ]
        );
        register_rest_route(
            'disembark/v1', '/full-manifest', [
                'methods'  => 'GET',
                'callback' => [ $this, 'get_full_manifest' ]
            ]
        );
        register_rest_route(
            'disembark/v1', '/backup/(?P<selection>[a-zA-Z0-9-]+)', [
                'methods'  => 'GET',
                'callback' => [ $this, 'backup' ]
            ]
        );
        register_rest_route(
            'disembark/v1', '/export/database/(?P<table>[a-zA-Z0-9-_]+)', [
                'methods'  => 'POST',
                'callback' => [ $this, 'export_database' ]
            ]
        );
        register_rest_route(
            'disembark/v1', '/zip-files', [
                'methods'  => 'POST',
                'callback' => [ $this, 'zip_files' ]
            ]
        );
        register_rest_route(
            'disembark/v1', '/zip-database', [
                'methods'  => 'POST',
                'callback' => [ $this, 'zip_database' ]
            ]
        );
        register_rest_route(
            'disembark/v1', '/stream-file', [
                'methods'  => 'GET',
                'callback' => [ $this, 'stream_file' ]
            ]
        );
        register_rest_route(
            'disembark/v1', '/download', [
                'methods'  => 'GET',
                'callback' => [ $this, 'download' ]
            ]
        );
        register_rest_route(
            'disembark/v1', '/cleanup', [
                'methods'  => 'GET',
                'callback' => [ $this, 'cleanup' ]
            ]
        );
    }

    function export_database ( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        if ( ! empty( $request['backup_token'] ) ) {
            $this->token = $request['backup_token'];
        }
        $table = empty( $request['table'] ) ? "" : $request['table'];
        if ( ! empty( $request['parts'] ) ) {
            return ( new Backup( $this->token ) )->database_export( $table, $request['parts'], $request['rows_per_part'] );
        }
        return ( new Backup( $this->token ) )->database_export( $table );
    }

    function zip_files ( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        if ( ! empty( $request['backup_token'] ) ) {
            $this->token = $request['backup_token'];
        }

        $file         = empty( $request['file'] ) ? "" : $request['file'];
        $include_file = empty( $request['include_file'] ) ? "" : $request['include_file'];

        // Get `exclude_files` string from the request, defaulting to an empty string.
        $exclude_files_string = $request['exclude_files'] ?? '';
        
        // Convert the newline-separated string into an array of paths.
        $exclude_files_array = !empty($exclude_files_string) ? explode( "\n", $exclude_files_string ) : [];

        if ( ! empty( $include_file ) ) {
            // This is a secondary logic path. For full compatibility, the match_and_zip_files
            // function would also need to be updated to accept the exclusion array.
            return ( new Backup( $this->token ) )->match_and_zip_files( $include_file );
        }
        
        // Pass the manifest file path and the array of excluded paths to the Backup class.
        return ( new Backup( $this->token ) )->zip_files( $file, $exclude_files_array );
    }

    function zip_database ( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        if ( ! empty( $request['backup_token'] ) ) {
            $this->token = $request['backup_token'];
        }
        return ( new Backup( $this->token ) )->zip_database();
    }

    function stream_file( $request ) {
        if ( ! User::allowed( $request ) ) {
            // Not using WP_Error to avoid JSON formatting
            header("HTTP/1.1 403 Forbidden");
            die('403 Forbidden: Invalid token.');
        }

        $file_path = $request->get_param('file');
        if ( empty( $file_path ) ) {
            header("HTTP/1.1 400 Bad Request");
            die('400 Bad Request: File parameter is missing.');
        }

        $base_dir = realpath(ABSPATH);
        $full_path = realpath($base_dir . '/' . $file_path);

        // Security check: Ensure the requested file is within the WordPress directory
        if ( !$full_path || strpos($full_path, $base_dir) !== 0 ) {
            header("HTTP/1.1 400 Bad Request");
            die('400 Bad Request: Invalid file path.');
        }

        if ( !file_exists($full_path) || !is_readable($full_path) ) {
            header("HTTP/1.1 404 Not Found");
            die('404 Not Found: File does not exist or is not readable.');
        }

        // Stream the file
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($full_path) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($full_path));
        flush();
        // Flush system output buffer
        readfile($full_path);
        exit;
    }

    function backup ( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        $selection = $request['selection'];
        if ( ! in_array( $selection, [ "plugins", "themes", "wordpress" ] ) ) {
            return new \WP_Error( 'rest_no_route', 'No route was found matching the URL and request method.', [ 'status' => 404 ] );
        }
        if ( ! empty( $request['backup_token'] ) ) {
            $this->token = $request['backup_token'];
        }
        return ( new Backup( $this->token ) )->{$selection}();
    }

    public static function database( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        global $wpdb;
        if ( defined( "DB_ENGINE" ) && DB_ENGINE == "sqlite" ) {
            $all_tables = [];
            $results    = $wpdb->get_results( "SHOW TABLES");
            $tables     = array_column( $results, "name" );
            foreach( $tables as $table ) {
                $response = $wpdb->get_results( 'SELECT SUM("pgsize") as size FROM "dbstat" WHERE name="' . $table . '";' );
                $all_tables[] = (object) [ "table" => $table, "size" => $response[0]->size ];
            }
            return $all_tables;
        }

        $sql      = "SELECT table_name AS \"table\", data_length + index_length AS \"size\" FROM information_schema.TABLES WHERE table_schema = '" . DB_NAME . "' ORDER BY (data_length + index_length) DESC;";
        $response = $wpdb->get_results( $sql );
        foreach( $response as $row ) {
            $row->row_count = $wpdb->get_var( "SELECT COUNT(*) FROM " . $row->table );
        }
        return $response;
    }
    
    function files( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        $directory = empty( $request['directory'] ) ? "" : $request['directory'];
        if ( $directory == "" || is_object( $directory ) ) {
            $directory = \get_home_path();
        }
        if ( ! empty( $request['backup_token'] ) ) {
            $this->token = $request['backup_token'];
        }
        $this->list_files( $directory, [], true );
        $manifest_files = ( new Backup( $request['backup_token'] ) )->list_manifest();
        return $manifest_files;
    }

    /**
     * Regenerates the file manifest based on a list of excluded files/folders.
     */
    function regenerate_manifest( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }

        if ( ! empty( $request['backup_token'] ) ) {
            $this->token = $request['backup_token'];
        }

        $backup_manager = new Backup( $this->token );
        $step           = $request['step'] ?? 'initiate';
        $chunk_size_mb  = 150; // Set the size limit to 150 MB per chunk

        switch ( $step ) {
            case 'initiate':
                // Fast: Just set up the initial state for scanning.
                $backup_manager->initiate_scan_state();
                return [ 'status' => 'ready' ];

            case 'scan':
                // Scan a small portion of the filesystem. Called in a loop.
                $exclude_files_string = $request['exclude_files'] ?? '';
                $exclude_paths = !empty($exclude_files_string) ? explode( "\n", $exclude_files_string ) : [];
                return $backup_manager->process_scan_step( $exclude_paths );

            case 'chunkify':
                // Read the full file list and calculate how many chunks are needed based on size.
                return $backup_manager->chunkify_manifest( $chunk_size_mb );

            case 'process_chunk':
                // Create a single files-n.json chunk based on size. Called in a loop.
                $chunk_number = $request['chunk'] ?? 1;
                return $backup_manager->process_manifest_chunk( $chunk_number, $chunk_size_mb );

            case 'finalize':
                // Generate the final manifest.json from all the chunk files.
                $manifest_files = $backup_manager->finalize_manifest();
                $backup_manager->cleanup_temp_files();
                return $manifest_files;
        }

        return new \WP_Error( 'invalid_step', 'The provided step is not valid.', [ 'status' => 400 ] );
    }

    function get_full_manifest( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        $directory = \get_home_path();
        // Call list_files without generating manifest files
        $all_files = $this->list_files( $directory, [], false );
        return $all_files;
    }

    function list_files( $directory = "", $include_files = [], $generate_manifest = true ) {
        if ( empty( $directory ) ) {
            $directory = \get_home_path();
        }
        $files    = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        $response = [];
        $seen     = [];
        foreach ( $files as $file ) {
            $name = $file->getPathname();
            // Skip directories
            if ( $file->isDir() ){ 
                continue;
            }
            // Skip symbolic links
            if ($file->isLink()) {
                continue;
            }
            // Normalize the file path
            $relativePath = str_replace( $directory, "", $name );
            $relativePath = ltrim( $relativePath, '/' );
            // Check for duplicates
            if (in_array($relativePath, $seen)) {
                continue;
            }
            // Exclude matches
            $excludes = [ 
                "uploads/disembark",
                "wp-content/updraft",
                "wp-content/ai1wm-backups",
                "wp-content/backups-dup-lite",
                "wp-content/backups-dup-pro",
                "wp-content/mysql.sql"
            ];
            foreach ( $excludes as $path ) {
                if ( str_contains( $relativePath, $path ) ) {
                    continue 2;
                }
            }
            if ( ! empty( $include_files ) ) {
                foreach( $include_files as $include ) {
                    if ( str_contains( $relativePath, $include ) ) {
                     
                       $seen[]     = $relativePath;
                       $response[] = (object) [ 
                            "name" => $name,
                            "size" => $file->getSize(),
                            "type" => "file"
                        ];
                        break;
                    }
                }
                continue;
            }
            $seen[]     = $relativePath;
            $response[] = (object) [ 
                "name" => $name,
                "size" => $file->getSize(),
                "type" => "file"
            ];
        }

        foreach ( $response as $file ) {
            $file->name = str_replace( $directory, "", $file->name );
            $file->name = ltrim( $file->name, '/' );
        }

        if ( ! empty( $include_files ) ) {
            return $response;
        }

        if ( $generate_manifest ) {
            ( new Backup( $this->token ) )->generate_manifest( $response );
        }

        return $response;
    }

    function download( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        $files = ( new Backup( $request['backup_token'] ) )->list_downloads();
        header('Content-Type: text/plain');
        echo implode( "\n", $files );
        exit;
    }

    function cleanup( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        $directory = wp_upload_dir()["basedir"] . "/disembark/";
        $files     = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        $files_to_delete = [];

        header('Content-Type: text/plain');

        foreach ( $files as $file ) {
            // Skip directories
            if ( $file->isDir() ){ 
                continue;
            }
            // Skip symbolic links
            if ($file->isLink()) {
                continue;
            }
            echo "Removing {$file->getPathname()}\n";
            unlink( $file->getPathname() );
        }
        $directories = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach( $directories as $dir ) {
            if ( $dir->isDir() ){
                rmdir( $dir->getPathname() );
            }   
        }
        exit;
    }

}