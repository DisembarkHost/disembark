<?php

namespace Disembark;

class Backup {

    private $backup_path      = "";
    private $backup_url       = "";
    private $token            = "";
    private $rows_per_segment = 100;
    private $archiver_type    = 'none'; // Can be 'ZipArchive', 'PclZip', or 'none'
    private $zip_object       = null;
    public function __construct( $token = "" ) {
        $bytes             = random_bytes( 20 );
        // The token names a folder under uploads/disembark; strip anything
        // that could climb out of it (e.g. "../.." via /cleanup-file).
        $token             = Storage::sanitize_session( $token );
        $this->token       = empty( $token ) ? substr( bin2hex( $bytes ), 0, -28) : $token;
        $this->backup_path = wp_upload_dir()["basedir"] . "/disembark/{$this->token}";
        $backup_url        = wp_upload_dir()["baseurl"] . "/disembark/{$this->token}";
        // Ensure URL scheme matches the current request to prevent mixed content issues
        // Check multiple indicators for HTTPS (handles reverse proxy scenarios)
        $is_https = is_ssl()
            || ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https' )
            || ( isset( $_SERVER['HTTP_X_FORWARDED_SSL'] ) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on' )
            || ( isset( $_SERVER['REQUEST_SCHEME'] ) && $_SERVER['REQUEST_SCHEME'] === 'https' );
        if ( $is_https ) {
            $backup_url = preg_replace( '/^http:\/\//', 'https://', $backup_url );
        }
        $this->backup_url = $backup_url;
        Storage::ensure_dir( $this->backup_path );
        
        if ( class_exists( 'ZipArchive' ) ) {
            $this->archiver_type = 'ZipArchive';
            $this->zip_object    = new \ZipArchive();
        } else {
            if ( ! class_exists( 'PclZip' ) ) {
                $pclzip_path = ABSPATH . 'wp-admin/includes/class-pclzip.php';
                if ( file_exists( $pclzip_path ) ) {
                    require_once $pclzip_path;
                }
            }
            if ( class_exists( 'PclZip' ) ) {
                $this->archiver_type = 'PclZip';
            } else {
                $this->archiver_type = 'none';
            }
        }
    }

    public function initiate_scan_state() {
        $state_file = "{$this->backup_path}/_scan_state.json";
        
        $web_root = dirname( WP_CONTENT_DIR );
        $core_root = rtrim( ABSPATH, '/' );
        $dirs_to_scan = [ $web_root ];
        
        // Add core root only if it's not the same as or inside the web root
        if ( $web_root !== $core_root && !str_starts_with( $core_root, $web_root . '/' ) ) {
            $dirs_to_scan[] = $core_root;
        }

        $initial_state = [
            'status' => 'scanning',
            'directories_to_scan' => $dirs_to_scan,
            'total_dirs' => count( $dirs_to_scan ),
            'scanned_dirs' => 0,
            'seen_files' => []
        ];
        file_put_contents( $state_file, json_encode( $initial_state ) );
    }

    public function process_scan_step( $exclude_paths, $include_checksums = false ) {
        $state_file = "{$this->backup_path}/_scan_state.json";
        $filtered_list_path = "{$this->backup_path}/_filtered_file_list.json";
        $state = json_decode( file_get_contents( $state_file ), true );

        $operation_limit = $include_checksums ? 200 : 5000;
        // Max files/folders to process per request
        $operations_this_batch = 0;
        
        // --- Open file handle *once* outside the loop ---
        $file_handle = fopen( $filtered_list_path, 'a' );
        if (!$file_handle) {
            return new \WP_Error('file_open_error', 'Could not open the filtered file list for writing.');
        }

        while ( $operations_this_batch < $operation_limit ) {
            // Check if we are already processing items in a directory
            if ( empty( $state['current_directory_items'] ) ) {
                // No directory is being processed, let's get a new one.
                if ( empty( $state['directories_to_scan'] ) ) {
                    // No new directories left, scan is complete.
                    $state['status'] = 'scan_complete';
                    unset($state['current_directory_path']); // Clean up
                    unset($state['current_directory_items']);
                    // Clean up
                    file_put_contents( $state_file, json_encode( $state ) );
                    
                    // --- FIX: Change 'break 2;' to just 'break;' ---
                    break; // Exit the master 'while' loop
                }

                // Get the next directory and save its contents to the state
                $directory_to_scan = array_shift( $state['directories_to_scan'] );
                $state['current_directory_path'] = $directory_to_scan;
                
                $items = @scandir( $directory_to_scan );
                if ($items === false) {
                    // Directory not readable, skip it
                    unset($state['current_directory_path']);
                    $state['scanned_dirs']++; // Still counts as "scanned"
                    file_put_contents( $state_file, json_encode( $state ) );
                    continue; // Skip to the next iteration of the master 'while' loop
                }
                
                // Remove '.' and '..'
                $state['current_directory_items'] = array_diff($items, ['.', '..']);
                $state['scanned_dirs']++;
            }

            // We have items to process (either new or from a previous run)
            // --- This logic was moved outside the loop ---
            $web_root = dirname( WP_CONTENT_DIR );
            $core_root = rtrim( ABSPATH, '/' );
            // Determine the home_path for the *current* directory
            $home_path = $web_root;
            if ( !empty($state['current_directory_path']) && $web_root !== $core_root && str_starts_with( $state['current_directory_path'], $core_root ) ) {
                $home_path = $core_root;
            }

            // !== null, not truthiness: an entry named "0" is a real file.
            while ( ( $item = array_shift( $state['current_directory_items'] ) ) !== null ) {
                
                $operations_this_batch++;
                if ( $operations_this_batch >= $operation_limit ) {
                    // Hit the limit.
                    // Put the item we just pulled *back* on the list.
                    array_unshift( $state['current_directory_items'], $item );

                    // Save state and exit.
                    // Next request will resume this 'while' loop.
                    // --- We don't close the file handle here ---
                    file_put_contents( $state_file, json_encode( $state ) );
                    
                    break 2; // Exit both the 'while ( $item = ... )' and the new master 'while' loop
                }

                $full_path = $state['current_directory_path'] . '/' . $item;
                $relative_path = ltrim( str_replace( $home_path, '', $full_path ), '/' );
                if ( isset( $state['seen_files'][$relative_path] ) ) {
                    continue; // Already processed
                }

                $is_excluded = false;
                foreach ($exclude_paths as $exclude_path) {
                    if ($relative_path === $exclude_path || str_starts_with($relative_path, $exclude_path . '/')) {
                        $is_excluded = true;
                        break;
                    }
                }
                if ($is_excluded) continue;
                if ( is_dir( $full_path ) ) {
                    if (is_readable($full_path)) {
                        // Add new directory to the *main* list, not the current batch
                        $state['directories_to_scan'][] = $full_path;
                        $state['total_dirs']++;
                    }
                } elseif ( is_file( $full_path ) ) {
                    $file_info = [
                        'name' => $relative_path,
                        'size' => filesize( $full_path ),
                        'type' => 'file'
                    ];
                    if ( $include_checksums ) {
                        $file_info['checksum'] = md5_file( $full_path );
                    }
                    fwrite( $file_handle, json_encode($file_info) . "\n" );
                    $state['seen_files'][$relative_path] = true;
                }
            } // end while (item processing loop)

            // If we are here, we finished processing $state['current_directory_items']
            unset($state['current_directory_path']);
            unset($state['current_directory_items']);

        } // End of the new master 'while' loop

        // --- Close the file handle *once* here ---
        fclose( $file_handle );
        file_put_contents( $state_file, json_encode( $state ) );
        
        // Check if we're all done
        if ( empty( $state['directories_to_scan'] ) ) {
            $state['status'] = 'scan_complete';
            file_put_contents( $state_file, json_encode( $state ) );
        }
        
        return $state;
    }

    public function chunkify_manifest( $chunk_size_mb = 100 ) {
        $filtered_list_path = "{$this->backup_path}/_filtered_file_list.json";
        if ( ! file_exists( $filtered_list_path ) ) {
            return ['total_chunks' => 0];
        }
        
        $storage_limit = $chunk_size_mb * 1024 * 1024;
        $chunk_offsets = [1];
        $current_chunk_size = 0;
        $line_number = 1;
    
        $handle = fopen($filtered_list_path, "r");
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                $file = json_decode($line);
                if (!$file || !isset($file->size)) {
                    $line_number++;
                    continue;
                }
                if ( ($current_chunk_size + $file->size) > $storage_limit && $current_chunk_size > 0) {
                    $chunk_offsets[] = $line_number;
                    $current_chunk_size = 0;
                }
                $current_chunk_size += $file->size;
                $line_number++;
            }
            fclose($handle);
        }
        
