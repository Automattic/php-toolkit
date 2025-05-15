<?php

namespace WordPress\Blueprints\Tests\Unit\Steps;

use PHPUnit\Framework\TestCase;
use WordPress\Blueprints\Runner;
use WordPress\Blueprints\RunnerConfiguration;
use WordPress\Blueprints\Runtime;
use WordPress\Filesystem\Filesystem;
use WordPress\Filesystem\LocalFilesystem;

use function WordPress\Filesystem\wp_join_paths;

class StepTestCase extends TestCase {
	/**
	 * @var string
	 */
	protected $document_root;

	/**
	 * @var string
	 */
	protected $execution_context_path;

	/**
	 * @var Filesystem
	 */
	protected $execution_context;

	/**
	 * @var Runtime
	 */
	public ?Runtime $runtime;

	/**
	 * @before
	 */
	public function setUp(): void {
		$this->document_root = wp_join_paths( sys_get_temp_dir(), 'test_' . uniqid() );
		$this->execution_context_path = wp_join_paths( sys_get_temp_dir(), 'test_' . uniqid() );
		$this->execution_context = LocalFilesystem::create($this->execution_context_path);

		$config = (new RunnerConfiguration())
			->setBlueprint([ "version" => 2 ])
			->setDatabaseEngine('sqlite')
			->setExecutionMode('create-new-site')
			->setExecutionContext($this->execution_context)
			->setTargetSiteRoot($this->document_root)
			->setTargetSiteUrl('http://127.0.0.1:2456') // Arbitrary URL for the new site
		;
	
		$runner = new Runner($config);
		$runner->run();
		$this->runtime = $runner->runtime;
		// Recreate the temp root directory – the runner cleans it up at the
		// end of run().
		mkdir($this->runtime->getTempRoot());
	}

	/**
	 * @after
	 */
	public function tearDown(): void {
		// Clean up temp directory
		if ( is_dir( $this->document_root ) ) {
			$this->removeDirectory( $this->document_root );
		}
		if ( is_dir( $this->runtime->getTempRoot() ) ) {
			$this->removeDirectory( $this->runtime->getTempRoot() );
		}
	}

	private function removeDirectory( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$objects = scandir( $dir );
		foreach ( $objects as $object ) {
			if ( $object == "." || $object == ".." ) {
				continue;
			}

			$path = $dir . DIRECTORY_SEPARATOR . $object;
			if ( is_dir( $path ) ) {
				$this->removeDirectory( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}
}
