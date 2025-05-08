<?php

namespace WordPress\Blueprints\Runtime;

use WordPress\Blueprints\Progress\ProgressTrackedReadStream;
use WordPress\Blueprints\Resources\Model\ExecutionContextPath;
use WordPress\Blueprints\Resources\Model\GitPath;
use WordPress\Blueprints\Resources\Model\InlineDirectory;
use WordPress\Blueprints\Resources\Model\InlineFile;
use WordPress\Blueprints\Resources\Model\URLReference;
use WordPress\Blueprints\Resources\Model\WordPressOrgTheme;
use WordPress\ByteStream\MemoryPipe;
use WordPress\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\references\DataReferenceResolver;
use WordPress\Blueprints\Resources\Model\DataReference;
use WordPress\Blueprints\Resources\Model\File;
use WordPress\Blueprints\Resources\Model\Directory;
use WordPress\Blueprints\Resources\Model\WordPressOrgPlugin;
use WordPress\Filesystem\FilesystemHelpers;
use WordPress\Filesystem\InMemoryFilesystem;
use WordPress\Filesystem\Layer\ChrootLayer;
use WordPress\Filesystem\LocalFilesystem;
use WordPress\Git\GitFilesystem;
use WordPress\Git\GitRepository;
use WordPress\HttpClient\ByteStream\RequestReadStream;
use WordPress\HttpClient\Client;
use WordPress\HttpClient\Request;

class Runtime {

	protected $siteFs;
	protected $documentRoot;

	protected $executionContext;
	protected $dataReferences;
	protected $dataResolutionTracker;
	protected $subTrackers;
	protected $http_client;
	protected $resolvedDataReferences;

	public function __construct(
		string $documentRoot,
		Filesystem $executionContext,
		array $dataReferences,
		Tracker $dataResolutionTracker
	) {
		$this->documentRoot          = $documentRoot;
		$this->siteFs                = LocalFilesystem::create( $this->getDocumentRoot() );
		$this->executionContext      = $executionContext;
		$this->http_client           = new Client();
		$this->dataResolutionTracker = $dataResolutionTracker;
		$this->resolvedDataReferences = [];

		$this->dataReferences = $dataReferences;
		$nb_data_references = count( $dataReferences );
		foreach( $dataReferences as $dataReference ) {
			$this->subTrackers[$dataReference->id] = $this->dataResolutionTracker->stage(
				1 / $nb_data_references,
				'Resolving data reference #' . $dataReference->id . ': ' . $dataReference->get_human_readable_name()
			);
		}
	}

	public function getHttpClient(): Client {
		return $this->http_client;
	}

	public function getDocumentRoot(): string {
		return $this->documentRoot;
	}

	/**
	 * This starts eager resolution of all data references, e.g. it starts
	 * fetching HTTP resources in parallel by eagerly initiating RequestReadStreams.
	 */
	public function startResolvingAllDataReferences() {
		foreach( $this->dataReferences as $dataReference ) {
			$this->resolvedDataReferences[$dataReference->id] = $this->resolveReferencedData( $dataReference );
		}
	}

