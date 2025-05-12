<?php

namespace WordPress\Blueprints\Steps;

use WordPress\Blueprints\DataReference\DataReference;
use WordPress\Blueprints\DataReference\File;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Runtime;

/**
 * Represents the 'runSql' step.
 */
class RunSqlStep implements StepInterface {
	/**
	 * SQL source identifier (URL, ./path, /path).
	 */
	public DataReference $source;

	/**
	 * @param  DataReference  $source  SQL source identifier.
	 */
	public function __construct( DataReference $source ) {
		$this->source = $source;
	}

	public function run( Runtime $runtime, Tracker $tracker ) {
		$tracker->setCaption( 'Running SQL queries' );

		// Get the data reference for the SQL file
		$sql = $runtime->resolve( $this->source );

		if ( ! $sql instanceof File ) {
			throw new \InvalidArgumentException( 'The provided resource is not a file.' );
		}

		$runtime->evalPhpInSubProcess(
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
}
