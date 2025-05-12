<?php

namespace WordPress\Blueprints;

use Symfony\Component\Process\Process;
use WordPress\Blueprints\DataReference\DataReference;
use WordPress\Blueprints\DataReference\DataReferenceResolver;
use WordPress\Blueprints\DataReference\Directory;
use WordPress\Blueprints\DataReference\File;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Filesystem\Filesystem;
use WordPress\Filesystem\FilesystemHelpers;
use WordPress\HttpClient\Client;

class Runtime {
	public function __construct(
		private Filesystem $targetFs,
		private RunnerConfiguration $configuration,
		private DataReferenceResolver $assets,
		private Client $client,
		private array $blueprint
	) {
	}

	public function getHttpClient(): Client {
		return $this->client;
	}

	public function getBlueprint(): array {
		return $this->blueprint;
	}

	public function getConfiguration(): RunnerConfiguration {
		return $this->configuration;
	}

	public function getTargetFilesystem(): Filesystem {
		return $this->targetFs;
	}

	public function getDataReferenceResolver(): DataReferenceResolver {
		return $this->assets;
	}

	public function resolve( DataReference $r, ?Tracker $progress_tracker = null ): File|Directory {
		return $this->assets->resolve( $r, $progress_tracker );
	}

	public function withTemporaryDirectory( callable $callback ) {
		return FilesystemHelpers::withTemporaryDirectory( $this->targetFs, $callback );
	}

	public function withTemporaryFile( callable $callback, ?string $suffix = null ) {
		return FilesystemHelpers::withTemporaryFile( $this->targetFs, $callback, $suffix );
	}

	/**
	 * @param  mixed[]|null  $env
	 * @param  float  $timeout
	 */
	public function evalPhpInSubProcess(
		$code,
		$env = null,
		$input = null,
		$timeout = 60
	) {
		return $this->withTemporaryFile( function ( $tempFile ) use ( $code, $env, $input, $timeout ) {
			$this->targetFs->put_contents( $tempFile, '<?php $_SERVER["HTTP_HOST"] = "localhost"; ?>' . $code );

			return $this->runShellCommand(
				array(
					'php',
					$tempFile,
				),
				$this->configuration->getTargetSiteRoot(),
				array_merge(
					array(
						'DOCROOT' => $this->configuration->getTargetSiteRoot(),
					),
					$env ?? array()
				),
				$input,
				$timeout
			);
		} );
	}

	/**
	 * @TODO: Migrate from Symfony Process to a more lightweight implementation.
	 * @TODO: Expose stdout and stderr as byte streams.
	 * @TODO: Don't wait until the process terminates. Just return the streams and
	 *        some kind of wait() method for the caller to decide.
	 *
	 * @param  mixed[]  $command
	 * @param  string|null  $cwd
	 * @param  mixed[]|null  $env
	 * @param  float  $timeout
	 */
	public function runShellCommand(
		$command,
		$cwd = null,
		$env = null,
		$input = null,
		$timeout = 60
	) {
		$cwd = $cwd ?? $this->configuration->getTargetSiteRoot();

		$process = new Process(
			$command,
			$cwd,
			$env,
			$input,
			$timeout
		);
		$process->start();
		$process->wait();
		if ( $process->getExitCode() !== 0 ) {
			// @TODO: Don't just echo this here
			echo $process->getErrorOutput();
			throw new ProcessFailedException( $process );
		}

		return $process->getOutput();
	}
}
