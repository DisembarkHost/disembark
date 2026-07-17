<?php

namespace Disembark;

class Import {

    private $import_id;
    private $import_path;
    private $staging_path;
    private $rollback_path;

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

    public function get_import_id() {
        return $this->import_id;
    }

    /**
     * Rejects a caller-supplied relative path that could climb out of the
     * staging / web-root tree (absolute paths, "..", or null bytes).
     */
    private function is_safe_relative_path( $relative_path ) {
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

        return [
            'db_prefix'          => $wpdb->prefix,
            'home_url'           => home_url(),
            'site_url'           => site_url(),
            'abspath'            => ABSPATH,
            'upload_max_filesize' => ini_get( 'upload_max_filesize' ),
            'post_max_size'      => ini_get( 'post_max_size' ),
            'max_execution_time' => ini_get( 'max_execution_time' ),
            'tables'             => $tables,
            'php_version'        => phpversion(),
            'mysql_version'      => $mysql_version,
            'disembark_token'    => get_option( 'disembark_token' ),
        ];
    }

    /**
     * Creates a rollback snapshot of the destination database and file manifest.
     */
    public function snapshot() {
        global $wpdb;

        if ( ! is_dir( $this->rollback_path ) ) {
            mkdir( $this->rollback_path, 0755, true );
        }

        // Export all tables
        $all_tables = $wpdb->get_col( 'SHOW TABLES' );
        if ( empty( $all_tables ) ) {
            return new \WP_Error( 'no_tables', 'No tables found to snapshot.', [ 'status' => 500 ] );
        }

        $backup   = new Backup( 'rollback-' . $this->import_id );
        $file_url = $backup->database_export_batch( $all_tables );

        if ( ! $file_url ) {
            return new \WP_Error( 'snapshot_failed', 'Failed to create database snapshot.', [ 'status' => 500 ] );
        }

        // Move the exported SQL to our rollback directory
        $backup_base = wp_upload_dir()['basedir'] . '/disembark/rollback-' . $this->import_id;
        $sql_files   = glob( "{$backup_base}/*.sql.txt" );
        foreach ( $sql_files as $sql_file ) {
            $dest = $this->rollback_path . '/' . basename( $sql_file );
            rename( $sql_file, $dest );
        }

        // Clean up the backup directory
        if ( is_dir( $backup_base ) ) {
            @rmdir( $backup_base );
        }

        // Save rollback metadata
        $meta = [
            'import_id'   => $this->import_id,
            'created_at'  => time(),
            'tables'      => $all_tables,
            'deployed_files' => [],
            'backed_up_files' => [],
        ];
        file_put_contents( $this->rollback_path . '/meta.json', json_encode( $meta, JSON_PRETTY_PRINT ) );

        return [
            'rollback_id' => $this->import_id,
            'tables'      => count( $all_tables ),
        ];
    }

