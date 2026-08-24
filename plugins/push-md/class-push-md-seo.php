<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SEO & OpenGraph Frontmatter Handler for Push MD.
 *
 * Supports seo_title, seo_description, seo_keywords, og_title, og_description,
 * og_image, canonical, and schema_type mapped to Rank Math and Yoast SEO plugins.
 */
class Push_MD_SEO {

	/**
	 * Whitelisted SEO & OpenGraph frontmatter keys.
	 *
	 * @var array
	 */
	public static $supported_keys = array(
		'seo_title',
		'seo_description',
		'seo_keywords',
		'og_title',
		'og_description',
		'og_image',
		'canonical',
		'schema_type',
		'seo_is_pillar',
	);

	/**
	 * Map of frontmatter key => internal field slug for simple text fields.
	 *
	 * @var array
	 */
	private static $simple_field_map = array(
		'seo_title'       => 'title',
		'seo_description' => 'description',
		'og_title'        => 'og_title',
		'og_description'  => 'og_description',
		'canonical'       => 'canonical',
	);

	/**
	 * Map of internal field slug => Rank Math and Yoast meta keys.
	 *
	 * @var array
	 */
	private static $field_meta_keys = array(
		'title'          => array(
			'rm'    => 'rank_math_title',
			'yoast' => '_yoast_wpseo_title',
		),
		'description'    => array(
			'rm'    => 'rank_math_description',
			'yoast' => '_yoast_wpseo_metadesc',
		),
		'og_title'       => array(
			'rm'    => 'rank_math_facebook_title',
			'yoast' => '_yoast_wpseo_opengraph-title',
		),
		'og_description' => array(
			'rm'    => 'rank_math_facebook_description',
			'yoast' => '_yoast_wpseo_opengraph-description',
		),
		'canonical'      => array(
			'rm'    => 'rank_math_canonical_url',
			'yoast' => '_yoast_wpseo_canonical',
		),
	);

	/**
	 * Register hooks for SEO frontmatter handling.
	 */
	public static function bootstrap() {
		add_filter( 'push_md_supported_frontmatter_keys', array( __CLASS__, 'add_supported_frontmatter_keys' ) );
		add_filter( 'push_md_export_frontmatter', array( __CLASS__, 'export_frontmatter' ), 10, 2 );
		add_action( 'push_md_import_frontmatter', array( __CLASS__, 'import_frontmatter' ), 10, 4 );
	}

	/**
	 * Check if Yoast SEO is active.
	 *
	 * @return bool
	 */
	public static function is_yoast_seo_active() {
		return defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' ) || defined( 'WPSEO_FILE' );
	}

	/**
	 * Check if Rank Math is active.
	 *
	 * @return bool
	 */
	public static function is_rank_math_active() {
		return defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' );
	}

	/**
	 * Register SEO & OG keys into Push MD's supported frontmatter key registry.
	 *
	 * @param array $keys Existing keys array.
	 * @return array Updated keys array.
	 */
	public static function add_supported_frontmatter_keys( $keys ) {
		if ( ! is_array( $keys ) ) {
			return self::$supported_keys;
		}

		return array_values( array_unique( array_merge( $keys, self::$supported_keys ) ) );
	}

	/**
	 * Export post SEO and OpenGraph metadata into Markdown frontmatter array.
	 *
	 * @param array   $metadata Frontmatter metadata array.
	 * @param WP_Post $post     WordPress post object.
	 * @return array Exported metadata array.
	 */
	public static function export_frontmatter( $metadata, $post ) {
		if ( ! is_array( $metadata ) || ! is_object( $post ) || empty( $post->ID ) ) {
			return $metadata;
		}

		$post_id = (int) $post->ID;

		// 1. Simple text fields.
		foreach ( self::$simple_field_map as $fm_key => $field_slug ) {
			$val = trim( (string) self::get_post_seo_field_meta( $post_id, $field_slug ) );
			if ( '' !== $val ) {
				$metadata[ $fm_key ] = array( $val );
			}
		}

		// 2. Focus Keywords.
		$keywords = self::get_post_seo_keywords( $post_id );
		if ( ! empty( $keywords ) ) {
			$metadata['seo_keywords'] = $keywords;
		}

		// 3. OpenGraph Image.
		$og_image = self::get_post_og_image_meta( $post_id );
		if ( '' !== $og_image ) {
			$metadata['og_image'] = array( $og_image );
		}

		// 4. Schema Type.
		$schema_type = self::get_post_schema_type_meta( $post_id, $post->post_type );
		if ( '' !== $schema_type ) {
			$metadata['schema_type'] = array( $schema_type );
		}

		// 5. SEO Pillar.
		if ( self::get_post_is_pillar( $post_id ) ) {
			$metadata['seo_is_pillar'] = array( 'true' );
		}

		return $metadata;
	}

