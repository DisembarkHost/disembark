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
        add_filter( 'rest_authentication_errors', [ $this, 'allow_token_auth' ], 99 );
        add_filter( 'rest_dispatch_request', [ Steps::class, 'dispatch' ], 10, 4 );

        if ( defined( 'WP_CLI' ) && \WP_CLI ) {
            \WP_CLI::add_command( 'disembark', new class {}, [
                'shortdesc' => 'Disembark helper commands.',
            ] );
            \WP_CLI::add_command( 'disembark token', [ 'Disembark\Command', 'token' ] );
            \WP_CLI::add_command( 'disembark connect-line', [ 'Disembark\Command', 'cli_info' ] );
            \WP_CLI::add_command( 'disembark cli-info', [ 'Disembark\Command', 'cli_info' ] );
            \WP_CLI::add_command( 'disembark pull', [ 'Disembark\Command', 'pull' ] );
            \WP_CLI::add_command( 'disembark restore-points', [ 'Disembark\Command', 'restore_points' ] );
            \WP_CLI::add_command( 'disembark rollback', [ 'Disembark\Command', 'rollback' ] );
            \WP_CLI::add_command( 'disembark delete-restore-point', [ 'Disembark\Command', 'delete_restore_point' ] );
            \WP_CLI::add_command( 'disembark search-replace', [ 'Disembark\Command', 'search_replace' ] );
            \WP_CLI::add_command( 'disembark quiet-mode', [ 'Disembark\Command', 'quiet_mode' ] );
            \WP_CLI::add_command( 'disembark unfinished', [ 'Disembark\Command', 'unfinished' ] );
            \WP_CLI::add_command( 'disembark discard', [ 'Disembark\Command', 'discard' ] );
            \WP_CLI::add_command( 'disembark verify', [ 'Disembark\Command', 'verify' ] );
        }
    }

    /**
     * When a request to a Disembark route carries a valid token, treat it as
     * authenticated so WordPress's cookie/nonce check (rest_cookie_check_errors)
     * doesn't reject it. This is essential during a restore: importing the
     * database replaces wp_users, which invalidates the admin's session
     * mid-flow — the browser then sends a stale nonce that would otherwise 403
     * the remaining steps ("Cookie check failed"). The token, preserved across
     * the import, keeps the request authorized. Scoped to Disembark routes; the
     * per-endpoint User::allowed() check still runs.
     */
    public function allow_token_auth( $errors ) {
        // Scope strictly to Disembark's own REST namespace (pretty and
        // ?rest_route= forms) — matching "disembark" anywhere in the URI would
        // relax the cookie/nonce check for unrelated routes too.
        $uri    = rawurldecode( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '' );
        $prefix = '/' . rest_get_url_prefix() . '/disembark/v1';
        if ( strpos( $uri, $prefix ) === false && strpos( $uri, 'rest_route=/disembark/v1' ) === false ) {
            return $errors;
        }

        $token     = isset( $_REQUEST['token'] ) ? $_REQUEST['token'] : null;
        $import_id = isset( $_REQUEST['import_id'] ) ? $_REQUEST['import_id'] : '';
        if ( ! $token && isset( $_SERVER['CONTENT_TYPE'] ) && stripos( $_SERVER['CONTENT_TYPE'], 'application/json' ) !== false ) {
            $body = file_get_contents( 'php://input' );
            if ( $body ) {
                $json = json_decode( $body, true );
                if ( is_array( $json ) && ! empty( $json['token'] ) ) {
                    $token = $json['token'];
                    if ( ! empty( $json['import_id'] ) && is_string( $json['import_id'] ) ) {
                        $import_id = $json['import_id'];
                    }
                }
            }
        }

        if ( is_string( $token ) && User::token_valid( $token, is_string( $import_id ) ? $import_id : '' ) ) {
            return true; // valid token → skip the cookie/nonce requirement
        }
        return $errors;
    }

    /**
     * Registers the admin page under the "Tools" menu in the WordPress dashboard.
     */
    public function register_admin_page() {
        add_management_page(
            '',
            'Disembark',
            User::menu_capability(),
            'disembark',
            [ $this, 'render_admin_page' ]
        );
    }
    
    /**
     * Renders the Disembark admin page. The dashboard draws its own header, so
     * the page is just the app's mount point (via the shortcode). The
     * wp-header-end marker tells WordPress where to put admin notices, so they
     * land above the app instead of under its first heading. The dashboard
     * then gathers them into its notices tray.
     */
    public function render_admin_page() {
        echo '<div class="wrap disembark-wrap"><hr class="wp-header-end">' . do_shortcode( '[disembark_ui]' ) . '</div>';
    }

    /**
     * Registers the shortcode that embeds the dashboard.
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
        // The template prints the site's Disembark token (full file/database
        // access) into the page. Only render it for users who can manage the
        // site, so the shortcode can't leak the token on a front-end page.
        if ( ! User::can_manage() ) {
            return '';
        }
        $this->enqueue_app_assets();
        ob_start();
        include $this->plugin_path . '/template.php';
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
        $this->enqueue_app_assets();

        // A restore intentionally replaces the users table, which invalidates
        // the current session. Suppress WordPress's "session expired" heartbeat
        // modal on this page so it doesn't flash over the restore UI — the
        // dashboard shows its own "log in with the source's credentials" notice.
        wp_dequeue_script( 'wp-auth-check' );
        wp_deregister_script( 'wp-auth-check' );
        add_filter( 'wp_auth_check_load', '__return_false' );
    }

    /**
     * Enqueues the dashboard: one stylesheet and a few plain scripts that share
     * the window.Disembark namespace. Everything ships with the plugin (no
     * CDNs) and is versioned by file time, so an update always busts caches.
     * Safe to call more than once.
     */
    public function enqueue_app_assets() {
        if ( wp_script_is( 'disembark-app', 'enqueued' ) ) {
            return;
        }
        $base = $this->plugin_url . 'assets/';
        $dir  = $this->plugin_path . '/assets/';
        $ver  = function ( $file ) use ( $dir ) {
            return file_exists( $dir . $file ) ? (string) filemtime( $dir . $file ) : null;
        };

        wp_enqueue_style( 'disembark-admin', $base . 'admin.css', [], $ver( 'admin.css' ) );

        // Prism only highlights what the Files section asks it to.
        wp_register_script( 'disembark-prism', $base . 'vendor/prism/prism.js', [], $ver( 'vendor/prism/prism.js' ), true );
        wp_add_inline_script( 'disembark-prism', 'window.Prism = window.Prism || {}; window.Prism.manual = true;', 'before' );

        $scripts = [
            'disembark-core'     => [ 'js/core.js', [ 'disembark-prism' ] ],
            'disembark-tree'     => [ 'js/tree.js', [ 'disembark-core' ] ],
            'disembark-backup'   => [ 'js/backup.js', [ 'disembark-tree' ] ],
            'disembark-restore'  => [ 'js/restore.js', [ 'disembark-core' ] ],
            'disembark-push'     => [ 'js/push.js', [ 'disembark-restore' ] ],
            'disembark-pull'     => [ 'js/pull.js', [ 'disembark-restore' ] ],
            'disembark-bring'    => [ 'js/bring.js', [ 'disembark-tree' ] ],
            'disembark-files'    => [ 'js/files.js', [ 'disembark-tree' ] ],
            'disembark-database' => [ 'js/database.js', [ 'disembark-core' ] ],
            'disembark-settings' => [ 'js/settings.js', [ 'disembark-core' ] ],
            'disembark-app'      => [ 'js/app.js', [ 'disembark-backup', 'disembark-restore', 'disembark-push', 'disembark-pull', 'disembark-bring', 'disembark-files', 'disembark-database', 'disembark-settings' ] ],
        ];
        foreach ( $scripts as $handle => list( $file, $deps ) ) {
            wp_register_script( $handle, $base . $file, $deps, $ver( $file ), true );
        }

        $plugin  = get_file_data( $this->plugin_path . '/disembark.php', [ 'Version' => 'Version' ] );
        $config  = [
            'apiRoot'   => esc_url_raw( rest_url( 'disembark/v1/' ) ),
            'nonce'     => wp_create_nonce( 'wp_rest' ),
            'token'     => Token::get(),
            'homeUrl'   => home_url(),
            'loginUrl'  => wp_login_url( admin_url( 'tools.php?page=disembark' ) ),
            'userEmail' => (string) wp_get_current_user()->user_email,
            'version'   => $plugin['Version'],
            'fileEdits' => User::file_edits_allowed(),
            'multisite' => is_multisite(),
            'install'   => self::install_summary(),
        ];
        wp_add_inline_script( 'disembark-core', 'window.DisembarkConfig = ' . wp_json_encode( $config ) . ';', 'before' );
        wp_enqueue_script( 'disembark-app' );
    }

    /**
     * For the dashboard: whether this is a network or a WP Freighter install,
     * which site this is, and the sites (so Backup and Push can bring one).
     */
    private static function install_summary() {
        $install = Scope::install();
        if ( ! $install ) {
            return null;
        }
        $install['sites'] = array_slice( $install['sites'], 0, 200 );
        return $install;
    }

    /**
     * Exposes the login URL to the dashboard so it can send the user to a clean
     * re-login after a restore (their old session was replaced).
     */
    public function login_url() {
        return wp_login_url( admin_url( 'tools.php?page=disembark' ) );
    }

    /**
     * True when $path is $base itself or sits inside it. Compares against
     * "$base/" so a sibling like "/var/www/htmlX" can't pass a "/var/www/html"
     * check (a bare strpos( $path, $base ) === 0 would).
     */
    protected function path_within( $path, $base ) {
        if ( ! $path || ! $base ) {
            return false;
        }
        return $path === $base || strpos( $path, rtrim( $base, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR ) === 0;
    }

    // --- REST API Endpoints ---
    function register_rest_endpoints() {
        register_rest_route('disembark/v1', '/backup-size', [
            'methods'  => 'GET',
            'callback' => [ $this, 'get_backup_size' ]
        ]);
        register_rest_route('disembark/v1', '/database', [
            'methods'  => 'GET',
            'callback' => [ $this, 'database' ]
        ]);
        register_rest_route('disembark/v1', '/database/row', [
            'methods'  => 'POST',
            'callback' => [ $this, 'update_database_row' ]
        ]);
        register_rest_route('disembark/v1', '/database/row/create', [
            'methods'  => 'POST',
            'callback' => [ $this, 'insert_database_row' ]
        ]);
        register_rest_route('disembark/v1', '/database/row/delete', [
            'methods'  => 'POST',
            'callback' => [ $this, 'delete_database_row' ]
        ]);
        register_rest_route('disembark/v1', '/database/rows', [
            'methods'  => 'GET',
            'callback' => [ $this, 'get_table_rows' ]
        ]);
        register_rest_route('disembark/v1', '/database/schema', [
            'methods'  => 'GET',
            'callback' => [ $this, 'get_table_schema' ]
        ]);
        register_rest_route('disembark/v1', '/file/save', [
            'methods'  => 'POST',
            'callback' => [ $this, 'save_file' ]
        ]);
        register_rest_route('disembark/v1', '/file/rename', [
            'methods'  => 'POST',
            'callback' => [ $this, 'rename_file' ]
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

        // Import / Restore endpoints
        register_rest_route('disembark/v1', '/import/preflight', [
            'methods'  => 'POST',
            'callback' => [ $this, 'import_preflight' ]
        ]);
        // Pull (direct site-to-site) endpoints
        register_rest_route('disembark/v1', '/import/pull/connect', [
            'methods'  => 'POST',
            'callback' => [ $this, 'import_pull_connect' ]
        ]);
        register_rest_route('disembark/v1', '/import/target', [
            'methods'  => 'POST',
            'callback' => [ $this, 'import_target' ]
        ]);
        register_rest_route('disembark/v1', '/import/pull/site', [
            'methods'  => 'POST',
            'callback' => [ $this, 'import_pull_site' ]
        ]);
        register_rest_route('disembark/v1', '/import/pull/scan', [
            'methods'  => 'POST',
            'callback' => [ $this, 'import_pull_scan' ]
        ]);
        register_rest_route('disembark/v1', '/import/pull/fetch-files', [
            'methods'  => 'POST',
            'callback' => [ $this, 'import_pull_fetch_files' ]
        ]);
        register_rest_route('disembark/v1', '/import/pull/fetch-database', [
            'methods'  => 'POST',
            'callback' => [ $this, 'import_pull_fetch_database' ]
        ]);
        register_rest_route('disembark/v1', '/import/pull/contents', [
            'methods'  => 'POST',
            'callback' => [ $this, 'import_pull_contents' ]
        ]);
        register_rest_route('disembark/v1', '/import/pull/select', [
            'methods'  => 'POST',
            'callback' => [ $this, 'import_pull_select' ]
        ]);
        register_rest_route('disembark/v1', '/import/unfinished', [
            'methods'  => 'GET',
            'callback' => [ $this, 'import_unfinished' ]
        ]);
        register_rest_route('disembark/v1', '/import/resume', [
            'methods'  => 'POST',
            'callback' => [ $this, 'import_resume' ]
        ]);
        register_rest_route('disembark/v1', '/import/last-pulls', [
            'methods'  => 'GET',
            'callback' => [ $this, 'import_last_pulls' ]
        ]);
        register_rest_route('disembark/v1', '/import/cancel', [
            'methods'  => 'POST',
            'callback' => [ $this, 'import_cancel' ]
        ]);
        register_rest_route('disembark/v1', '/import/checks', [
            'methods'  => 'POST',
            'callback' => [ $this, 'import_checks' ]
        ]);
        register_rest_route('disembark/v1', '/import/verify', [
            'methods'  => 'POST',
            'callback' => [ $this, 'import_verify' ]
        ]);
        register_rest_route('disembark/v1', '/import/notify', [
            'methods'  => 'POST',
            'callback' => [ $this, 'import_notify' ]
        ]);
        register_rest_route('disembark/v1', '/templates', [
            'methods'  => 'GET, POST',
            'callback' => [ $this, 'templates' ]
        ]);
        register_rest_route('disembark/v1', '/import/snapshot', [
            'methods'  => 'POST',
            'callback' => [ $this, 'import_snapshot' ]
        ]);
        register_rest_route('disembark/v1', '/import/upload-zip', [
            'methods'  => 'POST',
            'callback' => [ $this, 'import_upload_zip' ]
        ]);
        register_rest_route('disembark/v1', '/import/upload-chunk', [
            'methods'  => 'POST',
            'callback' => [ $this, 'import_upload_chunk' ]
        ]);
        register_rest_route('disembark/v1', '/import/extract-zip', [
            'methods'  => 'POST',
            'callback' => [ $this, 'import_extract_zip' ]
        ]);
        register_rest_route('disembark/v1', '/import/deploy-files', [
            'methods'  => 'POST',
            'callback' => [ $this, 'import_deploy_files' ]
        ]);
        register_rest_route('disembark/v1', '/import/sql', [
            'methods'  => 'POST',
            'callback' => [ $this, 'import_sql' ]
        ]);
        register_rest_route('disembark/v1', '/import/staged-info', [
            'methods'  => 'POST',
            'callback' => [ $this, 'import_staged_info' ]
        ]);
        register_rest_route('disembark/v1', '/import/import-staged-sql', [
            'methods'  => 'POST',
            'callback' => [ $this, 'import_staged_sql' ]
        ]);
        register_rest_route('disembark/v1', '/import/search-replace', [
            'methods'  => 'POST',
            'callback' => [ $this, 'import_search_replace' ]
        ]);
        register_rest_route('disembark/v1', '/import/remap-prefix', [
            'methods'  => 'POST',
            'callback' => [ $this, 'import_remap_prefix' ]
        ]);
        register_rest_route('disembark/v1', '/import/rollback', [
            'methods'  => 'POST',
            'callback' => [ $this, 'import_rollback' ]
        ]);
        register_rest_route('disembark/v1', '/import/finalize', [
            'methods'  => 'POST',
            'callback' => [ $this, 'import_finalize' ]
        ]);
        register_rest_route('disembark/v1', '/backup/archive', [
            'methods'  => 'POST',
            'callback' => [ $this, 'backup_archive' ]
        ]);
        register_rest_route('disembark/v1', '/restore-points', [
            'methods'  => 'GET',
            'callback' => [ $this, 'restore_points' ]
        ]);
        register_rest_route('disembark/v1', '/restore-points/delete', [
            'methods'  => 'POST',
            'callback' => [ $this, 'delete_restore_point' ]
        ]);
        register_rest_route('disembark/v1', '/search-replace', [
            'methods'  => 'POST',
            'callback' => [ $this, 'search_replace' ]
        ]);
        register_rest_route('disembark/v1', '/quiet-mode', [
            'methods'  => 'GET, POST',
            'callback' => [ $this, 'quiet_mode' ]
        ]);
    }

    public function get_backup_size( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }

        $directory = wp_upload_dir()["basedir"] . "/disembark/";
        $size = 0;
        $sessions = [];

        if ( is_dir( $directory ) ) {
            // 1. Calculate Total Size (Existing Logic)
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ( $files as $file ) {
                if ( $file->isFile() ) {
                    $size += $file->getSize();
                }
            }

            // 2. Find Previous Sessions
            $dirs = glob( $directory . '*', GLOB_ONLYDIR );
            if ( $dirs ) {
                foreach ( $dirs as $dir ) {
                    $token = basename( $dir );
                    // Only list sessions that have a manifest (completed scans)
                    if ( file_exists( $dir . '/manifest.json' ) ) {
                        $sessions[] = [
                            'token'     => $token,
                            'timestamp' => filemtime( $dir . '/manifest.json' ), // Use manifest time
                            'count'     => count( glob( $dir . '/files-*.json' ) ) // Count chunks
                        ];
                    }
                }
                // Sort by newest first
                usort( $sessions, function($a, $b) {
                    return $b['timestamp'] - $a['timestamp'];
                });
            }
        }

        $last_scan = get_option( 'disembark_last_scan_stats', null );
        
        return [ 
            'size'       => $size,
            'scan_stats' => $last_scan,
            'sessions'   => $sessions
        ];
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
        // One site of a shared install (a pull that chose one): its tables,
        // by the names it exports them under.
        if ( (string) $request->get_param( 'site' ) !== '' ) {
            $scope = Scope::from_request( $request );
            if ( is_wp_error( $scope ) ) {
                return $scope;
            }
            if ( $scope ) {
                return $scope->table_info();
            }
        }
        global $wpdb;
        $sql = "SELECT table_name AS \"table\", data_length + index_length AS \"size\" FROM information_schema.TABLES WHERE table_schema = '" . DB_NAME . "' ORDER BY (data_length + index_length) DESC;";
        $response = $wpdb->get_results( $sql );
        foreach( $response as $row ) {
            $row->row_count = $wpdb->get_var( "SELECT COUNT(*) FROM " . $row->table );
        }
        return $response;
    }

    public function update_database_row( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }

        global $wpdb;
        $params = $request->get_json_params();
        
        $table = $params['table'] ?? '';
        $data  = $params['data'] ?? [];       // The new values
        $where = $params['where'] ?? [];      // The primary keys to identify the row

        // Validate Table Name
        $valid_tables = $wpdb->get_col( "SHOW TABLES" );
        if ( ! in_array( $table, $valid_tables ) ) {
            return new \WP_Error( 'invalid_table', 'Invalid table name.', [ 'status' => 400 ] );
        }

        if ( empty( $data ) || empty( $where ) ) {
            return new \WP_Error( 'missing_data', 'Missing data or primary key identification.', [ 'status' => 400 ] );
        }

        // Perform the update
        $result = $wpdb->update( $table, $data, $where );

        if ( false === $result ) {
            return new \WP_Error( 'update_failed', 'Database update failed: ' . $wpdb->last_error, [ 'status' => 500 ] );
        }

        return [ 'success' => true, 'rows_affected' => $result ];
    }

    public function insert_database_row( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }

        global $wpdb;
        $params = $request->get_json_params();
        
        $table = $params['table'] ?? '';
        $data  = $params['data'] ?? []; 

        // Validate Table Name
        $valid_tables = $wpdb->get_col( "SHOW TABLES" );
        if ( ! in_array( $table, $valid_tables ) ) {
            return new \WP_Error( 'invalid_table', 'Invalid table name.', [ 'status' => 400 ] );
        }

        if ( empty( $data ) ) {
            return new \WP_Error( 'missing_data', 'Missing data.', [ 'status' => 400 ] );
        }

        $auto_inc_col = null;
        $columns = $wpdb->get_results( "SHOW COLUMNS FROM `$table`" );
        foreach ( $columns as $col ) {
            if ( stripos( $col->Extra, 'auto_increment' ) !== false ) {
                $auto_inc_col = $col->Field;
                break;
            }
        }

        // Clean empty values
        foreach( $data as $key => $value ) {
            if ( $value === '' ) {
                // If this is the auto_increment column, remove it so the DB handles it
                if ( $key === $auto_inc_col ) {
                    unset( $data[$key] );
                } else {
                    // Otherwise convert empty strings to NULL
                    $data[$key] = null;
                }
            }
        }

        // Perform the insert
        $result = $wpdb->insert( $table, $data );
        if ( false === $result ) {
            return new \WP_Error( 'insert_failed', 'Database insert failed: ' . $wpdb->last_error, [ 'status' => 500 ] );
        }

        return [ 'success' => true, 'id' => $wpdb->insert_id ];
    }

    public function delete_database_row( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }

        global $wpdb;
        $params = $request->get_json_params();
        
        $table = $params['table'] ?? '';
        $where = $params['where'] ?? [];

        // Validate Table Name
        $valid_tables = $wpdb->get_col( "SHOW TABLES" );
        if ( ! in_array( $table, $valid_tables ) ) {
            return new \WP_Error( 'invalid_table', 'Invalid table name.', [ 'status' => 400 ] );
        }

        if ( empty( $where ) ) {
            return new \WP_Error( 'missing_data', 'Missing primary key identification.', [ 'status' => 400 ] );
        }

        // Perform the delete
        $result = $wpdb->delete( $table, $where );
        
        if ( false === $result ) {
            return new \WP_Error( 'delete_failed', 'Database delete failed: ' . $wpdb->last_error, [ 'status' => 500 ] );
        }

        return [ 'success' => true, 'rows_affected' => $result ];
    }

    public function get_table_rows( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        
        global $wpdb;
        $table = $request['table'];
        $limit = isset($request['limit']) ? intval($request['limit']) : 1000;
        $page  = isset($request['page']) ? intval($request['page']) : 1;
        $offset = ($page - 1) * $limit;

        // Sorting parameters
        $orderby = isset($request['orderby']) ? $request['orderby'] : null;
        $order   = isset($request['order']) && strtoupper($request['order']) === 'DESC' ? 'DESC' : 'ASC';

        // 1. Validate Table Name
        $valid_tables = $wpdb->get_col( "SHOW TABLES" );
        if ( ! in_array( $table, $valid_tables ) ) {
            return new \WP_Error( 'invalid_table', 'Invalid table name.', [ 'status' => 400 ] );
        }

        // 2. Build Query
        $sql = "SELECT * FROM `{$table}`";

        // 3. Handle Sorting securely
        if ( $orderby ) {
            // Get valid columns for this table to ensure the 'orderby' param is safe
            $valid_columns = $wpdb->get_col( "DESCRIBE `{$table}`" );
            if ( in_array( $orderby, $valid_columns ) ) {
                $sql .= " ORDER BY `{$orderby}` {$order}";
            }
        }

        // 4. Add Limits
        $sql .= $wpdb->prepare( " LIMIT %d OFFSET %d", $limit, $offset );

        // 5. Execute
        $rows = $wpdb->get_results( $sql, ARRAY_A );
        
        // Get total rows for pagination
        $total = $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );

        return [
            'rows' => $rows,
            'total' => (int)$total
        ];
    }

    public function get_table_schema( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }

        global $wpdb;
        $table = $request['table'];

        // Security: Validate table existence
        $valid_tables = $wpdb->get_col( "SHOW TABLES" );
        if ( ! in_array( $table, $valid_tables ) ) {
            return new \WP_Error( 'invalid_table', 'Invalid table name.', [ 'status' => 400 ] );
        }

        // Get Table Structure
        $structure = $wpdb->get_results( "DESCRIBE `{$table}`" );
        
        // Get Table Status (for engine, collation, etc.)
        $status = $wpdb->get_row( "SHOW TABLE STATUS LIKE '{$table}'" );

        return [
            'structure' => $structure,
            'status'    => $status
        ];
    }

    function save_file( $request ) {
        // 1. Security Check
        $params = $request->get_json_params();
        $token = $params['token'] ?? null;
        
        // Manually create request for auth check
        $auth_request = new \WP_REST_Request();
        $auth_request->set_param('token', $token);

        if ( ! User::allowed( $auth_request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Invalid token.', [ 'status' => 403 ] );
        }

        if ( ! User::file_edits_allowed() ) {
            return new \WP_Error( 'file_edit_disabled', 'File editing is disabled on this site (DISALLOW_FILE_EDIT or DISALLOW_FILE_MODS).', [ 'status' => 403 ] );
        }

        $file_path = $params['file'] ?? null;
        $content   = $params['content'] ?? '';

        if ( empty( $file_path ) ) {
            return new \WP_Error( 'missing_params', 'File path is required.', [ 'status' => 400 ] );
        }

        // 2. Path Resolution (Same security logic as stream_file)
        $base_dir = realpath( dirname( WP_CONTENT_DIR ) );
        $core_dir = realpath( ABSPATH );
        
        $full_path = realpath($base_dir . '/' . $file_path);
        if ( !$full_path && $base_dir !== $core_dir ) {
            $full_path = realpath($core_dir . '/' . $file_path);
        }

        $is_in_base_dir = $this->path_within( $full_path, $base_dir );
        $is_in_core_dir = $core_dir !== $base_dir && $this->path_within( $full_path, $core_dir );

        if ( !$full_path || ( !$is_in_base_dir && !$is_in_core_dir ) ) {
            return new \WP_Error( 'invalid_path', 'Invalid file path.', [ 'status' => 400 ] );
        }

        // wp-config.php holds credentials and is deliberately never deployed
        // by a restore — keep the file editor consistent with that rule.
        if ( basename( $full_path ) === 'wp-config.php' ) {
            return new \WP_Error( 'protected_file', 'wp-config.php cannot be modified through Disembark.', [ 'status' => 400 ] );
        }

        // 3. Write File
        if ( !is_writable( $full_path ) ) {
            return new \WP_Error( 'not_writable', 'File is not writable.', [ 'status' => 500 ] );
        }

        if ( false === file_put_contents( $full_path, $content ) ) {
            return new \WP_Error( 'write_failed', 'Failed to save file content.', [ 'status' => 500 ] );
        }

        return [ 'success' => true ];
    }

    function rename_file( $request ) {
        $params = $request->get_json_params();
        $token = $params['token'] ?? null;
        
        // Auth Check
        $auth_request = new \WP_REST_Request();
        $auth_request->set_param('token', $token);
        if ( ! User::allowed( $auth_request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Invalid token.', [ 'status' => 403 ] );
        }

        if ( ! User::file_edits_allowed() ) {
            return new \WP_Error( 'file_edit_disabled', 'File editing is disabled on this site (DISALLOW_FILE_EDIT or DISALLOW_FILE_MODS).', [ 'status' => 403 ] );
        }

        $old_path_relative = $params['old_path'] ?? '';
        $new_name          = $params['new_name'] ?? '';

        if ( empty( $old_path_relative ) || empty( $new_name ) ) {
            return new \WP_Error( 'missing_params', 'Missing required parameters.', [ 'status' => 400 ] );
        }

        // Security: Prevent directory traversal
        if ( strpos( $new_name, '/' ) !== false || strpos( $new_name, '..' ) !== false ) {
            return new \WP_Error( 'invalid_name', 'Invalid file name.', [ 'status' => 400 ] );
        }

        // Same wp-config.php protection as save_file, in both directions.
        if ( basename( $old_path_relative ) === 'wp-config.php' || $new_name === 'wp-config.php' ) {
            return new \WP_Error( 'protected_file', 'wp-config.php cannot be renamed through Disembark.', [ 'status' => 400 ] );
        }

        // Resolve Paths
        $base_dir = realpath( dirname( WP_CONTENT_DIR ) );
        $core_dir = realpath( ABSPATH );
        
        // Determine full path of the EXISTING file
        $old_full_path = realpath($base_dir . '/' . $old_path_relative);
        if ( !$old_full_path && $base_dir !== $core_dir ) {
            $old_full_path = realpath($core_dir . '/' . $old_path_relative);
        }

        // Verify it exists and is safe
        if ( !$old_full_path || ( !$this->path_within( $old_full_path, $base_dir ) && !$this->path_within( $old_full_path, $core_dir ) ) ) {
            return new \WP_Error( 'invalid_path', 'Invalid source file.', [ 'status' => 400 ] );
        }

        // Calculate new full path
        $directory = dirname( $old_full_path );
        $new_full_path = $directory . '/' . $new_name;

        if ( file_exists( $new_full_path ) ) {
            return new \WP_Error( 'exists', 'A file or folder with that name already exists.', [ 'status' => 409 ] );
        }

        if ( rename( $old_full_path, $new_full_path ) ) {
            return [ 'success' => true ];
        }

        return new \WP_Error( 'rename_failed', 'Could not rename file.', [ 'status' => 500 ] );
    }
    
    function regenerate_manifest( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        $scope = Scope::from_request( $request );
        if ( is_wp_error( $scope ) ) {
            return $scope;
        }
        $backup_token     = $request['backup_token'];
        $backup_manager   = new Backup( $backup_token );
        $step             = $request['step'] ?? 'initiate';
        $chunk_size_mb    = 150;
        $default_excludes = [
            Storage::relative_base_dir(),
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
        $scope = Scope::from_request( $request );
        if ( is_wp_error( $scope ) ) {
            return $scope;
        }
        $backup_token = $request['backup_token'];
        $files_list   = $request['files'];
        $archive_name = $request['archive_name'] ?? null;

        if ( empty( $backup_token ) || ! is_array( $files_list ) ) {
            return new \WP_Error( 'missing_params', 'Missing backup_token or files list.', [ 'status' => 400 ] );
        }
        
        // Pass the archive name to the backup function
        return ( new Backup( $backup_token ) )->zip_file_list( $files_list, $archive_name );
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
        Token::set( $token );

        return [ 'token' => $token ];
    }

    function export_database ( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        $scope = Scope::from_request( $request );
        if ( is_wp_error( $scope ) ) {
            return $scope;
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
        $scope = Scope::from_request( $request );
        if ( is_wp_error( $scope ) ) {
            return $scope;
        }
        $backup_token = $request['backup_token'];
        $tables       = $request['tables'];

        if ( ! is_array( $tables ) || empty( $tables ) ) {
            return new \WP_Error( 'missing_params', 'Missing tables array.', [ 'status' => 400 ] );
        }

        $schema_only = is_array( $request['schema_only'] ) ? array_values( array_filter( $request['schema_only'], 'is_string' ) ) : [];
        return ( new Backup( $backup_token ) )->database_export_batch( $tables, $schema_only, ! empty( $request['shared_rows'] ) );
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
        // JSON body (the CLI, fetch) or a plain form post (the dashboard's
        // download fallback, which lets the browser stream big files to disk).
        $params = $request->get_json_params();
        if ( empty( $params ) ) {
            $params = $request->get_body_params();
        }
        $token = $params['token'] ?? null;
        $file_path = $params['file'] ?? null;
        
        // New params for chunking. Negative values are clamped (a negative
        // length used to reach fread() and throw a ValueError).
        $offset = isset($params['offset']) ? max( 0, (int)$params['offset'] ) : 0;
        $length = isset($params['length']) ? max( 0, (int)$params['length'] ) : null;

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

        $is_in_base_dir = $this->path_within( $full_path, $base_dir );
        $is_in_core_dir = $core_dir !== $base_dir && $this->path_within( $full_path, $core_dir );
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
        
        // Never promise more bytes than the file has past the offset.
        $available = max( 0, filesize( $full_path ) - $offset );
        if ( $length !== null ) {
            $length = min( $length, $available );
        }

        // If length is specified, we are sending a partial content
        if ($length !== null) {
             header('Content-Length: ' . $length);
        } else {
             header('Content-Length: ' . $available);
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

    // --- Import / Restore Endpoint Callbacks ---

    function import_preflight( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        $params    = $request->get_json_params();
        $import_id = $params['import_id'] ?? '';
        $import    = new Import( $import_id );
        $scope     = Scope::from_request( $request );
        if ( is_wp_error( $scope ) ) {
            return $scope;
        }
        $pre = $import->preflight();
        // A network or a WP Freighter install says so, and which site this
        // request reached, so a pull can bring just one site.
        $pre['install'] = Scope::install();
        if ( $scope ) {
            $pre['home_url'] = $scope->home;
            $pre['site_url'] = $scope->siteurl;
            $pre['db_prefix'] = $scope->prefix;
            $pre['tables']   = array_keys( $scope->tables() );
            $pre['profile']  = $scope->profile();
            $pre['scope']    = $scope->info();
            unset( $pre['disembark_token'] );
        }
        return $pre;
    }

    function import_snapshot( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        $params    = $request->get_json_params();
        $import_id = $params['import_id'] ?? '';
        if ( empty( $import_id ) ) {
            return new \WP_Error( 'missing_import_id', 'import_id is required.', [ 'status' => 400 ] );
        }
        $import = new Import( $import_id );
        return $import->snapshot( (string) ( $params['label'] ?? '' ) );
    }

    /**
     * Builds a backup zip in the browser's session folder, step by step:
     * step=files (files[]), step=database (tables[], or table+part+rows_per_part),
     * step=finish (returns the zip URL).
     */
    function backup_archive( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        $scope = Scope::from_request( $request );
        if ( is_wp_error( $scope ) ) {
            return $scope;
        }
        $token = Storage::sanitize_session( (string) ( $request['backup_token'] ?? '' ) );
        if ( $token === '' ) {
            return new \WP_Error( 'missing_token', 'backup_token is required.', [ 'status' => 400 ] );
        }
        $backup  = new Backup( $token );
        $archive = (string) ( $request['archive'] ?? '' );
        switch ( (string) ( $request['step'] ?? '' ) ) {
            case 'files':
                return $backup->archive_add_files( $archive, (array) ( $request['files'] ?? [] ) );
            case 'database':
                $table = preg_replace( '/[^a-zA-Z0-9_$-]/', '', (string) ( $request['table'] ?? '' ) );
                $tables = array_values( array_filter( (array) ( $request['tables'] ?? [] ), 'is_string' ) );
                return $backup->archive_add_database( $archive, $tables, $table, (int) ( $request['part'] ?? 0 ), (int) ( $request['rows_per_part'] ?? 0 ) );
            case 'finish':
                return $backup->archive_finish( $archive );
        }
        return new \WP_Error( 'bad_step', 'Unknown step.', [ 'status' => 400 ] );
    }

    function restore_points( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        return Import::restore_points();
    }

    function delete_restore_point( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        $import_id = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) ( $request['import_id'] ?? '' ) ) );
        if ( $import_id === '' ) {
            return new \WP_Error( 'missing_import_id', 'import_id is required.', [ 'status' => 400 ] );
        }
        return ( new Import( $import_id ) )->delete_restore_point();
    }

    /**
     * On-demand search & replace (the same serialize- and JSON-safe engine a
     * restore uses). dry_run counts what would change and returns samples.
     */
    function search_replace( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        $from   = (string) ( $request['from'] ?? '' );
        $to     = (string) ( $request['to'] ?? '' );
        $tables = array_values( array_filter( (array) ( $request['tables'] ?? [] ), 'is_string' ) );
        if ( $from === '' ) {
            return new \WP_Error( 'missing_from', 'Enter the text to search for.', [ 'status' => 400 ] );
        }
        return ( new Import() )->search_replace( $from, $to, $tables, ! empty( $request['dry_run'] ) );
    }

    /**
     * Quiet mode. GET (or POST without action): status with the held emails'
     * list; ?message=<index> returns one held email. POST action=on (source,
     * import_id) or action=off.
     */
    function quiet_mode( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        $action = (string) ( $request['action'] ?? '' );
        if ( $action === 'on' ) {
            $import_id = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) ( $request['import_id'] ?? '' ) ) );
            // Quiet mode covers the whole install, so a move into one of its
            // subsites or tenants doesn't turn it on.
            if ( $import_id !== '' && ( new Import( $import_id ) )->target() ) {
                return [ 'on' => false, 'skipped' => 'target', 'why' => 'Quiet mode would cover every site here, so it stays off for a move into one subsite or tenant.' ];
            }
            return Quiet::enable( esc_url_raw( (string) ( $request['source'] ?? '' ) ), $import_id );
        }
        if ( $action === 'off' ) {
            return Quiet::disable();
        }
        if ( isset( $request['message'] ) && $request['message'] !== '' ) {
            return Quiet::held_message( (int) $request['message'] );
        }
        return Quiet::status( true );
    }

    function import_upload_zip( $request ) {
        $params = $request->get_params();
        $token  = $params['token'] ?? null;

        $auth_request = new \WP_REST_Request();
        $auth_request->set_param( 'token', $token );
        if ( ! User::allowed( $auth_request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }

        $import_id = $params['import_id'] ?? '';
        if ( empty( $import_id ) ) {
            return new \WP_Error( 'missing_import_id', 'import_id is required.', [ 'status' => 400 ] );
        }

        $files = $request->get_file_params();
        if ( empty( $files['file'] ) ) {
            return new \WP_Error( 'no_file', 'No file uploaded.', [ 'status' => 400 ] );
        }

        $import = new Import( $import_id );
        return $import->upload_zip( $files['file'] );
    }

    function import_upload_chunk( $request ) {
        $params = $request->get_params();
        $token  = $params['token'] ?? null;

        $auth_request = new \WP_REST_Request();
        $auth_request->set_param( 'token', $token );
        if ( ! User::allowed( $auth_request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }

        $import_id    = $params['import_id'] ?? '';
        $file_path    = $params['file_path'] ?? '';
        $chunk_index  = isset( $params['chunk_index'] ) ? (int) $params['chunk_index'] : -1;
        $total_chunks = isset( $params['total_chunks'] ) ? (int) $params['total_chunks'] : 0;

        if ( empty( $import_id ) || empty( $file_path ) || $chunk_index < 0 || $total_chunks < 1 ) {
            return new \WP_Error( 'missing_params', 'Missing required parameters.', [ 'status' => 400 ] );
        }

        $files = $request->get_file_params();
        if ( empty( $files['file'] ) ) {
            return new \WP_Error( 'no_file', 'No chunk file uploaded.', [ 'status' => 400 ] );
        }

        $import = new Import( $import_id );
        return $import->upload_chunk( $files['file'], $file_path, $chunk_index, $total_chunks );
    }

    function import_extract_zip( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        $params    = $request->get_json_params();
        $import_id = $params['import_id'] ?? '';
        $file_path = $params['file_path'] ?? '';

        if ( empty( $import_id ) || empty( $file_path ) ) {
            return new \WP_Error( 'missing_params', 'import_id and file_path are required.', [ 'status' => 400 ] );
        }

        $import = new Import( $import_id );
        return $import->extract_staged_zip( $file_path );
    }

    function import_deploy_files( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        $params    = $request->get_json_params();
        $import_id = $params['import_id'] ?? '';
        $files     = $params['files'] ?? [];

        if ( empty( $import_id ) || empty( $files ) ) {
            return new \WP_Error( 'missing_params', 'import_id and files array required.', [ 'status' => 400 ] );
        }

        // keep_host_files (default on) leaves the destination's mu-plugins
        // and cache drop-ins in place; false brings the source's over too.
        $keep   = ! array_key_exists( 'keep_host_files', $params ) || ! empty( $params['keep_host_files'] );
        $import = new Import( $import_id );
        return $import->deploy_files( $files, $keep );
    }

    function import_sql( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        $params      = $request->get_json_params();
        $import_id   = $params['import_id'] ?? '';
        $sql_content = $params['sql'] ?? '';

        if ( empty( $sql_content ) ) {
            return new \WP_Error( 'missing_sql', 'SQL content is required.', [ 'status' => 400 ] );
        }

        $import = new Import( $import_id );
        $import->set_request_token( (string) $request->get_param( 'token' ) );
        return $import->execute_sql( $sql_content );
    }

    function import_remap_prefix( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        $params     = $request->get_json_params();
        $import_id  = $params['import_id'] ?? '';
        $old_prefix = $params['old_prefix'] ?? '';
        $new_prefix = $params['new_prefix'] ?? '';

        if ( $old_prefix === '' || $new_prefix === '' ) {
            return new \WP_Error( 'missing_params', 'old_prefix and new_prefix are required.', [ 'status' => 400 ] );
        }

        $import = new Import( $import_id );
        return $import->remap_table_prefix( $old_prefix, $new_prefix );
    }

    function import_pull_connect( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        $params       = $request->get_json_params();
        $import_id    = $params['import_id'] ?? '';
        $source_url   = $params['source_url'] ?? '';
        $source_token = $params['source_token'] ?? '';
        if ( empty( $source_url ) || empty( $source_token ) ) {
            return new \WP_Error( 'missing_params', 'source_url and source_token are required.', [ 'status' => 400 ] );
        }
        $move = is_array( $params['move'] ?? null ) ? $params['move'] : [];
        $move = [
            'client' => 'dashboard',
            'kind'   => ( $move['kind'] ?? '' ) === 'push' ? 'push' : 'pull',
            'quiet'  => ! empty( $move['quiet'] ),
            'label'  => sanitize_text_field( (string) ( $move['label'] ?? '' ) ),
        ];
        $site = isset( $params['site'] ) && $params['site'] !== '' && $params['site'] !== null ? (string) $params['site'] : null;
        return ( new Pull( $import_id ) )->connect( $source_url, $source_token, $move, ! empty( $params['catch_up'] ), $site, ! empty( $params['shared_rows'] ) );
    }

    /**
     * Where a move lands on a network or WP Freighter install: "site" (this
     * site), "new" (a new subsite or tenant), or the ID of one to replace.
     */
    function import_target( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        $params    = $request->get_json_params();
        $import_id = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) ( $params['import_id'] ?? '' ) ) );
        if ( $import_id === '' ) {
            return new \WP_Error( 'missing_import_id', 'import_id is required.', [ 'status' => 400 ] );
        }
        return ( new Import( $import_id ) )->set_target( (string) ( $params['into'] ?? '' ), [ 'slug' => (string) ( $params['slug'] ?? '' ), 'domain' => (string) ( $params['domain'] ?? '' ) ] );
    }

    /** Picks one site of the source's network or WP Freighter install ("all" for every site). */
    function import_pull_site( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        $params    = $request->get_json_params();
        $import_id = $params['import_id'] ?? '';
        if ( empty( $import_id ) ) {
            return new \WP_Error( 'missing_import_id', 'import_id is required.', [ 'status' => 400 ] );
        }
        return ( new Pull( $import_id ) )->choose_site( (string) ( $params['site'] ?? '' ) );
    }

    function import_pull_scan( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        $params    = $request->get_json_params();
        $import_id = $params['import_id'] ?? '';
        $step      = $params['step'] ?? '';
        $chunk     = isset( $params['chunk'] ) ? (int) $params['chunk'] : 0;
        if ( empty( $import_id ) || empty( $step ) ) {
            return new \WP_Error( 'missing_params', 'import_id and step are required.', [ 'status' => 400 ] );
        }
        return ( new Pull( $import_id ) )->scan_step( $step, $chunk );
    }

    function import_pull_fetch_files( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        $params    = $request->get_json_params();
        $import_id = $params['import_id'] ?? '';
        $offset    = isset( $params['offset'] ) ? (int) $params['offset'] : 0;
        $count     = isset( $params['count'] ) ? (int) $params['count'] : 300;
        if ( empty( $import_id ) ) {
            return new \WP_Error( 'missing_import_id', 'import_id is required.', [ 'status' => 400 ] );
        }
        return ( new Pull( $import_id ) )->fetch_files( $offset, $count );
    }

    function import_pull_fetch_database( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        $params    = $request->get_json_params();
        $import_id = $params['import_id'] ?? '';
        if ( empty( $import_id ) ) {
            return new \WP_Error( 'missing_import_id', 'import_id is required.', [ 'status' => 400 ] );
        }
        return ( new Pull( $import_id ) )->fetch_database();
    }

    /** Moves that stopped before finishing (see Pull::unfinished()). */
    function import_unfinished( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        return Pull::unfinished();
    }

    /**
     * Resume a move: clears a leftover cancel marker and returns every step
     * it already finished, for the dashboard to replay.
     */
    function import_resume( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        $import_id = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) ( $request['import_id'] ?? '' ) ) );
        $found     = null;
        foreach ( Pull::unfinished() as $move ) {
            if ( $move['id'] === $import_id ) {
                $found = $move;
            }
        }
        if ( ! $found ) {
            return new \WP_Error( 'not_found', 'That move has already finished or was cleaned up.', [ 'status' => 404 ] );
        }
        @unlink( Storage::base_dir() . "/_import_{$import_id}/cancel" );
        return [ 'move' => $found, 'steps' => (object) Steps::results( $import_id ) ];
    }

    /** Sources this site has pulled from, for catch-up pulls. */
    function import_last_pulls( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        return Pull::last_pulls();
    }

    /** Cancel a move: action=stop, then action=cleanup (see Import::cancel()). */
    function import_cancel( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        $import_id = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) ( $request['import_id'] ?? '' ) ) );
        if ( $import_id === '' ) {
            return new \WP_Error( 'missing_import_id', 'import_id is required.', [ 'status' => 400 ] );
        }
        $action = (string) ( $request['action'] ?? 'stop' ) === 'cleanup' ? 'cleanup' : 'stop';
        return ( new Import( $import_id ) )->cancel( $action, ! empty( $request['delete_restore_point'] ) );
    }

    /** Checks before the move changes anything (see Checks). */
    function import_checks( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        $import_id = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) ( $request['import_id'] ?? '' ) ) );
        if ( $import_id === '' ) {
            return new \WP_Error( 'missing_import_id', 'import_id is required.', [ 'status' => 400 ] );
        }
        return Checks::before_move( $import_id );
    }

    /** Checks after the move: rows, pages, leftover addresses (see Verify). */
    function import_verify( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        @set_time_limit( 300 );
        return Verify::run( (string) ( $request['import_id'] ?? '' ) );
    }

    /** Who to tell when the move ends (action=set), and telling them (action=send). */
    function import_notify( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        $import_id = (string) ( $request['import_id'] ?? '' );
        if ( (string) ( $request['action'] ?? '' ) === 'send' ) {
            $details = $request['details'] ?? [];
            return Notify::send( $import_id, (string) ( $request['event'] ?? 'finished' ), is_array( $details ) ? $details : [] );
        }
        return Notify::set( $import_id, (string) ( $request['to'] ?? '' ) );
    }

    function import_pull_contents( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        $import_id = (string) ( $request['import_id'] ?? '' );
        if ( $import_id === '' ) {
            return new \WP_Error( 'missing_import_id', 'import_id is required.', [ 'status' => 400 ] );
        }
        $contents = ( new Pull( $import_id ) )->contents();
        if ( is_array( $contents ) ) {
            $contents['templates'] = Templates::all();
        }
        return $contents;
    }

    function import_pull_select( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        $import_id = (string) ( $request['import_id'] ?? '' );
        if ( $import_id === '' ) {
            return new \WP_Error( 'missing_import_id', 'import_id is required.', [ 'status' => 400 ] );
        }
        $list = function ( $key ) use ( $request ) {
            return is_array( $request[ $key ] ) ? array_values( array_filter( $request[ $key ], 'is_string' ) ) : [];
        };
        return ( new Pull( $import_id ) )->select( $list( 'skip_paths' ), $list( 'skip_names' ), $list( 'skip_rows' ) );
    }

    /**
     * Saved "What to bring" templates on this site. GET lists them; POST
     * action=save (name, suggestions, paths, rows) or action=delete (name).
     */
    function templates( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        $action = (string) ( $request['action'] ?? '' );
        if ( $action === 'save' ) {
            return Templates::save( [
                'name'        => $request['name'] ?? '',
                'suggestions' => (array) ( $request['suggestions'] ?? [] ),
                'paths'       => (array) ( $request['paths'] ?? [] ),
                'rows'        => (array) ( $request['rows'] ?? [] ),
            ] );
        }
        if ( $action === 'delete' ) {
            return Templates::delete( (string) ( $request['name'] ?? '' ) );
        }
        return Templates::all();
    }

    function import_staged_info( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        $params    = $request->get_json_params();
        $import_id = $params['import_id'] ?? '';
        if ( empty( $import_id ) ) {
            return new \WP_Error( 'missing_import_id', 'import_id is required.', [ 'status' => 400 ] );
        }
        return ( new Import( $import_id ) )->staged_info();
    }

    function import_staged_sql( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        $params     = $request->get_json_params();
        $import_id  = $params['import_id'] ?? '';
        $sql_file   = $params['sql_file'] ?? '';
        $old_prefix = $params['old_prefix'] ?? '';
        $new_prefix = $params['new_prefix'] ?? '';

        if ( empty( $import_id ) || empty( $sql_file ) ) {
            return new \WP_Error( 'missing_params', 'import_id and sql_file are required.', [ 'status' => 400 ] );
        }
        return ( new Import( $import_id ) )
            ->set_request_token( (string) $request->get_param( 'token' ) )
            ->import_staged_sql( $sql_file, $old_prefix, $new_prefix );
    }

    function import_search_replace( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        $params    = $request->get_json_params();
        $import_id = $params['import_id'] ?? '';
        $from      = $params['from'] ?? '';
        $to        = $params['to'] ?? '';
        $tables    = $params['tables'] ?? [];

        if ( $from === '' ) {
            return new \WP_Error( 'missing_params', 'A "from" value is required.', [ 'status' => 400 ] );
        }
        if ( ! is_array( $tables ) ) {
            $tables = [];
        }

        $import = new Import( $import_id );
        // A move rewrites every spelling of the old address and the old
        // server's paths, and fixes the text files it brought in (uploads).
        if ( $import_id !== '' && ! $tables ) {
            return $import->move_search_replace( $from, $to );
        }
        return $import->search_replace( $from, $to, $tables );
    }

    function import_rollback( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        $params    = $request->get_json_params();
        $import_id = $params['import_id'] ?? '';

        if ( empty( $import_id ) ) {
            return new \WP_Error( 'missing_import_id', 'import_id (rollback_id) is required.', [ 'status' => 400 ] );
        }

        $import = new Import( $import_id );
        $import->set_request_token( (string) $request->get_param( 'token' ) );
        return $import->rollback();
    }

    function import_finalize( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        $params    = $request->get_json_params();
        $import_id = $params['import_id'] ?? '';

        if ( empty( $import_id ) ) {
            return new \WP_Error( 'missing_import_id', 'import_id is required.', [ 'status' => 400 ] );
        }

        $import = new Import( $import_id );
        return $import->finalize();
    }

    /**
     * Deletes backup working files. With a backup_token, only that session's
     * folder goes (the CLI passes its own at the end of every backup, so a
     * parallel backup or pull keeps its files). Without one, every backup
     * session goes. Restore folders (_import_*: staged restores and their
     * rollback points) are kept unless include_restore_points is set, so a
     * backup taken after a restore can't silently remove the way back.
     */
    function cleanup( $request ) {
        if ( ! User::allowed( $request ) ) {
            return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => 403 ] );
        }
        $base    = Storage::base_dir();
        $session = Storage::sanitize_session( $request['backup_token'] ?? '' );
        $targets = [];
        if ( $session !== '' ) {
            $targets[] = "{$base}/{$session}";
        } else {
            $include_restore_points = ! empty( $request['include_restore_points'] );
            foreach ( (array) glob( "{$base}/*", GLOB_ONLYDIR ) as $dir ) {
                if ( strpos( basename( $dir ), '_import_' ) === 0 && ! $include_restore_points ) {
                    continue;
                }
                $targets[] = $dir;
            }
        }

        header( 'Content-Type: text/plain' );
        foreach ( $targets as $dir ) {
            if ( ! is_dir( $dir ) || is_link( $dir ) ) {
                continue;
            }
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ( $items as $item ) {
                if ( $item->isDir() && ! $item->isLink() ) {
                    @rmdir( $item->getPathname() );
                } else {
                    echo "Removing {$item->getPathname()}\n";
                    @unlink( $item->getPathname() );
                }
            }
            @rmdir( $dir );
        }
        exit;
    }
}
