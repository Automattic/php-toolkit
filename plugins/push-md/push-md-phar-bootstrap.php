<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$pmd_phar = 'phar://' . __DIR__ . '/php-toolkit.phar';

if ( ! class_exists( 'Composer\\Autoload\\ClassLoader' ) ) {
	require_once $pmd_phar . '/vendor/composer/ClassLoader.php';
}

$pmd_loader = new Composer\Autoload\ClassLoader();

$pmd_classmap = require $pmd_phar . '/vendor/composer/autoload_classmap.php';
$pmd_loader->addClassMap( $pmd_classmap );

$pmd_psr4 = require $pmd_phar . '/vendor/composer/autoload_psr4.php';
foreach ( $pmd_psr4 as $prefix => $paths ) {
	$pmd_loader->setPsr4( $prefix, $paths );
}

$pmd_namespaces = require $pmd_phar . '/vendor/composer/autoload_namespaces.php';
foreach ( $pmd_namespaces as $prefix => $paths ) {
	$pmd_loader->set( $prefix, $paths );
}

$pmd_loader->register( true );

$pmd_files = array(
	$pmd_phar . '/components/DataLiberation/URL/functions.php',
	$pmd_phar . '/components/Encoding/utf8.php',
	$pmd_phar . '/components/Encoding/compat-utf8.php',
	$pmd_phar . '/components/Encoding/utf8-encoder.php',
	$pmd_phar . '/components/Filesystem/functions.php',
	$pmd_phar . '/components/Zip/functions.php',
	$pmd_phar . '/components/Polyfill/mbstring.php',
	$pmd_phar . '/components/Polyfill/php-functions.php',
	$pmd_phar . '/components/Git/functions.php',
);

foreach ( $pmd_files as $pmd_file ) {
	require_once $pmd_file;
}
