<?php

namespace DisembarkConnector;
class Backup {

    private $backup_path      = "";
    private $backup_url       = "";
    private $token            = "";
    private $rows_per_segment = 100;
    private $archiver_type    = 'none'; // Can be 'ZipArchive', 'PclZip', or 'none'
    private $zip_object       = null;   // Will hold the ZipArchive object if used

    public function __construct( $token = "" ) {
        $bytes             = random_bytes( 20 );
        $this->token       = empty( $token ) ? substr( bin2hex( $bytes ), 0, -28) : $token;
        $this->backup_path = wp_upload_dir()["basedir"] . "/disembark/{$this->token}";
        $this->backup_url  = wp_upload_dir()["baseurl"] . "/disembark/{$this->token}";
        if ( ! file_exists( $this->backup_path )) {
            mkdir( $this->backup_path, 0777, true );
        }
        
        // Determine which zipping method is available
        if ( class_exists( 'ZipArchive' ) ) {
            // The best method is available, so we'll use it
            $this->archiver_type = 'ZipArchive';
            $this->zip_object    = new \ZipArchive();
        } else {
            // ZipArchive is not found, let's try to use PclZip as a fallback
            if ( ! class_exists( 'PclZip' ) ) {
                $pclzip_path = ABSPATH . 'wp-admin/includes/class-pclzip.php';
                if ( file_exists( $pclzip_path ) ) {
                    require_once $pclzip_path;
                }
            }
            
            // Now, double-check that the class exists after our attempt to load it
            if ( class_exists( 'PclZip' ) ) {
                $this->archiver_type = 'PclZip';
            } else {
                // If we reach this point, no zipping method is available
                $this->archiver_type = 'none';
            }
        }
    }

    public function initiate_scan_state() {
        $state_file = "{$this->backup_path}/_scan_state.json";
        $initial_state = [
            'status' => 'scanning',
            'directories_to_scan' => [ \get_home_path() ],
            'total_dirs' => 1,
            'scanned_dirs' => 0
        ];
        file_put_contents( $state_file, json_encode( $initial_state ) );
    }

