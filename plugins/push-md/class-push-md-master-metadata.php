<?php
/**
 * Push MD Master Metadata: Category, Tag, and Author reference files and post term synchronization.
 *
 * @package Push_MD
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WordPress\Git\Model\TreeEntry;

/**
 * Class Push_MD_Master_Metadata
 *
 * Handles export, synchronization, and validation of categories.md, tags.md,
 * and authors.md master reference files and post-level taxonomy/author assignment.
 */
class Push_MD_Master_Metadata {

	/**
	 * Check if a given relative repository path is a master metadata file.
	 *
	 * @param string $path File path to check.
	 * @return bool True if path is a master metadata file.
	 */
	public static function is_master_metadata_path( $path ) {
		$clean_path = ltrim( $path, '/' );

		return in_array( $clean_path, array( 'categories.md', 'tags.md', 'authors.md' ), true );
	}

	/**
	 * Add master reference files (categories, tags, authors) to repository files for export.
	 *
	 * @param array $files Output repository files map.
	 */
	public static function add_master_taxonomy_and_author_files( &$files ) {
		$files['categories.md'] = array(
			'post'    => null,
			'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
			'content' => self::export_categories_markdown(),
		);
		$files['tags.md']       = array(
			'post'    => null,
			'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
			'content' => self::export_tags_markdown(),
		);
		$files['authors.md']    = array(
			'post'    => null,
			'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
			'content' => self::export_authors_markdown(),
		);
	}

