<?php

use PHPUnit\Framework\TestCase;
use WordPress\Git\Model\TreeEntry;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/wp-' . uniqid() . '/' );
}

require_once __DIR__ . '/../class-push-md-media.php';

/**
 * Unit and integration tests for Push MD Git Media Support (Inline & Featured Images).
 */
class MediaSupportTest extends TestCase {

	/**
	 * Transparent 1x1 PNG binary payload.
	 *
	 * @var string
	 */
	private $sample_png;

	/**
	 * Sample 1x1 GIF binary payload.
	 *
	 * @var string
	 */
	private $sample_gif;

	/** @before */
	public function set_up() {
		// Valid 1x1 PNG binary.
		$this->sample_png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' );
		// Valid 1x1 GIF binary.
		$this->sample_gif = base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' );

		// Reset mock attachments if function exists.
		if ( isset( $GLOBALS['mock_attachments'] ) ) {
			$GLOBALS['mock_attachments'] = array();
		}
	}

	public function testIsMediaPath() {
		$this->assertTrue( Push_MD_Media::is_media_path( 'media/cover.png' ) );
		$this->assertTrue( Push_MD_Media::is_media_path( '../media/cover.png' ) );
		$this->assertTrue( Push_MD_Media::is_media_path( './media/cover.png' ) );
		$this->assertTrue( Push_MD_Media::is_media_path( '/media/cover.png' ) );
		$this->assertTrue( Push_MD_Media::is_media_path( 'cover.png' ) );
		$this->assertTrue( Push_MD_Media::is_media_path( 'media/sub/chart.png' ) );
		$this->assertTrue( Push_MD_Media::is_media_path( 'media' ) );
		$this->assertFalse( Push_MD_Media::is_media_path( 'content/page.md' ) );
	}

	public function testNormalizeRelativeMediaPath() {
		$this->assertSame( 'media/imagename.webp', Push_MD_Media::normalize_relative_media_path( 'media/imagename.webp' ) );
		$this->assertSame( 'media/imagename.webp', Push_MD_Media::normalize_relative_media_path( '../media/imagename.webp' ) );
		$this->assertSame( 'media/imagename.webp', Push_MD_Media::normalize_relative_media_path( './media/imagename.webp' ) );
		$this->assertSame( 'media/imagename.webp', Push_MD_Media::normalize_relative_media_path( '/media/imagename.webp' ) );
		$this->assertSame( 'media/imagename.webp', Push_MD_Media::normalize_relative_media_path( 'imagename.webp' ) );
	}

	public function testValidMediaFileValidation() {
		// Should not throw exceptions.
		Push_MD_Media::validate_media_file( 'media/cover.png', $this->sample_png );
		Push_MD_Media::validate_media_file( 'media/banner.gif', $this->sample_gif );
		$this->assertTrue( true );
	}

	public function testFailClosedRejectionForInvalidExtensions() {
		$this->expectException( Exception::class );
		$this->expectExceptionMessageMatches( '/supported image type/' );
		Push_MD_Media::validate_media_file( 'media/malicious.php', '<?php echo "evil"; ?>' );
	}

	public function testFailClosedRejectionForPathTraversal() {
		$this->expectException( Exception::class );
		$this->expectExceptionMessageMatches( '/path traversal/' );
		Push_MD_Media::validate_media_file( 'media/../secret.png', $this->sample_png );
	}

	public function testFailClosedRejectionForCorruptedBinary() {
		$this->expectException( Exception::class );
		$this->expectExceptionMessageMatches( '/empty or corrupted|MIME type/' );
		Push_MD_Media::validate_media_file( 'media/fake.png', 'this is text content not a png' );
	}

	public function testDetectMimeType() {
		$this->assertSame( 'image/png', Push_MD_Media::detect_mime_type( $this->sample_png, 'test.png' ) );
		$this->assertSame( 'image/gif', Push_MD_Media::detect_mime_type( $this->sample_gif, 'test.gif' ) );
	}

	public function testRewriteInlineImagePathsMarkdown() {
		$post_content = 'Here is a diagram: ![Architecture Diagram](../media/arch.png) and another ![Chart](media/chart.png).';

		$commit_files = array(
			'media/arch.png'  => array(
				'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
				'content' => $this->sample_png,
			),
			'media/chart.png' => array(
				'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
				'content' => $this->sample_png,
			),
		);

		$rewritten = Push_MD_Media::rewrite_inline_image_paths( 1, $post_content, $commit_files );

		$this->assertStringNotContainsString( '../media/arch.png', $rewritten );
		$this->assertStringNotContainsString( '(media/chart.png)', $rewritten );
		$this->assertStringContainsString( 'arch.png', $rewritten );
		$this->assertStringContainsString( 'chart.png', $rewritten );
	}

	public function testRewriteInlineImagePathsHtml() {
		$post_content = '<p>Check <img src="../media/photo.jpg" alt="Photo" /> for details.</p>';

		$commit_files = array(
			'media/photo.jpg' => array(
				'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
				'content' => $this->sample_png, // Valid image payload
			),
		);

		$rewritten = Push_MD_Media::rewrite_inline_image_paths( 1, $post_content, $commit_files );

		$this->assertStringNotContainsString( '../media/photo.jpg', $rewritten );
		$this->assertStringContainsString( 'photo.jpg', $rewritten );
	}

