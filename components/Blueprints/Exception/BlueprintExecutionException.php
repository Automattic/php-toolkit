<?php

namespace WordPress\Blueprints\Exception;

use WordPress\Blueprints\Validator\ValidationError;

class BlueprintExecutionException extends \Exception {
	public ?ValidationError $schemaError;

	public function __construct( string $message, ?ValidationError $schemaError = null ) {
		parent::__construct( $message );
		$this->schemaError = $schemaError;
	}
}