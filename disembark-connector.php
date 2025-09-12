<?php
/**
 *
 * @link              https://austinginder.com
 * @since             1.0.0
 * @package           Disembark Connector
 *
 * @wordpress-plugin
 * Plugin Name:       Disembark Connector
 * Plugin URI:        https://disembark.host
 * Description:       Connector plugin for Disembark
 * Version:           1.0.7
 * Author:            Austin Ginder
 * Author URI:        https://austinginder.com
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       disembark-connector
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

require plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';
new DisembarkConnector\Run();
new DisembarkConnector\Updater();
