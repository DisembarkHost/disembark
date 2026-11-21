<?php

namespace Disembark;

/**
 * "What to bring": the things a move can usually leave behind, and the maths
 * of leaving them out.
 *
 * A suggestion matches folders or files (paths relative to the web root;
 * `*` matches within one path segment), files by name anywhere (`names`),
 * and tables whose rows can stay behind (`tables`, without the table prefix;
 * the table itself still comes across, empty, so plugins that expect it
 * keep working). Only suggestions that match something on the source are
 * offered. Extend or change the list with the `disembark_suggested_skips`
 * filter.
 */
class Skips {

    public static function catalog() {
        $list = [
            // Backups
            [ 'id' => 'updraftplus', 'group' => 'Backups', 'default' => true, 'label' => 'UpdraftPlus backups',
              'why' => 'Old backup archives. The copy has its own rollback point.', 'paths' => [ 'wp-content/updraft' ] ],
            [ 'id' => 'ai1wm', 'group' => 'Backups', 'default' => true, 'label' => 'All-in-One WP Migration backups',
              'why' => 'Old .wpress archives.', 'paths' => [ 'wp-content/ai1wm-backups' ] ],
            [ 'id' => 'duplicator', 'group' => 'Backups', 'default' => true, 'label' => 'Duplicator packages',
              'why' => 'Old migration packages.', 'paths' => [ 'wp-content/backups-dup-lite', 'wp-content/backups-dup-pro', 'wp-snapshots' ] ],
            [ 'id' => 'backwpup', 'group' => 'Backups', 'default' => true, 'label' => 'BackWPup backups',
              'why' => 'Old backup archives.', 'paths' => [ 'wp-content/uploads/backwpup-*', 'wp-content/backwpup-*' ] ],
            [ 'id' => 'wpvivid', 'group' => 'Backups', 'default' => true, 'label' => 'WPvivid backups',
              'why' => 'Old backup archives.', 'paths' => [ 'wp-content/wpvividbackups' ] ],
            [ 'id' => 'backupbuddy', 'group' => 'Backups', 'default' => true, 'label' => 'BackupBuddy backups',
              'why' => 'Old backup archives.', 'paths' => [ 'wp-content/uploads/backupbuddy_backups', 'wp-content/uploads/pb_backupbuddy' ] ],
            [ 'id' => 'backup-guard', 'group' => 'Backups', 'default' => true, 'label' => 'Backup Guard backups',
              'why' => 'Old backup archives.', 'paths' => [ 'wp-content/uploads/backup-guard' ] ],
            [ 'id' => 'wp-staging-backups', 'group' => 'Backups', 'default' => true, 'label' => 'WP Staging backups',
              'why' => 'Old backup archives.', 'paths' => [ 'wp-content/uploads/wp-staging/backups' ] ],
            [ 'id' => 'wp-migrate-db', 'group' => 'Backups', 'default' => true, 'label' => 'WP Migrate exports',
              'why' => 'Old database exports.', 'paths' => [ 'wp-content/uploads/wp-migrate-db' ] ],
            [ 'id' => 'root-archives', 'group' => 'Backups', 'default' => true, 'label' => 'Archives and database dumps in the site root',
              'why' => 'Zips, tarballs and .sql files left next to WordPress are almost always old backups.',
              'paths' => [ '*.zip', '*.tar', '*.tar.gz', '*.tgz', '*.gz', '*.sql', '*.wpress' ] ],
            [ 'id' => 'image-originals', 'group' => 'Backups', 'default' => false, 'label' => 'Image optimizer originals',
              'why' => 'Copies of images from before optimizing (ShortPixel, Imagify). Only needed to undo the optimizing.',
              'paths' => [ 'wp-content/uploads/ShortpixelBackups', 'wp-content/uploads/backup' ] ],

            // Caches
            [ 'id' => 'page-cache', 'group' => 'Caches', 'default' => true, 'label' => 'Page and asset caches',
              'why' => 'Rebuilt on the new site (WP Rocket, W3 Total Cache, WP Super Cache, Autoptimize, Breeze and others).',
              'paths' => [ 'wp-content/cache', 'wp-content/et-cache', 'wp-content/litespeed', 'wp-content/uploads/siteground-optimizer-assets' ],
              'tables' => [ 'wpr_rucss_used_css' ] ],

            // Leftovers
            [ 'id' => 'file-manager-leftovers', 'group' => 'Caches', 'default' => true, 'label' => 'File manager thumbnails and quarantine',
              'why' => 'The .tmb and .quarantine folders that File Manager plugins (elFinder) leave in the web root: thumbnails and unpacked uploads nothing on the site uses.',
              'paths' => [ '.tmb', '.quarantine', 'wp-content/.tmb', 'wp-content/.quarantine' ] ],
            [ 'id' => 'server-config-leftovers', 'group' => 'Caches', 'default' => true, 'label' => 'Old server config files in the web root',
              'why' => 'nginx.conf and the conf folder hold rules plugins wrote for the old server (W3 Total Cache, Imagify, Solid Security). The new server never reads them from the web root.',
              'paths' => [ 'nginx.conf', 'conf' ] ],

            // Logs
            [ 'id' => 'error-logs', 'group' => 'Logs', 'default' => true, 'label' => 'PHP error logs',
              'why' => 'The old server\'s error logs.', 'names' => [ 'error_log', 'debug.log', 'php_errorlog' ] ],
            [ 'id' => 'woocommerce-logs', 'group' => 'Logs', 'default' => true, 'label' => 'WooCommerce and Action Scheduler logs',
              'why' => 'Log lines from past jobs. The jobs themselves come across.',
              'paths' => [ 'wp-content/uploads/wc-logs' ], 'tables' => [ 'woocommerce_log', 'actionscheduler_logs' ] ],
            [ 'id' => 'email-logs', 'group' => 'Logs', 'default' => true, 'label' => 'Email logs',
              'why' => 'Copies of sent emails, including customers\' addresses and password reset links.',
              'tables' => [ 'wpml_mails', 'email_log', 'wpmailsmtp_emails_log', 'wpmailsmtp_debug_events', 'fsmpt_email_logs', 'post_smtp_logs', 'post_smtp_logmeta', 'cr_reminders_log', 'cr_wpmail_log', 'gravitysmtp_events' ] ],
            [ 'id' => 'redirect-logs', 'group' => 'Logs', 'default' => true, 'label' => 'Redirect and 404 logs',
              'why' => 'Hits logged by Redirection and Rank Math. Redirect rules themselves come across.',
              'tables' => [ 'redirection_logs', 'redirection_404', 'rank_math_404_logs' ] ],
            [ 'id' => 'activity-logs', 'group' => 'Logs', 'default' => false, 'label' => 'Activity and audit logs',
              'why' => 'Who changed what on the old site (WP Activity Log, Simple History, Activity Log, Stream). Keep them for a real move.',
              'tables' => [ 'wsal_occurrences', 'wsal_metadata', 'simple_history', 'simple_history_contexts', 'aryo_activity_log', 'stream', 'stream_meta' ] ],
            [ 'id' => 'statistics', 'group' => 'Logs', 'default' => false, 'label' => 'Visitor statistics',
              'why' => 'Visit history from WP Statistics.',
              'tables' => [ 'statistics_visit', 'statistics_visitor', 'statistics_visitor_relationships', 'statistics_pages', 'statistics_exclusions' ] ],
            [ 'id' => 'woocommerce-sessions', 'group' => 'Logs', 'default' => false, 'label' => 'WooCommerce shopping sessions',
              'why' => 'Carts in progress. Leave them out of a staging copy; keep them for a real move.',
              'tables' => [ 'woocommerce_sessions' ] ],

            // Security
            [ 'id' => 'wordfence-firewall', 'group' => 'Security', 'default' => true, 'label' => 'Wordfence firewall files',
              'why' => 'wordfence-waf.php and wp-content/wflogs hold the old server\'s paths, and a firewall loader that points at a missing file breaks the site. Wordfence rebuilds them; its firewall runs in basic mode until you optimize it again.',
              'paths' => [ 'wordfence-waf.php', 'wp-content/wflogs' ] ],
            [ 'id' => 'wordfence-data', 'group' => 'Security', 'default' => true, 'label' => 'Wordfence traffic and scan data',
              'why' => 'Live traffic, login attempts, blocks and scan results from the old server. Wordfence\'s settings come across.',
              'tables' => [ 'wfhits', 'wflogins', 'wfstatus', 'wfblockediplog', 'wffilemods', 'wfknownfilelist', 'wflivetraffichuman', 'wftrafficrates', 'wfsnipcache', 'wfcrawlers', 'wfnotifications', 'wfissues', 'wfpendingissues' ] ],
            [ 'id' => 'security-logs', 'group' => 'Security', 'default' => true, 'label' => 'Other security plugin logs',
              'why' => 'Logs from Solid Security (iThemes) and All In One WP Security.',
              'tables' => [ 'itsec_logs', 'itsec_temp', 'aiowps_audit_log', 'aiowps_events' ] ],
        ];
        $list = apply_filters( 'disembark_suggested_skips', $list );
        $out  = [];
        foreach ( (array) $list as $s ) {
            if ( empty( $s['id'] ) ) {
                continue;
            }
            $out[] = [
                'id'      => (string) $s['id'],
                'group'   => (string) ( $s['group'] ?? 'Other' ),
                'label'   => (string) ( $s['label'] ?? $s['id'] ),
                'why'     => (string) ( $s['why'] ?? '' ),
                'default' => ! empty( $s['default'] ),
                'paths'   => array_values( (array) ( $s['paths'] ?? [] ) ),
                'names'   => array_values( (array) ( $s['names'] ?? [] ) ),
                'tables'  => array_values( (array) ( $s['tables'] ?? [] ) ),
            ];
        }
        return $out;
    }