	/**
	 * Import SEO and OpenGraph metadata from Markdown frontmatter into WordPress post meta.
	 *
	 * @param int     $post_id       WordPress post ID.
	 * @param array   $metadata      Parsed Markdown frontmatter metadata.
	 * @param array   $postarr       Sanitized post array passed to wp_insert_post/wp_update_post.
	 * @param WP_Post $existing_post Existing post object if updating.
	 */
	public static function import_frontmatter( $post_id, $metadata, $postarr = array(), $existing_post = null ) {
		unset( $postarr, $existing_post );

		$post_id = intval( $post_id );
		if ( ! is_array( $metadata ) || $post_id <= 0 ) {
			return;
		}

		// 1. Simple text fields.
		foreach ( self::$simple_field_map as $fm_key => $field_slug ) {
			if ( isset( $metadata[ $fm_key ] ) ) {
				self::update_post_seo_field_meta( $post_id, $field_slug, $metadata[ $fm_key ] );
			}
		}

		// 2. Specialized fields.
		if ( isset( $metadata['seo_keywords'] ) ) {
			self::update_post_seo_keywords( $post_id, $metadata['seo_keywords'] );
		}

		if ( isset( $metadata['og_image'] ) ) {
			self::update_post_og_image_meta( $post_id, $metadata['og_image'] );
		}

		if ( isset( $metadata['schema_type'] ) ) {
			$post_type = function_exists( 'get_post_type' ) ? get_post_type( $post_id ) : 'post';
			self::update_post_schema_type_meta( $post_id, $metadata['schema_type'], $post_type ? $post_type : 'post' );
		}

		if ( isset( $metadata['seo_is_pillar'] ) ) {
			self::update_post_is_pillar( $post_id, $metadata['seo_is_pillar'] );
		}
	}

	/**
	 * Backward compatibility alias for export_frontmatter.
	 *
	 * @param array   $metadata Frontmatter metadata array.
	 * @param WP_Post $post     WordPress post object.
	 * @return array Exported metadata array.
	 */
	public static function handle_seo_export_frontmatter( $metadata, $post ) {
		return self::export_frontmatter( $metadata, $post );
	}

	/**
	 * Backward compatibility alias for import_frontmatter.
	 *
	 * @param int     $post_id       WordPress post ID.
	 * @param array   $metadata      Parsed Markdown frontmatter metadata.
	 * @param array   $postarr       Sanitized post array.
	 * @param WP_Post $existing_post Existing post object.
	 */
	public static function handle_seo_import_frontmatter( $post_id, $metadata, $postarr = array(), $existing_post = null ) {
		self::import_frontmatter( $post_id, $metadata, $postarr, $existing_post );
	}

	/**
	 * Check if post meta key exists for a given post.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 * @return bool True if meta exists.
	 */
	private static function seo_meta_exists( $post_id, $meta_key ) {
		$post_id = intval( $post_id );
		if ( $post_id <= 0 ) {
			return false;
		}
		if ( isset( $GLOBALS['mock_post_meta'] ) && is_array( $GLOBALS['mock_post_meta'] ) ) {
			return isset( $GLOBALS['mock_post_meta'][ $post_id ][ $meta_key ] );
		}
		if ( function_exists( 'metadata_exists' ) && metadata_exists( 'post', $post_id, $meta_key ) ) {
			return true;
		}
		if ( function_exists( 'get_post_meta' ) ) {
			$val = get_post_meta( $post_id, $meta_key, true );
			return '' !== $val && false !== $val && null !== $val;
		}
		return false;
	}

