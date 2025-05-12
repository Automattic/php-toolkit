<?php

namespace unit\steps;

use PHPUnitTestCase;
use WordPress\Blueprints\DataReference\DataReference;
use WordPress\Blueprints\Steps\DataClass\WriteFileStep;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Runner\Step\WriteFileStepRunner;
use WordPress\Blueprints\Runtime\Runtime;
use WordPress\Filesystem\Filesystem;
use WordPress\Filesystem\LocalFilesystem;

use function WordPress\Filesystem\wp_join_paths;

class WriteFileStepRunnerTest extends PHPUnitTestCase {
	/**
	 * @var string
	 */
	private $document_root;

	/**
	 * @var Runtime
	 */
	private $runtime;

	/**
	 * @var WriteFileStepRunner
	 */
	private $step_runner;

	/**
	 * @var Filesystem
	 */
	private $filesystem;

	/**
	 * @var Tracker
	 */
	private $progress_tracker;

	/**
	 * @before
	 */
	public function setUp(): void {
		$this->document_root = wp_join_paths(sys_get_temp_dir(), 'test_' . uniqid());
		$this->filesystem = LocalFilesystem::create($this->document_root);

		$this->runtime = new Runtime($this->document_root);

		// Create a test resource file
		$test_content = "Test file content";
		$this->filesystem->put_contents('test_source.txt', $test_content);

		$this->progress_tracker = new Tracker();

		$this->step_runner = new WriteFileStepRunner();
		$this->step_runner->setRuntime($this->runtime);
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

	public function testRunWithStringData() {
		// Create and run the step with string data
		$step = new WriteFileStep();
		$step->path = 'test_output.txt';
		$step->data = 'String content test';

		$this->step_runner->run($step, $this->progress_tracker);

		$this->assertTrue($this->filesystem->exists('test_output.txt'));
		$this->assertEquals('String content test', $this->filesystem->get_contents('test_output.txt'));
	}

	public function testRunWithDataReference() {
		// Create and run the step with a data reference
		$step = new WriteFileStep();
		$step->path = 'test_output_from_ref.txt';
		$step->data = DataReference::create('./test_source.txt');

		$this->step_runner->run($step, $this->progress_tracker);

		$this->assertTrue($this->filesystem->exists('test_output_from_ref.txt'));
		$this->assertEquals('Test file content', $this->filesystem->get_contents('test_output_from_ref.txt'));
	}

	public function testCreatesDirectoryStructure() {
		// Create and run the step with a nested path
		$step = new WriteFileStep();
		$step->path = 'nested/directory/structure/test.txt';
		$step->data = 'Nested directory test';

		$this->step_runner->run($step, $this->progress_tracker);

		$this->assertTrue($this->filesystem->exists('nested/directory/structure/test.txt'));
		$this->assertEquals('Nested directory test', $this->filesystem->get_contents('nested/directory/structure/test.txt'));
	}
}
