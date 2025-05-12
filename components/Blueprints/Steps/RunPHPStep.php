<?php

namespace WordPress\Blueprints\Steps;

use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Runtime;

/**
 * Represents the 'runPHP' step.
 */
class RunPHPStep implements StepInterface {
	public ?string $code;
	public ?string $relativeUri;
	public ?string $scriptPath;
	public ?string $protocol;
	public HttpMethod $method;
	/** @var array<string, string>|null */
	public ?array $headers;
	public ?string $body; // Simplified from string | Uint8Array
	/** @var array<string, string>|null */
	public ?array $env;
	/** @var array<string, string>|null */
	public ?array $__SERVER; // Renamed from $__SERVER to avoid PHP superglobal conflict

	/**
	 * @param  string|null  $code  PHP code snippet to run (either code or scriptPath is required).
	 * @param  string|null  $scriptPath  Path to PHP script to run (either code or scriptPath is required).
	 * @param  string|null  $relativeUri  Request path relative to domain.
	 * @param  HttpMethod  $method  HTTP method.
	 * @param  string|null  $protocol  Request protocol (e.g., 'http', 'https').
	 * @param  array<string, string>|null  $headers  Request headers.
	 * @param  string|null  $body  Request body.
	 * @param  array<string, string>|null  $env  Environment variables.
	 * @param  array<string, string>|null  $__SERVER  $__SERVER variables.
	 */
	static public function fromArray( array $data ): self {
		$instance              = new self();
		$instance->code        = $data['code'] ?? null;
		$instance->scriptPath  = $data['scriptPath'] ?? null;
		$instance->relativeUri = $data['relativeUri'] ?? null;
		$instance->method      = $data['method'] ?? 'GET';
		$instance->protocol    = $data['protocol'] ?? null;
		$instance->headers     = $data['headers'] ?? null;
		$instance->body        = $data['body'] ?? null;
		$instance->env         = $data['env'] ?? null;
		$instance->__SERVER    = $data['$_SERVER'] ?? null;

		return $instance;
	}

	public function run( Runtime $runtime, Tracker $tracker ) {
		// @TODO: Use the provided step options
		$tracker->setCaption( 'Running custom PHP code' );
		$runtime->evalPhpInSubProcess( $this->code, [
			'DOCROOT' => $runtime->getConfiguration()->getTargetSiteRoot(),
		] );
	}
}
