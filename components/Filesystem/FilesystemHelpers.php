<?php

namespace WordPress\Filesystem;

/**
 * Helper methods for filesystem operations that can be used with any filesystem implementation.
 */
class FilesystemHelpers {

	/**
	 * Creates an empty file at the specified path.
	 *
	 * @param  Filesystem  $fs  The filesystem to use.
	 * @param  string  $path  The path where the file should be created.
	 *
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

}
