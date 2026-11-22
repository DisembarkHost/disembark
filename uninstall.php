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
	delete_transient( 'disembark_updater_failed' );
	wp_clear_scheduled_hook( 'disembark_purge' );
}

if ( is_multisite() ) {
	global $wpdb;
	foreach ( [ 'disembark_token', 'disembark_storage_version', 'disembark_adopt_tries' ] as $option ) {
		delete_site_option( $option );
	}
	delete_site_transient( 'disembark_updater' );
	delete_site_transient( 'disembark_updater_failed' );
	// Only the sites that hold something of Disembark's are switched to: a
	// large network needn't load every site. Names are matched from their
	// start (the option_name index serves that), and a site whose tables
	// are gone (deleted, not yet cleaned up) is passed over quietly.
	$quiet = $wpdb->suppress_errors( true );
	foreach ( get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $id ) {
		$table = $wpdb->get_blog_prefix( $id ) . 'options';
		$found = $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE ( option_name LIKE 'disembark\\_%' AND option_name NOT LIKE 'disembark\\_quiet%' ) OR option_name LIKE '\\_transient\\_disembark\\_%' OR ( option_name = 'cron' AND option_value LIKE '%disembark_purge%' )" );
		if ( (int) $found > 0 ) {
			switch_to_blog( $id );
			disembark_uninstall_site();
			restore_current_blog();
		}
	}
	$wpdb->suppress_errors( $quiet );
} else {
	disembark_uninstall_site();
}
