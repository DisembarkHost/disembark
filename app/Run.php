<?php

namespace Disembark;

class Run {

    protected $plugin_url;
    protected $plugin_path;

    public function __construct() {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        
        $this->plugin_url  = plugin_dir_url( dirname( __FILE__ ) );
        $this->plugin_path = dirname( plugin_dir_path( __FILE__ ) );

        add_action( 'admin_menu', [ $this, 'register_admin_page' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );
        add_action( 'init', [ $this, 'register_shortcode' ] );

        add_action( 'rest_api_init', [ $this, 'register_rest_endpoints' ] );
        
        if ( defined( 'WP_CLI' ) && \WP_CLI ) {
            \WP_CLI::add_command( 'disembark', new class {}, [
                'shortdesc' => 'Disembark helper commands.',
            ] );
            \WP_CLI::add_command( 'disembark token', [ 'Disembark\Command', "token" ]  );
            \WP_CLI::add_command( 'disembark cli-info', [ 'Disembark\Command', 'cli_info' ] );
        }
    }

    /**
     * Registers the admin page under the "Tools" menu in the WordPress dashboard.
     */
    public function register_admin_page() {
        add_management_page(
            '',
            'Disembark',
            'manage_options',
            'disembark',
            [ $this, 'render_admin_page' ]
        );
    }
    
    /**
     * Renders the main container for the Disembark admin page.
     * The Vue.js application is injected into this page via a shortcode.
     */
    public function render_admin_page() {
        ?>
        <div class="wrap disembark-wrapper">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            <?php echo do_shortcode( '[disembark_ui]' ); ?>
        </div>
        <?php
    }

    /**
     * Registers the shortcode used to embed the Vue.js application.
     */
    public function register_shortcode() {
        add_shortcode( 'disembark_ui', [ $this, 'render_shortcode_ui' ] );
    }

    /**
     * Handles the rendering of the shortcode by loading the template file.
     *
     * @return string The HTML content of the template file.
     */
    public function render_shortcode_ui() {
        ob_start();
        include_once $this->plugin_path . '/template.php';
        return ob_get_clean();
    }

    /**
     * Enqueues the necessary CSS and JavaScript files for the admin page.
     *
     * @param string $hook The hook suffix for the current admin page.
     */
    public function enqueue_admin_scripts( $hook ) {
        if ( 'tools_page_disembark' !== $hook ) {
            return;
        }
        wp_enqueue_style( 'vuejs-font', "https://fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900" );
        wp_enqueue_style( 'vuejs-icons', "https://cdnjs.cloudflare.com/ajax/libs/MaterialDesign-Webfont/7.4.47/css/materialdesignicons.min.css" );
        wp_enqueue_style( 'vuetify', "https://cdn.jsdelivr.net/npm/vuetify@v3.6.10/dist/vuetify.min.css" );
        wp_enqueue_style( 'disembark-styles', $this->plugin_url . 'css/style.css' );
    }

    // --- REST API Endpoints ---
    // The methods below are unchanged and handle the direct backup logic.

    function register_rest_endpoints() {
        register_rest_route('disembark/v1', '/backup-size', [
            'methods'  => 'GET',
            'callback' => [ $this, 'get_backup_size' ]
        ]);
        register_rest_route('disembark/v1', '/database', [
            'methods'  => 'GET',
            'callback' => [ $this, 'database' ]
        ]);
        register_rest_route('disembark/v1', '/regenerate-manifest', [
            'methods'  => 'POST',
            'callback' => [ $this, 'regenerate_manifest' ]
        ]);
        register_rest_route('disembark/v1', '/zip-sync-files', [
            'methods'  => 'POST',
            'callback' => [ $this, 'zip_sync_files' ]
        ]);
        register_rest_route('disembark/v1', '/zip-database', [
            'methods'  => 'POST',
            'callback' => [ $this, 'zip_database' ]
        ]);
        register_rest_route('disembark/v1', '/regenerate-token', [
            'methods'  => 'POST',
            'callback' => [ $this, 'regenerate_token' ]
        ]);
        register_rest_route('disembark/v1', '/manifest', [
            'methods'  => 'GET',
            'callback' => [ $this, 'get_manifest' ]
        ]);
        register_rest_route('disembark/v1', '/export/database/(?P<table>[a-zA-Z0-9-_]+)', [
            'methods'  => 'POST',
            'callback' => [ $this, 'export_database' ]
        ]);
        register_rest_route('disembark/v1', '/export-database-batch', [
            'methods'  => 'POST',
            'callback' => [ $this, 'export_database_batch' ]
        ]);
        register_rest_route('disembark/v1', '/stream-file', [
            'methods'  => 'POST',
            'callback' => [ $this, 'stream_file' ]
        ]);
        register_rest_route('disembark/v1', '/cleanup', [
            'methods'  => 'GET',
            'callback' => [ $this, 'cleanup' ]
        ]);
        register_rest_route('disembark/v1', '/cleanup-file', [
            'methods'  => 'POST',
            'callback' => [ $this, 'cleanup_file' ]
        ]);
    }

    public function get_backup_size( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }

        $directory = wp_upload_dir()["basedir"] . "/disembark/";
        if ( ! is_dir( $directory ) ) {
            return [ 'size' => 0 ];
        }

        $size = 0;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ( $files as $file ) {
            if ( $file->isFile() ) {
                $size += $file->getSize();
            }
        }

        return [ 'size' => $size ];
    }

