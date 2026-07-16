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
		public $post_status = 'publish';
	}
}

$push_md_test_posts = array();

if ( ! function_exists( 'get_post' ) ) {
	function get_post( $post_id ) {
		global $push_md_test_posts;

		return isset( $push_md_test_posts[ $post_id ] ) ? $push_md_test_posts[ $post_id ] : null;
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
		return in_array( $post_type, array( 'post', 'page', 'wpdocs_document' ), true );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = array() ) {
		return array_merge( $defaults, $args );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return (string) $text;
	}
}

if ( ! function_exists( 'taxonomy_exists' ) ) {
	function taxonomy_exists( $taxonomy ) {
		unset( $taxonomy );

		return false;
	}
}

require_once dirname( __DIR__ ) . '/class-push-md-plugin.php';

class PMD_Export_Path_Test extends TestCase {
	public static function setUpBeforeClass(): void {
		Push_MD_Plugin::register_content_adapter(
			'wpdocs_document',
			array(
				'hierarchical'       => true,
				'frontmatter_fields' => array( 'collections', 'topics' ),
				'export_metadata'    => function () {
					return array(
						'collections' => array( 'reference', 'guides', 'guides' ),
						'topics'      => array( 'wordpress' ),
					);
				},
			)
		);
	}

	public function testPostWithEmptySlugUsesStableIdFallbackPath() {
		$this->assertSame(
			'post/post-4937.md',
			Push_MD_Plugin::build_markdown_path( $this->post( 4937, 'post', '' ) )
		);
	}

	public function testPageWithEmptySlugUsesStableIdFallbackPath() {
		$this->assertSame(
			'page/page-3814.md',
			Push_MD_Plugin::build_markdown_path( $this->post( 3814, 'page', '' ) )
		);
	}

	public function testExistingSlugStillDefinesExportPath() {
		$this->assertSame(
			'post/amazing-potatoes.md',
			Push_MD_Plugin::build_markdown_path( $this->post( 4163, 'post', 'amazing-potatoes' ) )
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

	public function testCustomHierarchicalAdapterBuildsStableNestedPath() {
		global $push_md_test_posts;

		$parent                    = $this->post( 900, 'wpdocs_document', 'guides' );
		$push_md_test_posts[900]    = $parent;
		$child                     = $this->post( 901, 'wpdocs_document', 'getting-started' );
		$child->post_parent        = 900;

		$this->assertSame(
			'wpdocs_document/guides/getting-started.md',
			Push_MD_Plugin::build_markdown_path( $child )
		);
	}

	public function testAdapterMetadataIsDeterministicAndDeduplicated() {
		$method = new ReflectionMethod( Push_MD_Plugin::class, 'export_adapter_metadata' );
		$method->setAccessible( true );
		$result = $method->invoke( null, $this->post( 902, 'wpdocs_document', 'metadata' ) );

		$this->assertSame( array( array( 'guides', 'reference' ) ), $result['collections'] );
		$this->assertSame( array( array( 'wordpress' ) ), $result['topics'] );
	}

	public function testDuplicateAdapterRegistrationIsRejected() {
		$this->expectException( InvalidArgumentException::class );
		Push_MD_Plugin::register_content_adapter( 'wpdocs_document' );
	}

	/**
	 * @dataProvider reservedAdapterFieldProvider
	 */
	public function testAdapterCannotClaimPushMdFrontMatterFields( $field ) {
		$this->expectException( InvalidArgumentException::class );
		Push_MD_Plugin::register_content_adapter(
			'reserved_' . $field,
			array( 'frontmatter_fields' => array( $field ) )
		);
	}

	public function reservedAdapterFieldProvider() {
		return array(
			'identity slug'   => array( 'slug' ),
			'identity type'   => array( 'type' ),
			'content id'      => array( 'id' ),
			'conflict marker' => array( 'modified_gmt' ),
		);
	}

	private function post( $id, $post_type, $post_name ) {
		$post              = new WP_Post();
		$post->ID          = $id;
		$post->post_type   = $post_type;
		$post->post_name   = $post_name;
		$post->post_parent = 0;
		$post->post_status = 'publish';

		return $post;
	}

	private function assert_id_fallback_path_is_current( $path, WP_Post $post ) {
		$method = new ReflectionMethod( Push_MD_Plugin::class, 'assert_id_fallback_path_is_current' );
		$method->setAccessible( true );
		$method->invoke( null, $path, $post );

		return true;
	}

	private function is_current_slugless_fallback_path( $path, WP_Post $post ) {
		$method = new ReflectionMethod( Push_MD_Plugin::class, 'is_current_slugless_fallback_path' );
		$method->setAccessible( true );

		return $method->invoke( null, $path, $post );
	}
}
