<?php

namespace WordPress\Blueprints\Model\DataClass;

use WordPress\Blueprints\Resources\Model\DataReference;

class WordPressPluginDirectoryReference extends DataReference {

	const DISCRIMINATOR = 'wordpress.org/plugin-directory';

	/**
	 * Identifies the resource as a WordPress Plugin Directory reference
	 *
	 * @var string
	 */
	public $resource = 'wordpress.org/plugin-directory';

	/**
	 * The slug of the WordPress plugin from the directory
	 *
	 * @var string
	 */
	public $slug;

	/**
	 * Optional version of the plugin to use
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