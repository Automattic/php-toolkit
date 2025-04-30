<?php

namespace unit\steps;

use PHPUnitTestCase;
use WordPress\Blueprints\Model\DataClass\RmStep;
use WordPress\Blueprints\Runner\Step\RmStepRunner;
use WordPress\Blueprints\Runtime\Runtime;
use WordPress\Filesystem\FilesystemException;
use WordPress\Filesystem\Filesystem;
use WordPress\Filesystem\LocalFilesystem;

use function WordPress\Filesystem\wp_join_paths;

class RmStepRunnerTest extends PHPUnitTestCase {
	/**
	 * @var string
	 */
	private $document_root;

	/**
	 * @var Runtime
	 */
	private $runtime;

	/**
	 * @var RmStepRunner
	 */
	private $step_runner;

	/**
	 * @var Filesystem
	 */
	private $filesystem;

	/**
	 * @before
	 */
	public function setUp(): void {
		$this->document_root = wp_join_paths(sys_get_temp_dir(), 'test_' . uniqid());
		$this->runtime       = new Runtime( $this->document_root );

		$this->filesystem = LocalFilesystem::create($this->document_root);
		
		// Create the document root directory if it doesn't exist
		if (!$this->filesystem->exists('/')) {
			$this->filesystem->mkdir('/');
		}

		$this->step_runner = new RmStepRunner();
		$this->step_runner->setRuntime( $this->runtime );
	}

	/**
	 * @after
	 */
	public function tearDown(): void {
		try {
			if ($this->filesystem->exists('/')) {
				$this->filesystem->rmdir('/', [
					'recursive' => true
				]);
			}
		} catch (\Exception $e) {
			// Ignore cleanup errors
		}
	}

	public function testRemoveDirectoryWhenUsingRelativePath() {
		$this->filesystem->mkdir('test_dir');
		
		$step = new RmStep();
		$step->path = 'test_dir';

		$this->step_runner->run($step);

		self::assertFalse(
			$this->filesystem->exists('test_dir'),
			'Failed to assert that the directory does not exist'
		);
	}

	public function testRemoveDirectoryWithSubdirectory() {
		$this->filesystem->mkdir('parent/child', ['recursive' => true]);
		
		$step = new RmStep();
		$step->path = 'parent';

		$this->step_runner->run($step);

		// Assert parent directory and child don't exist anymore
		self::assertFalse(
			$this->filesystem->exists('parent'),
			'Failed to assert that the parent directory does not exist'
		);
	}

	public function testRemoveDirectoryWithFile() {
		// Create directory with file
		$this->filesystem->mkdir('dir_with_file', ['recursive' => true]);
		$this->filesystem->put_contents('dir_with_file/test.txt', 'test content');
		
		// Create RmStep for removing the directory
		$step = new RmStep();
		$step->path = 'dir_with_file';

		$this->step_runner->run($step);

		self::assertFalse(
			$this->filesystem->exists('dir_with_file'),
			'Failed to assert that the directory does not exist'
		);
	}

	public function testRemoveFile() {
		$this->filesystem->put_contents('test_file.txt', 'test content');
		
		$step = new RmStep();
		$step->path = 'test_file.txt';

		$this->step_runner->run($step);

		self::assertFalse(
			$this->filesystem->exists('test_file.txt'),
			'Failed to assert that the file does not exist'
		);
	}

	public function testThrowExceptionWhenRemovingNonexistentDirectoryAndUsingRelativePath() {
		$step = new RmStep();
		$step->path = 'nonexistent_dir';

		self::expectException(FilesystemException::class);
		self::expectExceptionMessageMatches('/Path does not exist:/');
		
		$this->step_runner->run($step);
	}

	public function testThrowExceptionWhenRemovingNonexistentFileAndUsingRelativePath() {
		$step = new RmStep();
		$step->path = 'nonexistent_file.txt';

		self::expectException(FilesystemException::class);
		self::expectExceptionMessageMatches('/Path does not exist:/');
		
		$this->step_runner->run($step);
	}
}
