<?php

namespace Disembark;

/**
 * One site out of an install that holds several: a subsite of a multisite
 * network, or a WP Freighter site (the main site or a tenant). The source
 * exports that site alone, shaped like a standalone install, so the site
 * receiving it runs an ordinary single-site import.
 *
 * Tables keep the site's own prefix (wp_5_, stacked_8_); the import renames
 * them to the destination's like any other move. A network subsite's users
 * come from the network tables: only its members, the authors of its posts
 * and the network's super admins (as administrators) come across, under the
 * site's prefix. Network-wide tables with no copy for the site come across
 * empty unless shared rows are asked for. Files are mapped too: the site's
 * uploads folder (uploads/sites/5, content/8/uploads) becomes
 * wp-content/uploads, and plugins and themes in a folder the sites share
 * come across only when this site uses them.
 *
 * A request names the site with `site`: a blog ID, a Freighter tenant ID or
 * "main", or the site's address. Without it every endpoint exports the whole
 * install, as before.
 */
class Scope {

    public $kind;      // multisite | freighter
    public $id;        // "5", or "main" / "8" on Freighter
    public $prefix;    // this site's table prefix
    public $home  = '';
    public $siteurl = '';
    public $name  = '';
    public $root;      // the install's folder; file names are relative to it

    private $base;               // the network's base prefix (multisite)
    private $main_home = '';     // the install's main site
    private $tables    = null;   // output name => real name
    private $shared    = [];     // output names of network-wide tables
    private $moves     = [];     // real folder => output folder
    private $drop      = [];     // real folders left out
    private $drop_files = [];    // real files left out
    private $pools     = [];     // shared folder => [ child => true ] that this site uses
    private $active    = [];     // active plugin files
    private $theme     = [];     // [ stylesheet, template ]
    private $plugin_dir = '';    // real folder of the plugins this site runs
    private $theme_dir  = '';
    private $left_out  = [ 'plugins' => [], 'themes' => [] ];
    private $super     = null;   // super admin user IDs (multisite)
    private $blog_ids  = [];
    private $own_keys  = null;   // usermeta suffixes this site has (multisite)
    private $freighter = [];     // files mode etc.
    private $network   = 1;      // the subsite's network (WP Multi Network)
    private $aliases   = [];     // mapped domains that also served the site

    private static $active_scope = null;

    /** The scope this request exports, or null for the whole install. */
    public static function active() {
        return self::$active_scope;
    }

    public static function activate( $scope ) {
        self::$active_scope = $scope instanceof self ? $scope : null;
    }

    /**
     * Reads `site` from a request and activates the scope it names. Returns
     * the scope, null (the whole install) or WP_Error.
     */
    public static function from_request( $request ) {
        $value = '';
        if ( is_object( $request ) && method_exists( $request, 'get_param' ) ) {
            $value = (string) $request->get_param( 'site' );
        } elseif ( is_array( $request ) ) {
            $value = (string) ( $request['site'] ?? '' );
        }
        // A request that reached one site of an install by its own address
        // (a subsite's /alpha/wp-json/, a WP Freighter tenant's cookie or
        // STACKED_SITE_ID) and named no site exports that site, not the
        // whole install (the Disembark CLI pointed at a subsite's address).
        if ( trim( $value ) === '' ) {
            $install = self::ids();
            if ( $install && (string) $install['current'] !== (string) $install['main'] ) {
                $value = 'current';
            }
        }
        $scope = self::resolve( $value );
        // super_admins=0: the pulling site chose to leave out the network's
        // super admins who have no role on this site (see super_admins_added()).
        $super = is_object( $request ) && method_exists( $request, 'get_param' ) ? $request->get_param( 'super_admins' ) : ( is_array( $request ) ? ( $request['super_admins'] ?? null ) : null );
        if ( $scope instanceof self && $super !== null && in_array( strtolower( (string) $super ), [ '0', 'false', 'no', 'off' ], true ) ) {
            $scope->bring_super = false;
        }
        self::activate( is_wp_error( $scope ) ? null : $scope );
        return $scope;
    }

    /** Whether the network's super admins without a role on this site come across, as its administrators. */
    public $bring_super = true;

    /* ------------------------------------------------------------------
     * The install
     * ---------------------------------------------------------------- */

    /** Freighter's main table prefix, or '' when this isn't a Freighter install. */
    public static function freighter_primary() {
        global $wpdb;
        static $primary = null;
        if ( $primary !== null ) {
            return $primary;
        }
        $primary = '';
        $candidate = defined( 'TABLE_PREFIX' ) && is_string( TABLE_PREFIX ) && TABLE_PREFIX !== 'TABLE_PREFIX' ? TABLE_PREFIX : $wpdb->prefix;
        if ( is_multisite() || ! is_file( ABSPATH . 'wp-content/freighter.php' ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $candidate ) ) {
            return $primary;
        }
        // stacked_sites appears with the first tenant; an install converted
        // a moment ago, with none yet, has only its settings.
        $found = $wpdb->get_var( "SELECT COUNT(*) FROM `{$candidate}options` WHERE option_name IN ( 'stacked_sites', 'stacked_configurations' )" );
        if ( (int) $found < 1 ) {
            return $primary;
        }
        // Deleting WP Freighter leaves its bootstrap, the wp-config line and
        // its settings behind. With the plugin gone and no tenants listed,
        // this is a plain site again.
        $plugin_dir = ( defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : ABSPATH . 'wp-content/plugins' ) . '/wp-freighter';
        if ( ! is_dir( $plugin_dir ) ) {
            $tenants = array_filter( (array) self::freighter_option( $candidate, 'stacked_sites' ), function ( $s ) { return is_array( $s ) && (int) ( $s['stacked_site_id'] ?? 0 ) > 0; } );
            if ( ! $tenants ) {
                return $primary;
            }
        }
        $primary = $candidate;
        return $primary;
    }