	/**
	 * Determine whether to update Rank Math and/or Yoast SEO for a given pair of meta keys.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $rm_key    Rank Math meta key.
	 * @param string $yoast_key Yoast SEO meta key.
	 * @return array Array with boolean keys 'rm' and 'yoast'.
	 */
	private static function should_update_plugin_meta( $post_id, $rm_key, $yoast_key ) {
		$yoast_active   = self::is_yoast_seo_active();
		$rm_active      = self::is_rank_math_active();
		$has_rm_meta    = ! empty( $rm_key ) ? self::seo_meta_exists( $post_id, $rm_key ) : false;
		$has_yoast_meta = ! empty( $yoast_key ) ? self::seo_meta_exists( $post_id, $yoast_key ) : false;

		return array(
			'rm'    => $rm_active || $has_rm_meta || ( ! $yoast_active && ! $has_yoast_meta ),
			'yoast' => $yoast_active || $has_yoast_meta || ( ! $rm_active && ! $has_rm_meta ),
		);
	}

	/**
	 * Get generic SEO or OpenGraph field string value from Rank Math or Yoast SEO.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $field   Field name ('title', 'description', 'og_title', 'og_description', 'canonical').
	 * @return string Meta string value or empty string.
	 */
	private static function get_post_seo_field_meta( $post_id, $field ) {
		$post_id = intval( $post_id );
		if ( $post_id <= 0 || ! function_exists( 'get_post_meta' ) ) {
			return '';
		}

		$keys = self::get_meta_keys_for_field( $field );
		if ( empty( $keys['rm'] ) ) {
			return '';
		}

		$rm_val = get_post_meta( $post_id, $keys['rm'], true );
		if ( '' !== trim( (string) $rm_val ) ) {
			return trim( (string) $rm_val );
		}

		$yoast_val = get_post_meta( $post_id, $keys['yoast'], true );
		if ( '' !== trim( (string) $yoast_val ) ) {
			return trim( (string) $yoast_val );
		}

		return '';
	}

	/**
	 * Update generic SEO or OpenGraph field string value in Rank Math and/or Yoast SEO.
	 *
	 * @param int          $post_id Post ID.
	 * @param string       $field   Field name.
	 * @param string|array $val     Value from frontmatter.
	 */
	private static function update_post_seo_field_meta( $post_id, $field, $val ) {
		$post_id = intval( $post_id );
		if ( $post_id <= 0 || ! function_exists( 'update_post_meta' ) ) {
			return;
		}

		$val  = is_array( $val ) ? reset( $val ) : $val;
		$val  = trim( (string) $val );
		$keys = self::get_meta_keys_for_field( $field );
		if ( empty( $keys['rm'] ) ) {
			return;
		}

		$targets = self::should_update_plugin_meta( $post_id, $keys['rm'], $keys['yoast'] );

		if ( $targets['rm'] ) {
			update_post_meta( $post_id, $keys['rm'], $val );
		}
		if ( $targets['yoast'] ) {
			update_post_meta( $post_id, $keys['yoast'], $val );
		}
	}

	/**
	 * Get Rank Math and Yoast meta keys for a field.
	 *
	 * @param string $field Field slug.
	 * @return array Array with 'rm' and 'yoast' meta keys.
	 */
	private static function get_meta_keys_for_field( $field ) {
		return isset( self::$field_meta_keys[ $field ] ) ? self::$field_meta_keys[ $field ] : array(
			'rm'    => '',
			'yoast' => '',
		);
	}

	/**
	 * Get OpenGraph image URL from Rank Math or Yoast SEO.
	 *
	 * @param int $post_id Post ID.
	 * @return string Image URL string or empty string.
	 */
	private static function get_post_og_image_meta( $post_id ) {
		$post_id = intval( $post_id );
		if ( $post_id <= 0 || ! function_exists( 'get_post_meta' ) ) {
			return '';
		}

		$rm_val = get_post_meta( $post_id, 'rank_math_facebook_image', true );
		if ( '' !== trim( (string) $rm_val ) ) {
			return trim( (string) $rm_val );
		}

		$yoast_val = get_post_meta( $post_id, '_yoast_wpseo_opengraph-image', true );
		if ( '' !== trim( (string) $yoast_val ) ) {
			return trim( (string) $yoast_val );
		}

		return '';
	}