        $state_file = "{$this->backup_path}/_scan_state.json";
        $state = json_decode( file_get_contents( $state_file ), true );
        $state['chunk_offsets'] = $chunk_offsets;
        file_put_contents( $state_file, json_encode( $state ) );
        return [ 'total_chunks' => count( $chunk_offsets ) ];
    }

    public function process_manifest_chunk( $chunk_number, $chunk_size_mb = 100 ) {
        $filtered_list_path = "{$this->backup_path}/_filtered_file_list.json";
        $state_file = "{$this->backup_path}/_scan_state.json";
    
        if ( ! file_exists( $filtered_list_path ) || ! file_exists( $state_file ) ) {
            return new \WP_Error('missing_files', 'Required files for chunk processing are missing.');
        }
        
        $state = json_decode( file_get_contents( $state_file ), true );
        if ( !isset($state['chunk_offsets']) || !isset($state['chunk_offsets'][$chunk_number - 1]) ) {
            return new \WP_Error('no_offset', 'Chunk offset not found in state file.');
        }
        
        $storage_limit = $chunk_size_mb * 1024 * 1024;
        $start_line = $state['chunk_offsets'][$chunk_number - 1];
        $current_line = 1;
        $chunk_objects = [];
        $current_chunk_size = 0;
        
        $handle = fopen($filtered_list_path, "r");
        if (!$handle) {
            return new \WP_Error('file_open_error', 'Could not open the file list.');
        }
    
        while ($current_line < $start_line && !feof($handle)) {
            fgets($handle);
            $current_line++;
        }
    
        while (!feof($handle)) {
            $line = fgets($handle);
            if ($line === false || trim($line) === '') continue;
    
            $file = json_decode($line);
            if (!$file || !isset($file->size)) continue;
            if ( ($current_chunk_size + $file->size) > $storage_limit && !empty($chunk_objects) ) {
                break;
            }
    
            $chunk_objects[] = $file;
            $current_chunk_size += $file->size;
        }
        fclose($handle);
    
        $chunk_manifest_path = "{$this->backup_path}/files-{$chunk_number}.json";
        file_put_contents( $chunk_manifest_path, json_encode( $chunk_objects, JSON_PRETTY_PRINT ) );
        
        return [ 'success' => true, 'chunk' => $chunk_number, 'file_count' => count($chunk_objects) ];
    }
    
    public function finalize_manifest() {
        $manifest_chunks = glob( "{$this->backup_path}/files-*.json" );
        $response = [];
        natsort( $manifest_chunks );
        
        // Initialize grand totals
        $grand_total_size = 0;
        $grand_total_files = 0;
        
        foreach ( $manifest_chunks as $chunk_file ) {
            $content = json_decode( file_get_contents( $chunk_file ) );
            $file_count = count( $content );
            $total_size = array_sum( array_column( $content, 'size' ) );

            // Accumulate totals
            $grand_total_size += $total_size;
            $grand_total_files += $file_count;

            // Add back the URL for direct download
            $file_name = basename( $chunk_file );
            $url = "{$this->backup_url}/{$file_name}";

            // Keep relative path just in case
            $relative_path = str_replace( rtrim( ABSPATH, '/' ), '', $chunk_file );
            $relative_path = ltrim( $relative_path, '/' );

            $response[] = (object) [
                "name"  => $file_name,
                "url"   => $url,
                "path"  => $relative_path, 
                "size"  => $total_size,
                "count" => $file_count
            ];
        }

        // Cache the global stats for the CLI info command
        update_option( 'disembark_last_scan_stats', [
            'total_size'  => $grand_total_size,
            'total_files' => $grand_total_files,
            'timestamp'   => time()
        ]);

        file_put_contents( "{$this->backup_path}/manifest.json", json_encode( array_values( $response ), JSON_PRETTY_PRINT ) );
        return $this->list_manifest();
    }

    public function cleanup_temp_files() {
        $files_to_delete = [
            "{$this->backup_path}/_filtered_file_list.json",
            "{$this->backup_path}/_scan_state.json"
        ];
        foreach ($files_to_delete as $file) {
            if ( file_exists( $file ) ) {
                unlink( $file );
            }
        }
    }

    public function database_export( $table, $parts = 0, $rows_per_part = 0 ) {
        global $wpdb;
        $select_row_limit = 1000;
        $rows_start       = 0;
        $insert_sql       = "";
        
        // Hosts commonly block downloads of *.sql and *.sql.* (and some block
        // *.sql.txt), so exports carry no "sql" in their name at all.
        $file_ext         = self::EXPORT_EXT;
        $backup_file      = "{$this->backup_path}/{$table}{$file_ext}";
        $backup_url       = "{$this->backup_url}/{$table}{$file_ext}";
        if ( ! empty( $parts ) ) {
            $backup_file  = "{$this->backup_path}/{$table}-{$parts}{$file_ext}";
            $backup_url   = "{$this->backup_url}/{$table}-{$parts}{$file_ext}";
            $rows_start   = ( $parts - 1 ) * $rows_per_part;
        }

        if ( false === ( $file_handle = fopen( $backup_file, 'a' ) ) ) {
            return false;
        }

        if ( 0 == $rows_start ) {
            $create_table = $wpdb->get_results( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
            if ( false === $create_table || ! isset( $create_table[0] ) ) {
                return false;
            }
            $create_table_array = $create_table[0];
            unset( $create_table );
            $insert_sql .= str_replace( "\n", '', $create_table_array[1] ) . ";\n";
            unset( $create_table_array );
            $insert_sql .= "/*!40000 ALTER TABLE `{$table}` DISABLE KEYS */;\n";
            $insert_sql .= "SET FOREIGN_KEY_CHECKS = 0;\n";
            $insert_sql .= "SET UNIQUE_CHECKS = 0;\n";
        }

        $query_count = 0;
        $rows_remain = true;
        while ( true === $rows_remain ) {
            if ( $rows_per_part > 0 && ( $query_count + $select_row_limit ) >= $rows_per_part ) {
                $select_row_limit = $rows_per_part - $query_count;
                $rows_remain = false;
            }
            // ORDER BY the primary key: without it MySQL may return pages in
            // any order, so separate LIMIT/OFFSET requests can skip or repeat rows.
            $query       = "SELECT * FROM `$table`" . self::order_by_primary_key( $table ) . " LIMIT " . (int) $rows_start . ',' . (int) $select_row_limit;
            $table_query = $wpdb->get_results( $query, ARRAY_N );
            $rows_start += $select_row_limit;
            if ( $wpdb->last_error ) {
                @fclose( $file_handle );
                return false;
            }
            $table_count = count( $table_query );
            if ( 0 == $table_count || $table_count < $select_row_limit ) {
                $rows_remain = false;
            }
            $query_count += $table_count;
            $columns    = $wpdb->get_col_info();
            $num_fields = count( $columns );
            foreach ( $table_query as $fetch_row ) {
                $insert_sql .= "INSERT INTO `$table` VALUES(";
                for ( $n = 1; $n <= $num_fields; $n++ ) {
                    $m = $n - 1;
                    if ( null === $fetch_row[ $m ] ) {
                        $insert_sql .= 'NULL, ';
                    } else {
                        $insert_sql .= "'" . self::db_escape( $fetch_row[ $m ] ) . "', ";
                    }
                }
                $insert_sql  = substr( $insert_sql, 0, -2 );
                $insert_sql .= ");\n";
                $write_return = fwrite( $file_handle, $insert_sql );
                if ( false === $write_return || 0 == $write_return ) {
                    @fclose( $file_handle );
                    return false;
                }
                $insert_sql = '';
            }
        }

        $insert_sql  .= "/*!40000 ALTER TABLE `{$table}` ENABLE KEYS */;\n";
        $insert_sql  .= "SET FOREIGN_KEY_CHECKS = 1;\n";
        $insert_sql  .= "SET UNIQUE_CHECKS = 1;\n";
        $write_return = fwrite( $file_handle, $insert_sql );
        if ( false === $write_return || 0 == $write_return ) {
            @fclose( $file_handle );
            return false;
        }
        @fclose( $file_handle );
        // Return the public URL
        return $backup_url;
    }

    /**
     * Exports several tables into one dump and returns its URL, or WP_Error.
     * Used for CLI backups, a pull's database, and the rollback snapshot, so
     * it fails loudly: a table that can't be read or a write that doesn't land
     * (disk full) is an error, never a quietly truncated dump. Rows are read in
     * primary-key order, by key range where the table has a single-column key
     * (no OFFSET drift while the site keeps writing). The dump ends with
     * EXPORT_COMPLETE_MARKER so a reader can tell it is whole.
     */
    public function database_export_batch( $tables = [], $schema_only = [] ) {
        global $wpdb;
        // Tables exported as structure only: a move leaving their rows behind.
        $schema_only = array_flip( array_map( 'strval', (array) $schema_only ) );

        if ( empty( $tables ) ) {
            return new \WP_Error( 'missing_tables', 'No tables to export.', [ 'status' => 400 ] );
        }

        $batch_hash  = md5( implode( ',', $tables ) );
        $file_ext    = self::EXPORT_EXT;
        $backup_file = "{$this->backup_path}/batch-{$batch_hash}{$file_ext}";
        $backup_url  = "{$this->backup_url}/batch-{$batch_hash}{$file_ext}";

        if ( false === ( $file_handle = fopen( $backup_file, 'w' ) ) ) {
            return new \WP_Error( 'export_failed', 'Could not create the database export file.', [ 'status' => 500 ] );
        }
        $fail = function ( $message ) use ( $file_handle, $backup_file ) {
            @fclose( $file_handle );
            @unlink( $backup_file );
            return new \WP_Error( 'export_failed', $message, [ 'status' => 500 ] );
        };
        $write = function ( $data ) use ( $file_handle ) {
            return $data === '' || fwrite( $file_handle, $data ) === strlen( $data );
        };

        if ( ! $write( "SET FOREIGN_KEY_CHECKS = 0;\nSET UNIQUE_CHECKS = 0;\n" ) ) {
            return $fail( 'Could not write the database export (disk full?).' );
        }

        $select_row_limit = 1000;

        // Read every table from one consistent snapshot (InnoDB), so a site
        // that keeps writing during the export (a store taking orders) can't
        // leave an order in one table and its items missing from another.
        // Reads only; nothing is held locked.
        $wpdb->query( 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ' );
        $wpdb->query( 'START TRANSACTION WITH CONSISTENT SNAPSHOT' );
        $fail = function ( $message ) use ( $fail, $wpdb ) {
            $wpdb->query( 'COMMIT' );
            return $fail( $message );
        };

        foreach ( $tables as $table ) {
            $table        = (string) $table;
            $create_table = $wpdb->get_results( "SHOW CREATE TABLE `" . str_replace( '`', '``', $table ) . "`", ARRAY_N );
            if ( empty( $create_table[0][1] ) ) {
                return $fail( "Could not read the structure of table {$table}." );
            }
            if ( ! $write( str_replace( "\n", '', $create_table[0][1] ) . ";\n/*!40000 ALTER TABLE `{$table}` DISABLE KEYS */;\n" ) ) {
                return $fail( 'Could not write the database export (disk full?).' );
            }

            if ( isset( $schema_only[ $table ] ) ) {
                if ( ! $write( "/*!40000 ALTER TABLE `{$table}` ENABLE KEYS */;\n" ) ) {
                    return $fail( 'Could not write the database export (disk full?).' );
                }
                continue;
            }

            $keys     = self::primary_key_columns( $table );
            $keyset   = count( $keys ) === 1;
            $order_by = self::order_by_primary_key( $table );
            $offset   = 0;
            $last_key = null;
            $key_pos  = null;

            while ( true ) {
                if ( $keyset && $last_key !== null ) {
                    $query = $wpdb->prepare( "SELECT * FROM `{$table}` WHERE `{$keys[0]}` > %s{$order_by} LIMIT %d", $last_key, $select_row_limit );
                } else {
                    $query = "SELECT * FROM `{$table}`{$order_by} LIMIT " . (int) $offset . ',' . (int) $select_row_limit;
                }
                $rows = $wpdb->get_results( $query, ARRAY_N );
                if ( $wpdb->last_error ) {
                    return $fail( "Could not read rows from table {$table}: " . substr( $wpdb->last_error, 0, 200 ) );
                }
                if ( empty( $rows ) ) {
                    break;
                }
                if ( $keyset && $key_pos === null ) {
                    foreach ( (array) $wpdb->get_col_info( 'name' ) as $pos => $name ) {
                        if ( $name === $keys[0] ) {
                            $key_pos = $pos;
                        }
                    }
                    if ( $key_pos === null ) {
                        $keyset = false; // Couldn't locate the key column: fall back to OFFSET.
                    }
                }

                $insert_sql = '';
                foreach ( $rows as $fetch_row ) {
                    $values = [];
                    foreach ( $fetch_row as $value ) {
                        $values[] = ( null === $value ) ? 'NULL' : "'" . self::db_escape( $value ) . "'";
                    }
                    $insert_sql .= "INSERT INTO `{$table}` VALUES(" . implode( ', ', $values ) . ");\n";
                }
                if ( ! $write( $insert_sql ) ) {
                    return $fail( 'Could not write the database export (disk full?).' );
                }

                if ( count( $rows ) < $select_row_limit ) {
                    break;
                }
                $offset += $select_row_limit;
                if ( $keyset ) {
                    $last_key = $rows[ count( $rows ) - 1 ][ $key_pos ];
                }
            }

            if ( ! $write( "/*!40000 ALTER TABLE `{$table}` ENABLE KEYS */;\n" ) ) {
                return $fail( 'Could not write the database export (disk full?).' );
            }
        }

        $wpdb->query( 'COMMIT' );
        if ( ! $write( "SET FOREIGN_KEY_CHECKS = 1;\nSET UNIQUE_CHECKS = 1;\n" . self::EXPORT_COMPLETE_MARKER . "\n" ) ) {
            return $fail( 'Could not write the database export (disk full?).' );
        }
        if ( ! fclose( $file_handle ) ) {
            @unlink( $backup_file );
            return new \WP_Error( 'export_failed', 'Could not finish writing the database export.', [ 'status' => 500 ] );
        }
        return $backup_url;
    }

    /**
     * Last statement of every batch export. A harmless SQL statement rather
     * than a comment, so older importers that run every statement don't error.
     */
    /**
     * File extension of database exports. Older versions wrote *.sql.txt;
     * export_files() reads both.
     */
    const EXPORT_EXT = '.db.txt';

    /**
     * Database export files in $dir, current and legacy names, sorted.
     */
    public static function export_files( $dir ) {
        $files = array_merge(
            glob( rtrim( $dir, '/' ) . '/*' . self::EXPORT_EXT ) ?: [],
            glob( rtrim( $dir, '/' ) . '/*.sql.txt' ) ?: []
        );
        sort( $files );
        return $files;
    }

    const EXPORT_COMPLETE_MARKER = 'SET @disembark_export_complete = 1;';

    public static function primary_key_columns( $table ) {
        global $wpdb;
        static $cache = [];
        if ( isset( $cache[ $table ] ) ) {
            return $cache[ $table ];
        }
        $keys = $wpdb->get_results( "SHOW KEYS FROM `" . str_replace( '`', '``', $table ) . "` WHERE Key_name = 'PRIMARY'" );
        $cols = [];
        foreach ( (array) $keys as $key ) {
            $cols[ (int) $key->Seq_in_index ] = $key->Column_name;
        }
        ksort( $cols );
        return $cache[ $table ] = array_values( $cols );
    }

    private static function order_by_primary_key( $table ) {
        $cols = self::primary_key_columns( $table );
        if ( empty( $cols ) ) {
            return '';
        }
        return ' ORDER BY ' . implode( ', ', array_map( function ( $c ) { return '`' . str_replace( '`', '``', $c ) . '`'; }, $cols ) );
    }

    function zip_files( $file_manifest = "", $exclude_paths = [] ) {
        if ( empty( $file_manifest ) ) { return; }

        $manifest_full_path = "{$this->backup_path}/" . basename( $file_manifest );
        if ( ! is_readable( $manifest_full_path ) ) {
            return new \WP_Error( 'manifest_not_readable', 'Manifest chunk file not found or is not readable.' );
        }

        $file_name = str_replace( ".json", "", basename( $file_manifest ) );
        $files     = json_decode( file_get_contents( $manifest_full_path ) );
        if ( ! is_array( $files ) ) { $files = []; }

        $zip_name  = "{$this->backup_path}/{$file_name}.zip";
        $web_root = dirname( WP_CONTENT_DIR );
        $core_root = rtrim( ABSPATH, '/' );
        
        $exclude_paths = array_filter( array_map( 'trim', $exclude_paths ) );

        if ( $this->archiver_type === 'ZipArchive' ) {
            if ( $this->zip_object->open( $zip_name, \ZipArchive::CREATE ) === TRUE ) {
                foreach( $files as $file ) {
                    $should_exclude = false;
                    foreach ( $exclude_paths as $exclude_path ) {
                        if ( $file->name === $exclude_path || str_starts_with( $file->name, $exclude_path . '/' ) ) {
                            $should_exclude = true;
                            break;
                        }
                    }
                    if ( ! $should_exclude ) {
                        // Determine the correct full path
                        $full_file_path = "{$web_root}/{$file->name}";
                        if ( $web_root !== $core_root && ! file_exists( $full_file_path ) && file_exists( "{$core_root}/{$file->name}" ) ) {
                            $full_file_path = "{$core_root}/{$file->name}";
                        }
                        $this->zip_object->addFile( $full_file_path, $file->name );
                    }
                }
                $this->zip_object->close();
                self::make_readable( $zip_name );
            } else {
                 return new \WP_Error('zip_open_failed', 'Could not create the zip file using ZipArchive.');
            }
        } elseif ( $this->archiver_type === 'PclZip' ) {
            $zip = new \PclZip( $zip_name );
            $www_files = [];
            $core_files = [];
            foreach( $files as $file ) {
                $should_exclude = false;
                foreach ( $exclude_paths as $exclude_path ) {
                    if ( $file->name === $exclude_path || str_starts_with( $file->name, $exclude_path . '/' ) ) {
                        $should_exclude = true;
                        break;
                    }
                }
                if ( ! $should_exclude ) {
                    $web_path = "{$web_root}/{$file->name}";
                    if ( $web_root !== $core_root && ! file_exists( $web_path ) && file_exists( "{$core_root}/{$file->name}" ) ) {
                         $core_files[] = "{$core_root}/{$file->name}";
                    } else {
                         $www_files[] = $web_path;
                    }
                }
            }
            
            $result = 0;
            if ( !empty($www_files) ) {
                // Create zip with web root files
                $result = $zip->create( $www_files, PCLZIP_OPT_REMOVE_PATH, $web_root );
                if ( $result != 0 && !empty($core_files) ) {
                    // Add core root files to existing zip
                    $result = $zip->add( $core_files, PCLZIP_OPT_REMOVE_PATH, $core_root );
                }
            } elseif ( !empty($core_files) ) {
                 // Create zip with core root files if no web root files
                 $result = $zip->create( $core_files, PCLZIP_OPT_REMOVE_PATH, $core_root );
            }

            if ( $result == 0 ) {
                return new \WP_Error('pclzip_failed', 'Could not create zip: ' . $zip->errorInfo(true));
            }
        } else {
            return new \WP_Error('no_zip_method', 'No supported zipping library found.');
        }

        // Return the public URL
        return "{$this->backup_url}/{$file_name}.zip";
    }

    /**
     * ZipArchive writes archives through a temp file created 0600, so on hosts
     * where the web server runs as another user (Kinsta, for one) the zip's
     * URL answers 404. Give finished zips normal file permissions.
     */
    private static function make_readable( $path ) {
        if ( is_file( $path ) ) {
            @chmod( $path, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 );
        }
    }

    /**
     * Builds a full backup zip in steps, in the same layout as a CLI backup
     * (everything under public/, with database-<name>.sql and
     * disembark-source.json), so it restores from the dashboard or the CLI.
     * Needs ZipArchive.
     */
    private function archive_paths( $archive_name ) {
        $base = preg_replace( '/[^a-zA-Z0-9._-]/', '', str_replace( '.zip', '', basename( (string) $archive_name ) ) );
        if ( $base === '' ) {
            return new \WP_Error( 'bad_archive', 'Archive name is required.', [ 'status' => 400 ] );
        }
        return [
            'base' => $base,
            'zip'  => "{$this->backup_path}/{$base}.zip",
            'url'  => "{$this->backup_url}/{$base}.zip",
            'sql'  => "{$this->backup_path}/{$base}-database" . self::EXPORT_EXT,
        ];
    }

    /** Adds site files (paths relative to the site root) under public/. */
    public function archive_add_files( $archive_name, $files ) {
        if ( $this->archiver_type !== 'ZipArchive' ) {
            return new \WP_Error( 'no_zip', 'This server has no ZipArchive, so backups have to run through the CLI.', [ 'status' => 501 ] );
        }
        $paths = $this->archive_paths( $archive_name );
        if ( is_wp_error( $paths ) ) {
            return $paths;
        }
        $web_root  = dirname( WP_CONTENT_DIR );
        $core_root = rtrim( ABSPATH, '/' );
        $zip       = new \ZipArchive();
        if ( $zip->open( $paths['zip'], \ZipArchive::CREATE ) !== true ) {
            return new \WP_Error( 'zip_open_failed', 'Could not open the backup zip.', [ 'status' => 500 ] );
        }
        $added = 0;
        $missing = 0;
        foreach ( (array) $files as $file ) {
            $relative = is_array( $file ) ? ( $file['name'] ?? '' ) : (string) $file;
            $relative = ltrim( str_replace( '\\', '/', (string) $relative ), '/' );
            if ( $relative === '' || strpos( '/' . $relative . '/', '/../' ) !== false ) {
                continue;
            }
            $full = "{$web_root}/{$relative}";
            if ( $web_root !== $core_root && ! file_exists( $full ) && file_exists( "{$core_root}/{$relative}" ) ) {
                $full = "{$core_root}/{$relative}";
            }
            if ( is_file( $full ) && is_readable( $full ) && $zip->addFile( $full, 'public/' . $relative ) ) {
                $added++;
            } else {
                $missing++;
            }
        }
        if ( ! $zip->close() ) {
            return new \WP_Error( 'zip_write_failed', 'Could not write the backup zip (is the disk full?).', [ 'status' => 500 ] );
        }
        self::make_readable( $paths['zip'] );
        return [ 'added' => $added, 'missing' => $missing, 'size' => filesize( $paths['zip'] ) ];
    }

    /**
     * Exports a batch of tables (or one part of a large table) and appends the
     * SQL to the archive's database dump.
     */
    public function archive_add_database( $archive_name, $tables = [], $table = '', $part = 0, $rows_per_part = 0 ) {
        $paths = $this->archive_paths( $archive_name );
        if ( is_wp_error( $paths ) ) {
            return $paths;
        }
        if ( $table !== '' ) {
            $url = $this->database_export( $table, (int) $part, (int) $rows_per_part );
            if ( ! $url ) {
                return new \WP_Error( 'export_failed', "Could not export {$table}.", [ 'status' => 500 ] );
            }
        } else {
            $url = $this->database_export_batch( (array) $tables );
            if ( is_wp_error( $url ) ) {
                return $url;
            }
        }
        $part_file = $this->backup_path . '/' . basename( (string) $url );
        $in  = @fopen( $part_file, 'rb' );
        $out = @fopen( $paths['sql'], 'ab' );
        if ( ! $in || ! $out ) {
            return new \WP_Error( 'export_failed', 'Could not assemble the database dump.', [ 'status' => 500 ] );
        }
        $copied = stream_copy_to_stream( $in, $out );
        fclose( $in );
        fclose( $out );
        @unlink( $part_file );
        if ( $copied === false ) {
            return new \WP_Error( 'export_failed', 'Could not write the database dump (is the disk full?).', [ 'status' => 500 ] );
        }
        return [ 'bytes' => $copied, 'dump_size' => filesize( $paths['sql'] ) ];
    }

    /** Adds the database dump and the source record, and returns the zip's URL. */
    public function archive_finish( $archive_name ) {
        global $wpdb;
        $paths = $this->archive_paths( $archive_name );
        if ( is_wp_error( $paths ) ) {
            return $paths;
        }
        $zip = new \ZipArchive();
        if ( $zip->open( $paths['zip'], \ZipArchive::CREATE ) !== true ) {
            return new \WP_Error( 'zip_open_failed', 'Could not open the backup zip.', [ 'status' => 500 ] );
        }
        if ( file_exists( $paths['sql'] ) ) {
            $zip->addFile( $paths['sql'], 'public/database-' . $paths['base'] . '.sql' );
        }
        $zip->addFromString( 'public/disembark-source.json', wp_json_encode( [
            'home_url'  => home_url(),
            'site_url'  => site_url(),
            'db_prefix' => $wpdb->prefix,
            'captured'  => gmdate( 'c' ),
        ], JSON_PRETTY_PRINT ) );
        if ( ! $zip->close() ) {
            return new \WP_Error( 'zip_write_failed', 'Could not finish the backup zip (is the disk full?).', [ 'status' => 500 ] );
        }
        self::make_readable( $paths['zip'] );
        @unlink( $paths['sql'] );
        return [ 'url' => $paths['url'], 'file_name' => basename( $paths['zip'] ), 'size' => filesize( $paths['zip'] ) ];
    }

    function zip_file_list( $files = [], $archive_name = null ) {
        if ( empty( $files ) ) {
            return new \WP_Error( 'no_files', 'No files provided to zip.' );
        }

        if ( !empty( $archive_name ) ) {
            $safe_name = preg_replace( '/[^a-zA-Z0-9._-]/', '', basename( $archive_name ) );
            $file_name = str_replace( '.zip', '', $safe_name );
        } else {
            $file_name = "sync-files-" . uniqid();
        }

        $zip_name  = "{$this->backup_path}/{$file_name}.zip";
        $web_root  = dirname( WP_CONTENT_DIR );
        $core_root = rtrim( ABSPATH, '/' );
        
        // CHECK: Did the zip exist before we started this specific chunk?
        $zip_existed_before = file_exists( $zip_name );
        $files_added = 0;

        if ( $this->archiver_type === 'ZipArchive' ) {
            // ZipArchive::CREATE opens existing files for appending, which is what we want.
            if ( $this->zip_object->open( $zip_name, \ZipArchive::CREATE ) === TRUE ) {
                foreach( $files as $file_obj ) {
                    if ( !is_array($file_obj) || empty($file_obj['name']) ) continue;
                    $file_relative_path = $file_obj['name'];

                    $full_file_path = "{$web_root}/{$file_relative_path}";
                    if ( $web_root !== $core_root && ! file_exists( $full_file_path ) && file_exists( "{$core_root}/{$file_relative_path}" ) ) {
                        $full_file_path = "{$core_root}/{$file_relative_path}";
                    }

                    if ( file_exists( $full_file_path ) && is_readable( $full_file_path ) ) {
                        if ( $this->zip_object->addFile( $full_file_path, $file_relative_path ) ) {
                            $files_added++;
                        }
                    }
                }
                $this->zip_object->close();
                self::make_readable( $zip_name );
            } else {
                 return new \WP_Error('zip_open_failed', 'Could not create the zip file using ZipArchive.');
            }
        } elseif ( $this->archiver_type === 'PclZip' ) {
            $zip = new \PclZip( $zip_name );
            $www_files = [];
            $core_files = [];
            foreach( $files as $file_obj ) {
                if ( !is_array($file_obj) || empty($file_obj['name']) ) continue;
                $file_relative_path = $file_obj['name'];
                
                $web_path = "{$web_root}/{$file_relative_path}";
                $core_path = "{$core_root}/{$file_relative_path}";

                if ( $web_root !== $core_root && ! file_exists( $web_path ) && file_exists( $core_path ) ) {
                     if ( is_readable( $core_path ) ) $core_files[] = $core_path;
                } else {
                     if ( file_exists( $web_path ) && is_readable( $web_path ) ) $www_files[] = $web_path;
                }
            }
            
            $files_added = count($www_files) + count($core_files);
            
            // If zip exists, use 'add', otherwise 'create'.
            // PclZip::create overwrites, PclZip::add appends.
            $pclzip_method = $zip_existed_before ? 'add' : 'create';
            
            $result = 0;
            if ( !empty($www_files) ) {
                $result = $zip->$pclzip_method( $www_files, PCLZIP_OPT_REMOVE_PATH, $web_root );
                if ( $result != 0 && !empty($core_files) ) {
                    // Always 'add' for the second batch in the same request
                    $result = $zip->add( $core_files, PCLZIP_OPT_REMOVE_PATH, $core_root );
                }
            } elseif ( !empty($core_files) ) {
                 $result = $zip->$pclzip_method( $core_files, PCLZIP_OPT_REMOVE_PATH, $core_root );
            }

            if ( $result == 0 && $files_added > 0 ) {
                return new \WP_Error('pclzip_failed', 'Could not create zip: ' . $zip->errorInfo(true));
            }
        } else {
            return new \WP_Error('no_zip_method', 'No supported zipping library found.');
        }

        // Only delete if no files were added AND it didn't exist before.
        // This prevents deleting a valid zip just because the current chunk was empty/skipped.
        if ( $files_added === 0 && ! $zip_existed_before ) {
            if ( file_exists( $zip_name ) ) {
                unlink( $zip_name );
            }
            return new \WP_Error('zip_failed_no_files', 'Zip creation failed. None of the requested files could be found.');
        }

        return "{$this->backup_url}/{$file_name}.zip";
    }
    
    function zip_database() {
        $sql_files = glob( "{$this->backup_path}/*.sql" );
        $sql_files = array_merge( $sql_files, self::export_files( $this->backup_path ) );
        $zip_name = "{$this->backup_path}/database.zip";
        
        if ( $this->archiver_type === 'ZipArchive' ) {
            if ( $this->zip_object->open ( $zip_name, \ZipArchive::CREATE ) === TRUE) {
                foreach( $sql_files as $file ) {
                    $this->zip_object->addFile( $file, basename( $file ) );
                }
                $this->zip_object->close();
                self::make_readable( $zip_name );
            }
        } elseif ( $this->archiver_type === 'PclZip' ) {
            $zip = new \PclZip($zip_name);
            $result = $zip->create($sql_files, PCLZIP_OPT_REMOVE_PATH, $this->backup_path);
            if ($result == 0) {
                return new \WP_Error('pclzip_failed', 'Could not create zip: ' . $zip->errorInfo(true));
            }
        } else {
            return new \WP_Error('no_zip_method', 'No supported zipping library found.');
        }

        foreach( $sql_files as $file ) {
            unlink( $file );
        }

        // Return the public URL
        return "{$this->backup_url}/database.zip";
    }

    public static function db_escape( $sql ) {
        global $wpdb;
        return mysqli_real_escape_string( $wpdb->dbh, $sql );
    }

    function list_manifest() {
        $manifest_path = "{$this->backup_path}/manifest.json";
        if ( file_exists($manifest_path) ) {
            return json_decode( file_get_contents( $manifest_path ) );
        }
        return [];
    }

    public function delete_backup_file( $file_name ) {
        $safe_file_name = basename( $file_name );
        // Prevent deleting files outside the backup directory
        if ( empty( $safe_file_name ) || strpos( $safe_file_name, '..' ) !== false ) {
            return new \WP_Error( 'invalid_file', 'Invalid file name provided.' );
        }

        $file_path = "{$this->backup_path}/{$safe_file_name}";
        if ( file_exists( $file_path ) ) {
            if ( is_writable( $file_path ) && unlink( $file_path ) ) {
                return [ 'success' => true, 'message' => "Deleted {$safe_file_name}." ];
            } else {
                return new \WP_Error( 'delete_failed', "Could not delete {$safe_file_name}. Check permissions." );
            }
        }
        // Return success even if file not found, as the goal is for it to be gone
        return [ 'success' => true, 'message' => "File {$safe_file_name} not found or already deleted." ];
    }
}