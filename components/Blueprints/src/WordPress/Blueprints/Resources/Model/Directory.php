<?php

namespace WordPress\Blueprints\Resources\Model;

use WordPress\Filesystem\Filesystem;

/**
 * Represents a directory-like object that encapsulates a filesystem.
 * Similar to File, but for directories.
 */
class Directory extends DataReference {
	/**
	 * @var Filesystem The filesystem representing the directory content.
	 */
	public $filesystem;

	/**
	 * @var string The name of the directory.
	 */
	public $dirname;

	/**
	 * Constructor.
	 *
	 * @param Filesystem $filesystem The filesystem representing the directory content.
	 * @param string     $dirname   The name of the directory.
	 */
	public function __construct( Filesystem $filesystem, string $dirname ) {
		$this->filesystem = $filesystem;
		$this->dirname    = $dirname;
	}
}
