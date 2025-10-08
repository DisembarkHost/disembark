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