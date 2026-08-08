<?php

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wp/' );
}

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public $ID                = 0;
		public $post_type         = 'post';
		public $post_name         = '';
		public $post_parent       = 0;
		public $post_author       = 1;
		public $post_title        = '';
		public $post_excerpt      = '';
		public $post_status       = 'publish';
		public $post_content      = '';
		public $post_date_gmt     = '2026-01-01 00:00:00';
		public $post_modified     = '2026-01-01 00:00:00';
		public $post_modified_gmt = '2026-01-01 00:00:00';
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $title ) {
		$title = strtolower( (string) $title );
		$title = preg_replace( '/[^a-z0-9_-]+/', '-', $title );

		return trim( $title, '-' );
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '/' ) {
		return 'https://example.com' . $path;
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $key, $value, $url = '' ) {
		$query = is_array( $key ) ? http_build_query( $key ) : rawurlencode( $key ) . '=' . rawurlencode( $value );
		$sep   = false !== strpos( $url, '?' ) ? '&' : '?';

		return $url . $sep . $query;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $message = '';

		public function __construct( $message = '' ) {
			$this->message = $message;
		}

		public function get_error_message() {
			return $this->message;
		}
	}
}

global $mock_wp_posts, $mock_wp_postmeta, $mock_wp_transients, $mock_next_post_id;
$mock_wp_posts      = array();
$mock_wp_postmeta   = array();
$mock_wp_transients = array();
$mock_next_post_id  = 100;

if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post( $postarr, $wp_error = false ) {
		global $mock_wp_posts, $mock_next_post_id;

		$post              = new WP_Post();
		$post->ID          = isset( $postarr['ID'] ) ? intval( $postarr['ID'] ) : ++$mock_next_post_id;
		$post->post_type   = isset( $postarr['post_type'] ) ? $postarr['post_type'] : 'post';
		$post->post_status = isset( $postarr['post_status'] ) ? $postarr['post_status'] : 'draft';
		$post->post_parent = isset( $postarr['post_parent'] ) ? intval( $postarr['post_parent'] ) : 0;
		$post->post_title  = isset( $postarr['post_title'] ) ? $postarr['post_title'] : '';
		$post->post_content = isset( $postarr['post_content'] ) ? $postarr['post_content'] : '';
		$post->post_name   = isset( $postarr['post_name'] ) ? $postarr['post_name'] : '';

		$mock_wp_posts[ $post->ID ] = $post;

		return $post->ID;
	}
}

if ( ! function_exists( 'wp_update_post' ) ) {
	function wp_update_post( $postarr, $wp_error = false ) {
		global $mock_wp_posts;

		$post_id = isset( $postarr['ID'] ) ? intval( $postarr['ID'] ) : 0;
		if ( ! isset( $mock_wp_posts[ $post_id ] ) ) {
			return new WP_Error( 'Invalid post ID' );
		}

		$post = $mock_wp_posts[ $post_id ];
		if ( isset( $postarr['post_content'] ) ) {
			$post->post_content = $postarr['post_content'];
		}
		if ( isset( $postarr['post_title'] ) ) {
			$post->post_title = $postarr['post_title'];
		}
		if ( isset( $postarr['post_status'] ) ) {
			$post->post_status = $postarr['post_status'];
		}

		return $post->ID;
	}
}

if ( ! function_exists( 'get_post' ) ) {
	function get_post( $post_id = null ) {
		global $mock_wp_posts;

		$post_id = intval( $post_id );
		return isset( $mock_wp_posts[ $post_id ] ) ? $mock_wp_posts[ $post_id ] : null;
	}
}

if ( ! function_exists( 'wp_delete_post' ) ) {
	function wp_delete_post( $post_id, $force_delete = false ) {
		global $mock_wp_posts, $mock_wp_postmeta;

		$post_id = intval( $post_id );
		unset( $mock_wp_posts[ $post_id ] );
		unset( $mock_wp_postmeta[ $post_id ] );

		return true;
	}
}

