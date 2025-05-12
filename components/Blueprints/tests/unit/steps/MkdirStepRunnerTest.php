<?php

namespace unit\steps;

use PHPUnitTestCase;
use WordPress\Blueprints\Runner\Step\MkdirStepRunner;
use WordPress\Blueprints\Runtime\Runtime;
use WordPress\Blueprints\Steps\DataClass\MkdirStep;
use WordPress\Filesystem\Filesystem;
use WordPress\Filesystem\FilesystemException;
use WordPress\Filesystem\LocalFilesystem;

use function WordPress\Filesystem\wp_join_paths;

class MkdirStepRunnerTest extends PHPUnitTestCase {
	/**
	 * @var string
	 */
	private $document_root;

	/**
	 * @var Runtime
	 */
	private $runtime;

	/**
	 * @var MkdirStepRunner
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
		$this->document_root = wp_join_paths( sys_get_temp_dir(), 'test' );
		$this->runtime       = new Runtime( $this->document_root );

		$this->filesystem = LocalFilesystem::create( $this->document_root );

		$this->step_runner = new MkdirStepRunner();
		$this->step_runner->setRuntime( $this->runtime );
	}

	/**
	 * @after
	 */
	public function tearDown(): void {
		$this->filesystem->rmdir( '/', [
			'recursive' => true,
		] );
	}

	public function testCreateDirectoryWhenUsingRelativePath() {
		$path = 'dir';
		$step = new MkdirStep();
		$step->setPath( $path );

		$this->step_runner->run( $step );

		$resolved_path = $this->runtime->resolvePath( $path );
		self::assertDirectoryExists( $resolved_path );
	}

	public function testCreateDirectoryWhenUsingAbsolutePath() {
		$absolute_path = '/dir';

		$step = new MkdirStep();
		$step->setPath( $absolute_path );

		$this->step_runner->run( $step );

		self::assertTrue(
			$this->filesystem->exists( $absolute_path ),
			sprintf( 'Failed to assert that the directory exists: %s', $absolute_path )
		);
	}

	public function testCreateDirectoryRecursively() {
		$path = 'dir/subdir';
		$step = new MkdirStep();
		$step->setPath( $path );

		$this->step_runner->run( $step );

		$resolved_path = $this->runtime->resolvePath( $path );
		self::assertDirectoryExists( $resolved_path );
	}

	public function testCreateReadableAndWritableDirectory() {
		$path = 'dir';
		$step = new MkdirStep();
		$step->setPath( $path );

		$this->step_runner->run( $step );

		$resolved_path = $this->runtime->resolvePath( $path );
		self::assertDirectoryExists( $resolved_path );
		self::assertDirectoryIsWritable( $resolved_path );
		self::assertDirectoryIsReadable( $resolved_path );
	}

	public function testThrowExceptionWhenCreatingDirectoryAndItAlreadyExists() {
		$path = 'dir';
		$this->filesystem->mkdir( $path );

		$step = new MkdirStep();
		$step->setPath( $path );

		self::expectException( FilesystemException::class );
		self::expectExceptionMessageMatches( "/Path already exists:/" );
		$this->step_runner->run( $step );
	}
}
