<?php

namespace WordPress\Blueprints\Runner\Step;

use WordPress\Blueprints\Resources\Model\File;
use WordPress\Filesystem\Filesystem;
use WordPress\Filesystem\FilesystemHelpers;
use WordPress\Zip\FileEntry;
use WordPress\Zip\ZipDecoder;
use WordPress\Zip\ZipEncoder;

use function WordPress\Filesystem\copy_between_filesystems;
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
			$zip_filename = preg_replace('/\.(zip|php)$/', '', $plugin_data->filename) . '.zip';
			$zip_path = $temp_dir . '/' . $zip_filename;
			$zip_stream = $fs->open_write_stream( $zip_path );
		
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
				 * 
				 * @TODO: See if we can still somehow tell WordPress "hey, this plugin.zip was
				 * extracted to this path, you take over from there".
				 */
				// @TODO: This won't work as ZipEncoder is not a Filesystem.
				//        We'll need to add support for open_write_stream() in ZipFilesystem.
				$zip_writer = new ZipEncoder( $zip_stream );
				copy_between_filesystems( [
					'source_filesystem' => $plugin_data,
					'source_path'       => '/',
					'target_filesystem' => $zip_writer,
					'target_path'       => '/',
					'recursive'         => true,
				] );
				$zip_writer->close();
			} elseif ( $plugin_data instanceof File ) {
				// Use ZIP files directly. Wrap other types of files in a ZIP archive.
				$plugin_data->stream->pull( 4 );
				if ( $plugin_data->stream->peek( 4 ) === "PK\x03\x04" ) {
					while ( $plugin_data->stream->pull( 64 * 1024 ) ) {
						$zip_stream->append_bytes( $plugin_data->stream->consume_all() );
					}
				} else {
					$zip_writer = new ZipEncoder( $zip_stream );
					$zip_writer->append_file( new FileEntry( [
						'path'              => $plugin_data->filename,
						'body_reader'       => $plugin_data->stream,
						'compressionMethod' => ZipDecoder::COMPRESSION_DEFLATE,
					] ) );
					$zip_writer->close();
				}
			}
			$zip_stream->close_writing();
		
 			$this->getRuntime()->evalPhpInSubProcess(
				file_get_contents( __DIR__ . '/InstallPlugin/wp_install_plugin.php' ),
				[ 'PLUGIN_ZIP_PATH' => $zip_path ]
			);

			if ( $input->activate ) {
				$plugin_folder = preg_replace( '/\.zip$/', '', basename( $zip_path ) );
				$this->getRuntime()->evalPhpInSubProcess(
					file_get_contents( __DIR__ . '/ActivatePlugin/wp_activate_plugin.php' ),
					[ 'PLUGIN_PATH' => $plugin_folder ]
				);
			}
		}, '');
	}

	public function getDefaultCaption( $input ) {
		return 'Installing plugin ' . $input->pluginZipFile;
	}
}
