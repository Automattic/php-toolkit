<?php

use PHPUnit\Framework\TestCase;
use WordPress\Filesystem\InMemoryFilesystem;
use WordPress\Git\GitRepository;
use WordPress\Git\Model\Commit;
use WordPress\Git\Protocol\GitProtocolEncoderPipe;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wp/' );
}

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public $ID          = 0;
		public $post_type   = 'post';
		public $post_name   = '';
		public $post_parent = 0;
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $title ) {
		$title = strtolower( (string) $title );
		$title = preg_replace( '/[^a-z0-9_-]+/', '-', $title );

		return trim( $title, '-' );
	}
}

if ( ! function_exists( 'post_type_exists' ) ) {
	function post_type_exists( $post_type ) {
		return in_array( $post_type, array( 'post', 'page' ), true );
	}
}

if ( ! function_exists( 'taxonomy_exists' ) ) {
	function taxonomy_exists( $taxonomy ) {
		unset( $taxonomy );

		return false;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default = false ) {
		unset( $option );

		return $default;
	}
}

require_once dirname( __DIR__ ) . '/class-push-md-plugin.php';

class BranchPreviewTest extends TestCase {

	public function testTrunkPushRemainsPublishingPush() {
		$repository = $this->repository();
		$current    = $repository->get_branch_tip( 'refs/heads/trunk' );
		$new_oid    = '1111111111111111111111111111111111111111';
		$header     = $this->parse_push_header(
			$this->push_request( $current, $new_oid, 'refs/heads/trunk' ),
			$repository,
			$current
		);

		$this->assertFalse( $header['is_preview'] );
		$this->assertSame( 'trunk', $header['branch_name'] );
		$this->assertSame( $current, $header['old_oid'] );
		$this->assertSame( $new_oid, $header['new_oid'] );
	}

	public function testPreviewBranchPushUsesCurrentTrunkAsBase() {
		$repository = $this->repository();
		$current    = $repository->get_branch_tip( 'refs/heads/trunk' );
		$new_oid    = '2222222222222222222222222222222222222222';
		$header     = $this->parse_push_header(
			$this->push_request( Commit::NULL_HASH, $new_oid, 'refs/heads/preview-home' ),
			$repository,
			$current
		);

		$this->assertTrue( $header['is_preview'] );
		$this->assertFalse( $header['is_delete'] );
		$this->assertSame( 'preview-home', $header['branch_name'] );
		$this->assertSame( $current, $header['base_oid'] );
		$this->assertSame( $current, $header['validation_old_oid'] );
	}

	public function testPreviewBranchUpdateMustMatchExistingBranchTip() {
		$repository = $this->repository();
		$current    = $repository->get_branch_tip( 'refs/heads/trunk' );
		$branch_tip = '3333333333333333333333333333333333333333';
		$repository->set_branch_tip( 'refs/heads/preview-home', $branch_tip );

		$header = $this->parse_push_header(
			$this->push_request( $branch_tip, '4444444444444444444444444444444444444444', 'refs/heads/preview-home' ),
			$repository,
			$current
		);

		$this->assertTrue( $header['is_preview'] );
		$this->assertSame( $branch_tip, $header['validation_old_oid'] );
	}

	public function testPreviewBranchDeletionIsAccepted() {
		$repository = $this->repository();
		$current    = $repository->get_branch_tip( 'refs/heads/trunk' );
		$branch_tip = '4444444444444444444444444444444444444444';
		$repository->set_branch_tip( 'refs/heads/preview-home', $branch_tip );

		$header = $this->parse_push_header(
			$this->push_request( $branch_tip, Commit::NULL_HASH, 'refs/heads/preview-home' ),
			$repository,
			$current
		);

		$this->assertTrue( $header['is_preview'] );
		$this->assertTrue( $header['is_delete'] );
		$this->assertSame( 'preview-home', $header['branch_name'] );
		$this->assertSame( $branch_tip, $header['old_oid'] );
		$this->assertSame( Commit::NULL_HASH, $header['new_oid'] );
	}

	public function testPreviewBranchRejectsStaleOldOid() {
		$repository = $this->repository();
		$current    = $repository->get_branch_tip( 'refs/heads/trunk' );
		$repository->set_branch_tip( 'refs/heads/preview-home', '5555555555555555555555555555555555555555' );

		$header = $this->parse_push_header(
			$this->push_request( '6666666666666666666666666666666666666666', '7777777777777777777777777777777777777777', 'refs/heads/preview-home' ),
			$repository,
			$current
		);

		$this->assertSame(
			'Push rejected because the preview branch changed. Fetch the latest branch state and try again.',
			$header['error']
		);
	}

	public function testProtectedPreviewBranchNamesAreRejected() {
		$repository = $this->repository();
		$current    = $repository->get_branch_tip( 'refs/heads/trunk' );
		$header     = $this->parse_push_header(
			$this->push_request( Commit::NULL_HASH, '8888888888888888888888888888888888888888', 'refs/heads/_push_md_seed' ),
			$repository,
			$current
		);

		$this->assertSame(
			'Push rejected because the preview branch name is not supported.',
			$header['error']
		);
	}

	public function testTrunkDeletionIsStillRejected() {
		$repository = $this->repository();
		$current    = $repository->get_branch_tip( 'refs/heads/trunk' );
		$header     = $this->parse_push_header(
			$this->push_request( $current, Commit::NULL_HASH, 'refs/heads/trunk' ),
			$repository,
			$current
		);

		$this->assertSame(
			'Push rejected because deleting trunk is not supported.',
			$header['error']
		);
	}

	private function repository() {
		$repository = new GitRepository(
			InMemoryFilesystem::create(),
			array(
				'default_branch' => 'trunk',
			)
		);
		$repository->set_config_value( 'user.name', 'Push MD Test' );
		$repository->set_config_value( 'user.email', 'push-md@example.com' );
		$repository->commit(
			array(
				'updates' => array(
					'post/hello.md' => "---\ntitle: \"Hello\"\n---\n\nHello.\n",
				),
			)
		);

		return $repository;
	}

	private function push_request( $old_oid, $new_oid, $ref_name ) {
		return GitProtocolEncoderPipe::encode_packet_lines(
			array(
				$old_oid . ' ' . $new_oid . ' ' . $ref_name . "\0report-status side-band-64k\n",
				'0000',
			)
		);
	}

	private function parse_push_header( $request, GitRepository $repository, $current_head ) {
		$method = new ReflectionMethod( Push_MD_Plugin::class, 'parse_push_header' );
		$method->setAccessible( true );

		return $method->invoke( null, $request, $repository, $current_head );
	}
}
