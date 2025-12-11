<?php
/**
 *
 * @link              https://austinginder.com
 * @since             1.0.0
 * @package           Disembark
 *
 * @wordpress-plugin
 * Plugin Name:       Disembark
 * Plugin URI:        https://disembark.host
 * Description:       Safe journeys as you travel to your next WordPress host.
 * Version:           2.6.0
 * Author:            Austin Ginder
 * Author URI:        https://austinginder.com
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       disembark
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! function_exists( 'str_starts_with' ) ) {
    function str_starts_with( $haystack, $needle ) {
        return (string)$needle !== '' && strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

require plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';

/**
 * Adds a "Settings" link to the plugin's action links on the Plugins page.
 *
 * This function creates a direct link to the Disembark admin page from the main
 * list of installed plugins, making it easier for users to access.
 *
 * @param array $links An array of existing action links for the plugin.
 * @return array An array of action links with the new "Settings" link added.
 */
function disembark_add_settings_link( $links ) {
    $settings_link = '<a href="tools.php?page=disembark">' . __( 'Settings' ) . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'disembark_add_settings_link' );

new Disembark\Run();
new Disembark\Updater();
