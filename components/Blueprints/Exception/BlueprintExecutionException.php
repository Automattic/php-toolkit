<?php

namespace WordPress\Blueprints\Exception;

use WordPress\Blueprints\Validator\ValidationError;

class BlueprintExecutionException extends \Exception {
	public ?ValidationError $schemaError;

	public function __construct( string $message, ?ValidationError $schemaError = null, ?\Throwable $previous = null ) {
		parent::__construct( $message, 0, $previous );
		$this->schemaError = $schemaError;
	}
}