<?php

namespace WordPress\Blueprints\Runner\Step;

class RunPHPStepRunner extends BaseStepRunner {
	/**
	 * @param \WordPress\Blueprints\Model\DataClass\RunPHPStep $input
	 * @param \WordPress\Blueprints\Progress\Tracker           $tracker
	 */
	function run( $input, $tracker ) {
		( $nullsafeVariable1 = $tracker ) ? $nullsafeVariable1->setCaption( 'Running custom PHP code' ) : null;

		return $this->getRuntime()->evalPhpInSubProcess( $input->code, [
			'DOCROOT' => $this->getRuntime()->getDocumentRoot(),
		] );
	}
}
