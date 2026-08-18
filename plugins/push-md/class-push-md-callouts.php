<?php

/**
 * Registry and processor for Markdown Callouts, Directives (:::), and Admonitions.
 *
 * Provides a unified pipeline that accepts all three major Markdown callout
 * standards on input, compiles them into clean HTML / Gutenberg blocks, and
 * exports HTML back into portable GFM callouts.
 *
 * ----------------------------------------------------------------------------
 * 1. SUPPORTED INPUT SYNTAXES (All 3 compile to identical HTML)
 * ----------------------------------------------------------------------------
 *
 * A. GFM / Obsidian Native Callouts:
 *    > [!note] Heads Up!
 *    > Remember to back up your database before upgrading.
 *    > * Item one
 *    > * Item two
 *
 * B. Generic Directives (CommonMark / Docusaurus):
 *    :::note title="Heads Up!"
 *    Remember to back up your database before upgrading.
 *    - Item one
 *    - Item two
 *    :::
 *
 * C. Code Block Admonitions (Obsidian Admonition Plugin):
 *    ```ad-note
 *    title: Heads Up!
 *    Remember to back up your database before upgrading.
 *    - Item one
 *    - Item two
 *    ```
 *
 * ----------------------------------------------------------------------------
 * 2. REGISTRATION EXAMPLES (How developers configure callouts)
 * ----------------------------------------------------------------------------
 *
 * Example A: Infobox / Callout with a semantic paragraph label (no heading clutter)
 *    Push_MD_Callouts::register( 'note', array(
 *        'tag'           => 'aside',
 *        'class'         => 'note',
 *        'title_element' => 'p.callout-title', // <aside class="note"><p class="callout-title">Title</p>...</aside>
 *    ) );
 *
 * Example B: FAQ / Section block with an H2 document heading
 *    Push_MD_Callouts::register( 'faq', array(
 *        'tag'           => 'div',
 *        'class'         => 'faq',
 *        'title_element' => 'h2',              // <div class="faq"><h2>Title</h2>...</div>
 *    ) );
 *
 * Example C: Gutenberg Block Mapping
 *    Push_MD_Callouts::register( 'alert', array(
 *        'block' => 'my-plugin/alert-box',     // <!-- wp:my-plugin/alert-box -->...<!-- /wp:my-plugin/alert-box -->
 *    ) );
 *
 * ----------------------------------------------------------------------------
 * 3. EXPORT (HTML to Markdown)
 * ----------------------------------------------------------------------------
 *
 * By default, HTML nodes matching registered callouts export to GFM callouts (`> [!type] Title`).
 *
 * To export as `:::type` directives instead, use the filter:
 *    add_filter( 'push_md_export_callout_format', function() {
 *        return 'directive';
 *    } );
 */
class Push_MD_Callouts {

	/**
	 * Registry of all configured callouts.
	 *
	 * @var array
	 */
	private static $registry = array();

	/**
	 * Flag indicating whether default standard callouts have been seeded.
	 *
	 * @var bool
	 */
	private static $defaults_initialized = false;

	/**
	 * Ensures standard GFM and Obsidian callout types are initialized by default.
	 */
	public static function ensure_defaults_initialized() {
		if ( self::$defaults_initialized ) {
			return;
		}
		self::$defaults_initialized = true;

		$standard_asides = array( 'note', 'tip', 'important', 'warning', 'caution', 'info', 'danger', 'alert', 'success', 'example', 'quote' );
		foreach ( $standard_asides as $name ) {
			if ( ! isset( self::$registry[ $name ] ) ) {
				self::$registry[ $name ] = array(
					'name'          => $name,
					'class'         => $name,
					'id'            => null,
					'tag'           => 'aside',
					'title_element' => 'p.callout-title',
					'slots'         => array(),
					'block'         => null,
				);
			}
		}

		if ( ! isset( self::$registry['faq'] ) ) {
			self::$registry['faq'] = array(
				'name'          => 'faq',
				'class'         => 'faq',
				'id'            => null,
				'tag'           => 'div',
				'title_element' => 'h2',
				'slots'         => array(),
				'block'         => null,
			);
		}
	}

