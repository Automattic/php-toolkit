<?php

namespace WordPress\Filesystem;

function wp_path_segments($path) {
    $canonicalized = wp_canonicalize_path($path);
    $without_slashes = trim($canonicalized, '/');
    return explode('/', $without_slashes);
}

function wp_parent_paths($path, $options = []) {
    $include_self = $options['include_self'] ?? false;
    $path = '/' . trim($path, '/');
    $segments = wp_path_segments($path);
    $paths = ['/'];
    yield '/';
    for($i = 0; $i < count($segments) - 1; $i++) {
        $paths[] = $segments[$i];
        yield wp_join_paths(...$paths);
    }
    if($include_self) {
        yield $path;
    }
}

/**
 * Joins multiple path segments together into a single path.
 * 
 * Removes any double slashes between path segments.
 */
function wp_join_paths(...$path_segments) {
	$paths = array();
	foreach ( $path_segments as $path_segment ) {
		if ( $path_segment !== '' ) {
			$paths[] = $path_segment;
		}
	}
	$path = implode( '/', $paths );

	return preg_replace( '#/+#', '/', $path );
}

/**
 * Cleans up a file path.
 * 
 * - Ensures it starts with a forward slash
 * - Removes the /./ segments
 * - Flattens the /../ segments
 * 
 * Example:
 * 
 * wp_canonicalize_path( 'foo/bar/../baz' ) => '/foo/baz'
 *
 * @param string $path The file path that needs cleaning up
 * @return string The cleaned, absolute path
 */
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
