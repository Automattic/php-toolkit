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

	private function post( $id, $post_type, $post_name ) {
		$post              = new WP_Post();
		$post->ID          = $id;
		$post->post_type   = $post_type;
		$post->post_name   = $post_name;
		$post->post_parent = 0;

		return $post;
	}
}
