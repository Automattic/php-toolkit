<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$wp_origin_toolkit = __DIR__ . '/php-toolkit';

if ( ! class_exists( 'Composer\\Autoload\\ClassLoader' ) ) {
	require_once $wp_origin_toolkit . '/vendor/composer/ClassLoader.php';
}

$wp_origin_loader = new Composer\Autoload\ClassLoader();

$wp_origin_classmap        = require $wp_origin_toolkit . '/vendor/composer/autoload_classmap.php';
$wp_origin_psr_log_path    = $wp_origin_toolkit . '/components/DataLiberation/vendor-patched/psr/log/src/';
$wp_origin_psr_log_classes = array(
	'Psr\\Log\\AbstractLogger'          => 'AbstractLogger.php',
	'Psr\\Log\\InvalidArgumentException' => 'InvalidArgumentException.php',
	'Psr\\Log\\LoggerAwareInterface'    => 'LoggerAwareInterface.php',
	'Psr\\Log\\LoggerAwareTrait'        => 'LoggerAwareTrait.php',
	'Psr\\Log\\LoggerInterface'         => 'LoggerInterface.php',
	'Psr\\Log\\LoggerTrait'             => 'LoggerTrait.php',
	'Psr\\Log\\LogLevel'                => 'LogLevel.php',
	'Psr\\Log\\NullLogger'              => 'NullLogger.php',
);
foreach ( $wp_origin_psr_log_classes as $wp_origin_psr_log_class => $wp_origin_psr_log_file ) {
	$wp_origin_classmap[ $wp_origin_psr_log_class ] = $wp_origin_psr_log_path . $wp_origin_psr_log_file;
}

$wp_origin_loader->addClassMap( $wp_origin_classmap );

$wp_origin_psr4 = require $wp_origin_toolkit . '/vendor/composer/autoload_psr4.php';
foreach ( $wp_origin_psr4 as $prefix => $paths ) {
	$wp_origin_loader->setPsr4( $prefix, $paths );
}

$wp_origin_namespaces = require $wp_origin_toolkit . '/vendor/composer/autoload_namespaces.php';
foreach ( $wp_origin_namespaces as $prefix => $paths ) {
	$wp_origin_loader->set( $prefix, $paths );
}

$wp_origin_loader->register( true );

$wp_origin_files = array(
	$wp_origin_toolkit . '/components/DataLiberation/URL/functions.php',
	$wp_origin_toolkit . '/components/Encoding/utf8.php',
	$wp_origin_toolkit . '/components/Encoding/compat-utf8.php',
	$wp_origin_toolkit . '/components/Encoding/utf8-encoder.php',
	$wp_origin_toolkit . '/components/Filesystem/functions.php',
	$wp_origin_toolkit . '/components/Zip/functions.php',
	$wp_origin_toolkit . '/components/Polyfill/mbstring.php',
	$wp_origin_toolkit . '/components/Polyfill/php-functions.php',
	$wp_origin_toolkit . '/components/Git/functions.php',
);

foreach ( $wp_origin_files as $wp_origin_file ) {
	require_once $wp_origin_file;
}
