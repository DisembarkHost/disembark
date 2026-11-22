<?php
/**
 * Runs when Disembark is deleted from the Plugins screen: its settings, its
 * cached update check and its scheduled clean-up go, on every site of a
 * network. Left in place on purpose:
 *  - uploads/disembark, which can hold backups and restore points (the
 *    site's own data; delete the folder by hand when they aren't needed);
 *  - quiet mode, which runs from its own must-use plugin and stays on until
 *    it is turned off (wp-content/mu-plugins/disembark-quiet-mode.php).
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

function disembark_uninstall_site() {
	foreach ( [ 'disembark_token', 'disembark_last_scan_stats', 'disembark_move_address', 'disembark_storage_version' ] as $option ) {
		delete_option( $option );
	}
	delete_transient( 'disembark_updater' );
	wp_clear_scheduled_hook( 'disembark_purge' );
}

if ( is_multisite() ) {
	global $wpdb;
	foreach ( [ 'disembark_token', 'disembark_storage_version' ] as $option ) {
		delete_site_option( $option );
	}
	delete_site_transient( 'disembark_updater' );
	// Only the sites that hold something of Disembark's are switched to: a
	// large network needn't load every site.
	foreach ( get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $id ) {
		$table = $wpdb->get_blog_prefix( $id ) . 'options';
		$found = $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE option_name LIKE '%disembark%' AND option_name NOT LIKE 'disembark_quiet%' OR ( option_name = 'cron' AND option_value LIKE '%disembark_purge%' )" );
		if ( (int) $found > 0 ) {
			switch_to_blog( $id );
			disembark_uninstall_site();
			restore_current_blog();
		}
	}
} else {
	disembark_uninstall_site();
}
