<?php

use WordPress\DataLiberation\DataFormatConsumer\BlocksWithMetadata;
use WordPress\Markdown\MarkdownConsumer;

require_once __DIR__ . '/class-push-md-callouts.php';

/**
 * Thin wrapper around MarkdownConsumer that optionally strips Gutenberg
 * block comment wrappers from the generated HTML.
 *
 * MarkdownConsumer always produces Gutenberg block markup
 * (<!-- wp:paragraph --><p>…</p><!-- /wp:paragraph -->).
 * When the site does not use the block editor for the post type being
 * imported, those comment wrappers are removed and clean standard HTML
 * is returned instead.
 *
 * Usage:
 *   $consumer = new Push_MD_Markdown_Consumer( $markdown, $use_block_comments );
 *   $result   = $consumer->consume();            // returns BlocksWithMetadata
 *   $markup   = $result->get_block_markup();     // Gutenberg or plain HTML
 *   $meta     = $result->get_all_metadata();
 */
class Push_MD_Markdown_Consumer {

	/**
	 * @var string
	 */
	private $markdown;

	/**
	 * @var bool Whether to keep Gutenberg block comment wrappers in the output.
	 */
	private $use_block_comments;

	/**
	 * @var BlocksWithMetadata|null
	 */
	private $result;

	/**
	 * @param string $markdown           Markdown string (may include frontmatter).
	 * @param bool   $use_block_comments True to keep Gutenberg block comments
	 *                                   (default). False to produce standard HTML.
	 */
	public function __construct( $markdown, $use_block_comments = true ) {
		$this->markdown           = $markdown;
		$this->use_block_comments = (bool) $use_block_comments;
	}

	/**
	 * Parse the Markdown and return a BlocksWithMetadata instance.
	 *
	 * When $use_block_comments is false the block_markup in the returned
	 * object contains plain HTML without <!-- wp:… --> comment wrappers.
	 *
	 * @return BlocksWithMetadata
	 */
	public function consume() {
		if ( null !== $this->result ) {
			return $this->result;
		}

		$this->markdown = Push_MD_Callouts::process_markdown_to_html( $this->markdown, $this->use_block_comments );

		$inner_consumer = new MarkdownConsumer( $this->markdown );
		$raw_result     = $inner_consumer->consume();

		$block_markup = $raw_result->get_block_markup();
		if ( ! $this->use_block_comments ) {
			$block_markup = $this->strip_block_comments( $block_markup );
		}
		$block_markup = $this->escape_literal_angle_brackets( $block_markup );
		$block_markup = $this->unescape_inline_html_tags( $block_markup );
		$block_markup = $this->encode_bare_ampersands( $block_markup );
		$block_markup = $this->normalize_link_spacing_and_linebreaks( $block_markup );

		$metadata     = $raw_result->get_all_metadata();
		$this->result = new BlocksWithMetadata( $block_markup, $metadata );

		return $this->result;
	}

