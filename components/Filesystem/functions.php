<?php

namespace WordPress\Filesystem;

function ls_recursive( Filesystem $filesystem, $path = '/' ) {
	$tree = array();
	foreach ( $filesystem->ls( $path ) as $item ) {
		if ( $filesystem->is_dir( $item ) ) {
			$tree[] = array(
				'name'     => $item,
				'type'     => 'dir',
				'children' => ls_recursive( $filesystem, $item ),
			);
		} else {
			$tree[] = array(
				'name' => $item,
				'type' => 'file',
			);
		}
	}

	return $tree;
}

function copy_between_filesystems( array $args ) {
	/**
	 * @var Filesystem $source
	 * @var Filesystem $destination
	 */
	$source           = $args['source_filesystem'];
	$source_path      = $args['source_path'] ?? '/';
	$destination      = $args['target_filesystem'];
	$destination_path = $args['target_path'] ?? '/';
	$recursive        = $args['recursive'] ?? true;

	if ( $source->is_file( $source_path ) ) {
		$destination_dir = wp_dirname( $destination_path );
		if ( ! $destination->is_dir( $destination_dir ) ) {
			$destination->mkdir(
				$destination_dir,
				array(
					'recursive' => true,
				)
			);
		}

		$to_stream = $destination->open_write_stream( $destination_path );
		try {
			$from_stream = $source->open_read_stream( $source_path );
			try {
				$chunks_written = 0;
				while ( ! $from_stream->reached_end_of_data() ) {
					$available = $from_stream->pull( 65536 );
					$to_stream->append_bytes( $from_stream->consume( $available ), $to_stream );
					++ $chunks_written;
				}
				if ( $chunks_written === 0 ) {
					// Make sure the file receives at least one chunk
					// so we can be sure it gets created in case the
					// destination filesystem is lazy.
					$to_stream->append_bytes( '' );
				}
			} finally {
				$from_stream->close_reading();
			}
		} finally {
			$to_stream->close_writing();
		}
	} elseif ( $source->is_dir( $source_path ) ) {
		if ( ! $recursive ) {
			throw new FilesystemException( 'Cannot copy a directory. Set the option `recursive` to true to copy directories recursively.' );
		}
		if ( ! $destination->is_dir( $destination_path ) ) {
			$destination->mkdir(
				$destination_path,
				array(
					'recursive' => true,
				)
			);
		}
		foreach ( $source->ls( $source_path ) as $item ) {
			copy_between_filesystems(
				array(
					'source_filesystem' => $source,
					'source_path'       => wp_join_paths( $source_path, $item ),
					'target_filesystem' => $destination,
					'target_path'       => wp_join_paths( $destination_path, $item ),
				)
			);
		}
	} elseif ( $source->exists( $source_path ) ) {
		// For now ignore paths that are neither files nor directories.
		// For example, in GitFilesystem that could be a submodule.
	} else {
		// When a path does not exist, throw a clear error.
		throw new FilesystemException( 'Path does not exist in the source filesystem: ' . $source_path );
	}
}

/**
 * Pipes data from one stream to another.
 *
 * @param  ByteReadStream  $from_stream  The stream to read from.
 * @param  ByteWriteStream  $to_stream  The stream to write to.
 * @param  int  $chunk_size  Optional. The size of chunks to read at a time. Default 65536.
 *
 * @return int The number of chunks written.
 * @throws FilesystemException If there's an error during the transfer.
 */
function pipe_stream( $from_stream, $to_stream, $chunk_size = 65536 ) {
	$chunks_written = 0;
	while ( ! $from_stream->reached_end_of_data() ) {
		$available = $from_stream->pull( $chunk_size );
		$to_stream->append_bytes( $from_stream->consume( $available ) );
		++ $chunks_written;
	}

	if ( $chunks_written === 0 ) {
		// Make sure the file receives at least one chunk
		// so we can be sure it gets created in case the
		// destination filesystem is lazy.
		$to_stream->append_bytes( '' );
		$chunks_written = 1;
	}

	return $chunks_written;
}


