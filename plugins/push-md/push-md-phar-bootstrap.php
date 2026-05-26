<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/push-md-toolkit-loader.php';

$push_md_phar = 'phar://' . __DIR__ . '/php-toolkit.phar';

if ( ! class_exists( 'Composer\\Autoload\\ClassLoader' ) ) {
	require_once $push_md_phar . '/vendor/composer/ClassLoader.php';
}

$push_md_loader = new \Composer\Autoload\ClassLoader();

$push_md_classmap = require $push_md_phar . '/vendor/composer/autoload_classmap.php';
$push_md_loader->addClassMap( $push_md_classmap );

$push_md_psr4 = require $push_md_phar . '/vendor/composer/autoload_psr4.php';
foreach ( $push_md_psr4 as $push_md_prefix => $push_md_paths ) {
	$push_md_loader->setPsr4( $push_md_prefix, $push_md_paths );
}

$push_md_namespaces = require $push_md_phar . '/vendor/composer/autoload_namespaces.php';
foreach ( $push_md_namespaces as $push_md_prefix => $push_md_paths ) {
	$push_md_loader->set( $push_md_prefix, $push_md_paths );
}

$push_md_loader->register( true );

$push_md_files = array(
	$push_md_phar . '/components/DataLiberation/URL/functions.php',
	$push_md_phar . '/components/Encoding/utf8.php',
	$push_md_phar . '/components/Encoding/compat-utf8.php',
	$push_md_phar . '/components/Encoding/utf8-encoder.php',
	$push_md_phar . '/components/Filesystem/functions.php',
	$push_md_phar . '/components/Zip/functions.php',
	$push_md_phar . '/components/Polyfill/mbstring.php',
	$push_md_phar . '/components/Polyfill/php-functions.php',
	$push_md_phar . '/components/Git/functions.php',
);

foreach ( $push_md_files as $push_md_file ) {
	push_md_require_toolkit_file( md5( 'push-md:' . $push_md_file ), $push_md_file );
}