    /**
     * Everything the chooser needs: totals, every folder with its size, the
     * tables, and the suggestions that match this source.
     *
     * $files: [ ['name' => 'wp-content/x.php', 'size' => 123], ... ]
     * $tables: [ ['name' => 'wp_posts', 'size' => 123, 'rows' => 4], ... ]
     */
    /** WordPress's own files in the web root (wp-config.php never travels with one site of an install). */
    const CORE_ROOT = [ 'index.php', 'license.txt', 'readme.html', 'wp-activate.php', 'wp-blog-header.php', 'wp-comments-post.php',
        'wp-config-sample.php', 'wp-config.php', 'wp-cron.php', 'wp-links-opml.php', 'wp-load.php', 'wp-login.php', 'wp-mail.php',
        'wp-settings.php', 'wp-signup.php', 'wp-trackback.php', 'xmlrpc.php' ];

    /**
     * The install's own things in the web root, when the source is one site
     * of a network or WP Freighter install (not its main site): everything
     * at the top level that isn't WordPress core or the site's content
     * (scripts, other apps, notes, .htaccess). Top-level names.
     */
    public static function install_root( $paths ) {
        $core = array_flip( self::CORE_ROOT );
        $out  = [];
        foreach ( array_keys( $paths ) as $p ) {
            $top = (string) strtok( (string) $p, '/' );
            if ( $top === '' || in_array( $top, [ 'wp-admin', 'wp-includes', 'wp-content' ], true ) || ( strpos( (string) $p, '/' ) === false && isset( $core[ $top ] ) ) ) {
                continue;
            }
            $out[ $top ] = true;
        }
        $out = array_keys( $out );
        sort( $out );
        return $out;
    }