	public function resolveReferencedData( DataReference $reference ): File|Directory {
		if( isset( $this->resolvedDataReferences[$reference->id] ) ) {
			return $this->resolvedDataReferences[$reference->id];
		}

		$progress_tracker = $this->subTrackers[$reference->id];

		if ( $reference instanceof WordPressOrgPlugin ) {
			$reference = new URLReference('https://downloads.wordpress.org/plugin/' . $reference->get_slug() . '.latest-stable.zip');
		} elseif ( $reference instanceof WordPressOrgTheme ) {
			$reference = new URLReference('https://downloads.wordpress.org/theme/' . $reference->get_slug() . '.latest-stable.zip');
		}

		if ( $reference instanceof URLReference ) {
			$url = $reference->get_url();
			$filename = basename( parse_url( $url, PHP_URL_PATH ) );

			// @TODO: Memoize downloads to the disk – probably by adding
			//        disk cache (or even http cache) support to the Client
			//        class.
			$tracked_stream = $this->http_client->fetch(
				$url,
				array(
					/**
					 * Use a 100MB buffer to support seek()-ing in the streamed ZIP files.
					 * To support ZIPs larger than 100MB, we'll need a custom SeekableRequestReadStream that:
					 *
					 * * Uses range headers when possible.
					 * * Buffers data on disk for seeking(), not in memory.
					 */
					'buffer_size' => 100 * 1024 * 1024,
					'progress_tracker' => $progress_tracker,
					'eagerly_enqueue' => true,
				)
			);
			return new File(
				$tracked_stream,
				$filename
			);
		} elseif ( $reference instanceof ExecutionContextPath ) {
			$path = $reference->get_path();
			if( ! $this->executionContext->exists( $path ) ) {
				throw new \RuntimeException( 'File not found: ' . $path );
			}
			if( $this->executionContext->is_file( $path ) ) {
				$stream = $this->executionContext->open_read_stream( $path );
				$tracked_stream = new ProgressTrackedReadStream( $stream, $progress_tracker );
				return new File( $tracked_stream, basename( $path ) );
			} else if( $this->executionContext->is_dir( $path ) ) {
				// @TODO: Actually track the download progress for directories.
				$this->subTrackers[$reference->id]->finish();
				return new Directory(
					new ChrootLayer( $this->executionContext, $path ),
					basename( $path )
				);
			} else {
				throw new \RuntimeException( 'Path is not a file or directory: ' . $path );
			}
		} elseif ( $reference instanceof InlineFile ) {
			$progress_tracker->finish();
			return new File( new MemoryPipe( $reference->get_content() ), $reference->get_filename() );
		} elseif ( $reference instanceof InlineDirectory ) {
			$progress_tracker->finish();
			$fs = InMemoryFilesystem::create();
			/**
			 * @TODO: This can be recursive, we need to support nested directories.
			 */
			foreach( $reference->get_children() as $child ) {
				$fs->put_contents( $child->get_path(), $child->get_content() );
			}
			return new Directory( $fs, $reference->get_name() );
		} elseif ( $reference instanceof GitPath ) {
			// @TODO: Actually track the download progress for git repositories.
			$progress_tracker->finish();

			/**
			 * @TODO: Use a local path as in the Blueprints v2 spec Appendix B.
			 *        Even medium-sized repos can use all the memory.
			 */
			$repo = new GitRepository( InMemoryFilesystem::create() );
			$repo->add_remote( 'origin', $reference->get_git_repository() );
			$remote = $repo->get_remote( 'origin' );
			$remote->pull(
				$reference->get_ref(),
				array(
					'path' => $reference->get_path(),
				)
			);
			return new Directory(
				new GitFilesystem( $repo ),
				basename( $reference->get_path() ) ?: 'git-repo'
			);
		}

		throw new \Exception( 'Unsupported reference type ' . get_class( $reference ) );
	}

	public function getTargetFilesystem(): Filesystem {
		return $this->siteFs;
	}

	public function getExecutionContext(): Filesystem {
		return $this->executionContext;
	}

	public function withTemporaryDirectory( callable $callback ) {
		return FilesystemHelpers::withTemporaryDirectory( $this->siteFs, $callback );
	}

	public function withTemporaryFile( callable $callback, ?string $suffix = null ) {
		return FilesystemHelpers::withTemporaryFile( $this->siteFs, $callback, $suffix );
	}

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
		return $this->withTemporaryFile(function($tempFile) use ($code, $env, $input, $timeout) {
			$this->siteFs->put_contents($tempFile, '<?php $_SERVER["HTTP_HOST"] = "localhost"; ?>' . $code);

			return $this->runShellCommand(
				array(
					'php',
					$tempFile,
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
		});
	}

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
		$cwd = $cwd ?? $this->getDocumentRoot();

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
			throw new ProcessFailedException( $process );
		}

		return $process->getOutput();
	}
}
