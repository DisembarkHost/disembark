<?php

namespace Disembark;

/**
 * Where a move lands when this site is the main site of a multisite network
 * or a WP Freighter install: a new subsite or tenant, or an existing one it
 * replaces, instead of this site itself. The incoming site is always shaped
 * like a standalone site (any backup, pull or push, including one site
 * brought out of another network or install, see Scope), so a tenant can
 * become a subsite and a subsite a tenant.
 *
 * Chosen before the move starts (target.json in the move's folder), the new
 * site is created right after the rollback point is saved, and the import
 * steps then work on it alone:
 * - tables under its prefix (wp_5_, stacked_8_), and only those;
 * - files in its own folders (uploads/sites/5, content/8). Plugins and
 *   themes in a folder the sites share are added when missing and never
 *   overwritten, since other sites run them; core files, mu-plugins and
 *   drop-ins stay as they are;
 * - on a network, the incoming users are merged into the network's users:
 *   matched by email, otherwise added (with a free login), and the site's
 *   posts, comments and orders renumbered to match;
 * - the address rewrite and the checks after the move cover that site.
 *
 * The rollback point holds the site's own tables (none for a new one).
 * What the move adds to the install's shared tables is taken out again
 * row by row: the users it created or gave a role (journaled as the merge
 * goes, see merge_users()), and a new site's row in the network's blogs or
 * Freighter's registry (see undo()). So rolling back removes a new site
 * completely, folders included, and leaves everything else in the install
 * as it is now, sites made since included.
 */
class Target {

    public $kind;          // multisite | freighter
    public $into;          // "new" or the ID it replaces
    public $id      = '';  // the site's ID once known
    public $prefix  = '';
    public $home    = '';
    public $slug    = '';  // multisite: the new site's path or subdomain
    public $domain  = '';  // freighter: the new tenant's mapped domain
    public $files   = '';  // freighter files mode
    public $created = false;
    public $created_dirs = [];
    public $uploads = '';  // the site's uploads folder, relative to the web root
    public $content = '';  // its own content folder (dedicated Freighter tenants), or ''
    public $pending = [];  // a new site being made: what it will be, saved first (see create())

    /** @var string Where create() saves $pending; never saved itself. */
    private $import_path = '';

    /* ------------------------------------------------------------------
     * Choosing and saving
     * ---------------------------------------------------------------- */

    private static function file( $import_path ) {
        return rtrim( $import_path, '/' ) . '/target.json';
    }

    public static function load( $import_path ) {
        $file = self::file( $import_path );
        if ( ! is_file( $file ) ) {
            return null;
        }
        $data = json_decode( (string) file_get_contents( $file ), true );
        if ( ! is_array( $data ) || empty( $data['kind'] ) ) {
            return null;
        }
        $t = new self();
        foreach ( $data as $k => $v ) {
            if ( property_exists( $t, $k ) ) {
                $t->$k = $v;
            }
        }
        return $t;
    }

    public function save( $import_path ) {
        Storage::ensure_dir( $import_path );
        $vars = get_object_vars( $this );
        unset( $vars['import_path'] );
        file_put_contents( self::file( $import_path ), wp_json_encode( $vars ) );
    }

    /** Saves $pending before the new site is made (see create()). */
    private function save_pending( $pending ) {
        $this->pending = $pending;
        if ( $this->import_path !== '' ) {
            $this->save( $this->import_path );
        }
    }

