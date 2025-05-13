<?php

namespace unit\steps;

use PHPUnitTestCase;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Runner\Step\SetSiteOptionsStepRunner;
use WordPress\Blueprints\Runner\WordPressBoot\BootOptions;
use WordPress\Blueprints\Runner\WordPressBoot\WordPressBootManager;
use WordPress\Blueprints\Runtime\Runtime;
use WordPress\Blueprints\Steps\DataClass\SetSiteOptionsStep;

use function WordPress\Filesystem\wp_join_paths;

class SetSiteOptionsStepRunnerTest extends PHPUnitTestCase {
	/**
	 * @var string
	 */
	private $document_root;

	/**
	 * @var Runtime
	 */
	private $runtime;

	/**
	 * @before
	 */
	public function setUp(): void {
		$this->document_root = wp_join_paths( sys_get_temp_dir(), 'test_set_options_' . uniqid() );
		if ( ! is_dir( $this->document_root ) ) {
			mkdir( $this->document_root, 0777, true );
		}

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
	 * Helper to verify options in WordPress
	 * Note: This handles the fact that objects become arrays when serialized and deserialized through JSON
	 */
	private function verifyOptions( array $expected_options ) {
		$result = $this->runtime->evalPhpInSubProcess(
			<<<'PHP'
            <?php
            require_once getenv('DOCROOT') . '/wp-load.php';
            
            $options = json_decode(getenv('OPTIONS'), true);
            $result = [];
            
            foreach ($options as $name => $expected_value) {
                $actual_value = get_option($name);
                $result[$name] = $actual_value;
            }
            
            append_output( json_encode($result) );
            PHP,
			[
				'OPTIONS' => json_encode( $expected_options ),
			]
		)->outputFileContent;

		$actual_options = json_decode( $result, true );

		foreach ( $expected_options as $name => $expected_value ) {
			// Convert stdClass objects to arrays for comparison
			// This is because WordPress stores them as arrays in the database
			// and they get serialized/deserialized to JSON
			if ( is_object( $expected_value ) ) {
				$expected_value = json_decode( json_encode( $expected_value ), true );
			}

			// Compare complex nested structures
			if ( is_array( $expected_value ) && is_array( $actual_options[ $name ] ) ) {
				$this->assertSame(
					json_encode( $expected_value, JSON_PRETTY_PRINT ),
					json_encode( $actual_options[ $name ], JSON_PRETTY_PRINT ),
					"Option '$name' should have the expected structure"
				);
			} else {
				$this->assertEquals( $expected_value, $actual_options[ $name ], "Option '$name' should have the expected value" );
			}
		}
	}

	/**
	 * Test setting simple string options
	 */
	public function testSetSimpleStringOptions() {
		$step_runner = new SetSiteOptionsStepRunner();
		$step_runner->setRuntime( $this->runtime );

		$options = [
			'blogname'        => 'Test Blog',
			'blogdescription' => 'Test Description',
			'admin_email'     => 'test@example.com',
		];

		$step          = new SetSiteOptionsStep();
		$step->options = $options;

		$tracker = new Tracker();
		$step_runner->run( $step, $tracker );

		// Verify options were set correctly
		$this->verifyOptions( $options );
	}

	/**
	 * Test setting options with different data types
	 */
	public function testSetOptionsWithDifferentTypes() {
		$step_runner = new SetSiteOptionsStepRunner();
		$step_runner->setRuntime( $this->runtime );

		$options = [
			'string_option' => 'String Value',
			'int_option'    => 42,
			'bool_option'   => true,
			'array_option'  => [ 'one', 'two', 'three' ],
			'object_option' => (object) [ 'key' => 'value' ],
			'nested_option' => [
				'level1' => [
					'level2' => 'nested value',
				],
			],
		];

		$step          = new SetSiteOptionsStep();
		$step->options = $options;

		$tracker = new Tracker();
		$step_runner->run( $step, $tracker );

		// Verify options were set correctly
		$this->verifyOptions( $options );
	}

	/**
	 * Test updating existing WordPress options
	 */
	public function testUpdateExistingWordPressOptions() {
		// First, set some initial values
		$this->runtime->evalPhpInSubProcess(
			<<<'PHP'
            <?php
            require_once getenv('DOCROOT') . '/wp-load.php';
            update_option('users_can_register', 0);
            update_option('default_role', 'subscriber');
            PHP
		)->outputFileContent;

		// Now update them
		$step_runner = new SetSiteOptionsStepRunner();
		$step_runner->setRuntime( $this->runtime );

		$options = [
			'users_can_register' => 1,
			'default_role'       => 'author',
		];

		$step          = new SetSiteOptionsStep();
		$step->options = $options;

		$tracker = new Tracker();
		$step_runner->run( $step, $tracker );

		// Verify options were updated
		$this->verifyOptions( $options );
	}

	/**
	 * Test setting a large number of options at once
	 */
	public function testSetLargeNumberOfOptions() {
		$step_runner = new SetSiteOptionsStepRunner();
		$step_runner->setRuntime( $this->runtime );

		// Create a large number of options
		$options = [];
		for ( $i = 1; $i <= 50; $i ++ ) {
			$options["test_option_$i"] = "value_$i";
		}

		$step          = new SetSiteOptionsStep();
		$step->options = $options;

		$tracker = new Tracker();
		$step_runner->run( $step, $tracker );

		// Verify a sample of the options
		$sample_options = [
			'test_option_1'  => 'value_1',
			'test_option_10' => 'value_10',
			'test_option_25' => 'value_25',
			'test_option_50' => 'value_50',
		];

		$this->verifyOptions( $sample_options );
	}

	/**
	 * Test setting WordPress core settings
	 */
	public function testSetWordPressCoreSettings() {
		$step_runner = new SetSiteOptionsStepRunner();
		$step_runner->setRuntime( $this->runtime );

		$options = [
			'permalink_structure' => '/%year%/%monthnum%/%postname%/',
			'timezone_string'     => 'America/New_York',
			'date_format'         => 'F j, Y',
			'time_format'         => 'g:i a',
			'start_of_week'       => 1, // Monday
			'show_on_front'       => 'page',
			'page_on_front'       => 2,
			'page_for_posts'      => 3,
		];

		$step          = new SetSiteOptionsStep();
		$step->options = $options;

		$tracker = new Tracker();
		$step_runner->run( $step, $tracker );

		// Verify the core settings were applied
		$this->verifyOptions( $options );
	}

	/**
	 * Test setting serialized options
	 */
	public function testSetSerializedOptions() {
		$step_runner = new SetSiteOptionsStepRunner();
		$step_runner->setRuntime( $this->runtime );

		// Create a complex nested structure that will be serialized
		$options = [
			'complex_option' => [
				'setting1' => 'value1',
				'setting2' => [
					'nested1' => true,
					'nested2' => 42,
					'nested3' => [ 'a', 'b', 'c' ],
				],
				'setting3' => (object) [
					'prop1' => 'object property',
					'prop2' => [ 'x', 'y', 'z' ],
				],
			],
		];

		$step          = new SetSiteOptionsStep();
		$step->options = $options;

		$tracker = new Tracker();
		$step_runner->run( $step, $tracker );

		// Verify the complex option was stored and can be retrieved correctly
		$this->verifyOptions( $options );
	}
}
