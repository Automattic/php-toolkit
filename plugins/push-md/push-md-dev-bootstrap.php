<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/push-md-toolkit-loader.php';

if ( ! class_exists( 'Composer\\Autoload\\ClassLoader' ) ) {
	require_once __DIR__ . '/../../vendor/composer/ClassLoader.php';
}

$push_md_loader = new \Composer\Autoload\ClassLoader();

$push_md_classmap = require __DIR__ . '/../../vendor/composer/autoload_classmap.php';
$push_md_loader->addClassMap( $push_md_classmap );

$push_md_psr4 = require __DIR__ . '/../../vendor/composer/autoload_psr4.php';
foreach ( $push_md_psr4 as $push_md_prefix => $push_md_paths ) {
	$push_md_loader->setPsr4( $push_md_prefix, $push_md_paths );
}

$push_md_namespaces = require __DIR__ . '/../../vendor/composer/autoload_namespaces.php';
foreach ( $push_md_namespaces as $push_md_prefix => $push_md_paths ) {
	$push_md_loader->set( $push_md_prefix, $push_md_paths );
}

$push_md_loader->register( true );

$push_md_files = array(
	__DIR__ . '/../../components/DataLiberation/URL/functions.php',
	__DIR__ . '/../../components/Encoding/utf8.php',
	__DIR__ . '/../../components/Encoding/compat-utf8.php',
	__DIR__ . '/../../components/Encoding/utf8-encoder.php',
	__DIR__ . '/../../components/Filesystem/functions.php',
	__DIR__ . '/../../components/Zip/functions.php',
	__DIR__ . '/../../components/Polyfill/mbstring.php',
	__DIR__ . '/../../components/Polyfill/php-functions.php',
	__DIR__ . '/../../components/Git/functions.php',
);

foreach ( $push_md_files as $push_md_file ) {
	push_md_require_toolkit_file( md5( 'push-md:' . $push_md_file ), $push_md_file );
}
