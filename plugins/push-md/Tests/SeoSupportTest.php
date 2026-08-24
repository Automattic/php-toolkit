<?php

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/wp-' . uniqid() . '/' );
}

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public $ID          = 0;
		public $post_type   = 'post';
		public $post_title  = '';
		public $post_name   = '';
		public $post_status = 'publish';
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key, $single = false ) {
		unset( $single );
		$post_id = intval( $post_id );
		if ( isset( $GLOBALS['mock_post_meta'][ $post_id ][ $key ] ) ) {
			return $GLOBALS['mock_post_meta'][ $post_id ][ $key ];
		}
		return '';
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $post_id, $key, $value ) {
		$post_id                                       = intval( $post_id );
		$GLOBALS['mock_post_meta'][ $post_id ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'metadata_exists' ) ) {
	function metadata_exists( $meta_type, $object_id, $meta_key ) {
		unset( $meta_type );
		$object_id = intval( $object_id );
		return isset( $GLOBALS['mock_post_meta'][ $object_id ][ $meta_key ] );
	}
}

if ( ! function_exists( 'wp_get_attachment_url' ) ) {
	function wp_get_attachment_url( $attachment_id ) {
		$attachment_id = intval( $attachment_id );
		if ( $attachment_id > 0 ) {
			return 'http://example.org/wp-content/uploads/attachment-' . $attachment_id . '.png';
		}
		return false;
	}
}

if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( $post_id ) {
		unset( $post_id );
		return 'post';
	}
}

require_once __DIR__ . '/../class-push-md-seo.php';
if ( file_exists( __DIR__ . '/../class-push-md-media.php' ) ) {
	require_once __DIR__ . '/../class-push-md-media.php';
}

/**
 * Unit tests for Push MD SEO & OpenGraph Frontmatter Support.
 */
class SeoSupportTest extends TestCase {

	/** @before */
	public function set_up() {
		$GLOBALS['mock_post_meta'] = array();
	}

	public function testAddSupportedFrontmatterKeys() {
		$keys   = array( 'title', 'date', 'status' );
		$merged = Push_MD_SEO::add_supported_frontmatter_keys( $keys );

		$this->assertContains( 'seo_title', $merged );
		$this->assertContains( 'seo_description', $merged );
		$this->assertContains( 'seo_keywords', $merged );
		$this->assertContains( 'og_title', $merged );
		$this->assertContains( 'og_description', $merged );
		$this->assertContains( 'og_image', $merged );
		$this->assertContains( 'canonical', $merged );
		$this->assertContains( 'schema_type', $merged );
	}

	public function testExportFrontmatterRankMathMeta() {
		$post_id                               = 10;
		$GLOBALS['mock_post_meta'][ $post_id ] = array(
			'rank_math_title'                => 'Rank Math Title',
			'rank_math_description'          => 'Rank Math Desc',
			'rank_math_focus_keyword'        => 'keyword1, keyword2',
			'rank_math_facebook_title'       => 'Rank Math OG Title',
			'rank_math_facebook_description' => 'Rank Math OG Desc',
			'rank_math_facebook_image'       => 'http://example.org/uploads/og-hero.png',
			'rank_math_canonical_url'        => 'https://example.org/canonical-page',
			'rank_math_rich_snippet'         => 'article',
		);

		$post            = new stdClass();
		$post->ID        = $post_id;
		$post->post_type = 'post';

		$exported = Push_MD_SEO::export_frontmatter( array(), $post );

		$this->assertSame( array( 'Rank Math Title' ), $exported['seo_title'] );
		$this->assertSame( array( 'Rank Math Desc' ), $exported['seo_description'] );
		$this->assertSame( array( 'keyword1', 'keyword2' ), $exported['seo_keywords'] );
		$this->assertSame( array( 'Rank Math OG Title' ), $exported['og_title'] );
		$this->assertSame( array( 'Rank Math OG Desc' ), $exported['og_description'] );
		$this->assertSame( array( 'http://example.org/uploads/og-hero.png' ), $exported['og_image'] );
		$this->assertSame( array( 'https://example.org/canonical-page' ), $exported['canonical'] );
		$this->assertSame( array( 'article' ), $exported['schema_type'] );
	}

