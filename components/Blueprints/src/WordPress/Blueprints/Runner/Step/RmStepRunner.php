<?php

namespace WordPress\Blueprints\Runner\Step;

use WordPress\Blueprints\Model\DataClass\RmStep;
use WordPress\Filesystem\FilesystemException;

class RmStepRunner extends BaseStepRunner {

	/**
	 * @param RmStep $input
	 */
	public function run( RmStep $input ) {
		$filesystem = $this->getRuntime()->getTargetFilesystem();
		$path = $input->path;
		
		// Check if path exists
		if (!$filesystem->exists($path)) {
			throw new FilesystemException(sprintf('Path does not exist: %s', $path));
		}
		
		// Different behavior for files and directories
		if ($filesystem->is_dir($path)) {
			$filesystem->rmdir($path, ['recursive' => true]);
		} else {
			$filesystem->rm($path);
		}
	}
}
