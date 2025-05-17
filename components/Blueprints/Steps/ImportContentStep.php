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

	public function run( Runtime $runtime, Tracker $progress ) {
		$progress->setCaption( 'Importing content' );

		$contents = $this->content;

		$total_files = count( $contents );
		if ( $total_files === 0 ) {
			$progress->finish();

			return true;
		}

		$files_imported = 0;
		$progress->split($total_files);

		foreach ( $contents as $i => $content_definition ) {
			try {
				if ($content_definition['type'] === 'wxr') {
					// @TODO: More useful captions – include the url
					$progress[$i]->setCaption( 'Importing WXR file ' );
					$this->importWxr($runtime, $content_definition);
				} elseif ($content_definition['type'] === 'posts') {
					$progress[$i]->setCaption( 'Importing a post ' );
					$this->importPosts($runtime, $content_definition);
				} else {
					throw new \RuntimeException( 'Unsupported content type: ' . $content_definition['type'] );
				}

				$progress[$i]->finish();
			} catch ( \Exception $e ) {
				// Log error but continue with other content
				error_log( 'Failed to import content: ' . $e->getMessage() );
			}

			$files_imported++;
		}

		$progress->finish();
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

			$runtime->evalPhpInSubProcess(
				<<<'PHP'
				<?php
				require_once getenv('DOCROOT') . '/wp-load.php';
				require_once getenv('DOCROOT') . '/wp-admin/includes/admin.php';
				kses_remove_filters();
				wp_set_current_user(get_current_user_id());
				$importer = new WXR_Importer(['fetch_attachments' => true]);
				$importer->import(getenv('TEMP_FILE'));
				PHP,
				[
					'TEMP_FILE' => $tempFile,
				]
			);
		});
	}

	private function importPosts(Runtime $runtime, array $content_definition): void {
		$posts = $content_definition['source'];
		if (!is_array($posts)) {
			throw new \RuntimeException('Invalid posts data.');
		}

		$runtime->evalPhpInSubProcess(
			<<<'PHP'
			<?php
			require_once getenv('DOCROOT') . '/wp-load.php';
			foreach (json_decode(getenv('POSTS'), true) as $post) {
				wp_insert_post(wp_slash($post));
			}
			PHP,
			[
				'POSTS' => json_encode($posts),
			]
		);
	}
}
