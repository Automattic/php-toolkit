<?php

namespace WordPress\Blueprints\Steps;

use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Runtime;

/**
 * Represents the 'runPHP' step.
 */
class RunPHPStep implements StepInterface {
	public ?string $code;
	public ?string $scriptPath;
	/** @var array<string, string>|null */
	public ?array $env;
	/** @var array<string, string>|null */
	public ?array $__SERVER; // Renamed from $__SERVER to avoid PHP superglobal conflict

	/**
	 * @param  string|null  $code  PHP code snippet to run (either code or scriptPath is required).
	 * @param  string|null  $scriptPath  Path to PHP script to run (either code or scriptPath is required).
	 * @param  array<string, string>|null  $env  Environment variables.
	 * @param  array<string, string>|null  $__SERVER  $__SERVER variables.
	 */
	static public function fromArray( array $data ): self {
		$instance              = new self();
		$instance->code        = $data['code'] ?? null;
		$instance->scriptPath  = $data['scriptPath'] ?? null;
		$instance->env         = $data['env'] ?? null;
		$instance->__SERVER    = $data['$_SERVER'] ?? null;

		return $instance;
	}

	public function run( Runtime $runtime, Tracker $tracker ) {
		$tracker->setCaption( 'Running custom PHP code' );
		
		$env = $this->env ?? [];
		if ( !empty( $this->__SERVER ?? [] ) ) {
			$env['$_SERVER'] = $this->__SERVER ?? [];
		}
		
		$code = $this->code ?? file_get_contents( $this->scriptPath );
		$runtime->evalPhpInSubProcess( $code, $env );
	}
}
