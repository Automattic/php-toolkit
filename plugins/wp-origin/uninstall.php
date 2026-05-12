<?php
/**
 * Uninstall cleanup for WP Origin.
 *
 * Deletes WP Origin's derived Git repository storage and seeder state.
 * WordPress posts, pages, templates, and other source content remain intact.
 *
 * @package WPOrigin
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

wp_origin_uninstall_cleanup();

/**
 * Clean up every site in multisite installs, or the current site otherwise.
 */
function wp_origin_uninstall_cleanup() {
	if ( is_multisite() && function_exists( 'get_sites' ) && function_exists( 'switch_to_blog' ) && function_exists( 'restore_current_blog' ) ) {
		$offset = 0;
		$number = 100;

		do {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => $number,
					'offset' => $offset,
				)
			);

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				wp_origin_uninstall_cleanup_site();
				restore_current_blog();
			}

			$offset += $number;
		} while ( count( $site_ids ) === $number );

		return;
	}

	wp_origin_uninstall_cleanup_site();
}

/**
 * Clean up WP Origin data for the current site.
 */
function wp_origin_uninstall_cleanup_site() {
	delete_option( 'wp_origin_seed_state' );
	delete_option( 'wp_origin_seed_progress' );
	delete_transient( 'wp_origin_seed_lock' );
	wp_clear_scheduled_hook( 'wp_origin_seed_tick' );
	wp_origin_uninstall_drop_repository_tables();
}

/**
 * Drop the per-site Git object-store tables.
 */
function wp_origin_uninstall_drop_repository_tables() {
	global $wpdb;

	$table_prefix = preg_replace( '/[^A-Za-z0-9_]/', '', $wpdb->prefix . 'wp_origin_' );
	$tables       = array(
		$table_prefix . 'files',
		$table_prefix . 'directory_entries',
	);

	foreach ( $tables as $table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
	}
}