	/**
	 * Escape bare angle brackets (< and >) in text nodes outside HTML tags.
	 *
	 * @param string $markup Output markup.
	 * @return string Markup with literal < and > escaped as &lt; and &gt;.
	 */
	private function escape_literal_angle_brackets( $markup ) {
		$tags  = 'p|h[1-6]|ul|ol|li|blockquote|figure|figcaption|aside|table|thead|tbody|tfoot|tr|th|td|hr|div|pre|code|span|a|b|i|strong|em|img|svg|canvas|sub|sup|del|s|section|article|header|footer|nav|main';
		$parts = preg_split( '#(<(?:code|pre|script|style|textarea)\b[^>]*>.*?</(?:code|pre|script|style|textarea)>)#is', $markup, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( false === $parts || 1 === count( $parts ) ) {
			return $this->escape_literal_angle_brackets_in_fragment( $markup, $tags );
		}

		foreach ( $parts as $i => $part ) {
			// Odd parts are protected code/pre containers.
			if ( 1 === $i % 2 ) {
				continue;
			}
			$parts[ $i ] = $this->escape_literal_angle_brackets_in_fragment( $part, $tags );
		}

		return implode( '', $parts );
	}

	/**
	 * Helper to escape literal < and > outside HTML tags within a fragment.
	 *
	 * @param string $fragment Text fragment outside protected code/pre blocks.
	 * @param string $tags     Regex tag list.
	 * @return string Fragment with literal < and > escaped.
	 */
	private function escape_literal_angle_brackets_in_fragment( $fragment, $tags ) {
		$sub_parts = preg_split( '/(<!--.*?-->|<\/?(?:' . $tags . ')\b[^>]*>)/s', $fragment, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( false === $sub_parts || 1 === count( $sub_parts ) ) {
			return $fragment;
		}

		foreach ( $sub_parts as $i => $sub_part ) {
			if ( 1 === $i % 2 ) {
				continue;
			}
			$sub_part        = str_replace( '<', '&lt;', $sub_part );
			$sub_part        = str_replace( '>', '&gt;', $sub_part );
			$sub_parts[ $i ] = $sub_part;
		}

		return implode( '', $sub_parts );
	}

	/**
	 * Decode inline HTML tags that were escaped during Markdown parsing.
	 *
	 * @param string $markup Output markup.
	 * @return string Markup with un-escaped inline HTML tags.
	 */
	private function unescape_inline_html_tags( $markup ) {
		// Raw-text elements (script, style, pre, textarea) are deliberately excluded:
		// un-escaping their start tags turns escaped source text into real elements
		// that can swallow the rest of the document (e.g. an unclosed <script> inside
		// a preserved <aside>), which breaks subsequent HTML processing and loses
		// content. CommonMark treats these as block-level HTML, so they never reach
		// this function as escaped inline tags anyway.
		$tags = 'a|span|figure|figcaption|aside|iframe|form|img|div|table|thead|tbody|tfoot|tr|th|td|ul|ol|li|h[1-6]|b|i|strong|em|code|svg|canvas|br|hr|sub|sup|del|s|p|section|article|header|footer|nav|main|blockquote';

		$unescape = function ( $matches ) {
			return html_entity_decode( $matches[0], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		};

		// Protect the content of <code>/<pre>/<script>/<style>/<textarea> elements:
		// escaped entities inside them are literal text (e.g. a code sample showing
		// "<a href=...>" must not be un-escaped into a real element). Only un-escape
		// tags that appear outside those containers.
		$parts = preg_split( '#(<(?:code|pre|script|style|textarea)\b[^>]*>.*?</(?:code|pre|script|style|textarea)>)#is', $markup, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( false === $parts || 1 === count( $parts ) ) {
			return preg_replace_callback( '/&lt;(\/?(?:' . $tags . ')\b(?:\s+(?:&quot;|[^>])*)?)\s*&gt;/i', $unescape, $markup );
		}

		foreach ( $parts as $i => $part ) {
			// Odd parts are the protected container segments (delimiter capture).
			if ( 1 === $i % 2 ) {
				continue;
			}
			$parts[ $i ] = preg_replace_callback( '/&lt;(\/?(?:' . $tags . ')\b(?:\s+(?:&quot;|[^>])*)?)\s*&gt;/i', $unescape, $part );
		}

		return implode( '', $parts );
	}

	/**
	 * Encode bare ampersands as &amp; in the generated markup.
	 *
	 * The underlying MarkdownConsumer passes decoded text through unchanged, so
	 * a literal "&" in the original HTML would come back as a bare "&" instead
	 * of the well-formed "&amp;". Entities already present (&amp;, &lt;, &quot;,
	 * numeric references) are left untouched.
	 *
	 * @param string $markup Output markup.
	 * @return string Markup with bare ampersands encoded.
	 */
	private function encode_bare_ampersands( $markup ) {
		return preg_replace( '/&(?!(?:#[0-9]+|#[xX][0-9a-fA-F]+|[a-zA-Z][a-zA-Z0-9]*);)/', '&amp;', $markup );
	}

	/**
	 * Remove Gutenberg block comment wrappers from markup.
	 *
	 * Strips lines such as:
	 *   <!-- wp:paragraph -->
	 *   <!-- /wp:paragraph -->
	 *   <!-- wp:list {"ordered":false} -->
	 *
	 * @param string $markup Block markup with Gutenberg comment wrappers.
	 * @return string Plain HTML without block comments.
	 */
	private function strip_block_comments( $markup ) {
		// Remove block comment lines (including any trailing newline).
		$markup = preg_replace( '/<!--\s+\/?wp:[^>]+-->\n?/', '', $markup );
		// Remove wp-specific CSS classes that only make sense in the block editor.
		$markup = preg_replace( '/ class="wp-block-[^"]*"/', '', $markup );
		// Remove auto-generated id attributes on heading tags.
		$markup = preg_replace( '/(<h[1-6]\b[^>]*) id="[^"]*"/i', '$1', $markup );
		// Normalise empty paragraphs left by block conversion boundaries.
		$markup = preg_replace( '#<p>\s*</p>#', '', $markup );
		// Normalise excess blank lines left by the removal.
		$markup = preg_replace( "/\n{3,}/", "\n\n", $markup );
		return trim( $markup );
	}

	/**
	 * Normalize link boundary spacing and strip accidental line breaks after </a> tags.
	 *
	 * Preserves word boundaries and spaces around links while ensuring punctuation
	 * (periods, commas, etc.) and media/image elements are not corrupted.
	 *
	 * @param string $markup Output markup.
	 * @return string Markup with clean link spacing.
	 */
	private function normalize_link_spacing_and_linebreaks( $markup ) {
		// Replace linebreaks directly after </a> tags with a single space when followed by text.
		$markup = preg_replace( '/(<\/a>)\s*[\r\n]+\s*([a-zA-Z0-9])/i', '$1 $2', $markup );
		// Replace linebreaks directly before <a href=...> tags with a single space when preceded by text.
		$markup = preg_replace( '/([a-zA-Z0-9])\s*[\r\n]+\s*(<a\b[^>]*>)/i', '$1 $2', $markup );

		// Ensure space before <a href="..."> if preceded directly by a word character without space.
		$markup = preg_replace( '/([a-zA-Z0-9])(<a\b[^>]*>)/i', '$1 $2', $markup );

		// Ensure space after </a> if followed directly by a word character without space (excluding punctuation).
		$markup = preg_replace( '/(<\/a>)([a-zA-Z0-9])/i', '$1 $2', $markup );

		return $markup;
	}
}
