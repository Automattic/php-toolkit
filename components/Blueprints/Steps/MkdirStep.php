<?php

namespace WordPress\Blueprints\Steps;

use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Runtime;

/**
 * Represents the 'mkdir' (make directory) step.
 */
class MkdirStep implements StepInterface {
	/**
	 * @var string
	 */
	public $path;

	/**
	 * @param  string  $path  The directory path to create.
	 */
	public function __construct( string $path ) {
		$this->path = $path;
	}

	/**
	 * Executes the mkdir step.
	 */
	public function run( Runtime $runtime, Tracker $tracker ) {
		$tracker->setCaption( 'Creating directory ' . $this->path );
		// @TODO: Throw exception in the LocalFilesystem class if the creation fails
		$runtime->getTargetFilesystem()->mkdir( $this->path, [ 'recursive' => true ] );
	}
}
