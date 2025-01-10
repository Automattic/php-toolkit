<?php

function wp_join_paths() {
	$paths = array();
	foreach ( func_get_args() as $arg ) {
		if ( $arg !== '' ) {
			$paths[] = $arg;
		}
	}
	$path = implode( '/', $paths );

	return preg_replace( '#/+#', '/', $path );
}

function wp_canonicalize_path( $path ) {
	// Convert to absolute path
	if ( ! str_starts_with( $path, '/' ) ) {
		$path = '/' . $path;
	}

	// Resolve . and ..
	$parts      = explode( '/', $path );
	$normalized = array();
	foreach ( $parts as $part ) {
		if ( $part === '.' || $part === '' ) {
			continue;
		}
		if ( $part === '..' ) {
			array_pop( $normalized );
			continue;
		}
		$normalized[] = $part;
	}

	// Reconstruct path
	$result = '/' . implode( '/', $normalized );
	if ( $result === '/.' ) {
		$result = '/';
	}
	return $result === '' ? '/' : $result;
}
