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

class EvalResult {
	public function __construct(
		public string $outputFileContent,
		public Process $process
	) {
	}
}

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
	 * Runs the given PHP code in a sub-process. The code has access to:
	 * 
	 * * append_output( $output ): A function that appends a given string to the output file. Useful for
	 *                             separating the returned structured data from PHP warnings and echos.
	 * * DOCROOT environment variable: The path to the WordPress root directory.
	 * * OUTPUT_FILE environment variable: The path to a file where the output of the code will be appended.
	 * 
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
			return $this->withTemporaryFile( function ( $outputFile ) use ( $tempFile, $code, $env, $input, $timeout ) {
				$this->targetFs->put_contents( $tempFile, '<?php 
					function append_output( $output ) {
						file_put_contents( getenv("OUTPUT_FILE"), $output, FILE_APPEND );
					}
					$_SERVER["HTTP_HOST"] = "localhost";
					?>' . $code );

				$process = $this->runShellCommand(
					array(
						'php',
						$tempFile,
					),
					$this->configuration->getTargetSiteRoot(),
					array_merge(
						array(
							'DOCROOT' => $this->configuration->getTargetSiteRoot(),
							'OUTPUT_FILE' => $outputFile,
						),
						$env ?? array()
					),
					$input,
					$timeout
				);
				return new EvalResult(
					$this->targetFs->get_contents( $outputFile ),
					$process
				);
			} );
		} );
	}

	/**
	 * @TODO: Upgrade to the latest Symfony Process version.
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
		$process->mustRun();
		return $process;
	}
}