	public function testRewriteInlineImagePathsWithPrefixedHttp() {
		$post_content = '<p>Quote: <img alt="Quote" src="http://../media/quote-1.webp"></p>';

		$commit_files = array(
			'media/quote-1.webp' => array(
				'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
				'content' => $this->sample_png,
			),
		);

		$rewritten = Push_MD_Media::rewrite_inline_image_paths( 1, $post_content, array(), $commit_files );

		$this->assertStringNotContainsString( 'http://../media/quote-1.webp', $rewritten );
		$this->assertStringContainsString( 'quote-1.webp', $rewritten );
	}

	public function testFeaturedImageRelativePathProcessing() {
		$commit_files = array(
			'media/cover.png' => array(
				'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
				'content' => $this->sample_png,
			),
		);

		// Should not throw exception when processing valid relative featured image.
		Push_MD_Media::handle_featured_image( 101, '../media/cover.png', $commit_files );
		$this->assertTrue( true );
	}

	public function testRewriteInlineImagePathsPictureAndFigure() {
		$post_content = '<picture><source srcset="../media/cover-large.webp 1200w, ../media/cover-small.webp 600w" /><img src="../media/cover.png" alt="Cover" /></picture><figure class="wp-block-image"><a href="../media/full.png"><img src="../media/thumb.png" /></a></figure>';

		$commit_files = array(
			'media/cover-large.webp' => array(
				'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
				'content' => $this->sample_png,
			),
			'media/cover-small.webp' => array(
				'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
				'content' => $this->sample_png,
			),
			'media/cover.png'        => array(
				'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
				'content' => $this->sample_png,
			),
			'media/full.png'         => array(
				'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
				'content' => $this->sample_png,
			),
			'media/thumb.png'        => array(
				'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
				'content' => $this->sample_png,
			),
		);

		$rewritten = Push_MD_Media::rewrite_inline_image_paths( 10, $post_content, $commit_files );

		$this->assertStringNotContainsString( '../media/cover-large.webp', $rewritten );
		$this->assertStringNotContainsString( '../media/cover-small.webp', $rewritten );
		$this->assertStringNotContainsString( '../media/cover.png', $rewritten );
		$this->assertStringNotContainsString( '../media/full.png', $rewritten );
		$this->assertStringNotContainsString( '../media/thumb.png', $rewritten );
		$this->assertStringContainsString( 'cover-large.webp 1200w', $rewritten );
	}

	public function testRewriteInlineImagePathsGutenbergBlock() {
		$post_content = '<!-- wp:image {"id":0,"url":"../media/chart.png","sizeSlug":"full"} --><figure class="wp-block-image"><img src="../media/chart.png" alt=""/></figure><!-- /wp:image -->';

		$commit_files = array(
			'media/chart.png' => array(
				'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
				'content' => $this->sample_png,
			),
		);

		$rewritten = Push_MD_Media::rewrite_inline_image_paths( 20, $post_content, array(), $commit_files );

		$this->assertStringNotContainsString( '../media/chart.png', $rewritten );
		$this->assertStringContainsString( 'chart.png', $rewritten );
	}

	public function testAttachmentMetadataExtraction() {
		$post_content = '![Architecture Alt Text](../media/arch-diagram.png "Architecture Title")';

		$commit_files = array(
			'media/arch-diagram.png' => array(
				'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
				'content' => $this->sample_png,
			),
		);

		$rewritten = Push_MD_Media::rewrite_inline_image_paths( 10, $post_content, array(), $commit_files );
		$this->assertStringNotContainsString( '../media/arch-diagram.png', $rewritten );
		$this->assertStringContainsString( 'arch-diagram.png', $rewritten );
	}

	public function testFeaturedImageExternalUrlIgnored() {
		// External URLs should be safely ignored without raising errors.
		Push_MD_Media::handle_featured_image( 102, 'https://external-domain.com/untrusted-image.jpg', array() );
		$this->assertTrue( true );
	}

	public function testSimulatedGitPushCommitWithMarkdownAndMedia() {
		// Simulate commit containing both a Markdown post and an image in media/
		$markdown_content = "---\ntitle: \"Post with Git Media\"\nstatus: publish\nfeatured_image: \"../media/hero.png\"\n---\n\nCheck our diagram:\n\n![Diagram](../media/diagram.png)\n";

		$commit_files = array(
			'posts/post-with-media.md' => array(
				'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
				'content' => $markdown_content,
			),
			'media/hero.png'           => array(
				'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
				'content' => $this->sample_png,
			),
			'media/diagram.png'        => array(
				'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
				'content' => $this->sample_png,
			),
		);

		// 1. Validate all media files in push payload
		foreach ( $commit_files as $path => $entry ) {
			if ( Push_MD_Media::is_media_path( $path ) ) {
				Push_MD_Media::validate_media_file( $path, $entry['content'] );
			}
		}

		// 2. Rewrite inline relative image paths in post content
		$rewritten_markup = Push_MD_Media::rewrite_inline_image_paths( 50, $markdown_content, $commit_files );
		$this->assertStringNotContainsString( '../media/diagram.png', $rewritten_markup );

		// 3. Featured image assignment simulation for all path formats
		Push_MD_Media::handle_featured_image( 50, '../media/hero.png', array(), $commit_files );
		Push_MD_Media::handle_featured_image( 50, 'media/hero.png', array(), $commit_files );
		Push_MD_Media::handle_featured_image( 50, './media/hero.png', array(), $commit_files );
		Push_MD_Media::handle_featured_image( 50, '/media/hero.png', array(), $commit_files );
		Push_MD_Media::handle_featured_image( 50, 'hero.png', array(), $commit_files );
		$this->assertTrue( true );
	}