    function cleanup_file( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        $backup_token = $request['backup_token'];
        $file_name    = $request['file_name'];

        if ( empty( $backup_token ) || empty( $file_name ) ) {
            return new \WP_Error( 'missing_params', 'Missing backup_token or file_name.', [ 'status' => 400 ] );
        }

        return ( new Backup( $backup_token ) )->delete_backup_file( $file_name );
    }

    public static function database( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        global $wpdb;
        $sql = "SELECT table_name AS \"table\", data_length + index_length AS \"size\" FROM information_schema.TABLES WHERE table_schema = '" . DB_NAME . "' ORDER BY (data_length + index_length) DESC;";
        $response = $wpdb->get_results( $sql );
        foreach( $response as $row ) {
            $row->row_count = $wpdb->get_var( "SELECT COUNT(*) FROM " . $row->table );
        }
        return $response;
    }
    
    function regenerate_manifest( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        $backup_token     = $request['backup_token'];
        $backup_manager   = new Backup( $backup_token );
        $step             = $request['step'] ?? 'initiate';
        $chunk_size_mb    = 150;
        $default_excludes = [
            "wp-content/uploads/disembark",
            "wp-content/updraft",
            "wp-content/ai1wm-backups",
            "wp-content/backups-dup-lite",
            "wp-content/backups-dup-pro",
            "wp-content/mysql.sql"
        ];
        switch ( $step ) {
            case 'initiate':
                $backup_manager->initiate_scan_state();
                return [ 'status' => 'ready' ];
            case 'scan':
                $exclude_files_string = $request['exclude_files'] ?? '';
                $user_exclude_paths = !empty($exclude_files_string) ? explode( "\n", $exclude_files_string ) : [];
                // Merge default and user-defined exclusions
                $exclude_paths = array_unique( array_merge( $default_excludes, $user_exclude_paths ) );
                $include_checksums = isset( $request['include_checksums'] ) ? $request['include_checksums'] : false;
                return $backup_manager->process_scan_step( $exclude_paths, $include_checksums );
            case 'chunkify':
                return $backup_manager->chunkify_manifest( $chunk_size_mb );
            case 'process_chunk':
                $chunk_number = $request['chunk'] ?? 1;
                return $backup_manager->process_manifest_chunk( $chunk_number, $chunk_size_mb );
            case 'finalize':
                $manifest_files = $backup_manager->finalize_manifest();
                $backup_manager->cleanup_temp_files();
                return $manifest_files;
        }
        return new \WP_Error( 'invalid_step', 'The provided step is not valid.', [ 'status' => 400 ] );
    }

    function zip_sync_files( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        $backup_token = $request['backup_token'];
        $files_list   = $request['files']; // This will be an array of file objects
        if ( empty( $backup_token ) || ! is_array( $files_list ) ) {
            return new \WP_Error( 'missing_params', 'Missing backup_token or files list.', [ 'status' => 400 ] );
        }
        // We need a new method in Backup.php to handle this
        return ( new Backup( $backup_token ) )->zip_file_list( $files_list );
    }

    function zip_database ( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        $backup_token = $request['backup_token'];
        return ( new Backup( $backup_token ) )->zip_database();
    }

    function regenerate_token( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }

        $token = wp_generate_password( 42, false );
        update_option( "disembark_token", $token );

