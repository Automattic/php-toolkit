<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/push-md-toolkit-loader.php';

function push_md_load_core_html_api() {
	if ( class_exists( 'WP_HTML_Tag_Processor' ) && class_exists( 'WP_HTML_Processor' ) ) {
		return;
	}

	if ( ! defined( 'WPINC' ) ) {
		return;
	}

	$push_md_html_api_dir   = ABSPATH . WPINC . '/html-api/';
	$push_md_html_api_files = array(
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

	foreach ( $push_md_html_api_files as $push_md_html_api_file ) {
		$push_md_html_api_path = $push_md_html_api_dir . $push_md_html_api_file;
		if ( is_file( $push_md_html_api_path ) ) {
			require_once $push_md_html_api_path;
		}
	}
}

function push_md_load_toolkit_bundle() {
	$push_md_toolkit = __DIR__ . '/php-toolkit';

	push_md_load_core_html_api();

	if ( ! class_exists( 'Composer\\Autoload\\ClassLoader' ) ) {
		require_once $push_md_toolkit . '/vendor/composer/ClassLoader.php';
	}

	$push_md_loader   = new \Composer\Autoload\ClassLoader();
	$push_md_classmap = require $push_md_toolkit . '/vendor/composer/autoload_classmap.php';
	$push_md_loader->addClassMap( $push_md_classmap );

	$push_md_psr4 = require $push_md_toolkit . '/vendor/composer/autoload_psr4.php';
	foreach ( $push_md_psr4 as $push_md_prefix => $push_md_paths ) {
		$push_md_loader->setPsr4( $push_md_prefix, $push_md_paths );
	}

	$push_md_namespaces = require $push_md_toolkit . '/vendor/composer/autoload_namespaces.php';
	foreach ( $push_md_namespaces as $push_md_prefix => $push_md_paths ) {
		$push_md_loader->set( $push_md_prefix, $push_md_paths );
	}

	$push_md_loader->register( true );

	$push_md_files = require $push_md_toolkit . '/vendor/composer/autoload_files.php';
	foreach ( $push_md_files as $push_md_file_identifier => $push_md_file ) {
		push_md_require_toolkit_file( $push_md_file_identifier, $push_md_file );
	}
}

push_md_load_toolkit_bundle();
