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

	$pmd_html_api_dir   = ABSPATH . WPINC . '/html-api/';
	$pmd_html_api_files = array(
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

	foreach ( $pmd_html_api_files as $pmd_html_api_file ) {
		$pmd_html_api_path = $pmd_html_api_dir . $pmd_html_api_file;
		if ( is_file( $pmd_html_api_path ) ) {
			require_once $pmd_html_api_path;
		}
	}
}

function push_md_load_toolkit_bundle() {
	$pmd_toolkit = __DIR__ . '/php-toolkit';

	push_md_load_core_html_api();

	if ( ! class_exists( 'Composer\\Autoload\\ClassLoader' ) ) {
		require_once $pmd_toolkit . '/vendor/composer/ClassLoader.php';
	}

	$pmd_loader   = new Composer\Autoload\ClassLoader();
	$pmd_classmap = require $pmd_toolkit . '/vendor/composer/autoload_classmap.php';
	$pmd_loader->addClassMap( $pmd_classmap );

	$pmd_psr4 = require $pmd_toolkit . '/vendor/composer/autoload_psr4.php';
	foreach ( $pmd_psr4 as $pmd_prefix => $pmd_paths ) {
		$pmd_loader->setPsr4( $pmd_prefix, $pmd_paths );
	}

	$pmd_namespaces = require $pmd_toolkit . '/vendor/composer/autoload_namespaces.php';
	foreach ( $pmd_namespaces as $pmd_prefix => $pmd_paths ) {
		$pmd_loader->set( $pmd_prefix, $pmd_paths );
	}

	$pmd_loader->register( true );

	$pmd_files = require $pmd_toolkit . '/vendor/composer/autoload_files.php';
	foreach ( $pmd_files as $pmd_file_identifier => $pmd_file ) {
		push_md_require_toolkit_file( $pmd_file_identifier, $pmd_file );
	}
}

push_md_load_toolkit_bundle();
