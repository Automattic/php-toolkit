<?php

namespace WordPress\Blueprints\Runner\Step;

use WordPress\Blueprints\Model\DataClass\CpStep;


class CpStepRunner extends BaseStepRunner {
	/**
	 * @param CpStep $input
	 */
	function run( $input ) {
		$this->getRuntime()->getTargetFilesystem()->copy( $input->fromPath, $input->toPath, [
			'recursive' => $input->recursive,
		] );
	}
}
