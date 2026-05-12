<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Composer\\Autoload\\ClassLoader' ) ) {
	require_once __DIR__ . '/../../vendor/composer/ClassLoader.php';
}

$pmd_loader = new Composer\Autoload\ClassLoader();

$pmd_classmap = require __DIR__ . '/../../vendor/composer/autoload_classmap.php';
$pmd_loader->addClassMap( $pmd_classmap );

$pmd_psr4 = require __DIR__ . '/../../vendor/composer/autoload_psr4.php';
foreach ( $pmd_psr4 as $prefix => $paths ) {
	$pmd_loader->setPsr4( $prefix, $paths );
}

$pmd_namespaces = require __DIR__ . '/../../vendor/composer/autoload_namespaces.php';
foreach ( $pmd_namespaces as $prefix => $paths ) {
	$pmd_loader->set( $prefix, $paths );
}

$pmd_loader->register( true );

$pmd_files = array(
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

foreach ( $pmd_files as $pmd_file ) {
	require_once $pmd_file;
}
