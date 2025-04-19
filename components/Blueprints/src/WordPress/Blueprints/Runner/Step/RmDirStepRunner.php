<?php

namespace WordPress\Blueprints\Runner\Step;

use WordPress\Blueprints\Model\DataClass\RmDirStep;

class RmDirStepRunner extends BaseStepRunner {

	/**
	 * @param RmDirStep $input
	 */
	public function run( RmDirStep $input ) {
		$this->getRuntime()->getTargetFilesystem()->rmdir( $input->path, [ 'recursive' => $input->recursive ] );
	}
}
