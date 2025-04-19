<?php

namespace WordPress\Blueprints\Runner\Step;

use WordPress\Blueprints\Model\DataClass\UnzipStep;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Resources\Model\File;
use WordPress\Zip\ZipFilesystem;

use function WordPress\Filesystem\copy_between_filesystems;

class UnzipStepRunner extends BaseStepRunner {

	/**
	 * Runs the Unzip Step
	 *
	 * @param UnzipStep $input Step.
	 * @param Tracker $progress_tracker Tracker.
	 * @return void
	 */
	public function run(
		UnzipStep $input,
		Tracker   $progress_tracker
	) {
		$progress_tracker->set( 10, 'Unzipping...' );

		$target_fs = $this->getRuntime()->getTargetFilesystem();
		$zip_stream = $this->getRuntime()->resolveDataReference( $input->zipFile );
		if ( ! $zip_stream instanceof File ) {
			throw new \InvalidArgumentException( 'The provided resource is not a zip file.' );
		}
		$zip_fs = ZipFilesystem::create( $zip_stream->stream );
		copy_between_filesystems([
			'source_filesystem' => $zip_fs,
			'source_path'       => '/',
			'target_filesystem' => $target_fs,
			'target_path'       => $input->extractToPath,
			'recursive'         => true,
		]);
	}
}
