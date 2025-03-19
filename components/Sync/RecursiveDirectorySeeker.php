<?php

namespace WordPress\Sync;

use InvalidArgumentException;
use function WordPress\Filesystem\wp_join_paths;
use function WordPress\Filesystem\wp_path_segments;

class RecursiveDirectorySeeker {
	
	private $stack_of_open_directories = array();
	private $current_path;
	private $root;

	public function __construct( string $root ) {
		$this->root = rtrim( realpath( $root ), '/' );
		if ( ! is_dir( $this->root ) ) {
			throw new InvalidArgumentException( "Path specified as a root directory is not a directory: $root" );
		}
		$this->reset();
	}

	public function next_path() {
		while ( $this->stack_of_open_directories ) {
			/**
			 * If the parent stack entry is null, we need to open the directory
			 * handle and skip until the entry we've just popped off the stack.
			 */
			[ $path, $dir ] = end( $this->stack_of_open_directories );
			while ( false !== ( $entry = readdir( $dir ) ) ) {
				if ( $entry === '.' || $entry === '..' ) {
					continue;
				}
				$this->current_path = "$path/$entry";
				/**
				 * This is a depth-first traversal. If we just found a directory, we need to
				 * open it and walk its contents on the next next_path() call.
				 */
				if ( is_dir( $this->current_path) ) {
					$this->stack_of_open_directories[] = array( $this->current_path, opendir( $this->current_path ) );
				}

				return true;
			}
			closedir( $dir );
			array_pop( $this->stack_of_open_directories );

		}
		return false;
	}

	public function get_current_path() {
		return $this->current_path;
	}

	public function seek_to_closest_matching_prefix( string $sought_relative_path ) {
		$this->reset();

		/**
		 * If we can't seek to the exact path, we'll look for the closest
		 * ancestor that exists. For example, if we're looking for /a/b/c
		 * and it doesn't exist, we'll try seeking to /a/b, then /a, then /.
		 */
		while ( true ) {
			$sought_absolute_path = wp_join_paths( $this->root, $sought_relative_path );
			if ( file_exists( $sought_absolute_path ) ) {
				break;
			}
			$sought_relative_path = dirname( $sought_relative_path );
			if ( ! $sought_relative_path || $sought_relative_path === '.' || $sought_relative_path === '/' ) {
				return false; 
			}
		}

		$path_at_stack_top = $this->root;
		$subPathSegments   = wp_path_segments( $sought_relative_path );
		for ( $i = 0; $i < count( $subPathSegments ) - 1; $i ++ ) {
			$path_at_stack_top .= '/' . $subPathSegments[ $i ];
			if ( ! is_dir( $path_at_stack_top ) ) {
				break;
			}

			/**
			 * We don't need to eagerly open directory handles and seek to the
			 * desired entry, but it simplifies the traversing logic so let's
			 * do it until it becomes the bottleneck.
			 */
			$dirhandle = opendir( $path_at_stack_top );
			$next_segment = $subPathSegments[ $i + 1 ];
			$this->seek_to_entry( $dirhandle, $next_segment );
			$this->stack_of_open_directories[] = array( $path_at_stack_top, $dirhandle );
		}
		$this->current_path = $sought_absolute_path;
		if(is_dir($sought_absolute_path)) {
			$this->stack_of_open_directories[] = array( $sought_absolute_path, opendir( $sought_absolute_path ) );
		}
		return true;
	}

	private function seek_to_entry( $dirhandle, string $sought_filename ) {
		while ( false !== ( $found_filename = readdir( $dirhandle ) ) ) {
			if ( $found_filename === $sought_filename ) {
				return true;
			}
		}
	}

	public function reset() {
		foreach ( $this->stack_of_open_directories as $stack_entry ) {
			closedir( $stack_entry[1] );
		}
		$this->stack_of_open_directories = array(
			array( $this->root, opendir( $this->root ) ),
		);
		$this->current_path = $this->root;
	}

}