    /**
     * A new site an earlier try made and then stopped before saving it
     * (see create()): found by the address or ID it was given, and only
     * when it was made after that try began. True when adopted.
     */
    private function adopt_pending() {
        $p  = (array) $this->pending;
        $at = (int) ( $p['at'] ?? 0 );
        if ( $this->kind === 'multisite' && ! empty( $p['domain'] ) && isset( $p['path'] ) ) {
            $site = get_site_by_path( (string) $p['domain'], (string) $p['path'] );
            if ( ! $site || (string) $site->domain !== (string) $p['domain'] || (string) $site->path !== (string) $p['path'] || strtotime( (string) $site->registered . ' UTC' ) < $at - 5 ) {
                return false;
            }
            $this->slug    = (string) ( $p['slug'] ?? $this->slug );
            $this->created = true;
            $this->locate( (string) $site->blog_id );
            $scheme        = (string) wp_parse_url( get_home_url( get_main_site_id() ), PHP_URL_SCHEME );
            $this->home    = untrailingslashit( ( $scheme !== '' ? $scheme : 'https' ) . '://' . $p['domain'] . $p['path'] );
            $this->make_dir( $this->uploads );
            return true;
        }
        if ( $this->kind === 'freighter' && ! empty( $p['id'] ) ) {
            $primary = Scope::freighter_primary();
            foreach ( (array) Scope::freighter_option( $primary, 'stacked_sites' ) as $site ) {
                if ( (string) ( $site['stacked_site_id'] ?? '' ) === (string) $p['id'] && (int) ( $site['created_at'] ?? 0 ) >= $at - 5 ) {
                    $this->domain  = (string) ( $site['domain'] ?? '' );
                    $this->created = true;
                    $this->locate( (string) $p['id'] );
                    if ( $this->content !== '' ) {
                        $this->make_dir( $this->content );
                        foreach ( [ 'plugins', 'themes', 'uploads' ] as $sub ) {
                            $this->make_dir( $this->content . '/' . $sub );
                        }
                    } elseif ( $this->files === 'hybrid' ) {
                        $this->make_dir( $this->uploads );
                    }
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Records where the move goes. $into: '' or "site" (this site, as
     * before), "new", or the ID of a subsite or tenant to replace. Returns
     * the target, null for this site, or WP_Error.
     */
    public static function choose( $import_path, $into, $opts = [] ) {
        @unlink( self::file( $import_path ) );
        $into = strtolower( trim( (string) $into ) );
        if ( $into === '' || $into === 'site' ) {
            return null;
        }
        $install = Scope::install();
        if ( ! $install ) {
            return new \WP_Error( 'not_shared', 'This site isn\'t a multisite network or a WP Freighter install, so there\'s nowhere else to put the site (--into).', [ 'status' => 400 ] );
        }
        if ( (string) $install['current'] !== (string) $install['main'] ) {
            return new \WP_Error( 'not_main', 'Bring a site into the ' . ( $install['kind'] === 'multisite' ? 'network' : 'install' ) . ' from its main site\'s Disembark.', [ 'status' => 400 ] );
        }
        $t       = new self();
        $t->kind = $install['kind'];
        if ( $into === 'main' || $into === (string) $install['main'] ) {
            return new \WP_Error( 'is_main', 'That\'s the main site. To replace it, leave out --into.', [ 'status' => 400 ] );
        }
        if ( $into !== 'new' ) {
            $known = array_column( $install['sites'], 'id' );
            if ( ! in_array( $into, $known, true ) && ! ( $t->kind === 'multisite' && ctype_digit( $into ) && get_site( (int) $into ) ) ) {
                return new \WP_Error( 'unknown_site', 'No ' . ( $t->kind === 'multisite' ? 'subsite' : 'tenant' ) . ' ' . $into . ' here.', [ 'status' => 404 ] );
            }
        }
        $t->into   = $into;
        $t->slug   = sanitize_title( (string) ( $opts['slug'] ?? '' ) );
        $t->domain = strtolower( preg_replace( '#^https?://|/.*$#i', '', trim( (string) ( $opts['domain'] ?? '' ) ) ) );
        if ( $t->domain !== '' && $t->kind === 'freighter' ) {
            if ( ! preg_match( '/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?(?::\d+)?$/', $t->domain ) ) {
                return new \WP_Error( 'bad_domain', $t->domain . ' isn\'t a domain name.', [ 'status' => 400 ] );
            }
            if ( self::domain_taken( $t->domain ) ) {
                return new \WP_Error( 'domain_taken', $t->domain . ' already belongs to this install (its main site or another tenant). Choose another domain.', [ 'status' => 409 ] );
            }
        }
        if ( $into !== 'new' ) {
            $t->locate( $into );
        }
        $t->save( $import_path );
        return $t;
    }

    /** Fills in an existing site's prefix, address and folders. */
    private function locate( $id ) {
        global $wpdb;
        $this->id = (string) $id;
        if ( $this->kind === 'multisite' ) {
            $this->prefix  = $wpdb->get_blog_prefix( (int) $id );
            $this->home    = untrailingslashit( get_home_url( (int) $id ) );
            $this->uploads = self::rel( self::multisite_uploads( (int) $id ) );
            return;
        }
        $primary      = Scope::freighter_primary();
        $config       = (array) Scope::freighter_option( $primary, 'stacked_configurations' );
        $this->files  = in_array( $config['files'] ?? '', [ 'shared', 'hybrid', 'dedicated' ], true ) ? $config['files'] : 'shared';
        $this->prefix = 'stacked_' . (int) $id . '_';
        $this->home   = untrailingslashit( (string) Scope::freighter_option( $primary, 'home' ) );
        foreach ( (array) Scope::freighter_option( $primary, 'stacked_sites' ) as $site ) {
            if ( (string) ( $site['stacked_site_id'] ?? '' ) === (string) $id && ( $config['domain_mapping'] ?? 'off' ) === 'on' && trim( (string) ( $site['domain'] ?? '' ) ) !== '' ) {
                $this->home = 'https://' . trim( (string) $site['domain'] );
            }
        }
        $this->content = $this->files === 'dedicated' ? 'content/' . (int) $id : '';
        $this->uploads = $this->files === 'shared' ? self::rel( WP_CONTENT_DIR . '/uploads' ) : 'content/' . (int) $id . '/uploads';
    }

    private static function multisite_uploads( $id ) {
        if ( get_site_option( 'ms_files_rewriting' ) ) {
            return WP_CONTENT_DIR . '/blogs.dir/' . $id . '/files';
        }
        return WP_CONTENT_DIR . '/uploads/sites/' . $id;
    }

    private static function rel( $path ) {
        $root = rtrim( wp_normalize_path( dirname( WP_CONTENT_DIR ) ), '/' );
        $path = rtrim( wp_normalize_path( $path ), '/' );
        return strpos( $path, $root . '/' ) === 0 ? substr( $path, strlen( $root ) + 1 ) : $path;
    }

    /**
     * The network's main site, as the site whose users a whole-site move
     * merges into the network's (see Import::import_staged_sql()): replaced
     * in place, never created or undone.
     */
    public static function network_main() {
        global $wpdb;
        $t         = new self();
        $t->kind   = 'multisite';
        $t->into   = 'main';
        $t->id     = (string) get_main_site_id();
        $t->prefix = $wpdb->base_prefix;
        $t->home   = untrailingslashit( get_home_url( get_main_site_id() ) );
        return $t;
    }

    /**
     * SQL matching the usermeta keys that hold a user's role and settings
     * for this site: everything under a subsite's prefix (wp_5_), or the
     * main site's own keys under the network's base prefix, which every
     * subsite's keys share.
     */
    private function own_meta_where() {
        global $wpdb;
        if ( $this->into === 'main' ) {
            $keys = array_map( function ( $k ) { return $this->prefix . $k; }, [ 'capabilities', 'user_level', 'user-settings', 'user-settings-time', 'persisted_preferences' ] );
            return $wpdb->prepare( 'meta_key IN (' . implode( ',', array_fill( 0, count( $keys ), '%s' ) ) . ') OR meta_key LIKE %s', array_merge( $keys, [ $wpdb->esc_like( $this->prefix . 'dashboard_' ) . '%' ] ) );
        }
        return $wpdb->prepare( 'meta_key LIKE %s', $wpdb->esc_like( $this->prefix ) . '%' );
    }

    /* ------------------------------------------------------------------
     * Creating a new site (after the rollback point)
     * ---------------------------------------------------------------- */

    /**
     * Whether the site the move lands in is known: an existing one, or a new
     * one already made. A move never places files or tables for a target
     * that isn't (its folders and prefix would be empty).
     */
    public function ready() {
        return ( $this->into !== 'new' || $this->created ) && $this->id !== '' && $this->prefix !== '' && $this->uploads !== '';
    }

    /**
     * $source: the staged disembark-source.json (for a name and a slug).
     * $import_path: where to save what's about to be made before it's made,
     * so a request that dies between making the site and saving the target
     * leaves a site the next try adopts rather than an orphan, and a second
     * one.
     */
    public function create( $source, $import_path = '' ) {
        if ( $this->into !== 'new' || $this->created ) {
            return true;
        }
        if ( $this->pending && $this->adopt_pending() ) {
            $this->pending = [];
            return true;
        }
        $this->import_path = (string) $import_path;
        $source = (array) $source;
        $from   = (string) ( $source['home_url'] ?? '' );
        $name   = (string) ( ( (array) ( $source['scope'] ?? [] ) )['name'] ?? '' );
        if ( $name === '' ) {
            $name = (string) wp_parse_url( $from, PHP_URL_HOST );
        }
        return $this->kind === 'multisite' ? $this->create_subsite( $from, $name ) : $this->create_tenant( $from, $name );
    }

    private function make_dir( $rel ) {
        $abs = rtrim( wp_normalize_path( dirname( WP_CONTENT_DIR ) ), '/' ) . '/' . $rel;
        if ( is_dir( $abs ) ) {
            return;
        }
        // Remember the topmost folder this created, so a rollback removes it.
        $top = $rel;
        while ( dirname( $top ) !== '.' && ! is_dir( rtrim( wp_normalize_path( dirname( WP_CONTENT_DIR ) ), '/' ) . '/' . dirname( $top ) ) ) {
            $top = dirname( $top );
        }
        wp_mkdir_p( $abs );
        $this->created_dirs[] = $top;
    }

    private function create_subsite( $from, $name ) { // $name: the import brings the site's own
        global $wpdb;
        $network = get_network();
        $slug    = $this->slug;
        if ( $slug === '' ) {
            $path = trim( (string) wp_parse_url( $from, PHP_URL_PATH ), '/' );
            $slug = $path !== '' ? basename( $path ) : preg_replace( '/^www\./', '', (string) strtok( (string) wp_parse_url( $from, PHP_URL_HOST ), '.' ) );
            $slug = sanitize_title( $slug ) ?: 'site';
        }
        $base = $slug;
        for ( $n = 2; $n < 100; $n++ ) {
            $domain = is_subdomain_install() ? $slug . '.' . preg_replace( '/^www\./', '', $network->domain ) : $network->domain;
            $path   = is_subdomain_install() ? $network->path : $network->path . $slug . '/';
            if ( ! domain_exists( $domain, $path, $network->id ) && ( is_subdomain_install() || ! in_array( $slug, get_subdirectory_reserved_names(), true ) ) ) {
                break;
            }
            $slug = $base . '-' . $n;
        }
        // The import brings every table, so WordPress (and plugins hooked to
        // a new site) mustn't install the default ones first.
        global $wp_filter;
        $this->save_pending( [ 'domain' => $domain, 'path' => $path, 'slug' => $slug, 'at' => time() ] );
        $saved = $wp_filter['wp_initialize_site'] ?? null;
        unset( $wp_filter['wp_initialize_site'] );
        $id = wp_insert_site( [ 'domain' => $domain, 'path' => $path, 'network_id' => $network->id ] );
        Scope::forget();
        if ( $saved ) {
            $wp_filter['wp_initialize_site'] = $saved;
        }
        if ( is_wp_error( $id ) ) {
            $this->save_pending( [] );
            return $id;
        }
        $this->pending = [];
        $this->slug    = $slug;
        $this->created = true;
        $this->locate( (string) $id );
        $scheme        = (string) wp_parse_url( get_home_url( get_main_site_id() ), PHP_URL_SCHEME );
        $this->home    = untrailingslashit( ( $scheme !== '' ? $scheme : 'https' ) . '://' . $domain . $path );
        $this->make_dir( $this->uploads );
        return true;
    }

    private function create_tenant( $from, $name ) {
        global $wpdb;
        $primary = Scope::freighter_primary();
        $sites   = (array) Scope::freighter_option( $primary, 'stacked_sites' );
        $config  = (array) Scope::freighter_option( $primary, 'stacked_configurations' );
        $ids     = array_map( 'intval', array_column( $sites, 'stacked_site_id' ) );
        $id      = $ids ? max( $ids ) + 1 : 1;
        $tables  = (array) $wpdb->get_col( 'SHOW TABLES' );
        while ( true ) {
            $prefix = "stacked_{$id}_";
            $taken  = $prefix === $primary;
            foreach ( $tables as $t ) {
                if ( strpos( (string) $t, $prefix ) === 0 ) {
                    $taken = true;
                    break;
                }
            }
            if ( ! $taken ) {
                break;
            }
            $id++;
        }
        $mapping = ( $config['domain_mapping'] ?? 'off' ) === 'on';
        $domain  = $this->domain;
        if ( $mapping && $domain === '' ) {
            // The source's own domain, unless it is already the main site's
            // or another tenant's here (a copy within the same install):
            // mapped twice, it would take that site's address over.
            $domain = strtolower( (string) wp_parse_url( $from, PHP_URL_HOST ) );
            if ( $domain !== '' && self::domain_taken( $domain ) ) {
                $domain = '';
            }
        }
        $this->save_pending( [ 'id' => $id, 'at' => time() ] );
        $sites[] = [ 'stacked_site_id' => $id, 'created_at' => time(), 'name' => sanitize_text_field( $name ), 'domain' => $mapping ? $domain : '' ];
        // The install's first tenant: there is no registry row to update yet.
        if ( Scope::freighter_option( $primary, 'stacked_sites' ) === null ) {
            $wpdb->insert( $primary . 'options', [ 'option_name' => 'stacked_sites', 'option_value' => serialize( array_values( $sites ) ), 'autoload' => 'yes' ] );
        } else {
            $wpdb->update( $primary . 'options', [ 'option_value' => serialize( array_values( $sites ) ) ], [ 'option_name' => 'stacked_sites' ] );
        }
        wp_cache_delete( 'alloptions', 'options' );
        wp_cache_delete( 'stacked_sites', 'options' );
        Scope::forget();
        $this->pending = [];
        $this->domain  = $mapping ? $domain : '';
        $this->created = true;
        $this->locate( (string) $id );
        if ( $this->content !== '' ) {
            $this->make_dir( $this->content );
            foreach ( [ 'plugins', 'themes', 'uploads' ] as $sub ) {
                $this->make_dir( $this->content . '/' . $sub );
            }
        } elseif ( $this->files === 'hybrid' ) {
            $this->make_dir( $this->uploads );
        }
        self::refresh_freighter();
        return true;
    }

    /** Whether $domain is already the Freighter install's own address or one of its tenants'. */
    private static function domain_taken( $domain ) {
        $host    = function ( $d ) {
            $d = strtolower( trim( (string) $d ) );
            return (string) ( preg_match( '#^https?://#', $d ) ? wp_parse_url( $d, PHP_URL_HOST ) : preg_replace( '#[:/].*$#', '', $d ) );
        };
        $want    = $host( $domain );
        $primary = Scope::freighter_primary();
        $taken   = [ $host( (string) Scope::freighter_option( $primary, 'home' ) ), $host( (string) Scope::freighter_option( $primary, 'siteurl' ) ), $host( home_url() ) ];
        foreach ( (array) Scope::freighter_option( $primary, 'stacked_sites' ) as $site ) {
            $taken[] = $host( $site['domain'] ?? '' );
        }
        $taken = array_filter( $taken );
        foreach ( $taken as $t ) {
            if ( $want === $t || preg_replace( '/^www\./', '', $want ) === preg_replace( '/^www\./', '', $t ) ) {
                return true;
            }
        }
        return false;
    }

    /** Rewrites Freighter's bootstrap (its domain mappings), when it's here to do it. */
    private static function refresh_freighter() {
        if ( class_exists( '\WPFreighter\Configurations' ) ) {
            try {
                ( new \WPFreighter\Configurations() )->refresh_configs();
            } catch ( \Throwable $e ) {
                // The registry is right; Freighter rewrites its bootstrap on its next admin load.
            }
        }
    }

    /* ------------------------------------------------------------------
     * Files
     * ---------------------------------------------------------------- */

    /**
     * Where a staged file goes (relative to the web root), and whether it
     * may replace a file already there. Returns [ path, may_replace ] or
     * null when it stays out: core files, mu-plugins and drop-ins belong to
     * the whole install.
     */
    public function dest( $rel ) {
        if ( ! $this->ready() || ! Import::is_safe_relative_path( $rel ) ) {
            return null;
        }
        // One slash between segments and no "." segments, so a name like
        // wp-content/./db.php is judged as the file it is.
        $norm = implode( '/', array_filter( explode( '/', str_replace( '\\', '/', $rel ) ), function ( $seg ) { return $seg !== '' && $seg !== '.'; } ) );
        if ( Import::in_storage( $norm ) ) {
            return null;
        }
        $to = $this->map( $norm );
        // Never into a Disembark folder (its restore points, token guards)
        // and never a database export.
        if ( $to === null || Import::in_storage( $to[0] ) || preg_match( '#\.(?:db|sql)\.txt$#i', $to[0] ) ) {
            return null;
        }
        return $to;
    }

    private function map( $rel ) {
        $rel = ltrim( (string) $rel, '/' );
        if ( stripos( $rel, 'wp-content/' ) !== 0 ) {
            return null;
        }
        $sub = substr( $rel, strlen( 'wp-content/' ) );
        if ( strpos( $sub, '/' ) === false ) {
            // Never map drop-ins or loose files at wp-content/ root
            // (freighter.php, sunrise.php, db.php, advanced-cache.php,
            // object-cache.php, maintenance.php, db-error.php, etc.).
            return null;
        }
        // Folder names compared without case: on a case-insensitive disk
        // Uploads/ is uploads/, and its rules (no PHP, Disembark's own
        // folder left alone) hold however a backup spells it.
        $top = strtolower( (string) strtok( $sub, '/' ) );
        if ( $top === 'uploads' ) {
            if ( preg_match( '#^uploads/disembark(?:/|$)#i', $sub ) ) {
                return null;
            }
            if ( preg_match( '/\.(?:php[34578s]?|phtml|phar|pht)$/i', $sub ) ) {
                return null;
            }
            return [ $this->uploads . substr( $sub, strlen( 'uploads' ) ), ! ( $this->kind === 'freighter' && $this->files === 'shared' ) ];
        }
        if ( $this->content !== '' ) {
            if ( in_array( $top, [ 'plugins', 'themes', 'languages' ], true ) ) {
                return [ $this->content . '/' . $sub, true ];
            }
            if ( $top === 'mu-plugins' ) {
                $entry = (string) strtok( substr( $sub, strlen( 'mu-plugins/' ) ), '/' );
                if ( $entry === '' || strtolower( $entry ) === 'disembark-quiet-mode.php' || stripos( $entry, 'freighter' ) === 0 ) {
                    return null;
                }
                foreach ( Import::HOST_MU_PLUGINS as $name ) {
                    if ( stripos( $entry, $name ) === 0 ) {
                        return null;
                    }
                }
                return [ $this->content . '/' . $sub, true ];
            }
            // A dedicated tenant has a whole wp-content of its own, so its
            // other folders come too (a gallery's, a font or cache plugin's).
            return [ $this->content . '/' . $sub, true ];
        }
        $shared = self::rel( WP_CONTENT_DIR );
        if ( in_array( $top, [ 'plugins', 'themes', 'languages' ], true ) ) {
            return [ $shared . '/' . $sub, false ];
        }
        return null;
    }

    /** The shared folder a file belongs to (wp-content/plugins/woocommerce), or ''. */
    public function pool_folder( $dest ) {
        if ( $this->content !== '' ) {
            return '';
        }
        $shared = self::rel( WP_CONTENT_DIR );
        foreach ( [ 'plugins', 'themes' ] as $pool ) {
            $dir = $shared . '/' . $pool . '/';
            if ( strpos( $dest, $dir ) === 0 ) {
                $rest = substr( $dest, strlen( $dir ) );
                return strpos( $rest, '/' ) === false ? '' : $dir . strtok( $rest, '/' );
            }
        }
        return '';
    }

    /* ------------------------------------------------------------------
     * Database
     * ---------------------------------------------------------------- */

    /** The site's tables now. */
    public function tables() {
        global $wpdb;
        if ( $this->prefix === '' ) {
            return [];
        }
        $out = [];
        foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $this->prefix ) . '%' ) ) as $t ) {
            // On a network, wp_1_ doesn't exist and wp_10_ starts with wp_1.
            if ( $this->kind === 'multisite' && preg_match( '/^' . preg_quote( $this->prefix, '/' ) . '\d+_/', (string) $t ) ) {
                continue;
            }
            $out[] = (string) $t;
        }
        return $out;
    }

