<?php

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wp/' );
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $string, $remove_breaks = false ) {
		$string = strip_tags( $string );
		if ( $remove_breaks ) {
			$string = preg_replace( '/[\r\n\t ]+/', ' ', $string );
		}
		return trim( $string );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		$args = func_get_args();
		array_shift( $args );
		if ( empty( $GLOBALS['wp_filter'][ $tag ] ) ) {
			return $value;
		}

		ksort( $GLOBALS['wp_filter'][ $tag ] );
		foreach ( $GLOBALS['wp_filter'][ $tag ] as $priority => $callbacks ) {
			foreach ( $callbacks as $cb ) {
				$accepted  = isset( $cb['accepted_args'] ) ? $cb['accepted_args'] : 1;
				$call_args = array_slice( $args, 0, $accepted );
				$value     = call_user_func_array( $cb['function'], $call_args );
				$args[0]   = $value;
			}
		}

		return $value;
	}
}

require_once dirname( __DIR__ ) . '/class-push-md-html-converter.php';
require_once dirname( __DIR__ ) . '/class-push-md-markdown-consumer.php';

use WordPress\DataLiberation\DataFormatConsumer\BlocksWithMetadata;

/**
 * Tests for Push_MD_HTML_Converter and Push_MD_Markdown_Consumer.
 */
class HtmlMarkdownConversionTest extends TestCase {

	// -------------------------------------------------------------------------
	// Push_MD_HTML_Converter tests
	// -------------------------------------------------------------------------

	public function test_headings_converted() {
		$html     = '<h2>Section Title</h2><h3>Sub Title</h3>';
		$markdown = Push_MD_HTML_Converter::convert( $html );
		$this->assertStringContainsString( '## Section Title', $markdown );
		$this->assertStringContainsString( '### Sub Title', $markdown );
	}

	public function test_paragraph_has_blank_line_separator() {
		$html     = '<p>First paragraph.</p><p>Second paragraph.</p>';
		$markdown = Push_MD_HTML_Converter::convert( $html );
		$this->assertStringContainsString( "First paragraph.\n\nSecond paragraph.", $markdown );
	}

	public function test_raw_text_paragraphs_preserve_blank_line_separator() {
		$html     = "First paragraph line 1.\n\nSecond paragraph line 2.";
		$markdown = Push_MD_HTML_Converter::convert( $html );
		$this->assertStringContainsString( "First paragraph line 1.\n\nSecond paragraph line 2.", $markdown );
	}

	public function test_unordered_list_items_use_dash_bullet() {
		$html     = '<ul><li>Alpha</li><li>Beta</li><li>Gamma</li></ul>';
		$markdown = Push_MD_HTML_Converter::convert( $html );
		$this->assertStringContainsString( "- Alpha\n- Beta\n- Gamma", $markdown );
	}

	public function test_tight_list_formatting_without_extra_blank_lines() {
		$html     = "<ul>\n  <li><p>Alpha</p></li>\n  <li><p>Beta</p></li>\n</ul>";
		$markdown = Push_MD_HTML_Converter::convert( $html );
		$this->assertEquals( "- Alpha\n- Beta", trim( $markdown ) );
	}

	public function test_nested_list_formatting() {
		$html     = "<ul>\n  <li>Parent\n    <ul>\n      <li>Child 1</li>\n      <li>Child 2</li>\n    </ul>\n  </li>\n  <li>Next Parent</li>\n</ul>";
		$markdown = Push_MD_HTML_Converter::convert( $html );
		$expected = "- Parent\n  - Child 1\n  - Child 2\n- Next Parent";
		$this->assertEquals( $expected, trim( $markdown ) );
	}

	public function test_ordered_list_items_use_numbers() {
		$html     = '<ol><li>First</li><li>Second</li><li>Third</li></ol>';
		$markdown = Push_MD_HTML_Converter::convert( $html );
		$this->assertStringContainsString( "1. First\n2. Second\n3. Third", $markdown );
	}

	public function test_aside_rendered_as_blockquote() {
		$html     = '<aside><p>A note here.</p></aside>';
		$markdown = Push_MD_HTML_Converter::convert( $html );
		$this->assertStringContainsString( '> ', $markdown );
		$this->assertStringContainsString( 'A note here.', $markdown );
	}

	public function test_blockquote_rendered_with_prefix() {
		$html     = '<blockquote><p>Famous quote.</p></blockquote>';
		$markdown = Push_MD_HTML_Converter::convert( $html );
		$this->assertStringContainsString( '> ', $markdown );
		$this->assertStringContainsString( 'Famous quote.', $markdown );
	}

