<?php

namespace WordPress\Blueprints\Model\DataClass;

use WordPress\Blueprints\Resources\Model\DataReference;

class WordPressThemeDirectoryReference extends DataReference {

	const DISCRIMINATOR = 'wordpress.org/theme-directory';

	/**
	 * Identifies the resource as a WordPress Theme Directory reference
	 *
	 * @var string
	 */
	public $resource = 'wordpress.org/theme-directory';

	/**
	 * The slug of the WordPress theme from the directory
	 *
	 * @var string
	 */
	public $slug;

	/**
	 * Optional version of the theme to use
	 *
	 * @var string
	 */
	public $version;

	/**
	 * @param string $resource
	 */
	public function setResource( $resource ) {
		$this->resource = $resource;
		return $this;
	}

	/**
	 * @param string $slug
	 */
	public function setSlug( $slug ) {
		$this->slug = $slug;
		return $this;
	}

	/**
	 * @param string $version
	 */
	public function setVersion( $version ) {
		$this->version = $version;
		return $this;
	}
} 