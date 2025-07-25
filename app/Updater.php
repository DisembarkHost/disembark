<?php
namespace DisembarkConnector;

class Updater {

    public $plugin_slug;
    public $version;
    public $cache_key;
    public $cache_allowed;

    public function __construct() {

        if ( defined( 'DISEMBARK_CONNECT_DEV_MODE' ) ) {
            add_filter('https_ssl_verify', '__return_false');
            add_filter('https_local_ssl_verify', '__return_false');
            add_filter('http_request_host_is_external', '__return_true');
        }

        $this->plugin_slug   = dirname ( plugin_basename( __DIR__ ) );
        $this->version       = '1.0.6';
        $this->cache_key     = 'disembark_connect_updater';
        $this->cache_allowed = false;

        add_filter( 'plugins_api', [ $this, 'info' ], 30, 3 );
        add_filter( 'site_transient_update_plugins', [ $this, 'update' ] );
        add_action( 'upgrader_process_complete', [ $this, 'purge' ], 10, 2 );

    }

    public function request(){

        $manifest_file = dirname( plugin_dir_path( __FILE__ ) ) . "/manifest.json";
        $local         = json_decode( file_get_contents( $manifest_file ) );
        $token         = Token::get();
        $home_url      = home_url();
        $local->sections->description = "{$local->sections->description}<br /><br />Your Disembark Connector Token for $home_url<br /><code>$token</code><ul><li><strong><a href=\"https://disembark.host/?disembark_site_url=$home_url&disembark_token=$token\" target=\"_blank\">Launch Disembark with your token</a></strong></li></ul><p>Or over command line with <a href=\"https://github.com/DisembarkHost/disembark-cli\">Disembark CLI</a></p><p><ul><li><code>disembark connect $home_url $token</code></li><li><code>disembark backup $home_url</code></li></ul></p>";

        if ( defined( 'DISEMBARK_CONNECT_DEV_MODE' ) ) {
            return $local;
        }

        $remote = get_transient( $this->cache_key );

        if( false === $remote || ! $this->cache_allowed ) {

            $remote = wp_remote_get( 'https://raw.githubusercontent.com/DisembarkHost/disembark-connector/main/manifest.json', [
                    'timeout' => 30,
                    'headers' => [
                        'Accept' => 'application/json'
                    ]
                ]
            );

            if ( is_wp_error( $remote ) || 200 !== wp_remote_retrieve_response_code( $remote ) || empty( wp_remote_retrieve_body( $remote ) ) ) {
                return $local;
            }

            $remote   = json_decode( wp_remote_retrieve_body( $remote ) );
            $token    = Token::get();
            $home_url = home_url();
            $remote->sections->description = "{$remote->sections->description}<br /><br />Your Disembark Connector Token for $home_url<br /><code>$token</code><ul><li><strong><a href=\"https://disembark.host/?disembark_site_url=$home_url&disembark_token=$token\" target=\"_blank\">Launch Disembark with your token</a></strong></li></ul><p>Or over command line with <a href=\"https://github.com/DisembarkHost/disembark-cli\">Disembark CLI</a></p><p><ul><li><code>disembark connect $home_url $token</code></li><li><code>disembark backup $home_url</code></li></ul></p>";
            set_transient( $this->cache_key, $remote, DAY_IN_SECONDS );
            return $remote;

        }

        return $remote;

    }

    function info( $response, $action, $args ) {

        // do nothing if you're not getting plugin information right now
        if ( 'plugin_information' !== $action ) {
            return $response;
        }

        // do nothing if it is not our plugin
        if ( empty( $args->slug ) || $this->plugin_slug !== $args->slug ) {
            return $response;
        }

        // get updates
        $remote = $this->request();

        if ( ! $remote ) {
            return $response;
        }

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

        $response->sections = [
            'description'  => $remote->sections->description
        ];

        if ( ! empty( $remote->banners ) ) {
            $response->banners = [
                'low'  => $remote->banners->low,
                'high' => $remote->banners->high
            ];
        }

        return $response;

    }

    public function update( $transient ) {

        if ( empty($transient->checked ) ) {
            return $transient;
        }

        $remote          = $this->request();
        $response        = new \stdClass();
        $response->slug  = $this->plugin_slug;
        $response->plugin = "{$this->plugin_slug}/{$this->plugin_slug}.php";
        $response->tested = $remote->tested;

        if ( $remote && version_compare( $this->version, $remote->version, '<' ) && version_compare( $remote->requires, get_bloginfo( 'version' ), '<=' ) && version_compare( $remote->requires_php, PHP_VERSION, '<' ) ) {
            $response->new_version = $remote->version;
            $response->package     = $remote->download_url;
            $transient->response[ $response->plugin ] = $response;
        } else {
            $response->new_version = $this->version;
            $transient->no_update[ $response->plugin ] = $response;
        }

        return $transient;

    }

    public function purge( $upgrader, $options ) {

        if ( $this->cache_allowed && 'update' === $options['action'] && 'plugin' === $options[ 'type' ] ) {
            // just clean the cache when new plugin version is installed
            delete_transient( $this->cache_key );
        }

    }

}