	public function test_bold_text() {
		$html     = '<p><strong>Bold</strong> text.</p>';
		$markdown = Push_MD_HTML_Converter::convert( $html );
		$this->assertStringContainsString( '**Bold**', $markdown );
	}

	public function test_italic_text() {
		$html     = '<p><em>Italic</em> text.</p>';
		$markdown = Push_MD_HTML_Converter::convert( $html );
		$this->assertStringContainsString( '*Italic*', $markdown );
	}

	public function test_link_converted() {
		$html     = '<p>Visit <a href="https://example.com">Example</a> now.</p>';
		$markdown = Push_MD_HTML_Converter::convert( $html );
		$this->assertStringContainsString( '[Example](https://example.com)', $markdown );
	}

	public function test_image_converted() {
		$html     = '<img src="https://example.com/img.jpg" alt="An image">';
		$markdown = Push_MD_HTML_Converter::convert( $html );
		$this->assertStringContainsString( '![An image](https://example.com/img.jpg)', $markdown );
	}

	public function test_horizontal_rule_converted() {
		$html     = '<p>Before.</p><hr><p>After.</p>';
		$markdown = Push_MD_HTML_Converter::convert( $html );
		$this->assertStringContainsString( '---', $markdown );
	}

	public function test_nested_strong_does_not_duplicate_markers() {
		// <strong><strong>text</strong></strong> should not produce ****text****.
		$html     = '<strong><strong>Bold</strong></strong>';
		$markdown = Push_MD_HTML_Converter::convert( $html );
		$this->assertStringNotContainsString( '****', $markdown );
		$this->assertStringContainsString( '**Bold**', $markdown );
	}

	public function test_heading_followed_by_paragraph_has_blank_line() {
		$html     = '<h2>Title</h2><p>Body text here.</p>';
		$markdown = Push_MD_HTML_Converter::convert( $html );
		// There should be a blank line between the heading and paragraph.
		$this->assertMatchesRegularExpression( '/## Title\n\nBody text here\./', $markdown );
	}

	public function test_figure_with_img_in_correct_block() {
		$html     = '<figure><img src="https://example.com/photo.jpg" alt="Photo"></figure>';
		$markdown = Push_MD_HTML_Converter::convert( $html );
		$this->assertStringContainsString( '![Photo](https://example.com/photo.jpg)', $markdown );
	}

	public function test_aside_with_ordered_list() {
		$html     = '<aside><strong>Resources</strong><ol><li><a href="https://example.com/1">Link 1</a></li><li><a href="https://example.com/2">Link 2</a></li></ol></aside>';
		$markdown = Push_MD_HTML_Converter::convert( $html );
		// Content should be inside blockquote markers.
		$this->assertStringContainsString( '> ', $markdown );
		$this->assertStringContainsString( '[Link 1](https://example.com/1)', $markdown );
		$this->assertStringContainsString( '[Link 2](https://example.com/2)', $markdown );
	}

	public function test_bold_with_trailing_space_shifted_outside() {
		$html     = '<p><strong>Ask questions </strong></p>';
		$markdown = Push_MD_HTML_Converter::convert( $html );
		$this->assertStringContainsString( '**Ask questions** ', $markdown );
		$this->assertStringNotContainsString( '**Ask questions **', $markdown );
	}

	public function test_bold_with_leading_space_shifted_outside() {
		$html     = '<p><strong> Ask questions</strong></p>';
		$markdown = Push_MD_HTML_Converter::convert( $html );
		$this->assertStringContainsString( ' **Ask questions**', $markdown );
		$this->assertStringNotContainsString( '** Ask questions**', $markdown );
	}

	public function test_bold_with_both_spaces_shifted_outside() {
		$html     = '<p><strong> Ask questions </strong></p>';
		$markdown = Push_MD_HTML_Converter::convert( $html );
		$this->assertStringContainsString( ' **Ask questions** ', $markdown );
	}

	public function test_nbsp_converted_to_standard_space() {
		$html     = "<p>Ask\xC2\xA0questions&nbsp;<strong>now&nbsp;</strong></p>";
		$markdown = Push_MD_HTML_Converter::convert( $html );
		$this->assertStringNotContainsString( "\xC2\xA0", $markdown );
		$this->assertStringNotContainsString( '&nbsp;', $markdown );
		$this->assertStringContainsString( 'Ask questions **now** ', $markdown );
	}