function wp_path_segments( $path ) {
	$canonicalized   = wp_canonicalize_path( $path );
	$without_slashes = trim( $canonicalized, '/' );

	return explode( '/', $without_slashes );
}

/**
 * Joins multiple path segments together into a single path.
 *
 * Removes any double slashes between path segments.
 */
function wp_join_paths( ...$path_segments ) {
	$input_starts_with_slash = null;

	$paths = array();
	foreach ( $path_segments as $path_segment ) {
		if ( $path_segment !== '' ) {
			$paths[] = $path_segment;
			if ( null === $input_starts_with_slash ) {
				$input_starts_with_slash = strncmp( $path_segment, '/', strlen( '/' ) ) === 0;
			}
		}
	}
	$path = implode( '/', $paths );

	$result = preg_replace( '#/+#', '/', $path );
	if ( $input_starts_with_slash && strncmp( $result, '/', strlen( '/' ) ) !== 0 ) {
		$result = '/' . $result;
	}

	return $result;
}

/**
 * Resolves a sequence of paths or path segments into an absolute path.
 *
 * The given sequence of paths is processed from right to left, with each
 * subsequent path prepended until an absolute path is constructed. For instance
 * given the sequence of path segments: /foo, /bar, baz, calling
 * wp_resolve_path('/foo', '/bar', 'baz') would return /bar/baz because 'baz'
 * is not an absolute path but '/bar' + '/' + 'baz' is.
 *
 * If, after processing all given path segments, an absolute path has not yet been
 * generated, the current working directory is used.
 *
 * The resulting path is normalized and trailing slashes are removed unless the path is
 * resolved to the root directory.
 *
 * Zero-length path segments are ignored.
 *
 * If no path segments are passed, wp_resolve_path() will return the absolute path of the
 * current working directory.
 *
 * This docstring is sourced from Node.js path.resolve()
 *
 * @param  string[]  $path_segments  The path segments to resolve.
 *
 * @return string The resolved path.
 */
function wp_resolve_path( ...$path_segments ) {

	$last_absolute_segment = null;
	for ( $i = count( $path_segments ) - 1; $i >= 0; $i -- ) {
		if ( strncmp( $path_segments[ $i ], '/', strlen( '/' ) ) === 0 ) {
			$last_absolute_segment = $i;
			break;
		}
	}
	if ( null === $last_absolute_segment ) {
		$last_absolute_segment = 0;
		$path_segments         = array_merge( array( getcwd() ), $path_segments );
	}

	return wp_join_paths( ...array_slice( $path_segments, $last_absolute_segment ) );
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
 * @TODO: Make it windows-safe. Prepending the forward slash breaks paths such as C:/foo/bar.
 * @param  string  $path  The file path that needs cleaning up
 * @return string The cleaned, absolute path
 */
function wp_canonicalize_path( $path ) {
	// Convert to absolute path
	if ( strncmp( $path, '/', strlen( '/' ) ) !== 0 ) {
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
	$result = '/'.ltrim(implode( '/', $normalized ), '/');
	if ( $result === '/.' ) {
		$result = '/';
	}

	return $result === '' ? '/' : $result;
}

/**
 * Returns the directory name of a path. Like dirname(), but
 * consistent between different operating systems. wp_dirname("/foo")
 * will return "/" whereas dirname("/foo") would return "\\".
 *
 * @param  string  $path  The path to get the directory name of.
 *
 * @return string The directory name of the path.
 */
function wp_dirname( $path ) {
	// @TODO: Scrutinize this naive implementation. Could
	// we mess things up on Unix when a directory name
	// legitimately contains a backslash?
	return str_replace( '\\', '/', dirname( $path ) );
}

/**
 * Like sys_get_temp_dir(), but uses forward slashes on Windows.
 */
function wp_sys_get_temp_dir() {
	$path = sys_get_temp_dir();
	if ( DIRECTORY_SEPARATOR === '/' ) {
		$path = str_replace( '\\', '/', $path );
	}
	return $path;
}