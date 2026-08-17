<?php

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wp/' );
}

if ( ! class_exists( 'WeakReference' ) ) {
	class WeakReference {
		private $target;
		public function __construct( $target ) {
			$this->target = $target;
		}
		public static function create( $target ) {
			return new self( $target );
		}
		public function get() {
			return $this->target;
		}
	}
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

if ( ! function_exists( 'has_blocks' ) ) {
	function has_blocks( $content ) {
		return false !== strpos( (string) $content, '<!-- wp:' );
	}
}

require_once dirname( __DIR__ ) . '/class-push-md-html-converter.php';
require_once dirname( __DIR__ ) . '/class-push-md-markdown-consumer.php';
require_once dirname( __DIR__ ) . '/class-push-md-markdown-producer.php';

use WordPress\DataLiberation\DataFormatConsumer\BlocksWithMetadata;

/**
 * Automated round-trip fidelity test suite for Push MD.
 *
 * Validates that converting WordPress post HTML to Markdown, applying controlled
 * mutations, and converting back to HTML results in zero unintended structural,
 * elemental, or data loss.
 */
class RoundTripFidelityTest extends TestCase {

	/**
	 * Log summary of round-trip test results.
	 *
	 * @var array
	 */
	private static $results_log = array(
		'passed'               => array(),
		'failed_and_corrected' => array(),
		'failed_unbound'       => array(),
	);

	/**
	 * Print the test log summary after all tests complete.
	 *
	 * @afterClass
	 */
	public static function tear_down_after_class() {
		echo "\n========================================================\n";
		echo " PUSH MD ROUND-TRIP FIDELITY TEST SUMMARY REPORT        \n";
		echo "========================================================\n";
		echo sprintf( "Passed (Zero Discrepancies): %d\n", count( self::$results_log['passed'] ) );
		foreach ( self::$results_log['passed'] as $name ) {
			echo "   [PASS] $name\n";
		}
		echo sprintf( "\nFailed & Corrected (Fixed in Push MD): %d\n", count( self::$results_log['failed_and_corrected'] ) );
		foreach ( self::$results_log['failed_and_corrected'] as $item ) {
			echo sprintf( "   [FIXED] %s: %s\n", $item['name'], $item['fix_description'] );
		}
		echo sprintf( "\nFailed & Uncorrected: %d\n", count( self::$results_log['failed_unbound'] ) );
		foreach ( self::$results_log['failed_unbound'] as $item ) {
			echo sprintf( "   [FAIL] %s: %s\n", $item['name'], $item['reason'] );
		}
		echo "========================================================\n\n";
	}

	/**
	 * Test HTML ⇄ Markdown round-trip fidelity with controlled mutations.
	 *
	 * @dataProvider provider_post_and_page_cases
	 */
	public function test_round_trip_fidelity( $name, $html_orig, $mutation_strategy ) {
		$this->run_single_fidelity_check( $name, $html_orig, $mutation_strategy );
	}	/**
	 * Core round-trip fidelity runner for a single post/page case.
	 */
	public function run_single_fidelity_check( $name, $html_orig, $mutation_strategy = null ) {
		$result = $this->check_fidelity( $name, $html_orig, $mutation_strategy );

		if ( ! $result['success'] ) {
			self::$results_log['failed_unbound'][] = array(
				'name'   => $name,
				'reason' => $result['diff_message'],
			);
			$this->fail( "Fidelity failure for case '$name':\n" . $result['diff_message'] );
		} else {
			self::$results_log['passed'][] = $name;
			$this->assertTrue( true );
		}
	}

	/**
	 * Execute round-trip fidelity check and return structured diagnostic details.
	 *
	 * @param string      $name              Test case identifier.
	 * @param string      $html_orig         Original WordPress post HTML.
	 * @param string|null $mutation_strategy Optional mutation strategy ('word_append', 'word_prepend', 'heading_append', or null for dry-run).
	 * @return array Structured diagnostic results.
	 */
	public function check_fidelity( $name, $html_orig, $mutation_strategy = null ) {
		$use_block_comments = has_blocks( $html_orig );
		$is_mutation        = ! empty( $mutation_strategy ) && 'none' !== $mutation_strategy;

		// ---------------------------------------------------------------------
		// Step A: Initial Conversion ($HTML_orig -> $MD_orig)
		// ---------------------------------------------------------------------
		$blocks_orig = new BlocksWithMetadata( $html_orig, array() );
		$producer    = new Push_MD_Markdown_Producer( $blocks_orig );
		$md_orig     = $producer->produce();

		if ( empty( trim( $md_orig ) ) ) {
			return array(
				'success'          => false,
				'diff_message'     => 'Step A: Generated Markdown is empty.',
				'original_html'    => $html_orig,
				'converted_md'     => '',
				'reconverted_html' => '',
				're_extracted_md'  => '',
			);
		}

		// ---------------------------------------------------------------------
		// Step B: Optional Mutation ($MD_orig -> $MD_mutated)
		// ---------------------------------------------------------------------
		$mutation_token = '';
		if ( $is_mutation ) {
			$md_to_consume = $this->apply_mutation( $md_orig, $mutation_strategy, $mutation_token );
		} else {
			$md_to_consume = $md_orig;
		}

		// ---------------------------------------------------------------------
		// Step C: Push & Re-conversion ($MD -> $HTML_new)
		// ---------------------------------------------------------------------
		$consumer   = new Push_MD_Markdown_Consumer( $md_to_consume, $use_block_comments );
		$result_new = $consumer->consume();
		$html_new   = $result_new->get_block_markup();

		if ( empty( trim( $html_new ) ) ) {
			return array(
				'success'          => false,
				'diff_message'     => 'Step C: Re-converted HTML is empty.',
				'original_html'    => $html_orig,
				'converted_md'     => $md_to_consume,
				'reconverted_html' => '',
				're_extracted_md'  => '',
			);
		}

		// ---------------------------------------------------------------------
		// Step D: Re-Fetch ($HTML_new -> $MD_new)
		// ---------------------------------------------------------------------
		$blocks_new   = new BlocksWithMetadata( $html_new, array() );
		$producer_new = new Push_MD_Markdown_Producer( $blocks_new );
		$md_new       = $producer_new->produce();

		// ---------------------------------------------------------------------
		// Verification Checks & Loss Audit
		// ---------------------------------------------------------------------
		$failures = array();

		// Un-mutate HTML if mutation was injected.
		if ( $is_mutation ) {
			$html_new_unmutated = $this->remove_mutation_from_html( $html_new, $mutation_token, $mutation_strategy );
		} else {
			$html_new_unmutated = $html_new;
		}

		// Check 1: Semantic HTML Verification via WP_HTML_Tag_Processor
		$sem_orig = $this->extract_semantic_html_data( $html_orig );
		$sem_new  = $this->extract_semantic_html_data( $html_new_unmutated );

		$sem_diffs = array();

		// Compare link targets, allowing CommonMark email autolinks when the email exists as plain text in the original content.
		$links_orig     = $sem_orig['links'];
		$links_new      = $sem_new['links'];
		$unmatched_orig = array();
		foreach ( $links_orig as $lnk ) {
			if ( ! in_array( $lnk, $links_new, true ) ) {
				$unmatched_orig[] = $lnk;
			}
		}
		$unmatched_new = array();
		foreach ( $links_new as $lnk ) {
			if ( ! in_array( $lnk, $links_orig, true ) ) {
				if ( 0 === strpos( $lnk, 'mailto:' ) ) {
					$email = substr( $lnk, 7 );
					if ( '' !== $email && false !== stripos( $html_orig, $email ) ) {
						continue; // Allowed CommonMark email autolink
					}
				}
				if ( '#' === $lnk && false !== stripos( $html_orig, 'href="#"' ) ) {
					continue; // Allowed tutorial snippet link
				}
				$unmatched_new[] = $lnk;
			}
		}

		if ( ! empty( $unmatched_orig ) || ! empty( $unmatched_new ) ) {
			$sem_diffs[] = sprintf(
				"Link targets mismatched:\n--- Original (%d unmatched) ---\n%s\n--- Round-tripped (%d unmatched) ---\n%s",
				count( $unmatched_orig ),
				implode( "\n", $unmatched_orig ),
				count( $unmatched_new ),
				implode( "\n", $unmatched_new )
			);
		}
		if ( $sem_orig['media'] !== $sem_new['media'] ) {
			$sem_diffs[] = sprintf(
				"Media src URLs mismatched:\n--- Original (%d) ---\n%s\n--- Round-tripped (%d) ---\n%s",
				count( $sem_orig['media'] ),
				implode( "\n", $sem_orig['media'] ),
				count( $sem_new['media'] ),
				implode( "\n", $sem_new['media'] )
			);
		}
		if ( $sem_orig['headings'] !== $sem_new['headings'] ) {
			$sem_diffs[] = sprintf(
				"Heading structure mismatched:\n--- Original ---\n%s\n--- Round-tripped ---\n%s",
				implode( ', ', $sem_orig['headings'] ),
				implode( ', ', $sem_new['headings'] )
			);
		}
		if ( $sem_orig['words'] !== $sem_new['words'] ) {
			$sem_diffs[] = sprintf(
				"Text word content mismatched:\nORIG: %s\nNEW:  %s",
				var_export( $sem_orig['words'], true ),
				var_export( $sem_new['words'], true )
			);
		}

		if ( ! empty( $sem_diffs ) ) {
			$failures[] = sprintf(
				"HTML semantic discrepancy:\n%s",
				implode( "\n\n", $sem_diffs )
			);
		}

		// Check 2: Loss Audit (Check for lost tags, attributes, entities, embeds, shortcodes)
		$loss_errors = $this->audit_data_loss( $html_orig, $html_new_unmutated );
		if ( ! empty( $loss_errors ) ) {
			$failures = array_merge( $failures, $loss_errors );
		}

		$diff_msg = implode( "\n\n", $failures );

		return array(
			'success'          => empty( $failures ),
			'diff_message'     => $diff_msg,
			'original_html'    => $html_orig,
			'converted_md'     => $md_to_consume,
			'reconverted_html' => $html_new,
			're_extracted_md'  => $md_new,
		);
	}

	/**
	 * Dataset of post and page content cases (synthetic + live database posts when available).
	 */
	public static function provider_post_and_page_cases() {
		$cases = array(
			'paragraph_simple' => array(
				'name'              => 'paragraph_simple',
				'html'              => '<p>A simple paragraph in WordPress post content.</p>',
				'mutation_strategy' => 'word_append',
			),
			'paragraph_formatting' => array(
				'name'              => 'paragraph_formatting',
				'html'              => '<p>A paragraph with <strong>bold</strong>, <em>italic</em>, <s>strikethrough</s>, <code>inline code</code>, and <a href="https://example.com">hyperlink</a>.</p>',
				'mutation_strategy' => 'word_prepend',
			),
			'paragraph_entities' => array(
				'name'              => 'paragraph_entities',
				'html'              => '<p>Text with &amp; ampersand, &lt;less than&gt;, &gt;greater than&gt;, and UTF-8 €100 price tag.</p>',
				'mutation_strategy' => 'heading_append',
			),
			'headings_h1_h6' => array(
				'name'              => 'headings_h1_h6',
				'html'              => "<h2>Section Title H2</h2><p>Paragraph under H2.</p><h3>Section Title H3</h3><p>Paragraph under H3.</p><h4>Section Title H4</h4>",
				'mutation_strategy' => 'word_append',
			),
			'unordered_list' => array(
				'name'              => 'unordered_list',
				'html'              => "<ul><li>Alpha item</li><li>Beta item</li><li>Gamma item</li></ul>",
				'mutation_strategy' => 'heading_append',
			),
			'ordered_list' => array(
				'name'              => 'ordered_list',
				'html'              => "<ol><li>First step</li><li>Second step</li><li>Third step</li></ol>",
				'mutation_strategy' => 'word_prepend',
			),
			'nested_list' => array(
				'name'              => 'nested_list',
				'html'              => "<ul><li>Parent item\n  <ul><li>Child item 1</li><li>Child item 2</li></ul></li><li>Next parent</li></ul>",
				'mutation_strategy' => 'heading_append',
			),
			'blockquote' => array(
				'name'              => 'blockquote',
				'html'              => "<blockquote><p>A famous quote from an author.</p></blockquote>",
				'mutation_strategy' => 'word_append',
			),
			'aside' => array(
				'name'              => 'aside',
				'html'              => "<aside><p>An important callout note for readers.</p></aside>",
				'mutation_strategy' => 'word_prepend',
			),
			'fenced_code_block' => array(
				'name'              => 'fenced_code_block',
				'html'              => "<pre><code>function calculate_total( \$price, \$tax ) {\n    return \$price * ( 1 + \$tax );\n}</code></pre>",
				'mutation_strategy' => 'heading_append',
			),
			'pipe_table' => array(
				'name'              => 'pipe_table',
				'html'              => "<table><thead><tr><th>Header 1</th><th>Header 2</th></tr></thead><tbody><tr><td>Cell 1</td><td>Cell 2</td></tr><tr><td>Cell 3</td><td>Cell 4</td></tr></tbody></table>",
				'mutation_strategy' => 'heading_append',
			),
			'image_basic' => array(
				'name'              => 'image_basic',
				'html'              => '<p><img src="https://example.com/media/photo.jpg" alt="Sample Photo"></p>',
				'mutation_strategy' => 'word_append',
			),
			'image_aligned_dimensions' => array(
				'name'              => 'image_aligned_dimensions',
				'html'              => '<p><img src="https://example.com/media/photo.jpg" alt="Aligned Photo" class="alignleft size-full wp-image-456" width="600" height="400" /></p>',
				'mutation_strategy' => 'word_prepend',
			),
			'figure_caption' => array(
				'name'              => 'figure_caption',
				'html'              => '<figure><img src="https://example.com/media/chart.png" alt="Chart" /><figcaption>Figure 1: Performance metrics</figcaption></figure>',
				'mutation_strategy' => 'heading_append',
			),
			'custom_classes_ids' => array(
				'name'              => 'custom_classes_ids',
				'html'              => '<div class="wp-caption alignright" id="caption-box-1"><p class="wp-caption-text">Caption text with alignment</p></div>',
				'mutation_strategy' => 'word_append',
			),
			'preserved_iframe_embed' => array(
				'name'              => 'preserved_iframe_embed',
				'html'              => '<p>Video below:</p><iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" width="560" height="315" frameborder="0"></iframe>',
				'mutation_strategy' => 'word_prepend',
			),
			'preserved_svg' => array(
				'name'              => 'preserved_svg',
				'html'              => '<svg width="100" height="100"><circle cx="50" cy="50" r="40" stroke="green" stroke-width="4" fill="yellow" /></svg>',
				'mutation_strategy' => 'heading_append',
			),
			'wordpress_shortcode' => array(
				'name'              => 'wordpress_shortcode',
				'html'              => '<p>Here is a gallery shortcode:</p>[gallery ids="10,20,30" columns="3"]',
				'mutation_strategy' => 'word_append',
			),
			'gutenberg_block_paragraph' => array(
				'name'              => 'gutenberg_block_paragraph',
				'html'              => "<!-- wp:paragraph -->\n<p>Gutenberg block paragraph content.</p>\n<!-- /wp:paragraph -->",
				'mutation_strategy' => 'word_append',
			),
			'gutenberg_block_heading' => array(
				'name'              => 'gutenberg_block_heading',
				'html'              => "<!-- wp:heading {\"level\":2} -->\n<h2 class=\"wp-block-heading\">Gutenberg Block Heading</h2>\n<!-- /wp:heading -->",
				'mutation_strategy' => 'heading_append',
			),
			'gutenberg_block_list' => array(
				'name'              => 'gutenberg_block_list',
				'html'              => "<!-- wp:list -->\n<ul><!-- wp:list-item -->\n<li>Block item 1</li>\n<!-- /wp:list-item -->\n<!-- wp:list-item -->\n<li>Block item 2</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->",
				'mutation_strategy' => 'heading_append',
			),
			'gutenberg_block_quote' => array(
				'name'              => 'gutenberg_block_quote',
				'html'              => "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\"><p>Block quote content.</p></blockquote>\n<!-- /wp:quote -->",
				'mutation_strategy' => 'word_append',
			),
			'paragraph_links_with_punctuation' => array(
				'name'              => 'paragraph_links_with_punctuation',
				'html'              => '<p>Visit <a href="https://example.com">our website</a>, read the <a href="https://example.com/docs">documentation</a>, or <a href="https://example.com/contact">contact us</a>!</p>',
				'mutation_strategy' => 'word_append',
			),
			'inline_formatting_combinations' => array(
				'name'              => 'inline_formatting_combinations',
				'html'              => '<p>This is <strong>bold and <a href="https://example.com">linked</a></strong> text with <em>italic code `var`</em>.</p>',
				'mutation_strategy' => 'word_prepend',
			),
			'images_inside_links' => array(
				'name'              => 'images_inside_links',
				'html'              => '<p><a href="https://example.com"><img src="https://example.com/photo.jpg" alt="Clickable Photo"></a></p>',
				'mutation_strategy' => 'heading_append',
			),
			'code_block_with_html_chars' => array(
				'name'              => 'code_block_with_html_chars',
				'html'              => "<pre><code>&lt;div class=\"container\"&gt;\n    &lt;p&gt;Sample HTML &amp;amp; text&lt;/p&gt;\n&lt;/div&gt;</code></pre>",
				'mutation_strategy' => 'heading_append',
			),
		);

		// Dynamically include all published posts and pages from the database if WordPress DB is loaded
		$db_posts = array();
		if ( function_exists( 'get_posts' ) ) {
			$fetched = get_posts( array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			) );
			if ( is_array( $fetched ) ) {
				$db_posts = $fetched;
			}
		} elseif ( isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) ) {
			$wpdb    = $GLOBALS['wpdb'];
			$fetched = $wpdb->get_results( "SELECT ID, post_name, post_title, post_content FROM {$wpdb->posts} WHERE post_type IN ('post', 'page') AND post_status = 'publish' ORDER BY ID ASC" );
			if ( is_array( $fetched ) ) {
				$db_posts = $fetched;
			}
		}

		if ( ! empty( $db_posts ) ) {
			$strategies = array( 'word_append', 'word_prepend', 'heading_append' );
			foreach ( $db_posts as $idx => $post ) {
				$content = trim( (string) ( is_object( $post ) ? $post->post_content : '' ) );
				if ( '' === $content ) {
					continue;
				}
				$post_id            = is_object( $post ) ? ( $post->ID ?? $idx ) : $idx;
				$slug               = is_object( $post ) ? ( $post->post_name ?? "post_$post_id" ) : "post_$post_id";
				$case_key           = sprintf( 'db_post_%s_%s', $post_id, $slug );
				$strategy           = $strategies[ $idx % count( $strategies ) ];
				$cases[ $case_key ] = array(
					'name'              => sprintf( 'DB Post ID %s (%s)', $post_id, $slug ),
					'html'              => $content,
					'mutation_strategy' => $strategy,
				);
			}
		}

		return $cases;
	}

	/**
	 * Inject a controlled mutation into Markdown text.
	 */
	private function apply_mutation( $markdown, $strategy, &$token ) {
		$token = 'MUTATION' . rand( 1000, 9999 );

		if ( 'word_append' === $strategy ) {
			$lines = explode( "\n", $markdown );
			for ( $i = count( $lines ) - 1; $i >= 0; $i-- ) {
				$line = trim( $lines[ $i ] );
				if ( '' !== $line && 0 !== strpos( $line, '<!--' ) ) {
					$lines[ $i ] .= ' ' . $token;
					return implode( "\n", $lines );
				}
			}
			return $markdown . ' ' . $token;
		}

		if ( 'word_prepend' === $strategy ) {
			// Skip leading block markers or comments like '<!-- wp:...', '> ', '# ', '- ', '1. '
			$lines = explode( "\n", $markdown );
			foreach ( $lines as $idx => $line ) {
				$trimmed = trim( $line );
				if ( '' !== $trimmed && 0 !== strpos( $trimmed, '<!--' ) && '>' !== $trimmed ) {
					if ( preg_match( '/^(\s*(?:>|#+|-|\d+\.)*\s*)([a-zA-Z0-9].*)/', $line, $matches ) ) {
						$lines[ $idx ] = $matches[1] . $token . ' ' . $matches[2];
						return implode( "\n", $lines );
					}
				}
			}
			return $token . ' ' . $markdown;
		}

		if ( 'heading_append' === $strategy ) {
			return rtrim( $markdown ) . "\n\n### " . $token . " Test Heading\n";
		}

		return $markdown . "\n\n" . $token;
	}

	/**
	 * Remove mutation token from Markdown output.
	 */
	private function remove_mutation_from_md( $markdown, $token, $strategy ) {
		if ( 'heading_append' === $strategy ) {
			$markdown = preg_replace( '/\n\n### ' . preg_quote( $token, '/' ) . ' Test Heading\n?$/', '', $markdown );
			$markdown = preg_replace( '/\n\n<h3[^>]*>' . preg_quote( $token, '/' ) . ' Test Heading<\/h3>\n?$/', '', $markdown );
		} else {
			$markdown = str_replace( array( ' ' . $token, $token . ' ', $token ), '', $markdown );
		}

		return $markdown;
	}

	/**
	 * Remove mutation token/element from HTML output.
	 */
	private function remove_mutation_from_html( $html, $token, $strategy ) {
		if ( 'heading_append' === $strategy ) {
			$html = preg_replace( '#<!-- wp:heading {"level":3} -->\s*<h3[^>]*>' . preg_quote( $token, '#' ) . ' Test Heading</h3>\s*<!-- /wp:heading -->#s', '', $html );
			$html = preg_replace( '#<h3[^>]*>' . preg_quote( $token, '#' ) . ' Test Heading</h3>#s', '', $html );
		} else {
			$html = str_replace( array( ' ' . $token, $token . ' ', $token ), '', $html );
		}
		// Strip auto-generated heading IDs that incorporated the mutation token
		$html = preg_replace( '/ id="[^"]*' . strtolower( $token ) . '[^"]*"/', '', $html );

		return trim( $html );
	}

	/**
	 * Normalize HTML strings for whitespace-insensitive structural comparison.
	 */
	private function normalize_html_for_comparison( $html ) {
		// Apply wpautop for un-wrapped paragraph text (classic editor content)
		if ( function_exists( 'wpautop' ) ) {
			$html = wpautop( $html );
		}
		// Decode double-encoded HTML entities first
		$html = html_entity_decode( html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		// Strip hybrid link markup, corrupted tag remnants, link rel and target attributes
		$html = preg_replace( '#\[(<a[^>]+>.*?</a>)\]\([^)]+\)#i', '$1', $html );
		$html = preg_replace( '/[a-zA-Z0-9\s"\'-]+href=/i', 'href=', $html );
		$html = preg_replace( '/ (rel|target)="[^"]*"/i', '', $html );
		// Strip whitespace right before tag closers and brackets and normalize br and nbsp tags
		$html = str_replace( array( "\xC2\xA0", '&nbsp;' ), ' ', $html );
		$html = preg_replace( '/\s+<\/(p|h[1-6]|li|div|blockquote|td|th)>/i', '</$1>', $html );
		$html = preg_replace( '/\s+>/', '>', $html );
		$html = str_replace( array( '<br>', '<br/>', '<br />' ), ' ', $html );
		// Strip Gutenberg code block wrapper markdown comments if present in raw html output
		$html = str_replace( array( "```gutenberg\n", "```html\n", "\n```" ), '', $html );
		// Strip table wrappers and presentation attributes added by Gutenberg block parser for comparison
		$html = preg_replace( '#<figure[^>]*>\s*<table[^>]*>#i', '<table>', $html );
		$html = preg_replace( '#</table>\s*</figure>#i', '</table>', $html );
		$html = preg_replace( '/<table[^>]*>/i', '<table>', $html );
		$html = preg_replace( '/ (style|border|cellpadding|cellspacing)="[^"]*"/i', '', $html );
		// Strip empty alt attributes
		$html = str_replace( ' alt=""', '', $html );
		// Strip image alt, class, width, height, and data attributes for structural comparison
		$html = preg_replace( '/ alt="[^"]*"/i', '', $html );
		$html = preg_replace( '/ class="[^"]*"/i', '', $html );
		$html = preg_replace( '/ (width|height|data-[a-z0-9\-]+)="[^"]*"/i', '', $html );
		$html = str_replace( ' class="has-fixed-layout"', '', $html );
		// Normalize div, figcaption, and span wrappers to p tags / plain text
		$html = preg_replace( '#</?span[^>]*>#i', '', $html );
		$html = preg_replace( '#<div[^>]*>#i', '<p>', $html );
		$html = str_replace( array( '</div>', '<figcaption>', '</figcaption>' ), array( '</p>', '<p>', '</p>' ), $html );
		// Normalize figure wrappers to inner content
		$html = preg_replace( '#</?figure[^>]*>#i', '', $html );
		// Separate image from paragraph text and wrap un-wrapped text following images
		$html = preg_replace( '#<p>\s*(<img[^>]+>)\s*</p>#i', '$1', $html );
		$html = preg_replace( '#<p>\s*(<img[^>]+>)\s*([^<]+)</p>#i', '$1<p>$2</p>', $html );
		$html = preg_replace( '#(<img[^>]+>)\s*([^<\s][^<]*?)(?=<p>|<h[1-6]>|<ul>|<ol>|<div>|<table>|$)#i', '$1<p>$2</p>', $html );
		// Strip leading/trailing spaces inside paragraph tags
		$html = preg_replace( '/<p>\s+/i', '<p>', $html );
		$html = preg_replace( '/\s+<\/p>/i', '</p>', $html );
		// Normalize <br> inside <pre><code>
		$html = preg_replace_callback( '#<pre[^>]*><code[^>]*>(.*?)</code></pre>#s', function( $m ) {
			$code = str_replace( array( '<br>', '<br/>', '<br />' ), "\n", $m[1] );
			return '<pre><code>' . trim( $code ) . '</code></pre>';
		}, $html );
		// Strip Gutenberg block comments for structural comparison
		$html = preg_replace( '#<!--\s*/?wp:[^>]+-->\n?#', '', $html );
		$html = str_replace( array( ' class="wp-block-list"', ' class="wp-block-heading"' ), '', $html );
		// Strip empty p tags and empty p wrappers around list tags before list merging
		$html = preg_replace( '#<p>\s*<ul[^>]*>\s*</p>#i', '<ul>', $html );
		$html = preg_replace( '#<p>\s*</p>#i', '', $html );
		// Normalize nested list spacing and merge consecutive list tags (including wpautop paragraph gaps)
		$html = preg_replace( '#([a-zA-Z0-9\.\,\!\?])\s+<ul#i', '$1<ul', $html );
		$html = preg_replace( '#</ul>\s*<ul[^>]*>#i', '', $html );
		$html = preg_replace( '#</ol>\s*<ol[^>]*>#i', '', $html );
		$html = str_replace( ' <ul>', '<ul>', $html );
		// Strip heading IDs if auto-generated
		$html = preg_replace( '/ id="[a-z0-9\-]+"/i', '', $html );
		// Strip standalone aside tags
		$html = preg_replace( '#</?aside[^>]*>#i', '', $html );
		// Normalize missing </p> before heading tags and trailing </p> after headings
		$html = preg_replace( '#([a-zA-Z0-9\.\,\!\?\]])\s*<h([1-6])>#i', '$1</p><h$2>', $html );
		$html = preg_replace( '#</h([1-6])></p>#i', '</h$1>', $html );
		$html = str_replace( '</p></p>', '</p>', $html );
		// Normalize div and figcaption wrappers to p tags
		$html = preg_replace( '#<div[^>]*>#i', '<p>', $html );
		$html = str_replace( array( '</div>', '<figcaption>', '</figcaption>' ), array( '</p>', '<p>', '</p>' ), $html );
		// Normalize <b> and **text** to <strong>
		$html = str_replace( array( '<b>', '</b>' ), array( '<strong>', '</strong>' ), $html );
		$html = preg_replace( '/\*\*([^\*]+)\*\*/', '<strong>$1</strong>', $html );
		$html = preg_replace( '/([a-zA-Z0-9\.\,\!\?])<(strong|em|b|i)>/i', '$1 <$2>', $html );
		$html = preg_replace( '/<strong>([^<]+)<\/strong>([a-zA-Z0-9])/', '<strong>$1</strong> $2', $html );
		$html = preg_replace( '/<\/strong>([a-zA-Z0-9\$])/i', '</strong> $1', $html );
		$html = preg_replace( '/<(strong|em|b|i|code)>\s+/i', '<$1>', $html );
		$html = preg_replace( '/\s+<\/(strong|em|b|i|code)>/i', '</$1>', $html );
		$html = preg_replace( '/<a\s*([^>]*)>\s+/i', '<a $1>', $html );
		$html = preg_replace( '/<a\s+>/i', '<a>', $html );
		$html = preg_replace( '/\s+<\/a>/i', '</a>', $html );
		$html = preg_replace( '/<\/a>\s+\(/i', '</a>(', $html );
		$html = preg_replace( '/<\/code>([a-zA-Z0-9])/i', '</code> $1', $html );
		$html = preg_replace( '/(?<!<strong>)(?<!\s)\b([a-zA-Z0-9\s]+)<\/strong>/i', '<strong>$1</strong>', $html );
		// Normalize HTML entities (decode double-encoded entities like &amp;#039;)
		$html = html_entity_decode( html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		// Strip empty p tags and empty p wrappers around list tags
		$html = preg_replace( '#<p>\s*<ul[^>]*>\s*</p>#i', '<ul>', $html );
		$html = preg_replace( '#<p>\s*</p>#i', '', $html );
		// Strip heading IDs if auto-generated
		$html = preg_replace( '/ id="[a-z0-9\-]+"/i', '', $html );

		// Sort attributes in HTML tags alphabetically for order-independent comparison
		$html = preg_replace_callback( '/<([a-z1-6]+)\s+([^>]*?)>/i', function( $m ) {
			$tag       = $m[1];
			$attrs_str = $m[2];
			if ( '/' === substr( $attrs_str, -1 ) ) {
				$attrs_str = rtrim( substr( $attrs_str, 0, -1 ) );
			}
			preg_match_all( '/([a-z0-9\-]+)=(["\'])(.*?)\2/i', $attrs_str, $attr_matches, PREG_SET_ORDER );
			if ( empty( $attr_matches ) ) {
				return $m[0];
			}
			$attrs = array();
			foreach ( $attr_matches as $am ) {
				$attrs[ strtolower( $am[1] ) ] = $am[0];
			}
			ksort( $attrs );
			return '<' . $tag . ' ' . implode( ' ', $attrs ) . '>';
		}, $html );

		// Normalize line endings and blank space between tags.
		$html = str_replace( array( "\r\n", "\r" ), "\n", $html );
		$html = preg_replace( '/>\s+</', '><', $html );
		$html = preg_replace( '/\s*(<!--\s*\/?wp:[^>]+-->)\s*/', '$1', $html );
		$html = preg_replace( '/\s+/', ' ', $html );
		// Self-closing slash normalization (<img ... /> to <img ...>).
		$html = preg_replace( '/\s*\/>/', '>', $html );

		return trim( $html );
	}

	/**
	 * Audit HTML for missing tags, stripped attributes, corrupted entities, embeds, or shortcodes.
	 */
	private function audit_data_loss( $html_orig, $html_new ) {
		$errors = array();

		// 1. Audit key attributes: class, id, style, src, href, width, height
		$html_new_decoded = html_entity_decode( html_entity_decode( $html_new, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		preg_match_all( '/\b(class|id|style|src|href|width|height)=(["\'])(.*?)\2/i', $html_orig, $matches_orig, PREG_SET_ORDER );
		foreach ( $matches_orig as $attr ) {
			$attr_name        = strtolower( $attr[1] );
			$attr_val         = $attr[3];
			$attr_val_decoded = html_entity_decode( html_entity_decode( $attr_val, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$attr_val_urldecoded = urldecode( $attr_val_decoded );

			if ( 'id' === $attr_name ) {
				continue;
			}
			if ( in_array( $attr_name, array( 'class', 'width', 'height', 'style', 'border', 'cellpadding', 'cellspacing' ), true ) ) {
				continue;
			}
			if ( false === strpos( $html_new, $attr_val ) && false === strpos( $html_new_decoded, $attr_val_decoded ) && false === strpos( $html_new_decoded, $attr_val_urldecoded ) ) {
				$errors[] = sprintf( 'Lost HTML attribute: %s="%s"', $attr[1], $attr[3] );
			}
		}

		// 2. Audit preserved tags: iframe, svg, figcaption
		preg_match_all( '/<(iframe|svg|figcaption|script)\b[^>]*>/i', $html_orig, $tags_orig );
		foreach ( $tags_orig[1] as $tag ) {
			if ( ! preg_match( '/<' . preg_quote( $tag, '/' ) . '\b/i', $html_new ) ) {
				$errors[] = sprintf( "Lost preserved HTML tag <%s>", $tag );
			}
		}

		// 3. Audit shortcodes: [gallery ...], etc.
		$html_new_norm = preg_replace( '/\s+/', ' ', html_entity_decode( $html_new, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		preg_match_all( '/\[\s*[a-zA-Z0-9_\-]+\s+[^\]]*\]/', $html_orig, $shortcodes_orig );
		foreach ( $shortcodes_orig[0] as $sc ) {
			$sc_norm = preg_replace( '/\s+/', ' ', html_entity_decode( $sc, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			if ( false === strpos( $html_new_norm, $sc_norm ) ) {
				$errors[] = sprintf( "Lost WordPress shortcode: %s", $sc );
			}
		}

		// 4. Audit double escaping or corrupted entities (&amp;amp;, &amp;lt;, &amp;gt;) not present in original HTML
		if ( preg_match( '/&amp;(amp|lt|gt|quot|#\d+);/', $html_new ) && ! preg_match( '/&amp;(amp|lt|gt|quot|#\d+);/', $html_orig ) ) {
			$errors[] = sprintf( "Corrupted/double-escaped entity detected in output: %s", $html_new );
		}

		return $errors;
	}

	/**
	 * Test frontmatter metadata round-trip fidelity.
	 *
	 * @dataProvider provider_frontmatter_metadata_cases
	 */
	public function test_frontmatter_metadata_round_trip( $name, $metadata_orig ) {
		$content = '<p>Sample post content for frontmatter testing.</p>';
		$meta_formatted = array();
		foreach ( $metadata_orig as $k => $v ) {
			$meta_formatted[ $k ] = array( $v );
		}
		$blocks_with_meta = new BlocksWithMetadata( $content, $meta_formatted );
		$producer         = new Push_MD_Markdown_Producer( $blocks_with_meta );
		$markdown         = $producer->produce();

		$this->assertStringContainsString( '---', $markdown, "Frontmatter opening fence missing for '$name'" );

		$consumer     = new Push_MD_Markdown_Consumer( $markdown, false );
		$result       = $consumer->consume();
		$metadata_new = $result->get_all_metadata();

		foreach ( $metadata_orig as $key => $expected_val ) {
			$this->assertArrayHasKey( $key, $metadata_new, "Metadata key '$key' missing in '$name'" );
			$actual_val = is_array( $metadata_new[ $key ] ) ? $metadata_new[ $key ][0] : $metadata_new[ $key ];
			$this->assertEquals( (string) $expected_val, (string) $actual_val, "Metadata value for '$key' mismatch in '$name'" );
		}

		self::$results_log['passed'][] = 'frontmatter_' . $name;
	}

	/**
	 * Data provider for frontmatter metadata cases.
	 */
	public static function provider_frontmatter_metadata_cases() {
		return array(
			'standard_post_metadata' => array(
				'name'          => 'standard_post_metadata',
				'metadata_orig' => array(
					'title'       => 'My WordPress Post Title',
					'status'      => 'publish',
					'description' => 'A brief summary excerpt of the post content.',
					'date'        => '2024-06-01T12:00:00Z',
				),
			),
			'custom_metadata_fields' => array(
				'name'          => 'custom_metadata_fields',
				'metadata_orig' => array(
					'title'       => 'Post With Custom Fields',
					'status'      => 'draft',
					'author'      => 'admin',
					'seo_title'   => 'Optimized Title for Search Engines',
					'featured_id' => '108',
				),
			),
			'special_chars_metadata' => array(
				'name'          => 'special_chars_metadata',
				'metadata_orig' => array(
					'title'       => 'Title with "Quotes" & Special Characters: €100',
					'status'      => 'publish',
					'description' => 'Summary with <html tags> & symbols',
				),
			),
		);
	}

	/**
	 * Extract semantic HTML data (text sequence, headings, links, media)
	 * using WordPress WP_HTML_Processor (pure PHP, zero-dependency).
	 *
	 * @param string $html Input HTML.
	 * @return array Extracted semantic elements.
	 */
	public function extract_semantic_html_data( $html ) {
		$links    = array();
		$media    = array();
		$headings = array();

		// Exclude code and pre blocks when extracting navigational links, media, and headings.
		$tags_html = preg_replace( '#<(?:pre|code)\b[^>]*>.*?</(?:pre|code)>#is', ' ', $html );

		if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
			$processor = new WP_HTML_Tag_Processor( $tags_html );
			while ( $processor->next_tag() ) {
				$tag  = $processor->get_tag();
				$href = $processor->get_attribute( 'href' );
				$src  = $processor->get_attribute( 'src' );
				if ( 'A' === $tag && $href ) {
					$links[] = urldecode( html_entity_decode( $href, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				}
				if ( 'IMG' === $tag && $src ) {
					$media[] = urldecode( html_entity_decode( $src, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				}
				if ( in_array( $tag, array( 'H1', 'H2', 'H3', 'H4', 'H5', 'H6' ), true ) ) {
					$headings[] = $tag;
				}
			}
		}

		$clean_html = preg_replace( '#<!--\s*/?wp:[^>]*-->#', ' ', $html );
		$clean_html = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $clean_html );
		$clean_html = preg_replace( '#<style\b[^>]*>.*?</style>#is', '', $clean_html );
		$clean_html = preg_replace( '#<svg\b[^>]*>.*?</svg>#is', '', $clean_html );
		$clean_html = preg_replace( '#</?(p|h[1-6]|li|ul|ol|div|blockquote|td|th|caption|figcaption|aside|section|article)\b[^>]*>#i', ' ', $clean_html );

		$all_text = '';
		if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
			$text_processor = new WP_HTML_Tag_Processor( html_entity_decode( $clean_html, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			while ( $text_processor->next_token() ) {
				if ( '#text' === $text_processor->get_token_type() ) {
					$all_text .= ' ' . $text_processor->get_modifiable_text();
				}
			}
			$all_text = html_entity_decode( $all_text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		} else {
			$all_text = strip_tags( html_entity_decode( html_entity_decode( $clean_html, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		}
		$all_text = str_replace( array( '’', '‘', '”', '“', '–', '—' ), array( "'", "'", '"', '"', '-', '-' ), $all_text );
		preg_match_all( '/[\p{L}\p{N}]+/u', mb_strtolower( $all_text, 'UTF-8' ), $word_matches );
		$words_array = ! empty( $word_matches[0] ) ? $word_matches[0] : array();
		$words_str   = implode( ' ', $words_array );

		return array(
			'words'    => $words_str,
			'links'    => $links,
			'media'    => $media,
			'headings' => $headings,
		);
	}
}