	public function test_italic_strikethrough_code_spaces_normalized() {
		$html     = '<p><em>Italic </em> <s>Strikethrough </s> <code>Code </code></p>';
		$markdown = Push_MD_HTML_Converter::convert( $html );
		$this->assertStringContainsString( '*Italic* ', $markdown );
		$this->assertStringContainsString( '~~Strikethrough~~ ', $markdown );
		$this->assertStringContainsString( '`Code` ', $markdown );
	}

	public function test_fenced_code_block_preserved_without_alteration() {
		$input    = "Text **Ask questions **\n\n```php\n\$x = 5 ** \$y;\n```\n";
		$normalized = Push_MD_HTML_Converter::normalize_markdown( $input );
		$this->assertStringContainsString( 'Text **Ask questions** ', $normalized );
		$this->assertStringContainsString( '$x = 5 ** $y;', $normalized );
	}

	public function test_trailing_space_before_punctuation_cleaned_up() {
		$html     = '<p><strong>Ask questions </strong>.</p>';
		$markdown = Push_MD_HTML_Converter::convert( $html );
		$this->assertStringContainsString( '**Ask questions**.', $markdown );
	}

	public function test_void_image_tag_does_not_truncate_subsequent_content() {
		$html     = '<h2><a href="https://example.com">Header</a></h2><figure><img class="size-full wp-image-123" src="https://example.com/img.png" alt="Img" width="100" height="50" /></figure><p>Paragraph after image.</p><ul><li>List item 1</li><li>List item 2</li></ul><h2>Next Header</h2>';
		$markdown = Push_MD_HTML_Converter::convert( $html );
		$this->assertStringContainsString( '## [Header](https://example.com)', $markdown );
		$this->assertStringContainsString( 'Paragraph after image.', $markdown );
		$this->assertStringContainsString( '- List item 1', $markdown );
		$this->assertStringContainsString( '- List item 2', $markdown );
		$this->assertStringContainsString( '## Next Header', $markdown );
	}

	public function test_tab_indented_list_in_aside_does_not_create_code_blocks() {
		$html     = "<aside class=\"note\">\n<p>Resources</p>\n<ul>\n\t<li><a href=\"https://example.com/1\">Link 1</a></li>\n\t<li><a href=\"https://example.com/2\">Link 2</a></li>\n</ul>\n</aside>";
		$markdown = Push_MD_HTML_Converter::convert( $html );
		$consumer = new Push_MD_Markdown_Consumer( $markdown, false );
		$markup   = $consumer->consume()->get_block_markup();

		$this->assertStringNotContainsString( '<pre>', $markup );
		$this->assertStringNotContainsString( '&lt;a href=', $markup );
		$this->assertStringContainsString( '<a href="https://example.com/1">Link 1</a>', $markup );
		$this->assertStringContainsString( '<a href="https://example.com/2">Link 2</a>', $markup );
	}

	// -------------------------------------------------------------------------
	// Push_MD_Markdown_Consumer tests
	// -------------------------------------------------------------------------

	public function test_consumer_keeps_block_comments_when_enabled() {
		$markdown = "---\ntitle: \"Test\"\n---\n\nHello world.\n";
		$consumer = new Push_MD_Markdown_Consumer( $markdown, true );
		$result   = $consumer->consume();
		$markup   = $result->get_block_markup();
		$this->assertStringContainsString( '<!-- wp:paragraph -->', $markup );
	}

	public function test_consumer_strips_block_comments_when_disabled() {
		$markdown = "---\ntitle: \"Test\"\n---\n\nHello world.\n";
		$consumer = new Push_MD_Markdown_Consumer( $markdown, false );
		$result   = $consumer->consume();
		$markup   = $result->get_block_markup();
		$this->assertStringNotContainsString( '<!-- wp:', $markup );
		$this->assertStringContainsString( '<p>', $markup );
		$this->assertStringContainsString( 'Hello world.', $markup );
	}

	public function test_consumer_strips_block_comments_for_headings() {
		$markdown = "## My Heading\n\nSome text.\n";
		$consumer = new Push_MD_Markdown_Consumer( $markdown, false );
		$result   = $consumer->consume();
		$markup   = $result->get_block_markup();
		$this->assertStringNotContainsString( '<!-- wp:heading', $markup );
		$this->assertStringContainsString( '<h2', $markup );
		$this->assertStringContainsString( 'My Heading', $markup );
	}

