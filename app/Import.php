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

        return [
            'db_prefix'          => $wpdb->prefix,
            'home_url'           => home_url(),
            'site_url'           => site_url(),
            'abspath'            => ABSPATH,
            'upload_max_filesize' => ini_get( 'upload_max_filesize' ),
            'post_max_size'      => ini_get( 'post_max_size' ),
            'upload_chunk_bytes' => self::upload_chunk_bytes(),
            'max_execution_time' => ini_get( 'max_execution_time' ),
            'tables'             => $tables,
            'php_version'        => phpversion(),
            'mysql_version'      => $mysql_version,
            'disembark_token'    => Token::read(),
        ];
    }

    /**
     * Creates a rollback snapshot of the destination database and file manifest.
     */
    public function snapshot( $label = '' ) {
        global $wpdb;

        $this->prepare_import_dir();
        Storage::ensure_dir( $this->rollback_path );

        // Export all tables
        $all_tables = $wpdb->get_col( 'SHOW TABLES' );
        if ( empty( $all_tables ) ) {
            return new \WP_Error( 'no_tables', 'No tables found to snapshot.', [ 'status' => 500 ] );
        }

        $backup   = new Backup( 'rollback-' . $this->import_id );
        $file_url = $backup->database_export_batch( $all_tables );

        if ( ! $file_url || is_wp_error( $file_url ) ) {
            $why = is_wp_error( $file_url ) ? ' ' . $file_url->get_error_message() : '';
            return new \WP_Error( 'snapshot_failed', 'Failed to create database snapshot.' . $why . ' Nothing was changed.', [ 'status' => 500 ] );
        }

        // Move the exported SQL to our rollback directory
        $backup_base = wp_upload_dir()['basedir'] . '/disembark/rollback-' . $this->import_id;
        $sql_files   = Backup::export_files( $backup_base );
        foreach ( $sql_files as $sql_file ) {
            $dest = $this->rollback_path . '/' . basename( $sql_file );
            rename( $sql_file, $dest );
        }

        // Clean up the temporary export folder (and its listing guards).
        if ( is_dir( $backup_base ) ) {
            self::delete_directory_recursive( $backup_base );
        }

        $sql_sizes = [];
        foreach ( Backup::export_files( $this->rollback_path ) as $sql_file ) {
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
    public function extract_staged_zip( $file_path ) {
        if ( ! self::is_safe_relative_path( $file_path ) ) {
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
            // The pre-extract guard applies the same zip-slip filtering the
            // ZipArchive branch does — without it PclZip would honour "../".
            $result = $zip->extract( PCLZIP_OPT_PATH, $this->staging_path, PCLZIP_CB_PRE_EXTRACT, 'Disembark\\pclzip_pre_extract_guard' );
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
            if ( self::is_safe_relative_path( $name ) ) {
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
        $rel  = ltrim( str_replace( '\\', '/', (string) $rel ), '/' );
        $base = basename( $rel );
        if ( $rel === 'disembark-source.json' || $rel === 'restore.zip' ) {
            return true;
        }
        if ( preg_match( '#^database-.*\.sql$#i', $base ) ) {
            return true;
        }
        if ( strpos( $rel, '/' ) === false && preg_match( '#\.sql$#i', $rel ) ) {
            return true;
        }
        foreach ( [ '_chunks/', '.disembark-db-parts/', '.disembark-manifests/' ] as $dir ) {
            if ( strpos( $rel, $dir ) === 0 ) {
                return true;
            }
        }
        return false;
    }

    private function list_staged_files( $root ) {
        $files = [];
        if ( ! is_dir( $root ) ) {
            return $files;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $root, \RecursiveDirectoryIterator::SKIP_DOTS )
        );
        foreach ( $it as $file ) {
            if ( ! $file->isFile() ) {
                continue;
            }
            $rel = ltrim( str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) ) ), '/' );
            if ( self::never_deploy( $rel ) ) {
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
     */
    public function import_staged_sql( $sql_file, $old_prefix = '', $new_prefix = '' ) {
        if ( ! self::is_safe_relative_path( $sql_file ) ) {
            return new \WP_Error( 'invalid_path', 'Invalid SQL file path.', [ 'status' => 400 ] );
        }
        $path = $this->staging_path . '/' . $sql_file;
        if ( ! file_exists( $path ) ) {
            return new \WP_Error( 'not_found', 'Staged SQL file not found.', [ 'status' => 404 ] );
        }
        // Streamed statement by statement: reading the whole dump (and every
        // split statement) into memory needs a multiple of its size and runs
        // out of memory on large sites.
        $run  = $this->begin_statements();
        $read = self::each_sql_statement_in_file( $path, function ( $statement ) use ( &$run, $old_prefix, $new_prefix ) {
            foreach ( self::transform_statements( [ $statement ], $old_prefix, $new_prefix ) as $transformed ) {
                if ( ! $this->run_statement( $transformed, $run ) ) {
                    return false;
                }
            }
            return true;
        } );
        if ( is_wp_error( $read ) ) {
            $run['errors'][] = $read->get_error_message();
        }
        return $this->end_statements( $run );
    }

    /**
     * Reports what is staged for a dashboard restore: file count, the located
     * SQL dump, and the captured source metadata.
     */
    public function staged_info() {
        $sql_rel = null;
        foreach ( [ '/public', '' ] as $sub ) {
            $matches = glob( $this->staging_path . $sub . '/database-*.sql' );
            if ( ! empty( $matches ) ) {
                $sql_rel = ltrim( str_replace( '\\', '/', substr( $matches[0], strlen( $this->staging_path ) ) ), '/' );
                break;
            }
        }
        $source = null;
        foreach ( [ '/public/disembark-source.json', '/disembark-source.json' ] as $rel ) {
            if ( file_exists( $this->staging_path . $rel ) ) {
                $source = json_decode( file_get_contents( $this->staging_path . $rel ) );
                break;
            }
        }
        return [
            'files'    => $this->count_staged_files(),
            'sql_file' => $sql_rel,
            'source'   => $source,
        ];
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

        // The dashboard (thin client) can't list staged files, so it passes
        // ["*"] to deploy everything. A whole-snapshot zip nests the site under
        // public/, so treat that as the staging root when present.
        $staging_root = $this->staging_path;
        if ( count( $files ) === 1 && $files[0] === '*' ) {
            if ( is_dir( $this->staging_path . '/public/wp-includes' ) || is_dir( $this->staging_path . '/public/wp-content' ) ) {
                $staging_root = $this->staging_path . '/public';
            }
            $files = $this->list_staged_files( $staging_root );
        }

        foreach ( $files as $relative_path ) {
            if ( ! self::is_safe_relative_path( $relative_path ) ) {
                $errors[] = "Unsafe path skipped: {$relative_path}";
                continue;
            }

            $source = $staging_root . '/' . $relative_path;
            $dest   = $web_root . '/' . $relative_path;

            if ( ! file_exists( $source ) ) {
                $errors[] = "Source not found: {$relative_path}";
                continue;
            }

            // Skip wp-config.php
            if ( basename( $relative_path ) === 'wp-config.php' ) {
                continue;
            }
            // Never put a database dump (or Disembark's own working files) in
            // the web root, even when a client lists it explicitly.
            if ( self::never_deploy( $relative_path ) ) {
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
     * Applies restore transformations to already-split statements: remaps the
     * table-name prefix and injects a DROP TABLE before each CREATE TABLE.
     * Both operate on the statement's leading keyword + identifier only —
     * running them over the whole dump would also rewrite INSERT *values*
     * (a post containing `wp_options` or the text "CREATE TABLE" would be
     * mangled, and a prefix-length change would break serialized data).
     * Prefix-scoped values inside the data are handled after import by
     * remap_table_prefix(), which re-serializes safely.
     */
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
     * Executes pre-transformed SQL on the destination database.
     */
    public function execute_sql( $sql_content ) {
        // Split into individual statements with a quote/comment-aware scanner.
        // A column value may legitimately contain ";" (or ";\n"), so splitting
        // on the delimiter naively would cut a statement mid-value.
        return $this->execute_statements( self::split_sql_statements( $sql_content ) );
    }

    /**
     * Executes a list of individual SQL statements on the destination database.
     */
    private function execute_statements( $statements ) {
        $run = $this->begin_statements();
        foreach ( $statements as $statement ) {
            if ( ! $this->run_statement( $statement, $run ) ) {
                break;
            }
        }
        return $this->end_statements( $run );
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
                if ( $candidate !== '' && hash_equals( $guard['sha256'], hash( 'sha256', $candidate ) ) ) {
                    $preserved_token = $candidate;
                    break;
                }
            }
        } else {
            $preserved_token = $current;
            if ( $preserved_token !== '' ) {
                $this->prepare_import_dir();
                file_put_contents( $this->import_path . '/token-guard.json', wp_json_encode( [
                    'sha256'     => hash( 'sha256', $preserved_token ),
                    'created_at' => time(),
                ] ) );
            }
        }

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

        return [
            'statements_executed' => $run['statements_executed'],
            'errors'              => $run['errors'],
        ];
    }

    /**
     * True when $token is the token this site had when import $import_id
     * started its SQL import, within the last day. Lets a restore that died
     * mid-import (leaving the source's token in the options table) still
     * finish or roll back with the token the dashboard or CLI holds.
     */
    public static function token_guard_matches( $import_id, $token ) {
        if ( ! is_string( $token ) || $token === '' ) {
            return false;
        }
        $guard = self::read_token_guard( $import_id );
        return $guard !== null && hash_equals( $guard['sha256'], hash( 'sha256', $token ) );
    }

    /**
     * The token guard for import $import_id when it exists and is under a day
     * old, else null.
     */
    private static function read_token_guard( $import_id ) {
        $import_id = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $import_id ) );
        if ( $import_id === '' ) {
            return null;
        }
        $file = Storage::base_dir() . "/_import_{$import_id}/token-guard.json";
        if ( ! is_file( $file ) ) {
            return null;
        }
        $guard = json_decode( (string) file_get_contents( $file ), true );
        if ( ! is_array( $guard ) || empty( $guard['sha256'] ) || ! is_string( $guard['sha256'] ) || empty( $guard['created_at'] ) || time() - (int) $guard['created_at'] > DAY_IN_SECONDS ) {
            return null;
        }
        return $guard;
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
    public function search_replace( $from, $to, $tables = [], $dry_run = false ) {
        global $wpdb;

        if ( $from === '' || $from === $to ) {
            return [ 'tables' => 0, 'rows_changed' => 0, 'cells_changed' => 0, 'by_table' => [], 'samples' => [] ];
        }
        $by_table = [];
        $samples  = [];

        if ( empty( $tables ) ) {
            $tables = $wpdb->get_col( 'SHOW TABLES' );
        }

        $pairs = self::replacement_pairs( $from, $to );

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

            // Page in primary-key order so no row is skipped or seen twice.
            $order  = implode( ', ', array_map( function ( $pk ) { return '`' . str_replace( '`', '``', $pk ) . '`'; }, $primary_keys ) );
            $offset = 0;
            $limit  = 1000;
            while ( true ) {
                $rows = $wpdb->get_results(
                    "SELECT * FROM `{$table}` ORDER BY {$order} LIMIT {$limit} OFFSET {$offset}",
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
                        if ( ! self::contains_any( $value, $pairs ) ) {
                            continue;
                        }
                        $replaced = self::replace_in_value( $value, $pairs );
                        if ( $replaced !== $value ) {
                            $update[ $column ] = $replaced;
                            $cells_changed++;
                            $by_table[ $table ] = ( $by_table[ $table ] ?? 0 ) + 1;
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

                    if ( ! empty( $update ) && $dry_run ) {
                        $rows_changed++;
                    } elseif ( ! empty( $update ) ) {
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

        arsort( $by_table );
        return [
            'tables'        => $tables_seen,
            'rows_changed'  => $rows_changed,
            'cells_changed' => $cells_changed,
            'by_table'      => $by_table,
            'samples'       => $samples,
            'dry_run'       => (bool) $dry_run,
        ];
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
     * Every saved rollback point (newest first): what it was for, when, how
     * big, and whether it has been rolled back to.
     */
    public static function restore_points() {
        $points = [];
        foreach ( (array) glob( Storage::base_dir() . '/_import_*', GLOB_ONLYDIR ) as $dir ) {
            $meta_file = $dir . '/rollback/meta.json';
            if ( ! is_file( $meta_file ) ) {
                continue;
            }
            $meta = json_decode( (string) file_get_contents( $meta_file ), true );
            $meta = is_array( $meta ) ? $meta : [];
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

    private static function contains_any( $value, $pairs ) {
        foreach ( $pairs as $search => $replace ) {
            if ( strpos( $value, (string) $search ) !== false ) {
                return true;
            }
        }
        return false;
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
    public static function replacement_pairs( $from, $to ) {
        $pairs = [ $from => $to ];
        if ( strpos( $from, '/' ) === false ) {
            return $pairs;
        }
        $escapes = [ '\/', '\\\\\\/', '\u002F', '\u002f' ];
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
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $usermeta ) ) === $usermeta ) {
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
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $options ) ) === $options ) {
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

        // 1. Restore database from snapshot SQL. Validate the whole snapshot
        // before anything is dropped: a missing, truncated or unreadable
        // snapshot must leave the current database alone.
        $sql_files = Backup::export_files( $this->rollback_path );
        $check     = self::validate_snapshot( $sql_files, $meta );
        if ( is_wp_error( $check ) ) {
            return $check;
        }

        if ( ! empty( $sql_files ) ) {
            // Each table is dropped just before the snapshot recreates it
            // (DROP-before-CREATE), never all up front, so a rollback that
            // stops midway leaves every table present and can be run again.
            $run = $this->begin_statements();
            foreach ( $sql_files as $sql_file ) {
                $read = self::each_sql_statement_in_file( $sql_file, function ( $statement ) use ( &$run ) {
                    foreach ( self::transform_statements( [ $statement ] ) as $transformed ) {
                        if ( ! $this->run_statement( $transformed, $run ) ) {
                            return false;
                        }
                    }
                    return true;
                } );
                if ( is_wp_error( $read ) ) {
                    $run['errors'][] = $read->get_error_message();
                    break;
                }
            }
            // The snapshot's own token row is the right one now; don't
            // overwrite it with whatever the imported site had.
            $run['preserved_token'] = '';
            $result = $this->end_statements( $run );
            $errors = $result['errors'];

            // Tables the restore added (the source's plugin tables) go only
            // once the snapshot is fully back.
            if ( empty( $errors ) && ! empty( $meta['tables'] ) ) {
                $wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );
                foreach ( $wpdb->get_col( 'SHOW TABLES' ) as $table ) {
                    if ( ! in_array( $table, $meta['tables'], true ) ) {
                        $wpdb->query( "DROP TABLE IF EXISTS `" . str_replace( '`', '``', $table ) . "`" );
                    }
                }
                $wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' );
            }
            wp_cache_flush();
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

        @unlink( $this->import_path . '/token-guard.json' );
        @unlink( $this->import_path . '/pull.json' );

        // Remember the rollback so the restore points list can show it.
        if ( empty( $errors ) && is_file( $this->rollback_path . '/meta.json' ) ) {
            $saved = json_decode( (string) file_get_contents( $this->rollback_path . '/meta.json' ), true );
            if ( is_array( $saved ) ) {
                $saved['rolled_back_at'] = time();
                file_put_contents( $this->rollback_path . '/meta.json', json_encode( $saved, JSON_PRETTY_PRINT ) );
            }
        }

        return [
            'success'          => empty( $errors ),
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

        // The pull state holds the source's full-access token; the token guard
        // is only needed while the import is in flight.
        @unlink( $this->import_path . '/pull.json' );
        @unlink( $this->import_path . '/token-guard.json' );

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
    private static function each_sql_statement_in_file( $path, $callback ) {
        $handle = @fopen( $path, 'rb' );
        if ( ! $handle ) {
            return new \WP_Error( 'sql_unreadable', 'Could not read ' . basename( $path ) . '.', [ 'status' => 500 ] );
        }
        $scan  = self::sql_scanner();
        $count = 0;
        $emit  = function ( $statement ) use ( $callback, &$count ) {
            $count++;
            return $callback( $statement ) !== false;
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

    private static function sql_scanner() {
        return [ 'buf' => '', 'cur' => '', 'state' => 'normal', 'stopped' => false, 'ended_cleanly' => false ];
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

        $flush = function () use ( &$cur, &$scan, $emit ) {
            $statement = trim( $cur );
            $cur       = '';
            if ( $statement !== '' && ! $emit( $statement ) ) {
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
                    $run = strcspn( $s, "-#/'\"`;", $i );
                    if ( $run > 0 ) {
                        $cur .= substr( $s, $i, $run );
                        $i   += $run;
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
                    if ( $ch === ';' ) {
                        $i++;
                        $flush();
                        break;
                    }
                    $cur .= $ch; // a lone "-" or "/"
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
                if ( preg_match( '/^CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`((?:[^`]|``)+)`/i', $statement, $m ) ) {
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
        if ( empty( $creates ) ) {
            return $fail( 'the snapshot holds no tables' );
        }
        if ( $format >= 2 ) {
            foreach ( (array) ( $meta['tables'] ?? [] ) as $table ) {
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

/**
 * PclZip pre-extract guard shared by Import and Pull: skips any entry whose
 * stored name could escape the extraction root (zip-slip). PclZip callbacks
 * must be plain function names (function_exists is used to validate them), so
 * this lives outside the class. Returns 1 to extract, 0 to skip.
 */
function pclzip_pre_extract_guard( $event, &$header ) {
    return Import::is_safe_relative_path( isset( $header['stored_filename'] ) ? $header['stored_filename'] : '' ) ? 1 : 0;
}
