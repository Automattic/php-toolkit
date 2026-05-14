<?php
/**
 * Plugin Name: Push MD
 * Plugin URI: https://pushmd.blog/
 * Description: Edit WordPress content with Git, Markdown and block files, reviewable diffs, and safe pushes.
 * Version: 0.6.0
 * Requires at least: 6.9
 * Requires PHP: 7.2
 * Author: Automattic
 * Author URI: https://automattic.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: push-md
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( file_exists( __DIR__ . '/php-toolkit/vendor/composer/ClassLoader.php' ) ) {
	require_once __DIR__ . '/push-md-toolkit-bootstrap.php';
} elseif ( file_exists( __DIR__ . '/php-toolkit.phar' ) ) {
	require_once __DIR__ . '/push-md-phar-bootstrap.php';
} elseif ( file_exists( __DIR__ . '/push-md-dev-bootstrap.php' ) ) {
	require_once __DIR__ . '/push-md-dev-bootstrap.php';
} else {
	wp_die( esc_html__( 'Push MD is missing its bundled PHP Toolkit dependency.', 'push-md' ) );
}

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/class-pmd-plugin.php';
require_once __DIR__ . '/class-pmd-buffering-response.php';
require_once __DIR__ . '/class-pmd-seeder.php';
require_once __DIR__ . '/class-pmd-admin.php';

if ( ! defined( 'PMD_PLUGIN_FILE' ) ) {
	define( 'PMD_PLUGIN_FILE', __FILE__ );
}

register_activation_hook( __FILE__, array( 'PMD_Plugin', 'on_activation' ) );

// After the user clicks Activate, send them straight to the seeder
// progress page so they can watch the import without hunting for a
// menu item. Skipped for bulk activations and CLI/AJAX flows so we
// only intercept the one-click admin "Activate" link.
add_action(
	'activated_plugin',
	function ( $plugin ) {
		if ( plugin_basename( PMD_PLUGIN_FILE ) !== $plugin ) {
			return;
		}
		if ( wp_doing_ajax() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $action && 'activate' !== $action ) {
			return;
		}
		wp_safe_redirect( admin_url( 'tools.php?page=' . PMD_Admin::PAGE_SLUG ) );
		exit;
	}
);

add_filter(
	'plugin_action_links_' . plugin_basename( PMD_PLUGIN_FILE ),
	function ( $actions ) {
		$actions['push_md_landing_page'] = sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( 'https://pushmd.blog/' ),
			esc_html__( 'Landing page', 'push-md' )
		);

		return $actions;
	}
);

PMD_Plugin::bootstrap();