	/**
	 * Update OpenGraph image URL & Attachment ID in Rank Math and Yoast SEO.
	 * Resolves relative media paths (media/cover.png, ../media/cover.png) to uploaded URLs.
	 *
	 * @param int          $post_id Post ID.
	 * @param string|array $val     Image path or URL from frontmatter.
	 */
	private static function update_post_og_image_meta( $post_id, $val ) {
		$post_id = intval( $post_id );
		if ( $post_id <= 0 || ! function_exists( 'update_post_meta' ) ) {
			return;
		}

		$val = is_array( $val ) ? reset( $val ) : $val;
		$val = trim( (string) $val );
		if ( '' === $val ) {
			return;
		}

		$image_url     = $val;
		$attachment_id = 0;

		// 1. Check if numeric attachment ID passed.
		if ( is_numeric( $val ) ) {
			$attachment_id = (int) $val;
			if ( function_exists( 'wp_get_attachment_url' ) ) {
				$fetched_url = wp_get_attachment_url( $attachment_id );
				if ( $fetched_url ) {
					$image_url = $fetched_url;
				}
			}
		} elseif ( class_exists( 'Push_MD_Media' ) ) {
			// 2. Resolve relative media path or URL via Push_MD_Media.
			$info = Push_MD_Media::resolve_media_url_info( $val, $post_id );
			if ( $info && ! empty( $info['url'] ) ) {
				$image_url = $info['url'];
				if ( ! empty( $info['id'] ) ) {
					$attachment_id = (int) $info['id'];
				}
			} else {
				// Fallback lookup by filename if attachment exists.
				$fn            = basename( $val );
				$attachment_id = Push_MD_Media::find_existing_attachment_id_by_filename( $fn );
				$existing_url  = Push_MD_Media::find_existing_attachment_url_by_filename( $fn );
				if ( $existing_url ) {
					$image_url = $existing_url;
				}
			}
		}

		// Fallback attachment ID resolution via core function if still 0.
		if ( 0 === $attachment_id && ! empty( $image_url ) && is_string( $image_url ) && function_exists( 'attachment_url_to_postid' ) && ( 0 === strpos( $image_url, 'http://' ) || 0 === strpos( $image_url, 'https://' ) ) ) {
			$found_id = attachment_url_to_postid( $image_url );
			if ( $found_id > 0 ) {
				$attachment_id = (int) $found_id;
			}
		}

		$targets = self::should_update_plugin_meta( $post_id, 'rank_math_facebook_image', '_yoast_wpseo_opengraph-image' );

		if ( $targets['rm'] ) {
			update_post_meta( $post_id, 'rank_math_facebook_image', $image_url );
			if ( $attachment_id > 0 ) {
				update_post_meta( $post_id, 'rank_math_facebook_image_id', $attachment_id );
			}
		}

		if ( $targets['yoast'] ) {
			update_post_meta( $post_id, '_yoast_wpseo_opengraph-image', $image_url );
			if ( $attachment_id > 0 ) {
				update_post_meta( $post_id, '_yoast_wpseo_opengraph-image-id', $attachment_id );
			}
		}
	}

	/**
	 * Get schema type string from Rank Math or Yoast SEO.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $post_type Post type slug.
	 * @return string Schema type string or empty string.
	 */
	private static function get_post_schema_type_meta( $post_id, $post_type = 'post' ) {
		$post_id = intval( $post_id );
		if ( $post_id <= 0 || ! function_exists( 'get_post_meta' ) ) {
			return '';
		}

		$rm_val = get_post_meta( $post_id, 'rank_math_rich_snippet', true );
		if ( '' !== trim( (string) $rm_val ) ) {
			return trim( (string) $rm_val );
		}

		$yoast_key = 'page' === $post_type ? '_yoast_wpseo_schema_page_type' : '_yoast_wpseo_schema_article_type';
		$yoast_val = get_post_meta( $post_id, $yoast_key, true );
		if ( '' !== trim( (string) $yoast_val ) ) {
			return trim( (string) $yoast_val );
		}

		return '';
	}

	/**
	 * Update schema type string in Rank Math and Yoast SEO.
	 *
	 * @param int          $post_id   Post ID.
	 * @param string|array $val       Schema type string e.g. "Article", "BlogPosting", "NewsArticle", "Product".
	 * @param string       $post_type Post type slug.
	 */
	private static function update_post_schema_type_meta( $post_id, $val, $post_type = 'post' ) {
		$post_id = intval( $post_id );
		if ( $post_id <= 0 || ! function_exists( 'update_post_meta' ) ) {
			return;
		}

		$val = is_array( $val ) ? reset( $val ) : $val;
		$val = trim( (string) $val );
		if ( '' === $val ) {
			return;
		}

		$rm_schema    = strtolower( $val );
		$yoast_schema = self::normalize_yoast_schema_type( $val );
		$yoast_key    = 'page' === $post_type ? '_yoast_wpseo_schema_page_type' : '_yoast_wpseo_schema_article_type';

		$targets = self::should_update_plugin_meta( $post_id, 'rank_math_rich_snippet', $yoast_key );

		if ( $targets['rm'] ) {
			update_post_meta( $post_id, 'rank_math_rich_snippet', $rm_schema );
		}
		if ( $targets['yoast'] ) {
			update_post_meta( $post_id, $yoast_key, $yoast_schema );
		}
	}

