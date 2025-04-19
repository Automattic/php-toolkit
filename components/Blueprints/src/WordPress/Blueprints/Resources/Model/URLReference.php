<?php

namespace WordPress\Blueprints\Resources\Model;

/**
 * Represents a HTTP or HTTPS URL reference.
 */
class URLReference extends DataReference {
	/**
	 * @var string The URL.
	 */
	protected $url;

	/**
	 * Constructor.
	 *
	 * @param string $url The URL.
	 */
	public function __construct( string $url ) {
		$this->url = $url;
	}

	/**
	 * Get the URL.
	 *
	 * @return string The URL.
	 */
	public function get_url(): string {
		return $this->url;
	}

	/**
	 * Check if a string is a valid URL reference.
	 *
	 * @param string $url The URL to check.
	 * @return bool Whether the URL is valid.
	 */
	public static function is_valid( string $url ): bool {
		return strpos( $url, 'http://' ) === 0 || strpos( $url, 'https://' ) === 0;
	}
} 