    /**
     * $opts['install_root']: the source is one site of an install, so its
     * web root holds the install's things, not the site's: offered as a
     * suggestion that leaves them behind by default.
     */
    public static function analyze( $files, $tables, $prefix, $opts = [] ) {
        $folders = [];  // path => [bytes, files]
        $paths   = [];  // file path => size
        $bytes   = 0;
        foreach ( $files as $f ) {
            $name = (string) ( $f['name'] ?? '' );
            $size = (int) ( $f['size'] ?? 0 );
            if ( $name === '' ) {
                continue;
            }
            $paths[ $name ] = $size;
            $bytes         += $size;
            $dir = dirname( $name );
            while ( $dir !== '.' && $dir !== '' && $dir !== '/' ) {
                if ( ! isset( $folders[ $dir ] ) ) {
                    $folders[ $dir ] = [ 0, 0 ];
                }
                $folders[ $dir ][0] += $size;
                $folders[ $dir ][1]++;
                $dir = dirname( $dir );
            }
        }

        $by_table = [];
        foreach ( $tables as $t ) {
            $by_table[ strtolower( (string) $t['name'] ) ] = $t;
        }

        $suggestions = [];
        foreach ( self::catalog() as $s ) {
            $hit = [ 'paths' => [], 'names' => [], 'tables' => [], 'files' => 0, 'bytes' => 0, 'name_files' => 0, 'name_bytes' => 0, 'table_bytes' => 0, 'rows' => 0 ];
            foreach ( $s['paths'] as $pattern ) {
                foreach ( self::match_path( $pattern, $folders, $paths ) as $p ) {
                    $hit['paths'][] = $p;
                }
            }
            $hit['paths'] = self::topmost( array_values( array_unique( $hit['paths'] ) ) );
            foreach ( $hit['paths'] as $p ) {
                if ( isset( $folders[ $p ] ) ) {
                    $hit['bytes'] += $folders[ $p ][0];
                    $hit['files'] += $folders[ $p ][1];
                } elseif ( isset( $paths[ $p ] ) ) {
                    $hit['bytes'] += $paths[ $p ];
                    $hit['files']++;
                }
            }
            if ( $s['names'] ) {
                $names = array_flip( $s['names'] );
                $found = false;
                foreach ( $paths as $p => $size ) {
                    if ( isset( $names[ basename( $p ) ] ) && ! self::inside( $p, $hit['paths'] ) ) {
                        $hit['bytes']      += $size;
                        $hit['files']++;
                        $hit['name_bytes'] += $size;
                        $hit['name_files']++;
                        $found = true;
                    }
                }
                if ( $found ) {
                    $hit['names'] = $s['names'];
                }
            }
            foreach ( $s['tables'] as $suffix ) {
                foreach ( self::match_table( $suffix, $prefix, $by_table ) as $t ) {
                    $hit['tables'][]     = $t['name'];
                    $hit['table_bytes'] += (int) ( $t['size'] ?? 0 );
                    $hit['rows']        += (int) ( $t['rows'] ?? 0 );
                }
            }
            if ( ! $hit['paths'] && ! $hit['names'] && ! $hit['tables'] ) {
                continue;
            }
            $suggestions[] = array_merge( [ 'id' => $s['id'], 'group' => $s['group'], 'label' => $s['label'], 'why' => $s['why'], 'default' => $s['default'] ], $hit );
        }

        if ( ! empty( $opts['install_root'] ) && ( $extra = self::install_root( $paths ) ) ) {
            $hit = [ 'paths' => $extra, 'names' => [], 'tables' => [], 'files' => 0, 'bytes' => 0, 'name_files' => 0, 'name_bytes' => 0, 'table_bytes' => 0, 'rows' => 0 ];
            foreach ( $extra as $p ) {
                if ( isset( $folders[ $p ] ) ) {
                    $hit['bytes'] += $folders[ $p ][0];
                    $hit['files'] += $folders[ $p ][1];
                } elseif ( isset( $paths[ $p ] ) ) {
                    $hit['bytes'] += $paths[ $p ];
                    $hit['files']++;
                }
            }
            array_unshift( $suggestions, array_merge( [ 'id' => 'install-root', 'group' => 'The install', 'label' => 'The install\'s own files in the web root',
                'why' => 'This site is one of several sharing that install, and these sit next to WordPress for all of them (scripts, other apps, notes, server settings), not for this site. Tick any you want in the file list.',
                'default' => true ], $hit ) );
        }

        $folder_rows = [];
        foreach ( $folders as $p => $v ) {
            $folder_rows[] = [ $p, $v[0], $v[1] ];
        }
        // Loose files at the top level, so the tree can show and skip them.
        $root_files = [];
        foreach ( $paths as $p => $size ) {
            if ( strpos( $p, '/' ) === false ) {
                $root_files[] = [ $p, $size ];
            }
        }

        return [
            'files'       => count( $paths ),
            'bytes'       => $bytes,
            'folders'     => $folder_rows,
            'root_files'  => $root_files,
            'tables'      => array_values( array_map( function ( $t ) {
                return [ 'name' => (string) $t['name'], 'size' => (int) ( $t['size'] ?? 0 ), 'rows' => (int) ( $t['rows'] ?? 0 ) ];
            }, $tables ) ),
            'prefix'      => (string) $prefix,
            'suggestions' => $suggestions,
        ];
    }

