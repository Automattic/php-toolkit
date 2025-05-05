<?php

namespace WordPress\Blueprints\references;

use WordPress\Blueprints\BlueprintException;
use WordPress\Blueprints\Resources\Model\DataReference;
use WordPress\HttpClient\Client;
use WordPress\Filesystem\Filesystem;
use WordPress\ByteStream\ReadStream\ByteReadStream;
use WordPress\Blueprints\Resources\Model\URLReference;
use WordPress\Blueprints\Resources\Model\ExecutionContextPath;
use WordPress\Blueprints\Resources\Model\InlineFile;
use WordPress\Blueprints\Resources\Model\InlineDirectory;
use WordPress\Blueprints\Resources\Model\GitPath;
use WordPress\Blueprints\Resources\Model\File;
use WordPress\ByteStream\MemoryPipe;
use WordPress\ByteStream\ReadStream\FileReadStream;
use WordPress\ByteStream\WriteStream\FileWriteStream;
use WordPress\Filesystem\FilesystemHelpers;
use WordPress\Filesystem\InMemoryFilesystem;
use WordPress\Filesystem\Layer\ChrootLayer;
use WordPress\Git\GitFilesystem;
use WordPress\Git\GitRepository;

use function WordPress\Filesystem\pipe_stream;
use function WordPress\Filesystem\wp_join_paths;

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
	 * @return ByteReadStream|Filesystem The resolved reference
	 * @throws \Exception If the reference type is unsupported
	 */
	public function resolve( DataReference $reference ): Filesystem|File {
		if ( $reference instanceof URLReference ) {
			$url = $reference->get_url();
			$filename = basename( parse_url( $url, PHP_URL_PATH ) );

			// @TODO: Get the SeekableRequestReadStream to work instead of
			//        pre-emptively buffering the entire file to the disk.

			// @TODO: Don't cache files like this. Memoize downloads to the disk
			//        – probably by adding support for that in the Client class.
			$sha1_hash = hash( 'sha1', $url );
			$tmp_dir = sys_get_temp_dir();
			$temp_file_path = wp_join_paths( $tmp_dir, $sha1_hash . '.zip' );
			if( !file_exists( $temp_file_path ) ) {
				$remote_stream = $this->http_client->fetch( $url );
				$write_stream = FileWriteStream::from_path( $temp_file_path, 'truncate' );
				pipe_stream( $remote_stream, $write_stream );
				$remote_stream->close_reading();
				$write_stream->close_writing();
			}

			return new File(
				FileReadStream::from_path( $temp_file_path ),
				$filename
			);
		} elseif ( $reference instanceof ExecutionContextPath ) {
			$path = $reference->get_path();
			if( ! $this->blueprint_bundle_fs->exists( $path ) ) {
				throw new BlueprintException( 'File not found: ' . $path );
			}
			if( $this->blueprint_bundle_fs->is_file( $path ) ) {
				return new File( $this->blueprint_bundle_fs->open_read_stream( $path ), basename( $path ) );
			} else if( $this->blueprint_bundle_fs->is_dir( $path ) ) {
				return new ChrootLayer( $this->blueprint_bundle_fs, $path );
			} else {
				throw new BlueprintException( 'Path is not a file or directory: ' . $path );
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
			return $fs;
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
			return new GitFilesystem( $repo );
		}
		
		throw new \Exception( 'Unsupported reference type' );
	}
	
}