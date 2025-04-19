<?php
/**
 * @file
 */

namespace WordPress\Blueprints\Runner\Step;

use WordPress\Blueprints\Model\DataClass\MvStep;


class MvStepRunner extends BaseStepRunner {
	/**
	 * @param MvStep $input
	 */
	function run( $input ) {
		$this->getRuntime()->getTargetFilesystem()->rename( $input->fromPath, $input->toPath );
	}
}
