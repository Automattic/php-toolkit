<?php

namespace WordPress\Blueprints\Steps;

use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Runtime;

/**
 * Represents the 'wp-cli' step.
 */
class WPCLIStep implements StepInterface {
	/**
	 * The WP-CLI command arguments string (e.g., "plugin install woocommerce --activate").
	 */
	public string $command;

	/**
	 * Optional path to the WP-CLI executable.
	 */
	public ?string $wpCliPath;

	/**
	 * @param  string  $command  The WP-CLI command string.
	 * @param  string|null  $wpCliPath  Optional path to WP-CLI executable.
	 */
	public function __construct( string $command, ?string $wpCliPath = null ) {
		$this->command   = $command;
		$this->wpCliPath = $wpCliPath;
	}

	public function run( Runtime $runtime, Tracker $tracker ) {
		$tracker->setCaption( 'Running WP-CLI command: ' . $this->command );
		$runtime->runShellCommand( $this->command );
	}
}
