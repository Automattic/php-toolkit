<?php

use WordPress\DataLiberation\DataFormatConsumer\BlocksWithMetadata;
use WordPress\Filesystem\WpdbFilesystem;
use WordPress\Git\GitEndpoint;
use WordPress\Git\GitRepository;
use WordPress\Git\Model\Commit;
use WordPress\Git\Model\TreeEntry;
use WordPress\Markdown\MarkdownConsumer;
use WordPress\Markdown\MarkdownProducer;

/**
 * WP Origin – exposes WordPress as a Git remote.
 *
 * Persistence model: the GitRepository is backed directly by a
 * WpdbFilesystem instance, so every Git object, ref, and config entry the
 * server creates lives in two `{$wpdb->prefix}wp_origin_*` tables. There
 * is no parallel CPT/manifest layer — Git's own object store is the
 * source of truth for repository history. WordPress posts remain the
 * source of truth for content.
 *
 * On every request the plugin:
 *   1. opens the persistent repository,
 *   2. exports current WordPress content to Markdown and creates a
 *      "Sync from WordPress" commit when the export drifts from the
 *      latest tree on `refs/heads/trunk`,
 *   3. dispatches the Smart HTTP request through GitEndpoint, and
 *   4. for pushes, walks the new commits and applies their Markdown
 *      changes back to WordPress.
 */
class WP_Origin_Plugin {
	const DEFAULT_BRANCH     = 'trunk';
	const ROUTE_NAMESPACE    = 'git/v1';
	const ROUTE_PATTERN      = '/md\.git(?P<path>/.*)?';
	const EPOCH_TIMESTAMP    = 946684800;
	const TABLE_PREFIX       = 'wp_origin_';
	const AGENT_SKILL_SOURCE = 'wp-origin';
	const AGENT_SKILL_SLUG   = 'wp-origin';
	const AGENT_SKILL_TITLE  = 'WP Origin AGENTS.md';

	public static $supported_post_types    = array( 'post', 'page' );
	public static $supported_post_statuses = array( 'publish', 'draft', 'pending', 'private', 'future' );

	private static $guideline_type_directories = array(
		'artifact'    => 'artifacts',
		'content'     => 'content',
		'instruction' => 'instructions',
		'memory'      => 'memories',
		'plan'        => 'plans',
		'skill'       => 'skills',
	);

