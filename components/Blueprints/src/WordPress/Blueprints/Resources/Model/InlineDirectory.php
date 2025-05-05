<?php

namespace WordPress\Blueprints\Resources\Model;

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
	 * @param string $name     The directory name.
	 * @param array  $children The directory children.
	 */
	public function __construct( string $name, array $children ) {
		$this->name     = $name;
		$this->children = $children;
	}

	/**
	 * Get the directory name.
	 *
	 * @return string The directory name.
	 */
	public function get_name(): string {
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

	/**
	 * Create an instance from an array.
	 *
	 * @param array $data The array data.
	 * @return self The created instance.
	 */
	public static function from_array( array $data ): self {
		if ( ! isset( $data['name'] ) || ! isset( $data['children'] ) || ! is_array( $data['children'] ) ) {
			throw new \InvalidArgumentException( 'Invalid inline directory data' );
		}

		$children = [];
		foreach ( $data['children'] as $child ) {
			if ( InlineFile::is_valid( $child ) ) {
				$children[] = InlineFile::from_array( $child );
			} elseif ( self::is_valid( $child ) ) {
				$children[] = self::from_array( $child );
			} else {
				throw new \InvalidArgumentException( 'Invalid inline directory child' );
			}
		}

		return new self( $data['name'], $children );
	}

	/**
	 * Check if an array represents a valid inline directory.
	 *
	 * @param array $data The array to check.
	 * @return bool Whether the array is valid.
	 */
	public static function is_valid( $data ): bool {
		return is_array( $data ) && isset( $data['name'] ) && isset( $data['children'] ) && is_array( $data['children'] );
	}
} 