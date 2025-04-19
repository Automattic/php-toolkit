<?php

namespace WordPress\Blueprints\Resources\Model;

/**
 * Represents a file that is inlined within the Blueprint JSON document.
 */
class InlineFile extends DataReference {
	/**
	 * @var string The filename.
	 */
	protected $filename;

	/**
	 * @var string The content.
	 */
	protected $content;

	/**
	 * Constructor.
	 *
	 * @param string $filename The filename.
	 * @param string $content  The content.
	 */
	public function __construct( string $filename, string $content ) {
		$this->filename = $filename;
		$this->content  = $content;
	}

	/**
	 * Get the filename.
	 *
	 * @return string The filename.
	 */
	public function get_filename(): string {
		return $this->filename;
	}

	/**
	 * Get the content.
	 *
	 * @return string The content.
	 */
	public function get_content(): string {
		return $this->content;
	}

	/**
	 * Create an instance from an array.
	 *
	 * @param array $data The array data.
	 * @return self The created instance.
	 */
	public static function from_array( array $data ): self {
		if ( ! isset( $data['filename'] ) || ! isset( $data['content'] ) ) {
			throw new \InvalidArgumentException( 'Invalid inline file data' );
		}

		return new self( $data['filename'], $data['content'] );
	}

	/**
	 * Check if an array represents a valid inline file.
	 *
	 * @param array $data The array to check.
	 * @return bool Whether the array is valid.
	 */
	public static function is_valid( array $data ): bool {
		return isset( $data['filename'] ) && isset( $data['content'] );
	}
} 