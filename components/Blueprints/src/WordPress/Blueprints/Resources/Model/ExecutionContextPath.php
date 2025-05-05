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
	 * Every string is a valid context–relative path.
	 * Most strings are so, for now, we're always returning true.
	 * At this stage, we're not yet concerned whether the file actually
	 * exists. We're only saying "this seems like a local path, let's try
	 * using it as one".
	 *
	 * @param string $path The path to check.
	 * @return bool Whether the path is valid.
	 */
	public static function is_valid(): bool {
		return true;
	}
} 