if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( $args = array() ) {
		global $mock_wp_posts, $mock_wp_postmeta;

		$results = array();
		foreach ( $mock_wp_posts as $post ) {
			if ( isset( $args['post_type'] ) && $post->post_type !== $args['post_type'] ) {
				continue;
			}
			if ( isset( $args['post_parent'] ) && intval( $post->post_parent ) !== intval( $args['post_parent'] ) ) {
				continue;
			}
			if ( isset( $args['post_status'] ) && $post->post_status !== $args['post_status'] ) {
				continue;
			}
			if ( isset( $args['meta_key'] ) ) {
				$key   = $args['meta_key'];
				$has_meta = isset( $mock_wp_postmeta[ $post->ID ][ $key ] );
				if ( ! $has_meta ) {
					continue;
				}
			}

			$results[] = $post;
		}

		return $results;
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $post_id, $meta_key, $meta_value ) {
		global $mock_wp_postmeta;

		$mock_wp_postmeta[ intval( $post_id ) ][ $meta_key ] = $meta_value;
		return true;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key = '', $single = false ) {
		global $mock_wp_postmeta;

		$post_id = intval( $post_id );
		if ( isset( $mock_wp_postmeta[ $post_id ][ $key ] ) ) {
			return $mock_wp_postmeta[ $post_id ][ $key ];
		}

		return $single ? '' : array();
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $transient, $value, $expiration = 0 ) {
		global $mock_wp_transients;

		$mock_wp_transients[ $transient ] = $value;
		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $transient ) {
		global $mock_wp_transients;

		return isset( $mock_wp_transients[ $transient ] ) ? $mock_wp_transients[ $transient ] : false;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $transient ) {
		global $mock_wp_transients;

		unset( $mock_wp_transients[ $transient ] );
		return true;
	}
}

if ( ! function_exists( 'wp_slash' ) ) {
	function wp_slash( $value ) {
		return $value;
	}
}

if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( $user_id ) {
		return false;
	}
}

if ( ! function_exists( 'get_the_terms' ) ) {
	function get_the_terms( $post_id, $taxonomy ) {
		return false;
	}
}

if ( ! function_exists( 'remove_query_arg' ) ) {
	function remove_query_arg( $key, $query = false ) {
		return 'https://example.com/clean';
	}
}

if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $post_id = 0 ) {
		return 'https://example.com/post-' . intval( $post_id );
	}
}

if ( ! function_exists( 'get_post_thumbnail_id' ) ) {
	function get_post_thumbnail_id( $post_id ) {
		return false;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		return $value;
	}
}

require_once dirname( __DIR__ ) . '/class-push-md-plugin.php';
require_once dirname( __DIR__ ) . '/class-push-md-draft-previews.php';

class DraftPreviewTest extends TestCase {

	protected function setUp(): void {
		global $mock_wp_posts, $mock_wp_postmeta, $mock_wp_transients, $mock_next_post_id;
		$mock_wp_posts      = array();
		$mock_wp_postmeta   = array();
		$mock_wp_transients = array();
		$mock_next_post_id  = 100;
	}

	public function testGuardConditionDetectsDraftPushForPublishedPost() {
		$published_post              = new WP_Post();
		$published_post->ID          = 1;
		$published_post->post_status = 'publish';

		$draft_post              = new WP_Post();
		$draft_post->ID          = 2;
		$draft_post->post_status = 'draft';

		$this->assertTrue(
			Push_MD_Draft_Previews::is_draft_preview_for_published_post( $published_post, 'draft' )
		);
		$this->assertFalse(
			Push_MD_Draft_Previews::is_draft_preview_for_published_post( $published_post, 'publish' )
		);
		$this->assertFalse(
			Push_MD_Draft_Previews::is_draft_preview_for_published_post( $draft_post, 'draft' )
		);
		$this->assertFalse(
			Push_MD_Draft_Previews::is_draft_preview_for_published_post( null, 'draft' )
		);
	}

