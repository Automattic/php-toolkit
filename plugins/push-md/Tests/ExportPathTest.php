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
		public $post_title  = '';
		public $post_date   = '2000-01-01 00:00:00';
		public $post_date_gmt = '2000-01-01 00:00:00';
		public $post_modified_gmt = '2000-01-01 00:00:00';
		public $post_content = '<!-- wp:paragraph --><p>Test content</p><!-- /wp:paragraph -->';
		public $post_excerpt = '';
	}
}

$push_md_test_posts = array();

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

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability ) {
		unset( $capability );

		return true;
	}
}

if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( $args = array() ) {
		global $push_md_test_posts;

		$posts = $push_md_test_posts;

		if ( isset( $args['post_type'] ) ) {
			$post_types = (array) $args['post_type'];
			$posts      = array_filter(
				$posts,
				function ( $post ) use ( $post_types ) {
					return in_array( $post->post_type, $post_types, true );
				}
			);
		}

		if ( isset( $args['name'] ) ) {
			$posts = array_filter(
				$posts,
				function ( $post ) use ( $args ) {
					return $post->post_name === $args['name'];
				}
			);
		}

		if ( isset( $args['post_parent'] ) ) {
			$posts = array_filter(
				$posts,
				function ( $post ) use ( $args ) {
					return intval( $post->post_parent ) === intval( $args['post_parent'] );
				}
			);
		}

		if ( isset( $args['post_status'] ) ) {
			$statuses = (array) $args['post_status'];
			$posts    = array_filter(
				$posts,
				function ( $post ) use ( $statuses ) {
					return in_array( $post->post_status, $statuses, true );
				}
			);
		}

		usort(
			$posts,
			function ( $a, $b ) {
				return intval( $a->ID ) - intval( $b->ID );
			}
		);

		if ( isset( $args['fields'] ) && 'ids' === $args['fields'] ) {
			return array_map(
				function ( $post ) {
					return intval( $post->ID );
				},
				$posts
			);
		}

		return $posts;
	}
}

if ( ! function_exists( 'get_post' ) ) {
	function get_post( $post_id ) {
		global $push_md_test_posts;

		foreach ( $push_md_test_posts as $post ) {
			if ( intval( $post->ID ) === intval( $post_id ) ) {
				return $post;
			}
		}

		return null;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		unset( $name );

		return $default;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0 ) {
		return json_encode( $data, $options );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

require_once dirname( __DIR__ ) . '/class-push-md-plugin.php';

class PMD_Export_Path_Test extends TestCase {

	/**
	 * @after
	 */
	public function reset_test_posts() {
		global $push_md_test_posts;

		$push_md_test_posts = array();
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

	public function testDuplicatePathThrowsActionableErrorNamingBothPosts() {
		$this->set_test_posts(
			array(
				$this->post( 101, 'post', 'shared-slug' ),
				$this->post( 202, 'post', 'shared-slug' ),
			)
		);

		try {
			$this->export_wordpress_content();
			$this->fail( 'Expected duplicate export paths to be rejected.' );
		} catch ( Exception $exception ) {
			$message = $exception->getMessage();
			$this->assertStringContainsString( 'post/shared-slug.md', $message );
			$this->assertStringContainsString( '101', $message );
			$this->assertStringContainsString( '202', $message );
		}
	}

	public function testDetectExportPathCollisionsReturnsConflictingGroup() {
		$this->set_test_posts(
			array(
				$this->post( 101, 'post', 'shared-slug' ),
				$this->post( 202, 'post', 'shared-slug' ),
				$this->post( 303, 'post', 'unique-slug' ),
			)
		);

		$collisions = Push_MD_Plugin::detect_export_path_collisions();

		$this->assertCount( 1, $collisions );
		$this->assertSame( 'post/shared-slug.md', $collisions[0]['path'] );
		$this->assertSame( array( 101, 202 ), $collisions[0]['post_ids'] );
	}

	public function testDetectExportPathCollisionsIgnoresUniquePaths() {
		$this->set_test_posts(
			array(
				$this->post( 101, 'post', 'first-slug' ),
				$this->post( 202, 'post', 'second-slug' ),
			)
		);

		$this->assertSame( array(), Push_MD_Plugin::detect_export_path_collisions() );
	}

	public function testDetectExportPathCollisionsSkipsUnbuildablePaths() {
		// A published page pointing at a missing parent makes build_markdown_path()
		// throw for that page; detection must skip it instead of aborting, and
		// still report the unrelated post collision.
		$orphan              = $this->post( 70, 'page', 'orphan' );
		$orphan->post_parent = 9999;

		$this->set_test_posts(
			array(
				$orphan,
				$this->post( 101, 'post', 'shared-slug' ),
				$this->post( 202, 'post', 'shared-slug' ),
			)
		);

		$collisions = Push_MD_Plugin::detect_export_path_collisions();

		$this->assertCount( 1, $collisions );
		$this->assertSame( 'post/shared-slug.md', $collisions[0]['path'] );
		$this->assertSame( array( 101, 202 ), $collisions[0]['post_ids'] );
	}

	private function post( $id, $post_type, $post_name, $post_status = 'publish', $post_date_gmt = '2000-01-01 00:00:00' ) {
		$post              = new WP_Post();
		$post->ID          = $id;
		$post->post_type   = $post_type;
		$post->post_name   = $post_name;
		$post->post_parent = 0;
		$post->post_status = $post_status;
		$post->post_title  = 'Post ' . $id;
		$post->post_date   = $post_date_gmt;
		$post->post_date_gmt = $post_date_gmt;
		$post->post_modified_gmt = $post_date_gmt;

		return $post;
	}

	private function set_test_posts( $posts ) {
		global $push_md_test_posts;

		$push_md_test_posts = $posts;
	}

	private function export_wordpress_content() {
		$method = new ReflectionMethod( Push_MD_Plugin::class, 'export_wordpress_content' );
		$method->setAccessible( true );

		return $method->invoke( null );
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
