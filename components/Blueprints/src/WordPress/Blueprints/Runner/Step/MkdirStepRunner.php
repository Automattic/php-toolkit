<?php

namespace WordPress\Blueprints\Runner\Step;

use WordPress\Blueprints\Model\DataClass\MkdirStep;

class MkdirStepRunner extends BaseStepRunner {

	/**
	 * @param MkdirStep $input
	 */
	function run( MkdirStep $input ) {
		$filesystem = $this->getRuntime()->getTargetFilesystem();
		
		/**
		 * Fail if the directory already exists to alarm the developer something
		 * about their assumptions is wrong.
		 */
		if ($filesystem->exists($input->path)) {
			throw new \WordPress\Filesystem\FilesystemException(
				sprintf('Path already exists: %s', $input->path)
			);
		}
		$this->getRuntime()->getTargetFilesystem()->mkdir( $input->path, [ 'recursive' => true ] );
	}
}
