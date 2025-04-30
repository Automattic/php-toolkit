<?php

namespace unit\steps;

use PHPUnitTestCase;
use WordPress\Blueprints\Model\DataClass\RmDirStep;
use WordPress\Blueprints\Runner\Step\RmDirStepRunner;
use WordPress\Blueprints\Runtime\Runtime;
use WordPress\Filesystem\FilesystemException;
use WordPress\Filesystem\Filesystem;
use WordPress\Filesystem\LocalFilesystem;

use function WordPress\Filesystem\wp_join_paths;

class RmDirStepRunnerTest extends PHPUnitTestCase {
	/**
	 * @var string
	 */
	private $document_root;

	/**
	 * @var Runtime
	 */
	private $runtime;

	/**
	 * @var RmDirStepRunner
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

		$this->step_runner = new RmDirStepRunner();
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

	public function testRemoveEmptyDirectory() {
		$this->filesystem->mkdir('empty_dir');
		
		$step = new RmDirStep();
		$step->setPath('empty_dir');
		$step->setRecursive(false);

		$this->step_runner->run($step);

		self::assertFalse(
			$this->filesystem->exists('empty_dir'),
			'Failed to assert that the directory no longer exists'
		);
	}

	public function testRemoveDirectoryWithRecursiveOption() {
		$this->filesystem->mkdir('parent/child', ['recursive' => true]);
		$this->filesystem->put_contents('parent/file.txt', 'test content');
		$this->filesystem->put_contents('parent/child/nested_file.txt', 'nested content');
		
		$step = new RmDirStep();
		$step->setPath('parent');
		$step->setRecursive(true);

		$this->step_runner->run($step);

		self::assertFalse(
			$this->filesystem->exists('parent'),
			'Failed to assert that the parent directory no longer exists'
		);
	}

	public function testNonRecursiveRemovalFailsForNonEmptyDirectory() {
		$this->filesystem->mkdir('non_empty_dir');
		$this->filesystem->put_contents('non_empty_dir/file.txt', 'test content');
		
		$step = new RmDirStep();
		$step->setPath('non_empty_dir');
		$step->setRecursive(false);

		self::expectException(FilesystemException::class);
		$this->step_runner->run($step);
	}

	public function testRemoveDirectoryWithMultipleFilesAndNestedDirectories() {
		$this->filesystem->mkdir('complex/nested1/sub1', ['recursive' => true]);
		$this->filesystem->mkdir('complex/nested2', ['recursive' => true]);
		$this->filesystem->put_contents('complex/file1.txt', 'content 1');
		$this->filesystem->put_contents('complex/file2.txt', 'content 2');
		$this->filesystem->put_contents('complex/nested1/file3.txt', 'content 3');
		$this->filesystem->put_contents('complex/nested1/sub1/file4.txt', 'content 4');
		$this->filesystem->put_contents('complex/nested2/file5.txt', 'content 5');
		
		$step = new RmDirStep();
		$step->setPath('complex');
		$step->setRecursive(true);

		$this->step_runner->run($step);

		self::assertFalse(
			$this->filesystem->exists('complex'),
			'Failed to assert that the complex directory structure no longer exists'
		);
	}

	public function testRemoveNonExistentDirectoryFails() {
		$step = new RmDirStep();
		$step->setPath('nonexistent_dir');
		$step->setRecursive(false);

		self::expectException(FilesystemException::class);
		$this->step_runner->run($step);
	}

	public function testRemoveFileWithRmDirFails() {
		$this->filesystem->put_contents('test_file.txt', 'test content');
		
		$step = new RmDirStep();
		$step->setPath('test_file.txt');
		$step->setRecursive(false);

		self::expectException(FilesystemException::class);
		$this->step_runner->run($step);
	}
} 