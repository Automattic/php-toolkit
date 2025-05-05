<?php
/**
 * @file
 */

namespace WordPress\Blueprints\Runner\Step;

use WordPress\Blueprints\BlueprintException;
use WordPress\Blueprints\Model\DataClass\InstallThemeStep;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Resources\Model\Directory;
use WordPress\Blueprints\Resources\Model\File;
use WordPress\Blueprints\Resources\Resolver\ResourceResolverInterface;
use WordPress\Blueprints\Runtime\RuntimeInterface;
use WordPress\Filesystem\Filesystem;
use WordPress\Filesystem\FilesystemHelpers;
use WordPress\Zip\ZipEncoder;

use function WordPress\Filesystem\copy_between_filesystems;
use function WordPress\Filesystem\pipe_stream;
use function WordPress\Filesystem\wp_join_paths;

class InstallThemeStepRunner extends BaseStepRunner {

	/**
	 * @param InstallThemeStep $input
	 * @param Tracker          $tracker
	 */
	public function run( $input, $tracker ) {
		$tracker?->setCaption( $this->getDefaultCaption( $input ) );
		$fs = $this->getRuntime()->getTargetFilesystem();

		FilesystemHelpers::withTemporaryDirectory($fs, function($temp_dir) use ($fs, $input) {
			$theme_data = $this->getRuntime()->resolveDataReference( $input->themeData );

			if ( $theme_data instanceof Directory ) {
				/**
				 * A directory. Let's zip it and provide WordPress with a theme zip file.
				 * 
				 * This may seem silly. Why zip something that may come from a zip file in the first place?
				 * Well, WordPress ships nuanced logic for handling zipped themes. It supports
				 * different directory layouts, recognizes .__MACOSX and .DS_Store directories,
				 * and so on. If we just copied everything from Filesystem to wp-content/plugins,
				 * we'd need to replicate all that logic. It would come with bugs and
				 * eventually diverge from WordPress's logic in one or more ways. It's
				 * safer to make the computer do a bit more work and rely on WordPress's
				 * own logic.
				 */
				$zip_path = $temp_dir . '/' . $theme_data->dirname . '.zip';
				$zip_stream = $fs->open_write_stream( $zip_path );
				$zip_encoder = new ZipEncoder( $zip_stream );
				$zip_encoder->append_from_filesystem( $theme_data->filesystem );
				$zip_encoder->close();
			} elseif ( $theme_data instanceof File ) {
				// Use ZIP files directly. Wrap other types of files in a ZIP archive.
				$zip_filename = preg_replace('/\.(zip|php)$/', '', $theme_data->filename) . '.zip';
				$zip_path = $temp_dir . '/' . $zip_filename;
				$zip_stream = $fs->open_write_stream( $zip_path );

				$theme_data->stream->pull( 4 );
				if ( $theme_data->stream->peek( 4 ) === "PK\x03\x04" ) {
					pipe_stream( $theme_data->stream, $zip_stream );
				} else {
					throw new BlueprintException( "Theme is not a valid zip file." );
				}
				$zip_stream->close_writing();
			}

			// Run the WordPress script to install the theme using Theme_Upgrader
			$output_file = $temp_dir . '/theme_stylesheet.txt';
			$install_script_result = $this->getRuntime()->evalPhpInSubProcess(
				file_get_contents( __DIR__ . '/InstallTheme/wp_install_theme.php' ),
				[ 'THEME_ZIP_PATH' => $zip_path, 'OUTPUT_FILE' => $output_file ]
			);

			// Check if the output file exists and read the theme stylesheet
			if (!$fs->exists($output_file)) {
				throw new BlueprintException( "Theme installation script did not create output file. Error output: {$install_script_result}" );
			}

			$theme_folder_name = trim($fs->get_contents($output_file));
			if (empty($theme_folder_name)) {
				throw new BlueprintException( "Theme installation script did not return the theme stylesheet name." );
			}

			// Activate the theme if requested
			if ( $input->activate ) {
				$this->getRuntime()->evalPhpInSubProcess(
					file_get_contents( __DIR__ . '/ActivateTheme/wp_activate_theme.php' ),
					[ 'THEME_FOLDER_NAME' => $theme_folder_name ]
				);
			}
		}, '');
	}

	public function getDefaultCaption( $input ): string {
		return 'Installing theme';
	}
}
