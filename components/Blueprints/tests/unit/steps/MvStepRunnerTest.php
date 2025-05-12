<?php

namespace unit\steps;

use PHPUnitTestCase;
use WordPress\Blueprints\Runner\Step\MvStepRunner;
use WordPress\Blueprints\Runtime\Runtime;
use WordPress\Blueprints\Steps\DataClass\MvStep;
use WordPress\Filesystem\Filesystem;
use WordPress\Filesystem\FilesystemException;
use WordPress\Filesystem\LocalFilesystem;

use function WordPress\Filesystem\wp_join_paths;

class MvStepRunnerTest extends PHPUnitTestCase {
	/**
	 * @var string
	 */
	private $document_root;

	/**
	 * @var Runtime
	 */
	private $runtime;

	/**
	 * @var MvStepRunner
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
		$this->document_root = wp_join_paths( sys_get_temp_dir(), 'test_' . uniqid() );
		$this->runtime       = new Runtime( $this->document_root );

		$this->filesystem = LocalFilesystem::create( $this->document_root );

		// Create the document root directory if it doesn't exist
		if ( ! $this->filesystem->exists( '/' ) ) {
			$this->filesystem->mkdir( '/' );
		}

		$this->step_runner = new MvStepRunner();
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

	public function testMoveFile() {
		$this->filesystem->put_contents( 'source_file.txt', 'test content' );

		$step           = new MvStep();
		$step->fromPath = 'source_file.txt';
		$step->toPath   = 'target_file.txt';

		$this->step_runner->run( $step );

		self::assertFalse(
			$this->filesystem->exists( 'source_file.txt' ),
			'Failed to assert that the source file no longer exists'
		);
		self::assertTrue(
			$this->filesystem->exists( 'target_file.txt' ),
			'Failed to assert that the target file exists'
		);
		self::assertEquals(
			'test content',
			$this->filesystem->get_contents( 'target_file.txt' ),
			'Failed to assert that the file content was preserved'
		);
	}

	public function testMoveFileToDirectory() {
		$this->filesystem->put_contents( 'source_file.txt', 'test content' );
		$this->filesystem->mkdir( 'target_dir' );

		$step           = new MvStep();
		$step->fromPath = 'source_file.txt';
		$step->toPath   = 'target_dir/file.txt';

		$this->step_runner->run( $step );

		self::assertFalse(
			$this->filesystem->exists( 'source_file.txt' ),
			'Failed to assert that the source file no longer exists'
		);
		self::assertTrue(
			$this->filesystem->exists( 'target_dir/file.txt' ),
			'Failed to assert that the target file exists'
		);
	}

	public function testMoveDirectory() {
		$this->filesystem->mkdir( 'source_dir' );
		$this->filesystem->put_contents( 'source_dir/file.txt', 'test content' );

		$step           = new MvStep();
		$step->fromPath = 'source_dir';
		$step->toPath   = 'target_dir';

		$this->step_runner->run( $step );

		self::assertFalse(
			$this->filesystem->exists( 'source_dir' ),
			'Failed to assert that the source directory no longer exists'
		);
		self::assertTrue(
			$this->filesystem->exists( 'target_dir' ),
			'Failed to assert that the target directory exists'
		);
		self::assertTrue(
			$this->filesystem->exists( 'target_dir/file.txt' ),
			'Failed to assert that the target directory contains the file'
		);
	}

	public function testMoveDirectoryWithNestedContent() {
		$this->filesystem->mkdir( 'source_dir/nested_dir', [ 'recursive' => true ] );
		$this->filesystem->put_contents( 'source_dir/file1.txt', 'test content 1' );
		$this->filesystem->put_contents( 'source_dir/nested_dir/file2.txt', 'test content 2' );

		$step           = new MvStep();
		$step->fromPath = 'source_dir';
		$step->toPath   = 'target_dir';

		$this->step_runner->run( $step );

		self::assertFalse(
			$this->filesystem->exists( 'source_dir' ),
			'Failed to assert that the source directory no longer exists'
		);
		self::assertTrue(
			$this->filesystem->exists( 'target_dir' ),
			'Failed to assert that the target directory exists'
		);
		self::assertTrue(
			$this->filesystem->exists( 'target_dir/file1.txt' ),
			'Failed to assert that the target directory contains file1.txt'
		);
		self::assertTrue(
			$this->filesystem->exists( 'target_dir/nested_dir/file2.txt' ),
			'Failed to assert that the target directory contains nested structure'
		);
		self::assertEquals(
			'test content 2',
			$this->filesystem->get_contents( 'target_dir/nested_dir/file2.txt' ),
			'Failed to assert that the file content was preserved'
		);
	}

	public function testMoveNonexistentSourceFails() {
		$step           = new MvStep();
		$step->fromPath = 'nonexistent_file.txt';
		$step->toPath   = 'target_file.txt';

		self::expectException( FilesystemException::class );
		$this->step_runner->run( $step );
	}
}
