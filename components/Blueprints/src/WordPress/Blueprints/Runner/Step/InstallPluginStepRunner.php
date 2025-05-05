<?php

namespace WordPress\Blueprints\Runner\Step;

use WordPress\Blueprints\Resources\Model\File;
use WordPress\Filesystem\Filesystem;
use WordPress\Filesystem\FilesystemHelpers;
use WordPress\Zip\FileEntry;
use WordPress\Zip\ZipDecoder;
use WordPress\Zip\ZipEncoder;

use function WordPress\Filesystem\copy_between_filesystems;
use function WordPress\Filesystem\pipe_stream;
use function WordPress\Filesystem\wp_join_paths;

class InstallPluginStepRunner extends BaseStepRunner {

	/**
	 * @param \WordPress\Blueprints\Model\DataClass\InstallPluginStep $input
	 * @param \WordPress\Blueprints\Progress\Tracker                  $tracker
	 */
	function run( $input, $tracker ) {
		$fs = $this->getRuntime()->getTargetFilesystem();
		FilesystemHelpers::withTemporaryDirectory($fs, function($temp_dir) use ($fs, $input) {
			$plugin_data = $this->getRuntime()->resolveDataReference( $input->pluginData );
		
			if ( $plugin_data instanceof Filesystem ) {
				/**
				 * A directory. Let's zip it and provide WordPress with a plugin zip file.
				 * 
				 * This may seem silly. Why zip something that may come from a zip file in the first place?
				 * Well, WordPress ships nuanced logic for handling zipped plugins. It supports
				 * different directory layouts, recognizes .__MACOSX and .DS_Store directories,
				 * and so on. If we just copied everything from Filesystem to wp-content/plugins,
				 * we'd need to replicate all that logic. It would come with bugs and
				 * eventually diverge from WordPress's logic in one or more ways. It's
				 * safer to make the computer do a bit more work and rely on WordPress's
				 * own logic.
				 * 
				 * So, we zip it up and provide WordPress with a plugin zip file.
				 */
				$first_php_file = $first_directory = null;
				foreach( $plugin_data->ls() as $filename ) {
					if( !$first_php_file && str_ends_with( $filename, '.php' ) ) {
						$first_php_file = $filename;
					}
					if( !$first_directory && $plugin_data->is_dir( $filename ) && !str_starts_with( $filename, '.' ) ) {
						$first_directory = $filename;
					}
				}
				$zip_filename = substr($first_php_file, 0, -4) ?? $first_directory ?? 'plugin';
				$zip_path = $temp_dir . '/' . $zip_filename . '.zip';
				$zip_stream = $fs->open_write_stream( $zip_path );
				$zip_encoder = new ZipEncoder( $zip_stream );
				$zip_encoder->append_from_filesystem( $plugin_data );
				$zip_encoder->close();
			} elseif ( $plugin_data instanceof File ) {
				// Use ZIP files directly. Wrap other types of files in a ZIP archive.
				$zip_filename = preg_replace('/\.(zip|php)$/', '', $plugin_data->filename) . '.zip';
				$zip_path = $temp_dir . '/' . $zip_filename;
				$zip_stream = $fs->open_write_stream( $zip_path );
	
				$plugin_data->stream->pull( 4 );
				if ( $plugin_data->stream->peek( 4 ) === "PK\x03\x04" ) {
					pipe_stream( $plugin_data->stream, $zip_stream );
				} else {
					$zip_encoder = new ZipEncoder( $zip_stream );
					$zip_encoder->append_file( new FileEntry( [
						'path'              => $plugin_data->filename,
						'body_reader'       => $plugin_data->stream,
						'compressionMethod' => ZipDecoder::COMPRESSION_DEFLATE,
					] ) );
					$zip_encoder->close();
				}
			}
			$zip_stream->close_writing();
		
 			$this->getRuntime()->evalPhpInSubProcess(
				file_get_contents( __DIR__ . '/InstallPlugin/wp_install_plugin.php' ),
				[ 'PLUGIN_ZIP_PATH' => $zip_path, 'OUTPUT_FILE' => $temp_dir . '/plugin_path.txt' ]
			);
			$relative_path = $fs->get_contents($temp_dir . '/plugin_path.txt');

			if ( $input->activate ) {
				$this->getRuntime()->evalPhpInSubProcess(
					file_get_contents( __DIR__ . '/ActivatePlugin/wp_activate_plugin.php' ),
					[ 'PLUGIN_PATH' => $relative_path ]
				);
			}
		}, '');
	}

	public function getDefaultCaption( $input ) {
		return 'Installing plugin ' . $input->pluginZipFile;
	}
}
