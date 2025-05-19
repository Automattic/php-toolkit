<?php

namespace WordPress\Blueprints\Exception;

use WordPress\Blueprints\Validator\ValidationError;

class BlueprintExecutionException extends \Exception {
	/**
     * @var \WordPress\Blueprints\Validator\ValidationError|null
     */
    public $schemaError;

	public function __construct( string $message, $code = 0, ?\Throwable $previous = null, ?ValidationError $schemaError = null ) {
		parent::__construct( $message, $code, $previous );
		$this->schemaError = $schemaError;
	}
}