<?php

namespace DisembarkConnector;

class Backup {

    private $backup_path      = "";
    private $backup_url       = "";
    private $token            = "";
    private $zip              = "";
    private $rows_per_segment = 100;

    public function __construct( $token = "" ) {
        $bytes             = random_bytes( 20 );
        $this->token       = empty( $token ) ? substr( bin2hex( $bytes ), 0, -28) : $token;
        $this->zip         = new \ZipArchive;
        $this->backup_path = wp_upload_dir()["basedir"] . "/disembark/{$this->token}";
        $this->backup_url  = wp_upload_dir()["baseurl"] . "/disembark/{$this->token}";
        if ( ! file_exists( $this->backup_path )) {
            mkdir( $this->backup_path, 0777, true );
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
            // Table creation text.
            if ( ! isset( $create_table[0] ) ) {
                echo 'Error: Unable to get table creation SQL for table `' . $table . '`. Result: `' . print_r( $create_table ) . '`. Skipping table.';
                return false;
            }
            $create_table_array = $create_table[0];
            unset( $create_table );
            $insert_sql .= str_replace( "\n", '', $create_table_array[1] ) . ";\n"; // Remove internal linebreaks; only put one at end.
            unset( $create_table_array );

            // Disable keys for this table.
            $insert_sql .= "/*!40000 ALTER TABLE `{$table}` DISABLE KEYS */;\n";

            // Disable foreign key and unique checks temporarily to help avoid errors.
            $insert_sql .= "SET FOREIGN_KEY_CHECKS = 0;\n";
            $insert_sql .= "SET UNIQUE_CHECKS = 0;\n";
        }

        $query_count = 0;
        $rows_remain = true;
        while ( true === $rows_remain ) {
            // End early and limit select if we've reached the limit.
            if ( $rows_per_part > 0 && ( $query_count + $select_row_limit ) >= $rows_per_part ) {
                $select_row_limit = $rows_per_part - $query_count;
                $rows_remain = false;
            }
            // Row creation text for all rows within this table.
            $query       = "SELECT * FROM `$table` LIMIT " . $rows_start . ',' . $select_row_limit;
            $table_query = $wpdb->get_results( $query, ARRAY_N );
            $rows_start += $select_row_limit; // Next loop we will begin at this offset.
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

        // Re-enable keys for this table.
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
        $file_name = str_replace( ".json", "", basename( $file_manifest ) );
        $files     = json_decode( file_get_contents( $file_manifest ) );
        $zip_name  = "{$this->backup_path}/{$file_name}.zip";
        $directory = get_home_path();

        // Ensure exclude paths are clean (no whitespace).
        $exclude_paths = array_filter( array_map( 'trim', $exclude_paths ) );

        if ( $this->zip->open ( $zip_name, \ZipArchive::CREATE ) === TRUE) {
            foreach( $files as $file ) {
                
                $should_exclude = false;
                foreach ( $exclude_paths as $exclude_path ) {
                    // Check for an exact match (for excluding a specific file).
                    if ( $file->name === $exclude_path ) {
                        $should_exclude = true;
                        break;
                    }
                    // Check if the file is within an excluded directory.
                    if ( str_starts_with( $file->name, $exclude_path . '/' ) ) {
                        $should_exclude = true;
                        break; 
                    }
                }

                // Add the file to the zip only if it's not marked for exclusion.
                if ( ! $should_exclude ) {
                     $this->zip->addFile( "{$directory}/{$file->name}", $file->name );
                }
            }
            $this->zip->close();
        }
        return "{$this->backup_url}/{$file_name}.zip";
    }

    function match_and_zip_files( $file_or_path = "" ) {
        $files     = ( new Run )->list_files( "", [ $file_or_path ] );
        $file_name = sanitize_title( $file_or_path );
        $zip_name  = "{$this->backup_path}/files-{$file_name}.zip";
        $directory = get_home_path();
        if ( $this->zip->open ( $zip_name, \ZipArchive::CREATE ) === TRUE) {
            foreach( $files as $file ) {
                $this->zip->addFile( "{$directory}/{$file->name}", $file->name );
            }
            $this->zip->close();
        }
        return "{$this->backup_url}/files-{$file_name}.zip";
    }

    function zip_database( $table = "" ) {
        if ( ! empty( $table ) ) {
            $zip_name = "{$this->backup_path}/database-{$table}.zip";
            $file     = "{$this->backup_path}/{$table}.sql";
            if ( $this->zip->open ( $zip_name, \ZipArchive::CREATE ) === TRUE) {
                $this->zip->addFile( $file, basename( $file ) );
                $this->zip->close();
            }
            unlink( $file );
            return "{$this->backup_url}/database-{$table}.zip";
        }
        $database_files = glob( "{$this->backup_path}/*.sql" );
        $zip_name       = "{$this->backup_path}/database.zip";
        if ( $this->zip->open ( $zip_name, \ZipArchive::CREATE ) === TRUE) {
            foreach( $database_files as $file ) {
                $this->zip->addFile( $file, basename( $file ) );
            }
            $this->zip->close();
        }
        // Delete all SQL files
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
                "name"  => "{$this->backup_path}/files-{$manifest_count}.json",
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