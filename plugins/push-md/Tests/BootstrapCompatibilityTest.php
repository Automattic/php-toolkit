<?php

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) && ! class_exists( TestCase::class ) ) {
	exit;
}

class PMD_Bootstrap_Compatibility_Test extends TestCase {

	public function testDevBootstrapSkipsAlreadyLoadedToolkitFunctionFiles() {
		if ( ! function_exists( 'proc_open' ) ) {
			$this->markTestSkipped( 'proc_open() is required for the Push MD bootstrap compatibility test.' );
		}

		$project_dir          = dirname( __DIR__, 3 );
		$filesystem_functions = $project_dir . '/components/Filesystem/functions.php';
		$dev_bootstrap        = dirname( __DIR__ ) . '/push-md-dev-bootstrap.php';
		$code                 = 'define( "ABSPATH", "/tmp/wp/" );' .
			'require ' . var_export( $filesystem_functions, true ) . ';' .
			'require ' . var_export( $dev_bootstrap, true ) . ';' .
			'echo "loaded\n";';

		$result = $this->run_php( $code );

		$this->assertSame( 0, $result['exit_code'], $result['stdout'] . $result['stderr'] );
		$this->assertSame( "loaded\n", $result['stdout'] );
		$this->assertSame( '', $result['stderr'] );
	}

	public function testDevBootstrapLoadsMissingHelpersWhenOlderToolkitFunctionExists() {
		if ( ! function_exists( 'proc_open' ) ) {
			$this->markTestSkipped( 'proc_open() is required for the Push MD bootstrap compatibility test.' );
		}

		$dev_bootstrap = dirname( __DIR__ ) . '/push-md-dev-bootstrap.php';
		$code          = 'namespace WordPress\\Filesystem {' .
			'function ls_recursive( $filesystem, $path = "/" ) { return array(); }' .
			'} namespace {' .
			'define( "ABSPATH", "/tmp/wp/" );' .
			'require ' . var_export( $dev_bootstrap, true ) . ';' .
			'echo function_exists( "WordPress\\\\Filesystem\\\\wp_join_unix_paths" ) ? "loaded\n" : "missing\n";' .
			'}';

		$result = $this->run_php( $code );

		$this->assertSame( 0, $result['exit_code'], $result['stdout'] . $result['stderr'] );
		$this->assertSame( "loaded\n", $result['stdout'] );
		$this->assertSame( '', $result['stderr'] );
	}

	private function run_php( $code ) {
		$descriptor_spec = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$script          = tempnam( sys_get_temp_dir(), 'pmd-bootstrap-' );
		if ( false === $script || false === file_put_contents( $script, "<?php\n" . $code ) ) {
			$this->fail( 'Failed to write temporary PHP script for bootstrap compatibility test.' );
		}

		$command = $this->build_php_command( $script );
		$options = array();
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			$options['bypass_shell'] = true;
		}
		$process = proc_open( $command, $descriptor_spec, $pipes, null, null, $options );

		if ( ! is_resource( $process ) ) {
			unlink( $script );
			$this->fail( sprintf( 'Failed to start command: %s', $this->format_command_for_message( $command ) ) );
		}

		fclose( $pipes[0] );
		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$exit_code = proc_close( $process );
		unlink( $script );

		return array(
			'exit_code' => $exit_code,
			'stdout'    => $stdout,
			'stderr'    => $stderr,
		);
	}

	private function build_php_command( $script ) {
		$args = array( PHP_BINARY, '-d', 'display_errors=1', $script );
		if ( PHP_VERSION_ID >= 70400 ) {
			return $args;
		}

		$command = '';
		foreach ( $args as $arg ) {
			$command .= escapeshellarg( $arg ) . ' ';
		}

		return rtrim( $command );
	}

	private function format_command_for_message( $command ) {
		if ( is_array( $command ) ) {
			return implode( ' ', $command );
		}

		return $command;
	}
}