    /**
     * Merges the users a network subsite arrived with ({prefix}users and
     * {prefix}usermeta, imported beside its other tables, or the tables
     * $incoming names) into the network's users, renumbers the site's
     * content to match, and drops the copies.
     *
     * A site can arrive with tens of thousands of users (a store's
     * customers), so the merge goes in steps with a checkpoint in
     * $cursor_file: with a $deadline it stops there and answers
     * [ done: false, users_done, users_total ], and the next call carries
     * on. A call that was killed carries on from the last checkpoint
     * without adding anyone twice: the users it created are in the journal
     * with their old IDs, the settings rows since the checkpoint are
     * checked before they're added, and a table's renumbering is marked
     * before its last step (which can run twice) and after it.
     */
    public function merge_users( $old_prefix, $incoming = null, $journal_file = '', $deadline = 0, $cursor_file = '' ) {
        global $wpdb;
        // renamed: users whose login was taken here and who log in under a
        // new one; unattributed: users who couldn't be added, whose content
        // now has no author rather than someone else's.
        $blank = [ 'created' => 0, 'matched' => 0, 'renumbered' => 0, 'renamed' => [], 'unattributed' => [], 'orphaned' => 0 ];
        if ( $this->kind !== 'multisite' ) {
            return $blank;
        }
        $in_users = $incoming ? (string) $incoming[0] : $this->prefix . 'users';
        $in_meta  = $incoming ? (string) $incoming[1] : $this->prefix . 'usermeta';
        $users    = $wpdb->base_prefix . 'users';
        $meta     = $wpdb->base_prefix . 'usermeta';
        $cur      = $cursor_file !== '' ? json_decode( (string) Import::read_protected( $cursor_file ), true ) : null;
        $resumed  = is_array( $cur );
        if ( ! $resumed ) {
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $in_users ) ) ) !== $in_users ) {
                return $blank;
            }
            $cur = [ 'phase' => 'old', 'last_user' => 0, 'last_meta' => 0, 'map' => [], 'created' => [], 'report' => $blank, 'done_tables' => [], 'subtracting' => '', 'total' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$in_users}`" ) ];
        }
        $map     = [];
        foreach ( (array) $cur['map'] as $o => $n ) {
            $map[ (int) $o ] = (int) $n;
        }
        $created = array_fill_keys( array_map( 'intval', array_keys( (array) $cur['created'] ) ), true );
        $report  = array_merge( $blank, (array) $cur['report'] );
        // What the merge adds to the network's users and takes from them,
        // one line per row as it goes, so a rollback takes exactly that
        // out again (see undo_merge()): "u" a user it created (with "o",
        // their old ID), "m" a usermeta row it added, "d" a usermeta row it
        // removed.
        if ( $resumed && $journal_file !== '' ) {
            // Users a killed call created after its last checkpoint.
            foreach ( Pace::journal_read( $journal_file ) as $r ) {
                if ( isset( $r['u'], $r['o'] ) && ! isset( $map[ (int) $r['o'] ] ) ) {
                    $map[ (int) $r['o'] ]     = (int) $r['u'];
                    $created[ (int) $r['o'] ] = true;
                    $report['created']++;
                }
            }
        }
        $journal = $journal_file !== '' ? Pace::journal_open( $journal_file ) : null;
        $save    = function () use ( &$cur, &$map, &$created, &$report, $cursor_file ) {
            if ( $cursor_file === '' ) {
                return;
            }
            $cur['map']     = (object) $map;
            $cur['created'] = (object) $created;
            $cur['report']  = $report;
            Import::write_protected( $cursor_file, (string) wp_json_encode( $cur ) );
        };
        $stop = function () use ( &$cur, &$map, $save, $journal, $deadline ) {
            if ( ! $deadline || microtime( true ) < $deadline ) {
                return null;
            }
            $save();
            if ( $journal ) {
                fclose( $journal );
            }
            return [ 'done' => false, 'phase' => $cur['phase'], 'users_done' => count( $map ), 'users_total' => (int) $cur['total'] ];
        };

        // Replacing a site: its old roles go, the incoming ones replace them.
        if ( $cur['phase'] === 'old' ) {
            if ( $this->into !== 'new' ) {
                $where = $this->own_meta_where();
                $last  = 0;
                do {
                    $old_rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$meta}` WHERE umeta_id > %d AND ( {$where} ) ORDER BY umeta_id LIMIT 1000", $last ), ARRAY_A );
                    foreach ( $old_rows as $r ) {
                        $last = (int) $r['umeta_id'];
                        Pace::journal_add( $journal, [ 'd' => $r ] );
                    }
                } while ( count( $old_rows ) === 1000 );
                $wpdb->query( "DELETE FROM `{$meta}` WHERE " . $where );
            }
            $cur['phase'] = 'users';
            $save();
        }

        if ( $cur['phase'] === 'users' ) {
            $n = 0;
            do {
                $batch = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$in_users}` WHERE ID > %d ORDER BY ID LIMIT 500", (int) $cur['last_user'] ), ARRAY_A );
                foreach ( $batch as $u ) {
                    $old = (int) $u['ID'];
                    $cur['last_user'] = $old;
                    if ( isset( $map[ $old ] ) ) {
                        continue; // done before a checkpoint we came back to
                    }
                    $this->merge_one_user( $u, $old, $users, $map, $created, $report, $journal );
                    if ( ++$n % 200 === 0 ) {
                        $save();
                    }
                    if ( $r = $stop() ) {
                        return $r;
                    }
                }
            } while ( count( $batch ) === 500 );
            $cur['phase'] = 'meta';
            $save();
        }

        if ( $cur['phase'] === 'meta' ) {
            // Settings that belong to a site carry the site's prefix on a network.
            $site_keys = [ 'capabilities', 'user_level', 'user-settings', 'user-settings-time', 'persisted_preferences' ];
            // Rows a killed call may have added after its last checkpoint
            // are looked for before they're added again.
            $check = $resumed;
            $since = 0;
            do {
                $rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$in_meta}` WHERE umeta_id > %d ORDER BY umeta_id LIMIT 500", (int) $cur['last_meta'] ), ARRAY_A );
                foreach ( $rows as $r ) {
                    $cur['last_meta'] = (int) $r['umeta_id'];
                    $old  = (int) $r['user_id'];
                    if ( empty( $map[ $old ] ) ) {
                        continue;
                    }
                    $key      = (string) $r['meta_key'];
                    $remapped = false;
                    if ( $old_prefix !== '' && strpos( $key, $old_prefix ) === 0 ) {
                        $rest = substr( $key, strlen( $old_prefix ) );
                        if ( in_array( $rest, $site_keys, true ) || strpos( $rest, 'dashboard_' ) === 0 ) {
                            $key      = $this->prefix . $rest;
                            $remapped = true;
                        }
                    }
                    // Keys under the network's prefix are roles and settings for
                    // its sites (wp_capabilities is the main site's, wp_3_ subsite
                    // 3's): only the ones remapped to this site's prefix come.
                    if ( ! $remapped && strpos( $key, $wpdb->base_prefix ) === 0 ) {
                        continue;
                    }
                    // A user who already had an account keeps their own profile:
                    // only their role and settings for this site come across.
                    if ( ! isset( $created[ $old ] ) && ! $remapped ) {
                        continue;
                    }
                    if ( $check && $since < 1000 && $wpdb->get_var( $wpdb->prepare( "SELECT umeta_id FROM `{$meta}` WHERE user_id = %d AND meta_key = %s AND meta_value = %s LIMIT 1", $map[ $old ], $key, $r['meta_value'] ) ) ) {
                        $since++;
                        continue;
                    }
                    if ( $wpdb->insert( $meta, [ 'user_id' => $map[ $old ], 'meta_key' => $key, 'meta_value' => $r['meta_value'] ] ) && ! isset( $created[ $old ] ) ) {
                        Pace::journal_add( $journal, [ 'm' => (int) $wpdb->insert_id ] );
                    }
                    if ( ++$since % 1000 === 0 ) {
                        $check = false; // past where a killed call could have got to
                        $save();
                    }
                    if ( $r2 = $stop() ) {
                        return $r2;
                    }
                }
            } while ( count( $rows ) === 500 );
            foreach ( array_keys( $created ) as $old ) {
                update_user_meta( $map[ $old ], 'primary_blog', (int) $this->id );
            }
            $cur['phase'] = 'renumber';
            $save();
        }

        if ( $cur['phase'] === 'renumber' ) {
            // The site's own tables point at users by ID. Every incoming ID is
            // renumbered (to the user's ID here, or 0 for one who couldn't be
            // added), and an ID that names no incoming user (an author deleted
            // on the source) becomes 0: left as it was, it would name whoever
            // has that ID on this network.
            $p       = $this->prefix;
            $columns = [ [ "{$p}posts", 'post_author', '' ], [ "{$p}comments", 'user_id', '' ], [ "{$p}links", 'link_owner', '' ],
                [ "{$p}postmeta", 'meta_value', "meta_key IN ('_edit_last','_customer_user')" ],
                [ "{$p}wc_orders", 'customer_id', '' ], [ "{$p}wc_customer_lookup", 'user_id', '' ] ];
            /**
             * Filters the columns that hold a user ID in a site whose users are
             * merged into a network's: [ table, column, extra WHERE ] each, the
             * table under the site's prefix (passed as the second argument).
             */
            $columns = (array) apply_filters( 'disembark_user_id_columns', $columns, $p );
            $offset       = 1000000000000;
            $unattributed = [];
            $moved        = [];
            $same         = [];
            foreach ( $map as $old => $new ) {
                $old = (int) $old;
                $new = (int) $new;
                if ( $new === 0 ) {
                    $unattributed[ $old ] = 0;
                } elseif ( $new !== $old ) {
                    $moved[ $old ] = $new;
                } else {
                    $same[ $old ] = $new;
                }
            }
            $done_tables = array_flip( (array) $cur['done_tables'] );
            foreach ( $columns as $c ) {
                list( $table, $col, $where ) = array_values( (array) $c ) + [ '', '', '' ];
                $table = (string) $table;
                $col   = (string) $col;
                $id    = $table . '.' . $col . '|' . (string) $where;
                if ( isset( $done_tables[ $id ] ) ) {
                    continue;
                }
                if ( strpos( $table, $p ) !== 0 || ! preg_match( '/^[A-Za-z0-9_]+$/', $col ) || $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
                    continue;
                }
                $col_info = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", $wpdb->esc_like( $col ) ), ARRAY_A );
                if ( ! is_array( $col_info ) ) {
                    continue;
                }
                $nullable  = isset( $col_info['Null'] ) && strtoupper( (string) $col_info['Null'] ) === 'YES';
                $empty_val = $nullable ? 'NULL' : '0';
                $extra     = (string) $where !== '' ? " AND ({$where})" : '';
                // Its last step started before a call was killed: only that
                // step again (it can run twice), never the renumbering.
                if ( (string) $cur['subtracting'] !== $id ) {
                    // Unattributed users ($new === 0) go straight to NULL (for nullable
                    // columns such as wc_customer_lookup.user_id, avoiding UNIQUE index
                    // collisions) or 0 without passing through $offset.
                    foreach ( array_chunk( array_keys( $unattributed ), 500 ) as $chunk ) {
                        $in = implode( ',', array_map( 'intval', $chunk ) );
                        $report['renumbered'] += (int) $wpdb->query( "UPDATE `{$table}` SET `{$col}` = {$empty_val} WHERE `{$col}` IN ({$in}){$extra}" );
                    }
                    // Through a high offset, so 2→5 and 5→9 can't chain; the ones
                    // that keep their ID too, so they aren't taken for orphans.
                    foreach ( [ $moved, $same ] as $i => $set ) {
                        foreach ( array_chunk( $set, 500, true ) as $chunk ) {
                            $case = '';
                            foreach ( $chunk as $old => $new ) {
                                $case .= ' WHEN ' . (int) $old . ' THEN ' . ( (int) $new + $offset );
                            }
                            $in      = implode( ',', array_map( 'intval', array_keys( $chunk ) ) );
                            $changed = (int) $wpdb->query( "UPDATE `{$table}` SET `{$col}` = CASE `{$col}`{$case} END WHERE `{$col}` IN ({$in}){$extra}" );
                            if ( $i === 0 ) {
                                $report['renumbered'] += $changed;
                            }
                        }
                    }
                    $report['orphaned'] += (int) $wpdb->query( "UPDATE `{$table}` SET `{$col}` = {$empty_val} WHERE `{$col}` > 0 AND `{$col}` < {$offset}{$extra}" );
                    $cur['subtracting'] = $id;
                    $save();
                }
                $wpdb->query( "UPDATE `{$table}` SET `{$col}` = `{$col}` - {$offset} WHERE `{$col}` >= {$offset}{$extra}" );
                $cur['done_tables'][] = $id;
                $cur['subtracting']   = '';
                $done_tables[ $id ]   = true;
                $save();
            }
            $cur['phase'] = 'finish';
            $save();
        }

        // The roles the site defines, under its prefix.
        if ( $old_prefix !== '' && $old_prefix !== $this->prefix ) {
            $wpdb->update( $this->prefix . 'options', [ 'option_name' => $this->prefix . 'user_roles' ], [ 'option_name' => $old_prefix . 'user_roles' ] );
        }
        $wpdb->query( "DROP TABLE IF EXISTS `{$in_users}`" );
        $wpdb->query( "DROP TABLE IF EXISTS `{$in_meta}`" );
        if ( $journal ) {
            fclose( $journal );
        }
        if ( $cursor_file !== '' ) {
            @unlink( $cursor_file );
        }
        wp_cache_flush();
        return $report;
    }

    /** merge_users(): one incoming user, matched by email or added. */
    private function merge_one_user( $u, $old, $users, &$map, &$created, &$report, $journal ) {
        global $wpdb;
        $email = (string) $u['user_email'];
        $found = $email !== '' ? $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM `{$users}` WHERE user_email = %s ORDER BY ID LIMIT 1", $email ) ) : null;
        if ( $found ) {
            $map[ $old ] = (int) $found;
            $report['matched']++;
            return;
        }
        // Only WordPress's own columns, by name: the incoming table's
        // column names are the source's to choose.
        $u = array_intersect_key( $u, array_flip( [ 'user_login', 'user_pass', 'user_nicename', 'user_email', 'user_url', 'user_registered', 'user_activation_key', 'user_status', 'display_name' ] ) );
        $asked = (string) ( $u['user_login'] ?? '' );
        // A user without a login (or one WordPress wouldn't take) still
        // gets an account, under a login made from their old ID.
        $login = sanitize_user( $asked );
        $why   = 'was taken';
        if ( $login === '' || $login !== $asked ) {
            $login = 'user-' . $old;
            $why   = $asked === '' ? 'was empty' : 'isn\'t a valid login';
        }
        $u['user_login']    = $login;
        $u['user_nicename'] = sanitize_title( (string) ( $u['user_nicename'] ?? '' ) ) ?: sanitize_title( $login );
        foreach ( [ 'user_login' => 60, 'user_nicename' => 50 ] as $col => $max ) {
            $base = (string) $u[ $col ];
            for ( $n = 2; $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM `{$users}` WHERE `{$col}` = %s", $u[ $col ] ) ); $n++ ) {
                $u[ $col ] = substr( $base, 0, $max - 4 ) . '-' . $n;
            }
        }
        $error = '';
        $ok    = $wpdb->insert( $users, $u ) !== false;
        if ( ! $ok ) {
            // Once more with a login of its own and only what an
            // account needs (a value this database won't take, a
            // plugin's trigger refusing the name).
            $error           = (string) $wpdb->last_error;
            $why             = 'was refused';
            $u['user_login'] = $u['user_nicename'] = 'user-' . $old . '-' . strtolower( wp_generate_password( 6, false ) );
            $ok              = $wpdb->insert( $users, array_intersect_key( $u, array_flip( [ 'user_login', 'user_pass', 'user_nicename', 'user_email', 'display_name' ] ) ) ) !== false;
        }
        if ( ! $ok ) {
            // Their content keeps no author rather than pointing at
            // whoever has their old ID here.
            $map[ $old ] = 0;
            $report['unattributed'][] = [ 'login' => $asked, 'email' => $email, 'error' => substr( $error !== '' ? $error : (string) $wpdb->last_error, 0, 160 ) ];
            return;
        }
        if ( $u['user_login'] !== $asked ) {
            $report['renamed'][] = [ 'from' => $asked, 'to' => (string) $u['user_login'], 'email' => $email, 'why' => $why ];
        }
        $map[ $old ]     = (int) $wpdb->insert_id;
        $created[ $old ] = true;
        Pace::journal_add( $journal, [ 'u' => (int) $wpdb->insert_id, 'o' => $old ] );
        $report['created']++;
    }

    /**
     * Takes back what merge_users() did to the network's users, from its
     * journal: the users it created go (with all their settings), the roles
     * it gave existing users go, and the rows it removed come back. Safe to
     * run twice. Returns how many rows it changed.
     */
    public static function undo_merge( $journal_file ) {
        global $wpdb;
        $records = Pace::journal_read( $journal_file );
        if ( ! $records || ! is_multisite() ) {
            return 0;
        }
        $users   = $wpdb->base_prefix . 'users';
        $meta    = $wpdb->base_prefix . 'usermeta';
        $created = [];
        $added   = [];
        $removed = [];
        foreach ( $records as $r ) {
            if ( isset( $r['u'] ) ) {
                $created[] = (int) $r['u'];
            } elseif ( isset( $r['m'] ) ) {
                $added[] = (int) $r['m'];
            } elseif ( isset( $r['d'] ) && is_array( $r['d'] ) ) {
                $removed[] = $r['d'];
            }
        }
        $changed = 0;
        foreach ( array_chunk( $added, 500 ) as $ids ) {
            $changed += (int) $wpdb->query( "DELETE FROM `{$meta}` WHERE umeta_id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ')' );
        }
        foreach ( array_chunk( $created, 500 ) as $ids ) {
            $in = implode( ',', array_map( 'intval', $ids ) );
            $changed += (int) $wpdb->query( "DELETE FROM `{$meta}` WHERE user_id IN ({$in})" );
            $changed += (int) $wpdb->query( "DELETE FROM `{$users}` WHERE ID IN ({$in})" );
        }
        foreach ( $removed as $r ) {
            // Under its own id, so a second run finds it there and adds nothing.
            $changed += (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO `{$meta}` ( umeta_id, user_id, meta_key, meta_value ) VALUES ( %d, %d, %s, %s )", (int) $r['umeta_id'], (int) $r['user_id'], (string) $r['meta_key'], (string) $r['meta_value'] ) );
        }
        wp_cache_flush();
        return $changed;
    }

    /* ------------------------------------------------------------------
     * After the move, and undoing it
     * ---------------------------------------------------------------- */

    /** Makes the site's own settings fit where it now lives. */
    public function finish() {
        global $wpdb;
        $options = $this->prefix . 'options';
        $wpdb->delete( $options, [ 'option_name' => 'rewrite_rules' ] );
        // The source's own Disembark token came across in its options. Left
        // there, it would sign in on the new subsite's or tenant's address,
        // and on WP Freighter that reaches the whole install. The site makes
        // a fresh one the first time Disembark needs it.
        $wpdb->delete( $options, [ 'option_name' => Token::OPTION ] );
        $active = maybe_unserialize( (string) $wpdb->get_var( "SELECT option_value FROM `{$options}` WHERE option_name = 'active_plugins'" ) );
        $active = is_array( $active ) ? $active : [];
        if ( $this->kind === 'freighter' ) {
            // A tenant runs WP Freighter (its switcher and login links).
            if ( ! in_array( 'wp-freighter/wp-freighter.php', $active, true ) ) {
                $active[] = 'wp-freighter/wp-freighter.php';
                $wpdb->update( $options, [ 'option_value' => serialize( array_values( $active ) ) ], [ 'option_name' => 'active_plugins' ] );
            }
            if ( class_exists( '\WPFreighter\Site' ) && method_exists( '\WPFreighter\Site', 'ensure_freighter' ) ) {
                try {
                    \WPFreighter\Site::ensure_freighter( (int) $this->id );
                } catch ( \Throwable $e ) {
                    // It copies itself in on its next admin load.
                }
            }
            $this->copy_host_mu_plugins();
        } else {
            // Plugins on for the whole network stay on there, not per site.
            $network = array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) );
            $kept    = array_values( array_diff( $active, $network ) );
            if ( $kept !== $active ) {
                $wpdb->update( $options, [ 'option_value' => serialize( $kept ) ], [ 'option_name' => 'active_plugins' ] );
            }
            $site = get_site( (int) $this->id );
            if ( $site ) {
                wp_update_site( (int) $this->id, [ 'last_updated' => current_time( 'mysql', true ) ] );
                clean_blog_cache( $site );
            }
        }
        wp_cache_flush();
    }

    /**
     * A dedicated tenant loads mu-plugins from its own folder, so it gets
     * this install's hosting mu-plugins (Kinsta's and the like), as WP
     * Freighter does for a tenant it creates. The source's copies were left
     * out (see Import::host_file()). Ones the tenant already has stay.
     */
    private function copy_host_mu_plugins() {
        if ( $this->content === '' ) {
            return;
        }
        $root = rtrim( wp_normalize_path( dirname( WP_CONTENT_DIR ) ), '/' );
        $from = $root . '/wp-content/mu-plugins';
        $to   = $root . '/' . $this->content . '/mu-plugins';
        foreach ( (array) @scandir( $from ) as $entry ) {
            if ( $entry === '.' || $entry === '..' || $entry === '' || file_exists( $to . '/' . $entry ) || is_link( $from . '/' . $entry ) ) {
                continue;
            }
            $host = false;
            foreach ( Import::HOST_MU_PLUGINS as $name ) {
                $host = $host || stripos( $entry, $name ) === 0;
            }
            if ( ! $host ) {
                continue;
            }
            wp_mkdir_p( $to );
            if ( is_file( $from . '/' . $entry ) ) {
                @copy( $from . '/' . $entry, $to . '/' . $entry );
                continue;
            }
            $items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $from . '/' . $entry, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
            foreach ( $items as $item ) {
                $dest = $to . '/' . $entry . '/' . substr( wp_normalize_path( $item->getPathname() ), strlen( $from . '/' . $entry ) + 1 );
                if ( $item->isLink() ) {
                    continue;
                }
                if ( $item->isDir() ) {
                    wp_mkdir_p( $dest );
                } else {
                    wp_mkdir_p( dirname( $dest ) );
                    @copy( $item->getPathname(), $dest );
                }
            }
        }
    }

    /** After a rollback: a site this move created goes completely. */
    public function undo() {
        $root         = rtrim( wp_normalize_path( dirname( WP_CONTENT_DIR ) ), '/' );
        $created_dirs = array_map( function ( $d ) {
            return trim( (string) $d, '/' );
        }, (array) $this->created_dirs );
        $protected = [
            'wp-content',
            'wp-content/uploads',
            'wp-content/uploads/sites',
            'wp-content/blogs.dir',
            'wp-content/plugins',
            'wp-content/themes',
            'content',
            'wp-admin',
            'wp-includes',
        ];
        foreach ( $created_dirs as $rel ) {
            if ( $rel === '' || ! Import::is_safe_relative_path( $rel ) || in_array( $rel, $protected, true ) ) {
                continue;
            }
            self::remove_dir( $root . '/' . $rel );
        }
        // A site this move created owns only its own folder (not the shared
        // uploads/sites, blogs.dir or content/ container, which may now hold
        // other sites). Remove the shared container non-recursively only if
        // this move created it and nothing else is inside.
        if ( $this->created && $this->kind === 'multisite' ) {
            $uploads = trim( (string) $this->uploads, '/' );
            if ( preg_match( '#^(wp-content/(?:uploads/sites|blogs\.dir)/\d+)(?:/files)?$#', $uploads, $m ) ) {
                $site_dir = $m[1];
                self::remove_dir( $root . '/' . $site_dir );
                $parent = dirname( $site_dir );
                if ( in_array( $parent, $created_dirs, true ) ) {
                    @rmdir( $root . '/' . $parent );
                }
            }
        } elseif ( $this->created && $this->kind === 'freighter' ) {
            $tenant_dir = '';
            if ( $this->content !== '' && preg_match( '#^content/\d+$#', (string) $this->content ) ) {
                $tenant_dir = (string) $this->content;
            } elseif ( $this->files === 'hybrid' && $this->id !== '' && preg_match( '/^\d+$/', (string) $this->id ) ) {
                $tenant_dir = 'content/' . (string) $this->id;
            }
            if ( $tenant_dir !== '' ) {
                self::remove_dir( $root . '/' . $tenant_dir );
                if ( in_array( 'content', $created_dirs, true ) ) {
                    @rmdir( $root . '/content' );
                }
            }
        }
        // Its row in the install's registry goes, and nothing else there:
        // the registry as it is now, with any site made since, stays.
        global $wpdb;
        if ( $this->kind === 'freighter' ) {
            if ( $this->created && $this->id !== '' ) {
                $primary = Scope::freighter_primary();
                $sites   = Scope::freighter_option( $primary, 'stacked_sites' );
                if ( is_array( $sites ) ) {
                    $kept = array_values( array_filter( $sites, function ( $site ) { return (string) ( $site['stacked_site_id'] ?? '' ) !== (string) $this->id; } ) );
                    if ( count( $kept ) !== count( $sites ) ) {
                        $wpdb->update( $primary . 'options', [ 'option_value' => serialize( $kept ) ], [ 'option_name' => 'stacked_sites' ] );
                    }
                }
            }
            wp_cache_flush();
            self::refresh_freighter();
        } elseif ( $this->id !== '' ) {
            $site = get_site( (int) $this->id );
            if ( $site ) {
                clean_blog_cache( $site );
            }
            if ( $this->created && (int) $this->id !== (int) get_main_site_id() ) {
                $wpdb->delete( $wpdb->blogs, [ 'blog_id' => (int) $this->id ] );
                if ( ! empty( $wpdb->blogmeta ) ) {
                    $wpdb->delete( $wpdb->blogmeta, [ 'blog_id' => (int) $this->id ] );
                }
                if ( function_exists( 'wp_update_network_site_counts' ) ) {
                    wp_update_network_site_counts();
                }
            }
            wp_cache_flush();
        }
        Scope::forget();
    }

    private static function remove_dir( $dir ) {
        if ( ! is_dir( $dir ) || is_link( $dir ) ) {
            return;
        }
        foreach ( (array) scandir( $dir ) as $item ) {
            if ( $item === '.' || $item === '..' ) {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir( $path ) && ! is_link( $path ) ? self::remove_dir( $path ) : @unlink( $path );
        }
        @rmdir( $dir );
    }

    /** What the dashboard and the CLI show. */
    public function info() {
        $what = $this->kind === 'multisite' ? 'subsite' : 'tenant';
        return [
            'kind'    => $this->kind,
            'into'    => $this->into,
            'id'      => $this->id,
            'prefix'  => $this->prefix,
            'home'    => $this->home,
            'created' => $this->created,
            'label'   => $this->into === 'new'
                ? ( $this->id !== '' ? "the new {$what} {$this->id} ({$this->home})" : "a new {$what}" )
                : "{$what} {$this->id} ({$this->home}), replacing it",
        ];
    }
}
