<?php

namespace WordPress\Blueprints\Tests\Unit\Steps;

use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Steps\RunPHPStep;

use function WordPress\Filesystem\wp_join_paths;

class RunPHPStepTest extends StepTestCase {

	/**
	 * Test running simple PHP code
	 */
	public function testRunSimplePHPCode() {
		$step = new RunPHPStep(
			'<?php echo "Hello World";'
		);

		$tracker = new Tracker();
		$result = $step->run( $this->runtime, $tracker );

		$this->assertEquals( 'Hello World', $result );
	}

	/**
	 * Test running PHP code that creates a file
	 */
	public function testRunPHPCodeCreatingFile() {
		$test_file_path = wp_join_paths( $this->runtime->getConfiguration()->getTargetSiteRoot(), 'test_file.txt' );
		$test_content   = 'This is a test file created by PHP';

		$step = new RunPHPStep(
			<<<PHP
<?php
\$docroot = getenv('DOCROOT');
\$test_file_path = \$docroot . '/test_file.txt';
file_put_contents(\$test_file_path, 'This is a test file created by PHP');
echo "File created";
PHP
		);

		$tracker = new Tracker();
		$result  = $step->run( $this->runtime, $tracker );

		$this->assertEquals( 'File created', $result );
		$this->assertFileExists( $test_file_path );
		$this->assertEquals( $test_content, file_get_contents( $test_file_path ) );
	}

	/**
	 * Test running PHP code that loads WordPress
	 */
	public function testRunPHPCodeWithWordPress() {
		$step = new RunPHPStep(
			<<<PHP
<?php
require_once getenv('DOCROOT') . '/wp-load.php';

// Create a test option
update_option('test_option', 'test_value');

// Return the option value
echo get_option('test_option');
PHP
		);

		$tracker = new Tracker();
		$result  = $step->run( $this->runtime, $tracker );

		$this->assertEquals( 'test_value', $result );

		// Verify the option was actually set in WordPress
		$option_value = $this->runtime->evalPhpInSubProcess(
			<<<'PHP'
            <?php
            require_once getenv('DOCROOT') . '/wp-load.php';
            append_output( get_option('test_option') );
            PHP
		)->outputFileContent;

		$this->assertEquals( 'test_value', $option_value );
	}

	/**
	 * Test running PHP code that returns complex data
	 */
	public function testRunPHPCodeReturningComplexData() {
		$step = new RunPHPStep(
			<<<PHP
<?php
\$data = [
    'string' => 'Hello',
    'number' => 42,
    'boolean' => true,
    'array' => [1, 2, 3],
    'object' => (object)['name' => 'Test']
];

echo json_encode(\$data);
PHP
		);

		$tracker = new Tracker();
		$result  = $step->run( $this->runtime, $tracker );
		$data    = json_decode( $result, true );

		$this->assertIsArray( $data );
		$this->assertEquals( 'Hello', $data['string'] );
		$this->assertEquals( 42, $data['number'] );
		$this->assertTrue( $data['boolean'] );
		$this->assertEquals( [ 1, 2, 3 ], $data['array'] );
		$this->assertEquals( [ 'name' => 'Test' ], $data['object'] );
	}

	/**
	 * Test running PHP code with syntax error
	 */
	public function testRunPHPCodeWithSyntaxError() {
		$step = new RunPHPStep(
			'<?php echo "Missing semicolon" echo "Another string";'
		);

		$tracker = new Tracker();

		// The code contains a syntax error, so we expect an exception
		$this->expectException( \Exception::class );
		$step->run( $this->runtime, $tracker );
	}
}
