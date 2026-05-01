<?php
// phpcs:disable WordPress.Security.ValidatedSanitizedInput,WordPress.WP.AlternativeFunctions -- This router runs before WordPress loads.

if ( ! defined( 'ABSPATH' ) && 'cli-server' !== PHP_SAPI ) {
	exit;
}

// Router for `php -S` running WordPress as the document root.
//
// PHP's built-in webserver does not run .htaccess, does not synthesise
// PHP_AUTH_USER/PHP_AUTH_PW from Authorization headers, and does not
// route unknown paths to index.php. This script fixes all three so
// WordPress's pretty permalinks and Application Password auth work
// against a stock CI install.

if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) && empty( $_SERVER['PHP_AUTH_USER'] ) ) {
	if ( 0 === strncasecmp( $_SERVER['HTTP_AUTHORIZATION'], 'Basic ', 6 ) ) {
		$decoded = base64_decode( substr( $_SERVER['HTTP_AUTHORIZATION'], 6 ), true );
		if ( false !== $decoded && false !== strpos( $decoded, ':' ) ) {
			list( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ) = explode( ':', $decoded, 2 );
		}
	}
}

$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
if ( '/' !== $path && file_exists( getcwd() . $path ) && ! is_dir( getcwd() . $path ) ) {
	return false;
}

require getcwd() . '/index.php';