	public static function bootstrap() {
		add_action( 'init', array( __CLASS__, 'install_default_agent_skill' ), 20 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'add_authentication_challenge' ), 10, 3 );
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'serve_git_response' ), 10, 4 );

		WP_Origin_Seeder::bootstrap();
		WP_Origin_Admin::bootstrap();
	}

	public static function on_activation() {
		self::install_default_agent_skill();
		WP_Origin_Seeder::on_activation();
	}

	public static function install_default_agent_skill() {
		if ( ! self::guidelines_available() || ! function_exists( 'wp_install_skill' ) ) {
			return;
		}

		wp_install_skill(
			self::AGENT_SKILL_SOURCE,
			self::AGENT_SKILL_TITLE,
			'Guide for coding agents working in a WP Origin checkout of a WordPress site.',
			self::get_default_agent_skill_content(),
			array(
				'post_name' => self::AGENT_SKILL_SLUG,
			)
		);
	}

	public static function get_supported_post_types() {
		$post_types = self::$supported_post_types;
		if ( self::guidelines_available() ) {
			$post_types[] = 'wp_guideline';
		}

		return $post_types;
	}

	private static function guidelines_available() {
		return post_type_exists( 'wp_guideline' ) && taxonomy_exists( 'wp_guideline_type' );
	}

	private static function get_default_agent_skill_content() {
		return <<<'SKILL'
# WP Origin AGENTS.md

## What This Repository Is

This repository is a Git checkout of a WordPress site exposed by WP Origin. WordPress remains the source of truth. The Git history in this clone is a working view for review, editing, and agent workflows.

## Repository Layout

- `post/{slug}.md` contains WordPress posts.
- `page/{slug}.md` contains WordPress pages.
- `wp_guideline/skills/{slug}/SKILL.md` contains coding-agent skills stored as Gutenberg Guidelines.
- `.agents/skills` and `.claude/skills` point to `wp_guideline/skills` for agent discovery.
- `AGENTS.md` and `CLAUDE.md` point to this guide.

## Pulling And Pushing

- `git pull` refreshes the checkout from the current WordPress site.
- `git push` applies supported Markdown changes back to WordPress.
- Pushed post and page changes create WordPress revisions.
- Deleted post or page files are trashed in WordPress rather than permanently deleted.
- If WordPress changed after your last pull, the push is rejected. Pull, review the diff, and then push again.

## Editing Rules

- Preserve post and page front matter unless you are intentionally changing that WordPress metadata.
- Guideline skill front matter is generated from WordPress fields. Keep the body focused on the guideline content.
- Preserve unsupported block markup, fenced `gutenberg` blocks, custom blocks, and raw HTML unless the user asks for a conversion.
- Use forward slashes in paths.
- Keep changes scoped to site content. This checkout does not represent plugin code, themes, uploads, or the full database.
SKILL;
	}

	public static function register_routes() {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE_PATTERN,
			array(
				'methods'             => WP_REST_Server::ALLMETHODS,
				'callback'            => array( __CLASS__, 'handle_rest_request' ),
				'permission_callback' => array( __CLASS__, 'check_permissions' ),
			)
		);
	}

	public static function check_permissions( WP_REST_Request $request ) {
		$git_path = self::build_git_path( $request );
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'wp_origin_auth_required',
				'Authentication required.',
				array( 'status' => 401 )
			);
		}

		if ( self::is_push_request( $git_path ) && ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'wp_origin_forbidden',
				'You do not have permission to push content changes.',
				array( 'status' => 403 )
			);
		}

		return true;
	}

	public static function handle_rest_request( WP_REST_Request $request ) {
		$previous_error_handler = set_error_handler( array( __CLASS__, 'throw_on_php_warning' ) ); // phpcs:ignore

		try {
			if ( ! WP_Origin_Seeder::is_ready() ) {
				WP_Origin_Seeder::drive( 5 );
			}

			if ( ! WP_Origin_Seeder::is_ready() ) {
				$response = new WP_Origin_Buffering_Response();
				$response->send_http_code( 503 );
				$response->send_header( 'Content-Type', 'text/plain; charset=utf-8' );
				$response->send_header( 'Cache-Control', 'no-cache' );
				$response->send_header( 'Retry-After', '15' );
				$response->append_bytes( WP_Origin_Seeder::not_ready_message() . "\n" );

				return $response->to_rest_response();
			}

			$repository = self::open_repository();
			self::sync_repository_from_wordpress( $repository );

			$git_path     = self::build_git_path( $request );
			$request_body = file_get_contents( 'php://input' );
			$current_head = $repository->get_branch_tip( 'refs/heads/' . self::DEFAULT_BRANCH );

			$push_header = null;
			if ( self::is_push_request( $git_path ) ) {
				$push_header = self::parse_push_header( $request_body );
				if ( false === $push_header ) {
					return self::build_protocol_error_response(
						'git-receive-pack',
						'Invalid push request.'
					);
				}

				if ( $push_header['old_oid'] !== $current_head ) {
					return self::build_protocol_error_response(
						'git-receive-pack',
						'Push rejected because the remote changed. Pull the latest changes and try again.'
					);
				}
			}

			$response = new WP_Origin_Buffering_Response();
			$endpoint = new GitEndpoint( $repository );
			$endpoint->handle_request( $git_path, $request_body, $response );

			if ( self::is_push_request( $git_path ) ) {
				try {
					$push_summary = self::apply_repository_changes_to_wordpress(
						$repository,
						$push_header['old_oid'],
						$push_header['new_oid']
					);
					$response->append_progress_messages( self::format_push_summary_messages( $push_summary ) );
				} catch ( Throwable $exception ) {
					return self::build_protocol_error_response(
						'git-receive-pack',
						self::get_throwable_message( $exception )
					);
				}
			}

			return $response->to_rest_response();
		} catch ( Throwable $exception ) {
			return new WP_Error(
				'wp_origin_error',
				self::get_throwable_message( $exception ),
				array( 'status' => 500 )
			);
		} finally {
			restore_error_handler();
		}
	}

	public static function serve_git_response( $served, $result, $request, $server ) {
		unset( $server );

		if ( ! $result instanceof WP_HTTP_Response ) {
			return $served;
		}

		$headers = $result->get_headers();
		if ( empty( $headers[ WP_Origin_Buffering_Response::MARKER_HEADER ] ) ) {
			return $served;
		}

		if ( ! headers_sent() ) {
			status_header( $result->get_status() );
			foreach ( $headers as $name => $value ) {
				if ( WP_Origin_Buffering_Response::MARKER_HEADER === $name ) {
					continue;
				}
				header( $name . ': ' . $value );
			}
		}

		echo $result->get_data();

		return true;
	}

	public static function add_authentication_challenge( $response, $server, $request ) {
		unset( $server );

		if ( 0 !== strpos( $request->get_route(), '/' . self::ROUTE_NAMESPACE . '/md.git' ) ) {
			return $response;
		}
		if ( ! $response instanceof WP_HTTP_Response ) {
			return $response;
		}
		if ( 401 !== $response->get_status() ) {
			return $response;
		}

		$response->header( 'WWW-Authenticate', 'Basic realm="WP Origin"' );

		return $response;
	}

	private static function build_protocol_error_response( $service, $message ) {
		$response = new WP_Origin_Buffering_Response();
		$response->send_header( 'Content-Type', 'application/x-' . $service . '-result' );
		$response->send_header( 'Cache-Control', 'no-cache' );
		$response->send_header( 'Git-Protocol', 'version=2' );
		$response->append_bytes(
			WordPress\Git\Protocol\GitProtocolEncoderPipe::encode_packet_line(
				'error ' . rtrim( $message ) . "\n",
				"\x03"
			) . '0000'
		);

		return $response->to_rest_response();
	}

	private static function build_git_path( WP_REST_Request $request ) {
		$path = $request->get_param( 'path' );
		if ( ! is_string( $path ) || '' === $path ) {
			$path = '/';
		}

		$query_params = $request->get_query_params();
		if ( '/info/refs' === $path && isset( $query_params['service'] ) ) {
			$path .= '?service=' . $query_params['service'];
		}

		return $path;
	}

	private static function is_push_request( $git_path ) {
		return '/git-receive-pack' === $git_path;
	}

	public static function drop_repository_tables() {
		global $wpdb;
		WpdbFilesystem::drop_tables( $wpdb, $wpdb->prefix . self::TABLE_PREFIX );
	}

	public static function open_repository() {
		global $wpdb;

		$repository = new GitRepository(
			WpdbFilesystem::create( $wpdb, $wpdb->prefix . self::TABLE_PREFIX ),
			array(
				'default_branch' => self::DEFAULT_BRANCH,
			)
		);

		if ( ! $repository->get_config_value( 'user.name' ) ) {
			$repository->set_config_value( 'user.name', get_option( 'blogname', 'WP Origin' ) );
		}
		if ( ! $repository->get_config_value( 'user.email' ) ) {
			$repository->set_config_value( 'user.email', get_option( 'admin_email', 'wp-origin@example.com' ) );
		}

		return $repository;
	}

	private static function sync_repository_from_wordpress( GitRepository $repository ) {
		$exported_files = self::export_wordpress_content();
		$head_oid       = $repository->get_branch_tip( 'refs/heads/' . self::DEFAULT_BRANCH );
		$existing_files = ( is_string( $head_oid ) && '' !== $head_oid && ! Commit::is_null_hash( $head_oid ) )
			? self::read_repository_entries_from_commit( $repository, $head_oid )
			: array();

		$updates          = array();
		$symlinks         = array();
		$deletes          = array();
		$commit_timestamp = self::EPOCH_TIMESTAMP;

		foreach ( $exported_files as $path => $entry ) {
			$content = $entry['content'];
			$mode    = $entry['mode'];
			$post    = $entry['post'];

			if ( ! isset( $existing_files[ $path ] ) || ! self::repository_entries_match( $existing_files[ $path ], $entry ) ) {
				if ( TreeEntry::FILE_MODE_SYMBOLIC_LINK === $mode ) {
					$symlinks[ $path ] = $content;
				} else {
					$updates[ $path ] = $content;
				}
			}

			if ( ! $post ) {
				continue;
			}

			$maybe_timestamp = self::timestamp_from_gmt_string( $post->post_modified_gmt );
			if ( false === $maybe_timestamp ) {
				$maybe_timestamp = self::timestamp_from_gmt_string( $post->post_date_gmt );
			}
			if ( false !== $maybe_timestamp ) {
				$commit_timestamp = max( $commit_timestamp, $maybe_timestamp );
			}
		}

		foreach ( $existing_files as $path => $contents ) {
			unset( $contents );
			if ( ! isset( $exported_files[ $path ] ) ) {
				$deletes[] = $path;
			}
		}

		if ( empty( $updates ) && empty( $symlinks ) && empty( $deletes ) ) {
			return;
		}

		$identity = self::get_repository_identity( $repository );
		$date     = gmdate( Commit::DATE_FORMAT, $commit_timestamp );
		$repository->commit(
			array(
				'updates'         => $updates,
				'create_symlinks' => $symlinks,
				'deletes'         => $deletes,
				'commit'          => array(
					'message'        => 'Sync from WordPress',
					'author'         => $identity,
					'author_date'    => $date,
					'committer'      => $identity,
					'committer_date' => $date,
				),
			)
		);
	}

	private static function export_wordpress_content() {
		$posts = get_posts(
			array(
				'post_type'      => self::get_export_post_types(),
				'post_status'    => self::$supported_post_statuses,
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		$files                  = array();
		$has_guideline_skills   = false;
		$agent_guide_skill_path = null;
		foreach ( $posts as $post ) {
			if ( ! current_user_can( 'read_post', $post->ID ) ) {
				continue;
			}

			$path    = self::build_markdown_path( $post );
			$content = self::export_post_to_markdown( $post );

			$files[ $path ] = array(
				'post'    => $post,
				'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
				'content' => $content,
			);

			if ( 'wp_guideline' === $post->post_type && self::is_guideline_skill_path( $path ) ) {
				$has_guideline_skills = true;
				if ( self::AGENT_SKILL_SOURCE === get_post_meta( $post->ID, 'guideline_source', true ) ) {
					$agent_guide_skill_path = $path;
				}
			}
		}

		if ( $has_guideline_skills ) {
			foreach ( self::get_agent_skills_directory_symlink_paths() as $symlink_path => $target ) {
				$files[ $symlink_path ] = array(
					'post'    => null,
					'mode'    => TreeEntry::FILE_MODE_SYMBOLIC_LINK,
					'content' => $target,
				);
			}
		}

		if ( $agent_guide_skill_path ) {
			foreach ( self::get_agent_entrypoint_symlink_paths( $agent_guide_skill_path ) as $symlink_path => $target ) {
				$files[ $symlink_path ] = array(
					'post'    => null,
					'mode'    => TreeEntry::FILE_MODE_SYMBOLIC_LINK,
					'content' => $target,
				);
			}
		}

		ksort( $files );

		return $files;
	}

	private static function get_export_post_types() {
		return self::get_supported_post_types();
	}

	public static function build_markdown_path( $post_or_type, $slug = null ) {
		if ( $post_or_type instanceof WP_Post ) {
			if ( 'wp_guideline' === $post_or_type->post_type ) {
				return self::build_guideline_markdown_path( $post_or_type );
			}

			return ltrim( $post_or_type->post_type . '/' . $post_or_type->post_name . '.md', '/' );
		}

		return ltrim( $post_or_type . '/' . $slug . '.md', '/' );
	}

	/**
	 * Convert a single WP_Post to its Markdown representation, the same
	 * way `export_wordpress_content()` does in bulk. Public so the
	 * seeder can reuse the conversion without duplicating logic.
	 */
	public static function export_post_to_markdown( WP_Post $post ) {
		if ( 'wp_guideline' === $post->post_type ) {
			if ( 'skill' === self::get_guideline_type_slug( $post->ID ) ) {
				return self::export_guideline_skill_to_markdown( $post );
			}

			return $post->post_content;
		}

		$metadata = array(
			'title'  => array( $post->post_title ),
			'date'   => array( self::format_post_date_for_frontmatter( $post ) ),
			'status' => array( self::frontmatter_status_from_post_status( $post->post_status ) ),
		);
		if ( '' !== trim( $post->post_excerpt ) ) {
			$metadata['description'] = array( $post->post_excerpt );
		}

		$producer = new MarkdownProducer(
			new BlocksWithMetadata(
				$post->post_content,
				$metadata
			)
		);

		return $producer->produce();
	}

	private static function export_guideline_skill_to_markdown( WP_Post $post ) {
		$frontmatter = array(
			'---',
			'name: ' . self::quote_yaml_scalar( $post->post_name ),
			'description: ' . self::quote_yaml_scalar( trim( $post->post_excerpt ) ),
			'---',
			'',
		);

		return implode( "\n", $frontmatter ) . ltrim( $post->post_content, "\r\n" );
	}

	private static function quote_yaml_scalar( $value ) {
		$encoded = wp_json_encode( (string) $value );
		if ( false === $encoded ) {
			return '""';
		}

		return $encoded;
	}

	private static function build_guideline_markdown_path( WP_Post $post ) {
		$type_slug = self::get_guideline_type_slug( $post->ID );
		$directory = self::guideline_type_to_directory( $type_slug );

		if ( 'skill' === $type_slug ) {
			return 'wp_guideline/' . $directory . '/' . $post->post_name . '/SKILL.md';
		}

		return 'wp_guideline/' . $directory . '/' . $post->post_name . '.md';
	}

	private static function get_guideline_type_slug( $post_id ) {
		if ( ! taxonomy_exists( 'wp_guideline_type' ) ) {
			return 'artifact';
		}

		$terms = get_the_terms( $post_id, 'wp_guideline_type' );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return 'artifact';
		}

		$known_types = array_keys( self::$guideline_type_directories );
		foreach ( $known_types as $known_type ) {
			foreach ( $terms as $term ) {
				if ( $known_type === $term->slug ) {
					return $term->slug;
				}
			}
		}

		return $terms[0]->slug;
	}

	private static function guideline_type_to_directory( $type_slug ) {
		if ( isset( self::$guideline_type_directories[ $type_slug ] ) ) {
			return self::$guideline_type_directories[ $type_slug ];
		}

		return sanitize_title( $type_slug ) . 's';
	}

	private static function guideline_directory_to_type( $directory ) {
		$type_slug = array_search( $directory, self::$guideline_type_directories, true );
		if ( false !== $type_slug ) {
			return $type_slug;
		}

		throw new Exception( 'Push rejected because the guideline type directory is not supported yet.' );
	}

	private static function get_agent_skills_directory_symlink_paths() {
		return array(
			'.agents/skills' => '../wp_guideline/skills',
			'.claude/skills' => '../wp_guideline/skills',
		);
	}

	private static function get_agent_entrypoint_symlink_paths( $skill_path ) {
		return array(
			'AGENTS.md' => $skill_path,
			'CLAUDE.md' => $skill_path,
		);
	}

	public static function repository_identity( GitRepository $repository ) {
		return self::get_repository_identity( $repository );
	}

	private static function apply_repository_changes_to_wordpress( GitRepository $repository, $old_commit, $new_commit ) {
		$commit_hashes = self::get_push_commit_hashes( $repository, $old_commit, $new_commit );
		$push_summary  = array();

		foreach ( $commit_hashes as $index => $commit_hash ) {
			// Conflict checks against WordPress's current modified_gmt only
			// make sense for the first commit in the push range — every
			// subsequent commit is being applied on top of the WP state we
			// just produced, so per-file modified_gmt comparisons would
			// spuriously fail.
			$push_summary = array_merge(
				$push_summary,
				self::apply_single_commit_to_wordpress( $repository, $commit_hash, 0 !== $index )
			);
		}

		return $push_summary;
	}

	private static function apply_single_commit_to_wordpress( GitRepository $repository, $commit_hash, $skip_modified_checks ) {
		$commit = $repository->read_object( $commit_hash )->as_commit();
		self::validate_push_commit( $repository, $commit );

		$parent_hash = empty( $commit->parents ) ? Commit::NULL_HASH : $commit->get_first_parent_hash();
		$old_files   = Commit::is_null_hash( $parent_hash )
			? array()
			: self::read_repository_entries_from_commit( $repository, $parent_hash );
		$new_files   = self::read_repository_entries_from_commit( $repository, $commit_hash );

		$updated_post_ids = array();
		$changes          = array();

		foreach ( $new_files as $path => $entry ) {
			if ( isset( $old_files[ $path ] ) && self::repository_entries_match( $old_files[ $path ], $entry ) ) {
				continue;
			}
			if ( TreeEntry::FILE_MODE_SYMBOLIC_LINK === $entry['mode'] ) {
				continue;
			}

			$applied = self::upsert_post_from_markdown(
				$path,
				$entry['content'],
				array( 'skip_modified_check' => $skip_modified_checks )
			);
			$post_id = $applied['post_id'];
			if ( $post_id ) {
				$updated_post_ids[ $post_id ] = true;
				$changes[]                    = $applied['change'];
			}
		}

		foreach ( $old_files as $path => $entry ) {
			if ( isset( $new_files[ $path ] ) ) {
				continue;
			}
			if ( TreeEntry::FILE_MODE_SYMBOLIC_LINK === $entry['mode'] ) {
				continue;
			}

			$metadata = self::parse_markdown_metadata( $entry['content'] );
			$post_id  = isset( $metadata['id'] ) ? intval( $metadata['id'] ) : 0;
			if ( ! $post_id ) {
				$post_id = self::find_post_id_by_path_metadata( $path, $metadata );
			}

			if ( ! $post_id || isset( $updated_post_ids[ $post_id ] ) ) {
				continue;
			}

			if ( ! $skip_modified_checks && isset( $metadata['modified_gmt'] ) ) {
				$current_modified = get_post_field( 'post_modified_gmt', $post_id );
				if ( $current_modified && $current_modified !== $metadata['modified_gmt'] ) {
					throw new Exception( 'Push rejected because a deleted post changed in WordPress. Pull the latest changes and try again.' );
				}
			}
			self::assert_can_edit_post( $post_id );

			$post      = get_post( $post_id );
			$changes[] = self::build_push_summary_item( 'trashed', $post, $path );

			if ( false === wp_trash_post( $post_id ) ) {
				throw new Exception( 'Push rejected because WordPress could not trash the deleted content.' );
			}
		}

		return $changes;
	}

	private static function get_push_commit_hashes( GitRepository $repository, $old_commit, $new_commit ) {
		if ( Commit::is_null_hash( $old_commit ) ) {
			$commit_hashes = array();
			$current_hash  = $new_commit;

			while ( ! Commit::is_null_hash( $current_hash ) ) {
				$commit_hashes[] = $current_hash;
				$commit          = $repository->read_object( $current_hash )->as_commit();
				if ( count( $commit->parents ) > 1 ) {
					throw new Exception( 'Push rejected because merge commits are not supported yet.' );
				}
				$current_hash = empty( $commit->parents ) ? Commit::NULL_HASH : $commit->get_first_parent_hash();
			}

			return array_reverse( $commit_hashes );
		}

		return array_reverse(
			$repository->get_commits_range(
				$new_commit,
				$old_commit,
				array(
					'include_ancestor' => false,
				)
			)
		);
	}

	private static function validate_push_commit( GitRepository $repository, Commit $commit ) {
		if ( count( $commit->parents ) > 1 ) {
			throw new Exception( 'Push rejected because merge commits are not supported yet.' );
		}

		if ( empty( $commit->parents ) ) {
			return;
		}

		$parent_commit = $repository->read_object( $commit->get_first_parent_hash() )->as_commit();
		if ( $parent_commit->tree === $commit->tree ) {
			throw new Exception( 'Push rejected because empty commits are not supported yet.' );
		}
	}

	private static function upsert_post_from_markdown( $path, $markdown, $options = array() ) {
		$post_type = self::path_to_post_type( $path );
		$slug      = self::path_to_slug( $path );
		if ( 'wp_guideline' === $post_type ) {
			return self::upsert_guideline_from_markdown( $path, $markdown, $options );
		}

		$consumer = new MarkdownConsumer( $markdown );
		$result   = $consumer->consume();
		$metadata = array();
		foreach ( $result->get_all_metadata() as $key => $value ) {
			$metadata[ $key ] = is_array( $value ) ? reset( $value ) : $value;
		}

		if ( isset( $metadata['type'] ) && $metadata['type'] !== $post_type ) {
			throw new Exception( 'Push rejected because the file post type does not match its directory.' );
		}
		if ( isset( $metadata['slug'] ) && $metadata['slug'] !== $slug ) {
			throw new Exception( 'Push rejected because the file slug does not match its filename.' );
		}
		$post_id = isset( $metadata['id'] ) ? intval( $metadata['id'] ) : 0;
		if ( $post_id && get_post( $post_id ) ) {
			$existing_post = get_post( $post_id );
		} else {
			$post_id       = self::find_post_id_by_path_metadata( $path, $metadata );
			$existing_post = $post_id ? get_post( $post_id ) : null;
		}
		$post_status = self::normalize_frontmatter_status(
			isset( $metadata['status'] ) ? $metadata['status'] : ( $existing_post ? $existing_post->post_status : 'draft' )
		);
		self::validate_post_status( $post_status, $post_type );

		if (
			$existing_post &&
			empty( $options['skip_modified_check'] ) &&
			isset( $metadata['modified_gmt'] ) &&
			$existing_post->post_modified_gmt !== $metadata['modified_gmt']
		) {
			throw new Exception( 'Push rejected because WordPress content changed since the last pull.' );
		}

		if ( $existing_post ) {
			self::assert_can_edit_post( $existing_post->ID );
		} else {
			self::assert_can_create_post_type( $post_type );
		}

		$postarr = array(
			'post_type'    => $post_type,
			'post_name'    => isset( $metadata['slug'] ) ? $metadata['slug'] : $slug,
			'post_title'   => isset( $metadata['title'] ) ? $metadata['title'] : ucwords( str_replace( '-', ' ', $slug ) ),
			'post_status'  => $post_status,
			'post_content' => $result->get_block_markup(),
		);

		$post_date_gmt = self::frontmatter_date_to_mysql_gmt( $metadata );
		if ( '' !== $post_date_gmt ) {
			$postarr['post_date_gmt'] = $post_date_gmt;
			$postarr['post_date']     = get_date_from_gmt( $post_date_gmt );
		}
		if ( array_key_exists( 'description', $metadata ) ) {
			$postarr['post_excerpt'] = $metadata['description'];
		}

		if ( $existing_post ) {
			$postarr['ID'] = $existing_post->ID;
			$post_id       = wp_update_post( wp_slash( $postarr ), true );
		} else {
			$post_id = wp_insert_post( wp_slash( $postarr ), true );
		}

		if ( is_wp_error( $post_id ) ) {
			throw new Exception( $post_id->get_error_message() );
		}

		$post = get_post( $post_id );

		return array(
			'post_id' => $post_id,
			'change'  => self::build_push_summary_item(
				$existing_post ? 'updated' : 'created',
				$post,
				$path
			),
		);
	}

	private static function upsert_guideline_from_markdown( $path, $markdown, $options = array() ) {
		if ( ! self::guidelines_available() ) {
			throw new Exception( 'Push rejected because Gutenberg Guidelines are not available on this site.' );
		}

		$slug                = self::path_to_slug( $path );
		$guideline_type_slug = self::path_to_guideline_type_slug( $path );
		$metadata            = array();
		if ( 'skill' === $guideline_type_slug ) {
			$skill_document = self::split_guideline_skill_markdown( $markdown );
			$metadata       = $skill_document['metadata'];
			$markdown       = $skill_document['content'];
			if ( isset( $metadata['name'] ) && $metadata['name'] !== $slug ) {
				throw new Exception( 'Push rejected because the skill name front matter does not match its directory.' );
			}
		}

		$post_id = isset( $metadata['id'] ) ? intval( $metadata['id'] ) : 0;
		if ( $post_id && get_post( $post_id ) ) {
			$existing_post = get_post( $post_id );
		} else {
			$post_id       = self::find_post_id_by_path_metadata( $path, $metadata );
			$existing_post = $post_id ? get_post( $post_id ) : null;
		}

		$post_status = self::normalize_frontmatter_status(
			isset( $metadata['status'] ) ? $metadata['status'] : ( $existing_post ? $existing_post->post_status : 'draft' )
		);
		self::validate_post_status( $post_status, 'wp_guideline' );

		if (
			$existing_post &&
			empty( $options['skip_modified_check'] ) &&
			isset( $metadata['modified_gmt'] ) &&
			$existing_post->post_modified_gmt !== $metadata['modified_gmt']
		) {
			throw new Exception( 'Push rejected because WordPress content changed since the last pull.' );
		}

		if ( $existing_post ) {
			self::assert_can_edit_post( $existing_post->ID );
		} else {
			self::assert_can_create_post_type( 'wp_guideline' );
		}

		$postarr = array(
			'post_type'    => 'wp_guideline',
			'post_name'    => $slug,
			'post_title'   => self::guideline_title_from_metadata( $metadata, $slug, $existing_post ),
			'post_status'  => $post_status,
			'post_content' => $markdown,
		);

		if ( array_key_exists( 'description', $metadata ) ) {
			$postarr['post_excerpt'] = $metadata['description'];
		}

		if ( $existing_post ) {
			$postarr['ID'] = $existing_post->ID;
			$post_id       = wp_update_post( wp_slash( $postarr ), true );
		} else {
			$post_id = wp_insert_post( wp_slash( $postarr ), true );
		}

		if ( is_wp_error( $post_id ) ) {
			throw new Exception( $post_id->get_error_message() );
		}

		$term_id   = self::get_or_create_guideline_type_term_id( $guideline_type_slug );
		$set_terms = wp_set_object_terms( $post_id, array( $term_id ), 'wp_guideline_type' );
		if ( is_wp_error( $set_terms ) ) {
			throw new Exception( $set_terms->get_error_message() );
		}

		$post = get_post( $post_id );

		return array(
			'post_id' => $post_id,
			'change'  => self::build_push_summary_item(
				$existing_post ? 'updated' : 'created',
				$post,
				$path
			),
		);
	}

	private static function build_push_summary_item( $action, $post, $path ) {
		$post_id = $post ? $post->ID : 0;

		return array(
			'action'    => $action,
			'post_id'   => $post_id,
			'post_type' => $post ? $post->post_type : self::path_to_post_type( $path ),
			'status'    => $post ? $post->post_status : '',
			'title'     => $post ? get_the_title( $post ) : '',
			'url'       => $post_id ? get_permalink( $post_id ) : '',
			'path'      => $path,
		);
	}

	private static function format_push_summary_messages( $push_summary ) {
		if ( empty( $push_summary ) ) {
			return array();
		}

		$messages   = array();
		$messages[] = sprintf(
			'WP Origin applied %d content %s:',
			count( $push_summary ),
			1 === count( $push_summary ) ? 'change' : 'changes'
		);

		foreach ( $push_summary as $change ) {
			$messages[] = sprintf(
				'- %s %s: %s',
				ucfirst( $change['action'] ),
				$change['post_type'],
				self::sanitize_push_summary_text( $change['url'] ? $change['url'] : $change['path'] )
			);
		}

		return $messages;
	}

	private static function sanitize_push_summary_text( $text ) {
		return str_replace( array( "\r", "\n" ), ' ', (string) $text );
	}

	private static function get_repository_identity( GitRepository $repository ) {
		return $repository->get_config_value( 'user.name' ) . ' <' . $repository->get_config_value( 'user.email' ) . '>';
	}

	private static function timestamp_from_gmt_string( $gmt_string ) {
		if ( ! is_string( $gmt_string ) || '' === $gmt_string || '0000-00-00 00:00:00' === $gmt_string ) {
			return false;
		}

		return strtotime( $gmt_string . ' UTC' );
	}

	private static function format_post_date_for_frontmatter( WP_Post $post ) {
		$timestamp = self::timestamp_from_gmt_string( $post->post_date_gmt );
		if (
			false === $timestamp &&
			is_string( $post->post_date ) &&
			'' !== $post->post_date &&
			'0000-00-00 00:00:00' !== $post->post_date
		) {
			$timestamp = self::timestamp_from_gmt_string( get_gmt_from_date( $post->post_date ) );
		}
		if ( false === $timestamp ) {
			$timestamp = self::EPOCH_TIMESTAMP;
		}

		return gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
	}

	private static function frontmatter_date_to_mysql_gmt( $metadata ) {
		if ( isset( $metadata['date'] ) && '' !== trim( (string) $metadata['date'] ) ) {
			return self::parse_frontmatter_date( $metadata['date'] );
		}
		if ( isset( $metadata['date_gmt'] ) && '' !== trim( (string) $metadata['date_gmt'] ) ) {
			$timestamp = self::timestamp_from_gmt_string( $metadata['date_gmt'] );

			return false === $timestamp ? '' : gmdate( 'Y-m-d H:i:s', $timestamp );
		}

		return '';
	}

	private static function parse_frontmatter_date( $date ) {
		$date = trim( (string) $date );
		if ( '' === $date ) {
			return '';
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$timestamp = strtotime( $date . ' 00:00:00 UTC' );
		} elseif ( preg_match( '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$/', $date ) ) {
			$timestamp = strtotime( $date . ' UTC' );
		} else {
			$timestamp = strtotime( $date );
		}

		return false === $timestamp ? '' : gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	private static function frontmatter_status_from_post_status( $post_status ) {
		$statuses = array(
			'publish' => 'published',
			'future'  => 'scheduled',
		);

		return isset( $statuses[ $post_status ] ) ? $statuses[ $post_status ] : $post_status;
	}

	private static function normalize_frontmatter_status( $post_status ) {
		if ( ! is_string( $post_status ) ) {
			return $post_status;
		}

		$statuses = array(
			'published' => 'publish',
			'publish'   => 'publish',
			'scheduled' => 'future',
			'future'    => 'future',
			'draft'     => 'draft',
			'pending'   => 'pending',
			'private'   => 'private',
		);
		$key      = strtolower( trim( $post_status ) );

		return isset( $statuses[ $key ] ) ? $statuses[ $key ] : $post_status;
	}

	private static function find_post_id_by_path_metadata( $path, $metadata ) {
		$post_type = self::path_to_post_type( $path );
		$slug      = isset( $metadata['slug'] ) ? $metadata['slug'] : self::path_to_slug( $path );
		$posts     = get_posts(
			array(
				'post_type'      => $post_type,
				'name'           => $slug,
				'post_status'    => array_merge( self::$supported_post_statuses, array( 'trash' ) ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		if ( empty( $posts ) ) {
			return 0;
		}

		return intval( $posts[0] );
	}

	private static function path_to_post_type( $path ) {
		$segments = explode( '/', ltrim( $path, '/' ) );
		if ( ! empty( $segments[0] ) && 'wp_guideline' === $segments[0] && ! self::guidelines_available() ) {
			throw new Exception( 'Push rejected because Gutenberg Guidelines are not available on this site.' );
		}
		if ( empty( $segments[0] ) || ! in_array( $segments[0], self::get_supported_post_types(), true ) ) {
			throw new Exception( 'Push rejected because the file path is outside the supported post type directories.' );
		}
		if ( 'wp_guideline' === $segments[0] ) {
			self::path_to_guideline_type_slug( $path );
		}

		return $segments[0];
	}

	private static function path_to_slug( $path ) {
		if ( self::is_guideline_skill_path( $path ) ) {
			$segments = explode( '/', ltrim( $path, '/' ) );
			return $segments[2];
		}

		$basename = basename( $path );
		if ( 'md' !== pathinfo( $basename, PATHINFO_EXTENSION ) ) {
			throw new Exception( 'Push rejected because only Markdown files are supported.' );
		}

		return pathinfo( $basename, PATHINFO_FILENAME );
	}

	private static function path_to_guideline_type_slug( $path ) {
		$segments = explode( '/', ltrim( $path, '/' ) );
		if (
			count( $segments ) < 3 ||
			'wp_guideline' !== $segments[0] ||
			! in_array( $segments[1], self::$guideline_type_directories, true )
		) {
			throw new Exception( 'Push rejected because guideline files must live under wp_guideline/<type> directories.' );
		}

		$type_slug = self::guideline_directory_to_type( $segments[1] );
		if ( 'skill' === $type_slug ) {
			if ( 4 !== count( $segments ) || 'SKILL.md' !== $segments[3] ) {
				throw new Exception( 'Push rejected because guideline skills must use wp_guideline/skills/<name>/SKILL.md.' );
			}
			return $type_slug;
		}

		if ( 3 !== count( $segments ) || 'md' !== pathinfo( $segments[2], PATHINFO_EXTENSION ) ) {
			throw new Exception( 'Push rejected because guideline files must be Markdown files.' );
		}

		return $type_slug;
	}

	private static function is_guideline_skill_path( $path ) {
		$segments = explode( '/', ltrim( $path, '/' ) );

		return 4 === count( $segments )
			&& 'wp_guideline' === $segments[0]
			&& 'skills' === $segments[1]
			&& '' !== $segments[2]
			&& 'SKILL.md' === $segments[3];
	}

	private static function parse_markdown_metadata( $markdown ) {
		$consumer = new MarkdownConsumer( $markdown );
		$result   = $consumer->consume();
		$metadata = array();
		foreach ( $result->get_all_metadata() as $key => $value ) {
			$metadata[ $key ] = is_array( $value ) ? reset( $value ) : $value;
		}

		return $metadata;
	}

	private static function split_guideline_skill_markdown( $markdown ) {
		$metadata = array();
		$content  = $markdown;

		if ( preg_match( '/\A---\r?\n.*?\r?\n---(?:\r?\n|\z)/s', $markdown, $matches ) ) {
			$metadata = self::parse_markdown_metadata( $markdown );
			$content  = substr( $markdown, strlen( $matches[0] ) );
		}

		return array(
			'metadata' => $metadata,
			'content'  => $content,
		);
	}

	private static function guideline_title_from_metadata( $metadata, $slug, $existing_post = null ) {
		if ( isset( $metadata['title'] ) && '' !== trim( (string) $metadata['title'] ) ) {
			return $metadata['title'];
		}
		if ( $existing_post ) {
			return $existing_post->post_title;
		}
		if ( isset( $metadata['name'] ) && '' !== trim( (string) $metadata['name'] ) ) {
			return ucwords( str_replace( '-', ' ', $metadata['name'] ) );
		}

		return ucwords( str_replace( '-', ' ', $slug ) );
	}

	private static function get_or_create_guideline_type_term_id( $slug ) {
		$term = get_term_by( 'slug', $slug, 'wp_guideline_type' );
		if ( $term ) {
			return (int) $term->term_id;
		}

		$inserted = wp_insert_term(
			ucwords( str_replace( '-', ' ', $slug ) ),
			'wp_guideline_type',
			array( 'slug' => $slug )
		);

		if ( is_wp_error( $inserted ) ) {
			throw new Exception( $inserted->get_error_message() );
		}

		return (int) $inserted['term_id'];
	}

	private static function validate_post_status( $post_status, $post_type ) {
		if ( in_array( $post_status, self::$supported_post_statuses, true ) ) {
			return;
		}

		throw new Exception(
			sprintf(
				'Push rejected because "%s" is not a supported %s status.',
				$post_status,
				$post_type
			)
		);
	}

	private static function read_repository_entries_from_commit( GitRepository $repository, $commit_hash ) {
		$commit = $repository->read_object( $commit_hash )->as_commit();
		$files  = array();

		if ( Commit::is_null_hash( $commit->tree ) ) {
			return $files;
		}

		self::collect_tree_entries( $repository, $commit->tree, '', $files );
		ksort( $files );

		return $files;
	}

	private static function repository_entries_match( $a, $b ) {
		return isset( $a['mode'], $a['content'], $b['mode'], $b['content'] )
			&& $a['mode'] === $b['mode']
			&& $a['content'] === $b['content'];
	}

	private static function collect_tree_entries( GitRepository $repository, $tree_hash, $prefix, &$files ) {
		$tree = $repository->read_object( $tree_hash )->as_tree();
		foreach ( $tree->entries as $entry ) {
			$path = ltrim( $prefix . '/' . $entry->name, '/' );
			if ( TreeEntry::FILE_MODE_DIRECTORY === $entry->get_mode_bucket() ) {
				self::collect_tree_entries( $repository, $entry->hash, $path, $files );
				continue;
			}
			if (
				TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE !== $entry->get_mode_bucket() &&
				TreeEntry::FILE_MODE_REGULAR_EXECUTABLE !== $entry->get_mode_bucket() &&
				TreeEntry::FILE_MODE_SYMBOLIC_LINK !== $entry->get_mode_bucket()
			) {
				throw new Exception( 'Push rejected because one or more repository entries use an unsupported Git file mode.' );
			}
			$files[ $path ] = array(
				'mode'    => $entry->get_mode_bucket(),
				'content' => $repository->read_object( $entry->hash )->consume_all(),
			);
		}
	}

	private static function parse_push_header( $request_bytes ) {
		if ( ! preg_match( '/([0-9a-f]{40}) ([0-9a-f]{40}) refs\\/heads\\/' . self::DEFAULT_BRANCH . '/', $request_bytes, $matches ) ) {
			return false;
		}

		return array(
			'old_oid' => $matches[1],
			'new_oid' => $matches[2],
		);
	}

	private static function assert_can_edit_post( $post_id ) {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			throw new Exception( 'Push rejected because you do not have permission to edit one or more posts in this change.' );
		}
	}

	private static function assert_can_create_post_type( $post_type ) {
		$post_type_object = get_post_type_object( $post_type );
		$edit_posts_cap   = $post_type_object && isset( $post_type_object->cap->edit_posts ) ? $post_type_object->cap->edit_posts : 'edit_posts';
		if ( ! current_user_can( $edit_posts_cap ) ) {
			throw new Exception( 'Push rejected because you do not have permission to create this post type.' );
		}
	}

	private static function get_throwable_message( Throwable $throwable ) {
		$message = $throwable->getMessage();
		if ( '' === $message && isset( $throwable->code_str ) && is_string( $throwable->code_str ) && '' !== $throwable->code_str ) {
			$message = $throwable->code_str;
		}
		if ( '' === $message ) {
			$message = get_class( $throwable );
		}

		return $message;
	}

	public static function throw_on_php_warning( $severity, $message, $file, $line ) {
		if ( 0 === ( error_reporting() & $severity ) ) { // phpcs:ignore
			return false;
		}

		throw new ErrorException( $message, 0, $severity, $file, $line );
	}
}
