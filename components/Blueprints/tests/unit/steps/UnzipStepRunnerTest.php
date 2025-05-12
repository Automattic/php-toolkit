<?php

namespace unit\steps;

use PHPUnitTestCase;
use WordPress\Blueprints\DataReference\DataReference;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Runner\Step\UnzipStepRunner;
use WordPress\Blueprints\Runtime\Runtime;
use WordPress\Blueprints\Steps\DataClass\UnzipStep;
use WordPress\Filesystem\Filesystem;
use WordPress\Filesystem\LocalFilesystem;

use function WordPress\Filesystem\wp_join_paths;

class UnzipStepRunnerTest extends PHPUnitTestCase {
	/**
	 * @var string
	 */
	private $document_root;

	/**
	 * @var Runtime|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $runtime;

	/**
	 * @var UnzipStepRunner
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
		$this->document_root = wp_join_paths( sys_get_temp_dir(), 'test_' . uniqid() );
		$this->filesystem    = LocalFilesystem::create( $this->document_root );

		$this->runtime = new Runtime( $this->document_root );
		copy(
			wp_join_paths( __DIR__, 'resources', 'test_zip.zip' ),
			wp_join_paths( $this->document_root, 'test_zip.zip' )
		);

		$this->progress_tracker = new Tracker();

		$this->step_runner = new UnzipStepRunner();
		$this->step_runner->setRuntime( $this->runtime );
	}

	/**
	 * @after
	 */
	public function tearDown(): void {
		try {
			if ( $this->filesystem->exists( '/' ) ) {
				$this->filesystem->rmdir( '/', [
					'recursive' => true,
				] );
			}
		} catch ( \Exception $e ) {
			// Ignore cleanup errors
		}
	}

	public function testRunWithValidDataReference() {
		// Create and run the step
		$step                = new UnzipStep();
		$step->zipFile       = DataReference::create( './test_zip.zip' );
		$step->extractToPath = 'extract_dir';

		$this->step_runner->run( $step, $this->progress_tracker );

		$this->assertTrue( $this->filesystem->exists( 'extract_dir' ) );
		$this->assertTrue( $this->filesystem->exists( 'extract_dir/test_zip.txt' ) );
	}

}
