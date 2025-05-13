<?php

namespace unit\steps;

use PHPUnitTestCase;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Runner\Step\DefineWpConfigConstsStepRunner;
use WordPress\Blueprints\Runner\WordPressBoot\BootOptions;
use WordPress\Blueprints\Runner\WordPressBoot\WordPressBootManager;
use WordPress\Blueprints\Runtime\Runtime;
use WordPress\Blueprints\Steps\DataClass\DefineWpConfigConstsStep;

use function WordPress\Filesystem\wp_join_paths;

class DefineWpConfigConstsStepRunnerTest extends PHPUnitTestCase {
	/**
	 * @var string
	 */
	private $document_root;

	/**
	 * @var Runtime
	 */
	private $runtime;

	/**
	 * Sample wp-config.php content for testing
	 */
	const SAMPLE_WP_CONFIG = <<<'PHP'
<?php
/**
 * The base configuration for WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define('DB_NAME', 'test_db');

/** Database username */
define('DB_USER', 'root');

/** Database password */
define('DB_PASSWORD', 'password');

/** Database hostname */
define('DB_HOST', 'localhost');

/** Database charset to use in creating database tables. */
define('DB_CHARSET', 'utf8');

/** The database collate type. Don't change this if in doubt. */
define('DB_COLLATE', '');

define('WP_DEBUG', false);

/**
 * WordPress Database Table prefix.
 */
$table_prefix = 'wp_';

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
PHP;

	/**
	 * @before
	 */
	public function setUp(): void {
		$this->document_root = wp_join_paths( sys_get_temp_dir(), 'test_wp_config_' . uniqid() );
		if ( ! is_dir( $this->document_root ) ) {
			mkdir( $this->document_root, 0777, true );
		}

		// Set up wp-config.php
		file_put_contents( $this->document_root . '/wp-config.php', self::SAMPLE_WP_CONFIG );

		// Boot WordPress using WordPressBootManager
		$options = BootOptions::parse( [
			'siteUrl'      => 'https://example.com',
			'documentRoot' => $this->document_root,
		] );

		$this->runtime = WordPressBootManager::boot( $options );
	}

	/**
	 * @after
	 */
	public function tearDown(): void {
		// Clean up temp directory
		if ( is_dir( $this->document_root ) ) {
			$this->removeDirectory( $this->document_root );
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

	/**
	 * Test updating existing constants
	 */
	public function testUpdateExistingConstants() {
		// Create and configure the step runner
		$step_runner = new DefineWpConfigConstsStepRunner();
		$step_runner->setRuntime( $this->runtime );

		// Create the step with constants to define
		$constants = [
			'WP_DEBUG' => true,
			'DB_NAME'  => 'updated_db',
		];

		$step         = new DefineWpConfigConstsStep();
		$step->consts = $constants;

		// Run the step
		$tracker = new Tracker();
		$step_runner->run( $step, $tracker );

		$this->assertWordPressConstants( $constants );
	}

	/**
	 * Test adding new constants
	 */
	public function testAddNewConstants() {
		// Create and configure the step runner
		$step_runner = new DefineWpConfigConstsStepRunner();
		$step_runner->setRuntime( $this->runtime );

		// Create the step with constants to define
		$constants = [
			'WP_MEMORY_LIMIT'            => '256M',
			'AUTOMATIC_UPDATER_DISABLED' => true,
		];

		$step         = new DefineWpConfigConstsStep();
		$step->consts = $constants;

		// Run the step
		$tracker = new Tracker();
		$step_runner->run( $step, $tracker );

		$this->assertWordPressConstants( $constants );
	}

	/**
	 * Test defining constants with different data types
	 */
	public function testDefineConstantsWithDifferentTypes() {
		// Create and configure the step runner
		$step_runner = new DefineWpConfigConstsStepRunner();
		$step_runner->setRuntime( $this->runtime );

		// Create the step with constants of different types
		$constants = [
			'STRING_CONST' => 'string value',
			'BOOL_CONST'   => true,
			'INT_CONST'    => 42,
			'FLOAT_CONST'  => 3.14,
			'ARRAY_CONST'  => [ 'one', 'two', 'three' ],
			'NULL_CONST'   => null,
		];

		$step         = new DefineWpConfigConstsStep();
		$step->consts = $constants;

		// Run the step
		$tracker = new Tracker();
		$step_runner->run( $step, $tracker );

		$this->assertWordPressConstants( $constants );
	}

	/**
	 * Test error handling when wp-config.php does not exist
	 */
	public function testErrorHandlingWhenWpConfigNotExists() {
		// Remove the wp-config.php file
		unlink( $this->document_root . '/wp-config.php' );

		// Create and configure the step runner
		$step_runner = new DefineWpConfigConstsStepRunner();
		$step_runner->setRuntime( $this->runtime );

		// Create the step with constants to define
		$step         = new DefineWpConfigConstsStep();
		$step->consts = [
			'WP_DEBUG' => true,
		];

		// Run the step with a try-catch to capture the expected error
		$tracker = new Tracker();
		try {
			$result = $step_runner->run( $step, $tracker );
			$this->fail( 'Expected an exception when wp-config.php does not exist' );
		} catch ( \Exception $e ) {
			// Test passes if an exception is thrown
			$this->assertTrue( true );
		}
	}

	/**
	 * Test defining multiple constants at once
	 */
	public function testDefineMultipleConstants() {
		// Create and configure the step runner
		$step_runner = new DefineWpConfigConstsStepRunner();
		$step_runner->setRuntime( $this->runtime );

		// Define a large set of constants
		$constants = [
			'WP_DEBUG'            => true,
			'WP_DEBUG_LOG'        => true,
			'WP_DEBUG_DISPLAY'    => false,
			'SCRIPT_DEBUG'        => true,
			'WP_ENVIRONMENT_TYPE' => 'development',
			'WP_CACHE'            => false,
			'CONCATENATE_SCRIPTS' => false,
			'COMPRESS_SCRIPTS'    => false,
			'COMPRESS_CSS'        => false,
			'ENFORCE_GZIP'        => false,
		];

		// Create the step with constants to define
		$step         = new DefineWpConfigConstsStep();
		$step->consts = $constants;

		// Run the step
		$tracker = new Tracker();
		$step_runner->run( $step, $tracker );

		$this->assertWordPressConstants( $constants );
	}

	/**
	 * Helper method to verify constants are defined in WordPress
	 *
	 * @param  array  $constants  Array of constants to check
	 *
	 * @return array Results of constant verification
	 */
	private function assertWordPressConstants( array $expected_constants ) {
		$result = $this->runtime->evalPhpInSubProcess(
			<<<'PHP'
            <?php
            // Load WordPress environment
            require_once getenv('DOCROOT') . '/wp-load.php';
            
            // Check if constants are defined
            $results = [];
            $constants = json_decode(getenv('CONSTANTS'), true);
            
            foreach ($constants as $name => $expected_value) {
                $results[$name] = defined($name) ? constant($name) : null;
            }
            
            append_output( json_encode($results) );
            PHP,
			[
				'CONSTANTS' => json_encode( $expected_constants ),
			]
		)->outputFileContent;

		$actual_constants = json_decode( $result, true );
		$this->assertEquals( $expected_constants, $actual_constants );
	}

}