	public function testExportFrontmatterYoastMeta() {
		$post_id                               = 20;
		$GLOBALS['mock_post_meta'][ $post_id ] = array(
			'rank_math_title'                    => '',
			'rank_math_description'              => '',
			'rank_math_focus_keyword'            => '',
			'_yoast_wpseo_title'                 => 'Yoast Title',
			'_yoast_wpseo_metadesc'              => 'Yoast Desc',
			'_yoast_wpseo_focuskw'               => 'primary-kw',
			'_yoast_wpseo_opengraph-title'       => 'Yoast OG Title',
			'_yoast_wpseo_opengraph-description' => 'Yoast OG Desc',
			'_yoast_wpseo_opengraph-image'       => 'http://example.org/uploads/yoast-og.png',
			'_yoast_wpseo_canonical'             => 'https://example.org/yoast-canonical',
			'_yoast_wpseo_schema_article_type'   => 'NewsArticle',
		);

		$post            = new stdClass();
		$post->ID        = $post_id;
		$post->post_type = 'post';

		$exported = Push_MD_SEO::export_frontmatter( array(), $post );

		$this->assertSame( array( 'Yoast Title' ), $exported['seo_title'] );
		$this->assertSame( array( 'Yoast Desc' ), $exported['seo_description'] );
		$this->assertSame( array( 'primary-kw' ), $exported['seo_keywords'] );
		$this->assertSame( array( 'Yoast OG Title' ), $exported['og_title'] );
		$this->assertSame( array( 'Yoast OG Desc' ), $exported['og_description'] );
		$this->assertSame( array( 'http://example.org/uploads/yoast-og.png' ), $exported['og_image'] );
		$this->assertSame( array( 'https://example.org/yoast-canonical' ), $exported['canonical'] );
		$this->assertSame( array( 'NewsArticle' ), $exported['schema_type'] );
	}

	public function testImportFrontmatterRankMathMeta() {
		$post_id  = 30;
		$metadata = array(
			'seo_title'       => 'Imported SEO Title',
			'seo_description' => 'Imported SEO Description',
			'seo_keywords'    => array( 'kw1', 'kw2' ),
			'og_title'        => 'Imported OG Title',
			'og_description'  => 'Imported OG Description',
			'og_image'        => 'http://example.org/uploads/og-image.png',
			'canonical'       => 'https://example.org/imported-canonical',
			'schema_type'     => 'BlogPosting',
		);

		Push_MD_SEO::import_frontmatter( $post_id, $metadata );

		// Check if updated in mock meta (either rank_math or _yoast).
		$meta = $GLOBALS['mock_post_meta'][ $post_id ];
		$title = isset( $meta['rank_math_title'] ) ? $meta['rank_math_title'] : $meta['_yoast_wpseo_title'];
		$desc  = isset( $meta['rank_math_description'] ) ? $meta['rank_math_description'] : $meta['_yoast_wpseo_metadesc'];
		$og_t  = isset( $meta['rank_math_facebook_title'] ) ? $meta['rank_math_facebook_title'] : $meta['_yoast_wpseo_opengraph-title'];
		$og_d  = isset( $meta['rank_math_facebook_description'] ) ? $meta['rank_math_facebook_description'] : $meta['_yoast_wpseo_opengraph-description'];
		$og_i  = isset( $meta['rank_math_facebook_image'] ) ? $meta['rank_math_facebook_image'] : $meta['_yoast_wpseo_opengraph-image'];
		$canon = isset( $meta['rank_math_canonical_url'] ) ? $meta['rank_math_canonical_url'] : $meta['_yoast_wpseo_canonical'];

		$this->assertSame( 'Imported SEO Title', $title );
		$this->assertSame( 'Imported SEO Description', $desc );
		$this->assertSame( 'Imported OG Title', $og_t );
		$this->assertSame( 'Imported OG Description', $og_d );
		$this->assertSame( 'http://example.org/uploads/og-image.png', $og_i );
		$this->assertSame( 'https://example.org/imported-canonical', $canon );
	}

