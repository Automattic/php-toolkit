<?php

namespace WordPress\Blueprints\Runtime;

use WordPress\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use WordPress\Blueprints\references\DataReferenceResolver;
use WordPress\Blueprints\Resources\Model\DataReference;
use WordPress\Blueprints\Resources\Model\File;
use WordPress\Filesystem\FilesystemHelpers;
use WordPress\Filesystem\LocalFilesystem;
use WordPress\HttpClient\Client;

use function WordPress\Filesystem\wp_join_paths;

class Runtime {

	public $fs;
	protected $documentRoot;
	protected $dataReferenceResolver;

	public function __construct(
		string $documentRoot
	) {
		$this->documentRoot = $documentRoot;
		$this->fs           = LocalFilesystem::create( $this->getDocumentRoot() );
		$http_client = new Client();
		$this->dataReferenceResolver = new DataReferenceResolver( $http_client, $this->fs );
	}

	public function getDocumentRoot(): string {
		return $this->documentRoot;
	}

	/**
	 * @param string $path
	 */
	public function resolvePath( $path ): string {
		// @deprecated Use getTargetFilesystem() instead.
		trigger_error( 'Runtime::resolvePath() is deprecated. Use getTargetFilesystem() instead.', E_USER_DEPRECATED );
		return wp_join_paths( $this->getDocumentRoot(), $path );
	}

	public function resolveDataReference( DataReference $reference ): Filesystem|File {
		return $this->dataReferenceResolver->resolve( $reference );
	}

	public function getTargetFilesystem(): Filesystem {
		return $this->fs;
	}

	public function withTemporaryDirectory( callable $callback ) {
		return FilesystemHelpers::withTemporaryDirectory( $this->fs, $callback );
	}

	public function withTemporaryFile( callable $callback, ?string $suffix = null ) {
		return FilesystemHelpers::withTemporaryFile( $this->fs, $callback, $suffix );
	}

	// @TODO: Move this to a separate class
	/**
	 * @param mixed[]|null $env
	 * @param float        $timeout
	 */
	public function evalPhpInSubProcess(
		$code,
		$env = null,
		$input = null,
		$timeout = 60
	) {
		return $this->runShellCommand(
			array(
				'php',
				'-r',
				'?>' . $code,
			),
			null,
			array_merge(
				array(
					'DOCROOT' => $this->getDocumentRoot(),
				),
				$env ?? array()
			),
			$input,
			$timeout
		);
	}

	// @TODO: Move this to a separate class
	/**
	 * @param mixed[]      $command
	 * @param string|null  $cwd
	 * @param mixed[]|null $env
	 * @param float        $timeout
	 */
	public function runShellCommand(
		$command,
		$cwd = null,
		$env = null,
		$input = null,
		$timeout = 60
	) {
		$process = $this->startProcess(
			$command,
			$cwd,
			$env,
			$input,
			$timeout
		);
		$process->start();
		$process->wait();
		if ( $process->getExitCode() !== 0 ) {
			throw new ProcessFailedException( $process );
		}

		return $process->getOutput();
	}

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
	): Process {
		$cwd = $cwd ?? $this->getDocumentRoot();

		return new Process(
			$command,
			$cwd,
			$env,
			$input,
			$timeout
		);
	}
}
