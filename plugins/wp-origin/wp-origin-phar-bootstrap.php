<?php

$wp_origin_phar = 'phar://' . __DIR__ . '/php-toolkit.phar';

if ( ! class_exists( 'Composer\\Autoload\\ClassLoader' ) ) {
	require_once $wp_origin_phar . '/vendor/composer/ClassLoader.php';
}

$wp_origin_loader = new Composer\Autoload\ClassLoader();

$wp_origin_classmap = require $wp_origin_phar . '/vendor/composer/autoload_classmap.php';
$wp_origin_loader->addClassMap( $wp_origin_classmap );

$wp_origin_psr4 = require $wp_origin_phar . '/vendor/composer/autoload_psr4.php';
foreach ( $wp_origin_psr4 as $prefix => $paths ) {
	$wp_origin_loader->setPsr4( $prefix, $paths );
}

$wp_origin_namespaces = require $wp_origin_phar . '/vendor/composer/autoload_namespaces.php';
foreach ( $wp_origin_namespaces as $prefix => $paths ) {
	$wp_origin_loader->set( $prefix, $paths );
}

$wp_origin_loader->register( true );

$wp_origin_files = array(
	$wp_origin_phar . '/components/DataLiberation/URL/functions.php',
	$wp_origin_phar . '/components/Encoding/utf8.php',
	$wp_origin_phar . '/components/Encoding/compat-utf8.php',
	$wp_origin_phar . '/components/Encoding/utf8-encoder.php',
	$wp_origin_phar . '/components/Filesystem/functions.php',
	$wp_origin_phar . '/components/Zip/functions.php',
	$wp_origin_phar . '/components/Polyfill/mbstring.php',
	$wp_origin_phar . '/components/Polyfill/php-functions.php',
	$wp_origin_phar . '/components/Git/functions.php',
);

foreach ( $wp_origin_files as $wp_origin_file ) {
	require_once $wp_origin_file;
}
