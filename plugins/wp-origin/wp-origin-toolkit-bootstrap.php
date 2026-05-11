<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function wporigin_load_core_html_api() {
	if ( class_exists( 'WP_HTML_Tag_Processor' ) && class_exists( 'WP_HTML_Processor' ) ) {
		return;
	}

	if ( ! defined( 'WPINC' ) ) {
		return;
	}

	$wporigin_html_api_dir   = ABSPATH . WPINC . '/html-api/';
	$wporigin_html_api_files = array(
		'class-wp-html-span.php',
		'class-wp-html-text-replacement.php',
		'class-wp-html-attribute-token.php',
		'class-wp-html-token.php',
		'class-wp-html-decoder.php',
		'class-wp-token-map.php',
		'class-wp-html-tag-processor.php',
		'class-wp-html-processor-state.php',
		'class-wp-html-stack-event.php',
		'class-wp-html-open-elements.php',
		'class-wp-html-active-formatting-elements.php',
		'class-wp-html-doctype-info.php',
		'class-wp-html-unsupported-exception.php',
		'class-wp-html-processor.php',
	);

	foreach ( $wporigin_html_api_files as $wporigin_html_api_file ) {
		$wporigin_html_api_path = $wporigin_html_api_dir . $wporigin_html_api_file;
		if ( is_file( $wporigin_html_api_path ) ) {
			require_once $wporigin_html_api_path;
		}
	}
}

function wporigin_load_toolkit_bundle() {
	$wporigin_toolkit = __DIR__ . '/php-toolkit';

	wporigin_load_core_html_api();

	if ( ! class_exists( 'Composer\\Autoload\\ClassLoader' ) ) {
		require_once $wporigin_toolkit . '/vendor/composer/ClassLoader.php';
	}

	$wporigin_loader   = new Composer\Autoload\ClassLoader();
	$wporigin_classmap = require $wporigin_toolkit . '/vendor/composer/autoload_classmap.php';
	$wporigin_loader->addClassMap( $wporigin_classmap );

	$wporigin_psr4 = require $wporigin_toolkit . '/vendor/composer/autoload_psr4.php';
	foreach ( $wporigin_psr4 as $wporigin_prefix => $wporigin_paths ) {
		$wporigin_loader->setPsr4( $wporigin_prefix, $wporigin_paths );
	}

	$wporigin_namespaces = require $wporigin_toolkit . '/vendor/composer/autoload_namespaces.php';
	foreach ( $wporigin_namespaces as $wporigin_prefix => $wporigin_paths ) {
		$wporigin_loader->set( $wporigin_prefix, $wporigin_paths );
	}

	$wporigin_loader->register( true );

	$wporigin_files = require $wporigin_toolkit . '/vendor/composer/autoload_files.php';
	foreach ( $wporigin_files as $wporigin_file ) {
		require_once $wporigin_file;
	}
}

wporigin_load_toolkit_bundle();