    /** Folders and files a pattern matches (`*` stays within one segment). */
    private static function match_path( $pattern, $folders, $paths ) {
        $pattern = trim( (string) $pattern, '/' );
        if ( $pattern === '' ) {
            return [];
        }
        if ( strpos( $pattern, '*' ) === false ) {
            return ( isset( $folders[ $pattern ] ) || isset( $paths[ $pattern ] ) ) ? [ $pattern ] : [];
        }
        $depth = substr_count( $pattern, '/' );
        $out   = [];
        foreach ( [ $folders, $paths ] as $set ) {
            foreach ( $set as $p => $unused ) {
                if ( substr_count( $p, '/' ) === $depth && fnmatch( $pattern, $p, FNM_PATHNAME ) ) {
                    $out[] = (string) $p;
                }
            }
        }
        return $out;
    }

    /** Tables named <prefix><suffix>, or <prefix><site>_<suffix> on multisite. */
    private static function match_table( $suffix, $prefix, $by_table ) {
        $suffix = strtolower( (string) $suffix );
        $prefix = strtolower( (string) $prefix );
        $out    = [];
        foreach ( $by_table as $lower => $t ) {
            if ( $lower === $prefix . $suffix || preg_match( '/^' . preg_quote( $prefix, '/' ) . '\d+_' . preg_quote( $suffix, '/' ) . '$/', $lower ) ) {
                $out[] = $t;
            }
        }
        return $out;
    }

