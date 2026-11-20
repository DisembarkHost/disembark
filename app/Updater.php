<?php
namespace Disembark;

class Updater {

    public $plugin_slug;
    public $version;
    public $cache_key;
    public $cache_allowed;

    public function __construct() {
        // DISEMBARK_DEV_MODE (local sites with self-signed certificates)
        // relaxes TLS and internal-address checks for Disembark's own
        // requests only, never for the rest of the site (plugin and theme
        // downloads, other plugins' API calls).
        if ( defined( 'DISEMBARK_DEV_MODE' ) && DISEMBARK_DEV_MODE ) {
            add_filter( 'http_request_args', [ __CLASS__, 'dev_mode_args' ], 10, 2 );
            add_filter( 'http_request_host_is_external', [ __CLASS__, 'dev_mode_external' ], 10, 3 );
        }
        $this->plugin_slug   = 'disembark';
        $this->version       = '2.9.0';
        $this->cache_key     = 'disembark_updater';
        $this->cache_allowed = true;
        
        add_filter( 'plugins_api', [ $this, 'info' ], 30, 3 );
        add_filter( 'site_transient_update_plugins', [ $this, 'update' ] );
        add_action( 'upgrader_process_complete', [ $this, 'purge' ], 10, 2 );
    }

    /**
     * True for a request to another site's Disembark API or to a file it
     * made under uploads/disembark: the requests DISEMBARK_DEV_MODE covers.
     */
    public static function is_own_request( $url ) {
        return (bool) preg_match( '#(/disembark/v1(/|\?|$)|[?&]rest_route=(/|%2F)disembark(/|%2F)v1|/uploads/disembark/)#i', (string) $url );
    }

    public static function dev_mode_args( $args, $url ) {
        if ( self::is_own_request( $url ) ) {
            $args['sslverify']          = false;
            $args['reject_unsafe_urls'] = false;
        }
        return $args;
    }

    public static function dev_mode_external( $external, $host, $url = '' ) {
        return self::is_own_request( $url ) ? true : $external;
    }

    public function request(){
        // Get the local manifest as a fallback.
        $manifest_file = dirname(__DIR__) . "/manifest.json";
        $local_manifest = null;
        if ( file_exists($manifest_file) ) {
            $local_manifest = json_decode( file_get_contents( $manifest_file ) );
        }
        
        // If local manifest fails to load, create a default object to prevent errors.
        if ( ! is_object( $local_manifest ) ) {
            $local_manifest = new \stdClass();
        }

        // Attempt to get the remote manifest from the transient cache.
        $remote = get_transient( $this->cache_key );

        // The update_plugins transient is read on every admin page, so the
        // manifest is fetched at most once a day (and a failed fetch is not
        // retried for an hour) rather than on every read.
        if ( false === $remote || ! $this->cache_allowed ) {
            if ( $this->cache_allowed && get_transient( $this->cache_key . '_failed' ) ) {
                return $local_manifest;
            }
            $remote_response = wp_remote_get( 'https://raw.githubusercontent.com/DisembarkHost/disembark/main/manifest.json', [
                    'timeout' => 10,
                    'headers' => [ 'Accept' => 'application/json' ]
                ]
            );

            // If the remote request fails, return the modified local manifest.
            if ( is_wp_error( $remote_response ) || 200 !== wp_remote_retrieve_response_code( $remote_response ) || empty( wp_remote_retrieve_body( $remote_response ) ) ) {
                set_transient( $this->cache_key . '_failed', 1, HOUR_IN_SECONDS );
                return $local_manifest;
            }

            $remote = json_decode( wp_remote_retrieve_body( $remote_response ) );
            if ( ! is_object( $remote ) ) {
                set_transient( $this->cache_key . '_failed', 1, HOUR_IN_SECONDS );
                return $local_manifest;
            }
            set_transient( $this->cache_key, $remote, DAY_IN_SECONDS );
        }

        // If a valid remote object is retrieved, return it.
        if ( is_object( $remote ) ) {
            return $remote;
        }

        // Fallback to the local manifest if remote data is invalid.
        return $local_manifest;
    }

    /**
     * Only release zips from the DisembarkHost organisation on GitHub are
     * installed. The manifest isn't signed, so this caps what a tampered or
     * hijacked manifest could point WordPress at.
     */
    public static function trusted_package( $url ) {
        return is_string( $url ) && (bool) preg_match( '#^https://github\.com/DisembarkHost/[A-Za-z0-9._-]+/#', $url );
    }

    function info( $response, $action, $args ) {
        if ( 'plugin_information' !== $action || empty( $args->slug ) || $this->plugin_slug !== $args->slug ) {
            return $response;
        }

        $remote = $this->request();
        if ( ! $remote || ! self::trusted_package( $remote->download_url ?? '' ) ) { return $response; }

        $response = new \stdClass();
        $response->name           = $remote->name;
        $response->slug           = $remote->slug;
        $response->version        = $remote->version;
        $response->tested         = $remote->tested;
        $response->requires       = $remote->requires;
        $response->author         = $remote->author;
        $response->author_profile = $remote->author_profile;
        $response->donate_link    = $remote->donate_link;
        $response->homepage       = $remote->homepage;
        $response->download_link  = $remote->download_url;
        $response->trunk          = $remote->download_url;
        $response->requires_php   = $remote->requires_php;
        $response->last_updated   = $remote->last_updated;
        $response->sections       = [ 'description'  => $remote->sections->description ];

        if ( ! empty( $remote->banners ) ) {
            $response->banners = [
                'low'  => $remote->banners->low,
                'high' => $remote->banners->high
            ];
        }
        return $response;
    }

    public function update( $transient ) {
        if ( empty($transient->checked ) ) { return $transient; }

        $remote = $this->request();
        if ( $remote && isset($remote->version) && self::trusted_package( $remote->download_url ?? '' ) && version_compare( $this->version, $remote->version, '<' ) ) {
            $response = new \stdClass();
            $response->slug = $this->plugin_slug;
            $response->plugin = "{$this->plugin_slug}/{$this->plugin_slug}.php";
            $response->new_version = $remote->version;
            $response->package = $remote->download_url;
            $response->tested = $remote->tested;
            $response->requires_php = $remote->requires_php;
            $transient->response[ $response->plugin ] = $response;
        }
        return $transient;
    }

    public function purge( $upgrader, $options ) {
        if ( $this->cache_allowed && 'update' === $options['action'] && 'plugin' === $options[ 'type' ] ) {
            delete_transient( $this->cache_key );
        }
    }
}

