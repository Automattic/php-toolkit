<?php

namespace WordPress\Blueprints\references;

use WordPress\Blueprints\RuntimeException;
use WordPress\Blueprints\Model\DataClass\WordPressPluginDirectoryReference;
use WordPress\Blueprints\Model\DataClass\WordPressThemeDirectoryReference;
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
use WordPress\Blueprints\Resources\Model\Directory;
use WordPress\ByteStream\MemoryPipe;
use WordPress\ByteStream\ReadStream\FileReadStream;
use WordPress\ByteStream\WriteStream\FileWriteStream;
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
	 * @return The resolved reference
	 * @throws \Exception If the reference type is unsupported
	 */
	public function resolve( DataReference $reference ): File|Directory {
		if ( $reference instanceof WordPressPluginDirectoryReference ) {
			$reference = new URLReference('https://downloads.wordpress.org/plugin/' . $reference->slug . '.latest-stable.zip');
			var_dump($reference);
		} elseif ( $reference instanceof WordPressThemeDirectoryReference ) {
			$reference = new URLReference('https://downloads.wordpress.org/theme/' . $reference->slug . '.latest-stable.zip');
		}
		
		if ( $reference instanceof URLReference ) {
			$url = $reference->get_url();
			$filename = basename( parse_url( $url, PHP_URL_PATH ) );

			// @TODO: Get the SeekableRequestReadStream to work instead of
			//        pre-emptively buffering the entire file to the disk.

			// @TODO: Don't cache files like this. Memoize downloads to the disk
			//        – probably by adding support for that in the Client class.

			// @TODO: Parallelize the downloads.
			$sha1_hash = hash( 'sha1', $url );
			$tmp_dir = sys_get_temp_dir();
			$temp_file_path = wp_join_paths( $tmp_dir, $sha1_hash . '.zip' );
			if( !file_exists( $temp_file_path ) ) {
				$remote_stream = $this->http_client->fetch( $url );
				$response = $remote_stream->get_response();
				if( $response->status_code !== 200 ) {
					$remote_stream->pull( 50 );
					throw new \RuntimeException( sprintf( 'Failed to download file: %s (status code: %d, response: %s)', 
						$url, 
						$response->status_code, 
						substr( $remote_stream->peek( 50 ), 0, 50 ) 
					) );
				}
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
		
		throw new \Exception( 'Unsupported reference type' );
	}
	
}