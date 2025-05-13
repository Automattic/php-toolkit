<?php

namespace WordPress\Blueprints\DataReference;

use WordPress\Blueprints\Progress\ProgressTrackedReadStream;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\ByteStream\MemoryPipe;
use WordPress\Filesystem\Filesystem;
use WordPress\Filesystem\InMemoryFilesystem;
use WordPress\Filesystem\Layer\ChrootLayer;
use WordPress\Git\GitFilesystem;
use WordPress\Git\GitRepository;
use WordPress\HttpClient\Client;

class DataReferenceResolver {
	private array $subTrackers;
	private array $dataReferences;
	private array $resolvedDataReferences;
	private Tracker $dataResolutionTracker;
	private ?Filesystem $executionContext;
	
	public function __construct(private Client $client) {
	}

	public function setExecutionContext( Filesystem $executionContext ) {
		$this->executionContext = $executionContext;
	}

	public function startEagerResolution( array $dataReferences, Tracker $dataResolutionTracker ) {
		$this->dataResolutionTracker = $dataResolutionTracker;
		$this->dataReferences        = $dataReferences;
		$nb_data_references          = count( $this->dataReferences );
		foreach ( $this->dataReferences as $dataReference ) {
			$this->subTrackers[ $dataReference->id ] = $this->dataResolutionTracker->stage(
				1 / $nb_data_references,
				'Resolving data reference #' . $dataReference->id . ': ' . $dataReference->get_human_readable_name()
			);
			$this->resolve( $dataReference, $this->subTrackers[ $dataReference->id ] );
		}
	}

	/** Core service method shared by runner, target resolvers and steps */
	public function resolve( DataReference $reference, ?Tracker $progress_tracker = null ): File|Directory {
		if ( isset( $this->resolvedDataReferences[ $reference->id ] ) ) {
			return $this->resolvedDataReferences[ $reference->id ];
		}

		if ( $progress_tracker === null ) {
			$progress_tracker = $this->subTrackers[ $reference->id ] ?? new Tracker();
		}

		if ( $reference instanceof WordPressOrgPlugin ) {
			$reference = new URLReference( 'https://downloads.wordpress.org/plugin/' . $reference->get_slug() . '.latest-stable.zip' );
		} elseif ( $reference instanceof WordPressOrgTheme ) {
			$reference = new URLReference( 'https://downloads.wordpress.org/theme/' . $reference->get_slug() . '.latest-stable.zip' );
		}

		if ( $reference instanceof URLReference ) {
			$url      = $reference->get_url();
			$filename = basename( parse_url( $url, PHP_URL_PATH ) );

			$tracked_stream = $this->client->fetch(
				$url,
				array(
					/**
					 * Use a 100MB buffer to support seek()-ing in the streamed ZIP files.
					 * To support ZIPs larger than 100MB, we'll need a custom SeekableRequestReadStream that:
					 *
					 * * Uses range headers when possible.
					 * * Buffers data on disk for seeking(), not in memory.
					 * 
					 * @TODO: Support ZIPs >= 100MB.
					 */
					'buffer_size'      => 100 * 1024 * 1024,
					'progress_tracker' => $progress_tracker,
					'eagerly_enqueue'  => true,
				)
			);

			return new File(
				$tracked_stream,
				$filename
			);
		} elseif ( $reference instanceof ExecutionContextPath ) {
			$path = $reference->get_path();
			if ( ! $this->executionContext->exists( $path ) ) {
				throw new \RuntimeException( 'File not found: ' . $path );
			}
			if ( $this->executionContext->is_file( $path ) ) {
				$stream         = $this->executionContext->open_read_stream( $path );
				$tracked_stream = new ProgressTrackedReadStream( $stream, $progress_tracker );

				return new File( $tracked_stream, basename( $path ) );
			} elseif ( $this->executionContext->is_dir( $path ) ) {
				// @TODO (low priority): Actually track the download progress for directories.
				$progress_tracker->finish();

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
			foreach ( $reference->get_children() as $child ) {
				$fs->put_contents( $child->get_path(), $child->get_content() );
			}

			return new Directory( $fs, $reference->get_name() );
		} elseif ( $reference instanceof GitPath ) {
			// @TODO (low priority): Actually track the download progress for git repositories.
			$progress_tracker->finish();

			/**
			 * @TODO: Use a temporary local filesystem for cloning the repo as in the Blueprints v2 spec Appendix B.
			 *        Even medium-sized repos can use all the memory.
			 */
			$repo = new GitRepository( InMemoryFilesystem::create() );
			$repo->add_remote( 'origin', $reference->get_git_repository() );
			$client = $repo->get_remote_client( 'origin' );
			$client->pull(
				$reference->get_ref(),
				// Sparse checkout
				array(
					'path'    => $reference->get_path(),
					'shallow' => true,
				)
			);

			return new Directory(
				new ChrootLayer( GitFilesystem::create( $repo ), $reference->get_path() ),
				basename( $reference->get_path() ) ?: 'git-repo'
			);
		}

		throw new \Exception( 'Unsupported reference type ' . get_class( $reference ) );
	}
}
