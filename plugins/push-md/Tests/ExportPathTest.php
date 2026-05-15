<?php

use PHPUnit\Framework\TestCase;

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

require_once dirname( __DIR__ ) . '/class-pmd-plugin.php';

class PMD_Export_Path_Test extends TestCase {

	public function testPostWithEmptySlugUsesStableIdFallbackPath() {
		$this->assertSame(
			'post/post-4937.md',
			PMD_Plugin::build_markdown_path( $this->post( 4937, 'post', '' ) )
		);
	}

	public function testPageWithEmptySlugUsesStableIdFallbackPath() {
		$this->assertSame(
			'page/page-3814.md',
			PMD_Plugin::build_markdown_path( $this->post( 3814, 'page', '' ) )
		);
	}

	public function testExistingSlugStillDefinesExportPath() {
		$this->assertSame(
			'post/amazing-potatoes.md',
			PMD_Plugin::build_markdown_path( $this->post( 4163, 'post', 'amazing-potatoes' ) )
		);
	}

	public function testCurrentSluglessFallbackPathIsAccepted() {
		$this->assertTrue(
			$this->assert_id_fallback_path_is_current(
				'post/post-4937.md',
				$this->post( 4937, 'post', '' )
			)
		);
	}

	public function testCurrentSluglessFallbackPathDoesNotWriteFallbackAsPostSlug() {
		$this->assertTrue(
			$this->is_current_slugless_fallback_path(
				'post/post-4937.md',
				$this->post( 4937, 'post', '' )
			)
		);
	}

	public function testStaleFallbackPathIsRejectedAfterPostReceivesSlug() {
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'fallback filename is stale' );

		$this->assert_id_fallback_path_is_current(
			'post/post-4937.md',
			$this->post( 4937, 'post', 'test-post-from-cli' )
		);
	}

	public function testFallbackShapedRealSlugIsAcceptedWhenItIsCurrent() {
		$this->assertTrue(
			$this->assert_id_fallback_path_is_current(
				'post/post-4937.md',
				$this->post( 4937, 'post', 'post-4937' )
			)
		);
	}

	private function post( $id, $post_type, $post_name ) {
		$post              = new WP_Post();
		$post->ID          = $id;
		$post->post_type   = $post_type;
		$post->post_name   = $post_name;
		$post->post_parent = 0;

		return $post;
	}

	private function assert_id_fallback_path_is_current( $path, WP_Post $post ) {
		$method = new ReflectionMethod( 'PMD_Plugin', 'assert_id_fallback_path_is_current' );
		$method->setAccessible( true );
		$method->invoke( null, $path, $post );

		return true;
	}

	private function is_current_slugless_fallback_path( $path, WP_Post $post ) {
		$method = new ReflectionMethod( 'PMD_Plugin', 'is_current_slugless_fallback_path' );
		$method->setAccessible( true );

		return $method->invoke( null, $path, $post );
	}
}