    /**
     * Receives a ZIP of files (single POST) and extracts to staging.
     */
    public function upload_zip( $uploaded_file ) {
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
    public function extract_staged_zip( $file_path ) {
        if ( ! $this->is_safe_relative_path( $file_path ) ) {
            return new \WP_Error( 'invalid_path', 'Invalid file path.', [ 'status' => 400 ] );
        }
        $zip_path = $this->staging_path . '/' . $file_path;
        if ( ! file_exists( $zip_path ) ) {
            return new \WP_Error( 'not_found', 'Staged ZIP not found.', [ 'status' => 404 ] );
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
     * Extracts a ZIP into the staging directory, skipping any zip-slip entry
     * names. Uses ZipArchive, falling back to PclZip. Returns true or WP_Error.
     */
    private function extract_zip_file( $zip_path ) {
        if ( ! class_exists( 'ZipArchive' ) ) {
            if ( ! class_exists( 'PclZip' ) ) {
                require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
            }
            $zip    = new \PclZip( $zip_path );
            $result = $zip->extract( PCLZIP_OPT_PATH, $this->staging_path );
            if ( $result == 0 ) {
                return new \WP_Error( 'extract_failed', 'Failed to extract ZIP: ' . $zip->errorInfo( true ), [ 'status' => 500 ] );
            }
            return true;
        }

        $zip = new \ZipArchive();
        if ( $zip->open( $zip_path ) !== true ) {
            return new \WP_Error( 'extract_failed', 'Failed to open ZIP archive.', [ 'status' => 500 ] );
        }
        // Extract entry-by-entry, skipping any name that could escape staging
        // (zip-slip); extractTo() on the whole archive would honour "../".
        $safe_entries = [];
        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $name = $zip->getNameIndex( $i );
            if ( $this->is_safe_relative_path( $name ) ) {
                $safe_entries[] = $name;
            }
        }
        if ( ! empty( $safe_entries ) ) {
            $zip->extractTo( $this->staging_path, $safe_entries );
        }
        $zip->close();
        return true;
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
        if ( ! $this->is_safe_relative_path( $file_path ) ) {
            return new \WP_Error( 'invalid_path', 'Invalid file path.', [ 'status' => 400 ] );
        }
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
        $final_dir = dirname( $this->staging_path . '/' . $file_path );
        if ( ! is_dir( $final_dir ) ) {
            mkdir( $final_dir, 0755, true );
        }
        $final_path = $this->staging_path . '/' . $file_path;
        $fp         = fopen( $final_path, 'wb' );
        if ( ! $fp ) {
            return new \WP_Error( 'write_failed', 'Could not create final file.', [ 'status' => 500 ] );
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
     */
    public function deploy_files( $files ) {
        $web_root  = dirname( WP_CONTENT_DIR );
        $deployed  = [];
        $errors    = [];
        $backed_up = [];

        foreach ( $files as $relative_path ) {
            if ( ! $this->is_safe_relative_path( $relative_path ) ) {
                $errors[] = "Unsafe path skipped: {$relative_path}";
                continue;
            }

            $source = $this->staging_path . '/' . $relative_path;
            $dest   = $web_root . '/' . $relative_path;

            if ( ! file_exists( $source ) ) {
                $errors[] = "Source not found: {$relative_path}";
                continue;
            }

            // Skip wp-config.php
            if ( basename( $relative_path ) === 'wp-config.php' ) {
                continue;
            }

            // Backup original if it exists
            if ( file_exists( $dest ) ) {
                $backup_dest = $this->rollback_path . '/files/' . $relative_path;
                $backup_dir  = dirname( $backup_dest );
                if ( ! is_dir( $backup_dir ) ) {
                    mkdir( $backup_dir, 0755, true );
                }
                copy( $dest, $backup_dest );
                $backed_up[] = $relative_path;
            }

            // Create destination directory
            $dest_dir = dirname( $dest );
            if ( ! is_dir( $dest_dir ) ) {
                mkdir( $dest_dir, 0755, true );
            }

            // Move file
            if ( rename( $source, $dest ) ) {
                $deployed[] = $relative_path;
            } else {
                // Try copy + delete as fallback
                if ( copy( $source, $dest ) ) {
                    @unlink( $source );
                    $deployed[] = $relative_path;
                } else {
                    $errors[] = "Failed to deploy: {$relative_path}";
                }
            }
        }

        // Update rollback metadata with deployed files
        $meta_file = $this->rollback_path . '/meta.json';
        if ( file_exists( $meta_file ) ) {
            $meta = json_decode( file_get_contents( $meta_file ), true );
            $meta['deployed_files']  = array_merge( $meta['deployed_files'] ?? [], $deployed );
            $meta['backed_up_files'] = array_merge( $meta['backed_up_files'] ?? [], $backed_up );
            file_put_contents( $meta_file, json_encode( $meta, JSON_PRETTY_PRINT ) );
        }

        return [
            'deployed' => count( $deployed ),
            'errors'   => $errors,
        ];
    }

    /**
     * Executes pre-transformed SQL statements on the destination database.
     */
    public function execute_sql( $sql_content ) {
        global $wpdb;

        $statements_executed = 0;
        $errors              = [];

        // The import overwrites wp_options (and wp_users) with the source's
        // data, which would replace this site's Disembark token and lock the
        // CLI out of the remaining steps. Remember it and restore it afterward
        // so the tool stays authenticated through search-replace/finalize.
        $preserved_token = get_option( 'disembark_token' );

        // Split into individual statements with a quote/comment-aware scanner.
        // A column value may legitimately contain ";" (or ";\n"), so splitting
        // on the delimiter naively would cut a statement mid-value.
        $statements = self::split_sql_statements( $sql_content );

        // Temporarily disable FK checks for the import
        $wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );
        $wpdb->query( 'SET UNIQUE_CHECKS = 0' );
        $wpdb->query( "SET sql_mode='NO_AUTO_VALUE_ON_ZERO'" );

        foreach ( $statements as $statement ) {
            $statement = trim( $statement );
            if ( empty( $statement ) ) {
                continue;
            }

            // Skip SET statements we handle ourselves
            if ( preg_match( '/^SET\s+(FOREIGN_KEY_CHECKS|UNIQUE_CHECKS|sql_mode|NAMES)/i', $statement ) ) {
                continue;
            }

            // Skip the DISABLE/ENABLE KEYS comments
            if ( preg_match( '/^\/\*!\d+\s+ALTER TABLE/', $statement ) ) {
                continue;
            }

            // Add semicolon back for execution
            $result = $wpdb->query( $statement );
            if ( $result === false ) {
                $error_msg = $wpdb->last_error;
                // Don't fail on DROP TABLE errors
                if ( stripos( $statement, 'DROP TABLE' ) === 0 ) {
                    continue;
                }
                $errors[] = substr( $error_msg, 0, 200 ) . ' | SQL: ' . substr( $statement, 0, 100 );
                if ( count( $errors ) > 50 ) {
                    $errors[] = '...truncated (too many errors)';
                    break;
                }
            } else {
                $statements_executed++;
            }
        }

        $wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' );
        $wpdb->query( 'SET UNIQUE_CHECKS = 1' );

        // Restore the preserved Disembark token. The raw SQL import bypassed the
        // options cache, so clear it first — otherwise update_option would see a
        // stale "unchanged" value and skip the write, leaving the source's token
        // in the row and breaking auth for the next request.
        if ( $preserved_token !== false ) {
            wp_cache_delete( 'disembark_token', 'options' );
            wp_cache_delete( 'alloptions', 'options' );
            update_option( 'disembark_token', $preserved_token );
        }

        return [
            'statements_executed' => $statements_executed,
            'errors'              => $errors,
        ];
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
     */
    public function search_replace( $from, $to, $tables = [] ) {
        global $wpdb;

        if ( $from === '' || $from === $to ) {
            return [ 'tables' => 0, 'rows_changed' => 0, 'cells_changed' => 0 ];
        }

        if ( empty( $tables ) ) {
            $tables = $wpdb->get_col( 'SHOW TABLES' );
        }

        $rows_changed  = 0;
        $cells_changed = 0;
        $tables_seen   = 0;

        foreach ( $tables as $table ) {
            // Only touch real tables on this database.
            $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
            if ( ! $exists ) {
                continue;
            }
            $tables_seen++;

            $columns     = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`" );
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

            $offset = 0;
            $limit  = 1000;
            while ( true ) {
                $rows = $wpdb->get_results(
                    "SELECT * FROM `{$table}` LIMIT {$limit} OFFSET {$offset}",
                    ARRAY_A
                );
                if ( empty( $rows ) ) {
                    break;
                }

                foreach ( $rows as $row ) {
                    $update = [];
                    foreach ( $row as $column => $value ) {
                        if ( ! is_string( $value ) || $value === '' ) {
                            continue;
                        }
                        if ( strpos( $value, $from ) === false ) {
                            continue;
                        }
                        $replaced = self::recursive_unserialize_replace( $from, $to, $value );
                        if ( $replaced !== $value ) {
                            $update[ $column ] = $replaced;
                            $cells_changed++;
                        }
                    }

                    if ( ! empty( $update ) ) {
                        $where = [];
                        foreach ( $primary_keys as $pk ) {
                            $where[ $pk ] = $row[ $pk ];
                        }
                        $result = $wpdb->update( $table, $update, $where );
                        if ( $result !== false && $result > 0 ) {
                            $rows_changed++;
                        }
                    }
                }

                $offset += $limit;
            }
        }

        return [
            'tables'        => $tables_seen,
            'rows_changed'  => $rows_changed,
            'cells_changed' => $cells_changed,
        ];
    }

    /**
     * Recursively replaces $from with $to inside a value, re-serializing PHP
     * serialized strings so their length prefixes stay valid. Mirrors the
     * approach used by WP-CLI search-replace / interconnectit's srdb.
     */
    private static function recursive_unserialize_replace( $from, $to, $data, $serialised = false ) {
        try {
            if ( is_string( $data ) && $data !== '' && ( $unserialized = @unserialize( $data ) ) !== false ) {
                $data = self::recursive_unserialize_replace( $from, $to, $unserialized, true );
            } elseif ( is_array( $data ) ) {
                $result = [];
                foreach ( $data as $key => $value ) {
                    $result[ $key ] = self::recursive_unserialize_replace( $from, $to, $value, false );
                }
                $data = $result;
                unset( $result );
            } elseif ( is_object( $data ) ) {
                $result = clone $data;
                foreach ( get_object_vars( $data ) as $key => $value ) {
                    // Skip protected/private mangled property names.
                    if ( $key === '' || ord( $key[0] ) === 0 ) {
                        continue;
                    }
                    $result->$key = self::recursive_unserialize_replace( $from, $to, $value, false );
                }
                $data = $result;
                unset( $result );
            } elseif ( is_string( $data ) ) {
                $data = str_replace( $from, $to, $data );
            }

            if ( $serialised ) {
                return serialize( $data );
            }
        } catch ( \Exception $e ) {
            // On any failure, leave the value untouched rather than corrupt it.
        }

        return $data;
    }

    /**
     * Restores the destination to pre-import state using the rollback snapshot.
     */
    public function rollback() {
        global $wpdb;

        $meta_file = $this->rollback_path . '/meta.json';
        if ( ! file_exists( $meta_file ) ) {
            return new \WP_Error( 'no_rollback', 'No rollback data found for this import ID.', [ 'status' => 404 ] );
        }

        $meta   = json_decode( file_get_contents( $meta_file ), true );
        $errors = [];

        // 1. Restore database from snapshot SQL
        $sql_files = glob( $this->rollback_path . '/*.sql.txt' );
        if ( ! empty( $sql_files ) ) {
            $wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );
            $wpdb->query( 'SET UNIQUE_CHECKS = 0' );

            // Drop all current tables first
            $current_tables = $wpdb->get_col( 'SHOW TABLES' );
            foreach ( $current_tables as $table ) {
                $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
            }

            // Re-import snapshot
            foreach ( $sql_files as $sql_file ) {
                $content    = file_get_contents( $sql_file );
                $statements = self::split_sql_statements( $content );
                foreach ( $statements as $statement ) {
                    $statement = trim( $statement );
                    if ( empty( $statement ) ) {
                        continue;
                    }
                    $result = $wpdb->query( $statement );
                    if ( $result === false && stripos( $statement, 'SET ' ) !== 0 ) {
                        $errors[] = 'SQL Error: ' . substr( $wpdb->last_error, 0, 200 );
                    }
                }
            }

            $wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' );
            $wpdb->query( 'SET UNIQUE_CHECKS = 1' );
        }

        // 2. Restore backed-up files
        $web_root    = dirname( WP_CONTENT_DIR );
        $backed_up   = $meta['backed_up_files'] ?? [];
        foreach ( $backed_up as $relative_path ) {
            $backup_src = $this->rollback_path . '/files/' . $relative_path;
            $dest       = $web_root . '/' . $relative_path;
            if ( file_exists( $backup_src ) ) {
                $dest_dir = dirname( $dest );
                if ( ! is_dir( $dest_dir ) ) {
                    mkdir( $dest_dir, 0755, true );
                }
                if ( ! rename( $backup_src, $dest ) ) {
                    copy( $backup_src, $dest );
                }
            }
        }

        // 3. Delete newly deployed files that weren't overwriting originals
        $deployed = $meta['deployed_files'] ?? [];
        foreach ( $deployed as $relative_path ) {
            if ( in_array( $relative_path, $backed_up, true ) ) {
                continue; // Already restored from backup
            }
            $file = $web_root . '/' . $relative_path;
            if ( file_exists( $file ) ) {
                @unlink( $file );
            }
        }

        return [
            'success'          => true,
            'tables_restored'  => count( $sql_files ),
            'files_restored'   => count( $backed_up ),
            'files_removed'    => count( array_diff( $deployed, $backed_up ) ),
            'errors'           => $errors,
        ];
    }

    /**
     * Cleans up staging directory. Keeps rollback data.
     */
    public function finalize() {
        // Remove staging directory
        if ( is_dir( $this->staging_path ) ) {
            self::delete_directory_recursive( $this->staging_path );
        }

        // Remove leftover chunk directories
        $chunks_dir = $this->staging_path;
        if ( is_dir( $chunks_dir ) ) {
            self::delete_directory_recursive( $chunks_dir );
        }

        // Flush rewrite rules
        flush_rewrite_rules();

        // Flush object cache
        wp_cache_flush();

        return [
            'success'     => true,
            'rollback_id' => $this->import_id,
            'message'     => 'Import finalized. Rollback data retained.',
        ];
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
        $current    = '';
        $len        = strlen( $sql );
        $state      = 'normal'; // normal | squote | dquote | backtick | line_comment | block_comment
        $i          = 0;

        while ( $i < $len ) {
            $ch  = $sql[ $i ];
            $nxt = ( $i + 1 < $len ) ? $sql[ $i + 1 ] : '';

            switch ( $state ) {
                case 'normal':
                    if ( $ch === '-' && $nxt === '-' && ( $i + 2 >= $len || ctype_space( $sql[ $i + 2 ] ) ) ) {
                        $state    = 'line_comment';
                        $current .= $ch;
                        $i++;
                        break;
                    }
                    if ( $ch === '#' ) {
                        $state    = 'line_comment';
                        $current .= $ch;
                        $i++;
                        break;
                    }
                    if ( $ch === '/' && $nxt === '*' ) {
                        $state    = 'block_comment';
                        $current .= $ch . $nxt;
                        $i       += 2;
                        break;
                    }
                    if ( $ch === "'" ) { $state = 'squote';   $current .= $ch; $i++; break; }
                    if ( $ch === '"' ) { $state = 'dquote';   $current .= $ch; $i++; break; }
                    if ( $ch === '`' ) { $state = 'backtick'; $current .= $ch; $i++; break; }
                    if ( $ch === ';' ) {
                        $stmt = trim( $current );
                        if ( $stmt !== '' ) {
                            $statements[] = $stmt;
                        }
                        $current = '';
                        $i++;
                        break;
                    }
                    $current .= $ch;
                    $i++;
                    break;

                case 'squote':
                case 'dquote':
                    $q = ( $state === 'squote' ) ? "'" : '"';
                    if ( $ch === '\\' ) {
                        // Backslash escapes the next character.
                        $current .= $ch . $nxt;
                        $i       += 2;
                        break;
                    }
                    if ( $ch === $q ) {
                        if ( $nxt === $q ) {
                            // Doubled quote is a literal quote, not a terminator.
                            $current .= $ch . $nxt;
                            $i       += 2;
                            break;
                        }
                        $state    = 'normal';
                        $current .= $ch;
                        $i++;
                        break;
                    }
                    $current .= $ch;
                    $i++;
                    break;

                case 'backtick':
                    if ( $ch === '`' ) {
                        if ( $nxt === '`' ) {
                            $current .= $ch . $nxt;
                            $i       += 2;
                            break;
                        }
                        $state    = 'normal';
                        $current .= $ch;
                        $i++;
                        break;
                    }
                    $current .= $ch;
                    $i++;
                    break;

                case 'line_comment':
                    $current .= $ch;
                    if ( $ch === "\n" ) {
                        $state = 'normal';
                    }
                    $i++;
                    break;

                case 'block_comment':
                    if ( $ch === '*' && $nxt === '/' ) {
                        $current .= $ch . $nxt;
                        $i       += 2;
                        $state    = 'normal';
                        break;
                    }
                    $current .= $ch;
                    $i++;
                    break;
            }
        }

        $stmt = trim( $current );
        if ( $stmt !== '' ) {
            $statements[] = $stmt;
        }

        return $statements;
    }

    /**
     * Recursively delete a directory.
     */
    private static function delete_directory_recursive( $dir ) {
        if ( ! is_dir( $dir ) ) {
            return;
        }
        $it    = new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS );
        $files = new \RecursiveIteratorIterator( $it, \RecursiveIteratorIterator::CHILD_FIRST );
        foreach ( $files as $file ) {
            if ( $file->isDir() ) {
                @rmdir( $file->getRealPath() );
            } else {
                @unlink( $file->getRealPath() );
            }
        }
        @rmdir( $dir );
    }
}