	/**
	 * Retrieves the configuration for a callout type, falling back to a dynamic generic definition.
	 *
	 * @param string $name Callout name.
	 * @return array Callout configuration.
	 */
	public static function get_directive_config( $name ) {
		self::ensure_defaults_initialized();
		$name = strtolower( $name );
		if ( isset( self::$registry[ $name ] ) ) {
			return self::$registry[ $name ];
		}

		return array(
			'name'          => $name,
			'class'         => $name,
			'id'            => null,
			'tag'           => 'aside',
			'title_element' => 'p.callout-title',
			'slots'         => array(),
			'block'         => null,
			'is_dynamic'    => true,
		);
	}

	/**
	 * Registers a custom callout / directive.
	 *
	 * Supported $config keys:
	 *  - tag           (string) HTML tag name ('div', 'aside', 'section'). Default 'div'.
	 *  - class         (string) Primary CSS class for matching and output. Default $name.
	 *  - id            (string) Optional ID attribute required for matching. Default null.
	 *  - title_element (string) CSS selector for rendering title ('h2', 'h3', 'p.callout-title'). Default null.
	 *  - block         (string) WordPress block name ('core/quote', 'my-plugin/alert'). Default null.
	 *  - slots         (array)  Named inner slots for declarative template mapping. Default empty array.
	 *
	 * @param string $name   The callout name used in `> [!name]` or `:::name` (e.g. 'note', 'faq', 'warning').
	 * @param array  $config Configuration options.
	 */
	public static function register( $name, $config = array() ) {
		self::ensure_defaults_initialized();

		$parsed = array(
			'name'          => $name,
			'class'         => isset( $config['class'] ) ? $config['class'] : $name,
			'id'            => isset( $config['id'] ) ? $config['id'] : null,
			'tag'           => isset( $config['tag'] ) ? $config['tag'] : 'div',
			'title_element' => isset( $config['title_element'] ) ? $config['title_element'] : null,
			'slots'         => isset( $config['slots'] ) && is_array( $config['slots'] ) ? $config['slots'] : array(),
			'block'         => null,
		);

		if ( isset( $config['block'] ) && is_string( $config['block'] ) && false !== strpos( $config['block'], '/' ) ) {
			$parsed['block'] = $config['block'];
		}

		self::$registry[ $name ] = $parsed;
	}

	/**
	 * Get all registered callouts.
	 *
	 * @return array Array of registered callout configurations keyed by name.
	 */
	public static function get_directives() {
		self::ensure_defaults_initialized();
		return self::$registry;
	}