        return [ 'token' => $token ];
    }

    function export_database ( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        $backup_token = $request['backup_token'];
        $table = empty( $request['table'] ) ? "" : $request['table'];
        if ( ! empty( $request['parts'] ) ) {
            return ( new Backup( $backup_token ) )->database_export( $table, $request['parts'], $request['rows_per_part'] );
        }
        return ( new Backup( $backup_token ) )->database_export( $table );
    }

    function export_database_batch( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        $backup_token = $request['backup_token'];
        $tables       = $request['tables'];

        if ( empty( $backup_token ) || ! is_array( $tables ) || empty( $tables ) ) {
            return new \WP_Error( 'missing_params', 'Missing backup_token or tables array.', [ 'status' => 400 ] );
        }

        return ( new Backup( $backup_token ) )->database_export_batch( $tables );
    }

    function get_manifest( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        $backup_token = $request['backup_token'];
        if ( empty( $backup_token ) ) {
            return new \WP_Error( 'missing_params', 'Missing backup_token.', [ 'status' => 400 ] );
        }
        $manifest = ( new Backup( $backup_token ) )->list_manifest();
        if ( empty( $manifest ) ) {
            return new \WP_Error( 'not_found', 'Manifest not found. It may not be generated yet or the session ID is invalid.', [ 'status' => 404 ] );
        }
        return $manifest;
    }

    function stream_file( $request ) {
        // Get params from the JSON body
        $params = $request->get_json_params();
        $token = $params['token'] ?? null;
        $file_path = $params['file'] ?? null;
        
        // New params for chunking
        $offset = isset($params['offset']) ? (int)$params['offset'] : 0;
        $length = isset($params['length']) ? (int)$params['length'] : null;

        // Manually create a new request object for User::allowed()
        $auth_request = new \WP_REST_Request();
        $auth_request->set_param('token', $token);

        if ( ! User::allowed( $auth_request ) ) {
            header("HTTP/1.1 403 Forbidden");
            die('403 Forbidden: Invalid token.');
        }

        if ( empty( $file_path ) ) {
            header("HTTP/1.1 400 Bad Request");
            die('400 Bad Request: File parameter is missing.');
        }

        $base_dir = realpath( dirname( WP_CONTENT_DIR ) );
        $core_dir = realpath( ABSPATH );
        
        // Try to resolve the path from the web root first
        $full_path = realpath($base_dir . '/' . $file_path);
        // If not found, and roots are different, try resolving from the core root
        if ( !$full_path && $base_dir !== $core_dir ) {
            $full_path = realpath($core_dir . '/' . $file_path);
        }

        $is_in_base_dir = $full_path && strpos( $full_path, $base_dir ) === 0;
        $is_in_core_dir = $full_path && $core_dir !== $base_dir && strpos( $full_path, $core_dir ) === 0;
        if ( !$full_path || ( !$is_in_base_dir && !$is_in_core_dir ) ) {
            header("HTTP/1.1 400 Bad Request");
            die('400 Bad Request: Invalid file path.');
        }
        if ( !file_exists($full_path) || !is_readable($full_path) ) {
            header("HTTP/1.1 404 Not Found");
            die('404 Not Found: File does not exist or is not readable.');
        }
        
        // Prepare headers
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($full_path) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        
        // If length is specified, we are sending a partial content
        if ($length !== null) {
             header('Content-Length: ' . $length);
        } else {
             header('Content-Length: ' . filesize($full_path));
        }
        
        flush();
        
        // Use a chunked readfile to support large files
        $file = @fopen($full_path, 'rb');
        if ($file) {
            // Move to offset
            if ($offset > 0) {
                fseek($file, $offset);
            }

            $bytes_sent = 0;
            while (!feof($file)) {
                // If we have a limit, check if we reached it
                if ($length !== null && $bytes_sent >= $length) {
                    break;
                }

                // Calculate how much to read in this buffer
                $read_size = 8192;
                if ($length !== null) {
                    $remaining = $length - $bytes_sent;
                    if ($remaining < $read_size) {
                        $read_size = $remaining;
                    }
                }

                echo @fread($file, $read_size);
                $bytes_sent += $read_size;

                flush();
                
                if (connection_status() != 0) {
                    @fclose($file);
                    die(); 
                }
            }
            @fclose($file);
        } else {
            header("HTTP/1.1 500 Internal Server Error");
            die('500 Internal Server Error: Could not open file for reading.');
        }
        
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
        header('Content-Type: text/plain');
        foreach ( $files as $file ) {
            if ( $file->isDir() ){ continue; }
            if ($file->isLink()) { continue; }
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
