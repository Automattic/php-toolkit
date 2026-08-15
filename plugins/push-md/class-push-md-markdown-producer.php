<?php

use WordPress\DataLiberation\DataFormatConsumer\BlocksWithMetadata;
use WordPress\Markdown\MarkdownProducer;

require_once __DIR__ . '/class-push-md-callouts.php';

/**
 * Thin wrapper around MarkdownProducer that adds support for posts whose
 * content is standard HTML (classic editor, raw HTML, imported HTML)
 * rather than Gutenberg block markup.
 *
 * Detection strategy:
 *   - has_blocks( $content ) === true  → content contains Gutenberg block
 *     comment delimiters → delegate entirely to MarkdownProducer (unchanged).
 *   - has_blocks( $content ) === false → standard HTML or plain text →
 *     convert body with Push_MD_HTML_Converter and emit our own frontmatter.
 *
 * Mixed posts (some Gutenberg blocks, some classic HTML) are handled by
 * MarkdownProducer: its default case already calls html_to_markdown() for
 * null-blockName fragments, which covers inline text. Block-level standard
 * HTML inside a Gutenberg-wrapped post is an unusual edge case.
 */
class Push_MD_Markdown_Producer {

	/**
	 * @var BlocksWithMetadata
	 */
	private $blocks_with_meta;

	/**
	 * @var string|null
	 */
	private $markdown;

	/**
	 * @param BlocksWithMetadata $blocks_with_meta
	 */
	public function __construct( BlocksWithMetadata $blocks_with_meta ) {
		$this->blocks_with_meta = $blocks_with_meta;
	}

	/**
	 * Produce the Markdown string (frontmatter + body).
	 *
	 * @return string
	 */
	public function produce() {
		if ( null !== $this->markdown ) {
			return $this->markdown;
		}

		$content = $this->blocks_with_meta->get_block_markup();

		if ( $this->content_has_blocks( $content ) ) {
			// Gutenberg content: handle core/quote blocks that have innerHTML instead of nested innerBlocks.
			$content = preg_replace_callback(
				'#<!-- wp:quote\b[^>]*-->\s*<blockquote\b[^>]*>(.*?)</blockquote>\s*<!-- /wp:quote -->#is',
				function ( $matches ) {
					$inner_md = Push_MD_HTML_Converter::convert( $matches[1] );
					$lines    = explode( "\n", trim( $inner_md ) );
					$quoted   = implode(
						"\n",
						array_map(
							function ( $l ) {
								return '> ' . $l;
							},
							$lines
						)
					);
					return "\n\n" . $quoted . "\n\n";
				},
				$content
			);

			if ( class_exists( 'Push_MD_Callouts' ) ) {
				$content = Push_MD_Callouts::process_html_directives_in_content( $content );
			}

			$metadata = $this->blocks_with_meta->get_all_metadata();
			if ( class_exists( 'Push_MD_Plugin' ) && method_exists( 'Push_MD_Plugin', 'sort_frontmatter_keys' ) ) {
				$metadata = Push_MD_Plugin::sort_frontmatter_keys( $metadata );
			}
			$blocks_obj     = new BlocksWithMetadata( $content, $metadata );
			$producer       = new MarkdownProducer( $blocks_obj );
			$this->markdown = Push_MD_HTML_Converter::normalize_markdown( $producer->produce() );

			if ( class_exists( 'Push_MD_Callouts' ) ) {
				$this->markdown = Push_MD_Callouts::process_gutenberg_to_markdown( $this->markdown );
			}
		} else {
			// Standard HTML or plain-text content.
			$this->markdown = $this->frontmatter( $this->blocks_with_meta->get_all_metadata() )
				. Push_MD_HTML_Converter::convert( $content );
		}

		$this->markdown = $this->normalize_frontmatter_single_element_arrays( $this->markdown );

		return $this->markdown;
	}

	/**
	 * Detect whether post content contains Gutenberg block comment delimiters.
	 *
	 * Uses WordPress core's has_blocks() when available; falls back to a
	 * strpos() check so the class works in unit-test environments that run
	 * outside of WordPress.
	 *
	 * @param string $content Raw post_content.
	 * @return bool
	 */
	private function content_has_blocks( $content ) {
		if ( function_exists( 'has_blocks' ) ) {
			return has_blocks( $content );
		}
		// Fallback: Gutenberg block markup always contains <!-- wp: delimiters.
		return false !== strpos( $content, '<!-- wp:' );
	}

	private function normalize_frontmatter_single_element_arrays( $markdown ) {
		if ( 0 !== strpos( $markdown, "---\n" ) ) {
			return $markdown;
		}

		$end_pos = strpos( $markdown, "\n---\n", 4 );
		if ( false === $end_pos ) {
			return $markdown;
		}

		$frontmatter_block = substr( $markdown, 4, $end_pos - 4 );
		$rest              = substr( $markdown, $end_pos );

		$lines     = explode( "\n", $frontmatter_block );
		$new_lines = array();

		foreach ( $lines as $line ) {
			if ( preg_match( '/^([A-Za-z0-9_-]+)\s*:\s*\[\s*("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|[^,\]]+)\s*\]\s*$/', $line, $m ) ) {
				$new_lines[] = $m[1] . ': ' . trim( $m[2] );
			} else {
				$new_lines[] = $line;
			}
		}

		return "---\n" . implode( "\n", $new_lines ) . $rest;
	}

	private function frontmatter( $metadata ) {
		if ( empty( $metadata ) ) {
			return '';
		}
		if ( class_exists( 'Push_MD_Plugin' ) && method_exists( 'Push_MD_Plugin', 'sort_frontmatter_keys' ) ) {
			$metadata = Push_MD_Plugin::sort_frontmatter_keys( $metadata );
		}
		$frontmatter = '';
		foreach ( $metadata as $key => $value ) {
			$frontmatter .= $key . ': ' . json_encode( $value ) . "\n";
		}
		return "---\n" . $frontmatter . "---\n\n";
	}
}