    /**
     * Where site $id of a network with ms-files rewriting keeps its uploads
     * (see setup_multisite()): its own upload_path setting, else
     * uploads/sites/<id>, else blogs.dir/<id>/files, the first that exists.
     * A site with none has no uploads: its folder is named all the same, so
     * nothing of the main site's is taken for it. Returns wp_upload_dir()'s
     * basedir and baseurl, plus files_url, the site's old ms-files address.
     */
    private function legacy_uploads( $id, $upload_path, $fallback ) {
        $content    = wp_normalize_path( WP_CONTENT_DIR );
        $abs        = rtrim( wp_normalize_path( ABSPATH ), '/' );
        $main_url   = untrailingslashit( (string) get_site_url( get_main_site_id( $this->network ) ) );
        $candidates = [];
        if ( $upload_path !== '' ) {
            $dir = wp_normalize_path( path_is_absolute( $upload_path ) ? $upload_path : $abs . '/' . $upload_path );
            $candidates[] = [ $dir, $main_url . '/' . ltrim( substr( $dir, strlen( $abs ) ), '/' ) ];
        }
        $candidates[] = [ $content . '/uploads/sites/' . $id, untrailingslashit( content_url( 'uploads/sites/' . $id ) ) ];
        $candidates[] = [ $content . '/blogs.dir/' . $id . '/files', untrailingslashit( content_url( 'blogs.dir/' . $id . '/files' ) ) ];
        $pick = null;
        foreach ( $candidates as $c ) {
            if ( is_dir( $c[0] ) ) {
                $pick = $c;
                break;
            }
        }
        if ( $pick === null ) {
            $pick = $candidates[ $upload_path !== '' ? 1 : 0 ];
        }
        return [ 'basedir' => rtrim( $pick[0], '/' ), 'baseurl' => $pick[1], 'files_url' => $this->home !== '' ? $this->home . '/files' : '' ];
    }

