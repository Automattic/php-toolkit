<?php

namespace WordPress\Blueprints\references;

use WordPress\Blueprints\Progress\ProgressTrackedReadStream;
use WordPress\Blueprints\RuntimeException;
use WordPress\Blueprints\Resources\Model\DataReference;
use WordPress\HttpClient\Client;
use WordPress\Filesystem\Filesystem;
use WordPress\Blueprints\Resources\Model\URLReference;
use WordPress\Blueprints\Resources\Model\ExecutionContextPath;
use WordPress\Blueprints\Resources\Model\InlineFile;
use WordPress\Blueprints\Resources\Model\InlineDirectory;
use WordPress\Blueprints\Resources\Model\GitPath;
use WordPress\Blueprints\Resources\Model\File;
use WordPress\Blueprints\Resources\Model\Directory;
use WordPress\Blueprints\Resources\Model\WordPressOrgPlugin;
use WordPress\Blueprints\Resources\Model\WordPressOrgTheme;
use WordPress\ByteStream\MemoryPipe;
use WordPress\Filesystem\InMemoryFilesystem;
use WordPress\Filesystem\Layer\ChrootLayer;
use WordPress\Git\GitFilesystem;
use WordPress\Git\GitRepository;
use WordPress\HttpClient\ByteStream\RequestReadStream;
use WordPress\HttpClient\Request;

class DataReferenceResolver {

	protected $http_client;
	protected $blueprint_bundle_fs;

	public function __construct(
		Client $http_client,
		Filesystem $blueprint_bundle_fs
	) {
		$this->http_client = $http_client;
		$this->blueprint_bundle_fs = $blueprint_bundle_fs;
	}

	/**
	 * Resolves a data reference into either a ByteReadStream or Filesystem instance.
	 *
	 * @param mixed $reference The reference to resolve (string or array)
	 * @return File|Directory The resolved reference
	 * @throws \Exception If the reference type is unsupported
	 */
	public function resolve( DataReference $reference ): File|Directory {
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
			$stream = new RequestReadStream(
				new Request( $url ),
				array(
					'client' => $this->http_client,
					/**
					 * Use a 100MB buffer to support seek()-ing in the streamed ZIP files.
					 * To support ZIPs larger than 100MB, we'll need a custom SeekableRequestReadStream that:
					 *
					 * * Uses range headers when possible.
					 * * Buffers data on disk for seeking(), not in memory.
					 */
					'buffer_size' => 100 * 1024 * 1024
				)
			);
			$tracked_stream = new ProgressTrackedReadStream( $stream, $tracker );
			return new File(
				$tracked_stream,
				$filename
			);
		} elseif ( $reference instanceof ExecutionContextPath ) {
			$path = $reference->get_path();
			if( ! $this->blueprint_bundle_fs->exists( $path ) ) {
				throw new \RuntimeException( 'File not found: ' . $path );
			}
			if( $this->blueprint_bundle_fs->is_file( $path ) ) {
				return new File( $this->blueprint_bundle_fs->open_read_stream( $path ), basename( $path ) );
			} else if( $this->blueprint_bundle_fs->is_dir( $path ) ) {
				return new Directory(
					new ChrootLayer( $this->blueprint_bundle_fs, $path ),
					basename( $path )
				);
			} else {
				throw new \RuntimeException( 'Path is not a file or directory: ' . $path );
			}
		} elseif ( $reference instanceof InlineFile ) {
			return new File( new MemoryPipe( $reference->get_content() ), $reference->get_filename() );
		} elseif ( $reference instanceof InlineDirectory ) {
			$fs = InMemoryFilesystem::create();
			/**
			 * @TODO: This can be recursive, we need to support nested directories.
			 */
			foreach( $reference->get_children() as $child ) {
				$fs->put_contents( $child->get_path(), $child->get_content() );
			}
			return new Directory( $fs, $reference->get_name() );
		} elseif ( $reference instanceof GitPath ) {
			/**
			 * @TODO: Use a local path as in the Blueprints v2 spec Appendix B.
			 *        Even medium-sized repos can use all the memory.
			 */
			$repo = new GitRepository( new InMemoryFilesystem() );
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
	
}