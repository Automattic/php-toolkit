<?php

namespace WordPress\Blueprints\Steps;

use WordPress\Blueprints\DataReference\File;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Runtime;
use WordPress\ByteStream\WriteStream\FileWriteStream;

use function WordPress\Filesystem\pipe_stream;

/**
 * Represents the 'importContent' step.
 */
class ImportContentStep implements StepInterface {
	private array $content;

	public function __construct( array $content ) {
		$this->content = $content;
	}

	public function run( Runtime $runtime, Tracker $tracker ) {
		$tracker->setCaption( 'Importing content' );
		$progress_import = $tracker->stage( 1.0 );

		$contents = $this->content;

		$total_files = count( $contents );
		if ( $total_files === 0 ) {
			$tracker->finish();

			return true;
		}

		$files_imported = 0;
		$progress_import_step = 1.0 / $total_files;

		foreach ( $contents as $content_definition ) {
			try {
				if ($content_definition['type'] === 'wxr') {
					$this->importWxr($runtime, $content_definition);
				} elseif ($content_definition['type'] === 'posts') {
					$this->importPosts($runtime, $content_definition);
				} else {
					throw new \RuntimeException( 'Unsupported content type: ' . $content_definition['type'] );
				}

				$progress_import->increment( $progress_import_step );
			} catch ( \Exception $e ) {
				// Log error but continue with other content
				error_log( 'Failed to import content: ' . $e->getMessage() );
			}

			$files_imported++;
		}

		$tracker->finish();
	}

	private function importWxr(Runtime $runtime, array $content_definition): void {
		$resolved = $runtime->resolve($content_definition['source']);
		if (!$resolved instanceof File) {
			throw new \RuntimeException('Failed to resolve WXR file.');
		}

		$runtime->withTemporaryFile(function ($tempFile) use ($resolved, $runtime) {
			$write_stream = FileWriteStream::from_path($tempFile);
			pipe_stream($resolved->stream, $write_stream);
			$write_stream->close_writing();

			$runtime->runShellCommand([
				'php',
				'-r',
				<<<'PHP'
				<?php
				require_once getenv('DOCROOT') . '/wp-load.php';
				require_once getenv('DOCROOT') . '/wp-admin/includes/admin.php';
				kses_remove_filters();
				wp_set_current_user(get_current_user_id());
				$importer = new WXR_Importer(['fetch_attachments' => true]);
				$importer->import(getenv('TEMP_FILE'));
				PHP
			], null, [
				'TEMP_FILE' => $tempFile,
			]);
		});
	}

	private function importPosts(Runtime $runtime, array $content_definition): void {
		$posts = $content_definition['source'];
		if (!is_array($posts)) {
			throw new \RuntimeException('Invalid posts data.');
		}

		$runtime->runShellCommand([
			'php',
			'-r',
			<<<'PHP'
			<?php
			require_once getenv('DOCROOT') . '/wp-load.php';
			foreach (json_decode(getenv('POSTS'), true) as $post) {
				wp_insert_post(wp_slash($post));
			}
			PHP
		], null, [
			'POSTS' => json_encode($posts),
		]);
	}
}
