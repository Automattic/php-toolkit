<?php

namespace unit\steps;

use PHPUnitTestCase;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Runner\Step\RunPHPStepRunner;
use WordPress\Blueprints\Runner\WordPressBoot\BootOptions;
use WordPress\Blueprints\Runner\WordPressBoot\WordPressBootManager;
use WordPress\Blueprints\Runtime\Runtime;
use WordPress\Blueprints\Steps\DataClass\RunPHPStep;

use function WordPress\Filesystem\wp_join_paths;

class RunPHPStepRunnerTest extends PHPUnitTestCase {
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
		$this->document_root = wp_join_paths( sys_get_temp_dir(), 'test_runphp_' . uniqid() );
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
	 * Test running simple PHP code
	 */
	public function testRunSimplePHPCode() {
		$step_runner = new RunPHPStepRunner();
		$step_runner->setRuntime( $this->runtime );

		$step       = new RunPHPStep();
		$step->code = '<?php echo "Hello World";';

		$tracker = new Tracker();
		$result  = $step_runner->run( $step, $tracker );

		$this->assertEquals( 'Hello World', $result );
	}

	/**
	 * Test running PHP code that creates a file
	 */
	public function testRunPHPCodeCreatingFile() {
		$step_runner = new RunPHPStepRunner();
		$step_runner->setRuntime( $this->runtime );

		$test_file_path = $this->document_root . '/test_file.txt';
		$test_content   = 'This is a test file created by PHP';

		$step       = new RunPHPStep();
		$step->code = <<<PHP
<?php
\$docroot = getenv('DOCROOT');
\$test_file_path = \$docroot . '/test_file.txt';
file_put_contents(\$test_file_path, 'This is a test file created by PHP');
echo "File created";
PHP;

		$tracker = new Tracker();
		$result  = $step_runner->run( $step, $tracker );

		$this->assertEquals( 'File created', $result );
		$this->assertFileExists( $test_file_path );
		$this->assertEquals( $test_content, file_get_contents( $test_file_path ) );
	}

	/**
	 * Test running PHP code that loads WordPress
	 */
	public function testRunPHPCodeWithWordPress() {
		$step_runner = new RunPHPStepRunner();
		$step_runner->setRuntime( $this->runtime );

		$step       = new RunPHPStep();
		$step->code = <<<PHP
<?php
require_once getenv('DOCROOT') . '/wp-load.php';

// Create a test option
update_option('test_option', 'test_value');

// Return the option value
echo get_option('test_option');
PHP;

		$tracker = new Tracker();
		$result  = $step_runner->run( $step, $tracker );

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
		$step_runner = new RunPHPStepRunner();
		$step_runner->setRuntime( $this->runtime );

		$step       = new RunPHPStep();
		$step->code = <<<PHP
<?php
\$data = [
    'string' => 'Hello',
    'number' => 42,
    'boolean' => true,
    'array' => [1, 2, 3],
    'object' => (object)['name' => 'Test']
];

echo json_encode(\$data);
PHP;

		$tracker = new Tracker();
		$result  = $step_runner->run( $step, $tracker );
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
		$step_runner = new RunPHPStepRunner();
		$step_runner->setRuntime( $this->runtime );

		$step       = new RunPHPStep();
		$step->code = '<?php echo "Missing semicolon" echo "Another string";';

		$tracker = new Tracker();

		// The code contains a syntax error, so we expect an exception
		$this->expectException( \Exception::class );
		$step_runner->run( $step, $tracker );
	}
}
