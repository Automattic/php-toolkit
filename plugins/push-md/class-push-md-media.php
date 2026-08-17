<?php

use WordPress\Git\Model\TreeEntry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Media Handler for Push MD – handles validation, uploading, inline image path
 * rewriting, featured image assignment, and git pull media export.
 */
class Push_MD_Media {

	/**
	 * Whitelisted image file extensions.
	 *
	 * @var array
	 */
	public static $allowed_extensions = array( 'png', 'jpg', 'jpeg', 'gif', 'webp' );

	/**
	 * Whitelisted image MIME types.
	 *
	 * @var array
	 */
	public static $allowed_mime_types = array(
		'image/png',
		'image/jpeg',
		'image/gif',
		'image/webp',
	);

	/**
	 * Check if a repository path is inside the media directory.
	 *
	 * @param string $path Repository file path.
	 * @return bool True if under media/ directory.
	 */
	public static function is_media_path( $path ) {
		$clean = self::normalize_relative_media_path( $path );
		return '' !== $clean;
	}

	/**
	 * Safe esc_html wrapper for test environments without WordPress functions loaded.
	 *
	 * @param string $text Input text.
	 * @return string Escaped string.
	 */
	private static function safe_esc( $text ) {
		return function_exists( 'esc_html' ) ? esc_html( (string) $text ) : htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Validate a media file path, extension, and binary content (fail-closed).
	 *
	 * @param string $path         Repository path (must be under media/).
	 * @param string $binary_data Raw binary file content.
	 * @throws Exception If path, extension, MIME type, or binary is invalid.
	 */
	public static function validate_media_file( $path, $binary_data ) {
		$clean_path = self::normalize_relative_media_path( $path );
		if ( '' === $clean_path || ! self::is_media_path( $clean_path ) ) {
			throw new Exception( 'Push rejected because media files must be placed within the media/ directory: ' . self::safe_esc( $path ) );
		}

		if ( false !== strpos( $clean_path, '..' ) ) {
			throw new Exception( 'Push rejected because media path contains invalid characters or path traversal: ' . self::safe_esc( $path ) );
		}

		$filename  = basename( $path );
		$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

		if ( empty( $extension ) || ! in_array( $extension, self::$allowed_extensions, true ) ) {
			throw new Exception( 'Push rejected because file extension is not a supported image type (.png, .jpg, .jpeg, .gif, .webp): ' . self::safe_esc( $filename ) );
		}

		// Check WP filetype validation.
		if ( function_exists( 'wp_check_filetype' ) ) {
			$wp_filetype = wp_check_filetype( $filename );
			if ( empty( $wp_filetype['ext'] ) || ! in_array( strtolower( $wp_filetype['ext'] ), self::$allowed_extensions, true ) ) {
				throw new Exception( 'Push rejected because file extension fails WordPress filetype check: ' . self::safe_esc( $filename ) );
			}
		}

		// Validate raw binary data with finfo.
		if ( empty( $binary_data ) ) {
			throw new Exception( 'Push rejected because media file binary is empty or corrupted: ' . self::safe_esc( $filename ) );
		}

		$mime_type = self::detect_mime_type( $binary_data, $filename );
		if ( empty( $mime_type ) || ! in_array( $mime_type, self::$allowed_mime_types, true ) ) {
			throw new Exception( 'Push rejected because detected MIME type (' . self::safe_esc( (string) $mime_type ) . ') is not a supported image type: ' . self::safe_esc( $filename ) );
		}
	}

	/**
	 * Validate and upload all media files in a pushed commit upfront.
	 * Returns a map of normalized media path => array('url' => string, 'id' => int).
	 *
	 * @param array $commit_files Pushed commit tree entries ($path => array('mode' => ..., 'content' => ...)).
	 * @param bool  $dry_run      If true, validate only without writing uploads.
	 * @return array Map of clean media path to media info array.
	 * @throws Exception On validation or upload failure.
	 */
	public static function process_commit_media_files( $commit_files = array(), $dry_run = false ) {
		@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Batch media thumbnail generation requires time budget extension.
		$uploaded_map = array();

		if ( ! is_array( $commit_files ) ) {
			return $uploaded_map;
		}

		foreach ( $commit_files as $path => $entry ) {
			if ( ! self::is_media_path( $path ) ) {
				continue;
			}

			// Skip non-image files inside media/ (e.g. media/.gitkeep or any future
			// placeholder) without error. Only files with allowed image extensions are
			// uploaded to the WordPress Media Library.
			$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
			if ( '' === $extension || ! in_array( $extension, self::$allowed_extensions, true ) ) {
				continue;
			}

			// Fail-closed validation for all media files in commit.
			self::validate_media_file( $path, isset( $entry['content'] ) ? $entry['content'] : '' );

			if ( $dry_run ) {
				continue;
			}

			$clean_path                              = self::normalize_relative_media_path( $path );
			$upload                                  = self::upload_media_asset( $clean_path, $entry['content'] );
			$info                                    = array(
				'url' => $upload['url'],
				'id'  => $upload['attachment_id'],
			);
			$uploaded_map[ $path ]                   = $info;
			$uploaded_map[ $clean_path ]             = $info;
			$uploaded_map[ '../' . $clean_path ]     = $info;
			$uploaded_map[ basename( $clean_path ) ] = $info;
		}

		return $uploaded_map;
	}

	/**
	 * Detect MIME type using finfo_buffer or fallback headers.
	 *
	 * @param string $binary_data Raw binary content.
	 * @param string $filename    Filename for extension fallback.
	 * @return string MIME type or empty string.
	 */
	public static function detect_mime_type( $binary_data, $filename = '' ) {
		if ( function_exists( 'finfo_open' ) && function_exists( 'finfo_buffer' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			if ( $finfo ) {
				$mime = finfo_buffer( $finfo, $binary_data );
				if ( is_string( $mime ) && '' !== $mime ) {
					return strtolower( trim( $mime ) );
				}
			}
		}

		// Fallback detection via binary magic numbers if finfo is unavailable.
		if ( strlen( $binary_data ) >= 8 ) {
			if ( 0 === strpos( $binary_data, "\x89PNG\r\n\x1a\n" ) ) {
				return 'image/png';
			}
			if ( 0 === strpos( $binary_data, "\xFF\xD8\xFF" ) ) {
				return 'image/jpeg';
			}
			if ( 0 === strpos( $binary_data, 'GIF87a' ) || 0 === strpos( $binary_data, 'GIF89a' ) ) {
				return 'image/gif';
			}
			if ( 0 === strpos( $binary_data, 'RIFF' ) && 'WEBP' === substr( $binary_data, 8, 4 ) ) {
				return 'image/webp';
			}
		}

		if ( function_exists( 'wp_check_filetype' ) && '' !== $filename ) {
			$check = wp_check_filetype( $filename );
			if ( ! empty( $check['type'] ) ) {
				return strtolower( trim( $check['type'] ) );
			}
		}

		return '';
	}

	/**
	 * Upload a media binary file to WordPress Uploads and create attachment.
	 *
	 * If a file with the same filename already exists in the Media Library,
	 * it is overwritten in place (upsert behaviour) rather than creating a
	 * duplicate with an auto-numbered suffix (filename-1.webp, etc.).
	 * The existing attachment ID and URL are preserved, so all posts that
	 * reference the image continue to work without any content updates.
	 *
	 * @param string $rel_path       Relative path in repo (e.g. media/chart.png).
	 * @param string $binary_data    Binary image data.
	 * @param int    $parent_post_id Parent post ID to attach to (optional).
	 * @return array Array with 'attachment_id' and 'url'.
	 * @throws Exception On upload failure.
	 */
	public static function upload_media_asset( $rel_path, $binary_data, $parent_post_id = 0 ) {
		self::validate_media_file( $rel_path, $binary_data );

		$filename = basename( $rel_path );

		// Upsert: if an attachment with this exact filename already exists,
		// overwrite the file on disk and update its metadata instead of
		// creating a new attachment with an auto-numbered suffix.
		$existing_id = self::find_existing_attachment_id_by_filename( $filename );
		if ( $existing_id > 0 && function_exists( 'get_attached_file' ) && function_exists( 'wp_get_attachment_url' ) ) {
			$existing_path = get_attached_file( $existing_id );
			$existing_url  = wp_get_attachment_url( $existing_id );
			if ( $existing_path && $existing_url ) {
				// Overwrite the file on disk.
				$bytes_written = file_put_contents( $existing_path, $binary_data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
				if ( false !== $bytes_written ) {
					// Regenerate image metadata (dimensions, thumbnails, etc.).
					self::generate_and_update_attachment_metadata( $existing_id, $existing_path );
					return array(
						'attachment_id' => (int) $existing_id,
						'url'           => $existing_url,
					);
				}
			}
		}

		// No existing attachment — create a new one.
		$file_path = '';
		$url       = '';

		// Use wp_upload_bits to save file.
		if ( function_exists( 'wp_upload_bits' ) ) {
			$upload = wp_upload_bits( $filename, null, $binary_data );
			if ( ! empty( $upload['error'] ) ) {
				throw new Exception( 'Failed to upload media file (' . esc_html( $filename ) . '): ' . esc_html( $upload['error'] ) );
			}

			$file_path = $upload['file'];
			$url       = $upload['url'];
		} else {
			// Test environment fallback.
			$upload_dir = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array(
				'path' => sys_get_temp_dir(),
				'url'  => 'http://example.org/wp-content/uploads',
			);
			$file_path  = rtrim( $upload_dir['path'], '/' ) . '/' . $filename;
			file_put_contents( $file_path, $binary_data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
			$url = rtrim( $upload_dir['url'], '/' ) . '/' . $filename;
		}

		$mime_type = self::detect_mime_type( $binary_data, $filename );

		$title_name = function_exists( 'sanitize_file_name' ) ? sanitize_file_name( pathinfo( $filename, PATHINFO_FILENAME ) ) : pathinfo( $filename, PATHINFO_FILENAME );

		$attachment = array(
			'guid'           => $url,
			'post_mime_type' => $mime_type,
			'post_title'     => $title_name,
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		if ( $parent_post_id > 0 ) {
			$attachment['post_parent'] = $parent_post_id;
		}

		if ( function_exists( 'wp_insert_attachment' ) ) {
			$attachment_id = wp_insert_attachment( $attachment, $file_path, $parent_post_id );
			if ( ! is_wp_error( $attachment_id ) && $attachment_id > 0 ) {
				self::generate_and_update_attachment_metadata( $attachment_id, $file_path );

				return array(
					'attachment_id' => (int) $attachment_id,
					'url'           => $url,
				);
			}
		}

		return array(
			'attachment_id' => 0,
			'url'           => $url,
		);
	}

	/**
	 * Ensure WordPress image administration headers/libraries are loaded
	 * and generate attachment metadata (including thumbnails/sub-sizes).
	 *
	 * @param int    $attachment_id Attachment post ID.
	 * @param string $file_path     Full path to attached file.
	 * @return array Generated attachment metadata array.
	 */
	public static function generate_and_update_attachment_metadata( $attachment_id, $file_path ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 || empty( $file_path ) || ! file_exists( $file_path ) ) {
			return array();
		}

		if ( defined( 'ABSPATH' ) ) {
			if ( file_exists( ABSPATH . 'wp-admin/includes/image.php' ) ) {
				require_once ABSPATH . 'wp-admin/includes/image.php';
			}
			if ( file_exists( ABSPATH . 'wp-admin/includes/file.php' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			if ( file_exists( ABSPATH . 'wp-admin/includes/media.php' ) ) {
				require_once ABSPATH . 'wp-admin/includes/media.php';
			}
		}

		if ( function_exists( 'wp_generate_attachment_metadata' ) && function_exists( 'wp_update_attachment_metadata' ) ) {
			$attach_data = wp_generate_attachment_metadata( $attachment_id, $file_path );
			if ( is_array( $attach_data ) && ! empty( $attach_data ) ) {
				wp_update_attachment_metadata( $attachment_id, $attach_data );
				return $attach_data;
			}
		}

		return array();
	}

	/**
	 * Resolve media URL to attachment info array ('url' => string, 'id' => int).
	 *
	 * @param string $relative_url       Relative URL string.
	 * @param int    $post_id            Post ID.
	 * @param array  $uploaded_media_map Pre-uploaded media map.
	 * @param array  $commit_files       Commit files payload.
	 * @param array  $uploaded_cache     Upload cache array reference.
	 * @param bool   $dry_run            If true, validate only without writing uploads.
	 * @return array|null Array with 'url' and 'id', or null.
	 */
	public static function resolve_media_url_info( $relative_url, $post_id, $uploaded_media_map = array(), $commit_files = array(), &$uploaded_cache = array(), $dry_run = false ) {
		$relative_url = trim( (string) $relative_url );
		if ( '' === $relative_url || 0 === strpos( $relative_url, 'data:' ) ) {
			return null;
		}

		// Strip malformed scheme prefixes like http://../media/ or http://localhost/../media/.
		$clean_url = preg_replace( '#^https?://([^/]+/)??(\.\./|\./|/)*#i', '', $relative_url );

		if ( ( 0 === strpos( $relative_url, 'http://' ) || 0 === strpos( $relative_url, 'https://' ) || 0 === strpos( $relative_url, '//' ) ) && ! preg_match( '#^https?://([^/]+/)??(\.\./|\./|/)*(media/|[^/]+\.(png|jpg|jpeg|gif|webp)$)#i', $relative_url ) ) {
			return null;
		}

		$clean_path = self::normalize_relative_media_path( $clean_url );
		if ( '' === $clean_path || ! self::is_media_path( $clean_path ) ) {
			$clean_path = self::normalize_relative_media_path( $relative_url );
		}

		if ( '' === $clean_path || ! self::is_media_path( $clean_path ) ) {
			return null;
		}

		if ( is_array( $uploaded_media_map ) ) {
			if ( isset( $uploaded_media_map[ $clean_path ]['url'] ) ) {
				return $uploaded_media_map[ $clean_path ];
			}
			if ( isset( $uploaded_media_map[ $relative_url ]['url'] ) ) {
				return $uploaded_media_map[ $relative_url ];
			}
			$fn = basename( $clean_path );
			if ( isset( $uploaded_media_map[ 'media/' . $fn ]['url'] ) ) {
				return $uploaded_media_map[ 'media/' . $fn ];
			}
			foreach ( $uploaded_media_map as $map_key => $map_info ) {
				if ( is_array( $map_info ) && isset( $map_info['url'] ) && basename( $map_key ) === $fn ) {
					return $map_info;
				}
			}
			if ( empty( $commit_files ) ) {
				$commit_files = $uploaded_media_map;
			}
		}

		if ( isset( $uploaded_cache[ $clean_path ] ) ) {
			return $uploaded_cache[ $clean_path ];
		}

		$fn = basename( $clean_path );

		// Before falling through to upload from commit payload, do a final exhaustive
		// basename scan of the already-uploaded map. This prevents double-uploads when
		// process_commit_media_files() has already handled this file but stored it
		// under a key that didn't match the earlier lookups (e.g. path normalisation
		// differences between how the MD references the image and how it was stored).
		if ( is_array( $uploaded_media_map ) ) {
			foreach ( $uploaded_media_map as $map_key => $map_info ) {
				if ( is_array( $map_info ) && isset( $map_info['url'] ) && basename( $map_key ) === $fn ) {
					$uploaded_cache[ $clean_path ] = $map_info;
					return $map_info;
				}
			}
		}

		$file_payload = null;
		$target_path  = $clean_path;

		if ( isset( $commit_files[ $clean_path ]['content'] ) ) {
			$file_payload = $commit_files[ $clean_path ]['content'];
			$target_path  = $clean_path;
		} elseif ( isset( $commit_files[ 'media/' . $fn ]['content'] ) ) {
			$file_payload = $commit_files[ 'media/' . $fn ]['content'];
			$target_path  = 'media/' . $fn;
		} elseif ( isset( $commit_files[ $fn ]['content'] ) ) {
			$file_payload = $commit_files[ $fn ]['content'];
			$target_path  = 'media/' . $fn;
		} else {
			foreach ( $commit_files as $c_path => $c_entry ) {
				if ( is_array( $c_entry ) && isset( $c_entry['content'] ) && basename( $c_path ) === $fn ) {
					$file_payload = $c_entry['content'];
					$target_path  = $c_path;
					break;
				}
			}
		}

		if ( null !== $file_payload ) {
			if ( $dry_run ) {
				$info                          = array(
					'url' => 'http://example.org/wp-content/uploads/' . $fn,
					'id'  => 0,
				);
				$uploaded_cache[ $clean_path ] = $info;
				return $info;
			}
			$upload                        = self::upload_media_asset( $target_path, $file_payload, $post_id );
			$info                          = array(
				'url' => $upload['url'],
				'id'  => $upload['attachment_id'],
			);
			$uploaded_cache[ $clean_path ] = $info;
			return $info;
		}

		$existing_id  = self::find_existing_attachment_id_by_filename( $fn );
		$existing_url = self::find_existing_attachment_url_by_filename( $fn );
		if ( $existing_url ) {
			$info                          = array(
				'url' => $existing_url,
				'id'  => $existing_id,
			);
			$uploaded_cache[ $clean_path ] = $info;
			return $info;
		}

		return null;
	}

	/**
	 * Update attachment record metadata (alt text, title, caption) if present and not already set.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $alt_text      Alt text from Markdown/HTML.
	 * @param string $title         Title from Markdown/HTML.
	 * @param string $caption       Caption text from HTML/block metadata.
	 */
	public static function update_attachment_metadata_from_context( $attachment_id, $alt_text = '', $title = '', $caption = '', $dry_run = false ) {
		if ( $dry_run || ! is_numeric( $attachment_id ) || (int) $attachment_id <= 0 ) {
			return;
		}
		$attachment_id = (int) $attachment_id;

		$alt_text = trim( (string) $alt_text );
		if ( '' !== $alt_text && function_exists( 'update_post_meta' ) ) {
			$existing_alt = function_exists( 'get_post_meta' ) ? get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) : '';
			if ( empty( $existing_alt ) ) {
				$clean_alt = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $alt_text ) : strip_tags( $alt_text );
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', $clean_alt );
			}
		}

		$post_updates = array();

		$title = trim( (string) $title );
		if ( '' !== $title && function_exists( 'get_post' ) ) {
			$post = get_post( $attachment_id );
			if ( $post && 'attachment' === $post->post_type ) {
				$clean_title = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $title ) : strip_tags( $title );
				$file_path   = function_exists( 'get_attached_file' ) ? get_attached_file( $attachment_id ) : '';
				$fn_title    = $file_path ? pathinfo( $file_path, PATHINFO_FILENAME ) : '';
				if ( empty( $post->post_title ) || ( '' !== $fn_title && $post->post_title === $fn_title ) ) {
					$post_updates['post_title'] = $clean_title;
				}
			}
		}

		$caption = trim( (string) $caption );
		if ( '' !== $caption && function_exists( 'get_post' ) ) {
			$post = get_post( $attachment_id );
			if ( $post && 'attachment' === $post->post_type && empty( $post->post_excerpt ) ) {
				$clean_caption                = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $caption ) : strip_tags( $caption );
				$post_updates['post_excerpt'] = $clean_caption;
			}
		}

		if ( ! empty( $post_updates ) && function_exists( 'wp_update_post' ) ) {
			$post_updates['ID'] = $attachment_id;
			wp_update_post( $post_updates );
		}
	}

	/**
	 * Process media files in a pushed commit payload and rewrite relative image URLs in Markdown, HTML,
	 * picture/figure tags, srcset attributes, and Gutenberg block comment JSON metadata.
	 *
	 * @param int    $post_id            Post ID.
	 * @param string $post_content       Block/HTML markup or Markdown content.
	 * @param array  $uploaded_media_map Pre-uploaded media map.
	 * @param array  $commit_files       Array of file entries from pushed commit ($path => array('mode' => ..., 'content' => ...)).
	 * @return string Updated post content with rewritten absolute attachment URLs.
	 */
	public static function rewrite_inline_image_paths( $post_id, $post_content, $uploaded_media_map = array(), $commit_files = array(), $dry_run = false ) {
		if ( empty( $post_content ) || ! is_string( $post_content ) ) {
			return $post_content;
		}

		$uploaded_cache = array();

		// 1. Markdown image syntax: ![alt](url "title")
		$post_content = preg_replace_callback(
			'/!\[(?P<alt>[^\]]*)\]\((?P<url>[^\s\)]+)(?:\s+["\'](?P<title>[^"\']+)["\'])?\)/i',
			function ( $matches ) use ( $post_id, $uploaded_media_map, $commit_files, &$uploaded_cache, $dry_run ) {
				$full_match   = $matches[0];
				$relative_url = $matches['url'];
				$alt_text     = isset( $matches['alt'] ) ? $matches['alt'] : '';
				$title_text   = isset( $matches['title'] ) ? $matches['title'] : '';
				$info         = Push_MD_Media::resolve_media_url_info( $relative_url, $post_id, $uploaded_media_map, $commit_files, $uploaded_cache, $dry_run );
				if ( $info && ! empty( $info['url'] ) ) {
					if ( ! empty( $info['id'] ) ) {
						Push_MD_Media::update_attachment_metadata_from_context( $info['id'], $alt_text, $title_text, '', $dry_run );
					}
					return str_replace( $relative_url, $info['url'], $full_match );
				}
				return $full_match;
			},
			$post_content
		);

		// 2. HTML figure tags with caption: <figure...><img.../><figcaption>Caption</figcaption></figure>
		$post_content = preg_replace_callback(
			'/<figure\b[^>]*>(?P<inner>.*?<img\b[^>]*?\bsrc=["\'](?P<url>[^"\']+)["\'][^>]*>.*?)(?:<figcaption\b[^>]*>(?P<caption>.*?<\/figcaption>))?.*?<\/figure>/is',
			function ( $matches ) use ( $post_id, $uploaded_media_map, $commit_files, &$uploaded_cache, $dry_run ) {
				$full_match   = $matches[0];
				$relative_url = $matches['url'];
				$caption_raw  = isset( $matches['caption'] ) ? $matches['caption'] : '';
				$caption_text = trim( strip_tags( preg_replace( '#</?figcaption\b[^>]*>#i', '', $caption_raw ) ) );
				$info         = Push_MD_Media::resolve_media_url_info( $relative_url, $post_id, $uploaded_media_map, $commit_files, $uploaded_cache, $dry_run );
				if ( $info && ! empty( $info['url'] ) && ! empty( $info['id'] ) && '' !== $caption_text ) {
					Push_MD_Media::update_attachment_metadata_from_context( $info['id'], '', '', $caption_text, $dry_run );
				}
				return $full_match;
			},
			$post_content
		);

		// 3. HTML attributes (src, href, poster, data-src, etc.) on any tag (<img/>, <source/>, <figure>, <a>, etc.)
		$post_content = preg_replace_callback(
			'/\b(?P<attr>src|href|poster|data-[a-z0-9_-]+)=["\'](?P<url>[^"\']+)["\']/i',
			function ( $matches ) use ( $post_id, $uploaded_media_map, $commit_files, &$uploaded_cache, $dry_run ) {
				$full_match   = $matches[0];
				$attr_name    = $matches['attr'];
				$relative_url = $matches['url'];
				$info         = Push_MD_Media::resolve_media_url_info( $relative_url, $post_id, $uploaded_media_map, $commit_files, $uploaded_cache, $dry_run );
				if ( $info && ! empty( $info['url'] ) ) {
					return $attr_name . '="' . $info['url'] . '"';
				}
				return $full_match;
			},
			$post_content
		);

		// 4. HTML srcset attributes (e.g. srcset="../media/c1.png 1x, ../media/c2.png 2x")
		$post_content = preg_replace_callback(
			'/\b(?P<attr>srcset)=["\'](?P<val>[^"\']+)["\']/i',
			function ( $matches ) use ( $post_id, $uploaded_media_map, $commit_files, &$uploaded_cache, $dry_run ) {
				$full_match = $matches[0];
				$srcset_val = $matches['val'];
				$entries    = explode( ',', $srcset_val );
				$rewritten  = array();
				$changed    = false;

				foreach ( $entries as $entry ) {
					$trimmed = trim( $entry );
					$parts   = preg_split( '/\s+/', $trimmed, 2 );
					if ( ! empty( $parts[0] ) ) {
						$info = Push_MD_Media::resolve_media_url_info( $parts[0], $post_id, $uploaded_media_map, $commit_files, $uploaded_cache, $dry_run );
						if ( $info && ! empty( $info['url'] ) ) {
							$descriptor  = isset( $parts[1] ) ? ' ' . $parts[1] : '';
							$rewritten[] = $info['url'] . $descriptor;
							$changed     = true;
							continue;
						}
					}
					$rewritten[] = $trimmed;
				}

				if ( $changed ) {
					return 'srcset="' . implode( ', ', $rewritten ) . '"';
				}

				return $full_match;
			},
			$post_content
		);

		// 5. Gutenberg Block Comment JSON metadata: <!-- wp:image {"id":0,"url":"../media/chart.png"} -->
		$post_content = preg_replace_callback(
			'/<!--\s+wp:(?P<name>[a-z0-9\/-]+)\s+(?P<json>\{.*?\})\s+-->/i',
			function ( $matches ) use ( $post_id, $uploaded_media_map, $commit_files, &$uploaded_cache, $dry_run ) {
				$full_match = $matches[0];
				$block_name = $matches['name'];
				$json_str   = $matches['json'];

				$decoded = json_decode( $json_str, true );
				if ( ! is_array( $decoded ) ) {
					return $full_match;
				}

				$changed  = false;
				$found_id = 0;

				$walker = function ( &$data ) use ( &$walker, $post_id, $uploaded_media_map, $commit_files, &$uploaded_cache, &$changed, &$found_id, $dry_run ) {
					if ( ! is_array( $data ) ) {
						return;
					}
					foreach ( $data as $key => &$val ) {
						if ( is_string( $val ) ) {
							$info = Push_MD_Media::resolve_media_url_info( $val, $post_id, $uploaded_media_map, $commit_files, $uploaded_cache, $dry_run );
							if ( $info && ! empty( $info['url'] ) ) {
								$val     = $info['url'];
								$changed = true;
								if ( ! empty( $info['id'] ) ) {
									$found_id = $info['id'];
								}
							}
						} elseif ( is_array( $val ) ) {
							$walker( $val );
						}
					}
				};

				$walker( $decoded );

				$alt_text     = isset( $decoded['alt'] ) ? $decoded['alt'] : '';
				$title_text   = isset( $decoded['title'] ) ? $decoded['title'] : '';
				$caption_text = isset( $decoded['caption'] ) ? $decoded['caption'] : '';

				if ( $found_id > 0 ) {
					if ( array_key_exists( 'id', $decoded ) ) {
						$decoded['id'] = $found_id;
						$changed       = true;
					}
					if ( array_key_exists( 'mediaId', $decoded ) ) {
						$decoded['mediaId'] = $found_id;
						$changed            = true;
					}
					if ( '' !== $alt_text || '' !== $title_text || '' !== $caption_text ) {
						Push_MD_Media::update_attachment_metadata_from_context( $found_id, $alt_text, $title_text, $caption_text, $dry_run );
					}
				}

				if ( $changed ) {
					$new_json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $decoded, JSON_UNESCAPED_SLASHES ) : json_encode( $decoded, JSON_UNESCAPED_SLASHES );
					return '<!-- wp:' . $block_name . ' ' . $new_json . ' -->';
				}

				return $full_match;
			},
			$post_content
		);

		return $post_content;
	}

	/**
	 * Normalize relative media path to always start with media/.
	 * Handles "../media/image.png", "media/image.png", "/media/image.png", "./media/image.png", or "image.png".
	 *
	 * @param string $path Relative path string.
	 * @return string Normalized path starting with media/, or empty string if not a media path.
	 */
	public static function normalize_relative_media_path( $path ) {
		$path = trim( (string) $path );
		if ( '' === $path || 0 === strpos( $path, 'http://' ) || 0 === strpos( $path, 'https://' ) || 0 === strpos( $path, '//' ) || 0 === strpos( $path, 'data:' ) ) {
			return '';
		}

		while ( preg_match( '#^(\.\.?/|/)#', $path ) ) {
			$path = preg_replace( '#^(\.\.?/|/)+#', '', $path );
		}

		if ( 0 === strpos( $path, 'media/' ) || 'media' === $path ) {
			return $path;
		}

		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( ! empty( $extension ) && in_array( $extension, self::$allowed_extensions, true ) ) {
			return 'media/' . ltrim( $path, '/' );
		}

		return '';
	}

	/**
	 * Assign featured image for a post based on frontmatter value.
	 *
	 * @param int   $post_id            Post ID.
	 * @param mixed $img_val            Featured image value from frontmatter.
	 * @param array $uploaded_media_map Pre-uploaded media map.
	 * @param array $commit_files       Pushed commit payload files ($path => entry).
	 */
	public static function handle_featured_image( $post_id, $img_val, $uploaded_media_map = array(), $commit_files = array(), $dry_run = false ) {
		$img_val = trim( (string) $img_val );
		if ( '' === $img_val ) {
			if ( ! $dry_run && function_exists( 'delete_post_thumbnail' ) ) {
				delete_post_thumbnail( $post_id );
			}
			return;
		}

		// 1. Check if numeric attachment ID.
		if ( is_numeric( $img_val ) ) {
			$att_id = (int) $img_val;
			$post   = function_exists( 'get_post' ) ? get_post( $att_id ) : null;
			if ( $post && 'attachment' === $post->post_type ) {
				if ( ! $dry_run && function_exists( 'set_post_thumbnail' ) ) {
					set_post_thumbnail( $post_id, $att_id );
				}
				return;
			}
		}

		// 2. Check if absolute URL.
		if ( 0 === strpos( $img_val, 'http://' ) || 0 === strpos( $img_val, 'https://' ) || 0 === strpos( $img_val, '//' ) ) {
			if ( function_exists( 'attachment_url_to_postid' ) ) {
				$att_id = attachment_url_to_postid( $img_val );
				if ( $att_id > 0 ) {
					$current_thumb = function_exists( 'get_post_thumbnail_id' ) ? get_post_thumbnail_id( $post_id ) : 0;
					if ( ! $dry_run && (int) $current_thumb !== (int) $att_id && function_exists( 'set_post_thumbnail' ) ) {
						set_post_thumbnail( $post_id, $att_id );
					}
					return;
				}
			}
			// Pointing to another URL: ignore.
			return;
		}

		// 3. Relative media path pointing to media/ directory.
		$clean_path = self::normalize_relative_media_path( $img_val );
		if ( '' !== $clean_path && self::is_media_path( $clean_path ) ) {
			$attachment_id = 0;

			if ( is_array( $uploaded_media_map ) ) {
				if ( isset( $uploaded_media_map[ $clean_path ]['id'] ) && $uploaded_media_map[ $clean_path ]['id'] > 0 ) {
					$attachment_id = (int) $uploaded_media_map[ $clean_path ]['id'];
				} elseif ( empty( $commit_files ) && ( isset( $uploaded_media_map[ $clean_path ]['content'] ) || isset( $uploaded_media_map[ 'media/' . basename( $clean_path ) ]['content'] ) ) ) {
					$commit_files = $uploaded_media_map;
				}
			}

			if ( 0 === $attachment_id && isset( $commit_files[ $clean_path ]['content'] ) ) {
				if ( ! $dry_run ) {
					$upload        = self::upload_media_asset( $clean_path, $commit_files[ $clean_path ]['content'], $post_id );
					$attachment_id = $upload['attachment_id'];
				}
			} elseif ( 0 === $attachment_id ) {
				$attachment_id = self::find_existing_attachment_id_by_filename( basename( $clean_path ) );
			}

			if ( ! $dry_run && $attachment_id > 0 && function_exists( 'set_post_thumbnail' ) ) {
				set_post_thumbnail( $post_id, $attachment_id );
			}
		}
	}

	/**
	 * Find attachment ID by filename.
	 *
	 * @param string $filename Image filename.
	 * @return int Attachment ID or 0.
	 */
	public static function find_existing_attachment_id_by_filename( $filename ) {
		if ( ! function_exists( 'get_posts' ) ) {
			return 0;
		}

		// LIKE is used so the DB can match paths like "2025/01/chart.png", but we
		// then verify the basename exactly to avoid false positives such as
		// "chart-inline.png" matching a search for "chart.png".
		$attachments = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'     => array(
					array(
						'key'     => '_wp_attached_file',
						'value'   => $filename,
						'compare' => 'LIKE',
					),
				),
			)
		);

		foreach ( $attachments as $att ) {
			$attached_file = function_exists( 'get_post_meta' ) ? get_post_meta( $att->ID, '_wp_attached_file', true ) : '';
			if ( basename( (string) $attached_file ) === $filename ) {
				return (int) $att->ID;
			}
		}

		// Fallback: search by sanitized slug (handles cases where WP sanitized the
		// filename on upload, e.g. "My Photo.png" → "my-photo.png").
		$slug = pathinfo( $filename, PATHINFO_FILENAME );
		if ( '' !== $slug ) {
			$clean_slug = function_exists( 'sanitize_title' ) ? sanitize_title( $slug ) : $slug;
			if ( $clean_slug !== $slug ) {
				$extension  = pathinfo( $filename, PATHINFO_EXTENSION );
				$clean_name = '' !== $extension ? $clean_slug . '.' . $extension : $clean_slug;
				$by_slug    = get_posts(
					array(
						'post_type'      => 'attachment',
						'post_status'    => 'inherit',
						'posts_per_page' => -1,
						// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						'meta_query'     => array(
							array(
								'key'     => '_wp_attached_file',
								'value'   => $clean_slug,
								'compare' => 'LIKE',
							),
						),
					)
				);

				foreach ( $by_slug as $att ) {
					$attached_file = function_exists( 'get_post_meta' ) ? get_post_meta( $att->ID, '_wp_attached_file', true ) : '';
					if ( basename( (string) $attached_file ) === $clean_name ) {
						return (int) $att->ID;
					}
				}
			}
		}

		return 0;
	}

	/**
	 * Find attachment URL by filename.
	 *
	 * @param string $filename Image filename.
	 * @return string Attachment URL or empty string.
	 */
	public static function find_existing_attachment_url_by_filename( $filename ) {
		$att_id = self::find_existing_attachment_id_by_filename( $filename );
		if ( $att_id > 0 && function_exists( 'wp_get_attachment_url' ) ) {
			$url = wp_get_attachment_url( $att_id );
			return $url ? $url : '';
		}

		return '';
	}

	/**
	 * Export media library image attachments to media/ entries for Git export/pull.
	 *
	 * The media/ directory in the repository is intentionally push-only: files
	 * committed to media/ are uploaded to the WordPress Media Library on push and
	 * then auto-deleted from the repository tree by a server-side follow-up commit.
	 * This keeps the local checkout lean (no binary blobs accumulating over time)
	 * and avoids re-exporting the entire media library on every git clone/fetch.
	 *
	 * This method is retained for optional use (e.g. a future opt-in export flag)
	 * but is no longer called during normal repository sync.
	 *
	 * @return array Always returns an empty array. Media is not exported to git.
	 */
	public static function export_media_content() {
		return array();
	}
}
