<?php

namespace WordPress\Blueprints\Steps;

use WordPress\Blueprints\DataReference\DataReference;
use WordPress\Blueprints\DataReference\File;
use WordPress\Blueprints\Exception\BlueprintExecutionException;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Runtime;

/**
 * Represents the 'runPHP' step.
 */
class RunPHPStep implements StepInterface {
	public DataReference $code;
	public ?string $scriptPath;
	/** @var array<string, string>|null */
	public ?array $env;
	/** @var array<string, string>|null */
	public ?array $__SERVER; // Renamed from $__SERVER to avoid PHP superglobal conflict

	public function __construct( DataReference $code, ?array $env = null ) {
		$this->code = $code;
		$this->env = $env;
	}

	public function run( Runtime $runtime, Tracker $tracker ) {
		$tracker->setCaption( 'Running custom PHP code' );
		
		$env = $this->env ?? [];
		if ( !empty( $this->__SERVER ?? [] ) ) {
			$env['$_SERVER'] = $this->__SERVER ?? [];
		}
		
		$resolvedCode = $runtime->resolve( $this->code );
		if($resolvedCode instanceof File) {
			$code = $resolvedCode->getStream()->consume_all();
		} else {
			throw new BlueprintExecutionException('The code property must be a File reference.');
		}
		$runtime->evalPhpInSubProcess( $code, $env );
	}
}