    /** Drops paths that sit inside another path in the list. */
    public static function topmost( $paths ) {
        sort( $paths );
        $out = [];
        foreach ( $paths as $p ) {
            if ( ! self::inside( $p, $out ) ) {
                $out[] = $p;
            }
        }
        return $out;
    }

    public static function inside( $path, $parents ) {
        foreach ( $parents as $parent ) {
            if ( $path === $parent || strpos( $path, $parent . '/' ) === 0 ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Files left after skipping $skip_paths (folders or files) and files
     * named one of $skip_names anywhere. Returns [kept, left_out_files, left_out_bytes].
     */
    public static function filter_files( $files, $skip_paths, $skip_names ) {
        $skip  = array_flip( array_filter( array_map( function ( $p ) { return trim( (string) $p, '/' ); }, (array) $skip_paths ) ) );
        $names = array_flip( array_filter( array_map( 'strval', (array) $skip_names ) ) );
        $kept  = [];
        $count = 0;
        $bytes = 0;
        foreach ( $files as $f ) {
            $name = (string) ( $f['name'] ?? '' );
            $out  = isset( $names[ basename( $name ) ] );
            for ( $p = $name; ! $out && $p !== '.' && $p !== '' && $p !== '/'; $p = dirname( $p ) ) {
                $out = isset( $skip[ $p ] );
            }
            if ( $out ) {
                $count++;
                $bytes += (int) ( $f['size'] ?? 0 );
            } else {
                $kept[] = $f;
            }
        }
        return [ $kept, $count, $bytes ];
    }

    /**
     * A choice set from suggestion ids plus extra paths and tables: the
     * default when $ids is null. Used by the CLI and by templates.
     */
    public static function resolve( $analysis, $ids = null, $extra_paths = [], $extra_rows = [] ) {
        $paths = [];
        $names = [];
        $rows  = [];
        foreach ( $analysis['suggestions'] as $s ) {
            $on = $ids === null ? $s['default'] : in_array( $s['id'], (array) $ids, true );
            if ( $on ) {
                $paths = array_merge( $paths, $s['paths'] );
                $names = array_merge( $names, $s['names'] );
                $rows  = array_merge( $rows, $s['tables'] );
            }
        }
        $prefix   = strtolower( (string) $analysis['prefix'] );
        $by_table = [];
        foreach ( $analysis['tables'] as $t ) {
            $by_table[ strtolower( $t['name'] ) ] = $t;
        }
        foreach ( (array) $extra_rows as $suffix ) {
            foreach ( self::match_table( $suffix, $prefix, $by_table ) as $t ) {
                $rows[] = $t['name'];
            }
        }
        foreach ( (array) $extra_paths as $p ) {
            $paths[] = trim( (string) $p, '/' );
        }
        return [
            'skip_paths' => array_values( array_unique( array_filter( $paths ) ) ),
            'skip_names' => array_values( array_unique( $names ) ),
            'skip_rows'  => array_values( array_unique( $rows ) ),
        ];
    }
}
