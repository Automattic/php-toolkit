<?php

namespace WordPress\Filesystem\Mixin;

use WordPress\Filesystem\FilesystemException;

use function WordPress\Filesystem\wp_parent_paths;

/**
 * Implements a recursive mkdir() function by calling mkdir_single() for
 * each non-existing segment of the path.
 */
trait MkdirRecursive {

	public function mkdir( $path, $options = array() ) {
		$recursive = $options['recursive'] ?? false;
		if ( ! $recursive ) {
			$this->mkdir_single( $path, $options );
			return;
		}

		/**
		 * We'll only be checking the subpaths of the filesystem root,
		 * while assuming that the root itself already exists.
		 *
		 * Before we may proceed, we need to confirm our assumption that
		 * $path is within the filesystem root.
		 *
		 * ChrootLayer typically takes care of this. The code below is just
		 * extra sanity checking before we run a bunch of string operations on
		 * $path with the assumption that it started with $root.
		 */
		$root = rtrim( $this->get_root(), '/' ) . '/';
		$path = rtrim( $path, '/' ) . '/';
		if ( ! str_starts_with( $path, $root ) ) {
			throw new FilesystemException( sprintf( 'Path is not within the root: %s', $path ) );
		}

		// Alright, we're sure that $path is within the root. It's time
		// to start iterating over the path segment by segment.

		// Start at the root.
		foreach ( wp_parent_paths(
			$path,
			array(
				'include_self' => true,
			)
		) as $parent_path ) {
			if ( $parent_path === $root ) {
				continue;
			}
			if ( ! $this->exists( $parent_path ) ) {
				$this->mkdir_single( $parent_path, $options );
			}
		}
	}

	abstract protected function get_root(): string;
	abstract protected function mkdir_single( $path, $options = array() );
}
