<?php

namespace WordPress\Blueprints\Runtime;

use WordPress\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

interface RuntimeInterface {

	/**
	 * @param mixed[]      $command
	 * @param string|null  $cwd
	 * @param mixed[]|null $env
	 * @param float        $timeout
	 */
	public function startProcess(
		$command,
		$cwd = null,
		$env = null,
		$input = null,
		$timeout = 60
	): Process;

	public function getTargetFilesystem(): Filesystem;

	public function evalPhpInSubProcess(
		$code,
		$env = null,
		$input = null,
		$timeout = 60
	);
}