	/**
	 * Export all WordPress categories to markdown with YAML frontmatter.
	 *
	 * @return string Markdown content for categories.md.
	 */
	public static function export_categories_markdown() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		$list  = array();
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$parent_slug = '';
				if ( $term->parent > 0 ) {
					$parent_term = get_term( $term->parent, 'category' );
					if ( $parent_term && ! is_wp_error( $parent_term ) ) {
						$parent_slug = $parent_term->slug;
					}
				}
				$list[] = array(
					'id'          => intval( $term->term_id ),
					'name'        => $term->name,
					'slug'        => $term->slug,
					'description' => $term->description,
					'parent'      => $parent_slug,
				);
			}
		}

		$content = self::format_yaml_list( 'categories', $list ) . "\n# WordPress Categories Master Reference\n";

		return $content;
	}

	/**
	 * Format an array of records as a YAML list inside frontmatter.
	 *
	 * @param string $key   Root YAML key name.
	 * @param array  $items List of associative arrays.
	 * @return string Formatted YAML string.
	 */
	public static function format_yaml_list( $key, array $items ) {
		$yaml = "---\n" . $key . ":\n";
		foreach ( $items as $item ) {
			$first = true;
			foreach ( $item as $k => $v ) {
				$val_str = wp_json_encode( (string) $v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				if ( $first ) {
					$yaml .= '  - ' . $k . ': ' . $val_str . "\n";
					$first = false;
				} else {
					$yaml .= '    ' . $k . ': ' . $val_str . "\n";
				}
			}
		}
		$yaml .= "---\n";

		return $yaml;
	}

	/**
	 * Export all WordPress tags to markdown with YAML frontmatter.
	 *
	 * @return string Markdown content for tags.md.
	 */
	public static function export_tags_markdown() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'post_tag',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		$list  = array();
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$list[] = array(
					'id'          => intval( $term->term_id ),
					'name'        => $term->name,
					'slug'        => $term->slug,
					'description' => $term->description,
				);
			}
		}

		$content = self::format_yaml_list( 'tags', $list ) . "\n# WordPress Tags Master Reference\n";

		return $content;
	}

	/**
	 * Check if a given user has capability to edit any supported post type.
	 *
	 * @param WP_User $user User object to check.
	 * @return bool True if user can edit posts.
	 */
	public static function user_can_edit_any_supported_post_type( $user ) {
		$post_types = Push_MD_Plugin::get_supported_post_types();
		foreach ( $post_types as $post_type ) {
			$pt_obj = get_post_type_object( $post_type );
			$cap    = ( $pt_obj && isset( $pt_obj->cap->edit_posts ) ) ? $pt_obj->cap->edit_posts : 'edit_posts';
			if ( user_can( $user, $cap ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Export authors to markdown with YAML frontmatter.
	 *
	 * @return string Markdown content for authors.md.
	 */
	public static function export_authors_markdown() {
		$users = get_users(
			array(
				'orderby' => 'user_login',
				'order'   => 'ASC',
			)
		);
		$list  = array();
		if ( is_array( $users ) ) {
			foreach ( $users as $user ) {
				if ( ! self::user_can_edit_any_supported_post_type( $user ) ) {
					continue;
				}
				$author_data     = array(
					'user_login'    => $user->user_login,
					'display_name'  => $user->display_name,
					'first_name'    => get_user_meta( $user->ID, 'first_name', true ),
					'last_name'     => get_user_meta( $user->ID, 'last_name', true ),
					'user_email'    => $user->user_email,
					'user_nicename' => $user->user_nicename,
					'description'   => get_user_meta( $user->ID, 'description', true ),
				);
				$extra_meta_keys = apply_filters( 'push_md_author_meta_keys', array(), $user );
				if ( is_array( $extra_meta_keys ) ) {
					foreach ( $extra_meta_keys as $meta_key ) {
						$meta_key = (string) $meta_key;
						if ( '' !== $meta_key && ! isset( $author_data[ $meta_key ] ) ) {
							$val = get_user_meta( $user->ID, $meta_key, true );
							if ( '' !== $val && false !== $val && null !== $val ) {
								$author_data[ $meta_key ] = $val;
							}
						}
					}
				}

				$author_data = apply_filters( 'push_md_export_author', $author_data, $user );
				$list[]      = $author_data;
			}
		}

		$content = self::format_yaml_list( 'authors', $list ) . "\n# WordPress Authors Master Reference\n";

		return $content;
	}

	/**
	 * Verify permissions to sync authors from markdown.
	 *
	 * @param string $markdown Content of authors.md.
	 * @throws Exception If permission check fails.
	 */
	public static function assert_can_sync_authors( $markdown ) {
		$data = self::parse_master_file_data( $markdown, 'authors' );
		if ( ! is_array( $data ) ) {
			return;
		}

		$current_user_id = get_current_user_id();

		foreach ( $data as $item ) {
			if ( ! is_array( $item ) || empty( $item['user_login'] ) ) {
				continue;
			}
			$login = (string) $item['user_login'];
			$user  = get_user_by( 'login', $login );
			if ( ! $user ) {
				$user = get_user_by( 'slug', $login );
			}
			if ( ! $user ) {
				throw new Exception( sprintf( 'Push rejected because author "%s" was not found in WordPress.', esc_html( $login ) ) );
			}

			$can_edit = ( $current_user_id && (int) $current_user_id === (int) $user->ID )
						|| current_user_can( 'edit_users' )
						|| current_user_can( 'edit_user', $user->ID );

			if ( ! $can_edit ) {
				throw new Exception( sprintf( 'Push rejected because you do not have permission to edit author "%s".', esc_html( $login ) ) );
			}
		}
	}

	/**
	 * Verify permissions to manage terms for a given taxonomy.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @throws Exception If capability check fails.
	 */
	public static function assert_can_sync_terms( $taxonomy ) {
		$tax_obj = get_taxonomy( $taxonomy );
		$cap     = ( $tax_obj && isset( $tax_obj->cap->manage_terms ) ) ? $tax_obj->cap->manage_terms : 'manage_categories';
		if ( ! current_user_can( $cap ) ) {
			throw new Exception( sprintf( 'Push rejected because you do not have permission to manage %s.', esc_html( $taxonomy ) ) );
		}
	}

	/**
	 * Ingest and synchronize master metadata files during git push commit diff application.
	 *
	 * @param string $path     Path to master metadata file.
	 * @param string $markdown Markdown file content.
	 * @param array  $options  Sync options.
	 * @return array Post sync result.
	 */
	public static function upsert_master_metadata_from_markdown( $path, $markdown, $options = array() ) {
		$clean_path = ltrim( $path, '/' );

		if ( 'authors.md' === $clean_path ) {
			self::assert_can_sync_authors( $markdown );
		} elseif ( 'categories.md' === $clean_path ) {
			self::assert_can_sync_terms( 'category' );
		} elseif ( 'tags.md' === $clean_path ) {
			self::assert_can_sync_terms( 'post_tag' );
		}

		if ( ! empty( $options['dry_run'] ) ) {
			return array(
				'post_id' => 0,
				'change'  => null,
			);
		}

		if ( 'categories.md' === $clean_path ) {
			self::sync_categories_from_markdown( $markdown );
		} elseif ( 'tags.md' === $clean_path ) {
			self::sync_tags_from_markdown( $markdown );
		} elseif ( 'authors.md' === $clean_path ) {
			self::sync_authors_from_markdown( $markdown );
		}

		return array(
			'post_id' => 0,
			'change'  => null,
		);
	}

	/**
	 * Sync categories from markdown data into WordPress terms.
	 *
	 * @param string $markdown Categories markdown content.
	 */
	public static function sync_categories_from_markdown( $markdown ) {
		$data = self::parse_master_file_data( $markdown, 'categories' );
		if ( ! is_array( $data ) ) {
			return;
		}

		$processed_term_ids = array();

		foreach ( $data as $item ) {
			if ( ! is_array( $item ) || empty( $item['name'] ) ) {
				continue;
			}
			$name        = (string) $item['name'];
			$slug        = ! empty( $item['slug'] ) ? (string) $item['slug'] : sanitize_title( $name );
			$description = isset( $item['description'] ) ? (string) $item['description'] : '';
			$parent_slug = isset( $item['parent'] ) ? trim( (string) $item['parent'] ) : '';

			$parent_id = 0;
			if ( '' !== $parent_slug ) {
				$parent_term = false;
				if ( is_numeric( $parent_slug ) ) {
					$parent_term = get_term( intval( $parent_slug ), 'category' );
					if ( is_wp_error( $parent_term ) || ! $parent_term ) {
						$parent_term = false;
					}
				}
				if ( ! $parent_term ) {
					$parent_term = get_term_by( 'slug', $parent_slug, 'category' );
				}
				if ( ! $parent_term ) {
					$parent_term = get_term_by( 'name', $parent_slug, 'category' );
				}
				if ( $parent_term && ! is_wp_error( $parent_term ) ) {
					$parent_id = $parent_term->term_id;
				}
			}

			$existing = false;
			if ( ! empty( $item['id'] ) ) {
				$existing = get_term( intval( $item['id'] ), 'category' );
				if ( is_wp_error( $existing ) || ! $existing ) {
					$existing = false;
				}
			}
			if ( ! $existing ) {
				$existing = get_term_by( 'slug', $slug, 'category' );
			}
			if ( ! $existing ) {
				$existing = get_term_by( 'name', $name, 'category' );
			}

			if ( $existing && ! is_wp_error( $existing ) ) {
				$updated = wp_update_term(
					$existing->term_id,
					'category',
					array(
						'name'        => $name,
						'slug'        => $slug,
						'description' => $description,
						'parent'      => $parent_id,
					)
				);
				if ( is_array( $updated ) && isset( $updated['term_id'] ) ) {
					$processed_term_ids[] = intval( $updated['term_id'] );
				} else {
					$processed_term_ids[] = intval( $existing->term_id );
				}
			} else {
				$created = wp_insert_term(
					$name,
					'category',
					array(
						'slug'        => $slug,
						'description' => $description,
						'parent'      => $parent_id,
					)
				);
				if ( is_array( $created ) && isset( $created['term_id'] ) ) {
					$processed_term_ids[] = intval( $created['term_id'] );
				}
			}
		}

		$all_terms      = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
			)
		);
		$default_cat_id = (int) get_option( 'default_category', 1 );
		if ( is_array( $all_terms ) ) {
			foreach ( $all_terms as $term ) {
				if ( is_object( $term ) && ! in_array( intval( $term->term_id ), $processed_term_ids, true ) ) {
					if ( intval( $term->term_id ) !== $default_cat_id && 'uncategorized' !== strtolower( $term->slug ) ) {
						wp_delete_term( $term->term_id, 'category' );
					}
				}
			}
		}
	}

	/**
	 * Sync tags from markdown data into WordPress terms.
	 *
	 * @param string $markdown Tags markdown content.
	 */
	public static function sync_tags_from_markdown( $markdown ) {
		$data = self::parse_master_file_data( $markdown, 'tags' );
		if ( ! is_array( $data ) ) {
			return;
		}

		$processed_term_ids = array();

		foreach ( $data as $item ) {
			if ( ! is_array( $item ) || empty( $item['name'] ) ) {
				continue;
			}
			$name        = (string) $item['name'];
			$slug        = ! empty( $item['slug'] ) ? (string) $item['slug'] : sanitize_title( $name );
			$description = isset( $item['description'] ) ? (string) $item['description'] : '';

			$existing = false;
			if ( ! empty( $item['id'] ) ) {
				$existing = get_term( intval( $item['id'] ), 'post_tag' );
				if ( is_wp_error( $existing ) || ! $existing ) {
					$existing = false;
				}
			}
			if ( ! $existing ) {
				$existing = get_term_by( 'slug', $slug, 'post_tag' );
			}
			if ( ! $existing ) {
				$existing = get_term_by( 'name', $name, 'post_tag' );
			}

			if ( $existing && ! is_wp_error( $existing ) ) {
				$updated = wp_update_term(
					$existing->term_id,
					'post_tag',
					array(
						'name'        => $name,
						'slug'        => $slug,
						'description' => $description,
					)
				);
				if ( is_array( $updated ) && isset( $updated['term_id'] ) ) {
					$processed_term_ids[] = intval( $updated['term_id'] );
				} else {
					$processed_term_ids[] = intval( $existing->term_id );
				}
			} else {
				$created = wp_insert_term(
					$name,
					'post_tag',
					array(
						'slug'        => $slug,
						'description' => $description,
					)
				);
				if ( is_array( $created ) && isset( $created['term_id'] ) ) {
					$processed_term_ids[] = intval( $created['term_id'] );
				}
			}
		}

		$all_terms = get_terms(
			array(
				'taxonomy'   => 'post_tag',
				'hide_empty' => false,
			)
		);
		if ( is_array( $all_terms ) ) {
			foreach ( $all_terms as $term ) {
				if ( is_object( $term ) && ! in_array( intval( $term->term_id ), $processed_term_ids, true ) ) {
					wp_delete_term( $term->term_id, 'post_tag' );
				}
			}
		}
	}

	/**
	 * Sync authors from markdown data into WordPress user profiles.
	 *
	 * @param string $markdown Authors markdown content.
	 */
	public static function sync_authors_from_markdown( $markdown ) {
		$data = self::parse_master_file_data( $markdown, 'authors' );
		if ( ! is_array( $data ) ) {
			return;
		}

		foreach ( $data as $item ) {
			if ( ! is_array( $item ) || empty( $item['user_login'] ) ) {
				continue;
			}
			$login = (string) $item['user_login'];
			$user  = get_user_by( 'login', $login );
			if ( ! $user ) {
				$user = get_user_by( 'slug', $login );
			}
			if ( ! $user ) {
				continue;
			}

			$userdata = array(
				'ID' => $user->ID,
			);
			if ( isset( $item['display_name'] ) ) {
				$userdata['display_name'] = (string) $item['display_name'];
			}
			if ( isset( $item['first_name'] ) ) {
				$userdata['first_name'] = (string) $item['first_name'];
			}
			if ( isset( $item['last_name'] ) ) {
				$userdata['last_name'] = (string) $item['last_name'];
			}
			if ( isset( $item['user_email'] ) && is_email( $item['user_email'] ) ) {
				$userdata['user_email'] = (string) $item['user_email'];
			}
			if ( isset( $item['description'] ) ) {
				$userdata['description'] = (string) $item['description'];
			}

			wp_update_user( $userdata );

			$extra_meta_keys = apply_filters( 'push_md_author_meta_keys', array(), $user );
			if ( is_array( $extra_meta_keys ) ) {
				foreach ( $extra_meta_keys as $meta_key ) {
					$meta_key = (string) $meta_key;
					if ( '' !== $meta_key && isset( $item[ $meta_key ] ) ) {
						update_user_meta( $user->ID, $meta_key, $item[ $meta_key ] );
					}
				}
			}

			do_action( 'push_md_import_author', $user->ID, $item );
		}
	}

	/**
	 * Parse YAML list data from a master reference markdown file.
	 *
	 * @param string $markdown Master file markdown string.
	 * @param string $key      Root YAML key to extract.
	 * @return array List of records.
	 */
	public static function parse_master_file_data( $markdown, $key ) {
		Push_MD_Plugin::assert_markdown_front_matter_is_closed( $markdown );
		$lines = preg_split( "/\r\n|\n|\r/", $markdown );
		if ( empty( $lines ) || '---' !== trim( $lines[0] ) ) {
			return array();
		}

		$yaml_lines = array();
		$count      = count( $lines );
		for ( $i = 1; $i < $count; $i++ ) {
			if ( '---' === trim( $lines[ $i ] ) ) {
				break;
			}
			$yaml_lines[] = $lines[ $i ];
		}

		$items        = array();
		$current_item = null;
		$in_key       = false;

		foreach ( $yaml_lines as $line ) {
			$trimmed = trim( $line );
			if ( '' === $trimmed ) {
				continue;
			}
			if ( preg_match( '/^' . preg_quote( $key, '/' ) . '\s*:/', $trimmed ) ) {
				$in_key = true;
				continue;
			}
			if ( ! $in_key ) {
				continue;
			}

			if ( preg_match( '/^\s*-\s*([A-Za-z0-9_]+)\s*:\s*(.*)$/', $line, $m ) ) {
				if ( null !== $current_item ) {
					$items[] = $current_item;
				}
				$current_item       = array();
				$k                  = $m[1];
				$v                  = trim( $m[2] );
				$current_item[ $k ] = self::decode_yaml_value( $v );
			} elseif ( null !== $current_item && preg_match( '/^\s*([A-Za-z0-9_]+)\s*:\s*(.*)$/', $line, $m ) ) {
				$k                  = $m[1];
				$v                  = trim( $m[2] );
				$current_item[ $k ] = self::decode_yaml_value( $v );
			}
		}

		if ( null !== $current_item ) {
			$items[] = $current_item;
		}

		return $items;
	}

	/**
	 * Decode a YAML string scalar into PHP scalar or decoded JSON.
	 *
	 * @param string $val YAML scalar string.
	 * @return mixed Decoded value.
	 */
	public static function decode_yaml_value( $val ) {
		$val = trim( $val );
		if ( '' === $val ) {
			return '';
		}
		$len = strlen( $val );
		if ( ( '"' === $val[0] && '"' === $val[ $len - 1 ] ) || ( "'" === $val[0] && "'" === $val[ $len - 1 ] ) ) {
			$decoded = json_decode( $val, true );
			if ( null !== $decoded ) {
				return $decoded;
			}

			return trim( $val, "'\"" );
		}

		return $val;
	}

	/**
	 * Validate author, category, and tag references in post frontmatter against WordPress.
	 *
	 * @param array  $metadata  Parsed post frontmatter.
	 * @param string $post_type Post type.
	 * @param array  $options   Validation options.
	 * @param string $path      File path for error messaging.
	 * @throws Exception If reference validation fails.
	 */
	public static function validate_post_frontmatter_references( $metadata, $post_type, $options = array(), $path = '' ) {
		unset( $post_type );

		if ( isset( $metadata['author'] ) && '' !== trim( (string) $metadata['author'] ) ) {
			$author_id = self::resolve_frontmatter_author_id( $metadata['author'] );
			if ( 0 === $author_id ) {
				Push_MD_Plugin::throw_push_rejection(
					sprintf( 'author "%s" is incorrect or was not found in WordPress. Please refer to authors.md in your local repository for correct values.', esc_html( (string) $metadata['author'] ) ),
					$path
				);
			}
		}

		if ( isset( $metadata['categories'] ) && ! empty( $metadata['categories'] ) ) {
			$cat_list = self::parse_frontmatter_list( $metadata['categories'] );
			foreach ( $cat_list as $cat_path ) {
				if ( '' === $cat_path ) {
					continue;
				}
				$term_id = self::resolve_category_path_to_term_id( $cat_path );
				if ( 0 === $term_id ) {
					Push_MD_Plugin::throw_push_rejection(
						sprintf( 'category "%s" is incorrect or was not found in categories.md or WordPress. Please refer to categories.md in your local repository for correct values.', esc_html( $cat_path ) ),
						$path
					);
				}
			}
		}

		if ( isset( $metadata['tags'] ) && ! empty( $metadata['tags'] ) ) {
			$tag_list = self::parse_frontmatter_list( $metadata['tags'] );
			foreach ( $tag_list as $tag_name ) {
				if ( '' === $tag_name ) {
					continue;
				}
				$term_id = self::resolve_tag_name_to_term_id( $tag_name );
				if ( 0 === $term_id ) {
					Push_MD_Plugin::throw_push_rejection(
						sprintf( 'tag "%s" is incorrect or was not found in tags.md or WordPress. Please refer to tags.md in your local repository for correct values.', esc_html( $tag_name ) ),
						$path
					);
				}
			}
		}

		if ( isset( $metadata['featured_image'] ) && '' !== trim( (string) $metadata['featured_image'] ) ) {
			$img_id = self::resolve_featured_image_id( $metadata['featured_image'], $options );
			if ( 0 === $img_id ) {
				Push_MD_Plugin::throw_push_rejection(
					sprintf( 'featured image "%s" was not found in Media Library.', esc_html( (string) $metadata['featured_image'] ) ),
					$path
				);
			}
		}
	}

	/**
	 * Build hierarchical category breadcrumb string (Parent > Child).
	 *
	 * @param WP_Term|null $term Category term object.
	 * @return string Hierarchical category path.
	 */
	public static function get_category_path_string( $term ) {
		if ( ! $term || is_wp_error( $term ) ) {
			return '';
		}

		$names     = array( $term->name );
		$ancestors = get_ancestors( $term->term_id, 'category', 'taxonomy' );
		if ( is_array( $ancestors ) ) {
			foreach ( $ancestors as $ancestor_id ) {
				$parent_term = get_term( $ancestor_id, 'category' );
				if ( $parent_term && ! is_wp_error( $parent_term ) ) {
					array_unshift( $names, $parent_term->name );
				}
			}
		}

		return implode( ' > ', $names );
	}

	/**
	 * Resolve term ID by name or slug with optional parent constraint.
	 *
	 * @param string   $name_or_slug Name or slug of term.
	 * @param string   $taxonomy     Taxonomy slug.
	 * @param int|null $parent_id    Optional parent term ID.
	 * @return int Term ID or 0 if not found.
	 */
	public static function resolve_term_by_name_or_slug( $name_or_slug, $taxonomy, $parent_id = null ) {
		$name_or_slug = trim( (string) $name_or_slug );
		if ( '' === $name_or_slug ) {
			return 0;
		}

		$term = get_term_by( 'name', $name_or_slug, $taxonomy );
		if ( ! $term ) {
			$term = get_term_by( 'slug', $name_or_slug, $taxonomy );
		}
		if ( $term && ! is_wp_error( $term ) ) {
			if ( null === $parent_id || intval( $term->parent ) === intval( $parent_id ) ) {
				return (int) $term->term_id;
			}
		}

		$args = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
		);
		if ( null !== $parent_id ) {
			$args['parent'] = intval( $parent_id );
		}

		$terms = get_terms( $args );
		if ( is_array( $terms ) ) {
			foreach ( $terms as $t ) {
				if ( 0 === strcasecmp( $t->name, $name_or_slug ) || 0 === strcasecmp( $t->slug, $name_or_slug ) ) {
					return (int) $t->term_id;
				}
			}
		}

		return 0;
	}

	/**
	 * Resolve a hierarchical category path string (e.g. "Tech > AI") to term ID.
	 *
	 * @param string $path_str Hierarchical path string.
	 * @return int Term ID or 0 if not found.
	 */
	public static function resolve_category_path_to_term_id( $path_str ) {
		$path_str = trim( (string) $path_str );
		if ( '' === $path_str ) {
			return 0;
		}

		$parts     = array_map( 'trim', explode( '>', $path_str ) );
		$parent_id = 0;

		foreach ( $parts as $part ) {
			$term_id = self::resolve_term_by_name_or_slug( $part, 'category', $parent_id );
			if ( 0 === $term_id ) {
				$term_id = self::resolve_term_by_name_or_slug( $part, 'category' );
			}
			if ( 0 === $term_id ) {
				return 0;
			}
			$parent_id = $term_id;
		}

		return $parent_id;
	}

	/**
	 * Resolve tag name or slug to term ID.
	 *
	 * @param string $tag_name Tag name or slug.
	 * @return int Term ID or 0 if not found.
	 */
	public static function resolve_tag_name_to_term_id( $tag_name ) {
		return self::resolve_term_by_name_or_slug( $tag_name, 'post_tag' );
	}

	/**
	 * Resolve an author login, slug, ID, or display name to a WordPress User ID.
	 *
	 * @param mixed $author_val Author identifier.
	 * @return int User ID or 0 if not found.
	 */
	public static function resolve_frontmatter_author_id( $author_val ) {
		$author_val = trim( (string) $author_val );
		if ( '' === $author_val ) {
			return 0;
		}

		$user = get_user_by( 'login', $author_val );
		if ( ! $user ) {
			$user = get_user_by( 'slug', $author_val );
		}
		if ( ! $user && is_numeric( $author_val ) ) {
			$user = get_userdata( (int) $author_val );
		}
		if ( ! $user ) {
			$users = get_users();
			if ( is_array( $users ) ) {
				foreach ( $users as $u ) {
					if ( 0 === strcasecmp( $u->display_name, $author_val ) || 0 === strcasecmp( $u->user_login, $author_val ) ) {
						return $u->ID;
					}
				}
			}
		}

		return ( $user && ! is_wp_error( $user ) ) ? $user->ID : 0;
	}

	/**
	 * Resolve featured image reference in frontmatter to an attachment ID.
	 *
	 * @param string $img_val Image path, ID, or URL.
	 * @param array  $options Validation options.
	 * @return int Attachment ID, -1 if pending staging, or 0 if invalid.
	 */
	public static function resolve_featured_image_id( $img_val, $options = array() ) {
		$img_val = trim( (string) $img_val );
		if ( '' === $img_val ) {
			return 0;
		}

		if ( is_numeric( $img_val ) ) {
			$post = function_exists( 'get_post' ) ? get_post( (int) $img_val ) : null;
			return ( $post && 'attachment' === $post->post_type ) ? (int) $img_val : 0;
		}

		if ( function_exists( 'attachment_url_to_postid' ) ) {
			$attachment_id = attachment_url_to_postid( $img_val );
			if ( $attachment_id ) {
				return $attachment_id;
			}
		}

		$clean_path = Push_MD_Media::normalize_relative_media_path( $img_val );
		if ( '' !== $clean_path && Push_MD_Media::is_media_path( $clean_path ) ) {
			$commit_files       = isset( $options['commit_files'] ) && is_array( $options['commit_files'] ) ? $options['commit_files'] : array();
			$uploaded_media_map = isset( $options['uploaded_media_map'] ) && is_array( $options['uploaded_media_map'] ) ? $options['uploaded_media_map'] : array();

			if ( is_array( $uploaded_media_map ) && isset( $uploaded_media_map[ $clean_path ]['id'] ) && $uploaded_media_map[ $clean_path ]['id'] > 0 ) {
				return (int) $uploaded_media_map[ $clean_path ]['id'];
			}

			$fn = basename( $clean_path );
			if ( isset( $commit_files[ $clean_path ] ) || isset( $commit_files[ 'media/' . $fn ] ) || isset( $commit_files[ $fn ] ) ) {
				return -1;
			}

			foreach ( array_keys( $commit_files ) as $c_path ) {
				if ( basename( $c_path ) === $fn ) {
					return -1;
				}
			}

			$existing_id = Push_MD_Media::find_existing_attachment_id_by_filename( $fn );
			if ( $existing_id > 0 ) {
				return $existing_id;
			}
		}

		if ( ! empty( $img_val ) && is_string( $img_val ) && ( 0 === strpos( $img_val, 'http://' ) || 0 === strpos( $img_val, 'https://' ) || 0 === strpos( $img_val, '//' ) ) ) {
			return -1;
		}

		return 0;
	}

	/**
	 * Parse comma-separated or JSON list frontmatter values into string arrays.
	 *
	 * @param mixed $val Array or string value.
	 * @return array List of string items.
	 */
	public static function parse_frontmatter_list( $val ) {
		if ( is_array( $val ) ) {
			return array_map( 'trim', array_map( 'strval', $val ) );
		}

		$val = trim( (string) $val );
		if ( '' === $val ) {
			return array();
		}

		if ( 0 === strpos( $val, '[' ) && ']' === substr( $val, -1 ) ) {
			$decoded = json_decode( $val, true );
			if ( is_array( $decoded ) ) {
				return array_map( 'trim', array_map( 'strval', $decoded ) );
			}
		}

		return array_map( 'trim', explode( ',', $val ) );
	}

	/**
	 * Assign categories to a post from frontmatter value.
	 *
	 * @param int   $post_id        Post ID.
	 * @param mixed $categories_val Categories string or array.
	 */
	public static function assign_post_categories( $post_id, $categories_val ) {
		$cat_list = self::parse_frontmatter_list( $categories_val );
		$term_ids = array();
		foreach ( $cat_list as $cat_path ) {
			if ( '' === $cat_path ) {
				continue;
			}
			$term_id = self::resolve_category_path_to_term_id( $cat_path );
			if ( $term_id > 0 ) {
				$term_ids[] = $term_id;
			}
		}
		wp_set_object_terms( $post_id, array_unique( $term_ids ), 'category' );
	}

	/**
	 * Assign tags to a post from frontmatter value.
	 *
	 * @param int   $post_id  Post ID.
	 * @param mixed $tags_val Tags string or array.
	 */
	public static function assign_post_tags( $post_id, $tags_val ) {
		$tag_list = self::parse_frontmatter_list( $tags_val );
		wp_set_post_tags( $post_id, $tag_list, false );
	}
}