	public function test_consumer_preserves_metadata() {
		$markdown = "---\ntitle: \"My Title\"\nstatus: \"publish\"\n---\n\nContent here.\n";
		$consumer = new Push_MD_Markdown_Consumer( $markdown, false );
		$result   = $consumer->consume();
		$metadata = array();
		foreach ( $result->get_all_metadata() as $key => $value ) {
			$metadata[ $key ] = is_array( $value ) ? reset( $value ) : $value;
		}
		$this->assertEquals( 'My Title', $metadata['title'] );
		$this->assertEquals( 'publish', $metadata['status'] );
	}

	public function test_anchor_internal_page_link_with_class_before_and_after_href() {
		$html_class_first = '<p><a class="button primary arrow" href="#icegramgdpr">Jump to how Icegram Engage is getting GDPR compliant</a></p>';
		$md1              = Push_MD_HTML_Converter::convert( $html_class_first );
		$consumer1        = new Push_MD_Markdown_Consumer( $md1, false );
		$markup1          = $consumer1->consume()->get_block_markup();

		$this->assertStringNotContainsString( '&lt;a', $markup1 );
		$this->assertStringContainsString( 'href="#icegramgdpr"', $markup1 );
		$this->assertStringContainsString( 'Jump to how Icegram Engage is getting GDPR compliant</a>', $markup1 );

		$html_href_first = '<p><a href="#icegramgdpr" class="button primary arrow">Jump to anchor</a></p>';
		$md2             = Push_MD_HTML_Converter::convert( $html_href_first );
		$consumer2       = new Push_MD_Markdown_Consumer( $md2, false );
		$markup2         = $consumer2->consume()->get_block_markup();

		$this->assertStringNotContainsString( '&lt;a', $markup2 );
		$this->assertStringContainsString( 'href="#icegramgdpr"', $markup2 );
		$this->assertStringContainsString( 'Jump to anchor</a>', $markup2 );
	}

	public function test_anchor_with_spaces_in_link_text_and_multiple_classes() {
		$html     = '<p>Visit <a class="btn primary cta" href="https://example.com"> Click here </a> now.</p>';
		$md       = Push_MD_HTML_Converter::convert( $html );
		$consumer = new Push_MD_Markdown_Consumer( $md, false );
		$markup   = $consumer->consume()->get_block_markup();

		$this->assertStringNotContainsString( '&lt;a', $markup );
		$this->assertStringContainsString( '<a class="btn primary cta" href="https://example.com"> Click here </a>', $markup );
	}

	public function test_multiline_aside_note_with_blank_lines_preserves_all_links() {
		$html = "<aside class=\"note\">\n<p>Additional resources</p>\n<ul>\n<li><a href=\"https://www.icegram.com/sms-marketing-vs-email-marketing/\">Link 1</a></li>\n\n<li><a href=\"https://www.icegram.com/newsletter-vs-blog/\">Link 2</a></li>\n\n<li><a href=\"https://www.icegram.com/free-mailchimp-alternatives/\">Link 3</a></li>\n\n<li><a href=\"https://www.icegram.com/opt-in-email-marketing/\">Link 4</a></li>\n</ul>\n</aside>";

		$md       = Push_MD_HTML_Converter::convert( $html );
		$consumer = new Push_MD_Markdown_Consumer( $md, false );
		$markup   = $consumer->consume()->get_block_markup();

		$this->assertStringNotContainsString( '&lt;a', $markup, 'No links inside preserved block should be HTML escaped' );
		$this->assertStringContainsString( '<aside class="note">', $markup );
		$this->assertStringContainsString( 'href="https://www.icegram.com/sms-marketing-vs-email-marketing/"', $markup );
		$this->assertStringContainsString( 'href="https://www.icegram.com/newsletter-vs-blog/"', $markup );
		$this->assertStringContainsString( 'href="https://www.icegram.com/free-mailchimp-alternatives/"', $markup );
		$this->assertStringContainsString( 'href="https://www.icegram.com/opt-in-email-marketing/"', $markup );
	}

	public function test_literal_less_than_and_greater_than_in_paragraphs() {
		$html     = '<p>Text with &amp; ampersand, &lt;less than&gt;, &gt;greater than&gt;.</p>';
		$md       = Push_MD_HTML_Converter::convert( $html );
		$consumer = new Push_MD_Markdown_Consumer( $md, false );
		$markup   = $consumer->consume()->get_block_markup();

		$this->assertStringContainsString( 'Text with &amp; ampersand', $markup );
		$this->assertStringContainsString( '&lt;less than&gt;', $markup );
	}

