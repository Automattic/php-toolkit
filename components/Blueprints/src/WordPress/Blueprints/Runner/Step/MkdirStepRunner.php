<?php

namespace WordPress\Blueprints\Runner\Step;

use WordPress\Blueprints\Model\DataClass\MkdirStep;

class MkdirStepRunner extends BaseStepRunner {

	/**
	 * @param MkdirStep $input
	 */
	function run( MkdirStep $input ) {
		$this->getRuntime()->getTargetFilesystem()->mkdir( $input->path );
	}
}