	public function testRandomTokenGeneration() {
		$token1 = Push_MD_Draft_Previews::generate_random_token();
		$token2 = Push_MD_Draft_Previews::generate_random_token();

		$this->assertSame( 32, strlen( $token1 ) );
		$this->assertSame( 32, strlen( $token2 ) );
		$this->assertNotEquals( $token1, $token2 );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $token1 );
	}

	public function testCreateAndUpdatePreviewRevision() {
		$post_id = 42;

		$res1 = Push_MD_Draft_Previews::create_or_update_preview_revision( $post_id, 'Draft Content v1' );
		$this->assertTrue( $res1['is_new'] );
		$revision_id = $res1['revision_id'];

		$revision = get_post( $revision_id );
		$this->assertNotNull( $revision );
		$this->assertSame( 'revision', $revision->post_type );
		$this->assertSame( 42, $revision->post_parent );
		$this->assertSame( 'Draft Content v1', $revision->post_content );

		// Repeated call should update existing revision, not create a new one.
		$res2 = Push_MD_Draft_Previews::create_or_update_preview_revision( $post_id, 'Draft Content v2' );
		$this->assertFalse( $res2['is_new'] );
		$this->assertSame( $revision_id, $res2['revision_id'] );

		$updated_revision = get_post( $revision_id );
		$this->assertSame( 'Draft Content v2', $updated_revision->post_content );
	}

	public function testTokenGenerationAndReuseAcrossDraftPushes() {
		$post_id     = 55;
		$preview_res = Push_MD_Draft_Previews::create_or_update_preview_revision( $post_id, 'Initial' );
		$revision_id = $preview_res['revision_id'];

		$token_res1 = Push_MD_Draft_Previews::generate_or_refresh_preview_token( $post_id, $revision_id );
		$token1     = $token_res1['token'];
		$url1       = $token_res1['url'];

		$this->assertSame( 32, strlen( $token1 ) );
		$this->assertStringContainsString( 'pushmd_preview=' . $token1, $url1 );

		// Second push: token should be reused!
		$token_res2 = Push_MD_Draft_Previews::generate_or_refresh_preview_token( $post_id, $revision_id );
		$token2     = $token_res2['token'];

		$this->assertSame( $token1, $token2 );

		// Verify token works via transient lookup
		$verified_revision_id = Push_MD_Draft_Previews::verify_token( 55, $token1 );
		$this->assertSame( $revision_id, $verified_revision_id );
	}

	public function testVerifyTokenRejectsInvalidOrExpiredTokens() {
		$this->assertFalse( Push_MD_Draft_Previews::verify_token( 55, 'short' ) );
		$this->assertFalse( Push_MD_Draft_Previews::verify_token( 55, 'nonexistent00000000000000000000' ) );
		$this->assertFalse( Push_MD_Draft_Previews::verify_token( 0, '0123456789abcdef0123456789abcdef' ) );
	}

	public function testPublishCleanupRemovesRevisionAndTransient() {
		$post_id     = 88;
		$preview_res = Push_MD_Draft_Previews::create_or_update_preview_revision( $post_id, 'Content' );
		$revision_id = $preview_res['revision_id'];
		$token_res   = Push_MD_Draft_Previews::generate_or_refresh_preview_token( $post_id, $revision_id );
		$token       = $token_res['token'];

		$this->assertNotNull( get_post( $revision_id ) );
		$this->assertNotFalse( get_transient( 'pushmd_preview_' . $post_id ) );

		// Cleanup on publish
		Push_MD_Draft_Previews::cleanup_preview( $post_id );

		$this->assertNull( get_post( $revision_id ) );
		$this->assertFalse( get_transient( 'pushmd_preview_' . $post_id ) );
		$this->assertFalse( Push_MD_Draft_Previews::verify_token( $post_id, $token ) );
	}

	public function testExportPostToMarkdownUsesActiveDraftPreviewRevision() {
		$post               = new WP_Post();
		$post->ID           = 99;
		$post->post_title   = 'Live Title';
		$post->post_name    = 'live-title';
		$post->post_status  = 'publish';
		$post->post_content = 'Live Content';
		$post->post_date_gmt = '';
		$post->post_date     = '';
		$post->post_excerpt  = '';

		global $mock_wp_posts;
		$mock_wp_posts[99] = $post;

		Push_MD_Draft_Previews::create_or_update_preview_revision( 99, 'Draft Revision Content' );

		$exported = Push_MD_Plugin::export_post_to_markdown( $post );

		$this->assertStringContainsString( 'status: "draft"', $exported );
		$this->assertStringContainsString( 'Draft Revision Content', $exported );
		$this->assertStringNotContainsString( 'Live Content', $exported );
	}

	public function testDiscardStatusCleansUpPreviewRevisionAndTransient() {
		$post               = new WP_Post();
		$post->ID           = 77;
		$post->post_title   = 'Existing Published Post';
		$post->post_name    = 'existing-post';
		$post->post_status  = 'publish';
		$post->post_content = 'Live Content';
		$post->post_date_gmt = '';
		$post->post_date     = '';
		$post->post_excerpt  = '';

		global $mock_wp_posts;
		$mock_wp_posts[77] = $post;

		$preview_res = Push_MD_Draft_Previews::create_or_update_preview_revision( 77, 'Draft Content' );
		$revision_id = $preview_res['revision_id'];
		Push_MD_Draft_Previews::generate_or_refresh_preview_token( 77, $revision_id );

		$this->assertNotNull( get_post( $revision_id ) );
		$this->assertNotFalse( get_transient( 'pushmd_preview_77' ) );

		// Simulate push with status: discard
		Push_MD_Draft_Previews::cleanup_preview( 77 );

		$this->assertNull( get_post( $revision_id ) );
		$this->assertFalse( get_transient( 'pushmd_preview_77' ) );

		$exported = Push_MD_Plugin::export_post_to_markdown( $post );
		$this->assertStringContainsString( 'status: "published"', $exported );
		$this->assertStringContainsString( 'Live Content', $exported );
	}
}