	// -------------------------------------------------------------------------
	// Live database fidelity edge cases (from live-fidelity-run-output.txt)
	// -------------------------------------------------------------------------

	public function test_anchor_with_onclick_handler_preserved_as_raw_html() {
		// POST 2735: anchors with JavaScript event handlers must keep the onclick
		// attribute; converting them to Markdown links would discard it.
		$html = '<p>For links, the code may look like: <a href="#" onclick="window.icegram.get_message_by_id({message_id}).show();return false;">Show me a Popup!</a></p>';
		$md   = Push_MD_HTML_Converter::convert( $html );
		$this->assertStringContainsString( 'onclick="window.icegram.get_message_by_id', $md );

		$consumer = new Push_MD_Markdown_Consumer( $md, false );
		$markup   = $consumer->consume()->get_block_markup();
		$this->assertStringContainsString( 'onclick="window.icegram.get_message_by_id({message_id}).show();return false;"', $markup );
		$this->assertStringNotContainsString( '[Show me a Popup!](#)', $markup );
	}

	public function test_anchor_trailing_space_preserves_word_boundary() {
		// POST 36197/421462: a trailing space inside anchor text must not be lost,
		// otherwise "your deliverability" merges into "yourdeliverability".
		$html     = '<p>It improves <a href="https://example.com">your </a>deliverability today.</p>';
		$md       = Push_MD_HTML_Converter::convert( $html );
		$consumer = new Push_MD_Markdown_Consumer( $md, false );
		$markup   = $consumer->consume()->get_block_markup();

		$extract_words = function( $h ) {
			$clean = strip_tags( html_entity_decode( html_entity_decode( $h, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			preg_match_all( '/[\p{L}\p{N}]+/u', mb_strtolower( $clean, 'UTF-8' ), $matches );
			return implode( ' ', $matches[0] );
		};

		$this->assertEquals(
			$extract_words( $html ),
			$extract_words( $markup ),
			'Word content must be preserved when an anchor has trailing whitespace'
		);
		$this->assertStringContainsString( 'deliverability', $markup );
	}

	public function test_adjacent_inline_formatting_keeps_source_word_boundaries() {
		// Adjacent inline elements must not gain or lose spaces relative to the source.
		// Source has NO space between the strong elements -> "onecta" both ways.
		$html_no_space = '<p>only have <strong>one</strong><strong>cta</strong> button</p>';
		$md            = Push_MD_HTML_Converter::convert( $html_no_space );
		$consumer      = new Push_MD_Markdown_Consumer( $md, false );
		$markup        = $consumer->consume()->get_block_markup();

		$extract_words = function( $h ) {
			$clean = strip_tags( html_entity_decode( html_entity_decode( $h, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			preg_match_all( '/[\p{L}\p{N}]+/u', mb_strtolower( $clean, 'UTF-8' ), $matches );
			return implode( ' ', $matches[0] );
		};

		$this->assertEquals( $extract_words( $html_no_space ), $extract_words( $markup ) );
		$this->assertStringContainsString( 'onecta', $extract_words( $markup ) );

		// Source HAS a space between the strong elements -> "one cta" both ways.
		$html_with_space = '<p>only have <strong>one</strong> <strong>cta</strong> button</p>';
		$md2             = Push_MD_HTML_Converter::convert( $html_with_space );
		$markup2         = ( new Push_MD_Markdown_Consumer( $md2, false ) )->consume()->get_block_markup();
		$this->assertEquals( $extract_words( $html_with_space ), $extract_words( $markup2 ) );
		$this->assertStringContainsString( 'one cta', $extract_words( $markup2 ) );
	}

	public function test_code_span_with_nested_anchor_preserved() {
		// POST 2735: a shortcode snippet like <code>[icegram ...]<a href="#">text</a>[/icegram]</code>
		// must preserve the nested anchor instead of converting it to a Markdown link.
		$html = '<p><code>[icegram campaigns="XXXX"]<a href="#">Your Text </a> [/icegram]</code></p>';
		$md   = Push_MD_HTML_Converter::convert( $html );

		$consumer = new Push_MD_Markdown_Consumer( $md, false );
		$markup   = $consumer->consume()->get_block_markup();

		$this->assertStringContainsString( '<code>', $markup );
		$this->assertStringContainsString( '<a href="#">Your Text </a>', $markup );
		$this->assertStringContainsString( '[icegram campaigns="XXXX"]', $markup );
		$this->assertStringNotContainsString( '[Your Text](#)', $markup );
	}

	public function test_escaped_anchor_inside_code_span_stays_escaped() {
		// POST 2735: escaped entity code samples ("&lt;a href=...&gt;") inside a
		// <code> element are literal text and must NOT be un-escaped into real tags.
		$html = '<p>For links, the code may look like: <code>&lt;a href="#" onclick="window.icegram.get_message_by_id({message_id}).show();return false;"&gt;Show me a Popup!&lt;/a&gt;</code></p>';
		$md   = Push_MD_HTML_Converter::convert( $html );

		$consumer = new Push_MD_Markdown_Consumer( $md, false );
		$markup   = $consumer->consume()->get_block_markup();

		// The code sample must remain escaped text, not become a real <a> element.
		$this->assertStringContainsString( '&lt;a href=&quot;#&quot; onclick=', $markup );
		$this->assertStringNotContainsString( '<a href="#" onclick=', $markup );
	}

	public function test_aside_with_escaped_script_content_preserved() {
		// POST 10977: an <aside> whose content shows "&lt;script&gt;" as sample text
		// must keep that content on the round trip; un-escaped script elements would
		// swallow the rest of the document.
		$html = '<aside class="notify">Use !important override default CSS. For js code add your javascript code between "&lt;script&gt;"</aside>';
		$md   = Push_MD_HTML_Converter::convert( $html );

		$consumer = new Push_MD_Markdown_Consumer( $md, false );
		$markup   = $consumer->consume()->get_block_markup();

		$this->assertStringContainsString( '<aside class="notify">', $markup );
		$this->assertStringContainsString( 'Use !important override default CSS.', $markup );
		// The script sample must stay entity-escaped so it is not parsed as an element.
		$this->assertStringContainsString( '&lt;script&gt;', $markup );
		$this->assertStringNotContainsString( 'between "<script>"', $markup );
	}

	public function test_shortcode_with_spaces_in_brackets_round_trips() {
		// POST 2735: shortcode snippets written with spaces inside the brackets
		// ("[ icegram campaigns=\"campaign_id\" ]") must survive the round trip.
		$html = '<p>The code looks something like: [ icegram campaigns="campaign_id" ]</p>';
		$md   = Push_MD_HTML_Converter::convert( $html );

		$consumer = new Push_MD_Markdown_Consumer( $md, false );
		$markup   = $consumer->consume()->get_block_markup();

		$this->assertStringContainsString( '[ icegram campaigns="campaign_id" ]', $markup );
	}

	public function test_multiline_aside_after_inline_code_preserves_heading_and_media() {
		// POST 10977: content following an <aside class="notify"> must not be lost,
		// and headings/images after the aside must survive.
		$html = '<p><code>#ig_this_message{width:30% !important;}</code></p>
<aside class="notify">prefix your CSS selector/rule with "#ig_this_message"</aside>
<h2>Other Notable Mentions</h2>
<p><img src="https://example.com/img.png" alt="Flag"></p>';

		$md       = Push_MD_HTML_Converter::convert( $html );
		$consumer = new Push_MD_Markdown_Consumer( $md, false );
		$markup   = $consumer->consume()->get_block_markup();

		$this->assertStringContainsString( 'prefix your CSS selector/rule', $markup );
		$this->assertStringContainsString( '<h2', $markup );
		$this->assertStringContainsString( 'Other Notable Mentions', $markup );
		$this->assertStringContainsString( 'src="https://example.com/img.png"', $markup );
	}

	public function test_link_spaces_preserved() {
		$html     = 'Click <a href="https://example.com">here </a>for more details.';
		$md       = Push_MD_HTML_Converter::convert( $html );
		$consumer = new Push_MD_Markdown_Consumer( $md, false );
		$markup   = $consumer->consume()->get_block_markup();

		$this->assertEquals( '<p>Click <a href="https://example.com">here</a> for more details.</p>', $markup );
	}

	public function test_raw_paragraph_link_formatting_continuity() {
		$html = "According to the <a href=\"https://example.com\">Baymard Institute</a>, the average rate is 69%.\n\nCheck out <a href=\"https://example.com/express\">Icegram Express</a>!\n\n<h2>Next Section</h2>";
		$md   = Push_MD_HTML_Converter::convert( $html );

		$this->assertStringContainsString( 'According to the [Baymard Institute](https://example.com), the average rate is 69%.', $md );
		$this->assertStringContainsString( 'Check out [Icegram Express](https://example.com/express)!', $md );
		$this->assertStringNotContainsString( ")\n\n,", $md );
		$this->assertStringNotContainsString( ")\n\n!", $md );
	}
}
