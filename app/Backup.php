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
        $this->token       = empty( $token ) ? substr( bin2hex( $bytes ), 0, -28) : $token;
        $this->backup_path = wp_upload_dir()["basedir"] . "/disembark/{$this->token}";
        $this->backup_url  = wp_upload_dir()["baseurl"] . "/disembark/{$this->token}";
        if ( ! file_exists( $this->backup_path )) {
            mkdir( $this->backup_path, 0777, true );
        }
        
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

    public function process_scan_step( $exclude_paths, $include_checksums = true ) {
        $state_file = "{$this->backup_path}/_scan_state.json";
        $filtered_list_path = "{$this->backup_path}/_filtered_file_list.json";
        $state = json_decode( file_get_contents( $state_file ), true );

        $operation_limit = 5000;
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

            while ( $item = array_shift( $state['current_directory_items'] ) ) {
                
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
        
        foreach ( $manifest_chunks as $chunk_file ) {
            $content = json_decode( file_get_contents( $chunk_file ) );
            $file_count = count( $content );
            $total_size = array_sum( array_column( $content, 'size' ) );
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
        
        // Use a .txt extension to avoid server-side .sql file download blocks
        $file_ext         = ".sql.txt";
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
            $query       = "SELECT * FROM `$table` LIMIT " . $rows_start . ',' . $select_row_limit;
            $table_query = $wpdb->get_results( $query, ARRAY_N );
            $rows_start += $select_row_limit;
            if ( false === $table_query ) {
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

    public function database_export_batch( $tables = [] ) {
        global $wpdb;

        if ( empty( $tables ) ) {
            return false;
        }

        // Create a unique-ish name for the batch file
        $batch_hash = md5( implode( ',', $tables ) );
        $file_ext    = ".sql.txt";
        $backup_file = "{$this->backup_path}/batch-{$batch_hash}{$file_ext}";
        $backup_url  = "{$this->backup_url}/batch-{$batch_hash}{$file_ext}";

        if ( false === ( $file_handle = fopen( $backup_file, 'w' ) ) ) { // Use 'w' to create a new file
            return false;
        }

        // Write headers once
        fwrite( $file_handle, "SET FOREIGN_KEY_CHECKS = 0;\nSET UNIQUE_CHECKS = 0;\n" );

        // Add paging
        $select_row_limit = 1000; // Use the same paging as the single export

        foreach ( $tables as $table ) {
            $insert_sql = '';
            // 1. Get CREATE TABLE statement
            $create_table = $wpdb->get_results( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
            if ( false === $create_table || ! isset( $create_table[0] ) ) {
                continue; // Skip this table on error
            }
            $create_table_array = $create_table[0];
            unset( $create_table );
            $insert_sql .= str_replace( "\n", '', $create_table_array[1] ) . ";\n";
            unset( $create_table_array );

            // 2. Add DISABLE KEYS
            $insert_sql .= "/*!40000 ALTER TABLE `{$table}` DISABLE KEYS */;\n";
            if ( false === fwrite( $file_handle, $insert_sql ) ) {
                continue; // Error writing
            }
            $insert_sql = ''; // Reset for rows

            // 3. Get rows WITH PAGING
            $rows_start = 0;
            $rows_remain = true;
            $num_fields = 0; // Initialize
            $first_run = true;

            while ( true === $rows_remain ) {
                $query       = "SELECT * FROM `$table` LIMIT " . $rows_start . ',' . $select_row_limit;
                $table_query = $wpdb->get_results( $query, ARRAY_N );
                $rows_start += $select_row_limit;

                if ( false === $table_query ) {
                    $rows_remain = false; // Error, stop processing this table
                    break; 
                }

                if ( $first_run ) {
                    $columns = $wpdb->get_col_info(); // Get info from the first query
                    $num_fields = count( $columns );
                    $first_run = false;
                }
                
                $table_count = count( $table_query );
                if ( 0 == $table_count || $table_count < $select_row_limit ) {
                    $rows_remain = false;
                }
                
                if ( $num_fields == 0 ) {
                    // No columns found, or table is empty. In either case, we're done.
                    $rows_remain = false;
                    continue;
                }

                // 4. Loop through rows and build INSERT statements
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
                    
                    // Write to file
                    if ( false === fwrite( $file_handle, $insert_sql ) ) {
                        $rows_remain = false; // Stop processing this table if write fails
                        break; 
                    }
                    $insert_sql = '';
                }
            } // end while $rows_remain
            
            // 5. Add ENABLE KEYS
            $insert_sql = "/*!40000 ALTER TABLE `{$table}` ENABLE KEYS */;\n";
            fwrite( $file_handle, $insert_sql );
        } // end foreach $tables

        // Write footers once
        fwrite( $file_handle, "SET FOREIGN_KEY_CHECKS = 1;\nSET UNIQUE_CHECKS = 1;\n" );
        
        @fclose( $file_handle );
        // Return the public URL
        return $backup_url;
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

    function zip_file_list( $files = [] ) {
        if ( empty( $files ) ) {
            return new \WP_Error( 'no_files', 'No files provided to zip.' );
        }
        // Use a unique name for this sync zip
        $file_name = "sync-files-" . time();
        $zip_name  = "{$this->backup_path}/{$file_name}.zip";
        $web_root  = dirname( WP_CONTENT_DIR );
        $core_root = rtrim( ABSPATH, '/' );
        $files_added = 0; // Add a counter

        // We don't need to check $exclude_paths, the client already filtered.

        if ( $this->archiver_type === 'ZipArchive' ) {
            if ( $this->zip_object->open( $zip_name, \ZipArchive::CREATE ) === TRUE ) {
                foreach( $files as $file_obj ) {
                    // $file_obj is an associative array from the client
                    if ( !is_array($file_obj) || empty($file_obj['name']) ) continue;
                    $file_relative_path = $file_obj['name'];

                    $full_file_path = "{$web_root}/{$file_relative_path}";
                    if ( $web_root !== $core_root && ! file_exists( $full_file_path ) && file_exists( "{$core_root}/{$file_relative_path}" ) ) {
                        $full_file_path = "{$core_root}/{$file_relative_path}";
                    }
                    // Only add if it exists, otherwise zip fails
                    if ( file_exists( $full_file_path ) && is_readable( $full_file_path ) ) {
                        if ( $this->zip_object->addFile( $full_file_path, $file_relative_path ) ) {
                            $files_added++; // Increment counter
                        }
                    }
                }
                $this->zip_object->close();
            } else {
                 return new \WP_Error('zip_open_failed', 'Could not create the zip file using ZipArchive.');
            }
        } elseif ( $this->archiver_type === 'PclZip' ) {
            $zip = new \PclZip( $zip_name );
            $www_files = [];
            $core_files = [];
            foreach( $files as $file_obj ) {
                // $file_obj is an associative array from the client
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
            
            $files_added = count($www_files) + count($core_files); // Set counter
            
            $result = 0;
            if ( !empty($www_files) ) {
                $result = $zip->create( $www_files, PCLZIP_OPT_REMOVE_PATH, $web_root );
                if ( $result != 0 && !empty($core_files) ) {
                    $result = $zip->add( $core_files, PCLZIP_OPT_REMOVE_PATH, $core_root );
                }
            } elseif ( !empty($core_files) ) {
                 $result = $zip->create( $core_files, PCLZIP_OPT_REMOVE_PATH, $core_root );
            }

            // Only error if PclZip failed AND we actually had files to add
            if ( $result == 0 && $files_added > 0 ) {
                return new \WP_Error('pclzip_failed', 'Could not create zip: ' . $zip->errorInfo(true));
            }
        } else {
            return new \WP_Error('no_zip_method', 'No supported zipping library found.');
        }

        // Check if we successfully added any files
        if ( $files_added === 0 ) {
            // We successfully "created" an empty zip or no zip at all because no files were found/readable.
            // Delete the empty zip if it exists and return an error.
            if ( file_exists( $zip_name ) ) {
                unlink( $zip_name );
            }
            return new \WP_Error('zip_failed_no_files', 'Zip creation failed. None of the requested ' . count($files) . ' files could be found or read on the server.');
        }

        // Return the public URL
        return "{$this->backup_url}/{$file_name}.zip";
    }
    
    function zip_database() {
        $sql_files = glob( "{$this->backup_path}/*.sql" );
        $sql_txt_files = glob( "{$this->backup_path}/*.sql.txt" );
        $sql_files = array_merge( $sql_files, $sql_txt_files );
        $zip_name = "{$this->backup_path}/database.zip";
        
        if ( $this->archiver_type === 'ZipArchive' ) {
            if ( $this->zip_object->open ( $zip_name, \ZipArchive::CREATE ) === TRUE) {
                foreach( $database_files as $file ) {
                    $this->zip_object->addFile( $file, basename( $file ) );
                }
                $this->zip_object->close();
            }
        } elseif ( $this->archiver_type === 'PclZip' ) {
            $zip = new \PclZip($zip_name);
            $result = $zip->create($database_files, PCLZIP_OPT_REMOVE_PATH, $this->backup_path);
            if ($result == 0) {
                return new \WP_Error('pclzip_failed', 'Could not create zip: ' . $zip->errorInfo(true));
            }
        } else {
            return new \WP_Error('no_zip_method', 'No supported zipping library found.');
        }

        foreach( $database_files as $file ) {
            unlink( $file );
        }

        // Return the public URL
        return "{$this->backup_url}/database.zip";
    }

    function list_downloads() {
        $sql_files = glob( "{$this->backup_path}/*.sql" );
        $sql_txt_files = glob( "{$this->backup_path}/*.sql.txt" );
        $sql_files = array_merge( $sql_files, $sql_txt_files );
        $zip_files = glob( "{$this->backup_path}/*.zip" );
        $files     = [];
        natsort($sql_files);
        natsort($zip_files);
        foreach ( $sql_files as $file ) {
            $files[] = str_replace( $this->backup_path, $this->backup_url, $file );
        }
        foreach ( $zip_files as $file ) {
            $files[] = str_replace( $this->backup_path, $this->backup_url, $file );
        }
        return $files;
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