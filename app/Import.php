<?php

namespace Disembark;

class Import {

    private $import_id;
    private $import_path;
    private $staging_path;
    private $rollback_path;
    private $request_token = '';

    public function __construct( $import_id = '' ) {
        // Strip everything but hex so a caller-supplied id can never be used to
        // climb out of the uploads/disembark directory via the path templates
        // below. All ids we mint are hex (random_bytes / rollback-<hex>).
        $import_id = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $import_id ) );
        if ( empty( $import_id ) ) {
            $import_id = substr( bin2hex( random_bytes( 16 ) ), 0, 24 );
        }
        $this->import_id     = $import_id;
        $base                = wp_upload_dir()['basedir'] . '/disembark';
        $this->import_path   = "{$base}/_import_{$import_id}";
        $this->staging_path  = "{$this->import_path}/staging";
        $this->rollback_path = "{$this->import_path}/rollback";
    }

    /**
     * Creates this import's folder and guards it (and the base folder) against
     * directory listing. Staging is deliberately never guarded: everything in
     * it is deployed to the web root, so a guard file there would overwrite
     * the site's own index.php.
     */
    /**
     * Largest upload chunk this host accepts, capped at 20 MB. Hosts with a
     * small upload_max_filesize or post_max_size would otherwise reject the
     * dashboard's and CLI's chunks outright.
     */
    public static function upload_chunk_bytes() {
        $max = function_exists( 'wp_max_upload_size' ) ? (int) wp_max_upload_size() : 0;
        if ( $max <= 0 ) {
            $max = 20 * 1024 * 1024;
        }
        // Leave room for the multipart envelope and the other form fields.
        return (int) max( 256 * 1024, min( 20 * 1024 * 1024, $max - 64 * 1024 ) );
    }

    private function prepare_import_dir() {
        Storage::ensure_dir( $this->import_path );
    }

    /**
     * The token the current request authenticated with. A retried import or
     * a rollback uses it to recover this site's real token when the options
     * table already holds the source's (see begin_statements()).
     */
    public function set_request_token( $token ) {
        $this->request_token = is_string( $token ) ? $token : '';
        return $this;
    }

    public function get_import_id() {
        return $this->import_id;
    }

    /* ------------------------------------------------------------------
     * Files only PHP can read
     *
     * Everything Disembark keeps lives under the public uploads folder, and
     * index files only stop listings, not a direct GET. So restore points
     * (full database dumps) and state that holds secrets are written as .php
     * files that start with an exit line: a web server either runs them,
     * and they stop before printing a byte, or refuses to serve .php source.
     * The same format as Steps, Templates and the pull memory.
     * ---------------------------------------------------------------- */

    const PHP_EXIT = "<?php exit; ?>\n";

    /** Writes $data behind the exit line, atomically. */
    public static function write_protected( $file, $data ) {
        $tmp = $file . '.tmp';
        if ( @file_put_contents( $tmp, self::PHP_EXIT . $data ) === false ) {
            @unlink( $tmp );
            return false;
        }
        return @rename( $tmp, $file );
    }

    /** What write_protected() wrote (an older file without the exit line as is), or null. */
    public static function read_protected( $file ) {
        if ( ! is_file( $file ) ) {
            return null;
        }
        $raw = (string) file_get_contents( $file );
        return strpos( $raw, self::PHP_EXIT ) === 0 ? (string) substr( $raw, strlen( self::PHP_EXIT ) ) : $raw;
    }

    /**
     * A restore point's metadata: rollback/meta.php, or meta.json from
     * 2.9 and early 3.0 builds. Null when the folder holds no restore point.
     */
    public static function rollback_meta( $rollback_dir ) {
        foreach ( [ 'meta.php', 'meta.json' ] as $name ) {
            $raw = self::read_protected( rtrim( $rollback_dir, '/' ) . '/' . $name );
            if ( $raw !== null ) {
                $meta = json_decode( $raw, true );
                return is_array( $meta ) ? $meta : [];
            }
        }
        return null;
    }

    /** Whether the import folder $import_dir holds a restore point. */
    public static function has_restore_point( $import_dir ) {
        return is_file( "{$import_dir}/rollback/meta.php" ) || is_file( "{$import_dir}/rollback/meta.json" );
    }

    /** Saves this import's restore point metadata (an older meta.json goes). */
    private function save_rollback_meta( $meta ) {
        if ( self::write_protected( $this->rollback_path . '/meta.php', (string) json_encode( $meta, JSON_PRETTY_PRINT ) ) ) {
            @unlink( $this->rollback_path . '/meta.json' );
            return true;
        }
        return false;
    }

    /** A restore point's database dumps: *.db.php, plus *.db.txt / *.sql.txt from older builds. */
    private static function rollback_sql_files( $rollback_dir ) {
        $files = array_merge( glob( rtrim( $rollback_dir, '/' ) . '/*.db.php' ) ?: [], Backup::export_files( $rollback_dir ) );
        sort( $files );
        return $files;
    }

    /** Copies $from to $to behind the exit line. True when every byte arrived. */
    public static function copy_protected( $from, $to ) {
        // A temp name of its own: two requests copying to the same place
        // (a retried step) never write into one file, and neither is read
        // half written. The exit line goes first, so even the temp file
        // shows nothing.
        $in  = @fopen( $from, 'rb' );
        $tmp = $to . '.' . bin2hex( random_bytes( 6 ) ) . '.tmp';
        $out = $in ? @fopen( $tmp, 'wb' ) : false;
        if ( ! $in || ! $out ) {
            if ( $in ) {
                fclose( $in );
            }
            return false;
        }
        $ok = fwrite( $out, self::PHP_EXIT ) === strlen( self::PHP_EXIT ) && stream_copy_to_stream( $in, $out ) !== false;
        fclose( $in );
        $ok = fclose( $out ) && $ok;
        clearstatcache();
        if ( ! $ok || filesize( $tmp ) !== filesize( $from ) + strlen( self::PHP_EXIT ) || ! @rename( $tmp, $to ) ) {
            @unlink( $tmp );
            return false;
        }
        return true;
    }

    /** Where this move lands when it isn't this site itself (see Target), or null. */
    public function target() {
        return Target::load( $this->import_path );
    }

    /* ------------------------------------------------------------------
     * Moves that can't land here
     *
     * Checked by every step that changes the site (and by connect and the
     * upload, so nothing is copied for nothing), so a client that skips a
     * question in the dashboard or the CLI is refused all the same.
     * ---------------------------------------------------------------- */

    /**
     * A whole-site move into a subsite or tenant, from inside it (a push to
     * its own connect line, a pull or restore from its own Disembark, the
     * CLI in its context): the import would write core, plugins, themes and
     * uploads into the folders the whole install shares. WP_Error saying
     * which move does it (from the main site, Into: replace), or null.
     */
    public static function here_refusal( $target = null ) {
        if ( $target ) {
            return null;
        }
        $install = Scope::install();
        if ( ! $install || (string) $install['current'] === (string) $install['main'] ) {
            return null;
        }
        $id   = (string) $install['current'];
        $main = (string) $install['home'];
        $host = (string) wp_parse_url( $main, PHP_URL_HOST ) . rtrim( (string) wp_parse_url( $main, PHP_URL_PATH ), '/' );
        if ( $install['kind'] === 'multisite' ) {
            $what = 'a subsite (site ' . $id . ') of the network at ' . $host;
            $pick = 'replace site ' . $id;
            $cli  = 'wp disembark pull <connect line> --into=' . $id . ' --url=' . $host;
        } else {
            $what = 'tenant ' . $id . ' of the WP Freighter install at ' . $host;
            $pick = 'replace tenant ' . $id;
            $cli  = 'wp disembark pull <connect line> --into=' . $id . ' (in the main site\'s context, without STACKED_SITE_ID)';
        }
        return new \WP_Error( 'not_main_site', 'This is ' . $what . '. A move run from here would write over files the whole install shares, so it can\'t replace this site directly. To replace it, run the move from the main site (' . $main . ') and pick Into: ' . $pick . '. In WP-CLI: ' . $cli . '. Nothing here was changed.', [ 'status' => 409 ] );
    }

    /** Records where the move goes: '' (this site), "new", or a subsite or tenant ID. */
    public function set_target( $into, $opts = [] ) {
        $this->prepare_import_dir();
        $t = Target::choose( $this->import_path, $into, $opts );
        if ( is_wp_error( $t ) ) {
            return $t;
        }
        return [ 'ok' => true, 'target' => $t ? $t->info() : null ];
    }

    /** Why this move can't go on here (see here_refusal(), whole_install_refusal()), or null. */
    public function refused() {
        return self::here_refusal( $this->target() ) ?: $this->whole_install_refusal();
    }

    /**
     * A whole multisite network or WP Freighter install moved as one unit
     * (a pull of its main address with every site, or a backup zip of all
     * of it), into anything. Only half of that is built: a network's site
     * addresses (wp_blogs, wp_site) and its wp-config.php constants, and a
     * Freighter install's bootstrap and wp-config.php line, don't come
     * across, and into an install it would take over the install's own
     * registry. Refused, pointing at one site at a time. Null otherwise.
     */
    public function whole_install_refusal() {
        $kind  = '';
        $total = 0;
        $zip   = false;
        $state = is_file( $this->import_path . '/pull.json' ) ? json_decode( (string) file_get_contents( $this->import_path . '/pull.json' ), true ) : null;
        if ( is_array( $state ) && ! empty( $state['source_url'] ) ) {
            $install = (array) ( $state['install'] ?? [] );
            if ( $install && empty( $state['site'] ) && empty( $state['scope'] ) ) {
                $kind  = (string) ( $install['kind'] ?? '' );
                $total = (int) ( $install['total'] ?? 0 );
            }
        } else {
            $source  = json_decode( (string) wp_json_encode( $this->staged_source() ), true );
            $profile = is_array( $source ) ? (array) ( $source['profile'] ?? [] ) : [];
            if ( is_array( $source ) && empty( $source['scope'] ) ) {
                $zip = true;
                if ( ! empty( $profile['install']['kind'] ) ) {
                    $kind  = (string) $profile['install']['kind'];
                    $total = (int) ( $profile['install']['total'] ?? 0 );
                } elseif ( ! empty( $profile['multisite'] ) || $this->staged_multisite_constants() ) {
                    $kind = 'multisite';
                } elseif ( ! array_key_exists( 'install', $profile ) && is_file( self::staged_file( $this->staging_path . '/public', 'wp-content/freighter.php' ) ) ) {
                    // A backup from before the profile named the install.
                    $kind = 'freighter';
                }
            }
        }
        if ( $kind !== 'multisite' && $kind !== 'freighter' ) {
            return null;
        }
        $what  = $kind === 'multisite' ? 'multisite network' : 'WP Freighter install';
        $count = $total > 1 ? ' (' . $total . ' sites)' : '';
        $why   = $kind === 'multisite'
            ? 'its sites\' addresses and the network\'s wp-config.php settings wouldn\'t come across'
            : 'its tenants\' routing (WP Freighter\'s bootstrap and its wp-config.php line) wouldn\'t come across';
        $how   = $zip
            ? 'Back up one site of it instead (Backups → Download a backup zip, then pick the site), or pull the site you want from the ' . ( $kind === 'multisite' ? 'network' : 'install' ) . ' with --site=<id>.'
            : 'Bring its sites one at a time: choose one site (in WP-CLI, --site=<id>).';
        return new \WP_Error( 'whole_install', ( $zip ? 'This backup holds a whole ' : 'The source is a whole ' ) . $what . $count . '. Moving a whole ' . $what . ' as one isn\'t supported yet (it\'s planned): ' . $why . '. ' . $how . ' Nothing here was changed.', [ 'status' => 409 ] );
    }

    /**
     * Rejects a caller-supplied relative path that could climb out of the
     * staging / web-root tree (absolute paths, "..", or null bytes). Public
     * static so Pull and the PclZip pre-extract guard share the same rule.
     */
    public static function is_safe_relative_path( $relative_path ) {
        if ( ! is_string( $relative_path ) || $relative_path === '' ) {
            return false;
        }
        if ( strpos( $relative_path, "\0" ) !== false ) {
            return false;
        }
        // No absolute paths and no Windows drive letters.
        if ( $relative_path[0] === '/' || $relative_path[0] === '\\' || preg_match( '#^[a-zA-Z]:#', $relative_path ) ) {
            return false;
        }
        // No parent-directory traversal in any segment.
        foreach ( preg_split( '#[/\\\\]+#', $relative_path ) as $segment ) {
            if ( $segment === '..' ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Returns destination metadata the CLI needs to plan transformations.
     */
    public function preflight() {
        global $wpdb;

        $tables = [];
        $rows   = $wpdb->get_results(
            "SELECT table_name AS `table`, data_length + index_length AS `size`
             FROM information_schema.TABLES
             WHERE table_schema = '" . DB_NAME . "'
             ORDER BY (data_length + index_length) DESC"
        );
        foreach ( $rows as $row ) {
            $tables[] = $row->table;
        }

        $mysql_version = $wpdb->get_var( 'SELECT VERSION()' );

        $pre = [
            'db_prefix'          => $wpdb->prefix,
            'home_url'           => home_url(),
            'site_url'           => site_url(),
            'abspath'            => ABSPATH,
            'content_dir'        => WP_CONTENT_DIR,
            'upload_max_filesize' => ini_get( 'upload_max_filesize' ),
            'post_max_size'      => ini_get( 'post_max_size' ),
            'upload_chunk_bytes' => self::upload_chunk_bytes(),
            // Steps sent with a request_id are safe to retry (see Steps).
            'resumable'          => true,
            // Long steps can stop and carry on, and the client paces the
            // move to the host (see Pace): steps take a time budget, the
            // pull's batches are sized as the host allows, and a source
            // exports large tables in parts.
            'pacing'             => 1,
            // Table fingerprints, so a catch-up skips tables unchanged on
            // both sides (see Fingerprint).
            'fingerprint'        => 1,
            'budget'             => Pace::default_budget(),
            'max_execution_time' => ini_get( 'max_execution_time' ),
            'tables'             => $tables,
            'profile'            => self::site_profile(),
            'php_version'        => phpversion(),
            'mysql_version'      => $mysql_version,
            // Lets the CLI keep its access through the import (which
            // replaces the options table). Never to a request that only got
            // in with a token guard: that would hand out a newer token.
            'disembark_token'    => Token::read(),
        ];
        if ( self::$guard_authenticated ) {
            unset( $pre['disembark_token'] );
        }
        return $pre;
    }

    /**
     * What a move's checks compare between two sites: versions, plugins and
     * theme. Sent by the source in preflight and kept in backup zips.
     */
    public static function site_profile() {
        global $wpdb;
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $active = (array) get_option( 'active_plugins', [] );
        if ( is_multisite() ) {
            $active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) );
        }
        $plugins = [];
        foreach ( (array) get_plugins() as $file => $data ) {
            $plugins[ $file ] = [ 'name' => (string) ( $data['Name'] ?? $file ), 'version' => (string) ( $data['Version'] ?? '' ), 'active' => in_array( $file, $active, true ) ];
        }
        $theme = wp_get_theme();
        return [
            'wp_version'    => (string) get_bloginfo( 'version' ),
            'php_version'   => PHP_VERSION,
            'mysql_version' => (string) $wpdb->get_var( 'SELECT VERSION()' ),
            'plugins'       => $plugins,
            'theme'         => [ 'stylesheet' => $theme->get_stylesheet(), 'name' => (string) $theme->get( 'Name' ), 'version' => (string) $theme->get( 'Version' ) ],
            'multisite'     => is_multisite(),
            // A network or WP Freighter install exported whole (see whole_install_refusal()).
            'install'       => ( $install = Scope::install() ) ? [ 'kind' => $install['kind'], 'total' => (int) $install['total'] ] : null,
        ];
    }

    /**
     * Creates a rollback snapshot of the destination database and file manifest.
     */
    public function snapshot( $label = '', $budget = null, $tables = null ) {
        global $wpdb;

        // $tables: a restore point of just those tables, before a change
        // that isn't a move (Browse's search and replace).
        if ( $tables === null && ( $refused = $this->refused() ) ) {
            return $refused;
        }

        $this->prepare_import_dir();
        Storage::ensure_dir( $this->rollback_path );

        // Without a budget (an older client), the whole restore point in
        // this one request, the same way.
        return $this->snapshot_paced( $label, $budget !== null ? $budget : DAY_IN_SECONDS, $tables );
    }

    /**
     * The tables a restore point holds:
     * - into a subsite or tenant, its own tables (none for a new one): the
     *   rows the move adds to the install's shared tables (users, the
     *   registry) are journaled and taken out again instead (see
     *   Target::merge_users(), Target::undo());
     * - the main site of a network or WP Freighter install, its own tables
     *   (own_tables()): never a subsite's, a tenant's or the network's;
     * - a plain site, every table in the database, as the import may
     *   replace any of them.
     */
    private function snapshot_scope( $target ) {
        global $wpdb;
        if ( $target ) {
            return $target->tables();
        }
        return Scope::install() ? self::own_tables() : (array) $wpdb->get_col( 'SHOW TABLES' );
    }

    /**
     * This site's own tables: under its prefix, and not another site's of
     * its install (a subsite's, a tenant's, the network's shared tables)
     * or another WordPress's in the same database whose prefix starts with
     * this one's (wp_ and wp_staging_).
     */
    public static function own_tables() {
        global $wpdb;
        $prefix = strtolower( (string) $wpdb->prefix );
        $rows   = array_map( 'strval', (array) $wpdb->get_col( 'SHOW TABLES' ) );
        if ( $prefix === '' ) {
            return $rows;
        }
        $all   = array_flip( array_map( 'strtolower', $rows ) );
        $other = [];
        foreach ( array_keys( $all ) as $n ) {
            if ( substr( $n, -7 ) === 'options' && ( $p = substr( $n, 0, -7 ) ) !== $prefix && strpos( $p, $prefix ) === 0 && isset( $all[ $p . 'posts' ] ) && ! self::other_sites_table( $n ) ) {
                $other[] = $p;
            }
        }
        $out = [];
        foreach ( $rows as $table ) {
            $lower = strtolower( $table );
            if ( strpos( $lower, $prefix ) !== 0 || self::other_sites_table( $table ) ) {
                continue;
            }
            foreach ( $other as $p ) {
                if ( strpos( $lower, $p ) === 0 ) {
                    continue 2;
                }
            }
            $out[] = $table;
        }
        return $out;
    }

    /** The tables this move's import created (all of them, also while it's still running). */
    private function imported_tables() {
        $names = (array) json_decode( (string) @file_get_contents( $this->import_path . '/imported-tables.json' ), true );
        $state = json_decode( (string) self::read_protected( $this->import_path . '/sql-state.php' ), true );
        if ( is_array( $state ) ) {
            $names = array_merge( $names, array_keys( (array) ( $state['imported'] ?? [] ) ) );
        }
        return array_values( array_unique( array_filter( $names, 'is_string' ) ) );
    }

    /**
     * The restore point, a piece at a time (see Pace): each call exports
     * until its budget, straight into the restore point's dump (behind the
     * exit line, under a name rollback() doesn't read until it's whole), and
     * remembers the table and row it stopped at. The call that writes the
     * last row saves the restore point.
     */
    private function snapshot_paced( $label, $budget, $only = null ) {
        global $wpdb;
        $deadline   = Pace::deadline( $budget );
        $state_file = $this->rollback_path . '/snapshot-state.php';
        $state      = json_decode( (string) self::read_protected( $state_file ), true );
        $target     = $this->target();
        if ( ! is_array( $state ) ) {
            // Asked again after it finished (a lost answer): the same answer.
            $meta = self::rollback_meta( $this->rollback_path );
            if ( $meta !== null && ! empty( $meta['sql_files'] ) ) {
                return [ 'done' => true, 'rollback_id' => $this->import_id, 'tables' => count( (array) $meta['tables'] ), 'target' => $target ? $target->info() : null ];
            }
            if ( $only !== null ) {
                // Only tables that exist, each once.
                $target = null;
                $here   = array_flip( (array) $wpdb->get_col( 'SHOW TABLES' ) );
                $all    = array_values( array_unique( array_filter( array_map( 'strval', (array) $only ), function ( $t ) use ( $here ) { return isset( $here[ $t ] ); } ) ) );
            } else {
                $all = $this->snapshot_scope( $target );
            }
            // A new subsite or tenant has no tables yet: an empty restore
            // point still takes it away again.
            if ( empty( $all ) && ! $target ) {
                return new \WP_Error( 'no_tables', 'No tables found to snapshot.', [ 'status' => 500 ] );
            }
            // A catch-up that leaves unchanged tables alone saves only the
            // others, and the full list (see Pull::plan_decide()).
            $plan   = json_decode( (string) self::read_protected( $this->import_path . '/db-plan.php' ), true );
            $skip   = array_flip( (array) ( $plan['skipped_dst'] ?? [] ) );
            $tables = array_values( array_filter( $all, function ( $t ) use ( $skip ) { return ! isset( $skip[ $t ] ); } ) );
            $state  = [ 'tables' => $tables, 'all' => array_values( $all ), 'name' => 'batch-' . md5( implode( ',', $tables ) ), 'cur' => [], 'size' => 0, 'partial' => $only !== null ];
        }
        $done = $this->write_restore_dump( $state, $state_file, $deadline );
        if ( is_wp_error( $done ) ) {
            return $done;
        }
        if ( ! $done ) {
            return [ 'done' => false, 'tables_done' => (int) ( $state['cur']['t'] ?? 0 ), 'tables_total' => count( $state['tables'] ) ];
        }
        $result = $this->snapshot_saved( $label, $state['all'] ?? $state['tables'], ! empty( $state['partial'] ) ? null : $target, $state['tables'], ! empty( $state['partial'] ) );
        if ( is_array( $result ) ) {
            $result['done'] = true;
        }
        return $result;
    }

    /**
     * Writes the restore-point dump of $state['tables'] (as $state['name'],
     * behind the exit line, under a name rollback() doesn't read until it's
     * whole), carrying on from $state and saving it to $state_file as it
     * goes. True when done, false when out of time, or WP_Error.
     */
    private function write_restore_dump( array &$state, $state_file, $deadline ) {
        $part = $this->rollback_path . '/' . $state['name'] . '.db.php.part';
        $fail = function ( $why ) use ( $part, $state_file ) {
            @unlink( $part );
            @unlink( $state_file );
            return new \WP_Error( 'snapshot_failed', 'Failed to create database snapshot. ' . $why . ' Nothing was changed.', [ 'status' => 500 ] );
        };
        $handle = @fopen( $part, 'c+b' );
        if ( ! $handle ) {
            return $fail( 'Could not write the restore point (is the disk full?).' );
        }
        if ( (int) $state['size'] === 0 ) {
            ftruncate( $handle, 0 );
            if ( fwrite( $handle, self::PHP_EXIT . "SET FOREIGN_KEY_CHECKS = 0;\nSET UNIQUE_CHECKS = 0;\n" ) === false ) {
                fclose( $handle );
                return $fail( 'Could not write the restore point (is the disk full?).' );
            }
        } else {
            // Whatever a killed call wrote after its last checkpoint goes.
            ftruncate( $handle, (int) $state['size'] );
        }
        fseek( $handle, 0, SEEK_END );
        $save = function ( $cur ) use ( $handle, &$state, $state_file ) {
            fflush( $handle );
            $state['cur']  = $cur;
            $state['size'] = ftell( $handle );
            self::write_protected( $state_file, (string) wp_json_encode( $state ) );
        };
        $save( $state['cur'] );
        $cur  = (array) $state['cur'];
        $done = Backup::export_tables_until( $handle, $cur, $state['tables'], [ 'deadline' => $deadline, 'checkpoint' => $save ] );
        if ( is_wp_error( $done ) ) {
            fclose( $handle );
            return $fail( $done->get_error_message() );
        }
        if ( ! $done ) {
            $save( $cur );
            fclose( $handle );
            return false;
        }
        $ok = fwrite( $handle, "SET FOREIGN_KEY_CHECKS = 1;\nSET UNIQUE_CHECKS = 1;\n" . Backup::EXPORT_COMPLETE_MARKER . "\n" ) !== false;
        $ok = fclose( $handle ) && $ok;
        if ( ! $ok || ! @rename( $part, $this->rollback_path . '/' . $state['name'] . '.db.php' ) ) {
            return $fail( 'Could not finish the restore point (is the disk full?).' );
        }
        @unlink( $state_file );
        return true;
    }

    /**
     * Adds $tables (as they are now) to this move's restore point, before
     * a catch-up copies again tables it had left alone. Paced like the
     * snapshot.
     */
    public function snapshot_add( $tables, $budget ) {
        $deadline   = Pace::deadline( $budget );
        $state_file = $this->rollback_path . '/snapshot-add-state.php';
        $meta       = self::rollback_meta( $this->rollback_path );
        if ( $meta === null ) {
            return new \WP_Error( 'no_rollback', 'This move has no restore point to add to.', [ 'status' => 409 ] );
        }
        $state = json_decode( (string) self::read_protected( $state_file ), true );
        if ( ! is_array( $state ) ) {
            $tables = array_values( array_diff( array_map( 'strval', (array) $tables ), (array) ( $meta['saved_tables'] ?? $meta['tables'] ) ) );
            if ( ! $tables ) {
                return [ 'done' => true ];
            }
            $state = [ 'tables' => $tables, 'name' => 'recheck-' . md5( implode( ',', $tables ) . microtime() ), 'cur' => [], 'size' => 0 ];
        }
        $done = $this->write_restore_dump( $state, $state_file, $deadline );
        if ( is_wp_error( $done ) || ! $done ) {
            return is_wp_error( $done ) ? $done : [ 'done' => false ];
        }
        $file = $this->rollback_path . '/' . $state['name'] . '.db.php';
        $meta['sql_files'][ basename( $file ) ] = filesize( $file );
        $meta['saved_tables'] = array_values( array_unique( array_merge( (array) ( $meta['saved_tables'] ?? $meta['tables'] ), $state['tables'] ) ) );
        if ( ! $this->save_rollback_meta( $meta ) ) {
            return new \WP_Error( 'snapshot_failed', 'Failed to save the rollback point.', [ 'status' => 500 ] );
        }
        return [ 'done' => true ];
    }

    /** Records a restore point whose dumps are in place, and makes a new subsite or tenant. */
    private function snapshot_saved( $label, $all_tables, $target, $saved_tables = null, $partial = false ) {
        $sql_sizes = [];
        foreach ( self::rollback_sql_files( $this->rollback_path ) as $sql_file ) {
            $sql_sizes[ basename( $sql_file ) ] = filesize( $sql_file );
        }

        // Save rollback metadata. format 2 snapshots end with the export's
        // completeness marker and record their sizes, which rollback() checks
        // before it touches anything.
        $meta = [
            'import_id'   => $this->import_id,
            'format'      => 2,
            'created_at'  => time(),
            'label'       => substr( sanitize_text_field( (string) $label ), 0, 200 ),
            'tables'      => $all_tables,
            'sql_files'   => $sql_sizes,
            // The tables the dump holds, when that isn't all of them (a
            // catch-up leaves unchanged ones alone, and so does rollback).
            'saved_tables' => $saved_tables === null ? $all_tables : array_values( $saved_tables ),
            'deployed_files' => [],
            'backed_up_files' => [],
        ];
        // Only the tables a change touches (Browse's search and replace):
        // a rollback puts those back and drops nothing.
        if ( $partial ) {
            $meta['partial'] = true;
        }
        // Known before the new site exists, so a rollback never mistakes
        // this for a whole-site restore point.
        if ( $target ) {
            $meta['target'] = [ 'kind' => $target->kind, 'id' => $target->id, 'prefix' => $target->prefix, 'created' => false ];
        }
        if ( ! $this->save_rollback_meta( $meta ) ) {
            return new \WP_Error( 'snapshot_failed', 'Failed to save the rollback point. Nothing was changed.', [ 'status' => 500 ] );
        }

        // A new subsite or tenant is made now, once the rollback point can
        // take it away again.
        if ( $target ) {
            $made = $target->create( $this->staged_source() );
            $target->save( $this->import_path );
            $meta['target'] = [ 'kind' => $target->kind, 'id' => $target->id, 'prefix' => $target->prefix, 'created' => $target->created ];
            $this->save_rollback_meta( $meta );
            if ( is_wp_error( $made ) ) {
                return $made;
            }
        }

        return [
            'rollback_id' => $this->import_id,
            'tables'      => count( $all_tables ),
            'target'      => $target ? $target->info() : null,
        ];
    }

    /**
     * Receives a ZIP of files (single POST) and extracts to staging.
     */
    public function upload_zip( $uploaded_file ) {
        if ( $refused = $this->refused() ) {
            return $refused;
        }
        $this->prepare_import_dir();
        if ( ! is_dir( $this->staging_path ) ) {
            mkdir( $this->staging_path, 0755, true );
        }

        if ( empty( $uploaded_file['tmp_name'] ) || ! is_uploaded_file( $uploaded_file['tmp_name'] ) ) {
            return new \WP_Error( 'no_file', 'No file uploaded.', [ 'status' => 400 ] );
        }

        $zip_path = $this->staging_path . '/upload-' . uniqid() . '.zip';
        move_uploaded_file( $uploaded_file['tmp_name'], $zip_path );

        $result = $this->extract_zip_file( $zip_path );
        @unlink( $zip_path );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return [
            'success'      => true,
            'files_staged' => $this->count_staged_files(),
        ];
    }

    /**
     * Extracts a ZIP that was uploaded in chunks (reassembled into staging by
     * upload_chunk) and removes it. Lets large sites avoid a single huge POST.
     */
    public function extract_staged_zip( $file_path, $budget = null ) {
        if ( ! self::is_safe_relative_path( $file_path ) ) {
            return new \WP_Error( 'invalid_path', 'Invalid file path.', [ 'status' => 400 ] );
        }
        $zip_path = $this->staging_path . '/' . $file_path;
        $state_f  = $this->import_path . '/extract-state.php';
        if ( ! file_exists( $zip_path ) ) {
            // Asked again after it finished (a lost answer).
            if ( $budget !== null && ! is_file( $state_f ) && is_dir( $this->staging_path ) && $this->count_staged_files() > 0 ) {
                return [ 'success' => true, 'done' => true, 'files_staged' => $this->count_staged_files() ];
            }
            return new \WP_Error( 'not_found', 'Staged ZIP not found.', [ 'status' => 404 ] );
        }
        if ( $budget !== null ) {
            // A piece at a time (see Pace): entries until the budget, then
            // the next call starts at the entry after.
            $state  = json_decode( (string) self::read_protected( $state_f ), true );
            $from   = is_array( $state ) && ( $state['zip'] ?? '' ) === $file_path ? (int) $state['next'] : 0;
            $result = self::extract_zip_into( $zip_path, $this->staging_path, [ 'from' => $from, 'deadline' => Pace::deadline( $budget ), 'checkpoint' => function ( $next ) use ( $state_f, $file_path ) {
                self::write_protected( $state_f, (string) wp_json_encode( [ 'zip' => $file_path, 'next' => $next ] ) );
            } ] );
            if ( is_array( $result ) ) {
                self::write_protected( $state_f, (string) wp_json_encode( [ 'zip' => $file_path, 'next' => $result['next'] ] ) );
                return [ 'success' => true, 'done' => false, 'entries_done' => $result['next'], 'entries_total' => $result['total'] ];
            }
            @unlink( $state_f );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            @unlink( $zip_path );
            return [ 'success' => true, 'done' => true, 'files_staged' => $this->count_staged_files() ];
        }
        $result = $this->extract_zip_file( $zip_path );
        @unlink( $zip_path );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return [
            'success'      => true,
            'files_staged' => $this->count_staged_files(),
        ];
    }

    /**
     * Extracts a ZIP into the staging directory (see extract_zip_into()).
     */
    private function extract_zip_file( $zip_path ) {
        return self::extract_zip_into( $zip_path, $this->staging_path );
    }

    /**
     * Extracts a ZIP into a staging folder ($root), skipping any zip-slip
     * entry names, with files that could run under a permissive web server
     * saved under their staged names (see staged_name()) so none of them is
     * ever a runnable file before deploy_files() puts it in place. Uses
     * ZipArchive, falling back to PclZip. Shared with Pull. Returns true or
     * WP_Error.
     */
    public static function extract_zip_into( $zip_path, $root, $opts = [] ) {
        $from     = (int) ( $opts['from'] ?? 0 );
        $deadline = (float) ( $opts['deadline'] ?? 0 );
        $strict   = ! empty( $opts['strict'] ) || $deadline > 0;
        $zip = class_exists( 'ZipArchive' ) ? new \ZipArchive() : null;
        if ( ! $zip || $zip->open( $zip_path ) !== true ) {
            if ( ! class_exists( 'PclZip' ) ) {
                require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
            }
            return self::extract_with_pclzip( $zip_path, $root );
        }
        // Entry by entry, skipping any name that could escape staging
        // (zip-slip); extractTo() on the whole archive would honour "../".
        // With a deadline (see Pace) it stops between entries once that has
        // passed and says where to start next time.
        $failed = [];
        $total  = $zip->numFiles;
        $saved  = microtime( true );
        for ( $i = $from; $i < $total; $i++ ) {
            if ( $deadline > 0 && $i > $from && microtime( true ) >= $deadline ) {
                $zip->close();
                return [ 'next' => $i, 'total' => $total ];
            }
            // Now and then, where a killed request got to.
            if ( ! empty( $opts['checkpoint'] ) && microtime( true ) - $saved >= 1 ) {
                $saved = microtime( true );
                call_user_func( $opts['checkpoint'], $i );
            }
            $name = $zip->getNameIndex( $i );
            if ( $name === false || ! self::is_safe_relative_path( $name ) ) {
                continue;
            }
            Pace::tick();
            if ( substr( $name, -1 ) === '/' || self::staged_name( $name ) === $name ) {
                if ( ! $zip->extractTo( $root, $name ) ) {
                    $failed[] = $name;
                }
                continue;
            }
            $dest = rtrim( $root, '/' ) . '/' . self::staged_name( $name );
            if ( ! is_dir( dirname( $dest ) ) ) {
                wp_mkdir_p( dirname( $dest ) );
            }
            // Written under a temp name of its own and renamed when whole
            // (see copy_protected()).
            $tmp = $dest . '.' . bin2hex( random_bytes( 6 ) ) . '.tmp';
            $in  = $zip->getStream( $name );
            $out = $in ? @fopen( $tmp, 'wb' ) : false;
            $ok  = $in && $out && fwrite( $out, self::PHP_EXIT ) !== false && stream_copy_to_stream( $in, $out ) !== false;
            if ( $in ) {
                fclose( $in );
            }
            if ( $out ) {
                $ok = fclose( $out ) && $ok;
            }
            if ( ! $ok || ! @rename( $tmp, $dest ) ) {
                @unlink( $tmp );
                $failed[] = $name;
            }
        }
        $zip->close();
        if ( $failed && $strict ) {
            return new \WP_Error( 'extract_failed', 'Could not extract ' . count( $failed ) . ' files from the ZIP (first: ' . $failed[0] . '). Is the disk full?', [ 'status' => 500 ] );
        }
        return true;
    }

    /**
     * The PclZip side of extract_zip_into(), for servers without ZipArchive.
     * PclZip writes each entry straight to its path, and the exit line could
     * only go in front afterwards: in between, a staged x.php sat under
     * uploads holding the source's code, and a request in that moment ran
     * it. So files that wait under a staged name (runnable ones, database
     * dumps) are extracted into a folder of this request's own in the
     * system temp folder, outside the site, and each goes into staging
     * behind the exit line under a temp name, renamed when whole (see
     * copy_protected()). Everything else is extracted in place as before:
     * it's served as itself once deployed anyway. Zip-slip entries are
     * skipped either way.
     */
    private static function extract_with_pclzip( $zip_path, $root ) {
        if ( ! class_exists( 'PclZip' ) ) {
            require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
        }
        $pcl  = new \PclZip( $zip_path );
        $list = $pcl->listContent();
        if ( ! is_array( $list ) ) {
            return new \WP_Error( 'extract_failed', 'Failed to extract ZIP: ' . $pcl->errorInfo( true ), [ 'status' => 500 ] );
        }
        $plain = $staged = [];
        foreach ( $list as $entry ) {
            $name = (string) ( $entry['stored_filename'] ?? '' );
            if ( ! self::is_safe_relative_path( $name ) ) {
                continue;
            }
            if ( empty( $entry['folder'] ) && self::staged_name( $name ) !== $name ) {
                $staged[] = (int) $entry['index'];
            } else {
                $plain[] = (int) $entry['index'];
            }
        }
        if ( $plain && $pcl->extract( PCLZIP_OPT_PATH, $root, PCLZIP_OPT_BY_INDEX, implode( ',', $plain ), PCLZIP_CB_PRE_EXTRACT, 'Disembark\\pclzip_pre_extract_guard' ) == 0 ) {
            return new \WP_Error( 'extract_failed', 'Failed to extract ZIP: ' . $pcl->errorInfo( true ), [ 'status' => 500 ] );
        }
        if ( ! $staged ) {
            return true;
        }
        $work = rtrim( get_temp_dir(), '/' ) . '/disembark-' . bin2hex( random_bytes( 16 ) );
        if ( ! wp_mkdir_p( $work ) ) {
            return new \WP_Error( 'extract_failed', 'Could not make a temporary folder to extract the ZIP into.', [ 'status' => 500 ] );
        }
        @chmod( $work, 0700 );
        $failed = [];
        $out    = $pcl->extract( PCLZIP_OPT_PATH, $work, PCLZIP_OPT_BY_INDEX, implode( ',', $staged ), PCLZIP_CB_PRE_EXTRACT, 'Disembark\\pclzip_pre_extract_guard' );
        if ( $out == 0 ) {
            $failed[] = $pcl->errorInfo( true );
        } else {
            foreach ( $out as $entry ) {
                $name = (string) ( $entry['stored_filename'] ?? '' );
                $from = $work . '/' . $name;
                if ( ! self::is_safe_relative_path( $name ) || ! is_file( $from ) || is_link( $from ) ) {
                    continue;
                }
                $dest = rtrim( $root, '/' ) . '/' . self::staged_name( $name );
                if ( ( ! is_dir( dirname( $dest ) ) && ! wp_mkdir_p( dirname( $dest ) ) ) || ! self::copy_protected( $from, $dest ) ) {
                    $failed[] = $name;
                }
                @unlink( $from );
            }
        }
        self::delete_directory_recursive( $work );
        if ( $failed ) {
            return new \WP_Error( 'extract_failed', 'Could not extract ' . count( $failed ) . ' files from the ZIP (first: ' . $failed[0] . '). Is the disk full?', [ 'status' => 500 ] );
        }
        return true;
    }

    /* ------------------------------------------------------------------
     * Staged names
     *
     * Staging sits under the public uploads folder until deploy. On a host
     * that runs PHP there, a staged x.php from the source could be requested
     * and run before the checks were seen. So such files (and server config
     * files that change what runs: .htaccess, .user.ini) wait under another
     * name, with the exit line in front of their contents: every "." and "%"
     * of the file name percent-encoded, plus ".dsk.php" (wp-config.php →
     * wp-config%2Ephp.dsk.php). Requested, it either runs and stops at once
     * or isn't served; it is never the source's code, and never a .htaccess.
     * Apache maps every extension of a name (x.php.jpg runs as PHP), hence
     * the encoding rather than a suffix alone. Every name that ends in
     * .dsk.php is itself encoded, so the mapping is exact.
     *
     * A staged database dump waits as <name>.sql.php, behind the same exit
     * line: a full copy of a database (password hashes, the source's
     * token) at a URL. That's every .sql and .sql.gz file, wherever it is:
     * Disembark's own database-<name>.sql, and the dumps other hosts and
     * tools put in their zips (Kinsta's <site>.sql at the zip's root, WP
     * Engine's wp-content/mysql.sql, Duplicator's database.sql). The import
     * reads past the exit line, and one that is put in place as a file
     * gets its real bytes back (see take_staged()). A file that really is
     * called x.sql.php is runnable, so it's staged as x%2Esql%2Ephp.dsk.php
     * and the mapping stays exact.
     * ---------------------------------------------------------------- */

    const STAGED_SUFFIX = '.dsk.php';

    /** Whether $base (a file name) is a database dump, staged behind the exit line. */
    private static function dump_name( $base ) {
        return (bool) preg_match( '/\.sql(?:\.gz)?$/i', (string) $base );
    }

    /** Whether the staged path $path is a dump under its staged name (x.sql.php, x.sql.gz.php). */
    private static function dump_staged( $path ) {
        $path = (string) $path;
        return substr( $path, -4 ) === '.php' && self::dump_name( basename( substr( $path, 0, -4 ) ) );
    }

    /** Whether a file named $name could run, or change what runs, on a web server. */
    private static function runnable_name( $name ) {
        return (bool) preg_match( '/\.(?:php\d*|phtml|pht|phar|phps|shtml?|cgi|pl|asp|aspx|jsp)(?:\.|$)|^(?:\.htaccess|\.user\.ini|php\.ini|web\.config)$/i', $name );
    }

    /** The name $rel (a path; only its last part changes) is staged under. */
    public static function staged_name( $rel ) {
        $rel  = (string) $rel;
        $cut  = strrpos( $rel, '/' );
        $dir  = $cut === false ? '' : substr( $rel, 0, $cut + 1 );
        $base = $cut === false ? $rel : substr( $rel, $cut + 1 );
        if ( self::dump_name( $base ) ) {
            return $rel . '.php';
        }
        if ( $base === '' || ( ! self::runnable_name( $base ) && substr( $base, -strlen( self::STAGED_SUFFIX ) ) !== self::STAGED_SUFFIX ) ) {
            return $rel;
        }
        return $dir . strtr( $base, [ '%' => '%25', '.' => '%2E' ] ) . self::STAGED_SUFFIX;
    }

    /** The real name of a staged path (the reverse of staged_name()). */
    public static function unstaged_name( $rel ) {
        $rel = (string) $rel;
        if ( substr( $rel, -4 ) === '.php' && self::dump_name( basename( substr( $rel, 0, -4 ) ) ) ) {
            return substr( $rel, 0, -4 );
        }
        if ( substr( $rel, -strlen( self::STAGED_SUFFIX ) ) !== self::STAGED_SUFFIX ) {
            return $rel;
        }
        $rel  = substr( $rel, 0, -strlen( self::STAGED_SUFFIX ) );
        $cut  = strrpos( $rel, '/' );
        $dir  = $cut === false ? '' : substr( $rel, 0, $cut + 1 );
        $base = $cut === false ? $rel : substr( $rel, $cut + 1 );
        return $dir . strtr( $base, [ '%2E' => '.', '%25' => '%' ] );
    }

    /**
     * Moves the staged file $from to $to as the real file: without the exit
     * line staged_name() put in front (a file staged by an older build has
     * none and moves as it is).
     */
    private static function take_staged( $from, $to ) {
        $in = @fopen( $from, 'rb' );
        if ( ! $in ) {
            return false;
        }
        $head = (string) fread( $in, strlen( self::PHP_EXIT ) );
        if ( ( substr( $from, -strlen( self::STAGED_SUFFIX ) ) !== self::STAGED_SUFFIX && ! self::dump_staged( $from ) ) || $head !== self::PHP_EXIT ) {
            fclose( $in );
            if ( @rename( $from, $to ) ) {
                return true;
            }
            if ( copy( $from, $to ) ) {
                @unlink( $from );
                return true;
            }
            return false;
        }
        $tmp = $to . '.dsk-tmp';
        $out = @fopen( $tmp, 'wb' );
        $ok  = $out && stream_copy_to_stream( $in, $out ) !== false;
        fclose( $in );
        if ( $out ) {
            $ok = fclose( $out ) && $ok;
        }
        if ( ! $ok || ! @rename( $tmp, $to ) ) {
            @unlink( $tmp );
            return false;
        }
        @unlink( $from );
        return true;
    }

    /** Where staged $rel is under $root: its staged name, or (staged by an older build) its own. */
    private static function staged_file( $root, $rel ) {
        $staged = $root . '/' . self::staged_name( $rel );
        return file_exists( $staged ) || ! file_exists( $root . '/' . $rel ) ? $staged : $root . '/' . $rel;
    }

    /* ------------------------------------------------------------------
     * The files a restore point keeps
     *
     * Before a move replaces or removes a file, the restore point keeps the
     * original under rollback/files. Every one of them waits like a staged
     * runnable file, whatever it is: the name encoded plus .dsk.php, the
     * exit line in front. A .env, a private download, a log or a dump that
     * the site's own server rules protect where it lives would otherwise
     * be served from here to anyone with the move's id, and a .php copy
     * would run. A rollback takes the exit line off again, so the file
     * comes back byte for byte.
     * ---------------------------------------------------------------- */

    /** The name a restore point keeps the original of $rel under (only its last part changes). */
    public static function rollback_name( $rel ) {
        $rel  = (string) $rel;
        $cut  = strrpos( $rel, '/' );
        $dir  = $cut === false ? '' : substr( $rel, 0, $cut + 1 );
        $base = $cut === false ? $rel : substr( $rel, $cut + 1 );
        $name = strtr( $base, [ '%' => '%25', '.' => '%2E' ] ) . self::STAGED_SUFFIX;
        // Too long for the disk encoded: a hash ("%d" never comes out of the encoding).
        if ( strlen( $name ) > 255 ) {
            $name = '%dsk-' . md5( $base ) . self::STAGED_SUFFIX;
        }
        return $dir . $name;
    }

    /** Where this restore point keeps the original of $rel: its rollback name, or where an older build kept it. */
    private function rollback_file( $rel ) {
        $files = $this->rollback_path . '/files';
        $saved = $files . '/' . self::rollback_name( $rel );
        return file_exists( $saved ) ? $saved : self::staged_file( $files, $rel );
    }

    /** Keeps the original of $rel ($file) in this restore point. True when every byte is there. */
    private function save_original( $file, $rel ) {
        $to = $this->rollback_path . '/files/' . self::rollback_name( $rel );
        if ( ! is_dir( dirname( $to ) ) && ! wp_mkdir_p( dirname( $to ) ) ) {
            return false;
        }
        return self::copy_protected( $file, $to );
    }

    /**
     * Lists deployable staged files (archive-relative), skipping Disembark's own
     * metadata, the SQL dump, and chunk-reassembly leftovers.
     */
    /**
     * Staged paths that must never reach the web root: database dumps (a full
     * database at a guessable URL) and Disembark's own working files. Covers
     * Disembark's dumps anywhere (database-*.sql) and any .sql at the site
     * root, such as a sync mirror's database.sql or a db_export.sql.
     */
    private static function never_deploy( $rel ) {
        $rel  = self::normalize_rel( $rel );
        $base = basename( $rel );
        if ( $rel === 'disembark-source.json' || $rel === 'restore.zip' ) {
            return true;
        }
        if ( preg_match( '#^database-.*\.sql$#i', $base ) ) {
            return true;
        }
        // Database exports (Database tab, backups) and anything in a
        // Disembark folder: restore points, staged moves, token guards.
        // Brought along, a source could plant its own token guard here.
        if ( preg_match( '#\.(?:db|sql)\.txt$#i', $base ) || self::in_storage( $rel ) ) {
            return true;
        }
        if ( strpos( $rel, '/' ) === false && preg_match( '#\.sql(?:\.gz)?$#i', $rel ) ) {
            return true;
        }
        // Where hosts keep the site's dump inside wp-content (WP Engine's
        // mysql.sql, Pressidium's mysqldb-dump.sql.gz): never served here.
        if ( preg_match( '#^wp-content/(?:mysql\.sql|mysqldb-dump\.sql(?:\.gz)?)$#i', $rel ) ) {
            return true;
        }
        // A quiet mode file belongs to the site it was turned on for: never
        // carry one from a backup onto another site (it would silence it).
        if ( $base === Quiet::FILE && strpos( $rel, 'mu-plugins/' ) !== false ) {
            return true;
        }
        foreach ( [ '_chunks/', '.disembark-db-parts/', '.disembark-manifests/' ] as $dir ) {
            if ( strpos( $rel, $dir ) === 0 ) {
                return true;
            }
        }
        // Never overwrite the Disembark that is running this restore. The
        // backup carries the source's copy (often another version), and every
        // step after the file deploy (database import, URL rewrite, finalize)
        // would otherwise run the source's code. The destination keeps its own.
        // Compared case-insensitively: on a case-insensitive disk
        // wp-content/Plugins/Disembark/ is this same folder.
        $own = strtolower( self::own_plugin_dir() );
        $low = strtolower( $rel );
        if ( $own !== '' && ( $low === rtrim( $own, '/' ) || strpos( $low, $own ) === 0 ) ) {
            return true;
        }
        return false;
    }

    /**
     * A relative path the way the disk resolves it: forward slashes, no
     * repeated slashes, no "." segments. "wp-content//plugins/x" and
     * "wp-content/./plugins/x" are wp-content/plugins/x on disk, so every
     * check that compares prefixes compares this.
     */
    private static function normalize_rel( $rel ) {
        $parts = array_filter( explode( '/', str_replace( '\\', '/', (string) $rel ) ), function ( $p ) { return $p !== '' && $p !== '.'; } );
        return implode( '/', $parts );
    }

    /**
     * Whether $rel (relative to a site root) is inside a Disembark working
     * folder: this site's (Storage), or the usual places on the source
     * (uploads/disembark, a subsite's uploads/sites/N/disembark, a tenant's
     * content/N/uploads/disembark). Case-insensitive, for case-insensitive
     * disks. Used for staged paths and for where Target puts them.
     */
    public static function in_storage( $rel ) {
        $rel = strtolower( trim( (string) preg_replace( '#/+#', '/', str_replace( '\\', '/', (string) $rel ) ), '/' ) );
        $own = strtolower( trim( Storage::relative_base_dir(), '/' ) );
        if ( $own !== '' && ( $rel === $own || strpos( $rel, $own . '/' ) === 0 ) ) {
            return true;
        }
        return (bool) preg_match( '#(?:^|/)uploads/(?:sites/\d+/)?disembark(?:/|$)#', $rel );
    }

    /** Whether $rel is inside the Disembark running this import. */
    private static function own_plugin_path( $rel ) {
        $own = strtolower( self::own_plugin_dir() );
        $low = strtolower( self::normalize_rel( $rel ) );
        return $own !== '' && ( $low === rtrim( $own, '/' ) || strpos( $low, $own ) === 0 );
    }

    /** This plugin's folder relative to the site root ("wp-content/plugins/disembark/"), or ''. */
    private static function own_plugin_dir() {
        static $dir = null;
        if ( $dir === null ) {
            $web_root = rtrim( wp_normalize_path( dirname( WP_CONTENT_DIR ) ), '/' ) . '/';
            $plugin   = rtrim( wp_normalize_path( dirname( __DIR__ ) ), '/' ) . '/';
            $dir      = strpos( $plugin, $web_root ) === 0 ? substr( $plugin, strlen( $web_root ) ) : '';
        }
        return $dir;
    }

    private function list_staged_files( $root ) {
        $files = [];
        if ( ! is_dir( $root ) ) {
            return $files;
        }
        // The dump this restore imports, wherever it is in the zip (a deep
        // one, picked by hand, would otherwise be put in place as a file).
        $choice = json_decode( (string) self::read_protected( $this->import_path . '/db-choice.php' ), true );
        $dump   = is_array( $choice ) && ! empty( $choice['file'] ) && self::is_safe_relative_path( $choice['file'] ) ? $this->staging_path . '/' . $choice['file'] : '';
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $root, \RecursiveDirectoryIterator::SKIP_DOTS )
        );
        foreach ( $it as $file ) {
            if ( ! $file->isFile() ) {
                continue;
            }
            $rel = self::unstaged_name( ltrim( str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) ) ), '/' ) );
            if ( self::never_deploy( $rel ) || ( $dump !== '' && $root . '/' . $rel === $dump ) ) {
                continue;
            }
            $files[] = $rel;
        }
        return $files;
    }

    /**
     * Imports a SQL dump already sitting in staging (dashboard flow — the client
     * never holds the SQL). Applies the same identifier prefix remap and
     * DROP-before-CREATE the CLI does, then runs it.
     *
     * With a $budget (seconds, see Pace), it works until the budget runs out
     * and answers { done: false }; the next call carries on from the byte
     * offset it stopped at (always a statement boundary). The tables are
     * still built as copies and swapped in once, by the call that reaches
     * the end of the dump. Without one, the whole dump runs in this request.
     */
    public function import_staged_sql( $sql_file, $old_prefix = '', $new_prefix = '', $budget = null, $merge = false ) {
        if ( $refused = $this->refused() ) {
            return $refused;
        }
        if ( ! self::is_safe_relative_path( $sql_file ) ) {
            return new \WP_Error( 'invalid_path', 'Invalid SQL file path.', [ 'status' => 400 ] );
        }
        // Under its staged name (database-x.sql.php, behind the exit line)
        // or, staged by an older build, its own.
        $path = self::staged_file( $this->staging_path, $sql_file );
        if ( ! file_exists( $path ) ) {
            return new \WP_Error( 'not_found', 'Staged SQL file not found.', [ 'status' => 404 ] );
        }
        // Streamed statement by statement: reading the whole dump (and every
        // split statement) into memory needs a multiple of its size and runs
        // out of memory on large sites.
        // Tables whose rows a pull left behind ("What to bring"). The source
        // normally sends them structure-only; an older source sends the rows,
        // and they're dropped here.
        $skip_rows = [];
        $skip_file = $this->import_path . '/skip-rows.json';
        if ( is_file( $skip_file ) ) {
            $skip_rows = array_flip( array_map( 'strtolower', (array) json_decode( (string) file_get_contents( $skip_file ), true ) ) );
        }
        // Into a subsite or tenant: its prefix, and none of the install's
        // other tables can be touched (a table without the source's prefix
        // is left out).
        $target = $this->target();
        if ( $target ) {
            if ( $old_prefix === '' || $target->prefix === '' ) {
                return new \WP_Error( 'no_prefix', 'This backup doesn\'t record its table prefix, so it can\'t go into a subsite or tenant.', [ 'status' => 400 ] );
            }
            if ( ! preg_match( '/^[A-Za-z0-9_]+$/', (string) $old_prefix ) ) {
                return new \WP_Error( 'bad_prefix', 'This backup\'s table prefix isn\'t a plain table prefix, so it can\'t go into a subsite or tenant.', [ 'status' => 400 ] );
            }
            $new_prefix = $target->prefix;
        }
        // The main site of a network or WP Freighter install: its own tables
        // only. The incoming site lands under this site's prefix, and a table
        // that belongs to another site of the install (a subsite's wp_5_*, the
        // network's blogs and sitemeta, a tenant's stacked_8_*) is left out.
        // On a network the incoming users are imported beside the network's
        // and merged into them afterwards (see Target::merge_users()), so
        // the network keeps its users, its super admins and every subsite's
        // members.
        global $wpdb;
        $shared_main = ! $target && Scope::install();
        $in_users    = [];
        if ( $shared_main ) {
            $new_prefix = $wpdb->prefix;
            if ( is_multisite() ) {
                $in_users = [ $new_prefix . 'users' => $wpdb->base_prefix . self::INCOMING_USERS . 'users', $new_prefix . 'usermeta' => $wpdb->base_prefix . self::INCOMING_USERS . 'usermeta' ];
            }
        }

        // Where an earlier call of a paced import stopped (see Pace).
        $paced        = $budget !== null;
        $deadline     = $paced ? Pace::deadline( $budget ) : 0;
        $state_file   = $this->import_path . '/sql-state.php';
        $journal_file = $this->import_path . '/sql-journal.php';
        $saved        = $paced ? json_decode( (string) Import::read_protected( $state_file ), true ) : null;
        if ( ! is_array( $saved ) || ( $saved['file'] ?? '' ) !== $sql_file || (int) ( $saved['size'] ?? -1 ) !== (int) filesize( $path ) ) {
            $saved = null;
            @unlink( $state_file );
            @unlink( $journal_file );
        }
        $start = $saved ? (int) $saved['offset'] : 0;
        // A call that was killed got past its last checkpoint: the journal
        // says how far. Statements up to there are read again only to
        // rebuild what this import keeps track of, not run again.
        $replay_to = 0;
        $last      = $saved ? Pace::journal_last( $journal_file ) : null;
        if ( $last && (int) ( $last['o'] ?? 0 ) > $start ) {
            $replay_to = (int) $last['o'];
        }

        $foreign  = $saved ? (int) $saved['foreign'] : 0;
        $refused  = $saved ? (int) $saved['refused'] : 0;
        $run      = $this->begin_statements();
        $count    = $saved ? (int) $saved['count'] : 0;
        $swap     = $saved ? $saved['swap'] : self::swap_start();
        $compat   = self::db_compat();
        if ( $saved ) {
            $compat = array_merge( $compat, array_intersect_key( (array) $saved['compat'], array_flip( [ 'converted', 'definers_removed', 'map' ] ) ) );
            $run['statements_executed'] = (int) $saved['executed'];
            $run['warnings']            = (array) ( $saved['warnings'] ?? [] );
        }
        $expected = $saved ? (array) $saved['expected'] : [];
        $imported = $saved ? (array) $saved['imported'] : [];
        if ( $paced && ! $saved ) {
            // A first call the host kills still leaves its journal to go on from.
            $this->prepare_import_dir();
            Import::write_protected( $state_file, (string) wp_json_encode( [
                'file' => $sql_file, 'size' => filesize( $path ), 'offset' => 0, 'count' => 0, 'executed' => 0, 'warnings' => [],
                'foreign' => 0, 'refused' => 0, 'swap' => $swap, 'compat' => array_intersect_key( $compat, array_flip( [ 'converted', 'definers_removed', 'map' ] ) ),
                'expected' => [], 'imported' => [],
            ] ) );
        }
        $journal  = $paced ? Pace::journal_open( $journal_file ) : null;
        $stop_at  = 0;
        // The statement delimiter where it stops (a DELIMITER line can change it).
        $stop_delim = $saved ? (string) ( $saved['delim'] ?? ';' ) : ';';
        $tolerant = $replay_to > 0;
        // Rows go in many at a time: a dump has one INSERT per row, and one
        // round trip per row is most of an import's time. Consecutive
        // inserts into the same table become one statement (up to 1 MB,
        // under the server's max_allowed_packet), run before anything else.
        global $wpdb;
        $packet  = (int) $wpdb->get_var( 'SELECT @@max_allowed_packet' );
        $max     = (int) max( 65536, min( 1048576, $packet > 0 ? $packet / 2 : 1048576 ) );
        $pending = null;
        $exec    = function ( $sql ) use ( &$run, &$tolerant, $paced ) {
            $before = count( $run['errors'] );
            if ( ! $this->run_statement( $sql, $run ) ) {
                return false;
            }
            // The statement a killed call may have run just before it died
            // (it couldn't record it): rows or a table already there are fine.
            if ( $tolerant && count( $run['errors'] ) > $before && preg_match( '/Duplicate entry|already exists/i', (string) $run['errors'][ $before ] ) ) {
                array_splice( $run['errors'], $before );
            }
            $tolerant = false;
            // Paced, the first error ends the import: the copies go and
            // nothing here changes (going on would only spend the budget).
            return ! ( $paced && $run['errors'] );
        };
        $flush = function () use ( &$pending, $exec, $journal, $paced ) {
            if ( ! $pending ) {
                return true;
            }
            $batch   = $pending;
            $pending = null;
            if ( ! $exec( $batch['head'] . implode( ',', $batch['rows'] ) ) ) {
                return false;
            }
            if ( $paced ) {
                Pace::journal_add( $journal, [ 'o' => $batch['end'] ] );
            }
            return true;
        };
        $read     = self::each_sql_statement_in_file( $path, function ( $statement, $end, $delim = ';' ) use ( &$run, &$count, &$swap, &$compat, &$expected, &$foreign, &$refused, &$imported, &$stop_at, &$stop_delim, &$pending, $exec, $flush, $max, $old_prefix, $new_prefix, $skip_rows, $target, $paced, $deadline, $replay_to, $journal, $shared_main, $in_users ) {
            $dry = $end <= $replay_to;
            if ( ++$count % 500 === 0 && ! $dry && $this->cancelled() ) {
                $run['errors'][] = 'Cancelled.';
                return false;
            }
            // The source's row count for the table just exported (see
            // Backup::database_export_batch()), checked after the swap.
            if ( preg_match( "/^SET\\s+@disembark_rows\\s*=\\s*'(.+):(\\d+)'\\s*$/s", trim( $statement ), $m ) ) {
                $table = stripslashes( $m[1] );
                if ( $target && strpos( $table, $old_prefix ) !== 0 ) {
                    return true; // a table left out (not the source's)
                }
                if ( $old_prefix !== '' && $new_prefix !== '' && strpos( $table, $old_prefix ) === 0 ) {
                    $table = $new_prefix . substr( $table, strlen( $old_prefix ) );
                }
                $table = $in_users[ $table ] ?? $table;
                if ( $shared_main && self::other_sites_table( $table ) ) {
                    return true;
                }
                if ( ! isset( $skip_rows[ strtolower( $m[1] ) ] ) ) {
                    $expected[ $table ] = (int) $m[2];
                }
                return true;
            }
            if ( $skip_rows && preg_match( '/^\s*INSERT\s+INTO\s+`([^`]+)`/i', $statement, $m ) && isset( $skip_rows[ strtolower( $m[1] ) ] ) ) {
                return true;
            }
            // Into a subsite or tenant, only statements that create, fill or
            // drop one of the source's own tables run (see target_statement()).
            if ( $target ) {
                $verdict = self::target_statement( $statement, $old_prefix );
                // One that would be refused anyway, for a reason worth
                // stopping for (see below), stops the import instead.
                if ( $verdict === 'refused' && ! $dry && ( $problem = self::reach_problem( $statement, (string) $old_prefix ) ) ) {
                    $run['errors'][] = $problem;
                    return false;
                }
                if ( $verdict === 'foreign_create' ) {
                    $foreign++;
                } elseif ( $verdict === 'refused' ) {
                    $refused++;
                }
                // 'skip': mysqldump's own session bookkeeping, left out quietly.
                if ( $verdict !== 'ok' ) {
                    return true;
                }
            }
            // mysqldump puts each session setting back from a variable it
            // saved a moment before (SET character_set_client =
            // @saved_cs_client). A paced import runs in many requests, each
            // its own database session, so the variable can be gone (NULL)
            // and the line fails. The import sets the session itself, so
            // these lines are left out.
            if ( strlen( $statement ) < 400 && self::restores_saved_setting( $statement ) ) {
                return true;
            }
            // A storage engine or foreign key that reaches outside this
            // site's tables ends the import before the statement runs: the
            // copies go and the live tables stay (see reach_problem()).
            if ( ! $dry && ( $problem = self::reach_problem( $statement, (string) $old_prefix, $shared_main, (string) $old_prefix, (string) $new_prefix ) ) ) {
                $run['errors'][] = $problem;
                return false;
            }
            $transformed_statements = self::transform_statements( [ $statement ], $old_prefix, $new_prefix );
            if ( $shared_main ) {
                $kept = [];
                foreach ( $transformed_statements as $t ) {
                    $t    = self::rename_statement_table( $t, $in_users );
                    $name = self::statement_table( $t );
                    if ( $name !== null && self::other_sites_table( $name ) ) {
                        if ( preg_match( '/^(?:\/\*!\d+\s+)?CREATE\s/i', $t ) ) {
                            $foreign++;
                        }
                        continue;
                    }
                    $kept[] = $t;
                }
                $transformed_statements = $kept;
            }
            foreach ( $transformed_statements as $t ) {
                if ( preg_match( '/^CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`((?:[^`]|``)+)`/i', $t, $tm ) ) {
                    $imported[ str_replace( '``', '`', $tm[1] ) ] = true;
                }
            }
            foreach ( self::swap_statements( $transformed_statements, $swap ) as $transformed ) {
                $sql = self::compat_statement( $transformed, $compat );
                if ( $dry ) {
                    continue;
                }
                if ( strlen( $sql ) < $max && preg_match( '/^(INSERT\s+INTO\s+`(?:[^`]|``)+`\s+VALUES\s*)(\(.*\))\s*$/s', $sql, $im ) ) {
                    if ( $pending && ( $pending['head'] !== $im[1] || $pending['bytes'] + strlen( $im[2] ) >= $max || count( $pending['rows'] ) >= 2000 ) && ! $flush() ) {
                        return false;
                    }
                    if ( ! $pending ) {
                        $pending = [ 'head' => $im[1], 'rows' => [], 'bytes' => strlen( $im[1] ) ];
                    }
                    $pending['rows'][] = $im[2];
                    $pending['bytes'] += strlen( $im[2] ) + 1;
                    $pending['end']    = $end;
                    continue;
                }
                if ( ! $flush() || ! $exec( $sql ) ) {
                    return false;
                }
            }
            if ( $dry ) {
                return true;
            }
            if ( $paced ) {
                if ( ! $pending ) {
                    Pace::journal_add( $journal, [ 'o' => $end ] );
                }
                if ( microtime( true ) >= $deadline && empty( $run['errors'] ) ) {
                    if ( ! $flush() ) {
                        return false;
                    }
                    $stop_at    = $end;
                    $stop_delim = $delim;
                    return false;
                }
            }
            return true;
        }, $start, $stop_delim );
        // The last rows of the dump.
        if ( ! is_wp_error( $read ) && empty( $run['errors'] ) ) {
            $flush();
        }
        if ( $journal ) {
            fclose( $journal );
        }
        if ( is_wp_error( $read ) ) {
            $run['errors'][] = $read->get_error_message();
        }

        // Out of time: keep everything for the next call.
        if ( $stop_at > 0 && empty( $run['errors'] ) ) {
            $this->prepare_import_dir();
            Import::write_protected( $state_file, (string) wp_json_encode( [
                'file'     => $sql_file,
                'size'     => filesize( $path ),
                'offset'   => $stop_at,
                'delim'    => $stop_delim,
                'count'    => $count,
                'executed' => $run['statements_executed'],
                'warnings' => $run['warnings'] ?? [],
                'foreign'  => $foreign,
                'refused'  => $refused,
                'swap'     => $swap,
                'compat'   => array_intersect_key( $compat, array_flip( [ 'converted', 'definers_removed', 'map' ] ) ),
                'expected' => $expected,
                'imported' => $imported,
            ] ) );
            // Everything in the journal is in the checkpoint now.
            @unlink( $journal_file );
            global $wpdb;
            $wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' );
            $wpdb->query( 'SET UNIQUE_CHECKS = 1' );
            return [
                'done'                => false,
                'bytes_done'          => $stop_at,
                'bytes_total'         => self::dump_bytes( $path ),
                'statements_executed' => $run['statements_executed'],
                'errors'              => [],
            ];
        }
        @unlink( $state_file );
        @unlink( $journal_file );

        // Only a clean import replaces this site's tables. Otherwise the copies
        // go and the live tables stay exactly as they were.
        if ( empty( $run['errors'] ) ) {
            $swapped = self::swap_finish( $swap, $run );
            foreach ( $swap['deferred'] as $statement ) {
                $this->run_statement( self::compat_statement( $statement, $compat ), $run );
            }
        } else {
            self::swap_abandon( $swap );
            $swapped = 0;
        }
        // The address rewrite after the move covers these and this site's own.
        $this->prepare_import_dir();
        // $merge: a second import in the same move (a catch-up copying again
        // tables that changed meanwhile) adds to the first one's lists.
        $names = array_keys( $imported );
        if ( $merge && is_file( $this->import_path . '/imported-tables.json' ) ) {
            $names = array_values( array_unique( array_merge( (array) json_decode( (string) file_get_contents( $this->import_path . '/imported-tables.json' ), true ), $names ) ) );
        }
        file_put_contents( $this->import_path . '/imported-tables.json', wp_json_encode( $names ) );
        // Rows are counted right after the swap, before this site's own
        // token is written back into the options table.
        // A dump from another tool records no counts: nothing to compare.
        $rows   = empty( $run['errors'] ) && ( $expected || $merge ) ? $this->check_rows( $expected, $merge ) : null;
        $result = $this->end_statements( $run );
        if ( $paced ) {
            $result['done'] = true;
        }
        $result['tables_swapped']     = $swapped;
        $result['tables_direct']      = array_keys( $swap['direct'] );
        // Unchanged only when nothing was swapped in or imported in place
        // (a deferred view, trigger or routine fails after the swap).
        $result['database_unchanged'] = ! empty( $result['errors'] ) && empty( $swap['direct'] ) && ! $swapped;
        $result['converted']          = $compat['converted'];
        $result['definers_removed']   = $compat['definers_removed'];
        if ( $target ) {
            $result['target']         = $target->info();
            $result['tables_left_out'] = $foreign;
            $result['statements_refused'] = $refused;
            if ( $refused ) {
                $result['warnings'][] = "Left out {$refused} statements that would have changed something other than the incoming site's own tables.";
                error_log( "Disembark: import {$this->import_id} into {$target->kind} {$target->id} left out {$refused} statements outside the source's tables." );
            }
            if ( empty( $result['errors'] ) ) {
                $result['users'] = $target->merge_users( $old_prefix, null, $this->rollback_path . '/users-merge.php' );
            }
        } elseif ( $shared_main ) {
            $result['tables_other_sites'] = $foreign;
            if ( $foreign ) {
                $result['warnings'][] = "Left out {$foreign} tables that belong to other sites of this install.";
            }
            if ( $in_users && empty( $result['errors'] ) ) {
                $result['users'] = Target::network_main()->merge_users( $old_prefix, array_values( $in_users ), $this->rollback_path . '/users-merge.php' );
            }
        }
        if ( empty( $result['errors'] ) && $rows !== null ) {
            $result['rows'] = $rows;
        }
        // The address the dump came from, for a zip that doesn't say (see
        // staged_info()): its home, now in this site's options table.
        $options = ( $target ? $target->prefix : ( $new_prefix !== '' ? $new_prefix : $wpdb->prefix ) ) . 'options';
        if ( empty( $result['errors'] ) && preg_match( '/^[A-Za-z0-9_]+$/', $options ) ) {
            $home = (string) $wpdb->get_var( "SELECT option_value FROM `{$options}` WHERE option_name = 'home' LIMIT 1" );
            if ( preg_match( '#^https?://[^\s\'"<>]+$#i', $home ) ) {
                $result['source_home'] = untrailingslashit( $home );
                self::write_protected( $this->import_path . '/source-home.php', $result['source_home'] );
            }
        }
        return $result;
    }

    /**
     * Counts each imported table's rows against the count the source wrote
     * into its export, right after the swap (before anything on this site
     * writes to them). Saved for the checks after the move. A source that
     * predates row counts sends none, and nothing is checked.
     */
    private function check_rows( $expected, $merge = false ) {
        global $wpdb;
        $missing = [];
        $earlier = $merge && is_file( $this->import_path . '/rows-check.json' ) ? json_decode( (string) file_get_contents( $this->import_path . '/rows-check.json' ), true ) : null;
        foreach ( $expected as $table => $rows ) {
            $actual = $wpdb->get_var( 'SELECT COUNT(*) FROM `' . str_replace( '`', '``', $table ) . '`' );
            if ( $actual === null ) {
                $missing[] = [ 'table' => $table, 'expected' => $rows, 'actual' => null ];
            } elseif ( (int) $actual !== $rows ) {
                $missing[] = [ 'table' => $table, 'expected' => $rows, 'actual' => (int) $actual ];
            }
        }
        if ( is_array( $earlier ) ) {
            // Tables checked again replace their earlier result.
            $missing  = array_merge( array_values( array_filter( (array) ( $earlier['mismatched'] ?? [] ), function ( $m ) use ( $expected ) { return ! isset( $expected[ $m['table'] ] ); } ) ), $missing );
            $expected = array_merge( (array) ( $earlier['expected'] ?? [] ), $expected );
        }
        $check = [ 'checked' => count( $expected ), 'rows' => array_sum( $expected ), 'mismatched' => $missing, 'expected' => $expected ];
        $this->prepare_import_dir();
        file_put_contents( $this->import_path . '/rows-check.json', wp_json_encode( $check ) );
        return $check;
    }

    /* ------------------------------------------------------------------
     * Import into copies, then swap
     *
     * Each table of the dump is created and filled under a temporary name
     * (_dsk_<table>) while the live table keeps serving the site. Only when
     * every statement has run does each copy replace its live table (DROP,
     * then RENAME: milliseconds). An import that fails, runs out of disk or
     * is cancelled drops the copies and leaves the database untouched.
     *
     * Foreign key names are unique across a database, so a copy can't carry
     * the live table's constraint names: the copy's foreign keys get
     * temporary names, and after the swap each is put back under its own
     * name (a metadata change with foreign key checks off, no table copy).
     * Keys anywhere that point at a swapped table resolve by name, so they
     * keep working after the rename. Only a table with a named CHECK
     * constraint (rare in WordPress) is still imported in place.
     * ---------------------------------------------------------------- */

    const SHADOW_PREFIX = '_dsk_';

    /** A fresh swap, with copies left by an earlier attempt dropped first. */
    private static function swap_start() {
        global $wpdb;
        foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SHOW FULL TABLES WHERE `Tables_in_' . DB_NAME . '` LIKE %s', $wpdb->esc_like( self::SHADOW_PREFIX ) . '%' ), ARRAY_N ) as $row ) {
            $kind = strtoupper( (string) ( $row[1] ?? '' ) ) === 'VIEW' ? 'VIEW' : 'TABLE';
            $wpdb->query( "DROP {$kind} IF EXISTS `" . str_replace( '`', '``', $row[0] ) . '`' );
        }
        return [ 'map' => [], 'direct' => [], 'views' => [], 'deferred' => [], 'keys' => [], 'tag' => substr( md5( uniqid( '', true ) ), 0, 4 ) ];
    }

    /** A temporary, unique name for a copy's foreign key. */
    private static function shadow_constraint( $name, $tag ) {
        $tmp = '_dsk' . $tag . '_' . $name;
        return strlen( $tmp ) <= 64 ? $tmp : '_dsk' . $tag . '_' . substr( md5( $name ), 0, 24 );
    }

    /**
     * Gives the copy's foreign keys temporary names. Returns the statement
     * and [ temporary name => [ original name, definition ] ].
     */
    private static function rename_foreign_keys( $statement, $tag ) {
        $keys = [];
        $statement = preg_replace_callback(
            '/\bCONSTRAINT\s+`((?:[^`]|``)+)`\s+(FOREIGN\s+KEY\s*\([^)]*\)\s*REFERENCES\s+(?:`(?:[^`]|``)+`\.)?`(?:[^`]|``)+`\s*\([^)]*\)(?:\s+ON\s+(?:DELETE|UPDATE)\s+(?:RESTRICT|CASCADE|SET\s+NULL|NO\s+ACTION|SET\s+DEFAULT))*)/i',
            function ( $m ) use ( &$keys, $tag ) {
                $name          = str_replace( '``', '`', $m[1] );
                $tmp           = self::shadow_constraint( $name, $tag );
                $keys[ $tmp ]  = [ $name, $m[2] ];
                return 'CONSTRAINT `' . str_replace( '`', '``', $tmp ) . '` ' . $m[2];
            },
            $statement
        );
        return [ $statement, $keys ];
    }

    private static function shadow_name( $table ) {
        $name = self::SHADOW_PREFIX . $table;
        return strlen( $name ) <= 64 ? $name : self::SHADOW_PREFIX . substr( md5( $table ), 0, 24 );
    }

    /**
     * Points each statement at the table's copy. The dump's own DROP TABLE
     * and LOCK/UNLOCK TABLES lines are left out: the copy is created fresh,
     * and a lock on the live table would block writes to the copy.
     */
    private static function swap_statements( $statements, &$swap ) {
        $ident = '(?:`((?:[^`]|``)+)`|([A-Za-z0-9_$]+))';
        $out   = [];
        foreach ( $statements as $statement ) {
            if ( preg_match( '/^(?:DROP\s+TABLE\b|LOCK\s+TABLES\b|UNLOCK\s+TABLES\b)/i', $statement ) ) {
                continue;
            }
            // The statement's start with executable-comment markers taken
            // out: mysqldump splits a view's or trigger's CREATE across
            // several (CREATE ALGORITHM=… then DEFINER=… then VIEW …).
            $flat = trim( (string) preg_replace( '/\s+/', ' ', (string) preg_replace( '/\/\*M?!\d*|\*\//', ' ', substr( $statement, 0, 600 ) ) ) );
            // Triggers, routines and events wait for the swap too: a trigger
            // on a table's copy would go with the table it replaces, and
            // none of them may change the site before the import succeeds.
            if ( preg_match( '/^(?:CREATE|DROP)\s+(?:OR\s+REPLACE\s+)?(?:DEFINER\s*=\s*\S+\s+)?(?:AGGREGATE\s+)?(?:TRIGGER|PROCEDURE|FUNCTION|EVENT)\b/i', $flat ) ) {
                $swap['deferred'][] = $statement;
                continue;
            }
            // Views wait for the swap: they read the live tables by name, and
            // creating them earlier would change the site before the import
            // has succeeded. Rows an older exporter wrote for a view would
            // land in the table behind it, so they're dropped.
            if ( preg_match( '/^(?:DROP\s+VIEW\b|CREATE\s+(?:OR\s+REPLACE\s+)?(?:ALGORITHM\s*=\s*\w+\s+)?(?:DEFINER\s*=\s*\S+\s+)?(?:SQL\s+SECURITY\s+\w+\s+)?VIEW\s+' . $ident . ')/i', $flat, $m ) ) {
                if ( stripos( $flat, 'DROP' ) !== 0 && isset( $m[1] ) ) {
                    $view = str_replace( '``', '`', $m[1] !== '' ? $m[1] : ( $m[2] ?? '' ) );
                    $swap['views'][ $view ] = true;
                    $swap['deferred'][]     = 'DROP VIEW IF EXISTS `' . str_replace( '`', '``', $view ) . '`';
                    $swap['deferred'][]     = $statement;
                }
                continue;
            }
            if ( $swap['views'] && preg_match( '/^(?:INSERT|REPLACE)\b[^`(]*?\bINTO\s+' . $ident . '/i', $statement, $m ) && isset( $swap['views'][ str_replace( '``', '`', $m[1] !== '' ? $m[1] : ( $m[2] ?? '' ) ) ] ) ) {
                continue;
            }
            if ( preg_match( '/^(CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+)' . $ident . '/i', $statement, $m ) ) {
                $table = str_replace( '``', '`', $m[2] !== '' ? $m[2] : $m[3] );
                $keys = [];
                if ( preg_match( '/\bFOREIGN\s+KEY\b|\bREFERENCES\s/i', $statement ) ) {
                    list( $renamed, $keys ) = self::rename_foreign_keys( $statement, $swap['tag'] );
                    // A foreign key this can't parse (or a named CHECK
                    // constraint, unique per database on MySQL) means the
                    // table goes in place, as before.
                    $named = preg_match_all( '/\bCONSTRAINT\s+`/i', $statement );
                    if ( ! $keys || $named !== count( $keys ) || preg_match( '/\bFOREIGN\s+KEY\b/i', preg_replace( '/\bCONSTRAINT\s+`_dsk[0-9a-f]{4}_(?:[^`]|``)*`\s+FOREIGN\s+KEY\b/i', '', $renamed ) ) ) {
                        $swap['direct'][ $table ] = true;
                        unset( $swap['map'][ $table ], $swap['keys'][ $table ] );
                        $out[] = 'DROP TABLE IF EXISTS `' . str_replace( '`', '``', $table ) . '`';
                        $out[] = $statement;
                        continue;
                    }
                    $statement = $renamed; // the CREATE TABLE head is unchanged
                } elseif ( preg_match( '/\bCONSTRAINT\s+`(?:[^`]|``)+`\s+CHECK\b/i', $statement ) ) {
                    $swap['direct'][ $table ] = true;
                    unset( $swap['map'][ $table ], $swap['keys'][ $table ] );
                    $out[] = 'DROP TABLE IF EXISTS `' . str_replace( '`', '``', $table ) . '`';
                    $out[] = $statement;
                    continue;
                }
                $shadow                = self::shadow_name( $table );
                $swap['map'][ $table ] = $shadow;
                unset( $swap['direct'][ $table ] );
                if ( $keys ) {
                    $swap['keys'][ $table ] = $keys;
                } else {
                    unset( $swap['keys'][ $table ] );
                }
                $out[] = 'DROP TABLE IF EXISTS `' . $shadow . '`';
                $out[] = $m[1] . '`' . $shadow . '`' . substr( $statement, strlen( $m[0] ) );
                continue;
            }
            if ( $swap['map'] && preg_match( '/^((?:INSERT(?:\s+(?:IGNORE|DELAYED|LOW_PRIORITY|HIGH_PRIORITY))*\s+INTO|REPLACE(?:\s+(?:DELAYED|LOW_PRIORITY))*\s+INTO|ALTER\s+TABLE|TRUNCATE(?:\s+TABLE)?)\s+)' . $ident . '/i', $statement, $m ) ) {
                $table = str_replace( '``', '`', $m[2] !== '' ? $m[2] : $m[3] );
                if ( isset( $swap['map'][ $table ] ) ) {
                    $statement = $m[1] . '`' . $swap['map'][ $table ] . '`' . substr( $statement, strlen( $m[0] ) );
                }
            }
            $out[] = $statement;
        }
        return $out;
    }

    /** Puts every copy in place of its live table. Returns how many. */
    private static function swap_finish( $swap, &$run ) {
        global $wpdb;
        $done = 0;
        foreach ( $swap['map'] as $table => $shadow ) {
            $live = '`' . str_replace( '`', '``', $table ) . '`';
            $wpdb->query( "DROP TABLE IF EXISTS {$live}" );
            if ( $wpdb->query( "RENAME TABLE `{$shadow}` TO {$live}" ) === false ) {
                $run['errors'][] = "Could not put the imported {$table} in place: " . substr( $wpdb->last_error, 0, 200 );
                continue;
            }
            $done++;
        }
        // Foreign keys get their own names back (checks are off, so InnoDB
        // changes only the metadata). If that fails the key still works
        // under its temporary name, which is reported.
        foreach ( $swap['keys'] as $table => $keys ) {
            if ( ! isset( $swap['map'][ $table ] ) ) {
                continue;
            }
            $live = '`' . str_replace( '`', '``', $table ) . '`';
            foreach ( $keys as $tmp => list( $name, $definition ) ) {
                $added = $wpdb->query( "ALTER TABLE {$live} ADD CONSTRAINT `" . str_replace( '`', '``', $name ) . "` {$definition}" ) !== false;
                if ( $added ) {
                    $wpdb->query( "ALTER TABLE {$live} DROP FOREIGN KEY `" . str_replace( '`', '``', $tmp ) . '`' );
                } else {
                    $run['warnings'][] = "The foreign key {$name} on {$table} works, but kept the temporary name {$tmp}: " . substr( $wpdb->last_error, 0, 160 );
                }
            }
        }
        wp_cache_flush();
        return $done;
    }

    private static function swap_abandon( $swap ) {
        global $wpdb;
        foreach ( $swap['map'] as $shadow ) {
            $wpdb->query( "DROP TABLE IF EXISTS `{$shadow}`" );
        }
    }

    /* ------------------------------------------------------------------
     * Database compatibility
     *
     * A dump from MySQL 8 names collations (utf8mb4_0900_ai_ci) that older
     * MariaDB and MySQL don't have, a MariaDB dump can name engines MySQL
     * lacks (Aria), and views, triggers and routines carry a DEFINER account
     * that doesn't exist on the new server. Each is rewritten to the closest
     * thing this server has, and counted for the report.
     * ---------------------------------------------------------------- */

    private static function db_compat() {
        global $wpdb;
        $compat = [ 'collations' => [], 'charsets' => [], 'engines' => [], 'converted' => [], 'definers_removed' => 0, 'map' => [] ];
        // MariaDB 11 lists some collations without a character set.
        foreach ( (array) $wpdb->get_results( 'SHOW COLLATION', ARRAY_A ) as $row ) {
            $compat['collations'][ strtolower( (string) $row['Collation'] ) ] = strtolower( (string) $row['Charset'] );
        }
        foreach ( (array) $wpdb->get_results( 'SHOW CHARACTER SET', ARRAY_A ) as $row ) {
            $compat['charsets'][ strtolower( (string) $row['Charset'] ) ] = strtolower( (string) $row['Default collation'] );
        }
        foreach ( (array) $wpdb->get_results( 'SHOW ENGINES', ARRAY_A ) as $row ) {
            if ( in_array( strtoupper( (string) $row['Support'] ), [ 'YES', 'DEFAULT' ], true ) ) {
                $compat['engines'][ strtolower( (string) $row['Engine'] ) ] = true;
            }
        }
        return $compat;
    }

    /** The collation this server should use for $collation, or null if it has it. */
    private static function compat_collation( $collation, &$compat ) {
        $c = strtolower( $collation );
        if ( ! $compat['collations'] || isset( $compat['collations'][ $c ] ) ) {
            return null;
        }
        if ( array_key_exists( $c, $compat['map'] ) ) {
            return $compat['map'][ $c ];
        }
        $charset = strstr( $c, '_', true );
        $charset = $charset === false ? $c : $charset;
        $candidates = [];
        if ( $charset === 'utf8mb3' ) {
            $candidates[] = 'utf8' . substr( $c, 7 );
            $charset      = isset( $compat['charsets']['utf8mb3'] ) ? 'utf8mb3' : 'utf8';
        }
        if ( substr( $c, -4 ) === '_bin' || substr( $c, -3 ) === '_cs' ) {
            $candidates[] = $charset . '_bin';
        }
        if ( $charset === 'utf8mb4' ) {
            array_push( $candidates, 'utf8mb4_unicode_520_ci', 'utf8mb4_unicode_ci', 'utf8mb4_general_ci' );
        } elseif ( $charset === 'utf8' || $charset === 'utf8mb3' ) {
            array_push( $candidates, $charset . '_unicode_520_ci', $charset . '_unicode_ci', $charset . '_general_ci' );
        }
        if ( isset( $compat['charsets'][ $charset ] ) ) {
            $candidates[] = $compat['charsets'][ $charset ];
        }
        $pick = null;
        foreach ( $candidates as $candidate ) {
            if ( isset( $compat['collations'][ $candidate ] ) ) {
                $pick = $candidate;
                break;
            }
        }
        $compat['map'][ $c ] = $pick;
        return $pick;
    }

    private static function compat_statement( $statement, &$compat ) {
        if ( ! preg_match( '/^(?:\/\*!\d+\s+)?CREATE\b/i', $statement ) ) {
            return $statement;
        }
        if ( ! preg_match( '/^CREATE\s+TABLE\b/i', $statement ) ) {
            // Views, triggers, routines: run them as whoever imports them.
            $stripped = preg_replace( '/\bDEFINER\s*=\s*(?:`[^`]*`|\'[^\']*\'|[^\s@*]+)\s*@\s*(?:`[^`]*`|\'[^\']*\'|[^\s*]+)\s*/i', '', $statement, -1, $n );
            if ( $n ) {
                $compat['definers_removed'] += $n;
                $statement = preg_replace( '/\bSQL\s+SECURITY\s+DEFINER\b/i', 'SQL SECURITY INVOKER', (string) $stripped );
            }
            return $statement;
        }
        $notes = [];
        $statement = preg_replace_callback( '/\bCOLLATE(\s*=\s*|\s+)([A-Za-z0-9_]+)/i', function ( $m ) use ( &$compat, &$notes ) {
            $to = self::compat_collation( $m[2], $compat );
            if ( $to === null ) {
                return $m[0];
            }
            $notes[ strtolower( $m[2] ) . ' → ' . $to ] = true;
            return 'COLLATE' . $m[1] . $to;
        }, $statement );
        if ( $compat['charsets'] && ! isset( $compat['charsets']['utf8mb3'] ) && isset( $compat['charsets']['utf8'] ) ) {
            $statement = preg_replace_callback( '/\b(CHARSET|CHARACTER\s+SET)(\s*=\s*|\s+)utf8mb3\b/i', function ( $m ) use ( &$notes ) {
                $notes['utf8mb3 → utf8'] = true;
                return $m[1] . $m[2] . 'utf8';
            }, $statement );
        }
        if ( $compat['engines'] ) {
            $statement = preg_replace_callback( '/\bENGINE(\s*=\s*|\s+)([A-Za-z0-9_]+)/i', function ( $m ) use ( &$compat, &$notes ) {
                if ( isset( $compat['engines'][ strtolower( $m[2] ) ] ) || ! isset( $compat['engines']['innodb'] ) ) {
                    return $m[0];
                }
                $notes[ $m[2] . ' → InnoDB' ] = true;
                return 'ENGINE' . $m[1] . 'InnoDB';
            }, $statement );
        }
        foreach ( array_keys( $notes ) as $note ) {
            $compat['converted'][ $note ] = ( $compat['converted'][ $note ] ?? 0 ) + 1;
        }
        return $statement;
    }

    /**
     * Reports what is staged for a dashboard restore: file count, the located
     * SQL dump, and the captured source metadata.
     *
     * $database: the database to restore, when the person picked one (a
     * path in the zip, as 'database' lists it), or 'none' to restore the
     * files only. Remembered for the rest of the move.
     */
    public function staged_info( $database = null ) {
        if ( $database !== null && $database !== '' ) {
            $chosen = $this->choose_database( (string) $database );
            if ( is_wp_error( $chosen ) ) {
                return $chosen;
            }
        }
        $db      = $this->database_info();
        $source  = $this->staged_source();
        // A zip that isn't Disembark's says nothing about its source: what
        // the dump and the zip's wp-config.php tell stands in, so the prefix
        // is remapped and the old address rewritten.
        if ( $db['file'] !== null && ( $db['prefix'] !== '' || $db['home'] !== '' ) ) {
            $source = is_object( $source ) ? $source : new \stdClass();
            if ( empty( $source->db_prefix ) && $db['prefix'] !== '' ) {
                $source->db_prefix = $db['prefix'];
            }
            if ( empty( $source->home_url ) && $db['home'] !== '' ) {
                $source->home_url = $db['home'];
            }
        }
        $refused = $this->refused();
        return [
            'refused'   => $refused ? $refused->get_error_message() : null,
            'files'     => $this->count_staged_files(),
            'sql_file'  => $db['file'],
            'database'  => $db,
            'source'    => $source,
            'multisite' => ! empty( $source->scope ) ? [] : $this->staged_multisite_constants(),
            'paths'     => self::path_pairs( $source ),
            'moved'     => self::scope_pairs( $source ),
        ];
    }

    /* ------------------------------------------------------------------
     * Which database dump a zip restores
     *
     * Disembark's own zips carry database-<name>.sql. Other hosts' and
     * tools' zips carry their own: Kinsta's <site>.sql beside public/, WP
     * Engine's wp-content/mysql.sql, Duplicator's database.sql, a cPanel
     * backup's mysql/<db>.sql. When the answer is clear it's found on its
     * own (and the reason said); when it isn't, or the only dumps are deep
     * in the tree, the move stops before the restore point and asks, with
     * what a quick look at each one shows. A zip with no dump at all asks
     * too, so a restore never ends as files only by accident.
     * ---------------------------------------------------------------- */

    /** Where hosts keep the dump inside the site, beside the zip's own root (relative to the site's root). */
    const HOST_DUMPS = [ 'wp-content/mysql.sql' => 'where WP Engine keeps the database', 'wp-content/mysqldb-dump.sql.gz' => 'where Pressidium keeps the database', 'wp-content/mysqldb-dump.sql' => 'where Pressidium keeps the database' ];

    /**
     * The database this restore uses: [ file (path in staging, or null),
     * why, ask ('' | 'pick' | 'none'), files_only, prefix, home,
     * candidates (for 'pick': each dump found, with what a look at it shows) ].
     */
    public function database_info() {
        $out = [ 'file' => null, 'why' => '', 'ask' => '', 'files_only' => false, 'prefix' => '', 'home' => '', 'candidates' => [] ];
        // Disembark's own export, as before: no need to look any further.
        foreach ( [ '/public', '' ] as $sub ) {
            // Staged as database-x.sql.php; an older build left database-x.sql.
            $matches = array_merge( glob( $this->staging_path . $sub . '/database-*.sql.php' ) ?: [], glob( $this->staging_path . $sub . '/database-*.sql' ) ?: [] );
            if ( ! empty( $matches ) ) {
                $out['file'] = self::unstaged_name( ltrim( str_replace( '\\', '/', substr( $matches[0], strlen( $this->staging_path ) ) ), '/' ) );
                $out['why']  = 'Disembark\'s own database export';
                return $out;
            }
        }
        $found  = $this->find_dumps();
        $config = $this->staged_config();
        $choice = json_decode( (string) self::read_protected( $this->import_path . '/db-choice.php' ), true );
        if ( is_array( $choice ) && ! empty( $choice['none'] ) ) {
            $out['files_only'] = true;
            $out['why']        = 'files only, as asked';
            return $out;
        }
        $pick = function ( $rel, $why ) use ( &$out, $found, $config ) {
            $peek          = $this->peek_cached( $rel, $config['prefix'], 20 );
            $out['file']   = $rel;
            $out['why']    = $why;
            $out['prefix'] = self::dump_prefix( $peek, $config['prefix'] );
            $out['home']   = (string) ( $peek['home'] ?? '' );
            return $out;
        };
        if ( is_array( $choice ) && isset( $choice['file'], $found[ $choice['file'] ] ) ) {
            return $pick( $choice['file'], (string) ( $choice['why'] ?? 'you picked it' ) );
        }
        if ( ! $found ) {
            $out['ask'] = 'none';
            return $out;
        }
        $top = array_filter( $found, function ( $f ) { return $f['spot'] !== 'deep'; } );
        if ( count( $top ) === 1 ) {
            $rel = (string) key( $top );
            return $pick( $rel, $top[ $rel ]['spot'] === 'host' ? $top[ $rel ]['where'] : 'the only database dump ' . $top[ $rel ]['where'] );
        }
        if ( count( $top ) > 1 ) {
            // Named for the database in the zip's wp-config.php.
            if ( $config['db_name'] !== '' ) {
                $named = array_filter( array_keys( $top ), function ( $rel ) use ( $config ) {
                    return strcasecmp( (string) preg_replace( '/\.sql(?:\.gz)?$/i', '', basename( $rel ) ), $config['db_name'] ) === 0;
                } );
                if ( count( $named ) === 1 ) {
                    return $pick( (string) reset( $named ), 'its name matches DB_NAME (' . $config['db_name'] . ') in the zip\'s wp-config.php' );
                }
            }
            // The only one holding the site's options table.
            if ( $config['prefix'] !== '' ) {
                $with = [];
                foreach ( array_keys( $top ) as $rel ) {
                    $peek = $this->peek_cached( $rel, $config['prefix'], 5 );
                    if ( ( $peek['options'] ?? '' ) === $config['prefix'] . 'options' ) {
                        $with[] = $rel;
                    }
                }
                if ( count( $with ) === 1 ) {
                    return $pick( $with[0], 'the only one with the site\'s options table (' . $config['prefix'] . 'options)' );
                }
            }
        }
        // Not sure: everything found, with what a look shows, the best guess first.
        $out['ask'] = 'pick';
        $budget     = microtime( true ) + 20;
        foreach ( $found as $rel => $f ) {
            $peek  = $this->peek_cached( $rel, $config['prefix'], max( 1, min( 5, $budget - microtime( true ) ) ) );
            $score = ( $config['db_name'] !== '' && strcasecmp( (string) preg_replace( '/\.sql(?:\.gz)?$/i', '', basename( $rel ) ), $config['db_name'] ) === 0 ? 8 : 0 )
                + ( ! empty( $peek['options'] ) ? 4 : 0 ) + ( $f['spot'] !== 'deep' ? 2 : 0 ) + ( ! empty( $peek['sql'] ) ? 1 : 0 );
            $out['candidates'][] = [
                'file'    => $rel,
                'size'    => $f['size'],
                'where'   => $f['where'],
                'spot'    => $f['spot'],
                'prefix'  => self::dump_prefix( $peek, $config['prefix'] ),
                'options' => (string) ( $peek['options'] ?? '' ),
                'home'    => (string) ( $peek['home'] ?? '' ),
                'tables'  => count( (array) ( $peek['tables'] ?? [] ) ),
                'looked'  => (int) ( $peek['looked'] ?? 0 ),
                'whole'   => ! empty( $peek['whole'] ),
                'usable'  => ! empty( $peek['sql'] ),
                'note'    => (string) ( $peek['note'] ?? '' ),
                'score'   => $score,
            ];
        }
        usort( $out['candidates'], function ( $a, $b ) { return $b['score'] - $a['score'] ?: $b['size'] - $a['size'] ?: strcmp( $a['file'], $b['file'] ); } );
        foreach ( $out['candidates'] as $i => &$c ) {
            $c['guess'] = $i === 0 && $c['usable'];
            unset( $c['score'] );
        }
        unset( $c );
        return $out;
    }

    /**
     * Records which dump to restore: a path database_info() listed, or
     * 'none' for the files only. Only before the restore point is saved.
     */
    public function choose_database( $file, $why = 'you picked it' ) {
        $file = trim( (string) $file );
        // Remembered with the restore point it was saved for: once a
        // restore is under way, a request can't change what it imports.
        if ( self::has_restore_point( $this->import_path ) && is_file( $this->import_path . '/db-choice.php' ) ) {
            $was = json_decode( (string) self::read_protected( $this->import_path . '/db-choice.php' ), true );
            if ( is_array( $was ) && ( $file === 'none' ? ! empty( $was['none'] ) : ( $was['file'] ?? '' ) === $file ) ) {
                return true;
            }
            return new \WP_Error( 'database_chosen', 'This restore has already started with another database choice.', [ 'status' => 409 ] );
        }
        if ( $file === 'none' ) {
            $this->prepare_import_dir();
            self::write_protected( $this->import_path . '/db-choice.php', (string) wp_json_encode( [ 'none' => true ] ) );
            return true;
        }
        if ( ! self::is_safe_relative_path( $file ) || ! isset( $this->find_dumps()[ $file ] ) ) {
            return new \WP_Error( 'no_such_database', "There's no database dump called {$file} in this zip.", [ 'status' => 400 ] );
        }
        $this->prepare_import_dir();
        self::write_protected( $this->import_path . '/db-choice.php', (string) wp_json_encode( [ 'file' => $file, 'why' => $why ] ) );
        return true;
    }

    /**
     * Every .sql and .sql.gz in staging (paths as the zip has them):
     * [ rel => [ size, spot (own|top|host|deep), where ] ]. "top" is the
     * zip's root or the root of its public/ folder.
     */
    private function find_dumps() {
        $out = [];
        if ( ! is_dir( $this->staging_path ) ) {
            return $out;
        }
        $base = $this->staging_path;
        $site = $this->staging_root() === $base ? '' : 'public/';
        try {
            $it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS ) );
            foreach ( $it as $file ) {
                if ( ! $file->isFile() ) {
                    continue;
                }
                $rel = self::unstaged_name( ltrim( str_replace( '\\', '/', substr( $file->getPathname(), strlen( $base ) ) ), '/' ) );
                if ( ! preg_match( '/\.sql(?:\.gz)?$/i', $rel ) || self::in_storage( $rel ) || preg_match( '#(?:^|/)(?:_chunks|\.disembark-db-parts|\.disembark-manifests)/#', $rel ) ) {
                    continue;
                }
                $dir  = strpos( $rel, '/' ) === false ? '' : dirname( $rel ) . '/';
                $in   = $site !== '' && strpos( $rel, $site ) === 0 ? substr( $rel, strlen( $site ) ) : null;
                if ( $dir === '' ) {
                    $spot  = 'top';
                    $where = 'at the zip\'s root';
                } elseif ( $site !== '' && $dir === $site ) {
                    $spot  = 'top';
                    $where = 'at the root of the zip\'s ' . $site . ' folder';
                } elseif ( isset( self::HOST_DUMPS[ strtolower( (string) ( $in ?? $rel ) ) ] ) ) {
                    $spot  = 'host';
                    $where = self::HOST_DUMPS[ strtolower( (string) ( $in ?? $rel ) ) ];
                } else {
                    $spot  = 'deep';
                    $where = 'inside the site\'s files';
                }
                $out[ $rel ] = [ 'size' => (int) $file->getSize(), 'spot' => $spot, 'where' => $where ];
            }
        } catch ( \Throwable $e ) {
            return $out;
        }
        ksort( $out );
        return $out;
    }

    /** DB_NAME and $table_prefix from the zip's wp-config.php ('' when it has none). */
    private function staged_config() {
        foreach ( [ 'public/wp-config.php', 'wp-config.php' ] as $rel ) {
            $file = self::staged_file( $this->staging_path, $rel );
            if ( ! is_file( $file ) ) {
                continue;
            }
            $config = substr( (string) self::read_protected( $file ), 0, 262144 );
            $name   = preg_match( '/define\(\s*[\'"]DB_NAME[\'"]\s*,\s*[\'"]([^\'"]{1,64})[\'"]\s*\)/i', $config, $m ) ? $m[1] : '';
            $prefix = preg_match( '/^\s*\$table_prefix\s*=\s*[\'"]([A-Za-z0-9_]{1,40})[\'"]\s*;/m', $config, $m ) ? $m[1] : '';
            return [ 'db_name' => $name, 'prefix' => $prefix ];
        }
        return [ 'db_name' => '', 'prefix' => '' ];
    }

    /** The table prefix a dump uses: its options table's, the zip's wp-config.php one when its tables carry it, or its first table's. */
    private static function dump_prefix( $peek, $config_prefix ) {
        if ( ! empty( $peek['options'] ) ) {
            return (string) substr( $peek['options'], 0, -strlen( 'options' ) );
        }
        $tables = (array) ( $peek['tables'] ?? [] );
        if ( $config_prefix !== '' && ( ! $tables || array_filter( $tables, function ( $t ) use ( $config_prefix ) { return strpos( $t, $config_prefix ) === 0; } ) ) ) {
            return $config_prefix;
        }
        return $tables && preg_match( '/^([A-Za-z0-9]+_)/', $tables[0], $m ) ? $m[1] : '';
    }

    /** peek_dump() of a staged dump, kept for the rest of the move (one look per file). */
    private function peek_cached( $rel, $prefix, $seconds ) {
        $cache_file = $this->import_path . '/db-peek.php';
        $cache      = json_decode( (string) self::read_protected( $cache_file ), true );
        $cache      = is_array( $cache ) ? $cache : [];
        $path       = self::staged_file( $this->staging_path, $rel );
        $size       = is_file( $path ) ? (int) filesize( $path ) : -1;
        $hit        = $cache[ $rel ] ?? null;
        // A look cut short by its time is taken again with more time.
        if ( is_array( $hit ) && (int) ( $hit['size'] ?? -2 ) === $size && ( ! empty( $hit['whole'] ) || ! empty( $hit['home'] ) || (float) ( $hit['seconds'] ?? 0 ) >= $seconds ) ) {
            return $hit;
        }
        $peek            = self::peek_dump( $path, $prefix, $seconds );
        $peek['size']    = $size;
        $peek['seconds'] = $seconds;
        $cache[ $rel ]   = $peek;
        $this->prepare_import_dir();
        self::write_protected( $cache_file, (string) wp_json_encode( $cache ) );
        return $peek;
    }

    /**
     * A quick look at a dump, for at most $seconds: whether it reads as SQL,
     * the tables it creates (the first 200), its options table (the one
     * named $prefix.options, else the shortest name ending in options), and
     * that table's siteurl and home. Reads a .sql.gz through gzip as it goes.
     */
    private static function peek_dump( $path, $prefix, $seconds ) {
        $out = [ 'sql' => false, 'tables' => [], 'options' => '', 'home' => '', 'siteurl' => '', 'looked' => 0, 'whole' => false, 'note' => '' ];
        $open = self::open_dump( $path );
        if ( is_wp_error( $open ) ) {
            $out['note'] = 'unreadable';
            return $out;
        }
        list( $handle ) = $open;
        $deadline  = microtime( true ) + max( 0.5, (float) $seconds );
        $tail      = '';
        $in_opts   = false;
        $first     = true;
        while ( ! feof( $handle ) && microtime( true ) < $deadline ) {
            $chunk = fread( $handle, 1048576 );
            if ( $chunk === false || $chunk === '' ) {
                break;
            }
            $out['looked'] += strlen( $chunk );
            $s    = $tail . $chunk;
            $tail = substr( $s, -4096 );
            if ( $first ) {
                $first = false;
                if ( strpos( $s, "\0" ) !== false && ! preg_match( '/\b(?:CREATE\s+TABLE|INSERT\s+INTO)\b/i', substr( $s, 0, 65536 ) ) ) {
                    $out['note'] = 'not a text SQL dump';
                    break;
                }
            }
            if ( ! $out['sql'] && preg_match( '/^\s*(?:CREATE\s+TABLE|INSERT\s+INTO|DROP\s+TABLE)\b/im', $s ) ) {
                $out['sql'] = true;
            }
            if ( preg_match_all( '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`([^`]{1,64})`/i', $s, $m, PREG_OFFSET_CAPTURE ) ) {
                foreach ( $m[1] as $hit ) {
                    $name = $hit[0];
                    if ( count( $out['tables'] ) < 200 && ! in_array( $name, $out['tables'], true ) ) {
                        $out['tables'][] = $name;
                    }
                    if ( substr( $name, -7 ) === 'options' && preg_match( '/^[A-Za-z0-9_]*$/', $name ) ) {
                        $mine = $prefix !== '' && $name === $prefix . 'options';
                        if ( $mine || $out['options'] === '' || ( strlen( $name ) < strlen( $out['options'] ) && $out['options'] !== $prefix . 'options' ) ) {
                            $out['options'] = $name;
                            $in_opts        = true;
                        }
                    } elseif ( $in_opts && $out['home'] !== '' ) {
                        $in_opts = false;
                    }
                }
            }
            if ( $in_opts && preg_match_all( "/\\(\\s*'?\\d+'?\\s*,\\s*'(siteurl|home)'\\s*,\\s*'([^'\\\\]*+(?:\\\\.[^'\\\\]*+)*+)'/", $s, $m, PREG_SET_ORDER ) ) {
                foreach ( $m as $row ) {
                    $value = stripslashes( $row[2] );
                    if ( preg_match( '#^https?://[^\s\'"<>]+$#i', $value ) && $out[ $row[1] ] === '' ) {
                        $out[ $row[1] ] = untrailingslashit( $value );
                    }
                }
            }
            if ( $out['home'] !== '' && $out['siteurl'] !== '' ) {
                break;
            }
        }
        $out['whole'] = feof( $handle );
        fclose( $handle );
        if ( $out['home'] === '' ) {
            $out['home'] = $out['siteurl'];
        }
        if ( ! $out['sql'] && $out['note'] === '' ) {
            $out['note'] = $out['looked'] ? 'no CREATE TABLE or INSERT in the part read' : 'empty';
        }
        return $out;
    }

    /**
     * Opens a staged dump for reading past its exit line, through gzip when
     * it's compressed. [ handle, gz, base (bytes before the dump) ] or WP_Error.
     */
    private static function open_dump( $path ) {
        $handle = @fopen( $path, 'rb' );
        if ( ! $handle ) {
            return new \WP_Error( 'sql_unreadable', 'Could not read ' . basename( $path ) . '.', [ 'status' => 500 ] );
        }
        $base = fread( $handle, strlen( self::PHP_EXIT ) ) === self::PHP_EXIT ? strlen( self::PHP_EXIT ) : 0;
        fseek( $handle, $base );
        $gz = fread( $handle, 2 ) === "\x1f\x8b";
        fseek( $handle, $base );
        if ( $gz && ! @stream_filter_append( $handle, 'zlib.inflate', STREAM_FILTER_READ, [ 'window' => 31 ] ) ) {
            fclose( $handle );
            return new \WP_Error( 'sql_unreadable', 'Could not read ' . basename( $path ) . ': this server can\'t unpack gzip.', [ 'status' => 500 ] );
        }
        return [ $handle, $gz, $base ];
    }

    /** How many bytes a staged dump holds once unpacked (a .sql.gz's from its gzip trailer). */
    private static function dump_bytes( $path ) {
        $size = (int) @filesize( $path );
        $open = self::open_dump( $path );
        if ( is_wp_error( $open ) ) {
            return $size;
        }
        list( $handle, $gz, $base ) = $open;
        fclose( $handle );
        if ( ! $gz || $size < $base + 18 ) {
            return $size;
        }
        $raw = @fopen( $path, 'rb' );
        fseek( $raw, -4, SEEK_END );
        $isize = unpack( 'V', (string) fread( $raw, 4 ) );
        fclose( $raw );
        // The trailer keeps the size modulo 4 GB.
        $bytes = (int) ( $isize[1] ?? 0 );
        while ( $bytes < $size - $base ) {
            $bytes += 4294967296;
        }
        return $bytes;
    }

    /**
     * The source's details for the end of a move: disembark-source.json, or
     * for another host's zip the address its database told (see
     * staged_info()), so the tidy-up and the checks after the move know
     * where the site came from.
     */
    private function move_source() {
        $source = $this->staged_source();
        if ( is_object( $source ) && ! empty( $source->home_url ) ) {
            return $source;
        }
        $home = (string) self::read_protected( $this->import_path . '/source-home.php' );
        if ( $home === '' && ! is_object( $source ) ) {
            $db   = $this->database_info();
            $home = $db['file'] !== null ? (string) $db['home'] : '';
        }
        if ( ! preg_match( '#^https?://[^\s\'"<>]+$#i', $home ) ) {
            return $source;
        }
        $source           = is_object( $source ) ? $source : new \stdClass();
        $source->home_url = $home;
        return $source;
    }

    /** The source's metadata (disembark-source.json) from staging, or null. */
    public function staged_source() {
        foreach ( [ '/public/disembark-source.json', '/disembark-source.json' ] as $rel ) {
            if ( file_exists( $this->staging_path . $rel ) ) {
                return json_decode( (string) file_get_contents( $this->staging_path . $rel ) );
            }
        }
        return null;
    }

    /**
     * The search & replace a move runs: the old address in all its spellings
     * (url_variants()), plus the old server's paths. Also rewrites the old
     * address in text files under uploads (rewrite_files()).
     */
    public function move_search_replace( $from, $to, $budget = null ) {
        if ( $refused = $this->refused() ) {
            return $refused;
        }
        $source = $this->staged_source();
        $target = $this->target();
        if ( $target ) {
            // The new site's address and folders, and its tables alone.
            $to     = $target->home;
            $tables = $target->tables();
        } else {
            $tables = $this->move_tables();
        }
        if ( ! $tables ) {
            return [ 'tables' => 0, 'rows_changed' => 0, 'cells_changed' => 0, 'by_table' => [], 'samples' => [], 'paths' => [], 'moved' => [] ];
        }
        $moved   = self::scope_pairs( $source, $to, $target ) + self::target_pairs( $source, $target );
        $options = [ 'variants' => true, 'extra' => self::path_pairs( $source ) + $moved ];
        if ( $budget !== null ) {
            $options['budget'] = $budget;
        }
        $result = $this->search_replace( $from, $to, $tables, false, $options );
        if ( is_wp_error( $result ) || ( isset( $result['done'] ) && ! $result['done'] ) ) {
            return $result;
        }
        $result['paths'] = self::path_pairs( $source );
        $result['moved'] = $moved;
        $file_pairs = $from !== $to ? self::replacement_pairs( $from, $to, true ) : [];
        foreach ( $moved as $f => $t ) {
            $file_pairs += self::replacement_pairs( $f, $t );
        }
        return array_merge( $result, $this->rewrite_files( $file_pairs, $target ) );
    }

    /**
     * The address rewrite of a move (see move_search_replace()) on $tables
     * only, without the files: a catch-up's tables copied a second time.
     */
    public function rewrite_tables( $from, $to, $tables, $budget = null ) {
        $source  = $this->staged_source();
        $moved   = self::scope_pairs( $source, $to, null );
        $options = [ 'variants' => true, 'extra' => self::path_pairs( $source ) + $moved ];
        if ( $budget !== null ) {
            $options['budget'] = $budget;
        }
        return $tables ? $this->search_replace( $from, $to, array_values( $tables ), false, $options ) : [ 'done' => true, 'cells_changed' => 0 ];
    }

    /**
     * The tables a whole-site move rewrites: this site's (its prefix) and the
     * ones the import created. Never every table in the database: one shared
     * with another site (staging beside production, two prefixes) keeps the
     * other site's tables as they are.
     */
    private function move_tables() {
        global $wpdb;
        // On a network's main site the base prefix covers every subsite and
        // the network's users and sites too: its own tables only.
        $shared = (bool) Scope::install();
        $tables = $shared ? self::own_tables() : (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->base_prefix ) . '%' ) );
        $file   = $this->import_path . '/imported-tables.json';
        if ( is_file( $file ) ) {
            $all    = array_flip( (array) $wpdb->get_col( 'SHOW TABLES' ) );
            $extra  = array_filter( (array) json_decode( (string) file_get_contents( $file ), true ), function ( $t ) use ( $all, $shared ) { return is_string( $t ) && isset( $all[ $t ] ) && ! ( $shared && self::other_sites_table( $t ) ); } );
            $tables = array_merge( $tables, $extra );
        }
        // A catch-up leaves unchanged tables alone: they were rewritten on
        // the last pull, and this site's fingerprint says they still are.
        $plan = json_decode( (string) self::read_protected( $this->import_path . '/db-plan.php' ), true );
        $skip = array_flip( (array) ( $plan['skipped_dst'] ?? [] ) );
        $tables = array_filter( $tables, function ( $t ) use ( $skip ) { return ! isset( $skip[ $t ] ); } );
        return array_values( array_unique( array_map( 'strval', $tables ) ) );
    }

    /**
     * A folder path from the source (its abspath, content_dir) fit to use
     * as a search & replace pair: absolute, at least two folders deep, and
     * plain path characters only. A shorter or odd one would rewrite text
     * that merely contains it.
     */
    private static function safe_source_path( $path ) {
        $path = trim( (string) $path );
        if ( ! preg_match( '#^(?:/|[A-Za-z]:[\\\\/])[A-Za-z0-9 _.~@+\-/\\\\:]*$#D', $path ) || preg_match( '#(?:^|[\\\\/])\.\.(?:[\\\\/]|$)#', $path ) ) {
            return false;
        }
        return count( array_filter( preg_split( '#[\\\\/]+#', preg_replace( '#^[A-Za-z]:#', '', $path ) ), 'strlen' ) ) >= 2;
    }

    /** An address from the source fit for a pair: http(s), with a host, no spaces or quotes. */
    private static function safe_source_url( $url ) {
        $url = (string) $url;
        return (bool) preg_match( '#^https?://[^\s"\'<>\\\\]+$#iD', $url ) && (string) wp_parse_url( $url, PHP_URL_HOST ) !== '';
    }

    /** A folder of the source's (wp-content/uploads/sites/5, content/8): relative, two folders or more. */
    private static function safe_source_folder( $folder ) {
        return (bool) preg_match( '#^[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)+$#D', (string) $folder ) && ! preg_match( '#(?:^|/)\.\.?(?:/|$)#', (string) $folder );
    }

    /**
     * Into a subsite or tenant: a standard site's wp-content/uploads (and,
     * for a dedicated Freighter tenant, all of wp-content) now lives in the
     * site's own folders. Root-relative links only count where markup
     * starts them (a quote or a bracket), so other sites' paths are left be.
     */
    public static function target_pairs( $source, $target ) {
        if ( ! $target ) {
            return [];
        }
        $source = (array) $source;
        $root   = rtrim( wp_normalize_path( dirname( WP_CONTENT_DIR ) ), '/' );
        $moves  = [ 'wp-content/uploads' => $target->uploads ];
        if ( $target->content !== '' ) {
            $moves['wp-content'] = $target->content;
        }
        $bases = array_unique( array_filter( [ (string) ( $source['home_url'] ?? '' ), (string) ( $source['site_url'] ?? '' ) ] ) );
        $pairs = [];
        foreach ( $moves as $from => $to ) {
            if ( $from === $to ) {
                continue;
            }
            $url = untrailingslashit( $target->home ) . '/' . $to . '/';
            foreach ( $bases as $base ) {
                if ( self::safe_source_url( $base ) ) {
                    $pairs += self::url_variants( untrailingslashit( $base ) . '/' . $from . '/', $url );
                }
            }
            $path = (string) wp_parse_url( $url, PHP_URL_PATH );
            foreach ( [ '"', "'", '(' ] as $q ) {
                $pairs += [ $q . '/' . $from . '/' => $q . $path ];
            }
            if ( ! empty( $source['content_dir'] ) && self::safe_source_path( $source['content_dir'] ) ) {
                $pairs += [ trailingslashit( (string) $source['content_dir'] ) . ( $from === 'wp-content' ? '' : substr( $from, strlen( 'wp-content/' ) ) . '/' ) => $root . '/' . $to . '/' ];
            }
        }
        return $pairs;
    }

    /**
     * One site brought out of a network or WP Freighter install (the source's
     * `scope`): its folders that moved (uploads/sites/5 → uploads, content/8
     * → wp-content) at every address they were served from, root-relative,
     * and on the old server's disk; and the domains a mapping plugin pointed
     * at it. Empty for an ordinary source.
     */
    public static function scope_pairs( $source, $to = '', $target = null ) {
        $scope = (array) ( ( (array) $source )['scope'] ?? [] );
        if ( ! $scope ) {
            return [];
        }
        // This site's folders, as URLs under its address ($to): content_url()
        // can't be trusted mid-import (WP-CLI reports http, and the options
        // table is the source's until the rewrite).
        $uploads  = wp_upload_dir( null, false );
        $web_root = wp_normalize_path( dirname( WP_CONTENT_DIR ) );
        $to       = untrailingslashit( $to !== '' ? (string) $to : home_url() );
        $dirs     = [ 'wp-content' => wp_normalize_path( WP_CONTENT_DIR ), 'wp-content/uploads' => wp_normalize_path( (string) $uploads['basedir'] ) ];
        $urls     = [];
        foreach ( $dirs as $key => $dir ) {
            $urls[ $key ] = $to . '/' . ltrim( strpos( $dir, $web_root . '/' ) === 0 ? substr( $dir, strlen( $web_root ) + 1 ) : $key, '/' );
        }
        if ( $target ) {
            $to   = untrailingslashit( $target->home );
            $urls = [ 'wp-content' => $to . '/' . ( $target->content !== '' ? $target->content : ltrim( substr( wp_normalize_path( WP_CONTENT_DIR ), strlen( $web_root ) ), '/' ) ), 'wp-content/uploads' => $to . '/' . $target->uploads ];
            $dirs = [ 'wp-content' => $target->content !== '' ? $web_root . '/' . $target->content : wp_normalize_path( WP_CONTENT_DIR ), 'wp-content/uploads' => $web_root . '/' . $target->uploads ];
        }
        $pairs   = [];
        foreach ( (array) ( $scope['url_pairs'] ?? [] ) as $from_url => $to ) {
            if ( isset( $urls[ $to ] ) && self::safe_source_url( $from_url ) ) {
                $pairs += self::url_variants( trailingslashit( (string) $from_url ), trailingslashit( $urls[ $to ] ) );
            }
        }
        foreach ( (array) ( $scope['moves'] ?? [] ) as $move ) {
            list( $from, $to ) = array_values( (array) $move ) + [ '', '' ];
            $from = trim( (string) $from, '/' );
            if ( ! self::safe_source_folder( $from ) || ! isset( $urls[ $to ] ) ) {
                continue;
            }
            foreach ( (array) ( $scope['bases'] ?? [] ) as $base ) {
                if ( self::safe_source_url( $base ) ) {
                    $pairs += self::url_variants( untrailingslashit( (string) $base ) . '/' . $from . '/', trailingslashit( $urls[ $to ] ) );
                }
            }
            $pairs += [ '/' . $from . '/' => trailingslashit( (string) ( wp_parse_url( $urls[ $to ], PHP_URL_PATH ) ?: '/' . $to ) ) ];
            if ( ! empty( $scope['abspath'] ) && self::safe_source_path( $scope['abspath'] ) ) {
                $pairs += [ trailingslashit( (string) $scope['abspath'] ) . $from . '/' => trailingslashit( wp_normalize_path( $dirs[ $to ] ) ) ];
            }
        }
        foreach ( (array) ( $scope['aliases'] ?? [] ) as $alias ) {
            if ( self::safe_source_url( $alias ) ) {
                $pairs += self::url_variants( untrailingslashit( (string) $alias ), untrailingslashit( $target ? $target->home : home_url() ) );
            }
        }
        foreach ( $pairs as $f => $t ) {
            if ( (string) $f === (string) $t ) {
                unset( $pairs[ $f ] );
            }
        }
        return $pairs;
    }

    /**
     * The old server's folders mapped to this one's, when they differ: cache,
     * security, backup and log plugins store absolute paths, which point at
     * nothing after a move to a new host. Paths end in a slash, so only paths
     * inside the folder match (/var/www/html/ never matches /var/www/html2).
     * Empty for sources that don't report their paths.
     */
    public static function path_pairs( $source ) {
        $source = (array) $source;
        $pairs  = [];
        foreach ( [ 'abspath' => ABSPATH, 'content_dir' => WP_CONTENT_DIR ] as $key => $here ) {
            $there = trim( (string) ( $source[ $key ] ?? '' ) );
            if ( strlen( $there ) < 4 || strpos( $there, '/' ) === false || ! self::safe_source_path( $there ) ) {
                continue;
            }
            $there = rtrim( $there, '/' ) . '/';
            $here  = rtrim( $here, '/' ) . '/';
            if ( $there !== $here ) {
                $pairs[ $there ] = $here;
            }
        }
        // The content folder inside the web root moves with it.
        foreach ( $pairs as $there => $here ) {
            foreach ( $pairs as $outer => $outer_here ) {
                if ( $outer !== $there && strpos( $there, $outer ) === 0 && $outer_here . substr( $there, strlen( $outer ) ) === $here ) {
                    unset( $pairs[ $there ] );
                }
            }
        }
        return $pairs;
    }

    /**
     * The multisite define() lines from the staged wp-config.php, or [] when
     * the source isn't a network. A restore keeps the destination's own
     * wp-config.php, so these have to be added there by hand.
     */
    private function staged_multisite_constants() {
        foreach ( [ 'public/wp-config.php', 'wp-config.php' ] as $rel ) {
            $file = self::staged_file( $this->staging_path, $rel );
            if ( ! is_file( $file ) ) {
                continue;
            }
            $config = substr( (string) self::read_protected( $file ), 0, 262144 );
            if ( ! preg_match( '/define\(\s*[\'"]MULTISITE[\'"]\s*,\s*true\s*\)/i', $config ) ) {
                return [];
            }
            preg_match_all( '/^\s*define\(\s*[\'"](WP_ALLOW_MULTISITE|MULTISITE|SUBDOMAIN_INSTALL|DOMAIN_CURRENT_SITE|PATH_CURRENT_SITE|SITE_ID_CURRENT_SITE|BLOG_ID_CURRENT_SITE|SUNRISE|NOBLOGREDIRECT|COOKIE_DOMAIN)[\'"].*?\);/mi', $config, $m );
            return array_map( 'trim', $m[0] );
        }
        return [];
    }

    /**
     * Counts files currently staged for deployment.
     */
    private function count_staged_files() {
        $count = 0;
        if ( is_dir( $this->staging_path ) ) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator( $this->staging_path, \RecursiveDirectoryIterator::SKIP_DOTS )
            );
            foreach ( $it as $file ) {
                if ( $file->isFile() ) {
                    $count++;
                }
            }
        }
        return $count;
    }

    /**
     * Receives a chunk of a large file. Reassembles when all chunks arrive.
     */
    public function upload_chunk( $uploaded_file, $file_path, $chunk_index, $total_chunks ) {
        if ( $refused = $this->refused() ) {
            return $refused;
        }
        if ( ! self::is_safe_relative_path( $file_path ) ) {
            return new \WP_Error( 'invalid_path', 'Invalid file path.', [ 'status' => 400 ] );
        }
        $this->prepare_import_dir();
        if ( ! is_dir( $this->staging_path ) ) {
            mkdir( $this->staging_path, 0755, true );
        }

        $chunk_dir = $this->staging_path . '/_chunks/' . md5( $file_path );
        if ( ! is_dir( $chunk_dir ) ) {
            mkdir( $chunk_dir, 0755, true );
        }

        $chunk_file = $chunk_dir . '/chunk-' . str_pad( $chunk_index, 6, '0', STR_PAD_LEFT );
        move_uploaded_file( $uploaded_file['tmp_name'], $chunk_file );

        // Check if all chunks are present
        $existing_chunks = glob( $chunk_dir . '/chunk-*' );
        if ( count( $existing_chunks ) < $total_chunks ) {
            return [
                'success'         => true,
                'chunks_received' => count( $existing_chunks ),
                'total_chunks'    => $total_chunks,
                'complete'        => false,
            ];
        }

        // Reassemble
        $final_path = $this->staging_path . '/' . self::staged_name( $file_path );
        $final_dir  = dirname( $final_path );
        if ( ! is_dir( $final_dir ) ) {
            mkdir( $final_dir, 0755, true );
        }
        $fp         = fopen( $final_path, 'wb' );
        if ( ! $fp ) {
            return new \WP_Error( 'write_failed', 'Could not create final file.', [ 'status' => 500 ] );
        }
        if ( self::staged_name( $file_path ) !== $file_path ) {
            fwrite( $fp, self::PHP_EXIT );
        }

        sort( $existing_chunks );
        foreach ( $existing_chunks as $chunk ) {
            fwrite( $fp, file_get_contents( $chunk ) );
            @unlink( $chunk );
        }
        fclose( $fp );
        @rmdir( $chunk_dir );

        return [
            'success'  => true,
            'complete' => true,
            'file'     => $file_path,
            'size'     => filesize( $final_path ),
        ];
    }

    /**
     * Deploys files from staging to their final WordPress paths.
     * Backs up originals for rollback tracking.
     *
     * With a $budget (seconds, see Pace) and ["*"], it places files until the
     * budget runs out and answers { done: false }; the next call carries on
     * from the next file. Each file placed is journaled as it goes, so a
     * killed call loses nothing and a rollback still finds every file.
     */
    public function deploy_files( $files, $keep_host_files = true, $budget = null ) {
        if ( $refused = $this->refused() ) {
            return $refused;
        }
        if ( $budget !== null && count( $files ) === 1 && $files[0] === '*' ) {
            return $this->deploy_files_paced( $keep_host_files, $budget );
        }
        $web_root  = dirname( WP_CONTENT_DIR );
        $deployed  = [];
        $errors    = [];
        $backed_up = [];
        $created   = [];
        $kept      = [];
        $own_kept  = 0; // files this site keeps its own copy of (see below)

        // The dashboard (thin client) can't list staged files, so it passes
        // ["*"] to deploy everything. A whole-snapshot zip nests the site under
        // public/, so treat that as the staging root when present.
        $staging_root = $this->staging_path;
        if ( count( $files ) === 1 && $files[0] === '*' ) {
            $staging_root = $this->staging_root();
            $files        = $this->list_staged_files( $staging_root );
        }

        // Into a subsite or tenant: each file goes to its folders, and a
        // plugin or theme already in a folder the sites share stays as it is.
        $target      = $this->target();
        $shared_kept = [];
        $pool_seen   = [];

        $count = 0;
        foreach ( $files as $relative_path ) {
            // Cancel stops here, but still records what was placed so far
            // (below), so the rollback removes it.
            if ( ++$count % 1000 === 0 && $this->cancelled() ) {
                $errors[] = 'Cancelled.';
                break;
            }
            $out = $this->place_file( $relative_path, $staging_root, $web_root, $target, $keep_host_files, $pool_seen );
            if ( isset( $out['d'] ) ) {
                $deployed[] = $out['d'];
            }
            if ( isset( $out['c'] ) ) {
                $created = array_merge( $created, (array) $out['c'] );
            }
            if ( isset( $out['b'] ) ) {
                $backed_up[] = $out['b'];
            }
            if ( isset( $out['e'] ) ) {
                $errors[] = $out['e'];
            }
            if ( ! empty( $out['k'] ) ) {
                $own_kept++;
            }
            if ( isset( $out['h'] ) ) {
                $kept[ $out['h'] ] = true;
            }
            if ( isset( $out['s'] ) ) {
                $shared_kept[ $out['s'] ] = true;
            }
        }
        return $this->deploy_finish( $web_root, $target, $keep_host_files, $deployed, $backed_up, $errors, $own_kept, $kept, $shared_kept, $created );
    }

    /** Where staged files sit: staging, or its public/ folder when a whole-snapshot zip nests the site there. */
    private function staging_root() {
        // A pull stages under public/ too, with its source record, even when
        // a catch-up brings no files (then there's no wp-content to see).
        if ( is_dir( $this->staging_path . '/public/wp-includes' ) || is_dir( $this->staging_path . '/public/wp-content' ) || is_file( $this->staging_path . '/public/disembark-source.json' ) ) {
            return $this->staging_path . '/public';
        }
        return $this->staging_path;
    }

    /**
     * Places one staged file. Returns what happened: d (deployed path),
     * b (backed up first), e (error), k (this site keeps its own copy),
     * h (a host file kept), s (a shared plugin or theme folder kept).
     * $resumed: the first file after a killed call, which may have been
     * moved already.
     */
    private function place_file( $relative_path, $staging_root, $web_root, $target, $keep_host_files, &$pool_seen, $resumed = false ) {
        if ( ! self::is_safe_relative_path( $relative_path ) ) {
            return [ 'e' => "Unsafe path skipped: {$relative_path}" ];
        }
        Pace::tick();
        $relative_path = self::normalize_rel( $relative_path );

        $source = self::staged_file( $staging_root, $relative_path );
        $dest   = $web_root . '/' . $relative_path;
        if ( $target ) {
            if ( self::never_deploy( $relative_path ) ) {
                return [];
            }
            // A dedicated WP Freighter tenant has its own mu-plugins and
            // drop-ins folder, but the source host's (its Kinsta mu-plugin,
            // a drop-in pointing at its object cache) don't belong here
            // either: the tenant gets this install's own (see Target::finish()).
            if ( $keep_host_files ) {
                $host = self::host_file( $relative_path, $web_root );
                if ( $host !== '' ) {
                    return [ 'h' => $host, 'k' => 1 ];
                }
            }
            $to = $target->dest( $relative_path );
            if ( $to === null ) {
                return [];
            }
            list( $mapped, $may_replace ) = $to;
            $pool = $target->pool_folder( $mapped );
            if ( $pool !== '' ) {
                if ( ! isset( $pool_seen[ $pool ] ) ) {
                    $pool_seen[ $pool ] = is_dir( $web_root . '/' . $pool );
                }
                if ( $pool_seen[ $pool ] ) {
                    return [ 's' => $pool ];
                }
            } elseif ( ! $may_replace && file_exists( $web_root . '/' . $mapped ) ) {
                return [];
            }
            $relative_path = $mapped;
            $dest          = $web_root . '/' . $mapped;
        }

        if ( ! file_exists( $source ) ) {
            // A killed call moved it, then died before saying so.
            if ( $resumed && file_exists( $dest ) ) {
                $out = [ 'd' => $relative_path ];
                if ( file_exists( $this->rollback_file( $relative_path ) ) ) {
                    $out['b'] = $relative_path;
                }
                return $out;
            }
            return [ 'e' => "Source not found: {$relative_path}" ];
        }

        // Skip wp-config.php (in any case: one disk is case-insensitive)
        if ( strtolower( basename( $relative_path ) ) === 'wp-config.php' ) {
            return [];
        }
        // Never put a database dump (or Disembark's own working files) in
        // the web root, even when a client lists it explicitly.
        if ( self::never_deploy( $relative_path ) ) {
            return self::own_plugin_path( $relative_path ) ? [ 'k' => 1 ] : [];
        }
        // Leave the destination's host setup alone: its mu-plugins and
        // cache drop-ins belong to the server, not the site being moved.
        if ( $keep_host_files ) {
            $host = self::host_file( $relative_path, $web_root );
            if ( $host !== '' ) {
                return [ 'h' => $host, 'k' => 1 ];
            }
        }

        $out = [];
        // The original goes into the restore point first. A file that
        // can't be saved there whole isn't replaced.
        if ( file_exists( $dest ) ) {
            if ( ! $this->save_original( $dest, $relative_path ) ) {
                return [ 'e' => "Could not save {$relative_path} in the restore point (is the disk full?), so it was left as it was." ];
            }
            $out['b'] = $relative_path;
        }

        // Create destination directory, remembering each folder it adds
        // (a rollback removes those, and only those, once they're empty).
        $dest_dir = dirname( $dest );
        if ( ! is_dir( $dest_dir ) ) {
            $created = [];
            for ( $d = dirname( $relative_path ); $d !== '.' && $d !== '' && ! is_dir( $web_root . '/' . $d ); $d = dirname( $d ) ) {
                $created[] = $d;
            }
            mkdir( $dest_dir, 0755, true );
            if ( $created ) {
                $out['c'] = $created;
            }
        }

        // Move file (a staged name gets its real one back)
        if ( self::take_staged( $source, $dest ) ) {
            $out['d'] = $relative_path;
        } else {
            $out['e'] = "Failed to deploy: {$relative_path}";
        }
        return $out;
    }

    /** Paced deploy (see deploy_files()). */
    private function deploy_files_paced( $keep_host_files, $budget ) {
        $deadline  = Pace::deadline( $budget );
        $web_root  = dirname( WP_CONTENT_DIR );
        $list_file = $this->import_path . '/deploy-list.php';
        $journal_f = $this->import_path . '/deploy-journal.php';
        $list      = json_decode( (string) self::read_protected( $list_file ), true );
        if ( ! is_array( $list ) ) {
            $root = $this->staging_root();
            $list = [ 'root' => $root, 'files' => $this->list_staged_files( $root ) ];
            @unlink( $journal_f );
            $this->prepare_import_dir();
            self::write_protected( $list_file, (string) wp_json_encode( $list ) );
        }
        $files   = (array) $list['files'];
        $total   = count( $files );
        $last    = Pace::journal_last( $journal_f );
        $i       = $last ? (int) $last['i'] + 1 : 0;
        $resumed = $last !== null || $i === 0 && is_file( $journal_f );
        $target  = $this->target();
        $journal = Pace::journal_open( $journal_f );
        $pool_seen = [];
        $first   = true;
        $stopped = false;
        for ( ; $i < $total; $i++ ) {
            if ( ! $first && ( microtime( true ) >= $deadline || ( $i % 1000 === 0 && $this->cancelled() ) ) ) {
                $stopped = true;
                break;
            }
            $out = $this->place_file( $files[ $i ], $list['root'], $web_root, $target, $keep_host_files, $pool_seen, $first && $resumed );
            $first = false;
            Pace::journal_add( $journal, [ 'i' => $i ] + $out );
        }
        fclose( $journal );
        if ( $stopped && ! $this->cancelled() ) {
            return [ 'done' => false, 'placed' => $i, 'total' => $total, 'errors' => [] ];
        }

        // Every file has been seen (or the move was cancelled): add up the journal.
        $deployed = $backed_up = $errors = $created = [];
        $own_kept = 0;
        $kept = $shared_kept = [];
        if ( $stopped ) {
            $errors[] = 'Cancelled.';
        }
        foreach ( Pace::journal_read( $journal_f ) as $rec ) {
            if ( isset( $rec['d'] ) ) {
                $deployed[] = (string) $rec['d'];
            }
            if ( isset( $rec['b'] ) ) {
                $backed_up[] = (string) $rec['b'];
            }
            if ( isset( $rec['e'] ) ) {
                $errors[] = (string) $rec['e'];
            }
            if ( isset( $rec['c'] ) ) {
                $created = array_merge( $created, (array) $rec['c'] );
            }
            if ( ! empty( $rec['k'] ) ) {
                $own_kept++;
            }
            if ( isset( $rec['h'] ) ) {
                $kept[ (string) $rec['h'] ] = true;
            }
            if ( isset( $rec['s'] ) ) {
                $shared_kept[ (string) $rec['s'] ] = true;
            }
        }
        $result = $this->deploy_finish( $web_root, $target, $keep_host_files, array_values( array_unique( $deployed ) ), array_values( array_unique( $backed_up ) ), $errors, $own_kept, $kept, $shared_kept, $created );
        @unlink( $journal_f );
        @unlink( $list_file );
        $result['done'] = true;
        return $result;
    }

    /**
     * Folds a paced deploy's journal into the restore point (a move that
     * stopped mid-deploy), so a rollback finds every file it placed.
     */
    private function merge_deploy_journal() {
        $journal_f = $this->import_path . '/deploy-journal.php';
        if ( ! is_file( $journal_f ) ) {
            return;
        }
        $meta = self::rollback_meta( $this->rollback_path );
        if ( $meta === null ) {
            return;
        }
        foreach ( Pace::journal_read( $journal_f ) as $rec ) {
            if ( isset( $rec['d'] ) ) {
                $meta['deployed_files'][] = (string) $rec['d'];
            }
            if ( isset( $rec['b'] ) ) {
                $meta['backed_up_files'][] = (string) $rec['b'];
            }
            if ( isset( $rec['c'] ) ) {
                $meta['created_dirs'] = array_merge( (array) ( $meta['created_dirs'] ?? [] ), (array) $rec['c'] );
            }
        }
        if ( isset( $meta['created_dirs'] ) ) {
            $meta['created_dirs'] = array_values( array_unique( $meta['created_dirs'] ) );
        }
        $meta['deployed_files']  = array_values( array_unique( (array) ( $meta['deployed_files'] ?? [] ) ) );
        $meta['backed_up_files'] = array_values( array_unique( (array) ( $meta['backed_up_files'] ?? [] ) ) );
        if ( $this->save_rollback_meta( $meta ) ) {
            @unlink( $journal_f );
            @unlink( $this->import_path . '/deploy-list.php' );
        }
    }

    /** The end of a deploy: a catch-up's removals, the restore point's lists, and the answer. */
    private function deploy_finish( $web_root, $target, $keep_host_files, $deployed, $backed_up, $errors, $own_kept, $kept, $shared_kept, $created = [] ) {
        // A catch-up pull removes files that were brought by the last pull
        // and are gone from the source now. Each is backed up first, so a
        // rollback puts it back.
        $removed   = 0;
        $delete    = $this->import_path . '/delete-list.json';
        if ( is_file( $delete ) && ! $this->cancelled() && ! $target ) {
            foreach ( (array) json_decode( (string) file_get_contents( $delete ), true ) as $relative_path ) {
                $relative_path = (string) $relative_path;
                if ( ! self::is_safe_relative_path( $relative_path ) ) {
                    continue;
                }
                $relative_path = self::normalize_rel( $relative_path );
                if ( strtolower( basename( $relative_path ) ) === 'wp-config.php' || self::never_deploy( $relative_path ) ) {
                    continue;
                }
                if ( $keep_host_files && self::host_file( $relative_path, $web_root ) !== '' ) {
                    continue;
                }
                $dest = $web_root . '/' . $relative_path;
                if ( ! is_file( $dest ) ) {
                    continue;
                }
                if ( ! in_array( $relative_path, $backed_up, true ) && $this->save_original( $dest, $relative_path ) ) {
                    $backed_up[] = $relative_path;
                }
                if ( in_array( $relative_path, $backed_up, true ) && @unlink( $dest ) ) {
                    $removed++;
                }
            }
            @unlink( $delete );
        }

        // Update rollback metadata with deployed files
        $meta = self::rollback_meta( $this->rollback_path );
        if ( $meta !== null ) {
            $meta['deployed_files']  = array_values( array_unique( array_merge( $meta['deployed_files'] ?? [], $deployed ) ) );
            $meta['backed_up_files'] = array_values( array_unique( array_merge( $meta['backed_up_files'] ?? [], $backed_up ) ) );
            $meta['created_dirs']    = array_values( array_unique( array_merge( (array) ( $meta['created_dirs'] ?? [] ), (array) $created ) ) );
            $this->save_rollback_meta( $meta );
        }

        return [
            // Files this site deliberately keeps its own copy of (the
            // Disembark running this move, the host's mu-plugins and
            // drop-ins) count as in place: the CLI checks that every file
            // it sent is, and stops the restore otherwise.
            'deployed'        => count( $deployed ) + $own_kept,
            'kept_own'        => $own_kept,
            'errors'          => $errors,
            'host_files_kept' => array_keys( $kept ),
            'loaders_disabled' => self::disable_missing_prepends( $web_root, $deployed ),
            'removed'         => $removed,
            'shared_kept'     => array_keys( $shared_kept ),
            // The next steps run in new requests that load the files just
            // placed; a host that never rechecks file times would otherwise
            // keep running the old code.
            'opcache_reset'   => self::reset_opcache(),
        ];
    }

    private static function reset_opcache() {
        if ( ! function_exists( 'opcache_reset' ) || ! filter_var( ini_get( 'opcache.enable' ), FILTER_VALIDATE_BOOLEAN ) ) {
            return false;
        }
        return (bool) @opcache_reset();
    }

    /**
     * A firewall loader (Wordfence, NinjaFirewall, Sucuri…) is wired in with
     * auto_prepend_file in .user.ini, php.ini or .htaccess, as an absolute
     * path on the old server. Brought over as is, PHP fails to open that file
     * on every request and the whole site is down. In the root config files
     * this move deployed, a prepend whose file doesn't exist here is
     * commented out. Returns "file: path" for each one.
     */
    private static function disable_missing_prepends( $web_root, $deployed ) {
        $done = [];
        foreach ( [ '.user.ini', 'php.ini', '.htaccess' ] as $name ) {
            $file = $web_root . '/' . $name;
            if ( ! in_array( $name, (array) $deployed, true ) || ! is_file( $file ) ) {
                continue;
            }
            $lines   = file( $file );
            $comment = $name === '.htaccess' ? '# ' : '; ';
            $changed = false;
            foreach ( (array) $lines as $i => $line ) {
                if ( ! preg_match( '/^\s*(?:php_value\s+)?auto_prepend_file\s*=?\s*["\']?([^"\'\s]+)["\']?/i', $line, $m ) ) {
                    continue;
                }
                $path = $m[1];
                if ( strtolower( $path ) === 'none' || is_file( $path ) ) {
                    continue;
                }
                $lines[ $i ] = $comment . 'Disabled by Disembark (file not on this server): ' . ltrim( $line );
                $changed     = true;
                $done[]      = "{$name}: {$path}";
            }
            if ( $changed ) {
                file_put_contents( $file, implode( '', $lines ) );
            }
        }
        return $done;
    }

    /**
     * Names of mu-plugins that hosts install for their own platform. A site
     * leaving one of these hosts carries them along, and they don't belong
     * (or work) anywhere else. Matched against the first path segment under
     * mu-plugins/, case-insensitively, as a prefix.
     */
    /** Table names (after the prefix) that hosting platforms keep for themselves: never dropped by a move. */
    const HOST_TABLES = [ 'patchstack_', 'kinsta_', 'wpe_', 'wpengine_', 'sg_security_', 'gd_system_' ];

    /**
     * Options that belong to the host, not the site, by name prefix: Kinsta's
     * Patchstack firewall keeps its settings and the site's API token under
     * patchstack_ (webarx_ on installs from before Patchstack's rename). Like the host's mu-plugins and tables, they stay with the
     * server: a move keeps the destination's own and leaves the source's
     * behind (see keep_install_rows()), so a secret never travels to every
     * copy.
     */
    const HOST_OPTIONS = [ 'patchstack_', 'webarx_' ];

    const HOST_MU_PLUGINS = [
        'wpengine', 'wpe-', 'mu-plugin.php', 'slt-force-strong-passwords', 'force-strong-passwords', 'stop-long-comments',
        'kinsta-mu-plugins', 'gd-system-plugin', 'pantheon', 'wpcomsh', 'endurance-', 'flywheel', 'pressable',
        'sg-security', 'sg-cachepress', 'wp-cloudflare-page-cache', 'liquidweb', 'nexcess', 'cloudways', 'bluehost',
        'dreamhost', 'hostinger', 'ionos',
        // CaptainCore (Anchor Hosting): its login helper and the site's
        // Fathom analytics, which would count a copy's visits as the real site's.
        'captaincore-',
    ];

    /**
     * When $relative_path is part of the destination's host setup, the name
     * to report it under; '' when it should be deployed. Covers:
     * - mu-plugins the destination already has (its whole entry is kept),
     * - mu-plugins known to belong to a hosting platform,
     * - the cache and database drop-ins (object-cache.php, advanced-cache.php,
     *   db.php), which point at the source host's services.
     */
    private static function host_file( $relative_path, $web_root ) {
        $rel = ltrim( str_replace( '\\', '/', (string) $relative_path ), '/' );
        foreach ( [ 'object-cache.php', 'advanced-cache.php', 'db.php' ] as $dropin ) {
            if ( $rel === 'wp-content/' . $dropin ) {
                return $rel;
            }
        }
        $mu = 'wp-content/mu-plugins/';
        if ( strpos( $rel, $mu ) !== 0 ) {
            return '';
        }
        $top = strtok( substr( $rel, strlen( $mu ) ), '/' );
        if ( $top === false || $top === '' ) {
            return '';
        }
        if ( file_exists( $web_root . '/' . $mu . $top ) ) {
            return $mu . $top;
        }
        foreach ( self::HOST_MU_PLUGINS as $name ) {
            if ( stripos( $top, $name ) === 0 ) {
                return $mu . $top;
            }
        }
        return '';
    }

    /**
     * Applies restore transformations to already-split statements: remaps the
     * table-name prefix and injects a DROP TABLE before each CREATE TABLE.
     * Both operate on the statement's leading keyword + identifier only —
     * running them over the whole dump would also rewrite INSERT *values*
     * (a post containing `wp_options` or the text "CREATE TABLE" would be
     * mangled, and a prefix-length change would break serialized data).
     * Prefix-scoped values inside the data are handled after import by
     * remap_table_prefix(), which re-serializes safely.
     */
    /** The incoming users of a whole-site move into a network's main site wait under {base}{this}users (see import_staged_sql()). */
    const INCOMING_USERS = 'dskin_';

    /** The table a CREATE, INSERT, ALTER, DROP, TRUNCATE or LOCK statement names, or null. */
    private static function statement_table( $statement ) {
        if ( preg_match( '/^(?:\/\*!\d+\s+)?(?:CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|INSERT(?:\s+(?:IGNORE|DELAYED|LOW_PRIORITY|HIGH_PRIORITY))*\s+INTO|REPLACE(?:\s+(?:DELAYED|LOW_PRIORITY))*\s+INTO|ALTER\s+TABLE|DROP\s+TABLE(?:\s+IF\s+EXISTS)?|TRUNCATE(?:\s+TABLE)?|LOCK\s+TABLES)\s+`((?:[^`]|``)+)`/i', ltrim( (string) $statement ), $m ) ) {
            return str_replace( '``', '`', $m[1] );
        }
        return null;
    }

    /** $statement with the table it names renamed per $map (old name => new name). */
    private static function rename_statement_table( $statement, $map ) {
        if ( ! $map ) {
            return $statement;
        }
        return preg_replace_callback( '/^(\s*(?:\/\*!\d+\s+)?(?:CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|INSERT(?:\s+(?:IGNORE|DELAYED|LOW_PRIORITY|HIGH_PRIORITY))*\s+INTO|REPLACE(?:\s+(?:DELAYED|LOW_PRIORITY))*\s+INTO|ALTER\s+TABLE|DROP\s+TABLE(?:\s+IF\s+EXISTS)?|TRUNCATE(?:\s+TABLE)?|LOCK\s+TABLES)\s+`)((?:[^`]|``)+)`/i', function ( $m ) use ( $map ) {
            $name = str_replace( '``', '`', $m[2] );
            return $m[1] . str_replace( '`', '``', $map[ $name ] ?? $name ) . '`';
        }, (string) $statement, 1 );
    }

    /**
     * Whether $table belongs to another site of the install this site is
     * part of, or to the install as a whole: another subsite's (wp_5_*),
     * the network's own (blogs, site, sitemeta, signups, users and the
     * like), another WP Freighter tenant's (stacked_8_*), or, from a
     * tenant, the main site's. False on a plain site.
     */
    public static function other_sites_table( $table ) {
        global $wpdb;
        $lower  = strtolower( (string) $table );
        $prefix = strtolower( (string) $wpdb->prefix );
        if ( is_multisite() ) {
            $base = strtolower( $wpdb->base_prefix );
            if ( $prefix !== $base ) {
                // A subsite: its own prefix is its own, the rest of the
                // network's base prefix isn't.
                return strpos( $lower, $prefix ) !== 0 && strpos( $lower, $base ) === 0;
            }
            if ( strpos( $lower, $base ) !== 0 ) {
                return false;
            }
            $rest = substr( $lower, strlen( $base ) );
            return (bool) preg_match( '/^\d+_/', $rest ) || in_array( $rest, array_merge( (array) $wpdb->global_tables, (array) $wpdb->ms_global_tables ), true );
        }
        $primary = strtolower( Scope::freighter_primary() );
        if ( $primary === '' ) {
            return false;
        }
        if ( preg_match( '/^stacked_(\d+)_/', $lower, $m ) ) {
            return 'stacked_' . $m[1] . '_' !== $prefix;
        }
        return $prefix !== $primary && strpos( $lower, $primary ) === 0;
    }

    private static function transform_statements( $statements, $old_prefix = '', $new_prefix = '' ) {
        $remap = ( $old_prefix !== '' && $new_prefix !== '' && $old_prefix !== $new_prefix );
        $out   = [];
        foreach ( $statements as $statement ) {
            // mysqldump puts "-- ..." comment lines in front of DROP TABLE and
            // LOCK TABLES. The statement anchors below would miss those, so
            // leading comments go first (executable /*!...*/ ones are kept).
            $statement = self::strip_leading_comments( $statement );
            if ( $remap ) {
                $statement = preg_replace_callback(
                    '/^((?:\/\*!\d+\s+)?(?:CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|INSERT\s+INTO|REPLACE\s+INTO|ALTER\s+TABLE|LOCK\s+TABLES|DROP\s+TABLE(?:\s+IF\s+EXISTS)?|TRUNCATE\s+TABLE)\s+`)' . preg_quote( $old_prefix, '/' ) . '/i',
                    function ( $m ) use ( $new_prefix ) {
                        return $m[1] . $new_prefix;
                    },
                    $statement
                );
                // Foreign keys name their parent table too.
                if ( preg_match( '/^(?:\/\*!\d+\s+)?(?:CREATE|ALTER)\s+TABLE/i', $statement ) ) {
                    $statement = preg_replace( '/(\bREFERENCES\s+`)' . preg_quote( $old_prefix, '/' ) . '/i', '${1}' . str_replace( [ '\\', '$' ], [ '\\\\', '\\$' ], $new_prefix ), $statement );
                }
            }
            if ( preg_match( '/^CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`([^`]+)`/i', $statement, $m ) ) {
                $out[] = 'DROP TABLE IF EXISTS `' . $m[1] . '`';
            }
            $out[] = $statement;
        }
        return $out;
    }

    /**
     * Drops "-- ", "#" and plain block comments (and whitespace) from the start
     * of a statement. MySQL executable comments (slash-star-bang, such as
     * the 40101 SET lines in mysqldump output) are kept: they are the statement.
     */
    private static function strip_leading_comments( $statement ) {
        $i   = 0;
        $len = strlen( $statement );
        while ( $i < $len ) {
            $c = $statement[ $i ];
            if ( $c === ' ' || $c === "\t" || $c === "\n" || $c === "\r" ) {
                $i++;
            } elseif ( $c === '#' || ( $c === '-' && substr( $statement, $i, 2 ) === '--' && ( $i + 2 >= $len || strpos( " \t\r\n", $statement[ $i + 2 ] ) !== false ) ) ) {
                $nl = strpos( $statement, "\n", $i );
                if ( $nl === false ) {
                    return '';
                }
                $i = $nl + 1;
            } elseif ( $c === '/' && substr( $statement, $i, 2 ) === '/*' && substr( $statement, $i, 3 ) !== '/*!' ) {
                $end = strpos( $statement, '*/', $i + 2 );
                if ( $end === false ) {
                    return '';
                }
                $i = $end + 2;
            } else {
                break;
            }
        }
        return $i ? substr( $statement, $i ) : $statement;
    }

    /**
     * Into a subsite or tenant, the dump may only touch the incoming site's
     * own tables: everything else in the install belongs to other sites (and
     * on a network, wp_users and wp_sitemeta decide who is super admin). So
     * this is an allowlist, not a filter: CREATE TABLE, INSERT [IGNORE] INTO,
     * REPLACE INTO, ALTER TABLE, DROP TABLE / DROP VIEW and LOCK TABLES on ONE
     * backticked name (no database. qualifier) starting with the source's
     * prefix, UNLOCK TABLES, and SET of session settings that can't change
     * how later statements are read. A statement wrapped whole in an
     * executable comment is judged by what's inside it. Anything that reads
     * another table (a SELECT anywhere), renames one, or reaches outside the
     * database is refused too.
     *
     * Returns 'ok', 'foreign' (a table without the source's prefix, left
     * out), 'foreign_create' (the same, its CREATE), 'skip' (mysqldump's
     * own session bookkeeping, left out without a count; see
     * dump_session_restore()) or 'refused'.
     */
    private static function target_statement( $statement, $prefix ) {
        $sql = trim( self::strip_leading_comments( $statement ) );
        if ( $sql === '' ) {
            return 'ok';
        }
        if ( preg_match( '/^\/\*M?!\d*\s?(.*)\*\/$/s', $sql, $m ) && strpos( $m[1], '*/' ) === false ) {
            $sql = trim( $m[1] );
        }
        $ident = '`((?:[^`]|``)+)`';
        $heads = [
            'create' => '/^CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+' . $ident . '(?!\s*\.)/i',
            'insert' => '/^(?:INSERT(?:\s+IGNORE)?|REPLACE)\s+INTO\s+' . $ident . '(?!\s*\.)/i',
            'alter'  => '/^ALTER\s+TABLE\s+' . $ident . '(?!\s*\.)/i',
            'drop'   => '/^DROP\s+(?:TABLE|VIEW)(?:\s+IF\s+EXISTS)?\s+' . $ident . '\s*$/i',
            'lock'   => '/^LOCK\s+TABLES\s+' . $ident . '\s+(?:READ(?:\s+LOCAL)?|(?:LOW_PRIORITY\s+)?WRITE)\s*$/i',
        ];
        foreach ( $heads as $kind => $pattern ) {
            if ( ! preg_match( $pattern, $sql, $m ) ) {
                continue;
            }
            $rest = self::sql_skeleton( (string) substr( $sql, strlen( $m[0] ) ) );
            if ( $rest === null || strpos( $m[1], '``' ) !== false ) {
                return 'refused';
            }
            if ( in_array( $kind, [ 'create', 'insert', 'alter' ], true ) ) {
                // Nothing that reads, renames or swaps another table, runs a
                // routine, or reaches outside the database or onto the disk.
                if ( preg_match( '/\b(SELECT|UNION|RENAME|LIKE|EXCHANGE|TABLE|TABLESPACE|DISCARD|OUTFILE|DUMPFILE|LOAD_FILE|DIRECTORY|CONNECTION|TABLE_TYPE|TABNAME|FILE_NAME|TRIGGER|PROCEDURE|FUNCTION|EVENT|GRANT|HANDLER|CALL|EXECUTE|PREPARE|DATABASE|SCHEMA|INTO)\b|;|`i`\s*\.|\.\s*`i`/i', $rest ) ) {
                    return 'refused';
                }
            }
            if ( strpos( $m[1], $prefix ) !== 0 ) {
                return $kind === 'create' ? 'foreign_create' : 'foreign';
            }
            return 'ok';
        }
        if ( preg_match( '/^UNLOCK\s+TABLES\s*$/i', $sql ) ) {
            return 'ok';
        }
        if ( preg_match( '/^SET\s/i', $sql ) ) {
            if ( self::target_set_allowed( $sql ) ) {
                return 'ok';
            }
            return self::dump_session_restore( $sql ) ? 'skip' : 'refused';
        }
        return 'refused';
    }

    /**
     * mysqldump's own bookkeeping around a dump: the header that saves the
     * session's settings and sets sql_mode to NO_AUTO_VALUE_ON_ZERO, and
     * the trailer (and the line after each CREATE TABLE) that puts saved
     * values back from @OLD_* / @saved_* variables. target_set_allowed()
     * refuses the sql_mode, character_set_client and collation_connection
     * ones, so they never run into a subsite or tenant; they're left out
     * without counting them as refused, since every dump has them. Only
     * those exact shapes: anything else in the same SET is still refused.
     */
    /** mysqldump's line that puts a session setting back from a variable it saved (see dump_session_restore()). */
    private static function restores_saved_setting( $statement ) {
        if ( stripos( $statement, '@saved_' ) === false && stripos( $statement, '@OLD_' ) === false ) {
            return false;
        }
        $sql = trim( self::strip_leading_comments( $statement ) );
        if ( preg_match( '/^\/\*M?!\d*\s?(.*)\*\/$/s', $sql, $m ) && strpos( $m[1], '*/' ) === false ) {
            $sql = trim( $m[1] );
        }
        return self::dump_session_restore( $sql ) && preg_match( '/:?=\s*@(?:OLD|saved)_/i', $sql );
    }

    private static function dump_session_restore( $sql ) {
        $names = '(?:sql_mode|character_set_client|character_set_results|collation_connection|foreign_key_checks|unique_checks|sql_notes|note_verbosity|time_zone)';
        $part  = '(?:(?:@@(?:SESSION\.|LOCAL\.)?|(?:SESSION|LOCAL)\s+)?' . $names . '\s*:?=\s*@(?:OLD|saved)_[A-Za-z_]+'
            . '|@OLD_[A-Za-z_]+\s*:?=\s*@@(?:SESSION\.)?' . $names
            . '|sql_mode\s*:?=\s*(?:\'NO_AUTO_VALUE_ON_ZERO\'|"NO_AUTO_VALUE_ON_ZERO"))';
        return (bool) preg_match( '/^SET\s+' . $part . '(?:\s*,\s*' . $part . ')*\s*;?\s*$/i', trim( $sql ) );
    }

    /**
     * The first word of a statement, past comments and the opening of an
     * executable comment (uppercase; '' when there's none). Cheap: it looks
     * no further than the start, so every row of a dump can be asked.
     */
    private static function statement_word( $sql ) {
        // Most statements start with their word.
        $n = strspn( $sql, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz_' );
        if ( $n > 0 ) {
            return strtoupper( substr( $sql, 0, $n ) );
        }
        $i   = 0;
        $len = strlen( $sql );
        while ( $i < $len && preg_match( '/\G(?:\s+|(?:--(?=\s)|#)[^\n]*\n?|\/\*M?!\d*|\/\*(?!M?!).*?\*\/)/s', $sql, $m, 0, $i ) && $m[0] !== '' ) {
            $i += strlen( $m[0] );
        }
        return preg_match( '/\G[A-Za-z_]+/', $sql, $m, 0, $i ) ? strtoupper( $m[0] ) : '';
    }

    /**
     * Statements no WordPress database needs, which reach past the site's
     * tables to the database server itself: installing server plugins or
     * libraries (INSTALL PLUGIN/SONAME, CREATE FUNCTION … SONAME), links to
     * other servers (CREATE SERVER), reading or writing files on it (LOAD
     * DATA/XML, LOAD_FILE(), INTO OUTFILE/DUMPFILE), accounts and privileges
     * (GRANT, REVOKE, CREATE/ALTER/DROP/RENAME USER or ROLE, SET PASSWORD or
     * ROLE), server-wide settings (SET GLOBAL/PERSIST, @@GLOBAL), server
     * administration (FLUSH, RESET, SHUTDOWN, KILL, BINLOG, PURGE LOGS),
     * replication (CHANGE MASTER/REPLICATION, START/STOP SLAVE/REPLICA),
     * other databases (CREATE/ALTER/DROP DATABASE or SCHEMA, USE of any but
     * this site's database), and, with $lock_prefix (the batch import runs
     * LOCK TABLES as sent), a lock on a table without that prefix.
     * Matched at the start of the statement and of each statement in a
     * trigger's, routine's or event's body (after BEGIN, DO, THEN, ELSE,
     * LOOP, REPEAT or ";"), and anywhere for SONAME, LOAD_FILE(), INTO
     * OUTFILE/DUMPFILE and global settings. $sql is sql_skeleton() with
     * names kept: strings are blanked, comments gone, executable comments
     * unwrapped, so case, spacing and comment wrapping don't hide anything.
     * The reason, or null.
     */
    private static function server_problem( $sql, $lock_prefix = null ) {
        $names = $sql;
        $sql   = preg_replace( '/`(?:[^`]|``)*`/', '`i`', $sql );
        $at    = '(?:^|;|\b(?:BEGIN|DO|THEN|ELSE|LOOP|REPEAT)\b)\s*';
        $rules = [
            'installs or removes a plugin or library in the database server (INSTALL PLUGIN, SONAME)' => '/' . $at . '(?:UN)?INSTALL\s+(?:PLUGIN|SONAME)\b|\bSONAME\b/i',
            'defines a link to another database server (CREATE SERVER)'                      => '/' . $at . '(?:CREATE|ALTER|DROP)\s+(?:OR\s+REPLACE\s+)?SERVER\b/i',
            'reads a file on the database server (LOAD DATA, LOAD XML, LOAD_FILE)'            => '/' . $at . 'LOAD\s+(?:DATA|XML)\b|\bLOAD_FILE\s*\(/i',
            'writes a file on the database server (INTO OUTFILE, INTO DUMPFILE)'             => '/\bINTO\s+(?:OUTFILE|DUMPFILE)\b/i',
            'changes database accounts or privileges (GRANT, REVOKE, USER, ROLE, PASSWORD)'  => '/' . $at . '(?:GRANT|REVOKE)\b|' . $at . '(?:CREATE|ALTER|DROP|RENAME)\s+(?:USER|ROLE)\b|' . $at . 'SET\s+(?:PASSWORD|(?:DEFAULT\s+)?ROLE)\b/i',
            'changes a setting for the whole database server (SET GLOBAL)'                   => '/@@(?:GLOBAL|PERSIST(?:_ONLY)?)\b|\b(?:GLOBAL|PERSIST|PERSIST_ONLY)\s+[A-Za-z_][\w.]*\s*:?=/i',
            'runs a database server command (FLUSH, RESET, SHUTDOWN, KILL, BINLOG, PURGE LOGS)' => '/' . $at . '(?:FLUSH|RESET|SHUTDOWN|KILL|BINLOG)\b|' . $at . 'PURGE\s+(?:BINARY|MASTER)\s+LOGS\b/i',
            'changes replication (CHANGE MASTER, START or STOP SLAVE)'                       => '/' . $at . 'CHANGE\s+(?:MASTER|REPLICATION)\b|' . $at . '(?:START|STOP)\s+(?:ALL\s+)?(?:SLAVES?|REPLICAS?)\b/i',
            'creates, changes or drops a whole database (CREATE DATABASE, DROP DATABASE)'    => '/' . $at . '(?:CREATE|ALTER|DROP)\s+(?:DATABASE|SCHEMA)\b/i',
        ];
        foreach ( $rules as $what => $rule ) {
            if ( preg_match( $rule, $sql ) ) {
                $more = strpos( $what, 'whole database' ) !== false ? ' (A dump made with mysqldump --databases or --all-databases does this: export just this site\'s database instead.)' : '';
                return "The incoming database has a statement that {$what}, which no WordPress database needs, so a move doesn't accept it.{$more} Nothing was imported from it.";
            }
        }
        if ( preg_match( '/^\s*USE\s+(`(?:[^`]|``)+`|[A-Za-z0-9_$]+)\s*$/i', $names, $m ) ) {
            $db = str_replace( '``', '`', trim( $m[1], '`' ) );
            if ( $db !== DB_NAME ) {
                return "The incoming database switches to another database ({$db}), so a move doesn't accept it. A dump made with mysqldump --databases or --all-databases does this: export just this site's database instead. Nothing was imported from it.";
            }
        } elseif ( preg_match( '/^\s*USE\b/i', $sql ) ) {
            return "The incoming database switches to another database, so a move doesn't accept it. Nothing was imported from it.";
        }
        if ( $lock_prefix !== null && $lock_prefix !== '' && preg_match( '/^\s*LOCK\s+TABLES?\s+(.+)$/is', $names, $m ) ) {
            foreach ( preg_split( '/\s*,\s*/', trim( $m[1] ) ) as $part ) {
                if ( preg_match( '/^(`(?:[^`]|``)+`|[A-Za-z0-9_$]+)/', $part, $t ) && stripos( str_replace( '``', '`', trim( $t[1], '`' ) ), $lock_prefix ) !== 0 ) {
                    return "The incoming database locks {$t[1]}, a table outside this site's own ({$lock_prefix}*), so a move doesn't accept it. Nothing was imported from it.";
                }
            }
        }
        return null;
    }

    /** Storage engines a dump may create tables with: none that reaches outside the database (FEDERATED, CONNECT, SPIDER, S3), reads other tables (MRG_MyISAM) or files on the server (CSV, CONNECT). */
    const ALLOWED_ENGINES = [ 'innodb', 'myisam', 'aria', 'memory', 'heap', 'archive', 'rocksdb', 'tokudb' ];

    /**
     * Why a dump's CREATE TABLE or ALTER TABLE mustn't run here, or null.
     * Refused in every import, not only into a subsite or tenant:
     * - a storage engine outside ALLOWED_ENGINES, or one given as a quoted
     *   or backticked name (MySQL takes ENGINE='CONNECT' too);
     * - a foreign key to a table in another database;
     * - from one of the incoming site's tables (named with $prefix, the
     *   source's prefix that the import remaps), a foreign key to a table
     *   without it. '' when the backup doesn't record its prefix: then only
     *   the other-database rule applies. A table of another prefix that a
     *   whole-site dump carries along (a second install in the source's
     *   database) may point at its own kind, as it may be created anyway;
     * - on a network's or WP Freighter install's main site ($shared; $old,
     *   $new: the prefix remap), a foreign key to another site's table or
     *   the network's (other_sites_table()).
     * The text is read the way MySQL lexes it (sql_skeleton()), so a
     * keyword inside a string or comment doesn't count, and one hidden in
     * an executable comment does.
     */
    private static function reach_problem( $statement, $prefix, $shared = false, $old = '', $new = '', $locks = false ) {
        $raw = trim( (string) $statement );
        // Rows, most of any dump, are read through only when one could
        // name a file (INSERT … SELECT LOAD_FILE(), … INTO OUTFILE).
        $word = self::statement_word( $raw );
        if ( ( $word === 'INSERT' || $word === 'REPLACE' ) && stripos( $raw, 'FILE' ) === false ) {
            return null;
        }
        $sql = self::sql_skeleton( $raw, true );
        if ( $sql === null ) {
            return "The incoming database has a statement this can't read safely (an executable comment inside another, or one that doesn't end), so a move doesn't accept it. Nothing was imported from it.";
        }
        if ( $why = self::server_problem( $sql, $locks ? (string) $prefix : null ) ) {
            return $why;
        }
        if ( ! preg_match( '/^\s*(CREATE\s+(?:TEMPORARY\s+)?TABLE|ALTER\s+(?:ONLINE\s+|IGNORE\s+)*TABLE)\b/i', $sql, $head ) ) {
            return null;
        }
        $name  = self::statement_table( $statement );
        $table = $name !== null ? $name : 'a table';
        $remap = function ( $t ) use ( $old, $new ) {
            return $old !== '' && $new !== '' && stripos( $t, $old ) === 0 ? $new . substr( $t, strlen( $old ) ) : $t;
        };
        if ( $shared && $name !== null && self::other_sites_table( $remap( $name ) ) ) {
            return null; // left out on the way in (see import_staged_sql())
        }
        $own = $prefix === '' || $name === null || stripos( $name, $prefix ) === 0;
        $ident = '(?:`(?:[^`]|``)+`|[A-Za-z0-9_$]+)';
        // Table options follow the column list; columns may be called "engine".
        $opts = $sql;
        if ( stripos( $head[1], 'CREATE' ) === 0 && ( $open = strpos( $sql, '(' ) ) !== false ) {
            $depth = 0;
            for ( $i = $open, $n = strlen( $sql ); $i < $n; $i++ ) {
                if ( $sql[ $i ] === '(' ) {
                    $depth++;
                } elseif ( $sql[ $i ] === ')' && --$depth === 0 ) {
                    $opts = substr( $sql, $i + 1 );
                    break;
                }
            }
        }
        if ( preg_match_all( '/\bENGINE\b\s*(?:=\s*)?(' . $ident . '|\'\'|\S+)/i', $opts, $engines ) ) {
            foreach ( $engines[1] as $engine ) {
                if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $engine ) ) {
                    return "The incoming database names the storage engine of {$table} in quotes, so this can't tell which one it is, and a move doesn't accept it. Nothing was imported from it.";
                }
                if ( ! in_array( strtolower( $engine ), self::ALLOWED_ENGINES, true ) ) {
                    return "The incoming database creates {$table} with the {$engine} storage engine, which a move doesn't accept (engines like it can reach other servers, files or tables; InnoDB, MyISAM, Aria, MEMORY, ARCHIVE, RocksDB and TokuDB are fine). Nothing was imported from it.";
                }
            }
        }
        if ( preg_match_all( '/\bREFERENCES\s+(' . $ident . ')(\s*\.\s*' . $ident . ')?/i', $sql, $refs, PREG_SET_ORDER ) ) {
            foreach ( $refs as $ref ) {
                $parent = str_replace( '``', '`', trim( $ref[1], '`' ) );
                if ( ! empty( $ref[2] ) ) {
                    return "The incoming database gives {$table} a foreign key to a table in another database ({$parent}." . trim( ltrim( trim( $ref[2] ), '.' ), " `" ) . "), so a move doesn't accept it. Nothing was imported from it.";
                }
                if ( $own && $prefix !== '' && stripos( $parent, $prefix ) !== 0 ) {
                    return "The incoming database gives {$table} a foreign key to {$parent}, a table outside the incoming site's own ({$prefix}*), so a move doesn't accept it. Nothing was imported from it.";
                }
                if ( $shared ) {
                    $mapped = $remap( $parent );
                    if ( self::other_sites_table( $mapped ) ) {
                        return "The incoming database gives {$table} a foreign key to {$mapped}, which here belongs to another site of this install or to the install itself, so a move doesn't accept it. Nothing was imported from it.";
                    }
                }
            }
        }
        return null;
    }

    /**
     * SET statements a dump may run into a subsite or tenant: user variables,
     * and session settings that can't change how the next statements are
     * parsed. Never sql_mode (NO_BACKSLASH_ESCAPES, ANSI_QUOTES) nor a
     * multibyte client character set: either would let a statement read
     * differently here than target_statement() read it.
     */
    private static function target_set_allowed( $sql ) {
        $safe_charset = '(?:utf8mb4|utf8mb3|utf8|latin1|ascii|binary)';
        if ( preg_match( '/^SET\s+(?:NAMES|CHARACTER\s+SET|CHARSET)\s+([\'"]?)' . $safe_charset . '\1(?:\s+COLLATE\s+([\'"]?)\w+\2)?\s*$/i', $sql ) ) {
            return true;
        }
        $skeleton = self::sql_skeleton( $sql );
        if ( $skeleton === null || ! preg_match( '/^SET\s+(.*)$/is', $skeleton, $m ) ) {
            return false;
        }
        if ( preg_match( '/\b(GLOBAL|PERSIST|PERSIST_ONLY|PASSWORD|ROLE|STATEMENT|FOR|SELECT|TRANSACTION|NAMES|CHARACTER|CHARSET)\b|@@(?:GLOBAL|PERSIST)|;/i', $m[1] ) ) {
            return false;
        }
        foreach ( explode( ',', $m[1] ) as $part ) {
            $part = trim( $part );
            // A user variable takes any plain value.
            if ( preg_match( '/^@[A-Za-z0-9_$.]+\s*:?=\s*(?:@@?(?:SESSION\.|LOCAL\.)?[A-Za-z0-9_$.]+|\'\'|-?[0-9.]+|NULL|TRUE|FALSE|ON|OFF|DEFAULT)$/i', $part ) ) {
                continue;
            }
            if ( preg_match( '/^(?:@@(?:SESSION\.|LOCAL\.)?|(?:SESSION|LOCAL)\s+)?(time_zone|foreign_key_checks|unique_checks|sql_notes|character_set_results)\s*:?=\s*(?:@[A-Za-z0-9_$.]+|\'\'|-?[0-9.]+|\w+)$/i', $part ) ) {
                continue;
            }
            if ( preg_match( '/^(?:@@(?:SESSION\.|LOCAL\.)?|(?:SESSION|LOCAL)\s+)?character_set_client\s*:?=\s*' . $safe_charset . '$/i', $part ) ) {
                continue;
            }
            return false;
        }
        return true;
    }

    /**
     * $sql with what MySQL reads as data or names blanked out: string
     * literals become '' and backticked names `i`; plain comments go and
     * executable ones keep their content (MySQL runs it). Follows MySQL's
     * own lexing (backslash escapes, doubled quotes, "-- " with any space or
     * control character, "#"), so a keyword hidden in a comment or a string
     * as this reads it is hidden from MySQL too. Null when it ends inside a
     * string or comment. $keep_names: backticked names stay as they are.
     */
    private static function sql_skeleton( $sql, $keep_names = false ) {
        $out  = '';
        $len  = strlen( $sql );
        $i    = 0;
        $exec = false;
        while ( $i < $len ) {
            $run = strcspn( $sql, "-#/*'\"`", $i );
            if ( $run > 0 ) {
                $out .= substr( $sql, $i, $run );
                $i   += $run;
                continue;
            }
            $c = $sql[ $i ];
            $n = $i + 1 < $len ? $sql[ $i + 1 ] : '';
            if ( $c === '-' && $n === '-' && ( $i + 2 >= $len || ord( $sql[ $i + 2 ] ) <= 32 ) || $c === '#' ) {
                $nl = strpos( $sql, "\n", $i );
                if ( $nl === false ) {
                    return $out;
                }
                $out .= ' ';
                $i    = $nl + 1;
            } elseif ( $c === '/' && $n === '*' ) {
                if ( preg_match( '/\G\/\*M?!\d*/', $sql, $mm, 0, $i ) ) {
                    if ( $exec ) {
                        return null; // nested executable comments
                    }
                    $exec = true;
                    $out .= ' ';
                    $i   += strlen( $mm[0] );
                    continue;
                }
                $end = strpos( $sql, '*/', $i + 2 );
                if ( $end === false ) {
                    return null;
                }
                $out .= ' ';
                $i    = $end + 2;
            } elseif ( $c === '*' && $n === '/' && $exec ) {
                $exec = false;
                $out .= ' ';
                $i   += 2;
            } elseif ( $c === "'" || $c === '"' ) {
                $j = $i + 1;
                while ( true ) {
                    $j += strcspn( $sql, '\\' . $c, $j );
                    if ( $j >= $len ) {
                        return null;
                    }
                    if ( $sql[ $j ] === '\\' ) {
                        $j += 2;
                        continue;
                    }
                    if ( $j + 1 < $len && $sql[ $j + 1 ] === $c ) {
                        $j += 2;
                        continue;
                    }
                    break;
                }
                $out .= "''";
                $i    = $j + 1;
            } elseif ( $c === '`' ) {
                $j = $i + 1;
                while ( true ) {
                    $j = strpos( $sql, '`', $j );
                    if ( $j === false ) {
                        return null;
                    }
                    if ( $j + 1 < $len && $sql[ $j + 1 ] === '`' ) {
                        $j += 2;
                        continue;
                    }
                    break;
                }
                $out .= $keep_names ? substr( $sql, $i, $j - $i + 1 ) : '`i`';
                $i    = $j + 1;
            } else {
                $out .= $c;
                $i++;
            }
        }
        return $exec ? null : $out;
    }

    /**
     * Executes pre-transformed SQL on the destination database.
     */
    public function execute_sql( $sql_content ) {
        if ( $refused = $this->refused() ) {
            return $refused;
        }
        // Split into individual statements with a quote/comment-aware scanner.
        // A column value may legitimately contain ";" (or ";\n"), so splitting
        // on the delimiter naively would cut a statement mid-value.
        $statements = self::split_sql_statements( $sql_content );
        // The client remapped the prefix already: this site's own. Nothing
        // in the batch runs when one statement reaches outside it.
        global $wpdb;
        foreach ( $statements as $statement ) {
            if ( $problem = self::reach_problem( $statement, (string) $wpdb->prefix, (bool) Scope::install(), '', '', true ) ) {
                return new \WP_Error( 'sql_refused', $problem, [ 'status' => 400 ] );
            }
        }
        return $this->execute_statements( $statements );
    }

    /**
     * Executes a list of individual SQL statements on the destination database.
     */
    private function execute_statements( $statements ) {
        $run    = $this->begin_statements();
        $compat = self::db_compat();
        foreach ( $statements as $statement ) {
            if ( ! $this->run_statement( self::compat_statement( $statement, $compat ), $run ) ) {
                break;
            }
        }
        $result              = $this->end_statements( $run );
        $result['converted'] = $compat['converted'];
        return $result;
    }

    /**
     * Starts a SQL import. The import overwrites the options table (and the
     * users table) with the source's, which replaces this site's Disembark
     * token and ends the admin's login. The token is written back at the end;
     * the token guard (a hash of it in this import's folder) keeps the
     * remaining steps and a rollback authorized even if the import dies before
     * that (out of memory, a killed worker) — see token_guard_matches().
     */
    private function begin_statements() {
        global $wpdb;

        $current = (string) Token::read();
        $guard   = self::read_token_guard( $this->import_id );
        if ( $guard ) {
            // An earlier attempt of this import (or of its rollback) already
            // recorded this site's real token. If that attempt died after the
            // options table was replaced, Token::read() now returns the
            // source's token, so never overwrite the guard: keep the token that
            // matches it (the one the caller authenticated with, or the stored
            // one when the options table hasn't been replaced yet).
            $preserved_token = '';
            foreach ( [ $this->request_token, $current ] as $candidate ) {
                if ( $candidate !== '' && self::guard_signed( $guard, $this->import_id, $candidate ) ) {
                    $preserved_token = $candidate;
                    break;
                }
            }
        } else {
            $preserved_token = $current;
            $key             = self::guard_key();
            if ( $preserved_token !== '' && $key !== '' ) {
                $this->prepare_import_dir();
                $created = time();
                self::write_protected( $this->import_path . '/token-guard.php', (string) wp_json_encode( [
                    'created_at' => $created,
                    'mac'        => self::guard_mac( $key, $this->import_id, $created, $preserved_token ),
                ] ) );
            }
        }

        $this->keep_install_rows();

        $wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );
        $wpdb->query( 'SET UNIQUE_CHECKS = 0' );
        $wpdb->query( "SET sql_mode='NO_AUTO_VALUE_ON_ZERO'" );

        return [
            'preserved_token'     => $preserved_token,
            'statements_executed' => 0,
            'errors'              => [],
        ];
    }

    /**
     * Runs one statement. Returns false once too many errors have piled up,
     * which stops the import.
     */
    private function run_statement( $statement, &$run ) {
        global $wpdb;

        $statement = trim( $statement );
        if ( $statement === '' ) {
            return true;
        }
        // Skip SET statements we handle ourselves
        if ( preg_match( '/^SET\s+(FOREIGN_KEY_CHECKS|UNIQUE_CHECKS|sql_mode|NAMES)/i', $statement ) ) {
            return true;
        }
        // Skip the DISABLE/ENABLE KEYS comments
        if ( preg_match( '/^\/\*!\d+\s+ALTER TABLE/', $statement ) ) {
            return true;
        }

        $result = $wpdb->query( $statement );
        if ( $result === false ) {
            // Don't fail on DROP TABLE errors
            if ( stripos( $statement, 'DROP TABLE' ) === 0 ) {
                return true;
            }
            // A full disk is the usual cause, and worth saying plainly.
            if ( preg_match( '/\b(is full|No space left|Disk full|errno: 28)\b/i', $wpdb->last_error ) && empty( $run['disk_full'] ) ) {
                $run['disk_full'] = true;
                $run['errors'][]  = 'The database server ran out of disk space. The import needs room for a full copy of the incoming tables alongside the current ones.';
            }
            $run['errors'][] = substr( $wpdb->last_error, 0, 200 ) . ' | SQL: ' . substr( $statement, 0, 100 );
            if ( count( $run['errors'] ) > 50 ) {
                $run['errors'][] = '...truncated (too many errors)';
                return false;
            }
            return true;
        }
        $run['statements_executed']++;
        return true;
    }

    private function end_statements( $run ) {
        global $wpdb;

        $wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' );
        $wpdb->query( 'SET UNIQUE_CHECKS = 1' );

        // Restore the preserved Disembark token (Token::set() clears the
        // options cache the raw import bypassed).
        if ( ! empty( $run['preserved_token'] ) ) {
            Token::set( $run['preserved_token'] );
        }
        $this->restore_install_rows();

        return [
            'statements_executed' => $run['statements_executed'],
            'errors'              => $run['errors'],
            'warnings'            => $run['warnings'] ?? [],
        ];
    }

    /**
     * Rows of the options table that belong to this server or install, not
     * to the site, noted once at the start of the import (in this move's
     * folder) and put back over the incoming site's when it ends:
     * - the host's own options (HOST_OPTIONS): the destination keeps its
     *   own, and the source's are left behind, also in a subsite or tenant;
     * - on the main site of a WP Freighter install, the install's registry
     *   (stacked_sites: the tenants; stacked_configurations: files mode and
     *   domain mapping) and WP Freighter itself. A whole-site import
     *   replaces the options table with the incoming site's, which has
     *   neither, so the install forgot its tenants and the next refresh of
     *   its bootstrap would have cut them off.
     */
    private function keep_install_rows() {
        global $wpdb;
        $file = $this->import_path . '/install-keep.php';
        if ( is_file( $file ) ) {
            return;
        }
        $target = $this->target();
        $table  = $target ? ( $target->prefix !== '' ? $target->prefix . 'options' : '' ) : $wpdb->options;
        $keep   = [ 'table' => $table, 'rows' => [], 'freighter' => false, 'host' => [] ];
        if ( ! $target && Scope::freighter_primary() === $wpdb->prefix ) {
            $keep['rows']      = (array) $wpdb->get_results( "SELECT option_name, option_value, autoload FROM `{$wpdb->options}` WHERE option_name IN ( 'stacked_sites', 'stacked_configurations' )", ARRAY_A );
            $active            = maybe_unserialize( (string) $wpdb->get_var( "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = 'active_plugins'" ) );
            $keep['freighter'] = is_array( $active ) && in_array( 'wp-freighter/wp-freighter.php', $active, true );
        }
        if ( $table !== '' && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table ) {
            $keep['host'] = (array) $wpdb->get_results( "SELECT option_name, option_value, autoload FROM `{$table}` WHERE " . self::host_options_where(), ARRAY_A );
        }
        $this->prepare_import_dir();
        self::write_protected( $file, (string) wp_json_encode( $keep ) );
    }

    /** SQL matching the host's options (HOST_OPTIONS). */
    private static function host_options_where() {
        global $wpdb;
        return implode( ' OR ', array_map( function ( $p ) use ( $wpdb ) { return $wpdb->prepare( 'option_name LIKE %s', $wpdb->esc_like( $p ) . '%' ); }, self::HOST_OPTIONS ) );
    }

    /** Puts the install's rows back after an import replaced the options table without them (see keep_install_rows()). */
    private function restore_install_rows() {
        global $wpdb;
        $keep = json_decode( (string) self::read_protected( $this->import_path . '/install-keep.php' ), true );
        if ( ! is_array( $keep ) || $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->options ) ) ) !== $wpdb->options ) {
            return;
        }
        foreach ( (array) ( $keep['rows'] ?? [] ) as $row ) {
            $name = (string) ( $row['option_name'] ?? '' );
            if ( $name !== '' && ! $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM `{$wpdb->options}` WHERE option_name = %s", $name ) ) ) {
                $wpdb->insert( $wpdb->options, [ 'option_name' => $name, 'option_value' => (string) $row['option_value'], 'autoload' => (string) ( $row['autoload'] ?? 'yes' ) ] );
            }
        }
        if ( ! empty( $keep['freighter'] ) ) {
            $active = maybe_unserialize( (string) $wpdb->get_var( "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = 'active_plugins'" ) );
            if ( is_array( $active ) && ! in_array( 'wp-freighter/wp-freighter.php', $active, true ) ) {
                $active[] = 'wp-freighter/wp-freighter.php';
                $wpdb->update( $wpdb->options, [ 'option_value' => serialize( array_values( $active ) ) ], [ 'option_name' => 'active_plugins' ] );
            }
        }
        // Disembark itself stays on: the rest of the move (the address
        // rewrite, the tidy-up, the checks) and its undo run through it, and
        // a backup of a site without Disembark (another host's zip) turned
        // it off mid-move, so the dashboard's next step found no route.
        $own     = plugin_basename( dirname( __DIR__ ) . '/disembark.php' );
        $network = is_multisite() ? (array) get_site_option( 'active_sitewide_plugins', [] ) : [];
        if ( ! isset( $network[ $own ] ) ) {
            $active = maybe_unserialize( (string) $wpdb->get_var( "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = 'active_plugins'" ) );
            $active = is_array( $active ) ? $active : [];
            if ( ! in_array( $own, $active, true ) ) {
                $active[] = $own;
                if ( ! $wpdb->update( $wpdb->options, [ 'option_value' => serialize( array_values( $active ) ) ], [ 'option_name' => 'active_plugins' ] ) ) {
                    $wpdb->insert( $wpdb->options, [ 'option_name' => 'active_plugins', 'option_value' => serialize( array_values( $active ) ), 'autoload' => 'yes' ] );
                }
            }
        }
        // The host's options: this server's, never the incoming site's.
        $table = (string) ( $keep['table'] ?? '' );
        if ( array_key_exists( 'host', $keep ) && $table !== '' && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table ) {
            $wpdb->query( "DELETE FROM `{$table}` WHERE " . self::host_options_where() );
            foreach ( (array) $keep['host'] as $row ) {
                $wpdb->insert( $table, [ 'option_name' => (string) $row['option_name'], 'option_value' => (string) $row['option_value'], 'autoload' => (string) ( $row['autoload'] ?? 'yes' ) ] );
            }
        }
        wp_cache_delete( 'alloptions', 'options' );
        wp_cache_delete( 'notoptions', 'options' );
    }

    /** Set once a request got in with a token guard rather than the site's token (see preflight()). */
    private static $guard_authenticated = false;

    /**
     * True when $token is the token this site had when import $import_id
     * started its SQL import, within the last day. Lets a restore that died
     * mid-import (leaving the source's token in the options table) still
     * finish or roll back with the token the dashboard or CLI holds.
     *
     * A guard only counts when this site wrote it: it is signed with this
     * site's own key (see guard_key()), bound to the import's id and its
     * time, and a guard dated in the future is refused. Disembark's folder
     * is never deployed from a backup, so a source can't ship one either.
     */
    public static function token_guard_matches( $import_id, $token ) {
        if ( ! is_string( $token ) || $token === '' ) {
            return false;
        }
        $import_id = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $import_id ) );
        $guard     = self::read_token_guard( $import_id );
        if ( $guard === null || ! self::guard_signed( $guard, $import_id, $token ) ) {
            return false;
        }
        self::$guard_authenticated = true;
        return true;
    }

    /**
     * Deletes every token guard and replaces the key that signs them, so no
     * guard made before (for the old token) works any more. Called when the
     * site's token is regenerated. Returns how many guards went.
     */
    public static function clear_token_guards() {
        $count = 0;
        foreach ( (array) glob( Storage::base_dir() . '/_import_*/token-guard.{php,json}', GLOB_BRACE ) as $file ) {
            if ( @unlink( $file ) ) {
                $count++;
            }
        }
        @unlink( Storage::base_dir() . '/guard-key.php' );
        return $count;
    }

    /**
     * The token guard for import $import_id when it exists, is under a day
     * old and isn't dated in the future, else null. Guards written by older
     * builds (token-guard.json, unsigned) are never honoured.
     */
    private static function read_token_guard( $import_id ) {
        $import_id = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $import_id ) );
        if ( $import_id === '' ) {
            return null;
        }
        $guard = json_decode( (string) self::read_protected( Storage::base_dir() . "/_import_{$import_id}/token-guard.php" ), true );
        if ( ! is_array( $guard ) || empty( $guard['mac'] ) || ! is_string( $guard['mac'] ) || empty( $guard['created_at'] ) ) {
            return null;
        }
        $age = time() - (int) $guard['created_at'];
        if ( $age < -300 || $age > DAY_IN_SECONDS ) {
            return null;
        }
        return $guard;
    }

    private static function guard_signed( $guard, $import_id, $token ) {
        $key = self::guard_key();
        return $key !== '' && hash_equals( (string) $guard['mac'], self::guard_mac( $key, $import_id, (int) $guard['created_at'], $token ) );
    }

    private static function guard_mac( $key, $import_id, $created_at, $token ) {
        return hash_hmac( 'sha256', $import_id . '|' . (int) $created_at . '|' . hash( 'sha256', (string) $token ), $key );
    }

    /**
     * The key token guards are signed with: a random key in Disembark's
     * folder plus this site's wp-config salts. Not wp_salt(): without salt
     * constants it reads them from the options table, which is exactly what
     * an import replaces, and a guard has to hold across that. '' when no key
     * can be kept (then no guard is written or honoured).
     */
    private static function guard_key() {
        $file = Storage::base_dir() . '/guard-key.php';
        $key  = trim( (string) self::read_protected( $file ) );
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $key ) ) {
            Storage::ensure_dir( Storage::base_dir() );
            self::write_protected( $file, bin2hex( random_bytes( 32 ) ) );
            // Whatever is there now, in case another request made one too.
            $key = trim( (string) self::read_protected( $file ) );
            if ( ! preg_match( '/^[a-f0-9]{64}$/', $key ) ) {
                return '';
            }
        }
        foreach ( [ 'AUTH_KEY', 'AUTH_SALT', 'SECURE_AUTH_KEY', 'SECURE_AUTH_SALT' ] as $constant ) {
            $key .= defined( $constant ) ? (string) constant( $constant ) : '';
        }
        return $key;
    }

    /**
     * Serialize-aware search/replace across the destination database. Runs after
     * the SQL import so it can operate on real row values (a dump keeps those as
     * escaped substrings that can't be reliably unserialized). Walks every table
     * and column, fixing PHP-serialized length prefixes as it replaces, and
     * updates changed rows by primary key.
     *
     * @param string   $from   String to search for (e.g. the source home URL).
     * @param string   $to     Replacement (e.g. the destination home URL).
     * @param string[] $tables Optional table allowlist; defaults to all tables.
     * @param array    $options variants: also rewrite the old address's other
     *                          spellings (see url_variants()), as a move does;
     *                          extra: more plain from => to pairs (the old
     *                          server's file paths);
     *                          budget: seconds (see Pace). It stops once
     *                          they're spent and answers { done: false }, and
     *                          the next call carries on at the table and
     *                          primary key it stopped at. Every row it
     *                          changes is journaled, so a killed call never
     *                          rewrites a row twice.
     */
    public function search_replace( $from, $to, $tables = [], $dry_run = false, $options = [] ) {
        global $wpdb;

        $pairs = $from !== '' && $from !== $to ? self::replacement_pairs( $from, $to, ! empty( $options['variants'] ) ) : [];
        foreach ( (array) ( $options['extra'] ?? [] ) as $f => $t ) {
            if ( (string) $f !== '' && (string) $f !== (string) $t ) {
                $pairs += self::replacement_pairs( (string) $f, (string) $t );
            }
        }
        if ( ! $pairs ) {
            return [ 'tables' => 0, 'rows_changed' => 0, 'cells_changed' => 0, 'by_table' => [], 'samples' => [] ];
        }
        $samples = [];

        if ( empty( $tables ) ) {
            $tables = $wpdb->get_col( 'SHOW TABLES' );
        }

        $paced        = isset( $options['budget'] ) && ! $dry_run;
        $deadline     = $paced ? Pace::deadline( $options['budget'] ) : 0;
        if ( $paced ) {
            $this->prepare_import_dir();
        }
        $state_file   = $this->import_path . '/replace-state.php';
        $journal_file = $this->import_path . '/replace-journal.php';
        $st           = $paced ? json_decode( (string) self::read_protected( $state_file ), true ) : null;
        if ( ! is_array( $st ) ) {
            $st = [ 'tables' => array_values( array_map( 'strval', $tables ) ), 't' => 0, 'key' => null, 'offset' => 0, 'seen' => 0, 'rows' => 0, 'cells' => 0, 'by_table' => [] ];
            if ( $paced ) {
                // Saved before any row changes, so a first call the host
                // kills leaves its journal to go on from.
                @unlink( $journal_file );
                self::write_protected( $state_file, (string) wp_json_encode( $st ) );
            }
        } else {
            // A killed call changed rows after its last checkpoint: carry on
            // after the last one it recorded, with its counts.
            $records = Pace::journal_read( $journal_file );
            $last    = $records ? end( $records ) : null;
            if ( $last && ( (int) $last['t'] > (int) $st['t'] || ( (int) $last['t'] === (int) $st['t'] && (int) $last['o'] > (int) $st['offset'] ) ) ) {
                if ( (int) $last['t'] > (int) $st['t'] ) {
                    $st['seen'] += (int) $last['t'] - (int) $st['t'];
                }
                foreach ( $records as $rec ) {
                    $name = (string) ( $st['tables'][ (int) $rec['t'] ] ?? '' );
                    if ( $name !== '' ) {
                        $st['by_table'][ $name ] = (int) $rec['n'];
                    }
                }
                $st['t']      = (int) $last['t'];
                $st['key']    = $last['k'];
                $st['offset'] = (int) $last['o'];
                $st['rows']   = (int) $last['r'];
                $st['cells']  = (int) $last['c'];
            }
        }
        $tables        = $st['tables'];
        $by_table      = (array) $st['by_table'];
        $rows_changed  = (int) $st['rows'];
        $cells_changed = (int) $st['cells'];
        $tables_seen   = (int) $st['seen'];

        $needles = self::needles( $pairs );
        self::$base64_needles = $from !== $to ? self::base64_needles_for( $from, ! empty( $options['variants'] ) ) : [];
        $journal = $paced ? Pace::journal_open( $journal_file ) : null;
        $stopped = false;
        $worked  = false;

        for ( $ti = (int) $st['t']; $ti < count( $tables ); $ti++ ) {
            // Only touch real tables on this database.
            $table  = (string) $tables[ $ti ];
            $resume = $ti === (int) $st['t'];
            $last_key = $resume ? $st['key'] : null;
            $offset   = $resume ? (int) $st['offset'] : 0;
            $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
            if ( $exists !== $table ) {
                continue;
            }
            if ( ! $resume || ( $last_key === null && $offset === 0 ) ) {
                $tables_seen++;
            }

            $columns     = $wpdb->get_results( 'SHOW COLUMNS FROM `' . str_replace( '`', '``', $table ) . '`' );
            $column_names = [];
            $primary_keys = [];
            foreach ( $columns as $col ) {
                $column_names[] = $col->Field;
                if ( $col->Key === 'PRI' ) {
                    $primary_keys[] = $col->Field;
                }
            }
            // Without a primary key we can't safely target an UPDATE.
            if ( empty( $primary_keys ) || empty( $column_names ) ) {
                continue;
            }

            // Page in primary-key order so no row is skipped or seen twice:
            // after the last key with a one-column key (a later call starts
            // there), by offset otherwise.
            $order  = implode( ', ', array_map( function ( $pk ) { return '`' . str_replace( '`', '``', $pk ) . '`'; }, $primary_keys ) );
            $keyset = $paced && count( $primary_keys ) === 1;
            $limit  = 1000;
            while ( true ) {
                if ( $paced && $worked && microtime( true ) >= $deadline ) {
                    $stopped = true;
                    break 2;
                }
                if ( $keyset && $last_key !== null ) {
                    $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM `' . str_replace( '`', '``', $table ) . '` WHERE ' . $order . " > %s ORDER BY {$order} LIMIT {$limit}", $last_key ), ARRAY_A );
                } else {
                    $rows = $wpdb->get_results(
                        'SELECT * FROM `' . str_replace( '`', '``', $table ) . "` ORDER BY {$order} LIMIT {$limit} OFFSET {$offset}",
                        ARRAY_A
                    );
                }
                $worked = true;
                if ( empty( $rows ) ) {
                    break;
                }

                foreach ( $rows as $row ) {
                    $update = [];
                    foreach ( $row as $column => $value ) {
                        if ( ! is_string( $value ) || $value === '' ) {
                            continue;
                        }
                        if ( ! self::contains_any( $value, $needles ) && ! self::has_base64_needle( $value ) ) {
                            continue;
                        }
                        $replaced = self::replace_in_value( $value, $pairs );
                        if ( $replaced !== $value ) {
                            $update[ $column ] = $replaced;
                            if ( $dry_run && count( $samples ) < 25 ) {
                                $samples[] = [
                                    'table'  => $table,
                                    'column' => $column,
                                    'key'    => implode( ', ', array_map( function ( $pk ) use ( $row ) { return $pk . '=' . $row[ $pk ]; }, $primary_keys ) ),
                                    'before' => self::excerpt( $value, $from ),
                                    'after'  => self::excerpt( $replaced, $to ),
                                ];
                            }
                        }
                    }
                    $offset++;
                    if ( $keyset ) {
                        $last_key = $row[ $primary_keys[0] ];
                    }
                    if ( empty( $update ) ) {
                        continue;
                    }
                    $cells_changed     += count( $update );
                    $by_table[ $table ] = ( $by_table[ $table ] ?? 0 ) + count( $update );
                    if ( $dry_run ) {
                        $rows_changed++;
                        continue;
                    }
                    $where = [];
                    foreach ( $primary_keys as $pk ) {
                        $where[ $pk ] = $row[ $pk ];
                    }
                    $result = $wpdb->update( $table, $update, $where );
                    if ( $result !== false && $result > 0 ) {
                        $rows_changed++;
                    }
                    if ( $paced ) {
                        Pace::journal_add( $journal, [ 't' => $ti, 'k' => $last_key, 'o' => $offset, 'r' => $rows_changed, 'c' => $cells_changed, 'n' => $by_table[ $table ] ] );
                    }
                }
                if ( count( $rows ) < $limit ) {
                    break;
                }
            }
        }
        if ( $journal ) {
            fclose( $journal );
        }
        self::$base64_needles = [];

        if ( $stopped ) {
            if ( $last_key === null && $offset === 0 ) {
                $tables_seen--; // counted again when the next call starts it
            }
            self::write_protected( $state_file, (string) wp_json_encode( [ 'tables' => $tables, 't' => $ti, 'key' => $last_key, 'offset' => $offset, 'seen' => $tables_seen, 'rows' => $rows_changed, 'cells' => $cells_changed, 'by_table' => $by_table ] ) );
            @unlink( $journal_file );
            return [ 'done' => false, 'table' => $table, 'tables_done' => $ti, 'tables_total' => count( $tables ), 'cells_changed' => $cells_changed ];
        }
        if ( $paced ) {
            @unlink( $state_file );
            @unlink( $journal_file );
        }
        arsort( $by_table );
        $out = [
            'tables'        => $tables_seen,
            'rows_changed'  => $rows_changed,
            'cells_changed' => $cells_changed,
            'by_table'      => $by_table,
            'samples'       => $samples,
            'dry_run'       => (bool) $dry_run,
        ];
        if ( $paced ) {
            $out['done'] = true;
        }
        return $out;
    }

    /**
     * A short slice of $value around the first $needle (or its start), for
     * previews. Serialized and JSON-escaped spellings are matched too.
     */
    private static function excerpt( $value, $needle ) {
        $pos = strpos( $value, $needle );
        if ( $pos === false ) {
            $pos = strpos( $value, str_replace( '/', '\\/', $needle ) );
        }
        $pos   = $pos === false ? 0 : $pos;
        $start = max( 0, $pos - 50 );
        $slice = substr( $value, $start, 160 );
        return ( $start > 0 ? '…' : '' ) . $slice . ( $start + 160 < strlen( $value ) ? '…' : '' );
    }

    /**
     * After a restore's database rewrite: rewrites the old address in text
     * files the restore brought in under the uploads folder (page-builder CSS
     * and the like, #10), clears Elementor's generated CSS so it rebuilds from
     * the rewritten data, and lists theme and plugin files that still mention
     * the old address (reported, never edited: that's code). Safe to roll
     * back: these files were deployed by this import, and rollback restores
     * whatever was there before.
     */
    public function rewrite_files( $pairs, $target = null ) {
        $report = [ 'files_rewritten' => 0, 'files_rewritten_list' => [], 'files_mentioning_source' => [], 'files_mentioning_total' => 0, 'elementor_css_cleared' => false ];
        // Deploying moves files out of staging, so the list of what this
        // import put in place comes from its rollback metadata.
        $meta = self::rollback_meta( $this->rollback_path );
        $deployed = is_array( $meta ) ? array_unique( (array) ( $meta['deployed_files'] ?? [] ) ) : [];
        if ( ! $deployed ) {
            return $report;
        }
        $web_root = wp_normalize_path( dirname( WP_CONTENT_DIR ) );
        $uploads  = $target ? $target->uploads . '/' : ltrim( substr( wp_normalize_path( wp_upload_dir()['basedir'] ), strlen( $web_root ) ), '/' ) . '/';
        $content  = $target && $target->content !== '' ? $target->content . '/' : ltrim( substr( wp_normalize_path( WP_CONTENT_DIR ), strlen( $web_root ) ), '/' ) . '/';
        $code_dirs = [ $content . 'themes/', $content . 'plugins/', $content . 'mu-plugins/' ];

        foreach ( $deployed as $rel ) {
            $rel = ltrim( str_replace( '\\', '/', (string) $rel ), '/' );
            if ( $rel === '' || ! self::is_safe_relative_path( $rel ) ) {
                continue;
            }
            $in_uploads = strpos( $rel, $uploads ) === 0;
            $rewrite    = $in_uploads && preg_match( '/\.(css|js|json|html?|svg|xml|txt)$/i', $rel ) && strpos( $rel, $uploads . 'disembark/' ) !== 0;
            $report_it  = ! $in_uploads && preg_match( '/\.(php|css|js|html?|json)$/i', $rel );
            if ( $report_it ) {
                $report_it = false;
                foreach ( $code_dirs as $dir ) {
                    if ( strpos( $rel, $dir ) === 0 ) { $report_it = true; break; }
                }
            }
            if ( ! $rewrite && ! $report_it ) {
                continue;
            }
            $path = $web_root . '/' . $rel;
            $size = is_file( $path ) ? filesize( $path ) : 0;
            if ( ! $size || $size > ( $rewrite ? 5 : 1 ) * 1024 * 1024 ) {
                continue;
            }
            $contents = file_get_contents( $path );
            if ( $contents === false || ! self::contains_any( $contents, $pairs ) ) {
                continue;
            }
            if ( $rewrite ) {
                $updated = strtr( $contents, $pairs );
                if ( $updated !== $contents && file_put_contents( $path, $updated ) !== false ) {
                    $report['files_rewritten']++;
                    if ( count( $report['files_rewritten_list'] ) < 25 ) {
                        $report['files_rewritten_list'][] = $rel;
                    }
                }
            } else {
                $report['files_mentioning_total']++;
                if ( count( $report['files_mentioning_source'] ) < 25 ) {
                    $report['files_mentioning_source'][] = $rel;
                }
            }
        }
        $report['elementor_css_cleared'] = self::clear_elementor_css();
        return $report;
    }

    /**
     * Elementor writes per-page CSS (with absolute image URLs) to
     * uploads/elementor/css and records it in _elementor_css. Clearing both,
     * as its own "Regenerate CSS" does, makes it rebuild on the next view.
     */
    private static function clear_elementor_css() {
        global $wpdb;
        $dir = wp_upload_dir()['basedir'] . '/elementor/css';
        if ( ! is_dir( $dir ) ) {
            return false;
        }
        foreach ( (array) glob( $dir . '/*.css' ) as $file ) {
            @unlink( $file );
        }
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", '_elementor_css' ) );
        foreach ( [ '_elementor_global_css', 'elementor-custom-breakpoints-files' ] as $option ) {
            $wpdb->delete( $wpdb->options, [ 'option_name' => $option ] );
            wp_cache_delete( $option, 'options' );
        }
        wp_cache_delete( 'alloptions', 'options' );
        return true;
    }

    /**
     * Every saved rollback point (newest first): what it was for, when, how
     * big, and whether it has been rolled back to.
     */
    public static function restore_points() {
        $points = [];
        foreach ( (array) glob( Storage::base_dir() . '/_import_*', GLOB_ONLYDIR ) as $dir ) {
            $meta = self::rollback_meta( $dir . '/rollback' );
            if ( $meta === null ) {
                continue;
            }
            $meta_file = is_file( $dir . '/rollback/meta.php' ) ? $dir . '/rollback/meta.php' : $dir . '/rollback/meta.json';
            $size = 0;
            $it   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir . '/rollback', \FilesystemIterator::SKIP_DOTS ) );
            foreach ( $it as $file ) {
                if ( $file->isFile() ) {
                    $size += $file->getSize();
                }
            }
            $points[] = [
                'id'             => substr( basename( $dir ), strlen( '_import_' ) ),
                'label'          => (string) ( $meta['label'] ?? '' ),
                'created_at'     => (int) ( $meta['created_at'] ?? filemtime( $meta_file ) ),
                'rolled_back_at' => (int) ( $meta['rolled_back_at'] ?? 0 ),
                'tables'         => count( (array) ( $meta['tables'] ?? [] ) ),
                'files'          => count( (array) ( $meta['backed_up_files'] ?? [] ) ),
                'deployed'       => count( (array) ( $meta['deployed_files'] ?? [] ) ),
                'size'           => $size,
            ];
        }
        usort( $points, function ( $a, $b ) { return $b['created_at'] - $a['created_at']; } );
        return $points;
    }

    /**
     * Deletes this import's folder, rollback point included.
     */
    public function delete_restore_point() {
        if ( ! is_dir( $this->import_path ) ) {
            return new \WP_Error( 'not_found', 'That restore point no longer exists.', [ 'status' => 404 ] );
        }
        self::delete_directory_recursive( $this->import_path );
        return [ 'deleted' => true ];
    }

    /**
     * Daily clean-up (Run registers the cron): what a move or a backup left
     * in the uploads folder that nobody has touched for a while. Backup and
     * export session folders go after $max_age. A move folder:
     * - an unfinished move that can be resumed (its pull state is there)
     *   and saved no restore point yet: after a week (Pull::unfinished()
     *   and the changelog say the same);
     * - a move that saved its restore point and then stopped, so the site
     *   may be partly changed: never, until it's resumed, discarded or
     *   rolled back (the Move tab and `wp disembark unfinished` list it);
     * - one that was rolled back: its leftovers (staged files) after
     *   $max_age, the restore point stays;
     * - anything else without a restore point (a failed backup restore's
     *   staged database-*.sql): after $max_age.
     * The pull memory and restore points stay. Returns the folder names it
     * cleaned.
     */
    public static function purge_stale( $max_age = DAY_IN_SECONDS ) {
        $base   = Storage::base_dir();
        $cutoff = time() - (int) $max_age;
        $purged = [];
        foreach ( (array) glob( $base . '/*', GLOB_ONLYDIR ) as $dir ) {
            $name = basename( $dir );
            if ( strpos( $name, '_import_' ) === 0 && ! is_link( $dir ) && self::has_restore_point( $dir ) ) {
                ( new self( substr( $name, strlen( '_import_' ) ) ) )->protect_restore_point();
            }
            if ( is_link( $dir ) || $name === 'pulls' || self::newest_mtime( $dir ) > $cutoff ) {
                continue;
            }
            if ( strpos( $name, '_import_' ) === 0 ) {
                $id = substr( $name, strlen( '_import_' ) );
                if ( ! preg_match( '/^[a-f0-9]+$/', $id ) ) {
                    continue;
                }
                $leftovers = is_dir( "{$dir}/staging" ) || is_file( "{$dir}/pull.json" ) || is_file( "{$dir}/pull-secret.php" ) || glob( "{$dir}/token-guard*" );
                $point     = self::rollback_meta( "{$dir}/rollback" );
                if ( $point !== null && ( ! $leftovers || empty( $point['rolled_back_at'] ) ) ) {
                    continue;
                }
                if ( $point === null && is_file( "{$dir}/pull.json" ) && self::newest_mtime( $dir ) > time() - WEEK_IN_SECONDS ) {
                    continue;
                }
                ( new self( $id ) )->cancel( 'cleanup' );
                $purged[] = $name;
                continue;
            }
            self::delete_directory_recursive( $dir );
            $purged[] = $name;
        }
        return $purged;
    }

    /**
     * A restore point saved by 2.9 or an early 3.0 build keeps its dump as
     * a plain .db.txt and its metadata as meta.json, both served to anyone
     * who has the URL. Moves them behind the exit line, keeping the recorded
     * sizes in step so rollback() still checks them.
     */
    public function protect_restore_point() {
        $meta = self::rollback_meta( $this->rollback_path );
        if ( $meta === null ) {
            return false;
        }
        $changed = is_file( $this->rollback_path . '/meta.json' );
        foreach ( Backup::export_files( $this->rollback_path ) as $legacy ) {
            $name = preg_replace( '/\.(?:db|sql)\.txt$/', '', basename( $legacy ) ) . '.db.php';
            if ( ! self::copy_protected( $legacy, $this->rollback_path . '/' . $name ) ) {
                return false;
            }
            if ( isset( $meta['sql_files'][ basename( $legacy ) ] ) ) {
                unset( $meta['sql_files'][ basename( $legacy ) ] );
                $meta['sql_files'][ $name ] = filesize( $this->rollback_path . '/' . $name );
            }
            // Saved before the old copy goes, so a crash leaves a usable point.
            if ( ! $this->save_rollback_meta( $meta ) ) {
                @unlink( $this->rollback_path . '/' . $name );
                return false;
            }
            @unlink( $legacy );
            $changed = true;
        }
        if ( is_file( $this->rollback_path . '/meta.json' ) ) {
            $this->save_rollback_meta( $meta );
        }
        return $changed;
    }

    /* ------------------------------------------------------------------
     * Storage from earlier versions
     *
     * 2.9 and early 3.0 builds kept a restore point's dump and metadata,
     * the files it saved, its pull memory, a move's notification settings
     * (notify.json, webhook URLs included) and a pull's source token as
     * plain files under uploads, which a web server hands to anyone with
     * the URL (and a saved .php ran). The first load after an upgrade (see
     * Run::upgrade_storage()) moves them behind the exit line and deletes
     * the plain copies. Every file is written whole before its plain copy
     * goes and what's done is skipped, so it can stop at any point (out of
     * time, a killed request) and simply run again.
     * ---------------------------------------------------------------- */

    /** Moves every move folder's legacy files (see above) until $deadline. True when none are left. */
    public static function protect_legacy_storage( $deadline ) {
        $done = true;
        foreach ( (array) glob( Storage::base_dir() . '/_import_*', GLOB_ONLYDIR ) as $dir ) {
            $id = substr( basename( $dir ), strlen( '_import_' ) );
            if ( is_link( $dir ) || ! preg_match( '/^[a-f0-9]+$/', $id ) ) {
                continue;
            }
            if ( microtime( true ) >= $deadline ) {
                return false;
            }
            if ( ! ( new self( $id ) )->protect_legacy_files( $deadline ) ) {
                $done = false;
            }
        }
        return $done;
    }

    /** This move folder's legacy files, protected (see protect_legacy_storage()). True when none are left. */
    public function protect_legacy_files( $deadline ) {
        // A rollback running right now reads the saved files: next time.
        $busy = $this->import_path . '/rollback-state.php';
        if ( is_file( $busy ) && (int) @filemtime( $busy ) > time() - HOUR_IN_SECONDS ) {
            return false;
        }
        $done = Notify::protect_legacy( $this->import_id );
        $done = Pull::protect_legacy_state( $this->import_path ) && $done;
        if ( self::has_restore_point( $this->import_path ) ) {
            $this->protect_restore_point();
            if ( is_file( $this->rollback_path . '/meta.json' ) || Backup::export_files( $this->rollback_path ) ) {
                $done = false;
            }
        }
        return $this->protect_saved_files( $deadline ) && $done;
    }

    /**
     * The files a restore point saved, each moved to its rollback name
     * behind the exit line (see rollback_name()). 2.9 kept them under their
     * own names; early 3.0 builds kept runnable ones under staged names,
     * which is the same, and the rest under their own. True when done.
     */
    private function protect_saved_files( $deadline ) {
        $root = $this->rollback_path . '/files';
        if ( ! is_dir( $root ) || is_link( $root ) ) {
            return true;
        }
        $paths = [];
        $it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
        foreach ( $it as $file ) {
            if ( ! $file->isLink() && $file->isFile() ) {
                $paths[] = $file->getPathname();
            }
        }
        foreach ( $paths as $path ) {
            if ( microtime( true ) >= $deadline ) {
                return false;
            }
            $rel = ltrim( str_replace( '\\', '/', substr( $path, strlen( $root ) ) ), '/' );
            // What a copy that stopped left behind.
            if ( preg_match( '/\.dsk\.php\.(?:[0-9a-f]{12}\.)?tmp$/', $rel ) ) {
                if ( (int) @filemtime( $path ) < time() - HOUR_IN_SECONDS ) {
                    @unlink( $path );
                }
                continue;
            }
            if ( substr( $rel, -strlen( self::STAGED_SUFFIX ) ) === self::STAGED_SUFFIX ) {
                $in   = @fopen( $path, 'rb' );
                $head = $in ? (string) fread( $in, strlen( self::PHP_EXIT ) ) : '';
                if ( $in ) {
                    fclose( $in );
                }
                // Already behind the exit line; one an older build staged
                // without it gets it in front, in place.
                if ( $head !== self::PHP_EXIT && ! self::copy_protected( $path, $path ) ) {
                    return false;
                }
                continue;
            }
            $to = $root . '/' . self::rollback_name( $rel );
            if ( ! file_exists( $to ) && ! self::copy_protected( $path, $to ) ) {
                return false;
            }
            if ( ! @unlink( $path ) ) {
                return false;
            }
        }
        return true;
    }

    /** The newest modification time of $dir and anything under it (links not followed). */
    private static function newest_mtime( $dir ) {
        $newest = (int) @filemtime( $dir );
        foreach ( (array) @scandir( $dir ) as $item ) {
            if ( $item === '.' || $item === '..' ) {
                continue;
            }
            $path   = $dir . '/' . $item;
            $stat   = @lstat( $path );
            $newest = max( $newest, $stat ? (int) $stat['mtime'] : 0, is_dir( $path ) && ! is_link( $path ) ? self::newest_mtime( $path ) : 0 );
        }
        return $newest;
    }

    private static function contains_any( $value, $pairs ) {
        foreach ( $pairs as $search => $replace ) {
            if ( strpos( $value, (string) $search ) !== false ) {
                return true;
            }
        }
        return false;
    }

    /**
     * The fewest search strings that still find every key of $pairs: a key
     * that contains another key is found by that one (https://old-site.com
     * by //old-site.com). Returned as keys, for contains_any().
     */
    private static function needles( $pairs ) {
        $keys = array_map( 'strval', array_keys( $pairs ) );
        usort( $keys, function ( $a, $b ) { return strlen( $a ) - strlen( $b ); } );
        $kept = [];
        foreach ( $keys as $key ) {
            foreach ( $kept as $shorter ) {
                if ( strpos( $key, $shorter ) !== false ) {
                    continue 2;
                }
            }
            $kept[] = $key;
        }
        return array_fill_keys( $kept, true );
    }

    /* ------------------------------------------------------------------
     * Base64-wrapped content
     *
     * Some builders store markup base64-encoded, where a plain search never
     * sees the address: WPBakery's [vc_raw_html] and [vc_raw_js] (base64 of
     * URL-encoded HTML), Avada's [fusion_code], BeTheme's mfn-page-items,
     * Brizy's editor data. A value (or a serialized string, or one of those
     * shortcodes' bodies) that is base64 and whose decoded text holds the
     * old address is decoded, rewritten like any other value and encoded
     * again. The old address is spotted inside base64 without decoding, by
     * its encoding at each of the three byte alignments.
     * ---------------------------------------------------------------- */

    /** Set by search_replace() for the run; empty means no base64 handling. */
    private static $base64_needles = [];

    private static function base64_needles_for( $from, $variants ) {
        $host = (string) wp_parse_url( $from, PHP_URL_HOST );
        if ( $host === '' ) {
            return [];
        }
        $hosts = [ $host ];
        if ( $variants ) {
            $hosts[] = stripos( $host, 'www.' ) === 0 ? substr( $host, 4 ) : 'www.' . $host;
        }
        $needles = [];
        foreach ( $hosts as $h ) {
            foreach ( [ '//' . $h, '%2F%2F' . $h ] as $text ) {
                for ( $k = 0; $k < 3; $k++ ) {
                    // Each group of 4 base64 characters encodes 3 bytes. The
                    // first group mixes in whatever precedes the address and
                    // the last partial one whatever follows, so both go.
                    $encoded = base64_encode( str_repeat( "\0", $k ) . $text );
                    $start   = $k ? 4 : 0;
                    $end     = intdiv( $k + strlen( $text ), 3 ) * 4;
                    if ( $end - $start >= 8 ) {
                        $needles[ substr( $encoded, $start, $end - $start ) ] = true;
                    }
                }
            }
        }
        return $needles;
    }

    private static function has_base64_needle( $value ) {
        return self::$base64_needles && self::contains_any( $value, self::$base64_needles );
    }

    private static function looks_base64( $value ) {
        $len = strlen( $value );
        return $len >= 16 && $len % 4 === 0 && preg_match( '/^[A-Za-z0-9+\/]+={0,2}$/', $value );
    }

    /** $encoded decoded, rewritten and encoded again; null when nothing changed. */
    private static function replace_in_base64( $encoded, $pairs, $depth ) {
        $decoded = base64_decode( $encoded, true );
        if ( $decoded === false || $decoded === '' ) {
            return null;
        }
        $new = self::replace_in_value( $decoded, $pairs, $depth + 1 );
        return $new === $decoded ? null : base64_encode( $new );
    }

    /**
     * The spellings a URL takes in stored data, each mapped to its replacement.
     * The import screen and the CLI pass the plain home URLs, but JSON written
     * by PHP's default encoder escapes every slash (http:\/\/), JSON stored
     * inside JSON escapes it twice (http:\\\/\\\/), and some encoders spell it
     * as \u002F. Each form is rewritten in its own encoding so the JSON stays
     * valid. Elementor's _elementor_data is the common real-world case.
     *
     * @return array<string,string> search => replace, ready for strtr().
     */
    public static function replacement_pairs( $from, $to, $variants = false ) {
        $pairs = [];
        foreach ( ( $variants ? self::url_variants( $from, $to ) : [ $from => $to ] ) as $f => $t ) {
            $pairs += self::encoded_pairs( (string) $f, (string) $t );
        }
        // A spelling that maps to itself (a move from http to https keeps
        // https://host as is) needs no work.
        foreach ( $pairs as $f => $t ) {
            if ( (string) $f === (string) $t ) {
                unset( $pairs[ $f ] );
            }
        }
        return $pairs;
    }

    /**
     * Every address the old site was reachable at, mapped to the new one:
     * both schemes, with and without www, scheme-relative (//host), and the
     * international domain name in both its Unicode and xn-- spellings. Old
     * content links to http://www.old-site.com long after the site moved to
     * https://old-site.com; on the copy those links should point at the copy.
     */
    public static function url_variants( $from, $to ) {
        $out = [ $from => $to ];
        $f   = wp_parse_url( $from );
        $t   = wp_parse_url( $to );
        if ( empty( $f['host'] ) || empty( $f['scheme'] ) || empty( $t['host'] ) || ! in_array( strtolower( $f['scheme'] ), [ 'http', 'https' ], true ) ) {
            return $out;
        }
        $rest  = substr( $from, strpos( $from, $f['host'] ) + strlen( $f['host'] ) );
        $hosts = [ $f['host'] ];
        $hosts[] = stripos( $f['host'], 'www.' ) === 0 ? substr( $f['host'], 4 ) : 'www.' . $f['host'];
        foreach ( $hosts as $host ) {
            if ( function_exists( 'idn_to_ascii' ) && preg_match( '/[^\x00-\x7F]/', $host ) ) {
                $ascii = @idn_to_ascii( $host, 0, defined( 'INTL_IDNA_VARIANT_UTS46' ) ? INTL_IDNA_VARIANT_UTS46 : 0 );
                if ( $ascii ) {
                    $hosts[] = $ascii;
                }
            } elseif ( function_exists( 'idn_to_utf8' ) && stripos( $host, 'xn--' ) !== false ) {
                $utf8 = @idn_to_utf8( $host, 0, defined( 'INTL_IDNA_VARIANT_UTS46' ) ? INTL_IDNA_VARIANT_UTS46 : 0 );
                if ( $utf8 ) {
                    $hosts[] = $utf8;
                }
            }
        }
        $to_relative = substr( $to, strpos( $to, '//' ) );
        foreach ( array_unique( $hosts ) as $host ) {
            foreach ( [ 'https', 'http' ] as $scheme ) {
                $out += [ $scheme . '://' . $host . $rest => $to ];
            }
            $out += [ '//' . $host . $rest => $to_relative ];
        }
        return $out;
    }

    /** One address in each encoding stored data uses, mapped to its replacement. */
    private static function encoded_pairs( $from, $to ) {
        $pairs = [ $from => $to ];

        // Percent-encoded spellings (#9): redirect and return-URL parameters,
        // settings that store an encoded address. rawurlencode() output, its
        // lowercase-hex form and double encoding, each rewritten in its own
        // encoding so a redirect wrapper keeps its own URL.
        $enc_from = rawurlencode( $from );
        if ( $enc_from !== $from ) {
            $enc_to = rawurlencode( $to );
            $lower  = function ( $value ) {
                return preg_replace_callback( '/%[0-9A-F]{2}/', function ( $m ) { return strtolower( $m[0] ); }, $value );
            };
            $pairs[ $enc_from ]               = $enc_to;
            $pairs[ $lower( $enc_from ) ]     = $lower( $enc_to );
            $pairs[ rawurlencode( $enc_from ) ] = rawurlencode( $enc_to );
        }

        if ( strpos( $from, '/' ) === false ) {
            return $pairs;
        }
        // JSON's escaped slashes, and the HTML entities some editors write.
        $escapes = [ '\/', '\\\\\\/', '\u002F', '\u002f', '&#x2F;', '&#47;' ];
        foreach ( $escapes as $escape ) {
            $pairs[ str_replace( '/', $escape, $from ) ] = str_replace( '/', $escape, $to );
        }
        return $pairs;
    }

    /**
     * Replaces every search key of $pairs in one value. PHP serialized data is
     * rewritten token by token (see rewrite_serialized()) so length prefixes
     * stay valid; anything else gets a single-pass strtr(), which never
     * re-matches its own output when the new URL contains the old one.
     */
    public static function replace_in_value( $value, $pairs, $depth = 0 ) {
        if ( ! is_string( $value ) || $value === '' ) {
            return $value;
        }

        if ( $depth < 32 && self::has_base64_needle( $value ) ) {
            if ( self::looks_base64( $value ) ) {
                $new = self::replace_in_base64( $value, $pairs, $depth );
                if ( $new !== null ) {
                    return $new;
                }
            }
            if ( preg_match( '/\[(?:vc_raw_html|vc_raw_js|fusion_code)\b/', $value ) ) {
                $value = preg_replace_callback( '/(\[(vc_raw_html|vc_raw_js|fusion_code)\b[^\]]*\])([A-Za-z0-9+\/=\s]{16,}?)(\[\/\2\])/', function ( $m ) use ( $pairs, $depth ) {
                    $body = preg_replace( '/\s+/', '', $m[3] );
                    $new  = self::looks_base64( $body ) ? self::replace_in_base64( $body, $pairs, $depth ) : null;
                    return $new === null ? $m[0] : $m[1] . $new . $m[4];
                }, $value );
            }
        }

        if ( $depth < 32 && self::looks_serialized( $value ) ) {
            $offset    = 0;
            $rewritten = self::rewrite_serialized( $value, $offset, $pairs, $depth, 0 );
            if ( $rewritten !== null && $offset === strlen( $value ) ) {
                // Safety net: never turn a value PHP can read into one it can't.
                if ( $rewritten !== $value && ! self::unserializes( $rewritten ) && self::unserializes( $value ) ) {
                    return $value;
                }
                return $rewritten;
            }
        }

        return strtr( $value, $pairs );
    }

    private static function looks_serialized( $value ) {
        if ( $value === 'N;' ) {
            return true;
        }
        return strlen( $value ) >= 4
            && strpos( 'bidsaOCE', $value[0] ) !== false
            && $value[1] === ':'
            && in_array( substr( $value, -1 ), [ ';', '}' ], true );
    }

    private static function unserializes( $value ) {
        return $value === 'b:0;' || @unserialize( $value, [ 'allowed_classes' => false ] ) !== false;
    }

    /**
     * Walks one PHP serialized value starting at $i and returns it with every
     * string payload replaced and its s:N length fixed, or null if the input is
     * not well formed. Works on the serialized text itself rather than calling
     * unserialize(), so:
     *  - protected and private object properties (mangled \0 names) are
     *    rewritten like public ones,
     *  - no class is instantiated, so no __wakeup()/__unserialize()/
     *    __destruct() code runs during a restore and readonly properties or
     *    classes missing on the destination don't matter,
     *  - every byte outside a replaced string (floats, ints, references,
     *    Serializable C: payloads, enums) is kept exactly as stored.
     * Array keys and property names are copied unchanged; string payloads are
     * passed back through replace_in_value(), so nested serialized strings and
     * JSON inside them are handled too.
     */
    private static function rewrite_serialized( $s, &$i, $pairs, $depth, $level ) {
        if ( $level > 512 || ! isset( $s[ $i ] ) ) {
            return null;
        }
        $type = $s[ $i ];

        if ( $type === 'N' ) {
            if ( substr( $s, $i, 2 ) !== 'N;' ) {
                return null;
            }
            $i += 2;
            return 'N;';
        }

        if ( ( $type === 'b' || $type === 'i' || $type === 'd' || $type === 'r' || $type === 'R' ) ) {
            if ( ( $s[ $i + 1 ] ?? '' ) !== ':' ) {
                return null;
            }
            $end = strpos( $s, ';', $i + 2 );
            if ( $end === false ) {
                return null;
            }
            $scalar  = substr( $s, $i + 2, $end - $i - 2 );
            $pattern = $type === 'd' ? '/^(-?INF|NAN|[-+0-9.eE]+)$/' : '/^-?[0-9]+$/';
            if ( ! preg_match( $pattern, $scalar ) ) {
                return null;
            }
            $token = substr( $s, $i, $end - $i + 1 );
            $i     = $end + 1;
            return $token;
        }

        if ( $type === 's' ) {
            $string = self::read_length_prefixed( $s, $i, '";' );
            if ( $string === null ) {
                return null;
            }
            $new = self::replace_in_value( $string, $pairs, $depth + 1 );
            return 's:' . strlen( $new ) . ':"' . $new . '";';
        }

        if ( $type === 'E' ) {
            $start = $i;
            if ( self::read_length_prefixed( $s, $i, '";' ) === null ) {
                return null;
            }
            return substr( $s, $start, $i - $start );
        }

        if ( $type === 'C' ) {
            // C:len:"Class":len:{payload} — the payload's format belongs to the
            // class, so it is copied untouched.
            $start = $i;
            if ( self::read_length_prefixed( $s, $i, '":' ) === null ) {
                return null;
            }
            if ( ! preg_match( '/\G([0-9]+):\{/', $s, $m, 0, $i ) ) {
                return null;
            }
            $i += strlen( $m[0] ) + (int) $m[1];
            if ( ( $s[ $i ] ?? '' ) !== '}' ) {
                return null;
            }
            $i++;
            return substr( $s, $start, $i - $start );
        }

        if ( $type === 'a' || $type === 'O' ) {
            $start = $i;
            if ( $type === 'O' ) {
                if ( self::read_length_prefixed( $s, $i, '":' ) === null ) {
                    return null;
                }
                if ( ! preg_match( '/\G([0-9]+):\{/', $s, $m, 0, $i ) ) {
                    return null;
                }
            } elseif ( ! preg_match( '/\Ga:([0-9]+):\{/', $s, $m, 0, $i ) ) {
                return null;
            }
            $count  = (int) $m[1];
            $i     += strlen( $m[0] );
            $output = substr( $s, $start, $i - $start );

            for ( $n = 0; $n < $count; $n++ ) {
                // Key / property name: copied as stored.
                $key_start = $i;
                if ( ( $s[ $i ] ?? '' ) === 'i' ) {
                    if ( self::rewrite_serialized( $s, $i, [], $depth, $level + 1 ) === null ) {
                        return null;
                    }
                } elseif ( self::read_length_prefixed( $s, $i, '";' ) === null ) {
                    return null;
                }
                $output .= substr( $s, $key_start, $i - $key_start );

                $value = self::rewrite_serialized( $s, $i, $pairs, $depth, $level + 1 );
                if ( $value === null ) {
                    return null;
                }
                $output .= $value;
            }

            if ( ( $s[ $i ] ?? '' ) !== '}' ) {
                return null;
            }
            $i++;
            return $output . '}';
        }

        return null;
    }

    /**
     * Reads X:len:"payload" followed by $terminator at $i (s:, E:, and the
     * class name of O:/C:), advances $i past the terminator and returns the
     * payload, or null if the length doesn't land on the terminator.
     */
    private static function read_length_prefixed( $s, &$i, $terminator ) {
        if ( ! preg_match( '/\G[sEOC]:([0-9]+):"/', $s, $m, 0, $i ) ) {
            return null;
        }
        $length = (int) $m[1];
        $start  = $i + strlen( $m[0] );
        if ( substr( $s, $start + $length, strlen( $terminator ) ) !== $terminator ) {
            return null;
        }
        $i = $start + $length + strlen( $terminator );
        return substr( $s, $start, $length );
    }

    /**
     * Remaps prefix-scoped values that live inside the data (not just table
     * names) after a cross-prefix import: usermeta keys like
     * "{prefix}capabilities"/"{prefix}user_level" and the "{prefix}user_roles"
     * option. Without this, migrated users lose their roles and get locked out.
     * Operates on the destination (new-prefix) tables.
     */
    public function remap_table_prefix( $old_prefix, $new_prefix ) {
        global $wpdb;
        $target = $this->target();
        if ( $target && $target->prefix !== '' ) {
            $new_prefix = $target->prefix;
        }

        if ( $old_prefix === '' || $old_prefix === $new_prefix ) {
            return [ 'keys_changed' => 0 ];
        }

        $changed = 0;

        // usermeta: core prefix-scoped meta keys only. An allowlist keeps a
        // plugin key that merely *starts* with the old prefix as literal text
        // (e.g. "wp_stripe_customer_id" on a "wp_" -> "mig_" move) from being
        // rewritten. Plugin keys that adopt the prefix are intentionally left
        // alone — their plugins regenerate them.
        $allowed_keys = [ 'capabilities', 'user_level', 'user-settings', 'user-settings-time', 'persisted_preferences' ];
        $usermeta     = $new_prefix . 'usermeta';
        // A network's usermeta holds every site's keys, and the import
        // already merged the incoming users' ones under this site's prefix
        // (see Target::merge_users()): a key there that starts with the
        // source's prefix (wp_5_ from another network) is another site's.
        if ( is_multisite() ) {
            $usermeta = '';
        }
        if ( $usermeta !== '' && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $usermeta ) ) ) === $usermeta ) {
            $like = $wpdb->esc_like( $old_prefix ) . '%';
            $rows = $wpdb->get_results(
                $wpdb->prepare( "SELECT umeta_id, meta_key FROM `{$usermeta}` WHERE meta_key LIKE %s", $like )
            );
            foreach ( $rows as $row ) {
                $remainder = substr( $row->meta_key, strlen( $old_prefix ) );
                if ( ! in_array( $remainder, $allowed_keys, true ) && strpos( $remainder, 'dashboard_' ) !== 0 ) {
                    continue;
                }
                // Only rewrite the leading prefix, keep the remainder intact.
                $wpdb->update( $usermeta, [ 'meta_key' => $new_prefix . $remainder ], [ 'umeta_id' => $row->umeta_id ] );
                $changed++;
            }
        }

        // options: the roles are stored under "{prefix}user_roles".
        $options = $new_prefix . 'options';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $options ) ) ) === $options ) {
            $res = $wpdb->update(
                $options,
                [ 'option_name' => $new_prefix . 'user_roles' ],
                [ 'option_name' => $old_prefix . 'user_roles' ]
            );
            if ( $res ) {
                $changed += $res;
            }
        }

        wp_cache_flush();

        return [ 'keys_changed' => $changed ];
    }

    /** After a rollback's tables are back: drops the tables the move added (see rollback()). */
    private function drop_added_tables( $meta ) {
        global $wpdb;
        $kept = array_flip( array_map( 'strval', (array) ( $meta['tables'] ?? [] ) ) );
        if ( ! empty( $meta['target'] ) ) {
            $only = (string) ( $meta['target']['prefix'] ?? '' );
            if ( $only === '' ) {
                return;
            }
            $owned = [];
            foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $only ) . '%' ) ) as $t ) {
                // On a network, wp_1_ doesn't exist and wp_10_ starts with wp_1.
                if ( ( $meta['target']['kind'] ?? '' ) === 'multisite' && preg_match( '/^' . preg_quote( $only, '/' ) . '\d+_/', (string) $t ) ) {
                    continue;
                }
                $owned[ (string) $t ] = true;
            }
        } else {
            // A restore point that doesn't list its tables can't tell added ones apart.
            if ( empty( $meta['tables'] ) ) {
                return;
            }
            $owned = array_flip( self::own_tables() );
            foreach ( $this->imported_tables() as $t ) {
                if ( ! self::other_sites_table( $t ) ) {
                    $owned[ $t ] = true;
                }
            }
        }
        $wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );
        foreach ( (array) $wpdb->get_results( 'SHOW FULL TABLES', ARRAY_N ) as $row ) {
            if ( ! isset( $owned[ $row[0] ] ) || isset( $kept[ $row[0] ] ) ) {
                continue;
            }
            $kind = strtoupper( (string) ( $row[1] ?? '' ) ) === 'VIEW' ? 'VIEW' : 'TABLE';
            $wpdb->query( "DROP {$kind} IF EXISTS `" . str_replace( '`', '``', $row[0] ) . '`' );
        }
        $wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' );
    }

    /** A WP Freighter main site's registry rows as they are now, or [] elsewhere. */
    private function registry_rows() {
        global $wpdb;
        if ( Scope::freighter_primary() === '' || Scope::freighter_primary() !== $wpdb->prefix ) {
            return [];
        }
        return (array) $wpdb->get_results( "SELECT option_name, option_value, autoload FROM `{$wpdb->options}` WHERE option_name IN ( 'stacked_sites', 'stacked_configurations' )", ARRAY_A );
    }

    /** Puts registry_rows() back over whatever the restored options table holds. */
    private function put_registry_rows( $rows ) {
        global $wpdb;
        foreach ( (array) $rows as $row ) {
            $name = (string) ( $row['option_name'] ?? '' );
            if ( $name === '' ) {
                continue;
            }
            if ( $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM `{$wpdb->options}` WHERE option_name = %s", $name ) ) ) {
                $wpdb->update( $wpdb->options, [ 'option_value' => (string) $row['option_value'] ], [ 'option_name' => $name ] );
            } else {
                $wpdb->insert( $wpdb->options, [ 'option_name' => $name, 'option_value' => (string) $row['option_value'], 'autoload' => (string) ( $row['autoload'] ?? 'yes' ) ] );
            }
        }
        if ( $rows ) {
            wp_cache_delete( 'alloptions', 'options' );
            wp_cache_delete( 'notoptions', 'options' );
        }
    }

    /**
     * Restores the destination to pre-import state using the rollback snapshot.
     *
     * With a $budget (seconds, see Pace) it works in pieces like the other
     * long steps: the snapshot's tables go back statement by statement until
     * the budget, then the tables the move added go, then the files, each
     * call answering { done: false } with where it got to and the next one
     * carrying on from there. Statements a killed call ran past its last
     * checkpoint are journaled, so none runs twice. Without a budget (an
     * older client), all of it in this one request.
     */
    public function rollback( $budget = null ) {
        global $wpdb;
        $paced      = $budget !== null;
        $deadline   = $paced ? Pace::deadline( $budget ) : 0;
        $state_file = $this->import_path . '/rollback-state.php';
        $done_file  = $this->import_path . '/rollback-done.php';
        $st         = json_decode( (string) self::read_protected( $state_file ), true );
        if ( ! is_array( $st ) ) {
            // Asked again right after it finished (a lost answer): the same
            // answer, not a second rollback.
            $done = json_decode( (string) self::read_protected( $done_file ), true );
            if ( $paced && is_array( $done ) && (int) ( $done['at'] ?? 0 ) > time() - 600 ) {
                return (array) $done['result'];
            }
            // A paced deploy that stopped partway journaled what it placed.
            $this->merge_deploy_journal();
            $meta = self::rollback_meta( $this->rollback_path );
            if ( $meta === null ) {
                return new \WP_Error( 'no_rollback', 'No rollback data found for this import ID.', [ 'status' => 404 ] );
            }
            // Validate the whole snapshot before anything is dropped: a
            // missing, truncated or unreadable snapshot must leave the
            // current database alone.
            $sql_files = self::rollback_sql_files( $this->rollback_path );
            $check     = self::validate_snapshot( $sql_files, $meta );
            if ( is_wp_error( $check ) ) {
                return $check;
            }
            $st = [
                'phase'    => 'database',
                'files'    => array_map( 'basename', $sql_files ),
                'f'        => 0,
                'o'        => 0,
                'i'        => 0,
                'errors'   => [],
                // The main site of a WP Freighter install keeps its registry
                // as it is now (tenants made since the restore point
                // included), not as its options table had it then.
                'registry' => ! empty( $meta['target'] ) ? [] : $this->registry_rows(),
            ];
            @unlink( $this->import_path . '/rollback-journal.php' );
            @unlink( $done_file );
            if ( $paced ) {
                $this->prepare_import_dir();
                self::write_protected( $state_file, (string) wp_json_encode( $st ) );
            }
        }
        $meta = (array) self::rollback_meta( $this->rollback_path );
        $save = function () use ( &$st, $state_file, $paced ) {
            if ( $paced ) {
                self::write_protected( $state_file, (string) wp_json_encode( $st ) );
            }
        };

        // 1. The snapshot's tables, then what the move added beside them.
        if ( $st['phase'] === 'database' ) {
            if ( ! $this->rollback_tables( $st, $deadline, $save ) ) {
                return [ 'done' => false, 'phase' => 'database', 'parts_done' => (int) $st['f'], 'parts_total' => count( $st['files'] ) ];
            }
            $errors = (array) $st['errors'];
            // Tables the restore added (the source's plugin tables) go only
            // once the snapshot is fully back, and only tables this site
            // owns: a subsite's or tenant's own prefix, or this site's own
            // tables and the ones this move imported. Never another site's
            // of the install, one made after the restore point included.
            if ( $st['files'] && empty( $errors ) && empty( $meta['partial'] ) ) {
                $this->drop_added_tables( $meta );
            }
            $this->put_registry_rows( (array) $st['registry'] );
            wp_cache_flush();
            // Users the move created or gave a role on a network go again,
            // and the roles it replaced come back (see Target::merge_users()).
            if ( empty( $errors ) ) {
                Target::undo_merge( $this->rollback_path . '/users-merge.php' );
            }
            // A catch-up pull's memory goes back to what it was before this move.
            Pull::restore_memory( $this->rollback_path );
            $st['phase'] = 'files';
            $st['i']     = 0;
            $save();
        }

        // 2. Files: the ones the move replaced come back, the ones it added go.
        $web_root  = dirname( WP_CONTENT_DIR );
        $backed_up = array_values( (array) ( $meta['backed_up_files'] ?? [] ) );
        $deployed  = array_values( (array) ( $meta['deployed_files'] ?? [] ) );
        $was_saved = array_flip( $backed_up );
        $removed   = false;
        $total     = count( $backed_up ) + count( $deployed );
        for ( ; $st['i'] < $total; $st['i']++ ) {
            if ( $paced && $st['i'] % 200 === 0 && microtime( true ) >= $deadline ) {
                $save();
                if ( $removed ) {
                    self::quiet_shutdown();
                }
                return [ 'done' => false, 'phase' => 'files', 'files_done' => (int) $st['i'], 'files_total' => $total ];
            }
            if ( $st['i'] < count( $backed_up ) ) {
                $relative_path = $backed_up[ $st['i'] ];
                $backup_src    = $this->rollback_file( $relative_path );
                $dest          = $web_root . '/' . $relative_path;
                if ( file_exists( $backup_src ) ) {
                    if ( ! is_dir( dirname( $dest ) ) ) {
                        mkdir( dirname( $dest ), 0755, true );
                    }
                    self::take_staged( $backup_src, $dest );
                }
                continue;
            }
            // Newly deployed files that weren't overwriting originals.
            $relative_path = $deployed[ $st['i'] - count( $backed_up ) ];
            if ( isset( $was_saved[ $relative_path ] ) ) {
                continue; // Already restored from backup
            }
            if ( file_exists( $web_root . '/' . $relative_path ) ) {
                @unlink( $web_root . '/' . $relative_path );
                $removed = true;
            }
        }
        $errors = (array) $st['errors'];
        $target = ! empty( $meta['target'] ) ? $this->target() : null;

        // Folders the move created (a plugin it brought, a WP Freighter
        // install's content/<id>) go too once empty. rmdir() never removes a
        // folder that still holds anything.
        $content = ltrim( substr( wp_normalize_path( WP_CONTENT_DIR ), strlen( wp_normalize_path( $web_root ) ) ), '/' );
        $keep    = function ( $dir ) use ( $content ) {
            return in_array( $dir, [ 'wp-admin', 'wp-includes', 'wp-content', $content ], true ) || dirname( $dir ) === $content || dirname( $dir ) === 'wp-content';
        };
        if ( isset( $meta['created_dirs'] ) ) {
            // The deploy recorded the folders it added: those go (deepest
            // first, once empty), and folders that were here before stay,
            // empty or not.
            $dirs = array_filter( (array) $meta['created_dirs'], function ( $dir ) {
                return self::is_safe_relative_path( (string) $dir ) && ! in_array( (string) $dir, [ 'wp-admin', 'wp-includes', 'wp-content' ], true );
            } );
            usort( $dirs, function ( $a, $b ) { return substr_count( $b, '/' ) - substr_count( $a, '/' ) ?: strcmp( $b, $a ); } );
            foreach ( $dirs as $dir ) {
                @rmdir( $web_root . '/' . $dir );
            }
        } else {
            // A restore point from an earlier build: folders that end up
            // empty go, walking up, short of the ones above.
            $emptied = [];
            foreach ( $deployed as $relative_path ) {
                if ( ! isset( $was_saved[ $relative_path ] ) ) {
                    $emptied[ dirname( $relative_path ) ] = true;
                }
            }
            krsort( $emptied );
            foreach ( array_keys( $emptied ) as $dir ) {
                while ( $dir !== '.' && $dir !== '' && ! $keep( $dir ) && @rmdir( $web_root . '/' . $dir ) ) {
                    $dir = dirname( $dir );
                }
            }
        }

        // A subsite or tenant this move created goes completely.
        if ( $target && ! empty( $meta['target']['created'] ) ) {
            $target->undo();
        }

        if ( $removed || array_diff( $deployed, $backed_up ) ) {
            self::quiet_shutdown();
        }

        @unlink( $this->import_path . '/token-guard.php' );
        @unlink( $this->import_path . '/token-guard.json' );
        @unlink( $this->import_path . '/pull.json' );
        @unlink( $this->import_path . '/pull-secret.php' );

        // Quiet mode turned on by this restore goes with it.
        $quiet_lifted = empty( $errors ) && Quiet::lift_for_rollback( (string) $this->import_id );

        // Remember the rollback so the restore points list can show it.
        if ( empty( $errors ) ) {
            $saved = self::rollback_meta( $this->rollback_path );
            if ( $saved !== null ) {
                $saved['rolled_back_at'] = time();
                $this->save_rollback_meta( $saved );
            }
        }

        $result = [
            'success'          => empty( $errors ),
            'tables_restored'  => count( (array) ( $meta['saved_tables'] ?? $meta['tables'] ?? $st['files'] ) ),
            'files_restored'   => count( $backed_up ),
            'files_removed'    => count( array_diff( $deployed, $backed_up ) ),
            'quiet_mode_lifted' => $quiet_lifted,
            // A move into a subsite or tenant, or into a network (whose
            // users stay), leaves this site's logins as they were.
            'logins_kept'      => ! empty( $meta['target'] ) || is_multisite(),
            'errors'           => $errors,
        ];
        @unlink( $state_file );
        @unlink( $this->import_path . '/rollback-journal.php' );
        if ( $paced ) {
            $result['done'] = true;
            self::write_protected( $done_file, (string) wp_json_encode( [ 'at' => time(), 'result' => $result ] ) );
        }
        return $result;
    }

    /**
     * Plugins the rollback removed loaded at the start of this request:
     * their shutdown hooks would autoload classes from deleted files and
     * fatal (into the JSON response where errors are displayed). Only
     * core's output flush still belongs here.
     */
    private static function quiet_shutdown() {
        remove_all_actions( 'shutdown' );
        add_action( 'shutdown', 'wp_ob_end_flush_all', 1 );
    }

    /**
     * Puts the snapshot's tables back (see rollback()), from where $st says
     * it got to, until $deadline when there is one. Each table is dropped
     * just before the snapshot recreates it (DROP-before-CREATE), never all
     * up front, so a rollback that stops midway leaves every table present
     * and carries on. True once every dump is back.
     */
    private function rollback_tables( array &$st, $deadline, $save ) {
        global $wpdb;
        $journal_file = $this->import_path . '/rollback-journal.php';
        $paced        = $deadline > 0;
        // A call that was killed got past its last checkpoint: the journal
        // says how far, and the statement just after may have run too.
        $last     = $paced ? Pace::journal_last( $journal_file ) : null;
        $tolerant = false;
        if ( $last && ( (int) $last['f'] > (int) $st['f'] || ( (int) $last['f'] === (int) $st['f'] && (int) $last['o'] > (int) $st['o'] ) ) ) {
            $st['f']  = (int) $last['f'];
            $st['o']  = (int) $last['o'];
            $tolerant = true;
        }
        $run = $this->begin_statements();
        // Rows go back many at a time, as the import sends them (one
        // statement per row made a large table take minutes).
        $packet  = (int) $wpdb->get_var( 'SELECT @@max_allowed_packet' );
        $max     = (int) max( 65536, min( 1048576, $packet > 0 ? $packet / 2 : 1048576 ) );
        $pending = null;
        $journal = $paced ? Pace::journal_open( $journal_file ) : null;
        $fi      = (int) $st['f'];
        $exec    = function ( $sql ) use ( &$run, &$tolerant ) {
            $before = count( $run['errors'] );
            $more   = $this->run_statement( $sql, $run );
            if ( $tolerant && count( $run['errors'] ) > $before && preg_match( '/Duplicate entry|already exists/i', (string) $run['errors'][ $before ] ) ) {
                array_splice( $run['errors'], $before );
            }
            $tolerant = false;
            return $more;
        };
        $flush = function () use ( &$pending, $exec, $journal, &$fi ) {
            if ( ! $pending ) {
                return true;
            }
            $batch   = $pending;
            $pending = null;
            $more    = $exec( $batch['head'] . implode( ',', $batch['rows'] ) );
            Pace::journal_add( $journal, [ 'f' => $fi, 'o' => $batch['end'] ] );
            return $more;
        };
        $stop_at = 0;
        for ( ; $fi < count( $st['files'] ); $fi++ ) {
            $path = $this->rollback_path . '/' . $st['files'][ $fi ];
            $read = self::each_sql_statement_in_file( $path, function ( $statement, $end ) use ( &$pending, &$stop_at, $exec, $flush, $max, $journal, &$fi, $paced, $deadline ) {
                foreach ( self::transform_statements( [ $statement ] ) as $transformed ) {
                    $transformed = trim( $transformed );
                    if ( strlen( $transformed ) < $max && preg_match( '/^(INSERT\s+INTO\s+`(?:[^`]|``)+`\s+VALUES\s*)(\(.*\))$/s', $transformed, $im ) ) {
                        if ( $pending && ( $pending['head'] !== $im[1] || $pending['bytes'] + strlen( $im[2] ) >= $max || count( $pending['rows'] ) >= 2000 ) && ! $flush() ) {
                            return false;
                        }
                        if ( ! $pending ) {
                            $pending = [ 'head' => $im[1], 'rows' => [], 'bytes' => strlen( $im[1] ) ];
                        }
                        $pending['rows'][] = $im[2];
                        $pending['bytes'] += strlen( $im[2] ) + 1;
                        $pending['end']    = $end;
                        continue;
                    }
                    if ( ! $flush() || ! $exec( $transformed ) ) {
                        return false;
                    }
                }
                if ( ! $pending ) {
                    Pace::journal_add( $journal, [ 'f' => $fi, 'o' => $end ] );
                }
                if ( $paced && microtime( true ) >= $deadline ) {
                    if ( ! $flush() ) {
                        return false;
                    }
                    $stop_at = $end;
                    return false;
                }
                return true;
            }, $fi === (int) $st['f'] ? (int) $st['o'] : 0 );
            if ( $stop_at === 0 ) {
                $flush();
            }
            if ( is_wp_error( $read ) ) {
                $run['errors'][] = $read->get_error_message();
            }
            if ( $stop_at > 0 && ! is_wp_error( $read ) ) {
                break;
            }
            $st['o'] = 0;
            if ( is_wp_error( $read ) || count( $run['errors'] ) > 50 ) {
                $fi = count( $st['files'] );
                break;
            }
            $st['f'] = $fi + 1;
            $save();
        }
        if ( $journal ) {
            fclose( $journal );
        }
        $st['errors'] = array_merge( (array) $st['errors'], $run['errors'] );
        if ( $stop_at > 0 ) {
            // Out of time: everything in the journal is in the checkpoint now.
            $st['f'] = $fi;
            $st['o'] = $stop_at;
            $save();
            @unlink( $journal_file );
            $wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' );
            $wpdb->query( 'SET UNIQUE_CHECKS = 1' );
            return false;
        }
        @unlink( $journal_file );
        // The snapshot's own token row is the right one now; don't
        // overwrite it with whatever the imported site had.
        $run['preserved_token'] = '';
        $run['errors']          = [];
        $this->end_statements( $run );
        return true;
    }

    /**
     * Cleans up staging directory. Keeps rollback data.
     */
    /** Whether Cancel was pressed for this import (see cancel()). */
    public function cancelled() {
        clearstatcache( true, $this->import_path . '/cancel' );
        return is_file( $this->import_path . '/cancel' );
    }

    /**
     * Cancel. 'stop' leaves a marker the long steps check, so the step that's
     * running ends early. 'cleanup' removes everything the move left: the
     * staging folder, its working files, the source's temporary files when
     * it can still reach them, and the whole import folder when no rollback
     * point was saved. With $delete_restore_point (after a successful
     * rollback) the rollback point goes too.
     */
    public function cancel( $action, $delete_restore_point = false ) {
        if ( $action === 'stop' ) {
            Storage::ensure_dir( $this->import_path );
            file_put_contents( $this->import_path . '/cancel', (string) time() );
            return [ 'ok' => true, 'stopping' => true ];
        }
        ( new Pull( $this->import_id ) )->cleanup_source();
        $this->merge_deploy_journal();
        $this->remove_pace_files();
        if ( is_dir( $this->staging_path ) ) {
            self::delete_directory_recursive( $this->staging_path );
        }
        foreach ( [ 'pull.json', 'pull-secret.php', 'token-guard.php', 'token-guard.json', 'skip-rows.json', 'unchanged.json', 'delete-list.json', 'cancel' ] as $name ) {
            @unlink( $this->import_path . '/' . $name );
        }
        $has_point = self::has_restore_point( $this->import_path );
        if ( ! $has_point || $delete_restore_point ) {
            self::delete_directory_recursive( $this->import_path );
            $has_point = false;
        }
        return [ 'ok' => true, 'restore_point_kept' => $has_point ];
    }

    /** The working files of paced steps (see Pace), once a move is done or cancelled. */
    private function remove_pace_files() {
        $this->merge_deploy_journal();
        foreach ( [ 'sql-state.php', 'sql-journal.php', 'deploy-list.php', 'deploy-journal.php', 'replace-state.php', 'replace-journal.php', 'extract-state.php', 'fetched.php', 'fetched.lock', 'database-state.php', 'pace.php', 'db-plan.php', 'db-peek.php', 'settle-state.php', 'fingerprints.php', 'rollback/snapshot-state.php', 'rollback/snapshot-add-state.php' ] as $name ) {
            @unlink( $this->import_path . '/' . $name );
        }
        foreach ( (array) glob( $this->rollback_path . '/*.db.php.part' ) as $part ) {
            @unlink( $part );
        }
    }

    public function finalize() {
        $target = $this->target();
        // Remember the source's files now on this site, for a catch-up pull.
        if ( ! $target ) {
            ( new Pull( $this->import_id ) )->remember_files();
        }

        // Read before staging (which holds the source's details) goes.
        $source = $this->move_source();
        if ( $target ) {
            // A subsite or tenant: its own settings, and the page caches.
            $target->finish();
            $tidy = [ 'opcache_reset' => self::reset_opcache(), 'caches_cleared' => self::clear_page_caches(), 'plugins_turned_off' => [], 'login_url' => null, 'target' => $target->info() ];
        } else {
            $tidy = $this->tidy( $source );
            $tidy['tables_removed'] = $this->drop_extra_tables();
        }
        Verify::save_context( $this->import_path, (string) ( is_object( $source ) ? ( $source->home_url ?? '' ) : '' ), (string) ( $tidy['login_url']['url'] ?? '' ), $target );

        // Remove staging directory
        if ( is_dir( $this->staging_path ) ) {
            self::delete_directory_recursive( $this->staging_path );
        }

        // Remove leftover chunk directories
        $chunks_dir = $this->staging_path;
        if ( is_dir( $chunks_dir ) ) {
            self::delete_directory_recursive( $chunks_dir );
        }

        // The pull state holds the source's full-access token; the token guard
        // is only needed while the import is in flight.
        @unlink( $this->import_path . '/pull.json' );
        @unlink( $this->import_path . '/pull-secret.php' );
        @unlink( $this->import_path . '/token-guard.php' );
        @unlink( $this->import_path . '/token-guard.json' );
        @unlink( $this->import_path . '/unchanged.json' );
        $this->remove_pace_files();

        // Flush rewrite rules (a subsite or tenant had its own cleared)
        if ( ! $target ) {
            flush_rewrite_rules();
        }

        // Flush object cache
        wp_cache_flush();

        // A quiet mode turned on for this restore now binds to the address
        // the site ended with.
        Quiet::bind();

        return [
            'success'     => true,
            'rollback_id' => $this->import_id,
            'message'     => 'Import finalized. Rollback data retained.',
            'tidy'        => $tidy,
        ];
    }

    /**
     * After a whole-site pull: this site's tables that the incoming site
     * doesn't have (a plugin only the old site ran, a table the source
     * dropped since the last catch-up) go, so the copy matches the source.
     * Only tables the restore point holds (a rollback brings them back), only
     * under this site's prefix, and never one that belongs to the rest of an
     * install: a WP Freighter tenant's (stacked_<id>_), a network's subsites
     * and shared tables, or another WordPress in this database whose prefix
     * starts with this one's (wp_ and wp_staging_). In doubt, a table stays.
     * Returns the names dropped.
     */
    private function drop_extra_tables() {
        global $wpdb;
        $incoming = ( new Pull( $this->import_id ) )->incoming_tables();
        $meta     = self::rollback_meta( $this->rollback_path );
        $saved    = $meta === null ? [] : array_flip( (array) ( $meta['saved_tables'] ?? $meta['tables'] ?? [] ) );
        $prefix   = strtolower( (string) $wpdb->prefix );
        if ( ! $incoming || ! $saved || $prefix === '' ) {
            return [];
        }
        $rows  = (array) $wpdb->get_results( 'SHOW FULL TABLES', ARRAY_N );
        $names = array_map( function ( $r ) { return strtolower( (string) $r[0] ); }, $rows );
        $all   = array_flip( $names );
        $other = [];
        foreach ( $names as $n ) {
            if ( substr( $n, -7 ) === 'options' && ( $p = substr( $n, 0, -7 ) ) !== $prefix && strpos( $p, $prefix ) === 0 && isset( $all[ $p . 'posts' ] ) ) {
                $other[] = $p;
            }
        }
        $shared = [];
        if ( is_multisite() ) {
            foreach ( array_merge( (array) $wpdb->global_tables, (array) $wpdb->ms_global_tables ) as $t ) {
                $shared[ strtolower( $wpdb->base_prefix . $t ) ] = true;
            }
        }
        $dropped = [];
        foreach ( $rows as $row ) {
            $table = (string) $row[0];
            $lower = strtolower( $table );
            if ( strpos( $lower, $prefix ) !== 0 || isset( $incoming[ $lower ] ) || ! isset( $saved[ $table ] ) ) {
                continue;
            }
            if ( preg_match( '/^stacked_(\d+)_/', $lower, $m ) && 'stacked_' . $m[1] . '_' !== $prefix ) {
                continue;
            }
            if ( is_multisite() && ( isset( $shared[ $lower ] ) || preg_match( '/^' . preg_quote( strtolower( $wpdb->base_prefix ), '/' ) . '\d+_/', $lower ) ) ) {
                continue;
            }
            foreach ( $other as $p ) {
                if ( strpos( $lower, $p ) === 0 ) {
                    continue 2;
                }
            }
            // The host's own tables (its firewall's, like the mu-plugins a
            // move keeps) belong to this server, not to the site.
            foreach ( self::HOST_TABLES as $host ) {
                if ( strpos( substr( $lower, strlen( $prefix ) ), $host ) === 0 ) {
                    continue 2;
                }
            }
            $kind = strtoupper( (string) ( $row[1] ?? '' ) ) === 'VIEW' ? 'VIEW' : 'TABLE';
            if ( $wpdb->query( "DROP {$kind} IF EXISTS `" . str_replace( '`', '``', $table ) . '`' ) !== false ) {
                $dropped[] = $table;
            }
        }
        return $dropped;
    }

    /* ------------------------------------------------------------------
     * After a move
     *
     * The things that make a finished move look broken even though every
     * byte arrived: page caches still serving the site this one replaced, a
     * login page moved by a hide-login plugin (so wp-login.php is a 404),
     * force-SSL plugins redirecting a copy that has no certificate, and
     * login captchas whose keys only work on the old domain.
     * ---------------------------------------------------------------- */

    private function tidy( $source ) {
        $source = (array) $source;
        $from   = rtrim( (string) ( $source['home_url'] ?? '' ), '/' );
        $here   = rtrim( home_url(), '/' );
        $moved  = $from !== '' && Quiet::address( $from ) !== Quiet::address( $here );
        wp_cache_delete( 'alloptions', 'options' );

        $out = [
            'opcache_reset'      => self::reset_opcache(),
            'caches_cleared'     => self::clear_page_caches(),
            'plugins_turned_off' => [],
            'login_url'          => null,
        ];
        if ( $moved && strtolower( (string) wp_parse_url( $here, PHP_URL_SCHEME ) ) === 'http' ) {
            $out['plugins_turned_off'] = array_merge( $out['plugins_turned_off'], self::turn_off_plugins( self::FORCE_SSL_PLUGINS, 'it would redirect this http:// copy to https://' ) );
            if ( get_option( 'woocommerce_force_ssl_checkout' ) === 'yes' ) {
                update_option( 'woocommerce_force_ssl_checkout', 'no' );
            }
            self::remove_htaccess_https_blocks();
        }
        if ( $moved && Quiet::status()['on'] ) {
            $out['plugins_turned_off'] = array_merge( $out['plugins_turned_off'], self::turn_off_plugins( self::LOGIN_CAPTCHA_PLUGINS, 'its captcha keys belong to ' . wp_parse_url( $from, PHP_URL_HOST ) . ', so the login form would refuse every login' ) );
            if ( self::turn_off_wordfence_captcha() ) {
                $out['plugins_turned_off'][] = [ 'plugin' => 'wordfence', 'name' => 'Wordfence login reCAPTCHA', 'why' => 'its reCAPTCHA keys belong to ' . wp_parse_url( $from, PHP_URL_HOST ) ];
            }
        }
        $out['login_url'] = self::custom_login_url();
        return $out;
    }

    /** Plugin folders that force https (redirects, or rewriting the site URL). */
    const FORCE_SSL_PLUGINS = [
        'really-simple-ssl'                => 'Really Simple Security',
        'really-simple-ssl-pro'            => 'Really Simple Security Pro',
        'really-simple-ssl-pro-multisite'  => 'Really Simple Security Pro',
        'wp-force-ssl'                     => 'WP Force SSL',
        'https-redirection'                => 'Easy HTTPS Redirection',
        'easy-https-redirection'           => 'Easy HTTPS Redirection',
        'one-click-ssl'                    => 'One Click SSL',
        'wp-ssl-redirect'                  => 'WP SSL Redirect',
    ];

    /** Plugin folders that put a domain-bound captcha on the login form. */
    const LOGIN_CAPTCHA_PLUGINS = [
        'simple-cloudflare-turnstile'      => 'Simple Cloudflare Turnstile',
        'advanced-google-recaptcha'        => 'Advanced Google reCAPTCHA',
        'advanced-nocaptcha-recaptcha'     => 'CAPTCHA 4WP',
        'login-recaptcha'                  => 'Login No Captcha reCAPTCHA',
        'google-captcha'                   => 'reCaptcha by BestWebSoft',
        'hcaptcha-for-forms-and-more'      => 'hCaptcha for WordPress',
        'wp-recaptcha-integration'         => 'WordPress ReCaptcha Integration',
        'invisible-recaptcha'              => 'Invisible reCaptcha',
        'no-captcha-recaptcha'             => 'No CAPTCHA reCAPTCHA',
    ];

    /** Deactivates the active plugins whose folder is a key of $folders. */
    private static function turn_off_plugins( $folders, $why ) {
        $off    = [];
        $active = (array) get_option( 'active_plugins', [] );
        $keep   = [];
        foreach ( $active as $plugin ) {
            $folder = strtok( (string) $plugin, '/' );
            if ( isset( $folders[ $folder ] ) ) {
                $off[ $plugin ] = [ 'plugin' => $plugin, 'name' => $folders[ $folder ], 'why' => $why ];
                continue;
            }
            $keep[] = $plugin;
        }
        if ( count( $keep ) !== count( $active ) ) {
            update_option( 'active_plugins', $keep );
        }
        if ( is_multisite() ) {
            $network = (array) get_site_option( 'active_sitewide_plugins', [] );
            $changed = false;
            foreach ( array_keys( $network ) as $plugin ) {
                $folder = strtok( (string) $plugin, '/' );
                if ( isset( $folders[ $folder ] ) ) {
                    $off[ $plugin ] = [ 'plugin' => $plugin, 'name' => $folders[ $folder ], 'why' => $why ];
                    unset( $network[ $plugin ] );
                    $changed = true;
                }
            }
            if ( $changed ) {
                update_site_option( 'active_sitewide_plugins', $network );
            }
        }
        return array_values( $off );
    }

    /** Wordfence keeps its login captcha switch in its own settings table. */
    private static function turn_off_wordfence_captcha() {
        global $wpdb;
        $table = $wpdb->base_prefix . 'wfls_settings';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
            return false;
        }
        $value = $wpdb->get_var( $wpdb->prepare( "SELECT `value` FROM `{$table}` WHERE `name` = %s", 'enable-auth-captcha' ) );
        if ( (string) $value !== '1' ) {
            return false;
        }
        return (bool) $wpdb->update( $table, [ 'value' => '0' ], [ 'name' => 'enable-auth-captcha' ] );
    }

    /** Really Simple Security's https redirect blocks in the root .htaccess. */
    private static function remove_htaccess_https_blocks() {
        $file = dirname( WP_CONTENT_DIR ) . '/.htaccess';
        if ( ! is_file( $file ) || ! is_writable( $file ) ) {
            return false;
        }
        $code = (string) file_get_contents( $file );
        $new  = preg_replace( '/^# BEGIN (rlrssl\w*|Really Simple (?:SSL|Security)[^\r\n]*)\R.*?^# END \1\R?/ms', '', $code );
        return $new !== null && $new !== $code && file_put_contents( $file, $new ) !== false;
    }

    /**
     * Where this site's login page is when a plugin moved it: after a move
     * the user logs in with the source's account, and wp-login.php is a 404
     * there. Null when the login page is the usual one.
     */
    private static function custom_login_url() {
        $active = array_map( function ( $p ) { return strtok( (string) $p, '/' ); }, (array) get_option( 'active_plugins', [] ) );
        if ( is_multisite() ) {
            foreach ( array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) as $p ) {
                $active[] = strtok( (string) $p, '/' );
            }
        }
        $active = array_flip( $active );
        $slug   = '';
        $by     = '';
        if ( isset( $active['wps-hide-login'] ) ) {
            $slug = (string) ( is_multisite() ? get_site_option( 'whl_page', get_option( 'whl_page' ) ) : get_option( 'whl_page' ) );
            $by   = 'WPS Hide Login';
        } elseif ( isset( $active['rename-wp-login'] ) ) {
            $slug = (string) get_option( 'rwl_page' );
            $by   = 'Rename wp-login.php';
        } elseif ( isset( $active['perfmatters'] ) ) {
            $options = (array) get_option( 'perfmatters_options', [] );
            $slug    = (string) ( $options['login_url'] ?? '' );
            $by      = 'Perfmatters';
        } elseif ( isset( $active['all-in-one-wp-security-and-firewall'] ) ) {
            $options = (array) get_option( 'aio_wp_security_configs', [] );
            $slug    = (string) ( $options['aiowps_enable_rename_login_page'] ?? '' ) === '1' ? (string) ( $options['aiowps_login_page_slug'] ?? '' ) : '';
            $by      = 'All In One WP Security';
        } elseif ( isset( $active['better-wp-security'] ) || isset( $active['ithemes-security-pro'] ) ) {
            $storage = (array) get_site_option( 'itsec-storage', [] );
            $hide    = (array) ( $storage['hide-backend'] ?? [] );
            $slug    = ! empty( $hide['enabled'] ) ? (string) ( $hide['slug'] ?? '' ) : '';
            $by      = 'Solid Security';
        }
        $slug = trim( $slug, '/ ' );
        if ( $slug === '' || $slug === 'wp-login.php' ) {
            return null;
        }
        $url = get_option( 'permalink_structure' ) ? trailingslashit( home_url( $slug ) ) : add_query_arg( $slug, '', home_url( '/' ) );
        return [ 'url' => $url, 'plugin' => $by ];
    }

    /**
     * Empties the page and asset caches cache plugins keep on disk, and asks
     * the cache plugins and hosts that are running to purge. A copied or
     * left-behind cache serves the site this one replaced: the destination
     * keeps its own advanced-cache.php, which happily serves the old pages.
     * Everything here is rebuilt on the next visit. Returns what was cleared.
     */
    private static function clear_page_caches() {
        $content = wp_normalize_path( WP_CONTENT_DIR );
        $uploads = wp_normalize_path( (string) ( wp_upload_dir( null, false )['basedir'] ?? '' ) );
        $known   = [
            'WP Rocket'         => [ '/cache/wp-rocket', '/cache/min', '/cache/busting', '/cache/critical-css', '/cache/background-css' ],
            'WP Super Cache'    => [ '/cache/supercache', '/cache/blogs' ],
            'W3 Total Cache'    => [ '/cache/page_enhanced', '/cache/minify', '/cache/pgcache' ],
            'WP Fastest Cache'  => [ '/cache/all', '/cache/wpfc-minified', '/cache/wpfc-mobile-cache' ],
            'Breeze'            => [ '/cache/breeze', '/cache/breeze-minification' ],
            'Cache Enabler'     => [ '/cache/cache-enabler' ],
            'Comet Cache'       => [ '/cache/comet-cache' ],
            'FlyingPress'       => [ '/cache/flying-press' ],
            'Swift Performance' => [ '/cache/swift-performance' ],
            'Autoptimize'       => [ '/cache/autoptimize' ],
            'Hummingbird'       => [ '/wphb-cache' ],
            'LiteSpeed Cache'   => [ '/litespeed' ],
            'Divi'              => [ '/et-cache' ],
        ];
        $cleared = [];
        foreach ( $known as $name => $dirs ) {
            $files = 0;
            foreach ( $dirs as $dir ) {
                $files += self::empty_directory( $content . $dir );
            }
            if ( $files ) {
                $cleared[ $name ] = $files;
            }
        }
        if ( $uploads !== '' ) {
            $files = self::empty_directory( $uploads . '/bb-plugin/cache' );
            if ( $files ) {
                $cleared['Beaver Builder'] = $files;
            }
        }

        // The cache plugins and hosts running on this site now.
        $purges = [
            'WP Rocket'       => function () { return function_exists( 'rocket_clean_domain' ) ? rocket_clean_domain() || true : false; },
            'W3 Total Cache'  => function () { return function_exists( 'w3tc_flush_all' ) ? w3tc_flush_all() || true : false; },
            'WP Super Cache'  => function () { return function_exists( 'wp_cache_clear_cache' ) ? wp_cache_clear_cache() || true : false; },
            'WP Fastest Cache' => function () { return function_exists( 'wpfc_clear_all_cache' ) ? wpfc_clear_all_cache( true ) || true : false; },
            'SiteGround'      => function () { return function_exists( 'sg_cachepress_purge_cache' ) ? sg_cachepress_purge_cache() || true : false; },
            'Autoptimize'     => function () { return class_exists( 'autoptimizeCache' ) && method_exists( 'autoptimizeCache', 'clearall' ) ? \autoptimizeCache::clearall() || true : false; },
            'LiteSpeed Cache' => function () { return has_action( 'litespeed_purge_all' ) ? ( do_action( 'litespeed_purge_all' ) || true ) : false; },
            'Breeze'          => function () { return has_action( 'breeze_clear_all_cache' ) ? ( do_action( 'breeze_clear_all_cache' ) || true ) : false; },
            'Nginx Helper'    => function () { return has_action( 'rt_nginx_helper_purge_all' ) ? ( do_action( 'rt_nginx_helper_purge_all' ) || true ) : false; },
            'Kinsta'          => function () {
                global $kinsta_cache;
                if ( is_object( $kinsta_cache ) && isset( $kinsta_cache->kinsta_cache_purge ) && method_exists( $kinsta_cache->kinsta_cache_purge, 'purge_complete_caches' ) ) {
                    $kinsta_cache->kinsta_cache_purge->purge_complete_caches();
                    return true;
                }
                return false;
            },
            'WP Engine'       => function () {
                if ( class_exists( 'WpeCommon' ) && method_exists( 'WpeCommon', 'purge_varnish_cache' ) ) {
                    \WpeCommon::purge_varnish_cache();
                    if ( method_exists( 'WpeCommon', 'purge_memcached' ) ) {
                        \WpeCommon::purge_memcached();
                    }
                    return true;
                }
                return false;
            },
        ];
        foreach ( $purges as $name => $purge ) {
            try {
                if ( $purge() && ! isset( $cleared[ $name ] ) ) {
                    $cleared[ $name ] = 0;
                }
            } catch ( \Throwable $e ) {
                // A cache plugin failing to purge doesn't fail the move.
            }
        }
        return $cleared;
    }

    /**
     * Deletes what's inside $dir (not $dir itself, nor its top-level
     * .htaccess and index files, which some plugins rely on). Returns how many
     * files went.
     */
    private static function empty_directory( $dir ) {
        if ( ! is_dir( $dir ) || is_link( $dir ) ) {
            return 0;
        }
        $count = 0;
        foreach ( (array) scandir( $dir ) as $name ) {
            if ( $name === '.' || $name === '..' || $name === '.htaccess' || preg_match( '/^index\.(php|html?)$/', $name ) ) {
                continue;
            }
            $path = $dir . '/' . $name;
            if ( is_dir( $path ) && ! is_link( $path ) ) {
                $count += self::count_files( $path );
                self::delete_directory_recursive( $path );
            } elseif ( @unlink( $path ) ) {
                $count++;
            }
        }
        return $count;
    }

    private static function count_files( $dir ) {
        $count = 0;
        try {
            foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
                if ( $file->isFile() ) {
                    $count++;
                }
            }
        } catch ( \Throwable $e ) {
            return $count;
        }
        return $count;
    }

    /**
     * Splits a SQL dump into individual statements, respecting quoting and
     * comments so a ";" inside a string/identifier/comment never ends a
     * statement. Handles single/double quotes (backslash escapes and doubled
     * quotes), backtick identifiers, "--"/"#" line comments, and "/* *\/"
     * block comments (including "/*!" executable comments). Returns trimmed,
     * non-empty statements without their trailing ";".
     */
    private static function split_sql_statements( $sql ) {
        $statements = [];
        $scan       = self::sql_scanner();
        self::sql_scan( $scan, $sql, true, function ( $statement ) use ( &$statements ) {
            $statements[] = $statement;
            return true;
        } );
        return $statements;
    }

    /**
     * Streams a SQL file statement by statement into $callback (same rules
     * as split_sql_statements()), holding only one statement in memory.
     * $callback returns false to stop early. Returns a summary
     * (statements, ended_cleanly, stopped) or WP_Error if the file can't be read.
     */
    private static function each_sql_statement_in_file( $path, $callback, $start = 0, $delim = ';' ) {
        // A staged dump or a restore point's starts with the exit line (see
        // copy_protected()); a .sql.gz is read through gzip.
        $open = self::open_dump( $path );
        if ( is_wp_error( $open ) ) {
            return $open;
        }
        list( $handle, $gz, $base ) = $open;
        // $delim: the statement delimiter in force at $start (see sql_scan()).
        $scan = self::sql_scanner( $start > 0 ? $delim : ';' );
        if ( $start > 0 && $gz ) {
            // Offsets count unpacked bytes: read up to where it stopped.
            for ( $left = (int) $start; $left > 0 && ! feof( $handle ); ) {
                $skipped = fread( $handle, (int) min( 1048576, $left ) );
                if ( $skipped === false || $skipped === '' ) {
                    break;
                }
                $left -= strlen( $skipped );
            }
            $scan['fed'] = (int) $start;
        } elseif ( $start > 0 ) {
            // Carrying on from a statement boundary an earlier call recorded.
            fseek( $handle, (int) $start );
            $scan['fed'] = (int) $start;
        } else {
            $scan['fed'] = $gz ? 0 : $base;
        }
        $count = 0;
        // The callback also gets the byte offset just past the statement,
        // where a later call can start again.
        $emit  = function ( $statement, $end = 0, $delim = ';' ) use ( $callback, &$count ) {
            $count++;
            return $callback( $statement, $end, $delim ) !== false;
        };
        while ( ! $scan['stopped'] && ! feof( $handle ) ) {
            $chunk = fread( $handle, 1048576 );
            if ( $chunk === false ) {
                fclose( $handle );
                return new \WP_Error( 'sql_unreadable', 'Could not read ' . basename( $path ) . '.', [ 'status' => 500 ] );
            }
            self::sql_scan( $scan, $chunk, false, $emit );
        }
        if ( ! $scan['stopped'] ) {
            self::sql_scan( $scan, '', true, $emit );
        }
        fclose( $handle );
        return [
            'statements'    => $count,
            'ended_cleanly' => $scan['ended_cleanly'],
            'stopped'       => $scan['stopped'],
        ];
    }

    private static function sql_scanner( $delim = ';' ) {
        return [ 'buf' => '', 'cur' => '', 'state' => 'normal', 'stopped' => false, 'ended_cleanly' => false, 'fed' => 0, 'pos' => 0, 'delim' => (string) $delim !== '' ? (string) $delim : ';' ];
    }

    /**
     * Incremental quote/comment-aware statement scanner. Feeds $data through
     * the state machine, emitting each complete statement; bytes that need
     * lookahead past the end of $data wait for the next call. Pass
     * $final = true with the last piece to flush the trailing statement.
     * Plain runs of characters are copied with strcspn() rather than one byte
     * at a time.
     */
    private static function sql_scan( array &$scan, $data, $final, $emit ) {
        $s     = $scan['buf'] . $data;
        $len   = strlen( $s );
        $i     = 0;
        $state = $scan['state'];
        $cur   = $scan['cur'];
        // The file offset of $s[0], so each statement's end can be reported.
        $base         = ( $scan['fed'] ?? 0 ) - strlen( $scan['buf'] );
        $scan['fed']  = ( $scan['fed'] ?? 0 ) + strlen( $data );

        $flush = function () use ( &$cur, &$scan, $emit ) {
            $statement = trim( $cur );
            $cur       = '';
            if ( $statement !== '' && ! $emit( $statement, $scan['pos'], $scan['delim'] ) ) {
                $scan['stopped'] = true;
            }
        };

        while ( $i < $len && ! $scan['stopped'] ) {
            // Every transition looks at most two bytes ahead.
            if ( ! $final && $i + 2 >= $len ) {
                break;
            }
            $ch  = $s[ $i ];
            $nxt = ( $i + 1 < $len ) ? $s[ $i + 1 ] : '';

            switch ( $state ) {
                case 'normal':
                    // A client DELIMITER line at the start of a statement
                    // (mysqldump writes one around each trigger and routine,
                    // whose bodies hold ";"): from here on that ends one.
                    if ( ( $ch === 'D' || $ch === 'd' ) && strlen( $cur ) < 4096 && strncasecmp( substr( $s, $i, 9 ), 'DELIMITER', min( 9, $len - $i ) ) === 0 && trim( self::strip_leading_comments( $cur ) ) === '' ) {
                        if ( $len - $i < 10 && ! $final ) {
                            break 2; // too little left to tell
                        }
                        $nl = strpos( $s, "\n", $i );
                        if ( $nl === false && ! $final ) {
                            break 2; // the rest of the line is in the next piece
                        }
                        $line = $nl === false ? substr( $s, $i ) : substr( $s, $i, $nl - $i );
                        if ( preg_match( '/^DELIMITER[ \t]+(\S+)[ \t]*\r?$/i', $line, $dm ) ) {
                            $scan['delim'] = $dm[1];
                            $i             = $nl === false ? $len : $nl + 1;
                            $cur           = '';
                            $scan['pos']   = $base + $i;
                            break;
                        }
                    }
                    $delim = $scan['delim'];
                    $run   = strcspn( $s, "-#/'\"`\n" . $delim[0], $i );
                    if ( $run > 0 ) {
                        $cur .= substr( $s, $i, $run );
                        $i   += $run;
                        break;
                    }
                    if ( $ch === "\n" ) {
                        $cur .= $ch;
                        $i++;
                        break;
                    }
                    if ( $ch === '-' && $nxt === '-' && ( $i + 2 >= $len || ctype_space( $s[ $i + 2 ] ) ) ) {
                        $state = 'line_comment';
                        $cur  .= $ch;
                        $i++;
                        break;
                    }
                    if ( $ch === '#' ) {
                        $state = 'line_comment';
                        $cur  .= $ch;
                        $i++;
                        break;
                    }
                    if ( $ch === '/' && $nxt === '*' ) {
                        $state = 'block_comment';
                        $cur  .= '/*';
                        $i    += 2;
                        break;
                    }
                    if ( $ch === "'" ) { $state = 'squote';   $cur .= $ch; $i++; break; }
                    if ( $ch === '"' ) { $state = 'dquote';   $cur .= $ch; $i++; break; }
                    if ( $ch === '`' ) { $state = 'backtick'; $cur .= $ch; $i++; break; }
                    if ( $ch === $delim[0] ) {
                        $dl = strlen( $delim );
                        if ( $dl > 2 && ! $final && $i + $dl > $len ) {
                            break 2; // the rest of the delimiter is in the next piece
                        }
                        if ( $dl === 1 || substr_compare( $s, $delim, $i, $dl ) === 0 ) {
                            $i          += $dl;
                            $scan['pos'] = $base + $i;
                            $flush();
                            break;
                        }
                    }
                    $cur .= $ch; // a lone "-" or "/", or a ";" inside a body
                    $i++;
                    break;

                case 'squote':
                case 'dquote':
                    $q   = ( $state === 'squote' ) ? "'" : '"';
                    $run = strcspn( $s, '\\' . $q, $i );
                    if ( $run > 0 ) {
                        $cur .= substr( $s, $i, $run );
                        $i   += $run;
                        break;
                    }
                    if ( $ch === '\\' ) {
                        // Backslash escapes the next character.
                        $cur .= $ch . $nxt;
                        $i   += 2;
                        break;
                    }
                    if ( $nxt === $q ) {
                        // Doubled quote is a literal quote, not a terminator.
                        $cur .= $ch . $nxt;
                        $i   += 2;
                        break;
                    }
                    $state = 'normal';
                    $cur  .= $ch;
                    $i++;
                    break;

                case 'backtick':
                    $run = strcspn( $s, '`', $i );
                    if ( $run > 0 ) {
                        $cur .= substr( $s, $i, $run );
                        $i   += $run;
                        break;
                    }
                    if ( $nxt === '`' ) {
                        $cur .= '``';
                        $i   += 2;
                        break;
                    }
                    $state = 'normal';
                    $cur  .= $ch;
                    $i++;
                    break;

                case 'line_comment':
                    $run = strcspn( $s, "\n", $i );
                    if ( $run > 0 ) {
                        $cur .= substr( $s, $i, $run );
                        $i   += $run;
                        break;
                    }
                    $cur  .= "\n";
                    $state = 'normal';
                    $i++;
                    break;

                case 'block_comment':
                    $run = strcspn( $s, '*', $i );
                    if ( $run > 0 ) {
                        $cur .= substr( $s, $i, $run );
                        $i   += $run;
                        break;
                    }
                    if ( $nxt === '/' ) {
                        $cur  .= '*/';
                        $i    += 2;
                        $state = 'normal';
                        break;
                    }
                    $cur .= '*';
                    $i++;
                    break;
            }
        }

        $scan['buf']   = $scan['stopped'] ? '' : substr( $s, $i );
        $scan['state'] = $state;
        $scan['cur']   = $cur;

        if ( $final && ! $scan['stopped'] ) {
            // A dump that ends inside a quote or block comment was cut off.
            $scan['ended_cleanly'] = ( $state === 'normal' || $state === 'line_comment' );
            $scan['pos']           = $base + $len;
            $flush();
        }
    }

    /**
     * Checks a rollback snapshot is complete before rollback() changes
     * anything: every file present, the recorded sizes match, each file
     * parses to the end, every table in the snapshot has its CREATE TABLE,
     * and (format 2) the export's completeness marker is the last statement.
     */
    private static function validate_snapshot( $sql_files, $meta ) {
        $fail = function ( $why ) {
            return new \WP_Error( 'snapshot_invalid', "The rollback snapshot can't be used ({$why}). Nothing was changed.", [ 'status' => 500 ] );
        };
        if ( empty( $sql_files ) ) {
            return $fail( 'no snapshot SQL found' );
        }
        $format = (int) ( $meta['format'] ?? 1 );
        if ( $format >= 2 ) {
            $expected = (array) ( $meta['sql_files'] ?? [] );
            $names    = array_map( 'basename', $sql_files );
            sort( $names );
            $recorded = array_keys( $expected );
            sort( $recorded );
            if ( $names !== $recorded ) {
                return $fail( 'snapshot files are missing' );
            }
            foreach ( $sql_files as $file ) {
                clearstatcache( true, $file );
                if ( filesize( $file ) !== (int) $expected[ basename( $file ) ] ) {
                    return $fail( basename( $file ) . ' changed size since it was taken' );
                }
            }
        }

        $creates = [];
        $last    = '';
        foreach ( $sql_files as $file ) {
            $summary = self::each_sql_statement_in_file( $file, function ( $statement ) use ( &$creates, &$last ) {
                if ( preg_match( '/^CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`((?:[^`]|``)+)`/i', $statement, $m )
                    || preg_match( '/^CREATE\s+(?:OR\s+REPLACE\s+)?(?:ALGORITHM\s*=\s*\w+\s+)?(?:DEFINER\s*=\s*\S+\s+)?(?:SQL\s+SECURITY\s+\w+\s+)?VIEW\s+`((?:[^`]|``)+)`/i', $statement, $m ) ) {
                    $creates[ str_replace( '``', '`', $m[1] ) ] = true;
                }
                $last = $statement;
                return true;
            } );
            if ( is_wp_error( $summary ) ) {
                return $fail( $summary->get_error_message() );
            }
            if ( ! $summary['ended_cleanly'] ) {
                return $fail( basename( $file ) . ' is cut off' );
            }
        }
        // A new subsite's or tenant's restore point holds no tables.
        if ( empty( $creates ) && ( ! empty( $meta['tables'] ) || empty( $meta['target'] ) ) ) {
            return $fail( 'the snapshot holds no tables' );
        }
        if ( $format >= 2 ) {
            foreach ( (array) ( $meta['saved_tables'] ?? $meta['tables'] ?? [] ) as $table ) {
                if ( ! isset( $creates[ $table ] ) ) {
                    return $fail( "table {$table} is missing from it" );
                }
            }
            if ( $last . ';' !== Backup::EXPORT_COMPLETE_MARKER ) {
                return $fail( 'the export did not finish' );
            }
        }
        return true;
    }

    /**
     * Recursively deletes a directory. A symlink inside it is removed as a
     * link, never followed: its target (anywhere on the disk) stays.
     */
    private static function delete_directory_recursive( $dir ) {
        if ( is_link( $dir ) ) {
            @unlink( $dir );
            return;
        }
        if ( ! is_dir( $dir ) ) {
            return;
        }
        foreach ( (array) @scandir( $dir ) as $item ) {
            if ( $item === '.' || $item === '..' ) {
                continue;
            }
            $path = $dir . '/' . $item;
            if ( is_dir( $path ) && ! is_link( $path ) ) {
                self::delete_directory_recursive( $path );
            } else {
                @unlink( $path );
            }
        }
        @rmdir( $dir );
    }
}

/**
 * PclZip pre-extract guard (see Import::extract_with_pclzip()): skips any
 * entry whose stored name could escape the extraction root (zip-slip).
 * PclZip callbacks must be plain function names (function_exists is used
 * to validate them), so this lives outside the class. Returns 1 to
 * extract, 0 to skip.
 */
function pclzip_pre_extract_guard( $event, &$header ) {
    return Import::is_safe_relative_path( isset( $header['stored_filename'] ) ? $header['stored_filename'] : '' ) ? 1 : 0;
}
