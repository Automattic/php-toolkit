<?php

namespace WordPress\Blueprints;

use Symfony\Component\Process\Process;

class ProcessFailedException extends \Exception {

	/**
	 * @var \Symfony\Component\Process\Process
	 */
	protected $process;

	public function __construct( Process $process, ?\Throwable $previous = null ) {
		$this->process = $process;
		parent::__construct(
			'Process `' . $process->getCommandLine() . '` failed with exit code ' . $process->getExitCode() . " and the following stderr output: \n" . $process->getErrorOutput() . "\n" . $process->getOutput(),
			$process->getExitCode(),
			$previous
		);
	}

	public function getProcess(): Process {
		return $this->process;
	}
}
