<?php

namespace WordPress\Filesystem;

/**
 * Helper methods for filesystem operations that can be used with any filesystem implementation.
 */
class FilesystemHelpers {

	/**
	 * Creates an empty file at the specified path.
	 *
	 * @param Filesystem $fs     The filesystem to use.
	 * @param string     $path   The path where the file should be created.
	 * @throws FilesystemException If the file cannot be created.
	 */
	public static function touch( Filesystem $fs, string $path ): void {
		if ( $fs->exists( $path ) ) {
			// File exists, we just need to update its modification time
			// But since we don't have direct access to the underlying filesystem,
			// we'll need to rewrite the file to simulate touch
			$contents = '';
			if ( $fs->is_file( $path ) ) {
				$contents = $fs->get_contents( $path );
			}
			$fs->put_contents( $path, $contents );
			return;
		}

		// File doesn't exist, create an empty file
		$fs->put_contents( $path, '' );
	}

	/**
	 * Creates a temporary file, passes it to a callback function, and cleans it up afterwards.
	 *
	 * @param Filesystem $fs       The filesystem to use.
	 * @param callable   $callback The callback function to execute with the temporary file path.
	 * @param string     $prefix   Optional prefix for the temporary file name.
	 * @param string     $dir      Optional directory to create the temporary file in. Defaults to '/tmp'.
	 * @return mixed               The return value of the callback function.
	 * @throws FilesystemException If the temporary file cannot be created or cleaned up.
	 */
	public static function withTemporaryFile( ?Filesystem $fs = null, callable $callback, string $prefix = 'tmp_', ?string $dir = null ): mixed {
		if ( null === $fs ) {
			$fs = LocalFilesystem::create();
		}
		$tempPath = self::createTemporaryFile( $fs, $prefix, $dir );

		try {
			// Call the callback with the temporary file path
			$result = $callback( $tempPath );
			return $result;
		} finally {
			// Clean up the temporary file regardless of success or failure
			if ( $fs->exists( $tempPath ) ) {
				$fs->rm( $tempPath );
			}
		}
	}

	/**
	 * Creates a temporary file and returns its path.
	 *
	 * @param Filesystem $fs     The filesystem to use.
	 * @param string     $prefix Optional prefix for the temporary file name.
	 * @param string     $dir    Optional directory to create the temporary file in. Defaults to '/tmp'.
	 * @return string            The path to the created temporary file.
	 * @throws FilesystemException If the temporary file cannot be created.
	 */
	public static function createTemporaryFile( ?Filesystem $fs = null, string $prefix = 'tmp_', ?string $dir = null ): string {
		if ( null === $fs ) {
			$fs = LocalFilesystem::create();
		}
		if ( null === $dir ) {
			$dir = sys_get_temp_dir();
		}

		// Create temporary directory if it doesn't exist
		if ( ! $fs->exists( $dir ) ) {
			$fs->mkdir( $dir, [ 'recursive' => true ] );
		}

		// Generate a unique temporary file name
		$tempPath = wp_join_paths( $dir, $prefix . uniqid() );
		
		// Create empty file
		self::touch( $fs, $tempPath );

		return $tempPath;
	}

	/**
	 * Creates a temporary directory, passes it to a callback function, and cleans it up afterwards.
	 *
	 * @param Filesystem $fs       The filesystem to use.
	 * @param callable   $callback The callback function to execute with the temporary directory path.
	 * @param string     $prefix   Optional prefix for the temporary directory name.
	 * @param string     $dir      Optional parent directory to create the temporary directory in. Defaults to '/tmp'.
	 * @return mixed               The return value of the callback function.
	 * @throws FilesystemException If the temporary directory cannot be created or cleaned up.
	 */
	public static function withTemporaryDirectory( ?Filesystem $fs = null, callable $callback, string $prefix = 'tmp_', ?string $dir = null ): mixed {
		if ( null === $fs ) {
			$fs = LocalFilesystem::create();
		}
		if ( null === $dir ) {
			$dir = sys_get_temp_dir();
		}

		// Create parent directory if it doesn't exist
		if ( ! $fs->exists( $dir ) ) {
			$fs->mkdir( $dir, [ 'recursive' => true ] );
		}

		// Generate a unique temporary directory name
		$tempDir = wp_join_paths( $dir, $prefix . uniqid() );
		
		// Create temporary directory
		$fs->mkdir( $tempDir );

		try {
			// Call the callback with the temporary directory path
			$result = $callback( $tempDir );
			return $result;
		} finally {
			// Clean up the temporary directory recursively regardless of success or failure
			if ( $fs->exists( $tempDir ) ) {
				$fs->rmdir( $tempDir, [ 'recursive' => true ] );
			}
		}
	}
} 