    public function process_scan_step( $exclude_paths ) {
        $state_file = "{$this->backup_path}/_scan_state.json";
        $filtered_list_path = "{$this->backup_path}/_filtered_file_list.json";
        $state = json_decode( file_get_contents( $state_file ), true );

        // Define how many directories to scan in a single request.
        $batch_size = 25;

        if ( empty( $state['directories_to_scan'] ) ) {
            $state['status'] = 'scan_complete';
            file_put_contents( $state_file, json_encode( $state ) );
            return $state;
        }

        // Process a batch of directories from the list.
        $directories_in_batch = array_splice( $state['directories_to_scan'], 0, $batch_size );
        $state['scanned_dirs'] += count( $directories_in_batch );

        $file_handle = fopen( $filtered_list_path, 'a' );
        $home_path = \get_home_path();

        // Loop through the batch and process each directory.
        foreach ( $directories_in_batch as $directory_to_scan ) {
            $items = @scandir( $directory_to_scan );
            if ($items === false) {
                continue; // Skip directories that can't be read.
            }

            foreach ( $items as $item ) {
                if ( $item === '.' || $item === '..' ) continue;

                $full_path = $directory_to_scan . '/' . $item;
                $relative_path = ltrim( str_replace( $home_path, '', $full_path ), '/' );

                // Check against exclusion list
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
                        $state['directories_to_scan'][] = $full_path;
                        $state['total_dirs']++;
                    }
                } elseif ( is_file( $full_path ) ) {
                    $file_info = [
                        'name' => $relative_path,
                        'size' => filesize( $full_path ),
                        'type' => 'file'
                    ];
                    fwrite( $file_handle, json_encode($file_info) . "\n" );
                }
            }
        }

        fclose( $file_handle );
        file_put_contents( $state_file, json_encode( $state ) );
        return $state;
    }

    public function save_filtered_list( $files ) {
        $path = "{$this->backup_path}/_filtered_file_list.json";
        file_put_contents( $path, json_encode( $files ) );
    }

    public function finalize_manifest() {
        $manifest_chunks = glob( "{$this->backup_path}/files-*.json" );
        $response = [];
        
        // Sort the manifest chunk filenames naturally before processing them.
        natsort( $manifest_chunks );
        
        foreach ( $manifest_chunks as $chunk_file ) {
            $content = json_decode( file_get_contents( $chunk_file ) );
            $file_count = count( $content );
            $total_size = array_sum( array_column( $content, 'size' ) );

            $response[] = (object) [
                "name"  => basename( $chunk_file ),
                "url"   => "{$this->backup_url}/" . basename( $chunk_file ),
                "size"  => $total_size,
                "count" => $file_count
            ];
        }

        // The $response array is now built in the correct order, so the problematic sort is no longer needed.
        file_put_contents( "{$this->backup_path}/manifest.json", json_encode( array_values( $response ), JSON_PRETTY_PRINT ) );
        return $this->list_manifest();
    }

    public function chunkify_manifest( $chunk_size ) {
        $filtered_list_path = "{$this->backup_path}/_filtered_file_list.json";
        if ( ! file_exists( $filtered_list_path ) ) {
            return ['total_chunks' => 0];
        }

        $line_count = 0;
        $handle = fopen($filtered_list_path, "r");
        if ($handle) {
            // This loop reads the file one line at a time, using very little memory.
            while(!feof($handle)){
                if (fgets($handle) !== false) {
                    $line_count++;
                }
            }
            fclose($handle);
        }

        return [
            'total_chunks' => ceil( $line_count / $chunk_size )
        ];
    }

    public function process_manifest_chunk( $chunk_number, $chunk_size ) {
        $filtered_list_path = "{$this->backup_path}/_filtered_file_list.json";
        if ( ! file_exists( $filtered_list_path ) ) {
            return new \WP_Error('no_list', 'Filtered file list not found.');
        }

        $handle = fopen($filtered_list_path, "r");
        if (!$handle) {
            return new \WP_Error('file_open_error', 'Could not open the file list.');
        }

        $offset = ( $chunk_number - 1 ) * $chunk_size;
        $current_line = 0;
        $chunk_objects = [];

        // This loop efficiently skips to the starting line of the required chunk.
        while ($current_line < $offset && !feof($handle)) {
            fgets($handle);
            $current_line++;
        }

        // This loop reads only the lines needed for the current chunk.
        $lines_in_chunk = 0;
        while ($lines_in_chunk < $chunk_size && !feof($handle)) {
            $line = fgets($handle);
            if ($line !== false && trim($line) !== '') {
                $chunk_objects[] = json_decode($line);
                $lines_in_chunk++;
            }
        }
        fclose($handle);

        $chunk_manifest_path = "{$this->backup_path}/files-{$chunk_number}.json";
        file_put_contents( $chunk_manifest_path, json_encode( $chunk_objects, JSON_PRETTY_PRINT ) );

        return [ 'success' => true, 'chunk' => $chunk_number, 'file_count' => count($chunk_objects) ];
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
        $backup_file      = "{$this->backup_path}/{$table}.sql";
        $backup_url       = "{$this->backup_url}/{$table}.sql";
        if ( ! empty( $parts ) ) {
            $backup_file  = "{$this->backup_path}/{$table}-{$parts}.sql";
            $backup_url   = "{$this->backup_url}/{$table}-{$parts}.sql";
            $rows_start   = ( $parts - 1 ) * $rows_per_part;
        }

        if ( false === ( $file_handle = fopen( $backup_file, 'a' ) ) ) {
            echo 'Error: Database file is not creatable/writable. Check your permissions for file `' . htmlspecialchars( $backup_file ) . '` in directory `' . htmlspecialchars( $this->backup_path ) . '`.';
            return false;
        }

        if ( 0 == $rows_start ) {
            $create_table = $wpdb->get_results( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
            if ( false === $create_table ) {
                echo 'Error: Unable to access and dump database table `' . $table . '`. Table may not exist. Skipping table.';
                return;
            }
            if ( ! isset( $create_table[0] ) ) {
                echo 'Error: Unable to get table creation SQL for table `' . $table . '`. Result: `' . print_r( $create_table ) . '`. Skipping table.';
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
                echo 'Error: Unable to retrieve data from table `' . $table . '`. This table may be corrupt (try repairing the database) or too large to hold in memory (increase mysql and/or PHP memory). Skipping table.';
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
                    echo 'Error: Unable to write to SQL file. Return error/bytes written: `' . $write_return . '`. Skipping table.';
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
            echo 'Error: Unable to write to SQL file. Return error/bytes written: `' . $write_return . '`.';
            @fclose( $file_handle );
            return false;
        }
        $insert_sql = "";
        @fclose( $file_handle );
        unset( $file_handle );
        return $backup_url;
    }

    function zip_files( $file_manifest = "", $exclude_paths = [] ) {
        if ( empty( $file_manifest ) ) {
            return;
        }

        // Construct the full path to the manifest file inside the backup directory.
        $manifest_full_path = "{$this->backup_path}/" . basename( $file_manifest );
        
        // Verify the manifest chunk file exists and is readable.
        if ( ! is_readable( $manifest_full_path ) ) {
            return new \WP_Error( 'manifest_not_readable', 'Manifest chunk file not found or is not readable.' );
        }

        $file_name = str_replace( ".json", "", basename( $file_manifest ) );
        $files     = json_decode( file_get_contents( $manifest_full_path ) );

        // If the manifest is empty or invalid, ensure $files is an array to avoid errors.
        if ( ! is_array( $files ) ) {
            $files = [];
        }

        $zip_name  = "{$this->backup_path}/{$file_name}.zip";
        $directory = get_home_path();
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
                         $this->zip_object->addFile( "{$directory}/{$file->name}", $file->name );
                    }
                }
                $this->zip_object->close();
            } else {
                 return new \WP_Error('zip_open_failed', 'Could not create the zip file using ZipArchive.');
            }
        } elseif ( $this->archiver_type === 'PclZip' ) {
            $zip = new \PclZip( $zip_name );
            $files_to_add = [];
            foreach( $files as $file ) {
                $should_exclude = false;
                foreach ( $exclude_paths as $exclude_path ) {
                    if ( $file->name === $exclude_path || str_starts_with( $file->name, $exclude_path . '/' ) ) {
                        $should_exclude = true;
                        break;
                    }
                }
                if ( ! $should_exclude ) {
                    $files_to_add[] = "{$directory}/{$file->name}";
                }
            }
            $result = $zip->create( $files_to_add, PCLZIP_OPT_REMOVE_PATH, $directory );
            if ( $result == 0 ) {
                return new \WP_Error('pclzip_failed', 'Could not create the zip file using PclZip: ' . $zip->errorInfo(true));
            }
        } else {
            return new \WP_Error('no_zip_method', 'The server does not have a supported zipping library (ZipArchive or PclZip).');
        }
        return "{$this->backup_url}/{$file_name}.zip";
    }

    function match_and_zip_files( $file_or_path = "" ) {
        $files     = ( new Run )->list_files( "", [ $file_or_path ] );
        $file_name = sanitize_title( $file_or_path );
        $zip_name  = "{$this->backup_path}/files-{$file_name}.zip";
        $directory = get_home_path();

        if ( $this->archiver_type === 'ZipArchive' ) {
            if ( $this->zip_object->open ( $zip_name, \ZipArchive::CREATE ) === TRUE) {
                foreach( $files as $file ) {
                    $this->zip_object->addFile( "{$directory}/{$file->name}", $file->name );
                }
                $this->zip_object->close();
            }
        } elseif ( $this->archiver_type === 'PclZip' ) {
            $zip = new \PclZip($zip_name);
            $files_to_add = [];
            foreach( $files as $file ) {
                $files_to_add[] = "{$directory}/{$file->name}";
            }
            $result = $zip->create( $files_to_add, PCLZIP_OPT_REMOVE_PATH, $directory );
            if ( $result == 0 ) {
                return new \WP_Error('pclzip_failed', 'Could not create the zip file using PclZip: ' . $zip->errorInfo(true));
            }
        } else {
            return new \WP_Error('no_zip_method', 'The server does not have a supported zipping library (ZipArchive or PclZip).');
        }
        return "{$this->backup_url}/files-{$file_name}.zip";
    }

    function zip_database( $table = "" ) {
        if ( ! empty( $table ) ) {
            $zip_name = "{$this->backup_path}/database-{$table}.zip";
            $file     = "{$this->backup_path}/{$table}.sql";
            if ( $this->archiver_type === 'ZipArchive' ) {
                if ( $this->zip_object->open ( $zip_name, \ZipArchive::CREATE ) === TRUE) {
                    $this->zip_object->addFile( $file, basename( $file ) );
                    $this->zip_object->close();
                }
            } elseif ( $this->archiver_type === 'PclZip' ) {
                 $zip = new \PclZip($zip_name);
                 $result = $zip->create($file, PCLZIP_OPT_REMOVE_PATH, $this->backup_path);
                 if ($result == 0) {
                     return new \WP_Error('pclzip_failed', 'Could not create the zip file using PclZip: ' . $zip->errorInfo(true));
                 }
            } else {
                return new \WP_Error('no_zip_method', 'The server does not have a supported zipping library.');
            }
            unlink( $file );
            return "{$this->backup_url}/database-{$table}.zip";
        }

        $database_files = glob( "{$this->backup_path}/*.sql" );
        $zip_name       = "{$this->backup_path}/database.zip";
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
                return new \WP_Error('pclzip_failed', 'Could not create the zip file using PclZip: ' . $zip->errorInfo(true));
            }
        } else {
            return new \WP_Error('no_zip_method', 'The server does not have a supported zipping library.');
        }

        foreach( $database_files as $file ) {
            unlink( $file );
        }
        return "{$this->backup_url}/database.zip";
    }

    function list_downloads() {
        $sql_files = glob( "{$this->backup_path}/*.sql" );
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

    function generate_manifest( $files ) {
        $storage_limit    = 104857600;
        $manifest_storage = 0;
        $manifest_count   = 1;
        $file_count       = 0;
        $file_current     = 0;
        $total_files      = count( $files );
        $manifest         = [];
        $response         = [];
        do {
            foreach ( $files as $key => $file ) {
                $manifest[] = $file;
                $manifest_storage += $file->size;
                $file_count++;
                if ( $manifest_storage + $file->size > $storage_limit ) {
                    $files = array_slice($files, $key + 1);
                    break;
                }
            }
            $response[] = (object) [
                "name"  => "files-{$manifest_count}.json",
                "url"   => "{$this->backup_url}/files-{$manifest_count}.json",
                "size"  => $manifest_storage,
                "count" => $file_count
            ];
            file_put_contents( "{$this->backup_path}/files-{$manifest_count}.json", json_encode( $manifest, JSON_PRETTY_PRINT ) );
            $manifest_storage = 0;
            $manifest         = [];
            $manifest_count++;
        } while ( $file_count < $total_files );
        file_put_contents( "{$this->backup_path}/manifest.json", json_encode( $response, JSON_PRETTY_PRINT ) );
    }

    public static function db_escape( $sql ) {
        global $wpdb;
        return mysqli_real_escape_string( $wpdb->dbh, $sql );
    }

    function list_manifest() {
        return json_decode( file_get_contents( "{$this->backup_path}/manifest.json" ) );
    }
}