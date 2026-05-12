<?php
/**
 * Plugin Name: WP Origin
 * Description: Edit WordPress content with Git, Markdown and block files, reviewable diffs, and safe pushes.
 * Version: 0.5.0
 * Requires at least: 6.9
 * Requires PHP: 7.2
 * Author: Automattic
 * Author URI: https://automattic.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-origin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( file_exists( __DIR__ . '/php-toolkit/vendor/composer/ClassLoader.php' ) ) {
	require_once __DIR__ . '/wp-origin-toolkit-bootstrap.php';
} elseif ( file_exists( __DIR__ . '/php-toolkit.phar' ) ) {
	require_once __DIR__ . '/wp-origin-phar-bootstrap.php';
} elseif ( file_exists( __DIR__ . '/wp-origin-dev-bootstrap.php' ) ) {
	require_once __DIR__ . '/wp-origin-dev-bootstrap.php';
} else {
	wp_die( esc_html__( 'WP Origin is missing its bundled PHP Toolkit dependency.', 'wp-origin' ) );
}

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/class-wp-origin-plugin.php';
require_once __DIR__ . '/class-wp-origin-buffering-response.php';
require_once __DIR__ . '/class-wp-origin-seeder.php';
require_once __DIR__ . '/class-wp-origin-admin.php';

if ( ! defined( 'WP_ORIGIN_PLUGIN_FILE' ) ) {
	define( 'WP_ORIGIN_PLUGIN_FILE', __FILE__ );
}

register_activation_hook( __FILE__, array( 'WP_Origin_Plugin', 'on_activation' ) );

// After the user clicks Activate, send them straight to the seeder
// progress page so they can watch the import without hunting for a
// menu item. Skipped for bulk activations and CLI/AJAX flows so we
// only intercept the one-click admin "Activate" link.
add_action(
	'activated_plugin',
	function ( $plugin ) {
		if ( plugin_basename( WP_ORIGIN_PLUGIN_FILE ) !== $plugin ) {
			return;
		}
		if ( wp_doing_ajax() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $action && 'activate' !== $action ) {
			return;
		}
		wp_safe_redirect( admin_url( 'tools.php?page=' . WP_Origin_Admin::PAGE_SLUG ) );
		exit;
	}
);

WP_Origin_Plugin::bootstrap();