	/**
	 * Main entry point for Push_MD_HTML_Converter.
	 * Determines how an HTML node should be processed back into Markdown.
	 *
	 * @param \WordPress\DataLiberation\DataLiberationHTMLProcessor $processor       The HTML processor at the current token.
	 * @param string                                                $converter_class The class name to call for recursive conversion.
	 * @return string|null Markdown/HTML string if handled, null to continue normal processing.
	 */
	public static function convert_node( $processor, $converter_class ) {
		$tag = $processor->get_tag();

		// 1. Check if the node matches a registered callout.
		$matched = self::match_html( $processor );
		if ( $matched ) {
			$inner_html = $processor->get_inner_html();
			if ( false !== $inner_html ) {
				$format = function_exists( 'apply_filters' )
					? apply_filters( 'push_md_export_callout_format', 'callout' )
					: 'callout';

				if ( 'admonition' === $format ) {
					return self::format_as_admonition( $matched, $inner_html, $converter_class );
				}

				if ( 'directive' === $format ) {
					return self::format_as_directive( $matched, $inner_html, $converter_class, $processor );
				}

				return self::format_as_gfm_callout( $matched, $inner_html, $converter_class );
			}
		}

		// 2. Check for raw HTML preservation.
		if ( self::should_preserve_element( $processor, $tag ) ) {
			$container_tags = array( 'div', 'aside', 'section', 'article', 'header', 'footer', 'nav', 'main', 'figure', 'blockquote' );
			if ( in_array( strtolower( $tag ), $container_tags, true ) ) {
				$inner = $processor->get_inner_html();
				if ( false !== $inner ) {
					$attr_names = $processor->get_attribute_names_with_prefix( '' );
					$attr_str   = '';
					if ( ! empty( $attr_names ) ) {
						foreach ( $attr_names as $attr_name ) {
							$val       = $processor->get_attribute( $attr_name );
							$attr_str .= ' ' . $attr_name . '="' . htmlspecialchars( $val, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . '"';
						}
					}
					$opening_tag = '<' . strtolower( $tag ) . $attr_str . '>';
					$closing_tag = '</' . strtolower( $tag ) . '>';

					$inner_md = call_user_func( array( $converter_class, 'convert_fragment' ), $inner );
					return "\n\n" . $opening_tag . "\n\n" . trim( $inner_md ) . "\n\n" . $closing_tag . "\n\n";
				}
			}

			// Inline elements get preserved raw.
			$outer       = call_user_func( array( $converter_class, 'get_outer_html' ), $processor, $tag );
			$outer       = preg_replace( '/^[ \t]+/m', '', $outer );
			$inline_tags = array( 'a', 'span', 'b', 'i', 'strong', 'em', 'code', 's', 'del', 'sub', 'sup' );

			if ( in_array( strtolower( $tag ), $inline_tags, true ) ) {
				return $outer;
			} else {
				$outer_clean = preg_replace( "/\n{2,}/", "\n", $outer );
				return "\n\n" . $outer_clean . "\n\n";
			}
		}

		return null;
	}

	/**
	 * Formats a matched callout node as a GFM callout (`> [!type] Title`).
	 */
	private static function format_as_gfm_callout( $matched, $inner_html, $converter_class ) {
		$title = '';

		// 1. Check if a title element was specified and exists at the start of inner_html.
		if ( ! empty( $matched['title_element'] ) ) {
			$te          = self::parse_title_element( $matched['title_element'] );
			$tag_pattern = preg_quote( $te['tag'], '/' );
			$cls_pattern = $te['class'] ? '\s+class=["\'][^"\']*\b' . preg_quote( $te['class'], '/' ) . '\b[^"\']*["\']' : '';

			if ( preg_match( '/^\s*<' . $tag_pattern . $cls_pattern . '[^>]*>(.*?)<\/' . $tag_pattern . '>\s*/is', $inner_html, $tm ) ) {
				$title      = trim( html_entity_decode( strip_tags( $tm[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				$inner_html = substr( $inner_html, strlen( $tm[0] ) );
			}
		}

		// Fallback: check for leading <h2> or <h3> if title wasn't extracted yet.
		if ( '' === $title && preg_match( '/^\s*<h[23][^>]*>(.*?)<\/h[23]>\s*/is', $inner_html, $tm ) ) {
			$title      = trim( html_entity_decode( strip_tags( $tm[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			$inner_html = substr( $inner_html, strlen( $tm[0] ) );
		}

		$inner_md = call_user_func( array( $converter_class, 'convert_fragment' ), $inner_html );
		$inner_md = trim( $inner_md );

		$header = '> [!' . $matched['name'] . ']' . ( '' !== $title ? ' ' . $title : '' );

		if ( '' === $inner_md ) {
			return "\n\n" . $header . "\n\n";
		}

		$lines    = explode( "\n", $inner_md );
		$prefixed = array();
		foreach ( $lines as $l ) {
			if ( '' === trim( $l ) ) {
				$prefixed[] = '>';
			} else {
				$prefixed[] = '> ' . $l;
			}
		}

		return "\n\n" . $header . "\n" . implode( "\n", $prefixed ) . "\n\n";
	}

	/**
	 * Formats a matched callout node as a code block admonition (```ad-type).
	 */
	private static function format_as_admonition( $matched, $inner_html, $converter_class ) {
		$title = '';

		// 1. Check if a title element was specified and exists at the start of inner_html.
		if ( ! empty( $matched['title_element'] ) ) {
			$te          = self::parse_title_element( $matched['title_element'] );
			$tag_pattern = preg_quote( $te['tag'], '/' );
			$cls_pattern = $te['class'] ? '\s+class=["\'][^"\']*\b' . preg_quote( $te['class'], '/' ) . '\b[^"\']*["\']' : '';

			if ( preg_match( '/^\s*<' . $tag_pattern . $cls_pattern . '[^>]*>(.*?)<\/' . $tag_pattern . '>\s*/is', $inner_html, $tm ) ) {
				$title      = trim( html_entity_decode( strip_tags( $tm[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				$inner_html = substr( $inner_html, strlen( $tm[0] ) );
			}
		}

		// Fallback: check for leading <h2> or <h3> if title wasn't extracted yet.
		if ( '' === $title && preg_match( '/^\s*<h[23][^>]*>(.*?)<\/h[23]>\s*/is', $inner_html, $tm ) ) {
			$title      = trim( html_entity_decode( strip_tags( $tm[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			$inner_html = substr( $inner_html, strlen( $tm[0] ) );
		}

		$inner_md = call_user_func( array( $converter_class, 'convert_fragment' ), $inner_html );
		$inner_md = trim( $inner_md );

		$title_line = ( '' !== $title ) ? 'title: ' . $title . "\n" : '';

		return "\n\n```ad-" . $matched['name'] . "\n" . $title_line . $inner_md . "\n```\n\n";
	}

	/**
	 * Formats a matched node as a standard `:::type` directive.
	 */
	private static function format_as_directive( $matched, $inner_html, $converter_class, $processor ) {
		$title = '';
		if ( ! empty( $matched['title_element'] ) && 'h2' !== strtolower( $matched['title_element'] ) ) {
			$te          = self::parse_title_element( $matched['title_element'] );
			$tag_pattern = preg_quote( $te['tag'], '/' );
			$cls_pattern = $te['class'] ? '\s+class=["\'][^"\']*\b' . preg_quote( $te['class'], '/' ) . '\b[^"\']*["\']' : '';

			if ( preg_match( '/^\s*<' . $tag_pattern . $cls_pattern . '[^>]*>(.*?)<\/' . $tag_pattern . '>\s*/is', $inner_html, $tm ) ) {
				$title      = trim( html_entity_decode( strip_tags( $tm[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				$inner_html = substr( $inner_html, strlen( $tm[0] ) );
			}
		}

		$inner_md = call_user_func( array( $converter_class, 'convert_fragment' ), $inner_html );
		$inner_md = trim( $inner_md );

		$args_str   = '';
		$attr_names = $processor->get_attribute_names_with_prefix( '' );
		$args       = array();
		if ( ! empty( $attr_names ) ) {
			foreach ( $attr_names as $attr_name ) {
				if ( 'class' === $attr_name ) {
					$classes  = explode( ' ', trim( $processor->get_attribute( 'class' ) ) );
					$filtered = array_diff( $classes, array( $matched['class'] ) );
					if ( ! empty( $filtered ) ) {
						$args['class'] = implode( ' ', $filtered );
					}
				} elseif ( 'id' === $attr_name ) {
					if ( $matched['id'] !== $processor->get_attribute( 'id' ) ) {
						$args['id'] = $processor->get_attribute( 'id' );
					}
				} else {
					$args[ $attr_name ] = $processor->get_attribute( $attr_name );
				}
			}
		}

		if ( '' !== $title && ! isset( $args['title'] ) ) {
			$args['title'] = $title;
		}

		if ( ! empty( $args ) ) {
			$args_str = ' ' . self::format_args( $args );
		}

		return "\n\n:::" . $matched['name'] . $args_str . "\n" . $inner_md . "\n:::\n\n";
	}

	/**
	 * Checks if the current processor node matches a registered callout.
	 */
	private static function match_html( $processor ) {
		self::ensure_defaults_initialized();
		$tag   = strtolower( $processor->get_tag() );
		$class = $processor->get_attribute( 'class' );
		$id    = $processor->get_attribute( 'id' );

		$classes = $class ? explode( ' ', trim( $class ) ) : array();

		// 1. Explicit / Built-in registry match.
		foreach ( self::$registry as $directive ) {
			if ( $directive['block'] ) {
				continue;
			}

			if ( strtolower( $directive['tag'] ) !== $tag ) {
				continue;
			}

			if ( $directive['id'] && $id === $directive['id'] ) {
				return $directive;
			}

			if ( $directive['class'] && in_array( $directive['class'], $classes, true ) ) {
				return $directive;
			}
		}

		// 2. Dynamic fallback match for <aside class="..."> or <div class="callout callout-...">.
		if ( 'aside' === $tag && ! empty( $classes ) ) {
			$primary_class  = $classes[0];
			$ignore_classes = array( 'alignleft', 'alignright', 'aligncenter', 'alignnone', 'wp-block-quote' );
			if ( ! in_array( $primary_class, $ignore_classes, true ) ) {
				return self::get_directive_config( $primary_class );
			}
		}

		if ( 'div' === $tag && in_array( 'callout', $classes, true ) ) {
			foreach ( $classes as $c ) {
				if ( 0 === strpos( $c, 'callout-' ) ) {
					$name = substr( $c, 8 );
					return self::get_directive_config( $name );
				}
			}
		}

		return false;
	}

	/**
	 * Determines if a tag should be preserved as raw HTML.
	 */
	private static function should_preserve_element( $processor, $tag ) {
		$tag = strtolower( $tag );

		if ( 'img' === $tag ) {
			$class_attr = $processor->get_attribute( 'class' );
			$has_align  = $class_attr && preg_match( '/\b(alignleft|alignright|aligncenter|wp-image-\d+)\b/', $class_attr );
			$has_dims   = null !== $processor->get_attribute( 'width' ) || null !== $processor->get_attribute( 'height' );
			if ( $has_align || $has_dims ) {
				return true;
			}
			return false;
		}

		if ( 'figure' === $tag ) {
			$inner = $processor->get_inner_html();
			if ( false === $inner || false === strpos( strtolower( $inner ), '<figcaption' ) ) {
				return false;
			}
			return true;
		}

		if ( 'a' === $tag ) {
			$attr_names = $processor->get_attribute_names_with_prefix( 'on' );
			if ( ! empty( $attr_names ) ) {
				return true;
			}
		}

		if ( 'code' === $tag ) {
			$inner = $processor->get_inner_html();
			if ( false !== $inner && preg_match( '/<[a-z][a-z0-9]*/i', $inner ) ) {
				return true;
			}
			return false;
		}

		$default_preserved_tags = array( 'figure', 'figcaption', 'iframe', 'form', 'script', 'style', 'svg', 'canvas', 'video', 'audio', 'picture', 'source', 'track' );
		if ( function_exists( 'apply_filters' ) ) {
			$preserved_tags = apply_filters( 'push_md_preserved_html_tags', $default_preserved_tags, $tag );
		} else {
			$preserved_tags = $default_preserved_tags;
		}

		if ( in_array( $tag, $preserved_tags, true ) ) {
			return true;
		}

		if ( null !== $processor->get_attribute( 'id' ) ) {
			if ( ! preg_match( '/^h[1-6]$/i', $tag ) ) {
				return true;
			}
		}

		if ( null !== $processor->get_attribute( 'style' ) ) {
			return true;
		}

		$class_attr = $processor->get_attribute( 'class' );
		$classes    = $class_attr ? explode( ' ', trim( $class_attr ) ) : array();

		$default_preserved_classes = array( 'button', 'btn', 'cta', 'download_link' );
		if ( function_exists( 'apply_filters' ) ) {
			$preserved_classes = apply_filters( 'push_md_preserved_html_classes', $default_preserved_classes, $tag, $classes );
		} else {
			$preserved_classes = $default_preserved_classes;
		}

		$standard_wp_classes = array( 'alignleft', 'alignright', 'aligncenter', 'alignnone', 'wp-caption', 'wp-caption-text', 'gallery', 'gallery-caption' );
		$preserved_classes   = array_merge( $preserved_classes, $standard_wp_classes );

		if ( $class_attr ) {
			foreach ( $classes as $c ) {
				if ( in_array( $c, $preserved_classes, true ) ) {
					return true;
				}
				foreach ( $preserved_classes as $pc ) {
					if ( 0 === strpos( $c, $pc . '-' ) ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Pre-processes Markdown to normalize callout syntaxes and convert directives to raw HTML or Gutenberg blocks.
	 *
	 * @param string $markdown            Input Markdown string.
	 * @param bool   $use_block_comments  Whether to use Gutenberg block comments.
	 * @return string Processed Markdown / HTML.
	 */
	public static function process_markdown_to_html( $markdown, $use_block_comments ) {
		if ( empty( $markdown ) || ! is_string( $markdown ) ) {
			return (string) $markdown;
		}

		// Normalize GFM callouts (> [!type]) and code block admonitions (```ad-type) into :::type directives first.
		$markdown = self::normalize_callout_syntaxes( $markdown );

		if ( false === strpos( $markdown, ':::' ) ) {
			return $markdown;
		}

		$lines         = explode( "\n", $markdown );
		$output        = array();
		$in_code_block = false;
		$code_fence    = '';
		$stack         = array();

		foreach ( $lines as $line ) {
			if ( ! $in_code_block && preg_match( '/^(\`\`\`|~~~)/', ltrim( $line ), $matches ) ) {
				$in_code_block = true;
				$code_fence    = $matches[1];
				$output[]      = $line;
				continue;
			} elseif ( $in_code_block && 0 === strpos( ltrim( $line ), $code_fence ) ) {
				$in_code_block = false;
				$output[]      = $line;
				continue;
			}

			if ( ! $in_code_block && preg_match( '/^:::([a-zA-Z0-9_-]+)(?:[ \t]+(.*))?$/', trim( $line ), $matches ) ) {
				$name      = strtolower( $matches[1] );
				$args      = isset( $matches[2] ) ? self::parse_args( $matches[2] ) : array();
				$directive = self::get_directive_config( $name );

				$stack[] = array(
					'directive' => $directive,
					'args'      => $args,
					'inner'     => array(),
				);
				continue;
			}

			if ( ! $in_code_block && preg_match( '/^:::$/', trim( $line ) ) ) {
				if ( ! empty( $stack ) ) {
					$current  = array_pop( $stack );
					$inner_md = implode( "\n", $current['inner'] );

					// Convert inner markdown recursively.
					if ( class_exists( 'Push_MD_Markdown_Consumer' ) ) {
						$consumer     = new Push_MD_Markdown_Consumer( $inner_md, $use_block_comments );
						$inner_blocks = $consumer->consume();
						$inner_html   = $inner_blocks ? $inner_blocks->get_block_markup() : '';
					} else {
						$inner_html = $inner_md;
					}

					$directive = $current['directive'];

					if ( $directive['block'] ) {
						// Pattern 2: Gutenberg Block.
						$block_name = $directive['block'];
						$attrs_json = empty( $current['args'] ) ? '{}' : json_encode( $current['args'] );
						$html       = '';
						if ( $use_block_comments ) {
							$html .= '<!-- wp:' . $block_name . ' ' . $attrs_json . ' -->' . "\n";
						}
						$class = 'wp-block-' . str_replace( '/', '-', $block_name );
						$html .= '<div class="' . $class . '">' . "\n" . trim( $inner_html ) . "\n" . '</div>' . "\n";
						if ( $use_block_comments ) {
							$html .= '<!-- /wp:' . $block_name . ' -->';
						}
					} else {
						// Pattern 1: HTML Tag.
						$tag      = $directive['tag'];
						$attr_str = 'class="' . htmlspecialchars( $directive['class'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . '"';

						$title_html = '';
						if ( ! empty( $current['args']['title'] ) && ! empty( $directive['title_element'] ) ) {
							$te         = self::parse_title_element( $directive['title_element'] );
							$cls_str    = $te['class'] ? ' class="' . htmlspecialchars( $te['class'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . '"' : '';
							$title_html = '<' . $te['tag'] . $cls_str . '>'
								. htmlspecialchars( $current['args']['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8' )
								. '</' . $te['tag'] . '>' . "\n";
						}

						foreach ( $current['args'] as $k => $v ) {
							if ( 'title' === $k && ! empty( $directive['title_element'] ) ) {
								// Handled via title_element.
								continue;
							}
							if ( 'class' === $k ) {
								$attr_str = 'class="' . htmlspecialchars( $directive['class'] . ' ' . $v, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . '"';
							} else {
								$attr_str .= ' ' . htmlspecialchars( $k, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . '="' . htmlspecialchars( $v, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . '"';
							}
						}

						$html = '<' . $tag . ' ' . $attr_str . '>' . "\n" . $title_html . trim( $inner_html ) . "\n" . '</' . $tag . '>';
					}

					if ( empty( $stack ) ) {
						$output[] = $html;
					} else {
						$stack[ count( $stack ) - 1 ]['inner'][] = $html;
					}
					continue;
				}
			}

			if ( empty( $stack ) ) {
				$output[] = $line;
			} else {
				$stack[ count( $stack ) - 1 ]['inner'][] = $line;
			}
		}

		return implode( "\n", $output );
	}

	/**
	 * Normalizes GFM callouts (`> [!type] Title`) and code block admonitions (```ad-type) to `:::type` directives.
	 *
	 * @param string $markdown Input Markdown string.
	 * @return string Normalized Markdown string.
	 */
	public static function normalize_callout_syntaxes( $markdown ) {
		if ( empty( $markdown ) || ! is_string( $markdown ) ) {
			return (string) $markdown;
		}

		if ( false === strpos( $markdown, '[!' ) && false === strpos( $markdown, 'ad-' ) ) {
			return $markdown;
		}

		$markdown = self::normalize_gfm_callouts( $markdown );
		$markdown = self::normalize_ad_blocks( $markdown );

		return $markdown;
	}

	/**
	 * Normalizes GFM callout blocks (`> [!type] Title`) into `:::type title="..."` directives.
	 */
	private static function normalize_gfm_callouts( $markdown ) {
		if ( empty( $markdown ) || ! is_string( $markdown ) || false === strpos( $markdown, '[!' ) ) {
			return $markdown;
		}

		$lines  = explode( "\n", $markdown );
		$output = array();
		$count  = count( $lines );
		$i      = 0;

		while ( $i < $count ) {
			$line = $lines[ $i ];

			if ( preg_match( '/^>\s*\[!([a-zA-Z0-9_-]+)\](?:\s+(.*))?$/i', trim( $line ), $m ) ) {
				$type        = strtolower( $m[1] );
				$title       = isset( $m[2] ) ? trim( $m[2] ) : '';
				$inner_lines = array();
				++$i;

				// Collect contiguous lines prefixed with >.
				while ( $i < $count ) {
					$curr = $lines[ $i ];
					if ( preg_match( '/^>(?:[ \t](.*)|$)/', $curr, $lm ) ) {
						$inner_lines[] = isset( $lm[1] ) ? $lm[1] : '';
						++$i;
					} else {
						break;
					}
				}

				$title_attr = ( '' !== $title ) ? ' title="' . htmlspecialchars( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . '"' : '';
				$output[]   = ':::' . $type . $title_attr;
				foreach ( $inner_lines as $il ) {
					$output[] = $il;
				}
				$output[] = ':::';
			} else {
				$output[] = $line;
				++$i;
			}
		}

		return implode( "\n", $output );
	}

	/**
	 * Normalizes code block admonitions (```ad-type) into `:::type` directives.
	 */
	private static function normalize_ad_blocks( $markdown ) {
		if ( false === strpos( $markdown, 'ad-' ) ) {
			return $markdown;
		}

		$lines  = explode( "\n", $markdown );
		$output = array();
		$count  = count( $lines );
		$i      = 0;

		while ( $i < $count ) {
			$line = $lines[ $i ];

			if ( preg_match( '/^(\`\`\`|~~~)\s*ad-([a-zA-Z0-9_-]+)\s*$/i', trim( $line ), $m ) ) {
				$fence       = $m[1];
				$type        = strtolower( $m[2] );
				$inner_lines = array();
				$closed      = false;
				++$i;

				while ( $i < $count ) {
					$curr = $lines[ $i ];
					if ( 0 === strpos( ltrim( $curr ), $fence ) ) {
						$closed = true;
						++$i;
						break;
					}
					$inner_lines[] = $curr;
					++$i;
				}

				if ( $closed ) {
					$title = '';
					// Check if first non-empty line contains title: Title.
					foreach ( $inner_lines as $idx => $il ) {
						$trimmed_il = trim( $il );
						if ( '' === $trimmed_il ) {
							continue;
						}
						if ( preg_match( '/^title:\s*(.+)$/i', $trimmed_il, $tm ) ) {
							$title = trim( $tm[1] );
							unset( $inner_lines[ $idx ] );
						}
						break;
					}

					$title_attr = ( '' !== $title ) ? ' title="' . htmlspecialchars( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . '"' : '';
					$output[]   = ':::' . $type . $title_attr;
					foreach ( $inner_lines as $il ) {
						$output[] = $il;
					}
					$output[] = ':::';
				} else {
					// Unclosed fence: output as-is.
					$output[] = $line;
					foreach ( $inner_lines as $il ) {
						$output[] = $il;
					}
				}
			} else {
				$output[] = $line;
				++$i;
			}
		}

		return implode( "\n", $output );
	}

	/**
	 * Parses a CSS-like selector shorthand ('h2', 'p.callout-title') into tag and class components.
	 */
	public static function parse_title_element( $selector ) {
		$parts = explode( '.', trim( $selector ), 2 );
		return array(
			'tag'   => strtolower( $parts[0] ),
			'class' => isset( $parts[1] ) ? $parts[1] : '',
		);
	}

	/**
	 * Post-processes Gutenberg export to convert unknown blocks serialized as fences back to :::.
	 */
	public static function process_gutenberg_to_markdown( $markdown ) {
		if ( empty( $markdown ) || ! is_string( $markdown ) ) {
			return (string) $markdown;
		}

		$pattern = '/```gutenberg\r?\n<!-- wp:([a-zA-Z0-9_-]+\/[a-zA-Z0-9_-]+)(?: (.*?))? -->\r?\n(.*?)\r?\n<!-- \/wp:\1 -->\r?\n```/ms';

		return preg_replace_callback(
			$pattern,
			function ( $matches ) {
				$block_name = $matches[1];
				$attrs_json = $matches[2] ? trim( $matches[2] ) : '{}';
				$inner_html = $matches[3];

				$directive = null;
				foreach ( self::$registry as $reg ) {
					if ( $reg['block'] === $block_name ) {
						$directive = $reg;
						break;
					}
				}

				if ( ! $directive ) {
					return $matches[0];
				}

				$args = json_decode( $attrs_json, true );
				if ( ! is_array( $args ) ) {
					$args = array();
				}

				$args_str = empty( $args ) ? '' : ' ' . self::format_args( $args );

				$inner_html = preg_replace( '/^<div class="wp-block-[^>]+>\s*(.*?)\s*<\/div>$/is', '$1', $inner_html );
				$inner_md   = Push_MD_HTML_Converter::convert( $inner_html );

				return ':::' . $directive['name'] . $args_str . "\n" . trim( $inner_md ) . "\n:::";
			},
			$markdown
		);
	}

	public static function parse_args( $arg_string ) {
		$args = array();
		if ( preg_match_all( '/([a-zA-Z0-9_-]+)="([^"]*)"/', $arg_string, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$args[ $match[1] ] = $match[2];
			}
		}
		return $args;
	}

	public static function format_args( $args ) {
		$str = '';
		foreach ( $args as $k => $v ) {
			$str .= $k . '="' . htmlspecialchars( $v, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . '" ';
		}
		return trim( $str );
	}

	/**
	 * Pre-processes HTML content before Markdown conversion to convert registered HTML directives (Pattern 1/3) into ::: directive syntax.
	 *
	 * @param string $content HTML content or post markup.
	 * @return string Processed content.
	 */
	public static function process_html_directives_in_content( $content ) {
		if ( empty( $content ) || ! is_string( $content ) || empty( self::$registry ) || false === strpos( $content, '<' ) ) {
			return $content;
		}

		foreach ( self::$registry as $directive ) {
			if ( $directive['block'] ) {
				continue;
			}

			$tag   = preg_quote( $directive['tag'], '#' );
			$class = preg_quote( $directive['class'], '#' );

			$pattern = '#<' . $tag . '\b[^>]*class="[^"]*\b' . $class . '\b[^"]*"[^>]*>(.*?)</' . $tag . '>#is';

			if ( preg_match( $pattern, $content ) ) {
				$content = preg_replace_callback(
					$pattern,
					function ( $matches ) {
						return Push_MD_HTML_Converter::convert( $matches[0] );
					},
					$content
				);
			}
		}

		return $content;
	}
}
