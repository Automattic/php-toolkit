<?php
/**
 * Plugin Name: Push MD
 * Plugin URI: https://pushmd.blog/
 * Description: Edit WordPress content with Git, Markdown and block files, reviewable diffs, and safe pushes.
 * Version: 0.6.5
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
require_once __DIR__ . '/class-push-md-plugin.php';
require_once __DIR__ . '/class-push-md-buffering-response.php';
require_once __DIR__ . '/class-push-md-seeder.php';
require_once __DIR__ . '/class-push-md-admin.php';

if ( ! defined( 'PUSH_MD_PLUGIN_FILE' ) ) {
	define( 'PUSH_MD_PLUGIN_FILE', __FILE__ );
}

register_activation_hook( __FILE__, array( Push_MD_Plugin::class, 'on_activation' ) );

add_filter(
	'plugin_action_links_' . plugin_basename( PUSH_MD_PLUGIN_FILE ),
	function ( $actions ) {
		$actions = array_merge(
			array(
				'push_md_open' => sprintf(
					'<a href="%s">%s</a>',
					esc_url( admin_url( 'tools.php?page=' . Push_MD_Admin::PAGE_SLUG ) ),
					esc_html__( 'Open Push MD', 'push-md' )
				),
			),
			$actions
		);

		$actions['push_md_landing_page'] = sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( 'https://pushmd.blog/' ),
			esc_html__( 'Landing page', 'push-md' )
		);

		return $actions;
	}
);

Push_MD_Plugin::bootstrap();