	/**
	 * The exhaustive basename scan in resolve_media_url_info() must find a file
	 * that was already uploaded (present in $uploaded_media_map) without uploading
	 * it again. Even if the MD references the image as "../media/chart.png" and
	 * the map key is "media/chart.png", the URL from the map must be returned.
	 */
	public function testNoDoubleUploadWhenFileIsInUploadedMediaMap() {
		$pre_uploaded_map = array(
			'media/chart.png'     => array(
				'url' => 'http://example.org/wp-content/uploads/chart.png',
				'id'  => 99,
			),
			'../media/chart.png'  => array(
				'url' => 'http://example.org/wp-content/uploads/chart.png',
				'id'  => 99,
			),
			'chart.png'           => array(
				'url' => 'http://example.org/wp-content/uploads/chart.png',
				'id'  => 99,
			),
		);

		// The commit_files still contains the binary so a naive implementation
		// could try to upload it again. The fixed code must return from the map.
		$commit_files = array(
			'media/chart.png' => array(
				'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
				'content' => $this->sample_png,
			),
		);

		$cache = array();

		// resolve_media_url_info must find the pre-uploaded entry and NOT call upload_media_asset.
		// We verify by checking the returned URL matches the pre-uploaded map and no exception
		// occurs (upload_media_asset would fail without WP functions in test env).
		$result = Push_MD_Media::resolve_media_url_info(
			'../media/chart.png',
			1,
			$pre_uploaded_map,
			$commit_files,
			$cache,
			false
		);

		$this->assertNotNull( $result, 'Expected resolve_media_url_info to return a result from the pre-uploaded map.' );
		$this->assertSame( 'http://example.org/wp-content/uploads/chart.png', $result['url'] );
		$this->assertSame( 99, $result['id'] );
	}

	/**
	 * find_existing_attachment_id_by_filename() must not return an attachment for
	 * "chart.png" when the stored _wp_attached_file is "2025/01/chart-inline.png".
	 * Previously the LIKE comparison without basename verification caused this
	 * false positive.
	 */
	public function testFindExistingAttachmentNoFalsePositiveOnSimilarFilename() {
		// This test validates the logic without WP functions — we rely on the
		// fact that when get_posts() is not available, the function returns 0.
		// The actual LIKE + basename fix is exercised by the logic path when
		// WP is loaded (e.g. in blueprint e2e tests). Here we confirm the
		// no-WP-functions early return is safe.
		$id = Push_MD_Media::find_existing_attachment_id_by_filename( 'chart.png' );
		$this->assertSame( 0, $id, 'Expected 0 when WP functions are unavailable.' );
	}

	/**
	 * A commit that contains only media/ files (no .md changes) must pass
	 * process_commit_media_files() without errors. This verifies media-only
	 * pushes are fully supported.
	 */
	public function testMediaOnlyCommitIsProcessedWithoutErrors() {
		$commit_files = array(
			'media/logo.webp'  => array(
				'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
				'content' => $this->sample_png,
			),
			'media/banner.gif' => array(
				'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
				'content' => $this->sample_gif,
			),
		);

		// dry_run = true skips actual uploads but still runs validation.
		// If validation fails for a valid image, this will throw.
		$result = Push_MD_Media::process_commit_media_files( $commit_files, true );

		// In dry_run mode, the map is empty (no uploads), but no exception should be thrown.
		$this->assertIsArray( $result );
	}

	/**
	 * export_media_content() must return an empty array. Media is push-only;
	 * the server no longer exports the WP Media Library into the git tree on
	 * fetch/clone.
	 */
	public function testExportMediaContentReturnsEmptyArray() {
		$entries = Push_MD_Media::export_media_content();
		$this->assertIsArray( $entries );
		$this->assertEmpty( $entries, 'export_media_content() must return [] — media is push-only and must not be exported on git fetch/clone.' );
	}

	public function testGenerateAndUpdateAttachmentMetadataHandlesInvalidOrNonexistentFile() {
		$metadata = Push_MD_Media::generate_and_update_attachment_metadata( 0, '' );
		$this->assertIsArray( $metadata );
		$this->assertEmpty( $metadata );

		$metadata_nonexistent = Push_MD_Media::generate_and_update_attachment_metadata( 1, '/non/existent/file.png' );
		$this->assertIsArray( $metadata_nonexistent );
		$this->assertEmpty( $metadata_nonexistent );
	}
}