	public function testImportFrontmatterOgImageWithAttachmentId() {
		$post_id  = 50;
		$metadata = array(
			'og_image' => '42',
		);

		Push_MD_SEO::import_frontmatter( $post_id, $metadata );

		$meta     = $GLOBALS['mock_post_meta'][ $post_id ];
		$image_url = isset( $meta['rank_math_facebook_image'] ) ? $meta['rank_math_facebook_image'] : $meta['_yoast_wpseo_opengraph-image'];
		$image_id  = isset( $meta['rank_math_facebook_image_id'] ) ? $meta['rank_math_facebook_image_id'] : $meta['_yoast_wpseo_opengraph-image-id'];

		$this->assertNotEmpty( $image_url );
		$this->assertSame( 42, $image_id );
	}

	public function testExportFrontmatterPillarContent() {
		$post_id         = 60;
		$post            = new WP_Post();
		$post->ID        = $post_id;
		$post->post_type = 'post';

		$GLOBALS['mock_post_meta'][ $post_id ] = array(
			'_pushmd_seo_is_pillar'   => '1',
			'rank_math_pillar_content' => 'on',
		);

		$metadata = Push_MD_SEO::export_frontmatter( array(), $post );

		$this->assertSame( array( 'true' ), $metadata['seo_is_pillar'] );
	}

	public function testImportFrontmatterPillarContent() {
		$post_id  = 70;
		$metadata = array(
			'seo_is_pillar' => 'true',
		);

		Push_MD_SEO::import_frontmatter( $post_id, $metadata );

		$meta = $GLOBALS['mock_post_meta'][ $post_id ];
		$this->assertSame( '1', $meta['_pushmd_seo_is_pillar'] );
		$pillar_flag = isset( $meta['rank_math_pillar_content'] ) ? $meta['rank_math_pillar_content'] : ( isset( $meta['_yoast_wpseo_is_cornerstone'] ) ? $meta['_yoast_wpseo_is_cornerstone'] : '' );
		$this->assertTrue( 'on' === $pillar_flag || '1' === $pillar_flag );
	}

	public function testImportFrontmatterPillarContentVariations() {
		$inputs = array( 1, '1', true, 'true', 'yes', 'on' );

		foreach ( $inputs as $idx => $input_val ) {
			$post_id  = 100 + $idx;
			$metadata = array(
				'seo_is_pillar' => $input_val,
			);

			Push_MD_SEO::import_frontmatter( $post_id, $metadata );

			$meta = $GLOBALS['mock_post_meta'][ $post_id ];
			$this->assertSame( '1', $meta['_pushmd_seo_is_pillar'] );
			$this->assertTrue( Push_MD_SEO::get_post_is_pillar( $post_id ) );

			$post            = new WP_Post();
			$post->ID        = $post_id;
			$post->post_type = 'post';

			$exported = Push_MD_SEO::export_frontmatter( array(), $post );
			$this->assertSame( array( 'true' ), $exported['seo_is_pillar'] );
		}
	}

	public function testCleanMetadataValueCollapsesNewlinesAndWhitespace() {
		$raw = "SEO description line 1.\n\nLine 2.\t  Line 3.";
		$cleaned = Push_MD_Plugin::clean_metadata_value( $raw );
		$this->assertEquals( 'SEO description line 1. Line 2. Line 3.', $cleaned );
	}

	public function testExportPostWithMultilineSeoAndExcerptProducesSingleLineFrontmatter() {
		$post_id                               = 405;
		$GLOBALS['mock_post_meta'][ $post_id ] = array(
			'rank_math_description'          => "Rank Math meta description line 1.\n\nLine 2.",
			'rank_math_facebook_description' => "Facebook description line 1.\n\tLine 2.",
		);

		$post            = new stdClass();
		$post->ID        = $post_id;
		$post->post_type = 'post';

		$exported = Push_MD_SEO::export_frontmatter( array(), $post );
		$cleaned  = Push_MD_Plugin::clean_metadata_value( $exported );

		$this->assertSame( array( 'Rank Math meta description line 1. Line 2.' ), $cleaned['seo_description'] );
		$this->assertSame( array( 'Facebook description line 1. Line 2.' ), $cleaned['og_description'] );
	}
}
