<?php
/**
 * @file
 */

namespace WordPress\Blueprints\Runner\Step;

use WordPress\Blueprints\BlueprintException;
use WordPress\Blueprints\Model\DataClass\InstallThemeStep;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Resources\Model\File;
use WordPress\Blueprints\Resources\Resolver\ResourceResolverInterface;
use WordPress\Blueprints\Runtime\RuntimeInterface;
use WordPress\Filesystem\Filesystem;
use WordPress\Zip\ZipEncoder;

use function WordPress\Filesystem\copy_between_filesystems;

class InstallThemeStepRunner extends BaseStepRunner {

	/**
	 * @param InstallThemeStep $input
	 * @param Tracker          $tracker
	 */
	public function run( $input, $tracker ) {
		$tracker?->setCaption( $this->getDefaultCaption( $input ) );

		// @TODO get a temp directory, don't leave temp files dangling around
		$zip_path = '/' . uniqid( 'plugin_', true ) . '.zip';
		$zip_stream = $this->getRuntime()->getTargetFilesystem()->open_write_stream( $zip_path );
	
		$theme_data = $this->getRuntime()->resolveDataReference( $input->themeData );
	
		if ( $theme_data instanceof Filesystem ) {
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
			 * 
			 * So, we zip it up and provide WordPress with a plugin zip file.
			 * 
			 * @TODO: See if we can still somehow tell WordPress "hey, this plugin.zip was
			 * extracted to this path, you take over from there".
			 */
			$zip_writer = new ZipEncoder( $zip_stream );
			copy_between_filesystems( [
				'source_filesystem' => $theme_data,
				'source_path'       => '/',
				'target_filesystem' => $zip_writer,
				'target_path'       => '/',
				'recursive'         => true,
			] );
			$zip_writer->close();
		} elseif ( $theme_data instanceof File ) {
			if ( ! $theme_data->stream->peek( 4 ) === "PK\x03\x04" ) {
				throw new BlueprintException( "Theme is not a valid zip file." );
			}
			while ( $theme_data->stream->pull( 64 * 1024 ) ) {
				$zip_stream->append_bytes( $theme_data->stream->consume_all() );
			}
		}
		$zip_stream->close_writing();

		try {
			// Run the WordPress script to install the theme using Theme_Upgrader
			$install_script_result = $this->getRuntime()->evalPhpInSubProcess(
				file_get_contents( __DIR__ . '/InstallTheme/wp_install_theme.php' ),
				[ 'THEME_ZIP_PATH' => $zip_path ]
			);

			// Check if the installation script itself failed
			if ( $install_script_result->exit_code !== 0 ) {
				// The script wp_install_theme.php should have logged the specific error.
				// We throw a generic error here indicating the script execution failure.
				throw new BlueprintException( "Failed to execute theme installation script. Exit code: {$install_script_result->exit_code}. Error output: {$install_script_result->stderr}" );
			}

			// The installation script outputs the theme stylesheet (directory name) on success.
			$theme_stylesheet = trim( $install_script_result->stdout );
			if ( empty( $theme_stylesheet ) ) {
				// This indicates an issue if the script exited successfully but didn't output the stylesheet.
				throw new BlueprintException( "Theme installation script finished successfully but did not return the theme stylesheet name. Error output: {$install_script_result->stderr}" );
			}

			// Activate the theme if requested
			if ( $input->activate ) {
				// @TODO: Consider creating a dedicated wp_activate_theme.php script similar to wp_activate_plugin.php
				// For now, assuming a similar script exists or adapting the plugin activation logic.
				// Let's assume an ActivateTheme/wp_activate_theme.php script exists that takes THEME_STYLESHEET.
				$activate_script_path = __DIR__ . '/ActivateTheme/wp_activate_theme.php';
				if ( ! file_exists( $activate_script_path ) ) {
					// Fallback or error if the activation script doesn't exist
					// For now, we'll throw an error, as activation is requested but cannot be performed.
					// Alternatively, we could log a warning and skip activation.
					throw new BlueprintException( "Theme activation script not found at {$activate_script_path}" );
					// @TODO: Implement wp_activate_theme.php
				}

				$activation_result = $this->getRuntime()->evalPhpInSubProcess(
					file_get_contents( $activate_script_path ),
					[ 'THEME_STYLESHEET' => $theme_stylesheet ]
				);

				// Check if the activation script failed
				if ( $activation_result->exit_code !== 0 ) {
					throw new BlueprintException( "Failed to execute theme activation script for stylesheet '{$theme_stylesheet}'. Exit code: {$activation_result->exit_code}. Error output: {$activation_result->stderr}" );
				}
			}
		} finally {
			// Clean up the temporary zip file
			$this->getRuntime()->getTargetFilesystem()->rm( $zip_path );
		}
	}

	public function getDefaultCaption( $input ): string {
		// @TODO: Determine the theme name from the resource if possible for a better caption.
		return 'Installing theme';
	}
}
