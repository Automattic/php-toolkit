<?php

namespace WordPress\Blueprints;

use Symfony\Component\Process\Process;
use WordPress\Blueprints\DataReference\DataReference;
use WordPress\Blueprints\DataReference\DataReferenceResolver;
use WordPress\Blueprints\DataReference\Directory;
use WordPress\Blueprints\DataReference\File;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Filesystem\Filesystem;
use WordPress\Filesystem\LocalFilesystem;
use WordPress\HttpClient\Client;

use function WordPress\Filesystem\wp_join_paths;

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
		private array $blueprint,
		private callable $logWarning,
		private string $tempRoot
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

	public function getTempRoot(): string {
		return $this->tempRoot;
	}

	public function getDataReferenceResolver(): DataReferenceResolver {
		return $this->assets;
	}

	public function resolve( DataReference $r, ?Tracker $progress_tracker = null ): File|Directory {
		return $this->assets->resolve( $r, $progress_tracker );
	}

	public function logWarning( string $message ) {
		call_user_func( $this->logWarning, $message );
	}

	public function withTemporaryDirectory( callable $callback ) {
		$tmp = $this->createTemporaryDirectory();
		try {
			return $callback( $tmp );
		} finally {
			LocalFilesystem::create( $tmp )->rmdir( '/', [ 'recursive' => true ] );
		}
	}

	public function createTemporaryDirectory(): string {
		do {
			$dirname = wp_join_paths( $this->tempRoot, uniqid( 'tmp_' ) );
		} while ( file_exists( $dirname ) );

		mkdir( $dirname, 0777, true );
		return $dirname;
	}

	public function withTemporaryFile( callable $callback, ?string $suffix = null ) {
		$tempFile = $this->createTemporaryFile( $suffix );
		try {
			return $callback( $tempFile );
		} finally {
			@unlink( $tempFile );
		}
	}

	public function createTemporaryFile( ?string $suffix = null ): string {
		do {
			$filename = wp_join_paths( $this->tempRoot, uniqid( $suffix ?? 'tmp_' ) );
		} while ( file_exists( $filename ) );

		touch( $filename );
		return $filename;
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
				file_put_contents(
					$tempFile,
					'<?php 
					function append_output( $output ) {
						file_put_contents( getenv("OUTPUT_FILE"), $output, FILE_APPEND );
					}
					$_SERVER["HTTP_HOST"] = "localhost";
					?>' . $code
				);

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
					file_get_contents( $outputFile ),
					$process
				);
			} );
		} );
	}

	/**
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