    /** A site name as plain text: WordPress keeps blogname HTML-escaped ("Crane &amp; Turtle"). */
    public static function plain( $name ) {
        return html_entity_decode( (string) $name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    }

    public static function freighter_option( $prefix, $name ) {
        global $wpdb;
        $value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM `{$prefix}options` WHERE option_name = %s", $name ) );
        return $value === null ? null : maybe_unserialize( $value );
    }

    /**
     * What kind of shared install this is, or null for a plain site:
     * kind, current (the site this request reached), main, home and the
     * sites (up to 500, newest last).
     */
    public static function install() {
        global $wpdb;
        // Once per request per site: every batch of a backup or pull asks,
        // and on a network this lists up to 500 sites with their names.
        $key = ( is_multisite() ? 'b' . get_current_blog_id() : 'p' . $wpdb->prefix ) . '|' . self::$generation;
        if ( ! array_key_exists( $key, self::$install_cache ) ) {
            self::$install_cache = [ $key => self::install_uncached() ];
        }
        return self::$install_cache[ $key ];
    }

    /** @var array install()'s answer for this request. */
    private static $install_cache = [];

    /** @var int Bumped when a site is added or removed (forget()). */
    private static $generation = 0;

    /** Drops install()'s cached answer: a site was just added or removed. */
    public static function forget() {
        self::$generation++;
        self::$install_cache = [];
    }

    /**
     * The cheap part of install(): kind, this site's ID and the main site's,
     * without listing the sites. null when it isn't a shared install.
     */
    public static function ids() {
        global $wpdb;
        if ( is_multisite() ) {
            return [ 'kind' => 'multisite', 'current' => (string) get_current_blog_id(), 'main' => (string) get_main_site_id() ];
        }
        $primary = self::freighter_primary();
        if ( $primary === '' ) {
            return null;
        }
        return [ 'kind' => 'freighter', 'current' => preg_match( '/^stacked_(\d+)_$/', $wpdb->prefix, $m ) && $wpdb->prefix !== $primary ? $m[1] : 'main', 'main' => 'main' ];
    }

    private static function install_uncached() {
        global $wpdb;
        if ( is_multisite() ) {
            $sites = [];
            foreach ( get_sites( [ 'number' => 500, 'orderby' => 'id' ] ) as $site ) {
                $sites[] = [ 'id' => (string) $site->blog_id, 'url' => untrailingslashit( get_home_url( (int) $site->blog_id ) ), 'name' => self::plain( get_blog_option( (int) $site->blog_id, 'blogname' ) ) ];
            }
            return [
                'kind'    => 'multisite',
                'current' => (string) get_current_blog_id(),
                'main'    => (string) get_main_site_id(),
                'home'    => untrailingslashit( get_home_url( get_main_site_id() ) ),
                'total'   => (int) get_sites( [ 'count' => true ] ),
                'sites'   => $sites,
            ];
        }
        $primary = self::freighter_primary();
        if ( $primary === '' ) {
            return null;
        }
        $config  = (array) self::freighter_option( $primary, 'stacked_configurations' );
        $mapping = ( $config['domain_mapping'] ?? 'off' ) === 'on';
        $main    = untrailingslashit( (string) self::freighter_option( $primary, 'home' ) );
        $sites   = [ [ 'id' => 'main', 'url' => $main, 'name' => self::plain( self::freighter_option( $primary, 'blogname' ) ) ] ];
        foreach ( (array) self::freighter_option( $primary, 'stacked_sites' ) as $site ) {
            $id = (int) ( $site['stacked_site_id'] ?? 0 );
            if ( $id < 1 ) {
                continue;
            }
            $domain  = trim( (string) ( $site['domain'] ?? '' ) );
            $sites[] = [ 'id' => (string) $id, 'url' => $mapping && $domain !== '' ? 'https://' . $domain : $main, 'name' => (string) ( $site['name'] ?? '' ) ];
        }
        $current = preg_match( '/^stacked_(\d+)_$/', $wpdb->prefix, $m ) && $wpdb->prefix !== $primary ? $m[1] : 'main';
        return [
            'kind'    => 'freighter',
            'current' => $current,
            'main'    => 'main',
            'home'    => $main,
            'files'   => (string) ( $config['files'] ?? 'shared' ),
            'total'   => count( $sites ),
            'sites'   => $sites,
        ];
    }

    /**
     * The scope `site` names: '' or "all" → null (the whole install),
     * "current" → the site this request reached, an ID, or an address.
     */
    public static function resolve( $value ) {
        $value = trim( (string) $value );
        if ( $value === '' || strtolower( $value ) === 'all' ) {
            return null;
        }
        $ids = self::ids();
        if ( ! $ids ) {
            return new \WP_Error( 'not_shared', 'This site isn\'t a multisite network or a WP Freighter install, so there is no single site to choose.', [ 'status' => 400 ] );
        }
        if ( strtolower( $value ) === 'current' ) {
            $value = $ids['current'];
        } elseif ( strtolower( $value ) === 'main' ) {
            $value = $ids['main'];
        }
        // A network's site by its ID: no need to list them all.
        if ( $ids['kind'] === 'multisite' && ctype_digit( $value ) ) {
            if ( get_site( (int) $value ) ) {
                return new self( 'multisite', (string) (int) $value );
            }
            return new \WP_Error( 'unknown_site', 'No site ' . $value . ' in this network.', [ 'status' => 404 ] );
        }
        $install = self::install();
        $found = null;
        foreach ( $install['sites'] as $site ) {
            if ( strtolower( $value ) === strtolower( $site['id'] ) ) {
                $found = $site['id'];
                break;
            }
        }
        if ( $found === null && $install['kind'] === 'multisite' && ctype_digit( $value ) && get_site( (int) $value ) ) {
            $found = $value;
        }
        if ( $found === null && ! ctype_digit( $value ) ) {
            $matches = [];
            foreach ( $install['sites'] as $site ) {
                if ( Quiet::address( $site['url'] ) === Quiet::address( $value ) ) {
                    $matches[] = $site['id'];
                }
            }
            if ( count( $matches ) > 1 ) {
                return new \WP_Error( 'ambiguous_site', 'Several sites answer at ' . $value . '. Name the site by its ID instead: ' . implode( ', ', $matches ) . '.', [ 'status' => 400 ] );
            }
            $found = $matches[0] ?? null;
        }
        if ( $found === null ) {
            return new \WP_Error( 'unknown_site', 'No site ' . $value . ' in this ' . ( $install['kind'] === 'multisite' ? 'network' : 'WP Freighter install' ) . '.', [ 'status' => 404 ] );
        }
        return new self( $install['kind'], $found );
    }

    private function __construct( $kind, $id ) {
        $this->kind = $kind;
        $this->id   = (string) $id;
        if ( $kind === 'multisite' ) {
            $this->setup_multisite( (int) $id );
        } else {
            $this->setup_freighter();
        }
        // The install's own wp-config.php never leaves with one of its sites.
        $this->drop_files[] = 'wp-config.php';
        $this->pools_from_usage();
    }

    /** $path relative to the install's folder, or null when it's outside. */
    private function rel( $path ) {
        $path = rtrim( wp_normalize_path( (string) $path ), '/' );
        if ( $path === $this->root ) {
            return '';
        }
        return strpos( $path, $this->root . '/' ) === 0 ? substr( $path, strlen( $this->root ) + 1 ) : null;
    }

    private function setup_multisite( $id ) {
        global $wpdb;
        $this->root   = rtrim( wp_normalize_path( dirname( WP_CONTENT_DIR ) ), '/' );
        $this->base   = $wpdb->base_prefix;
        $this->prefix = $wpdb->get_blog_prefix( $id );
        $site          = get_site( $id );
        $this->network = $site ? (int) $site->network_id : 1;
        $main          = get_main_site_id( $this->network );
        $this->blog_ids = array_flip( array_map( 'intval', get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) ) );
        // Sites removed from the network often leave their tables behind
        // (wp_115_posts, or only a plugin's wp_115_podpress_stats, with no
        // row in wp_blogs). They are another site's too, never network-wide
        // tables. A numbered prefix counts as a removed site when it has its
        // own options table, or when it's below the next site ID and at
        // least two tables share it, so a plugin's lone table that merely
        // starts with a number (wp_404_to_301) doesn't.
        // (SQLite's driver has no AUTO_INCREMENT there: the highest ID does.)
        $quiet  = $wpdb->suppress_errors( true );
        $next   = Import::on_sqlite() ? 0 : (int) $wpdb->get_var( $wpdb->prepare( 'SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $wpdb->blogs ) );
        $wpdb->suppress_errors( $quiet );
        $next   = max( $next, (int) $wpdb->get_var( "SELECT MAX(blog_id) FROM {$wpdb->blogs}" ) + 1 );
        $groups = [];
        foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $this->base ) . '%' ) ) as $t ) {
            if ( preg_match( '/^' . preg_quote( $this->base, '/' ) . '(\d+)_(.+)$/', (string) $t, $m ) && ! isset( $this->blog_ids[ (int) $m[1] ] ) ) {
                $groups[ (int) $m[1] ][] = $m[2];
            }
        }
        foreach ( $groups as $n => $rests ) {
            if ( in_array( 'options', $rests, true ) || ( $n < $next && count( $rests ) >= 2 ) ) {
                $this->blog_ids[ $n ] = -1; // a removed site's leftovers
            }
        }
        $this->aliases  = $this->mapped_domains( $id );

        switch_to_blog( $id );
        $this->home     = untrailingslashit( (string) get_option( 'home' ) );
        $this->siteurl  = untrailingslashit( (string) get_option( 'siteurl' ) );
        $this->name     = self::plain( get_option( 'blogname' ) );
        $this->active   = (array) get_option( 'active_plugins', [] );
        $this->theme    = [ (string) get_option( 'stylesheet' ), (string) get_option( 'template' ) ];
        $own            = wp_upload_dir( null, false );
        $own_path       = trim( (string) get_option( 'upload_path' ) );
        restore_current_blog();
        // A network that still has ms-files rewriting on (the old blogs.dir
        // layout) can't say where a site it switched to keeps its uploads:
        // wp_upload_dir() answers the main site's folder for every site. The
        // site's own folder is found on disk instead.
        if ( (int) $id !== (int) $main && get_site_option( 'ms_files_rewriting' ) ) {
            $own = $this->legacy_uploads( (int) $id, $own_path, $own );
        }
        switch_to_blog( $main );
        $this->main_home = untrailingslashit( (string) get_option( 'home' ) );
        $main_siteurl    = untrailingslashit( (string) get_option( 'siteurl' ) );
        restore_current_blog();
        $this->active = array_values( array_unique( array_merge( $this->active, $this->network_plugins() ) ) );

        // A standalone site keeps its uploads in wp-content/uploads.
        $content  = $this->rel( WP_CONTENT_DIR );
        $target   = ltrim( (string) $content . '/uploads', '/' );
        $own_dir  = $this->rel( $own['basedir'] );
        $this->bases = array_values( array_unique( array_merge( [ $this->home, $this->siteurl, $main_siteurl, $this->main_home ], $this->aliases ) ) );
        if ( $own_dir !== null && $own_dir !== $target ) {
            $this->moves[ $own_dir ] = $target;
            $this->drop[]            = $target;
            $this->url_moves         = [ [ $own_dir, $target ] ];
            if ( ! empty( $own['baseurl'] ) ) {
                $this->url_pairs[ untrailingslashit( $own['baseurl'] ) ] = $target;
            }
            // ms-files addresses (site/files/2016/10/x.png) served from it.
            if ( ! empty( $own['files_url'] ) ) {
                $this->url_pairs[ untrailingslashit( $own['files_url'] ) ] = $target;
            }
        } else {
            $this->drop[] = $target . '/sites';
        }
        if ( $content !== null ) {
            $this->drop[]       = ltrim( $content . '/blogs.dir', '/' );
            $this->drop_files[] = ltrim( $content . '/sunrise.php', '/' );
        }
        $this->plugin_dir = (string) $this->rel( WP_PLUGIN_DIR );
        $this->theme_dir  = (string) $this->rel( get_theme_root() );
        $this->pools[ $this->plugin_dir ] = [];
        $this->pools[ $this->theme_dir ]  = [];
    }

    private function setup_freighter() {
        $this->root  = rtrim( wp_normalize_path( ABSPATH ), '/' );
        $primary     = self::freighter_primary();
        $config      = (array) self::freighter_option( $primary, 'stacked_configurations' );
        $files       = in_array( $config['files'] ?? '', [ 'shared', 'hybrid', 'dedicated' ], true ) ? $config['files'] : 'shared';
        $mapping     = ( $config['domain_mapping'] ?? 'off' ) === 'on';
        $this->freighter = [ 'files' => $files, 'domain_mapping' => $mapping ];
        $this->main_home = untrailingslashit( (string) self::freighter_option( $primary, 'home' ) );
        $this->prefix    = $this->id === 'main' ? $primary : 'stacked_' . (int) $this->id . '_';

        $this->home    = $this->main_home;
        $this->siteurl = untrailingslashit( (string) self::freighter_option( $primary, 'siteurl' ) );
        if ( $this->id !== 'main' ) {
            foreach ( (array) self::freighter_option( $primary, 'stacked_sites' ) as $site ) {
                if ( (string) ( $site['stacked_site_id'] ?? '' ) === $this->id && $mapping && trim( (string) ( $site['domain'] ?? '' ) ) !== '' ) {
                    $this->home    = 'https://' . trim( (string) $site['domain'] );
                    $this->siteurl = $this->home;
                }
            }
        }
        $this->name   = self::plain( self::freighter_option( $this->prefix, 'blogname' ) );
        $this->active = (array) self::freighter_option( $this->prefix, 'active_plugins' );
        $this->theme  = [ (string) self::freighter_option( $this->prefix, 'stylesheet' ), (string) self::freighter_option( $this->prefix, 'template' ) ];

        $this->drop_files[] = 'wp-content/freighter.php';
        $this->bases        = array_values( array_unique( [ $this->home, $this->main_home ] ) );
        $shared_code        = true;
        if ( $this->id === 'main' ) {
            $this->drop[] = 'content';
            $shared_code  = $files !== 'dedicated';
        } elseif ( $files === 'dedicated' ) {
            $own                = 'content/' . (int) $this->id;
            $this->moves[ $own ] = 'wp-content';
            $this->drop         = [ 'wp-content', 'content' ];
            $this->url_moves    = [ [ $own, 'wp-content' ] ];
            $shared_code        = false;
        } elseif ( $files === 'hybrid' ) {
            $own                = 'content/' . (int) $this->id . '/uploads';
            $this->moves[ $own ] = 'wp-content/uploads';
            $this->drop         = [ 'wp-content/uploads', 'content' ];
            $this->url_moves    = [ [ $own, 'wp-content/uploads' ] ];
        } else {
            $this->drop[] = 'content';
        }
        $this->plugin_dir = $shared_code || $this->id === 'main' ? 'wp-content/plugins' : 'content/' . (int) $this->id . '/plugins';
        $this->theme_dir  = $shared_code || $this->id === 'main' ? 'wp-content/themes' : 'content/' . (int) $this->id . '/themes';
        if ( $shared_code ) {
            $this->pools['wp-content/plugins'] = [];
            $this->pools['wp-content/themes']  = [];
        }
    }

    /** Plugins switched on for the subsite's whole network. */
    private function network_plugins() {
        $active = array_keys( (array) get_network_option( $this->network, 'active_sitewide_plugins', [] ) );
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        // A plugin that says it only works network-activated (Network: true,
        // such as a network directory or a network admin tool) has nothing
        // to do on a standalone site: it stays behind, inactive.
        $all = get_plugins();
        return array_values( array_filter( $active, function ( $file ) use ( $all ) {
            return empty( $all[ $file ]['Network'] );
        } ) );
    }

    /**
     * Domains a domain-mapping plugin pointed at the site (Mercator and the
     * old WordPress MU Domain Mapping use {base}domain_mapping, Ultimate
     * Multisite {base}wu_domain_mappings). Core's own mapping is the site's
     * address, so it needs nothing here.
     */
    private function mapped_domains( $id ) {
        global $wpdb;
        $out = [];
        foreach ( [ 'domain_mapping', 'wu_domain_mappings' ] as $table ) {
            $name = $wpdb->base_prefix . $table;
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) ) !== $name ) {
                continue;
            }
            $cols = (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$name}`" );
            if ( ! in_array( 'blog_id', $cols, true ) || ! in_array( 'domain', $cols, true ) ) {
                continue;
            }
            foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT domain FROM `{$name}` WHERE blog_id = %d", $id ) ) as $domain ) {
                $domain = strtolower( trim( (string) $domain ) );
                if ( $domain !== '' && preg_match( '/^[a-z0-9.-]+$/', $domain ) ) {
                    $out[] = 'https://' . $domain;
                }
            }
        }
        return array_values( array_unique( $out ) );
    }

    /** Source addresses the moved folders were served from, and the folder pairs. */
    private $bases     = [];
    private $url_moves = [];
    private $url_pairs = [];

    /** Which plugin and theme folders of a shared folder this site uses. */
    private function pools_from_usage() {
        if ( isset( $this->pools[ $this->plugin_dir ] ) ) {
            foreach ( $this->active as $file ) {
                $top = strtok( (string) $file, '/' );
                if ( $top !== false && $top !== '' ) {
                    $this->pools[ $this->plugin_dir ][ $top ] = true;
                }
            }
            $this->pools[ $this->plugin_dir ]['index.php'] = true;
            $this->pools[ $this->plugin_dir ][ basename( dirname( __DIR__ ) ) ] = true;
        }
        if ( isset( $this->pools[ $this->theme_dir ] ) ) {
            foreach ( $this->theme as $slug ) {
                if ( $slug !== '' ) {
                    $this->pools[ $this->theme_dir ][ $slug ] = true;
                }
            }
            $this->pools[ $this->theme_dir ]['index.php'] = true;
        }
        foreach ( $this->pools as $dir => $used ) {
            $abs = $this->root . ( $dir === '' ? '' : '/' . $dir );
            foreach ( (array) @scandir( $abs ) as $child ) {
                if ( $child === '.' || $child === '..' || $child === '' || isset( $used[ $child ] ) || ( $child[0] ?? '' ) === '.' ) {
                    continue;
                }
                $kind = $dir === $this->plugin_dir ? 'plugins' : 'themes';
                if ( $kind === 'plugins' && ! is_dir( $abs . '/' . $child ) && substr( $child, -4 ) !== '.php' ) {
                    continue;
                }
                $this->left_out[ $kind ][] = $child;
            }
        }
    }

    /* ------------------------------------------------------------------
     * Files
     * ---------------------------------------------------------------- */

    private static function under( $path, $dir ) {
        return $dir === '' || $path === $dir || strpos( $path, $dir . '/' ) === 0;
    }

    /** The name a file gets in the export, or null when it stays behind. */
    public function file_out( $rel ) {
        $rel = ltrim( (string) $rel, '/' );
        foreach ( $this->moves as $from => $to ) {
            if ( self::under( $rel, $from ) ) {
                return $to . substr( $rel, strlen( $from ) );
            }
        }
        if ( in_array( $rel, $this->drop_files, true ) ) {
            return null;
        }
        foreach ( $this->drop as $dir ) {
            if ( self::under( $rel, $dir ) ) {
                return null;
            }
        }
        foreach ( $this->pools as $dir => $used ) {
            if ( strpos( $rel, $dir . '/' ) === 0 ) {
                $child = strtok( substr( $rel, strlen( $dir ) + 1 ), '/' );
                if ( ! isset( $used[ $child ] ) ) {
                    return null;
                }
            }
        }
        return $rel;
    }

    /** True when the scan can skip a folder: nothing in it comes across. */
    public function skip_dir( $rel ) {
        $rel = trim( (string) $rel, '/' );
        foreach ( $this->moves as $from => $to ) {
            if ( self::under( $rel, $from ) || self::under( $from, $rel ) ) {
                return false;
            }
        }
        foreach ( $this->drop as $dir ) {
            if ( self::under( $rel, $dir ) ) {
                return true;
            }
        }
        foreach ( $this->pools as $dir => $used ) {
            if ( dirname( $rel ) === $dir || ( $dir === '' && strpos( $rel, '/' ) === false ) ) {
                return ! isset( $used[ basename( $rel ) ] );
            }
        }
        return false;
    }

    /** The real file behind a name in the export. */
    public function file_in( $out ) {
        $out = ltrim( (string) $out, '/' );
        foreach ( $this->moves as $from => $to ) {
            if ( self::under( $out, $to ) ) {
                return $from . substr( $out, strlen( $to ) );
            }
        }
        return $out;
    }

    /* ------------------------------------------------------------------
     * Tables
     * ---------------------------------------------------------------- */

    /** Tables this site exports: output name => real name. */
    public function tables() {
        global $wpdb;
        if ( $this->tables !== null ) {
            return $this->tables;
        }
        $all = [];
        foreach ( (array) $wpdb->get_col( 'SHOW TABLES' ) as $t ) {
            $all[ (string) $t ] = true;
        }
        $map = [];
        if ( $this->kind === 'freighter' ) {
            foreach ( $all as $t => $unused ) {
                if ( strpos( $t, $this->prefix ) === 0 ) {
                    $map[ $t ] = $t;
                }
            }
            return $this->tables = $map;
        }
        $own     = $this->prefix;
        $base    = $this->base;
        // The network's own tables, and domain-mapping and network-SaaS
        // tables (Mercator, MU Domain Mapping, Ultimate Multisite's wu_*),
        // which mean nothing to a standalone site.
        $network = [ 'blogs', 'blogmeta', 'site', 'sitemeta', 'signups', 'registration_log', 'blog_versions', 'sitecategories', 'domain_mapping', 'domain_mapping_logins' ];
        $core    = [ 'posts', 'postmeta', 'options', 'comments', 'commentmeta', 'terms', 'termmeta', 'term_taxonomy', 'term_relationships', 'links' ];
        foreach ( $all as $t => $unused ) {
            if ( $own !== $base && strpos( $t, $own ) === 0 ) {
                $map[ $t ] = $t;
                continue;
            }
            if ( strpos( $t, $base ) !== 0 ) {
                continue; // not WordPress's
            }
            $rest = substr( $t, strlen( $base ) );
            if ( preg_match( '/^(\d+)_/', $rest, $m ) && isset( $this->blog_ids[ (int) $m[1] ] ) ) {
                continue; // another site's (or this one's, already mapped)
            }
            if ( in_array( $rest, $network, true ) || strpos( $rest, 'wu_' ) === 0 ) {
                continue;
            }
            if ( $rest === 'users' || $rest === 'usermeta' ) {
                $map[ $own . $rest ] = $t;
                continue;
            }
            if ( $own === $base ) {
                $map[ $t ] = $t;
                continue;
            }
            // The main site's own table, or one the whole network shares.
            if ( isset( $all[ $own . $rest ] ) || in_array( $rest, $core, true ) ) {
                continue;
            }
            $map[ $own . $rest ]          = $t;
            $this->shared[ $own . $rest ] = true;
        }
        return $this->tables = $map;
    }

    /** Output names of network-wide tables (exported empty unless asked). */
    public function shared_tables() {
        $this->tables();
        return array_keys( $this->shared );
    }

    public function real( $out ) {
        $tables = $this->tables();
        return $tables[ $out ] ?? null;
    }

    /** CREATE statement with the table (and tables it references) renamed. */
    public function create( $sql, $out, $real ) {
        if ( $out !== $real ) {
            $sql = preg_replace( '/`' . preg_quote( $real, '/' ) . '`/', '`' . str_replace( [ '\\', '$' ], [ '\\\\', '\\$' ], $out ) . '`', $sql, 1 );
        }
        foreach ( $this->tables() as $o => $r ) {
            if ( $o !== $r ) {
                $sql = str_replace( 'REFERENCES `' . $r . '`', 'REFERENCES `' . $o . '`', $sql );
            }
        }
        return $sql;
    }

    private function super_admins() {
        global $wpdb;
        if ( $this->super !== null ) {
            return $this->super;
        }
        $this->super = [];
        if ( $this->kind === 'multisite' ) {
            $logins = array_filter( array_map( 'strval', (array) get_network_option( $this->network, 'site_admins', [] ) ) );
            if ( $logins ) {
                $in          = implode( ',', array_map( function ( $l ) { return "'" . esc_sql( $l ) . "'"; }, $logins ) );
                $this->super = array_map( 'intval', (array) $wpdb->get_col( "SELECT ID FROM `{$this->base}users` WHERE user_login IN ({$in})" ) );
            }
        }
        return $this->super;
    }

    /**
     * The network's super admins with no role on this site: they come across
     * (unless the pulling site chose not to) as its administrators. IDs.
     */
    private function super_admins_added() {
        global $wpdb;
        $super = $this->super_admins();
        if ( ! $super ) {
            return [];
        }
        $have = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM `{$this->base}usermeta` WHERE meta_key = %s AND meta_value NOT IN ('', 'a:0:{}') AND user_id IN (" . implode( ',', $super ) . ')', $this->prefix . 'capabilities' ) ) );
        return array_values( array_diff( $super, $have ) );
    }

    /** Logins of $ids, in order. */
    private function logins( $ids ) {
        global $wpdb;
        $ids = array_filter( array_map( 'intval', (array) $ids ) );
        return $ids ? array_map( 'strval', (array) $wpdb->get_col( "SELECT user_login FROM `{$this->base}users` WHERE ID IN (" . implode( ',', $ids ) . ') ORDER BY ID' ) ) : [];
    }

    /** SQL condition limiting a table's rows, or ''. */
    public function where( $out ) {
        if ( $this->kind !== 'multisite' ) {
            return '';
        }
        $col = $out === $this->prefix . 'users' ? 'ID' : ( $out === $this->prefix . 'usermeta' ? 'user_id' : '' );
        if ( $col === '' ) {
            return '';
        }
        $members = "SELECT user_id FROM `{$this->base}usermeta` WHERE meta_key = '" . esc_sql( $this->prefix . 'capabilities' ) . "' AND meta_value NOT IN ('', 'a:0:{}')"
            . " UNION SELECT post_author FROM `{$this->prefix}posts`";
        $super = $this->bring_super ? $this->super_admins() : [];
        if ( $super ) {
            $members .= ' UNION SELECT ID FROM `' . $this->base . 'users` WHERE ID IN (' . implode( ',', $super ) . ')';
        }
        return "`{$col}` IN ({$members})";
    }

    /** The usermeta suffixes this site keeps under its own prefix. */
    private function own_keys() {
        global $wpdb;
        if ( $this->own_keys === null ) {
            $this->own_keys = [ 'capabilities' => true, 'user_level' => true ];
            if ( $this->prefix !== $this->base ) {
                $len = strlen( $this->prefix );
                foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT SUBSTRING(meta_key, %d) FROM `{$this->base}usermeta` WHERE meta_key LIKE %s", $len + 1, $wpdb->esc_like( $this->prefix ) . '%' ) ) as $suffix ) {
                    $this->own_keys[ (string) $suffix ] = true;
                }
            }
        }
        return $this->own_keys;
    }

    const ADMIN_CAPS = 'a:1:{s:13:"administrator";b:1;}';

    /** A row as it should be exported, or null to leave it out. */
    public function row( $out, $cols, $row ) {
        if ( $this->kind === 'multisite' && $out === $this->prefix . 'usermeta' ) {
            $k = array_search( 'meta_key', $cols, true );
            $u = array_search( 'user_id', $cols, true );
            $v = array_search( 'meta_value', $cols, true );
            if ( $k === false ) {
                return $row;
            }
            $key = (string) $row[ $k ];
            // Where the user stands in the network (their home site, the
            // domain they signed up on): nothing outside the network uses them.
            if ( $key === 'primary_blog' || $key === 'source_domain' ) {
                return null;
            }
            if ( strpos( $key, $this->base ) === 0 ) {
                $rest = substr( $key, strlen( $this->base ) );
                if ( preg_match( '/^(\d+)_/', $rest, $m ) && isset( $this->blog_ids[ (int) $m[1] ] ) ) {
                    if ( strpos( $key, $this->prefix ) !== 0 || $this->prefix === $this->base ) {
                        return null; // another site's role or setting
                    }
                } elseif ( $this->prefix !== $this->base && isset( $this->own_keys()[ $rest ] ) ) {
                    return null; // the main site's copy of a setting this site has
                }
            }
            if ( $u !== false && $v !== false && in_array( (int) $row[ $u ], $this->super_admins(), true ) ) {
                if ( $key === $this->prefix . 'capabilities' ) {
                    $row[ $v ] = self::ADMIN_CAPS;
                } elseif ( $key === $this->prefix . 'user_level' ) {
                    $row[ $v ] = '10';
                }
            }
            return $row;
        }
        if ( $out === $this->prefix . 'options' ) {
            $n = array_search( 'option_name', $cols, true );
            $v = array_search( 'option_value', $cols, true );
            if ( $n === false || $v === false ) {
                return $row;
            }
            $name = (string) $row[ $n ];
            if ( $name === 'active_plugins' ) {
                $list = maybe_unserialize( (string) $row[ $v ] );
                $list = is_array( $list ) ? $list : [];
                // Disembark itself stays on: the site receiving this runs the
                // rest of the move through it (a tenant may not have it on).
                $list[] = plugin_basename( dirname( __DIR__ ) . '/disembark.php' );
                if ( $this->kind === 'multisite' ) {
                    $list = array_merge( $list, $this->network_plugins() );
                } else {
                    $list = array_filter( $list, function ( $p ) { return strpos( (string) $p, 'wp-freighter/' ) !== 0; } );
                }
                $row[ $v ] = serialize( array_values( array_unique( $list ) ) );
            } elseif ( $this->kind === 'multisite' && in_array( $name, [ 'upload_path', 'upload_url_path' ], true ) && strpos( (string) $row[ $v ], 'blogs.dir' ) !== false ) {
                $row[ $v ] = '';
            } elseif ( $this->kind === 'multisite' && $name === 'fileupload_url' ) {
                return null;
            } elseif ( $this->kind === 'freighter' && in_array( $name, [ 'stacked_sites', 'stacked_configurations' ], true ) ) {
                return null;
            }
        }
        return $row;
    }

    /** Rows to add after a table's own: super admins without a role here. */
    public function extra_rows( $out, $cols ) {
        global $wpdb;
        if ( $this->kind !== 'multisite' || $out !== $this->prefix . 'usermeta' || ! $this->bring_super || ! $this->super_admins() ) {
            return [];
        }
        $have = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM `{$this->base}usermeta` WHERE meta_key = %s AND user_id IN (" . implode( ',', $this->super_admins() ) . ')', $this->prefix . 'capabilities' ) ) );
        $next = (int) $wpdb->get_var( "SELECT MAX(umeta_id) FROM `{$this->base}usermeta`" );
        $rows = [];
        foreach ( array_diff( $this->super_admins(), $have ) as $user ) {
            foreach ( [ 'capabilities' => self::ADMIN_CAPS, 'user_level' => '10' ] as $suffix => $value ) {
                $row = [];
                foreach ( $cols as $col ) {
                    $row[] = $col === 'umeta_id' ? (string) ++$next : ( $col === 'user_id' ? (string) $user : ( $col === 'meta_key' ? $this->prefix . $suffix : ( $col === 'meta_value' ? $value : null ) ) );
                }
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /* ------------------------------------------------------------------
     * What the source reports
     * ---------------------------------------------------------------- */

    /** How many users come across (multisite), or null. */
    private function user_count() {
        global $wpdb;
        if ( $this->kind !== 'multisite' ) {
            return null;
        }
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$this->base}users` WHERE " . $this->where( $this->prefix . 'users' ) );
    }

    /** The site's plugins, theme and versions, as Import::site_profile(). */
    public function profile() {
        global $wpdb;
        if ( ! function_exists( 'get_plugin_data' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugins = [];
        $dir     = $this->root . '/' . $this->plugin_dir;
        foreach ( (array) @scandir( $dir ) as $entry ) {
            if ( $entry === '' || $entry[0] === '.' || ( isset( $this->pools[ $this->plugin_dir ] ) && ! isset( $this->pools[ $this->plugin_dir ][ $entry ] ) ) ) {
                continue;
            }
            $files = is_dir( "{$dir}/{$entry}" ) ? array_map( function ( $f ) use ( $entry ) { return "{$entry}/{$f}"; }, array_filter( (array) @scandir( "{$dir}/{$entry}" ), function ( $f ) { return substr( (string) $f, -4 ) === '.php'; } ) ) : ( substr( $entry, -4 ) === '.php' ? [ $entry ] : [] );
            foreach ( $files as $file ) {
                $data = get_file_data( "{$dir}/{$file}", [ 'Name' => 'Plugin Name', 'Version' => 'Version' ] );
                if ( ! empty( $data['Name'] ) ) {
                    $plugins[ $file ] = [ 'name' => (string) $data['Name'], 'version' => (string) $data['Version'], 'active' => in_array( $file, $this->active, true ) ];
                }
            }
        }
        $theme = wp_get_theme( $this->theme[0], $this->root . '/' . $this->theme_dir );
        return [
            'wp_version'    => (string) get_bloginfo( 'version' ),
            'php_version'   => PHP_VERSION,
            'mysql_version' => (string) $wpdb->get_var( 'SELECT VERSION()' ),
            'plugins'       => $plugins,
            'theme'         => [ 'stylesheet' => $this->theme[0], 'name' => (string) $theme->get( 'Name' ), 'version' => (string) $theme->get( 'Version' ) ],
            'multisite'     => false,
            'one_site_of'   => $this->kind,
        ];
    }

    /** Tables with sizes and row counts, like /database for a whole site. */
    public function table_info() {
        global $wpdb;
        $sizes = [];
        foreach ( (array) $wpdb->get_results( "SELECT table_name AS t, data_length + index_length AS s FROM information_schema.TABLES WHERE table_schema = '" . esc_sql( DB_NAME ) . "'" ) as $row ) {
            $sizes[ (string) $row->t ] = (int) $row->s;
        }
        $out = [];
        foreach ( $this->tables() as $o => $r ) {
            $where = $this->where( $o );
            $out[] = (object) [
                'table'     => $o,
                'size'      => $sizes[ $r ] ?? 0,
                'row_count' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$r}`" . ( $where !== '' ? " WHERE {$where}" : '' ) ),
            ];
        }
        usort( $out, function ( $a, $b ) { return $b->size - $a->size; } );
        return $out;
    }

    /**
     * Sent with preflight and kept in disembark-source.json: what this site
     * is, and what the import needs to finish the job (the folders that
     * moved and the addresses they were served from).
     */
    public function info() {
        $install = $this->kind === 'multisite' ? 'network' : 'WP Freighter install';
        $what    = $this->kind === 'multisite'
            ? ( $this->prefix === $this->base ? 'the main site' : 'site ' . $this->id )
            : ( $this->id === 'main' ? 'the main site' : 'tenant ' . $this->id );
        return [
            'kind'          => $this->kind,
            'site'          => $this->id,
            'name'          => $this->name,
            'home'          => $this->home,
            'prefix'        => $this->prefix,
            'label'         => ( $this->name !== '' ? $this->name . ', ' : '' ) . $what . ' of the ' . $install . ' at ' . wp_parse_url( $this->main_home, PHP_URL_HOST ),
            // The main site shares the install's web root; other sites don't own it.
            'main'          => $this->kind === 'multisite' ? $this->prefix === $this->base : $this->id === 'main',
            'install_home'  => $this->main_home,
            'files'         => $this->freighter['files'] ?? null,
            'bases'         => array_values( array_filter( $this->bases ) ),
            'moves'         => $this->url_moves,
            'url_pairs'     => $this->url_pairs,
            'abspath'       => trailingslashit( $this->root ),
            'shared_tables' => $this->shared_tables(),
            'aliases'       => $this->aliases,
            'users'         => $this->user_count(),
            // The network's super admins: the ones with no role here come
            // across as administrators unless the pulling site leaves them out.
            'super_admins'  => $this->kind === 'multisite' ? [ 'added' => $this->logins( $this->super_admins_added() ), 'with_role' => $this->logins( array_diff( $this->super_admins(), $this->super_admins_added() ) ) ] : null,
            'left_out'      => $this->left_out,
        ];
    }
}
