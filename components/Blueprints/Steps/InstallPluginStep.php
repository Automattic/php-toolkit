<?php

namespace WordPress\Blueprints\Steps;

use WordPress\Blueprints\DataReference\DataReference;
use WordPress\Blueprints\DataReference\Directory;
use WordPress\Blueprints\DataReference\File;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Runtime;
use WordPress\Zip\FileEntry;
use WordPress\Zip\ZipDecoder;
use WordPress\Zip\ZipEncoder;

use function WordPress\Filesystem\pipe_stream;
use function WordPress\Zip\is_zip_file_stream;

class InstallPluginStep implements StepInterface {
	/**
	 * Plugin source reference.
	 */
	public DataReference $source;

	/**
	 * Whether to activate the plugin after installation. Defaults to true.
	 */
	public bool $active;

	/**
	 * Optional key-value pairs passed to the plugin during activation.
	 * @var array<string, mixed>|null
	 */
	public ?array $activationOptions;

	/**
	 * Behavior on installation error. Defaults to THROW_ERROR.
	 */
	public string $onError;

	/**
	 * @param  DataReference  $source  Plugin source reference.
	 * @param  bool  $active  Activate after install?
	 * @param  array<string, mixed>|null  $activationOptions  Optional activation data.
	 * @param  PluginErrorBehavior  $onError  Error handling behavior.
	 */
	public function __construct(
		DataReference $source,
		bool $active = true,
		?array $activationOptions = null,
		string $onError = 'throw'
	) {
		$this->source            = $source;
		$this->active            = $active;
		$this->activationOptions = $activationOptions;
		$this->onError           = $onError;
	}

	public function run( Runtime $runtime, Tracker $tracker ) {
		$plugin_data = $runtime->resolve( $this->source );

		$fs = $runtime->getTargetFilesystem();
		$runtime->withTemporaryDirectory( function ( $temp_dir ) use ( $fs, $runtime, $tracker, $plugin_data ) {
			$tracker->setCaption( 'Installing plugin ' . $plugin_data->get_human_readable_name() );
			if ( $plugin_data instanceof Directory ) {
				$zip_path    = $temp_dir . '/' . $plugin_data->dirname . '.zip';
				$zip_stream  = $fs->open_write_stream( $zip_path );
				$zip_encoder = new ZipEncoder( $zip_stream );
				$zip_encoder->append_from_filesystem( $plugin_data->filesystem );
				$zip_encoder->close();
			} elseif ( $plugin_data instanceof File ) {
				$zip_filename = preg_replace( '/\.(zip|php)$/', '', $plugin_data->filename ) . '.zip';
				$zip_path     = $temp_dir . '/' . $zip_filename;
				$zip_stream   = $fs->open_write_stream( $zip_path );

				if ( is_zip_file_stream( $plugin_data->stream ) ) {
					pipe_stream( $plugin_data->stream, $zip_stream );
					$plugin_data->stream->close_reading();
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

			$tracker->set( 50 );
			$runtime->evalPhpInSubProcess(
				file_get_contents( __DIR__ . '/scripts/InstallPlugin/wp_install_plugin.php' ),
				[ 'PLUGIN_ZIP_PATH' => $zip_path, 'OUTPUT_FILE' => $temp_dir . '/plugin_path.txt' ]
			);

			$relative_path = $fs->get_contents( $temp_dir . '/plugin_path.txt' );

			if ( $this->active ) {
				$tracker->set( 75, 'Activating plugin ' . $plugin_data->get_human_readable_name() );
				$runtime->evalPhpInSubProcess(
					file_get_contents( __DIR__ . '/scripts/ActivatePlugin/wp_activate_plugin.php' ),
					[ 'PLUGIN_PATH' => $relative_path ]
				);
			}

			$tracker->set( 100 );
		}, '' );
	}
}
