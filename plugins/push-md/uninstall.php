<?php
/**
 * Uninstall cleanup for Push MD.
 *
 * Deletes Push MD's derived Git repository storage and seeder state.
 * WordPress posts, pages, templates, and other source content remain intact.
 *
 * @package PushMD
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

push_md_uninstall_cleanup();

/**
 * Clean up every site in multisite installs, or the current site otherwise.
 */
function push_md_uninstall_cleanup() {
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
				push_md_uninstall_cleanup_site();
				restore_current_blog();
			}

			$offset += $number;
		} while ( count( $site_ids ) === $number );

		return;
	}

	push_md_uninstall_cleanup_site();
}

/**
 * Clean up Push MD data for the current site.
 */
function push_md_uninstall_cleanup_site() {
	delete_option( 'push_md_seed_state' );
	delete_option( 'push_md_seed_progress' );
	delete_transient( 'push_md_seed_lock' );
	wp_clear_scheduled_hook( 'push_md_seed_tick' );
	push_md_uninstall_drop_repository_tables();
}

/**
 * Drop the per-site Git object-store tables.
 */
function push_md_uninstall_drop_repository_tables() {
	global $wpdb;

	$table_prefix = preg_replace( '/[^A-Za-z0-9_]/', '', $wpdb->prefix . 'push_md_' );
	$tables       = array(
		$table_prefix . 'files',
		$table_prefix . 'directory_entries',
	);

	foreach ( $tables as $table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
	}
}
