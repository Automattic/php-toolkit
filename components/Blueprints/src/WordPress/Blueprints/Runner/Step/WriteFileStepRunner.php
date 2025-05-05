<?php

namespace WordPress\Blueprints\Runner\Step;

use WordPress\Blueprints\Model\DataClass\WriteFileStep;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Resources\Model\DataReference;
use WordPress\Blueprints\Resources\Model\File;
use WordPress\ByteStream\ReadStream\ByteReadStream;

class WriteFileStepRunner extends BaseStepRunner {
	/**
	 * Runs the WriteFile Step
	 *
	 * @param WriteFileStep $input Step.
	 * @param Tracker $progress_tracker Tracker.
	 * @return void
	 */
	public function run(
		WriteFileStep $input,
		Tracker      $progress_tracker = null
	) {
		if ($progress_tracker) {
			$progress_tracker->set(10, 'Writing file...');
		}

		$target_fs = $this->getRuntime()->getTargetFilesystem();
		$path = $input->path;

		// Create directory structure if needed
		$dir = dirname($path);
		if ($dir && $dir !== '/' && $dir !== '.') {
			$target_fs->mkdir($dir, [
				'recursive' => true
			]);
		}

		if (is_string($input->data)) {
			$target_fs->put_contents($path, $input->data);
		} else {
			// Convert to DataReference if not already
			$data_ref = $input->data instanceof DataReference ?
				$input->data :
				DataReference::create($input->data);

			$data_stream = $this->getRuntime()->resolveDataReference($data_ref);
			if (!$data_stream instanceof File) {
				throw new \InvalidArgumentException('The provided resource is not a valid file.');
			}

			// ByteReadStream should be handled directly by the filesystem's put_contents
			$target_fs->put_contents($path, $data_stream->stream->consume_all());
		}

		if ($progress_tracker) {
			$progress_tracker->set(100, 'File written successfully.');
		}
	}

	public function getDefaultCaption($input) {
		return 'Writing file ' . $input->path;
	}
}
