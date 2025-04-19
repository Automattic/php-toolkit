<?php

namespace WordPress\Blueprints\Runner\Step;

use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use WordPress\Blueprints\BlueprintException;
use WordPress\Blueprints\Model\DataClass\RmStep;

class RmStepRunner extends BaseStepRunner {

	/**
	 * @param RmStep $input
	 */
	public function run( RmStep $input ) {
		$this->getRuntime()->getTargetFilesystem()->rm( $input->path );
	}
}
