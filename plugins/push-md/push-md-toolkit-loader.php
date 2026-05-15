<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function pmd_require_toolkit_file( $pmd_file_identifier, $pmd_file ) {
	if ( ! isset( $GLOBALS['__composer_autoload_files'] ) ) {
		$GLOBALS['__composer_autoload_files'] = array();
	}

	if ( ! empty( $GLOBALS['__composer_autoload_files'][ $pmd_file_identifier ] ) ) {
		return;
	}

	$GLOBALS['__composer_autoload_files'][ $pmd_file_identifier ] = true;

	require_once $pmd_file;
}
