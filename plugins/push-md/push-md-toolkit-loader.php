<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function push_md_require_toolkit_file( $push_md_file_identifier, $push_md_file ) {
	if ( ! isset( $GLOBALS['push_md_composer_autoload_files'] ) ) {
		$GLOBALS['push_md_composer_autoload_files'] = array();
	}

	if ( ! empty( $GLOBALS['push_md_composer_autoload_files'][ $push_md_file_identifier ] ) ) {
		return;
	}

	$GLOBALS['push_md_composer_autoload_files'][ $push_md_file_identifier ] = true;

	require_once $push_md_file;
}
