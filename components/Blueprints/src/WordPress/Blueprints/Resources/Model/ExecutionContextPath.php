<?php

namespace WordPress\Blueprints\Resources\Model;

/**
 * Represents a path in the Blueprint Execution Context.
 */
class ExecutionContextPath extends DataReference {
	/**
	 * @var string The path.
	 */
	protected $path;

	/**
	 * Constructor.
	 *
	 * @param string $path The path.
	 */
	public function __construct( string $path ) {
		$this->path = $path;
	}

	/**
	 * Get the path.
	 *
	 * @return string The path.
	 */
	public function get_path(): string {
		return $this->path;
	}

	/**
	 * Get the normalized path (without ./ or / prefix).
	 *
	 * @return string The normalized path.
	 */
	public function get_normalized_path(): string {
		$path = $this->path;
		
		// Remove ./ prefix if present
		if ( strpos( $path, './' ) === 0 ) {
			$path = substr( $path, 2 );
		}
		
		// Remove leading / if present
		return ltrim( $path, '/' );
	}

	/**
	 * Check if a string is a valid execution context path.
	 *
	 * @param string $path The path to check.
	 * @return bool Whether the path is valid.
	 */
	public static function is_valid( string $path ): bool {
		return strpos( $path, './' ) === 0 || strpos( $path, '/' ) === 0;
	}
} 