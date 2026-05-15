<?php

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wp/' );
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
	}
}

require_once dirname( __DIR__ ) . '/class-pmd-plugin.php';

class PMD_Git_Service_Detection_Test extends TestCase {

	public function testInfoRefsUploadPackPathIsRecognizedAsGitService() {
		$this->assertSame(
			'git-upload-pack',
			$this->git_service_from_request( '/info/refs?service=git-upload-pack' )
		);
	}

	public function testInfoRefsReceivePackPathIsRecognizedAsGitService() {
		$this->assertSame(
			'git-receive-pack',
			$this->git_service_from_request( '/info/refs?service=git-receive-pack' )
		);
	}

	public function testInvalidInfoRefsServiceIsRejected() {
		$this->assertSame(
			'',
			$this->git_service_from_request( '/info/refs?service=git-archive' )
		);
	}

	private function git_service_from_request( $git_path ) {
		$method = new ReflectionMethod( 'PMD_Plugin', 'git_service_from_request' );
		$method->setAccessible( true );

		return $method->invoke( null, $git_path, new WP_REST_Request() );
	}
}
