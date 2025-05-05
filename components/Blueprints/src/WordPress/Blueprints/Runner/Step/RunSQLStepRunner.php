<?php
/**
 * @file
 */

namespace WordPress\Blueprints\Runner\Step;

use WordPress\Blueprints\Model\DataClass\RunSQLStep;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Resources\Model\File;

class RunSQLStepRunner extends BaseStepRunner {
	/**
	 * @param RunSQLStep                                  $input
	 * @param \WordPress\Blueprints\Progress\Tracker|null $progress
	 */
	function run(
		$input,
		$progress = null
	) {
		$sql = $this->getRuntime()->resolveDataReference( $input->sql );
		if ( ! $sql instanceof File ) {
			throw new \InvalidArgumentException( 'The provided resource is not a file.' );
		}
		return $this->getRuntime()->evalPhpInSubProcess(
			<<<'CODE'
<?php
		require_once getenv("DOCROOT") . '/wp-load.php';

		$handle = STDIN;
		$buffer = '';

		global $wpdb;
		while ($bytes = fgets($handle)) {
			$buffer .= $bytes;

			if (!feof($handle) && substr($buffer, -1, 1) !== "\n") {
				continue;
			}

			$wpdb->query($buffer);
			$buffer = '';
		}
		fclose($handle);
CODE
			,
			null,
			$sql->stream->consume_all()
		);
	}

	public function getDefaultCaption( $input ) {
		return 'Running SQL queries';
	}
}
