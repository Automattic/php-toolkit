<?php

namespace WordPress\Blueprints\DataReference;

use InvalidArgumentException;
use WordPress\Filesystem\Filesystem;
use WordPress\Filesystem\InMemoryFilesystem;

use function WordPress\Filesystem\wp_join_paths;

/**
 * Represents a directory that is inlined within the Blueprint JSON document.
 */
class InlineDirectory extends DataReference {
	/**
	 * @var string The directory name.
	 */
	protected $name;

	/**
	 * @var array The directory children.
	 */
	protected $children;

	/**
	 * Constructor.
	 *
	 * @param  string  $name  The directory name.
	 * @param  array  $children  The directory children.
	 */
	public function __construct( string $name, array $children ) {
		$this->name     = $name;
		$this->children = $children;
		parent::__construct();
	}

	/**
	 * Get the directory name.
	 *
	 * @return string The directory name.
	 */
	public function get_name(): string {
		return $this->name;
	}

	public function get_filename(): string {
		return $this->name;
	}

	/**
	 * Get the directory children.
	 *
	 * @return array The directory children.
	 */
	public function get_children(): array {
		return $this->children;
	}

	public function as_filesystem(): Filesystem {
		$fs = InMemoryFilesystem::create();

		$add_to_fs = function ( $children, $base_path = '' ) use ( &$add_to_fs, $fs ) {
			foreach ( $children as $child ) {
				if ( $child instanceof InlineFile ) {
					$path = wp_join_paths( $base_path, $child->get_filename() );
					$fs->put_contents( $path, $child->get_content() );
				} elseif ( $child instanceof InlineDirectory ) {
					$dir_path = wp_join_paths( $base_path, $child->get_name() );
					$fs->mkdir( $dir_path, [ 'recursive' => true ] );
					$add_to_fs( $child->get_children(), $dir_path );
				}
			}
		};

		$add_to_fs( $this->children );

		return $fs;
	}

	public function as_directory(): Directory {
		return new Directory( $this->as_filesystem(), $this->get_name() );
	}

	/**
	 * Create an instance from an array.
	 *
	 * @param  array  $data  The array data.
	 *
	 * @return self The created instance.
	 */
	public static function from_array( array $data ): self {
		if ( ! isset( $data['name'] ) || ! isset( $data['children'] ) || ! is_array( $data['children'] ) ) {
			throw new InvalidArgumentException( 'Invalid inline directory data' );
		}

		$children = [];
		foreach ( $data['children'] as $child ) {
			if ( InlineFile::is_valid( $child ) ) {
				$children[] = InlineFile::from_blueprint_data( $child );
			} elseif ( self::is_valid( $child ) ) {
				$children[] = self::from_array( $child );
			} else {
				throw new InvalidArgumentException( 'Invalid inline directory child' );
			}
		}

		return new self( $data['name'], $children );
	}

	/**
	 * Check if an array represents a valid inline directory.
	 *
	 * @param  array  $data  The array to check.
	 *
	 * @return bool Whether the array is valid.
	 */
	public static function is_valid( $data ): bool {
		return is_array( $data ) && isset( $data['name'] ) && isset( $data['children'] ) && is_array( $data['children'] );
	}

	/**
	 * Get a human-readable name for this reference.
	 * Used in the progress tracker.
	 *
	 * @return string The human-readable name.
	 */
	public function get_human_readable_name(): string {
		return "Inline directory: " . $this->name;
	}
}