	/**
	 * Normalize schema type string for Yoast SEO enum conventions.
	 *
	 * @param string $val Schema string.
	 * @return string Normalized Yoast schema type string.
	 */
	private static function normalize_yoast_schema_type( $val ) {
		$lower = strtolower( trim( (string) $val ) );
		switch ( $lower ) {
			case 'article':
				return 'Article';
			case 'blogposting':
			case 'blog_posting':
			case 'blog':
				return 'BlogPosting';
			case 'newsarticle':
			case 'news_article':
			case 'news':
				return 'NewsArticle';
			case 'techarticle':
			case 'tech_article':
				return 'TechArticle';
			case 'webpage':
			case 'web_page':
			case 'page':
				return 'WebPage';
			case 'itempage':
			case 'item_page':
				return 'ItemPage';
			case 'aboutpage':
			case 'about_page':
				return 'AboutPage';
			case 'contactpage':
			case 'contact_page':
				return 'ContactPage';
			case 'faqpage':
			case 'faq_page':
				return 'FAQPage';
			case 'qapage':
			case 'qa_page':
				return 'QAPage';
			case 'profilepage':
			case 'profile_page':
				return 'ProfilePage';
			default:
				return ucfirst( trim( (string) $val ) );
		}
	}

	/**
	 * Get focus keywords array from Rank Math or Yoast SEO.
	 *
	 * @param int $post_id Post ID.
	 * @return array Array of keywords strings.
	 */
	private static function get_post_seo_keywords( $post_id ) {
		$post_id = intval( $post_id );
		if ( $post_id <= 0 || ! function_exists( 'get_post_meta' ) ) {
			return array();
		}

		$keywords = array();

		$rm_val = get_post_meta( $post_id, 'rank_math_focus_keyword', true );
		if ( '' !== trim( (string) $rm_val ) ) {
			$split = explode( ',', (string) $rm_val );
			foreach ( $split as $kw ) {
				$kw = trim( $kw );
				if ( '' !== $kw && ! in_array( $kw, $keywords, true ) ) {
					$keywords[] = $kw;
				}
			}
			if ( ! empty( $keywords ) ) {
				return $keywords;
			}
		}

		$primary = get_post_meta( $post_id, '_yoast_wpseo_focuskw', true );
		if ( '' !== trim( (string) $primary ) ) {
			$keywords[] = trim( (string) $primary );
		}
		$additional_json = get_post_meta( $post_id, '_yoast_wpseo_focuskeywords', true );
		if ( ! empty( $additional_json ) && is_string( $additional_json ) ) {
			$decoded = json_decode( $additional_json, true );
			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $item ) {
					if ( is_array( $item ) && ! empty( $item['keyword'] ) ) {
						$kw = trim( (string) $item['keyword'] );
						if ( '' !== $kw && ! in_array( $kw, $keywords, true ) ) {
							$keywords[] = $kw;
						}
					}
				}
			}
		}

		return $keywords;
	}

	/**
	 * Update focus keywords in Rank Math and/or Yoast SEO.
	 *
	 * @param int          $post_id Post ID.
	 * @param string|array $val     Keywords input from frontmatter.
	 */
	private static function update_post_seo_keywords( $post_id, $val ) {
		$post_id = intval( $post_id );
		if ( $post_id <= 0 || ! function_exists( 'update_post_meta' ) ) {
			return;
		}

		$keywords       = self::parse_seo_keywords_input( $val );
		$primary_kw     = ! empty( $keywords ) ? $keywords[0] : '';
		$additional_kws = count( $keywords ) > 1 ? array_slice( $keywords, 1 ) : array();

		$targets = self::should_update_plugin_meta( $post_id, 'rank_math_focus_keyword', '_yoast_wpseo_focuskw' );

		if ( $targets['rm'] ) {
			$rm_str = implode( ', ', $keywords );
			update_post_meta( $post_id, 'rank_math_focus_keyword', $rm_str );
		}

		if ( $targets['yoast'] ) {
			update_post_meta( $post_id, '_yoast_wpseo_focuskw', $primary_kw );

			$existing_scores = array();
			$existing_json   = get_post_meta( $post_id, '_yoast_wpseo_focuskeywords', true );
			if ( ! empty( $existing_json ) && is_string( $existing_json ) ) {
				$decoded = json_decode( $existing_json, true );
				if ( is_array( $decoded ) ) {
					foreach ( $decoded as $item ) {
						if ( is_array( $item ) && isset( $item['keyword'], $item['score'] ) ) {
							$existing_scores[ (string) $item['keyword'] ] = (string) $item['score'];
						}
					}
				}
			}

			$yoast_additional = array();
			foreach ( $additional_kws as $kw ) {
				$score              = isset( $existing_scores[ $kw ] ) ? $existing_scores[ $kw ] : 'ok';
				$yoast_additional[] = array(
					'keyword' => $kw,
					'score'   => $score,
				);
			}

			$json_val = ! empty( $yoast_additional ) ? ( function_exists( 'wp_json_encode' ) ? wp_json_encode( $yoast_additional, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : json_encode( $yoast_additional, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) : '[]';
			update_post_meta( $post_id, '_yoast_wpseo_focuskeywords', $json_val );
		}
	}

	/**
	 * Parse focus keywords input string or array.
	 *
	 * @param string|array $input Frontmatter value.
	 * @return array Unique non-empty keyword strings.
	 */
	private static function parse_seo_keywords_input( $input ) {
		$keywords = array();
		if ( is_string( $input ) ) {
			$input = array( $input );
		}
		if ( is_array( $input ) ) {
			foreach ( $input as $item ) {
				$item_str = (string) $item;
				$split    = explode( ',', $item_str );
				foreach ( $split as $kw ) {
					$kw = trim( $kw );
					if ( '' !== $kw && ! in_array( $kw, $keywords, true ) ) {
						$keywords[] = $kw;
					}
				}
			}
		}

		return $keywords;
	}

	/**
	 * Get pillar content status boolean from canonical meta or active SEO plugins.
	 *
	 * @param int $post_id Post ID.
	 * @return bool True if post is pillar content.
	 */
	public static function get_post_is_pillar( $post_id ) {
		$post_id = intval( $post_id );
		if ( $post_id <= 0 || ! function_exists( 'get_post_meta' ) ) {
			return false;
		}

		$canonical = get_post_meta( $post_id, '_pushmd_seo_is_pillar', true );
		if ( '' !== trim( (string) $canonical ) ) {
			$lower = strtolower( trim( (string) $canonical ) );
			if ( '1' === $lower || 'true' === $lower || 'yes' === $lower || 'on' === $lower ) {
				return true;
			}
			if ( '0' === $lower || 'false' === $lower || 'no' === $lower || 'off' === $lower ) {
				return false;
			}
		}

		$rm_val = get_post_meta( $post_id, 'rank_math_pillar_content', true );
		if ( 'on' === strtolower( trim( (string) $rm_val ) ) ) {
			return true;
		}

		$yoast_val = get_post_meta( $post_id, '_yoast_wpseo_is_cornerstone', true );
		if ( '1' === (string) $yoast_val || true === $yoast_val || 'true' === strtolower( trim( (string) $yoast_val ) ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Update pillar content status in canonical meta, Rank Math, and Yoast SEO.
	 *
	 * @param int               $post_id Post ID.
	 * @param bool|string|array $val     Value from frontmatter.
	 */
	public static function update_post_is_pillar( $post_id, $val ) {
		$post_id = intval( $post_id );
		if ( $post_id <= 0 || ! function_exists( 'update_post_meta' ) ) {
			return;
		}

		$raw       = is_array( $val ) ? reset( $val ) : $val;
		$lower     = strtolower( trim( (string) $raw ) );
		$is_pillar = true === $raw || 1 === $raw || '1' === $lower || 'true' === $lower || 'yes' === $lower || 'on' === $lower;

		update_post_meta( $post_id, '_pushmd_seo_is_pillar', $is_pillar ? '1' : '0' );

		$targets = self::should_update_plugin_meta( $post_id, 'rank_math_pillar_content', '_yoast_wpseo_is_cornerstone' );

		if ( $targets['rm'] ) {
			update_post_meta( $post_id, 'rank_math_pillar_content', $is_pillar ? 'on' : 'off' );
		}

		if ( $targets['yoast'] ) {
			update_post_meta( $post_id, '_yoast_wpseo_is_cornerstone', $is_pillar ? '1' : '0' );
		}
	}
}
