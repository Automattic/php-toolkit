<?php

use WordPress\DataLiberation\DataFormatConsumer\BlocksWithMetadata;
use WordPress\Filesystem\WpdbFilesystem;
use WordPress\Git\GitEndpoint;
use WordPress\Git\GitRepository;
use WordPress\Git\Model\Commit;
use WordPress\Git\Model\TreeEntry;
use WordPress\Markdown\MarkdownConsumer;
use WordPress\Markdown\MarkdownProducer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Push MD – exposes WordPress as a Git remote.
 *
 * Persistence model: the GitRepository is backed directly by a
 * WpdbFilesystem instance, so every Git object, ref, and config entry the
 * server creates lives in two `{$wpdb->prefix}pmd_*` tables. There
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
class PMD_Plugin {
	const DEFAULT_BRANCH               = 'trunk';
	const ROUTE_NAMESPACE              = 'git/v1';
	const ROUTE_PATTERN                = '/md\.git(?P<path>/.*)?';
	const EPOCH_TIMESTAMP              = 946684800;
	const TABLE_PREFIX                 = 'pmd_';
	const AGENT_SKILL_SOURCE           = 'push-md';
	const AGENT_SKILL_SLUG             = 'push-md';
	const AGENT_SKILL_TITLE            = 'Push MD AGENTS.md';
	const TEMPLATE_EDITOR_SKILL_SOURCE = 'push-md-template-editor';
	const TEMPLATE_EDITOR_SKILL_SLUG   = 'push-md-template-editor';
	const TEMPLATE_EDITOR_SKILL_TITLE  = 'Push MD Template Editor';
	const THEME_BASE_REF               = 'refs/remotes/push-md/theme-base';
	const THEME_BASE_COMMIT_MESSAGE    = 'Initial theme base from WordPress';
	const THEME_BASE_SYNC_MESSAGE      = 'Sync theme base from WordPress';
	const WORDPRESS_SYNC_MESSAGE       = 'Sync from WordPress';

	public static $supported_post_types    = array( 'post', 'page' );
	public static $supported_post_statuses = array( 'publish', 'draft', 'pending', 'private', 'future' );

	private static $raw_block_post_types = array( 'wp_template', 'wp_template_part', 'wp_navigation' );

	private static $theme_scoped_raw_block_post_types = array( 'wp_template', 'wp_template_part' );

	private static $json_post_types = array( 'wp_global_styles' );

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

		PMD_Seeder::bootstrap();
		PMD_Admin::bootstrap();
	}

	public static function on_activation() {
		self::install_default_agent_skill();
		PMD_Seeder::on_activation();
	}

	public static function install_default_agent_skill() {
		if ( ! self::guidelines_available() || ! function_exists( 'wp_install_skill' ) ) {
			return;
		}

		wp_install_skill(
			self::AGENT_SKILL_SOURCE,
			self::AGENT_SKILL_TITLE,
			'Guide for coding agents working in a Push MD checkout of a WordPress site.',
			self::get_default_agent_skill_content(),
			array(
				'post_name' => self::AGENT_SKILL_SLUG,
			)
		);

		wp_install_skill(
			self::TEMPLATE_EDITOR_SKILL_SOURCE,
			self::TEMPLATE_EDITOR_SKILL_TITLE,
			'Edit Push MD block theme templates and template parts as raw Gutenberg HTML while preserving Site Editor compatibility.',
			self::get_default_template_editor_skill_content(),
			array(
				'post_name' => self::TEMPLATE_EDITOR_SKILL_SLUG,
			)
		);
	}

	public static function get_supported_post_types() {
		$post_types = array_merge(
			self::$supported_post_types,
			self::get_existing_raw_block_post_types(),
			self::get_existing_json_post_types()
		);
		if ( self::guidelines_available() ) {
			$post_types[] = 'wp_guideline';
		}

		return $post_types;
	}

	private static function guidelines_available() {
		return post_type_exists( 'wp_guideline' ) && taxonomy_exists( 'wp_guideline_type' );
	}

	private static function guidelines_enabled() {
		if ( self::guidelines_available() ) {
			return true;
		}

		$experiments = get_option( 'gutenberg-experiments' );

		return is_array( $experiments ) && ! empty( $experiments['gutenberg-guidelines'] );
	}

	private static function get_default_agent_skill_content() {
		return <<<'SKILL'
# Push MD AGENTS.md

## What This Repository Is

This repository is a Git checkout of a WordPress site exposed by Push MD. WordPress remains the source of truth. The Git history in this clone is a working view for review, editing, and agent workflows.

## Repository Layout

- `post/{slug}.md` contains WordPress posts.
- `page/{slug}.md` and `page/{parent}/{slug}.md` contain WordPress pages.
- `wp_template/{slug}.html`, `wp_template_part/{slug}.html`, and `wp_navigation/{slug}.html` contain raw Gutenberg block markup for structural site entities. Theme-qualified WordPress slugs may appear as nested paths such as `wp_template_part/{theme}/header.html`.
- `wp_theme/{theme}/theme.json` contains read-only theme-provided design settings for agent context.
- `wp_global_styles/{theme}.json` contains the editable Global Styles overlay for the active theme. Edit this file for site-wide styles and settings instead of editing `wp_theme/{theme}/theme.json`.
- `wp_guideline/skills/{slug}/SKILL.md` contains coding-agent skills stored as Gutenberg Guidelines.
- `.agents/skills` and `.claude/skills` point to `wp_guideline/skills` for agent discovery.
- `AGENTS.md` and `CLAUDE.md` point to this guide.

## Pulling And Pushing

- `git pull` refreshes the checkout from the current WordPress site.
- `git push` applies supported Markdown changes back to WordPress.
- Pushed post and page changes create WordPress revisions.
- Post and page front matter may include `id` so a file rename updates the same WordPress object.
- Deleted post or page files are trashed in WordPress rather than permanently deleted.
- If WordPress changed after your last pull, the push is rejected. Pull, review the diff, and then push again.

## Editing Rules

- Preserve post and page front matter unless you are intentionally changing that WordPress metadata.
- Guideline skill front matter is generated from WordPress fields. Keep the body focused on the guideline content.
- Template HTML files must stay raw Gutenberg block markup without front matter.
- Template HTML files may be created or updated, but deletes and renames are rejected because their paths are their WordPress identity.
- Theme base files are checked out for context. Editing theme-provided templates creates WordPress customizations; `wp_theme/{theme}/theme.json` is read-only in this checkout.
- Global Styles JSON files may be created or updated, but deletes and renames are rejected. `wp_global_styles/{theme}.json` is the database overlay for `wp_theme/{theme}/theme.json`.
- Keep theme-scoped templates in their nested paths. For example, edit `wp_template_part/twentytwentyfive/footer.html`; do not create flattened files such as `wp_template_part/twentytwentyfive-footer.html`.
- A path such as `wp_template_part/twentytwentyfive/footer.html` maps to the WordPress template-part ID `twentytwentyfive//footer`. The customized database post keeps the slug `footer` and stores `twentytwentyfive` in the `wp_theme` taxonomy.
- Use the `push-md-template-editor` skill before editing `wp_template/*.html`, `wp_template_part/*.html`, or `wp_navigation/*.html`.
- Preserve unsupported block markup, fenced `gutenberg` blocks, custom blocks, and raw HTML unless the user asks for a conversion.
- After template or Global Styles edits, run `git status --short` and confirm there are no unintended deleted files, renamed files, `wp_theme` changes, or flattened theme paths.
- Use forward slashes in paths.
- Keep changes scoped to site content. This checkout does not represent plugin code, themes, uploads, or the full database.
SKILL;
	}

	private static function get_default_template_editor_skill_content() {
		return <<<'SKILL'
# Push MD Template Editor

Use this skill when editing `wp_template/*.html`, `wp_template_part/*.html`, or `wp_navigation/*.html` in a Push MD checkout.

## What These Files Are

Template HTML files are WordPress structural block entities exported as raw serialized Gutenberg block markup. WordPress remains the source of truth for IDs, titles, status, dates, theme ownership, and other administrative metadata. The file path is the working identity in Git.

Theme-provided templates and template parts appear in this checkout even before they have been customized in WordPress. Editing and pushing one of those files creates or updates the WordPress customization for that path. Theme-provided `wp_theme/{theme}/theme.json` files are exported for context and are read-only. Use `wp_global_styles/{theme}.json` for editable site-wide style and settings changes.

Theme-scoped paths are a filesystem view of WordPress template IDs. For example, `wp_template_part/twentytwentyfive/footer.html` maps to the template-part ID `twentytwentyfive//footer`. When customized, WordPress stores this as a `wp_template_part` post whose slug remains `footer` and whose `wp_theme` taxonomy term is `twentytwentyfive`.

## Hard Rules

- Keep files as raw Gutenberg block HTML. Do not add Markdown or YAML front matter.
- Create and update template HTML files only. Do not delete or rename them.
- Treat the path as identity. A nested path such as `wp_template_part/{theme}/header.html` maps back to a theme-qualified WordPress slug.
- Keep the theme as a directory segment. Do not flatten theme-scoped paths into files such as `wp_template_part/twentytwentyfive-footer.html` or `wp_template/twentytwentyfive-index.html`.
- Edit theme-provided templates in place. Do not copy them to top-level files unless the user explicitly wants a different non-theme-scoped entity.
- Do not edit `wp_theme/{theme}/theme.json`; use it to understand theme colors, spacing, typography, and layout settings. Edit `wp_global_styles/{theme}.json` when the user asks for site-wide theme.json-style changes.
- Preserve unknown blocks, custom blocks, and existing block attributes unless the user explicitly asks to change them.
- Preserve Gutenberg block comments. They are the block schema, not decorative comments.
- Do not create theme, plugin, upload, or database files. Push MD exposes content entities only.
- Use forward slashes in paths.

## Block Theme Markup Rules

- Prefer editable core blocks such as `core/group`, `core/columns`, `core/heading`, `core/paragraph`, `core/image`, `core/query`, `core/post-title`, `core/post-content`, `core/navigation`, and `core/template-part`.
- Avoid adding `core/html` blocks for normal layout, text, or visual wrappers. Use `core/html` only when the user explicitly needs opaque custom HTML.
- Reference reusable areas with `core/template-part` blocks instead of duplicating header, footer, or navigation markup across templates.
- Keep template parts focused: headers in `wp_template_part/header.html` or `wp_template_part/{theme}/header.html`, footers in `wp_template_part/footer.html` or `wp_template_part/{theme}/footer.html`, and navigation in `wp_navigation/*.html` when navigation entities are available.
- For full-width sections, use WordPress-native alignment attributes instead of CSS-only breakout tricks.

## Full-Width Section Pattern

Use an outer full-width group and an inner wide content shell:

```html
<!-- wp:group {"align":"full","layout":{"type":"default"}} -->
<div class="wp-block-group alignfull">
	<!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->
	<div class="wp-block-group alignwide">
		<!-- wp:heading -->
		<h2 class="wp-block-heading">Section heading</h2>
		<!-- /wp:heading -->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
```

Use the site's existing markup style when it differs, but keep alignment in block attributes so the Site Editor and front end agree.

## Editing Workflow

- Pull before editing if the user has not already done so.
- Make the smallest template change that satisfies the request.
- Check nearby templates and parts before duplicating structure.
- Run `git status --short` before committing or pushing, and verify template edits stayed on the intended `.html` path with no deletes, renames, flattened theme files, or `wp_theme` changes.
- After a successful push, pull again if WordPress normalizes or rewrites the exported markup.
- If a change would require theme files, plugin code, CSS assets, or database settings that are not represented in this checkout, tell the user instead of inventing files.
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
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'pmd_auth_required',
				'Authentication required.',
				array( 'status' => 401 )
			);
		}

		if ( ! self::current_user_can_read_exported_content() ) {
			return new WP_Error(
				'pmd_forbidden',
				'You do not have permission to read all Push MD content.',
				array( 'status' => 403 )
			);
		}

		return true;
	}

	private static function current_user_can_read_exported_content() {
		if ( current_user_can( 'edit_others_posts' ) ) {
			return true;
		}

		$post_ids = get_posts(
			array(
				'post_type'      => self::get_export_post_types(),
				'post_status'    => self::$supported_post_statuses,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		foreach ( $post_ids as $post_id ) {
			if ( ! current_user_can( 'read_post', intval( $post_id ) ) ) {
				return false;
			}
		}

		return true;
	}

	public static function handle_rest_request( WP_REST_Request $request ) {
		$previous_error_handler = set_error_handler( array( __CLASS__, 'throw_on_php_warning' ) ); // phpcs:ignore
		$git_path               = '';

		try {
			$git_path = self::build_git_path( $request );
			if ( is_wp_error( $git_path ) ) {
				return $git_path;
			}

			if ( ! PMD_Seeder::is_ready() ) {
				PMD_Seeder::drive( 5 );
			}

			if ( ! PMD_Seeder::is_ready() ) {
				$response = new PMD_Buffering_Response();
				$response->send_http_code( 503 );
				$response->send_header( 'Content-Type', 'text/plain; charset=utf-8' );
				$response->send_header( 'Cache-Control', 'no-cache' );
				$response->send_header( 'Retry-After', '15' );
				$response->append_bytes( PMD_Seeder::not_ready_message() . "\n" );

				return $response->to_rest_response();
			}

			$request_body = file_get_contents( 'php://input' );
			$repository   = self::open_repository();
			try {
				self::sync_repository_from_wordpress( $repository );
			} catch ( Throwable $exception ) {
				$service = self::git_service_from_request( $git_path, $request );
				if ( $service ) {
					return self::build_protocol_error_response(
						$service,
						self::get_throwable_message( $exception )
					);
				}

				throw $exception;
			}

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
				if ( isset( $push_header['error'] ) ) {
					return self::build_protocol_error_response(
						'git-receive-pack',
						$push_header['error']
					);
				}

				if ( $push_header['old_oid'] !== $current_head ) {
					return self::build_protocol_error_response(
						'git-receive-pack',
						'Push rejected because the remote changed. Pull the latest changes and try again.'
					);
				}
			}

			$response = new PMD_Buffering_Response();
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
					self::rollback_rejected_push_ref( $repository, $push_header );

					return self::build_protocol_error_response(
						'git-receive-pack',
						self::get_throwable_message( $exception )
					);
				}
			}

			return $response->to_rest_response();
		} catch ( Throwable $exception ) {
			$service = self::git_service_from_request( $git_path, $request );
			if ( $service ) {
				return self::build_protocol_error_response(
					$service,
					self::get_throwable_message( $exception )
				);
			}

			return new WP_Error(
				'pmd_error',
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
		if ( empty( $headers[ PMD_Buffering_Response::MARKER_HEADER ] ) ) {
			return $served;
		}

		if ( ! headers_sent() ) {
			status_header( $result->get_status() );
			foreach ( $headers as $name => $value ) {
				if ( PMD_Buffering_Response::MARKER_HEADER === $name ) {
					continue;
				}
				header( $name . ': ' . $value );
			}
		}

		echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Git Smart HTTP response bodies are binary protocol data; escaping corrupts them.

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

		$response->header( 'WWW-Authenticate', 'Basic realm="Push MD"' );

		return $response;
	}

	private static function build_protocol_error_response( $service, $message ) {
		$response = new PMD_Buffering_Response();
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
			$service = self::normalize_git_service( $query_params['service'] );
			if ( '' === $service ) {
				return new WP_Error(
					'pmd_invalid_git_service',
					'Unsupported Git service requested.',
					array( 'status' => 400 )
				);
			}

			$path .= '?service=' . $service;
		}

		return $path;
	}

	private static function normalize_git_service( $service ) {
		if ( ! is_string( $service ) ) {
			return '';
		}

		if ( 'git-upload-pack' === $service || 'git-receive-pack' === $service ) {
			return $service;
		}

		return '';
	}

	private static function git_service_from_request( $git_path, WP_REST_Request $request ) {
		unset( $request );
		if ( '/git-upload-pack' === $git_path || '/git-receive-pack' === $git_path ) {
			return ltrim( $git_path, '/' );
		}

		$info_refs_prefix = '/info/refs?service=';
		if ( 0 === strpos( $git_path, $info_refs_prefix ) ) {
			return self::normalize_git_service( substr( $git_path, strlen( $info_refs_prefix ) ) );
		}

		return '';
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
			$repository->set_config_value( 'user.name', get_option( 'blogname', 'Push MD' ) );
		}
		if ( ! $repository->get_config_value( 'user.email' ) ) {
			$repository->set_config_value( 'user.email', get_option( 'admin_email', 'push-md@example.com' ) );
		}

		return $repository;
	}

	private static function sync_repository_from_wordpress( GitRepository $repository ) {
		$theme_base_files = self::export_theme_base_content();
		self::sync_theme_base_from_wordpress( $repository, $theme_base_files );

		$exported_files = array_merge( $theme_base_files, self::export_wordpress_content() );
		ksort( $exported_files );
		self::sync_files_to_repository( $repository, $exported_files, self::WORDPRESS_SYNC_MESSAGE );
	}

	private static function sync_theme_base_from_wordpress( GitRepository $repository, $theme_base_files ) {
		$previous_base_files = array();
		if ( $repository->branch_exists( self::THEME_BASE_REF ) ) {
			$base_ref = $repository->get_branch_tip( self::THEME_BASE_REF );
			if ( is_string( $base_ref ) && '' !== $base_ref && ! Commit::is_null_hash( $base_ref ) ) {
				$previous_base_files = self::read_repository_entries_from_commit( $repository, $base_ref );
			}
		}

		$delta = self::calculate_file_delta( $previous_base_files, $theme_base_files );
		if ( empty( $delta['updates'] ) && empty( $delta['symlinks'] ) && empty( $delta['deletes'] ) ) {
			return;
		}

		$head_ref = $repository->get_branch_tip( 'HEAD', array( 'follow_symrefs' => false ) );
		if ( ! $repository->branch_exists( self::THEME_BASE_REF ) ) {
			$repository->set_branch_tip( self::THEME_BASE_REF, Commit::NULL_HASH );
		}

		$identity = self::get_repository_identity( $repository );
		$date     = gmdate( Commit::DATE_FORMAT, self::export_timestamp_from_entries( $theme_base_files ) );

		try {
			$repository->set_branch_tip( 'HEAD', 'ref: ' . self::THEME_BASE_REF . "\n" );
			$repository->commit(
				array(
					'updates'         => $delta['updates'],
					'create_symlinks' => $delta['symlinks'],
					'deletes'         => $delta['deletes'],
					'commit'          => array(
						'message'        => self::THEME_BASE_SYNC_MESSAGE,
						'author'         => $identity,
						'author_date'    => $date,
						'committer'      => $identity,
						'committer_date' => $date,
					),
				)
			);
		} finally {
			$repository->set_branch_tip( 'HEAD', $head_ref );
		}

		$head_oid        = $repository->get_branch_tip( 'refs/heads/' . self::DEFAULT_BRANCH );
		$existing_files  = ( is_string( $head_oid ) && '' !== $head_oid && ! Commit::is_null_hash( $head_oid ) )
			? self::read_repository_entries_from_commit( $repository, $head_oid )
			: array();
		$visible_deletes = array();
		foreach ( $delta['deletes'] as $path ) {
			if ( isset( $existing_files[ $path ] ) ) {
				$visible_deletes[] = $path;
			}
		}

		if ( empty( $delta['updates'] ) && empty( $delta['symlinks'] ) && empty( $visible_deletes ) ) {
			return;
		}

		$repository->commit(
			array(
				'updates'         => $delta['updates'],
				'create_symlinks' => $delta['symlinks'],
				'deletes'         => $visible_deletes,
				'commit'          => array(
					'message'        => self::THEME_BASE_SYNC_MESSAGE,
					'author'         => $identity,
					'author_date'    => $date,
					'committer'      => $identity,
					'committer_date' => $date,
				),
			)
		);
	}

	private static function sync_files_to_repository( GitRepository $repository, $exported_files, $message ) {
		$head_oid       = $repository->get_branch_tip( 'refs/heads/' . self::DEFAULT_BRANCH );
		$existing_files = ( is_string( $head_oid ) && '' !== $head_oid && ! Commit::is_null_hash( $head_oid ) )
			? self::read_repository_entries_from_commit( $repository, $head_oid )
			: array();

		$delta = self::calculate_file_delta( $existing_files, $exported_files );

		if ( empty( $delta['updates'] ) && empty( $delta['symlinks'] ) && empty( $delta['deletes'] ) ) {
			return;
		}

		$identity = self::get_repository_identity( $repository );
		$date     = gmdate( Commit::DATE_FORMAT, self::export_timestamp_from_entries( $exported_files ) );
		$repository->commit(
			array(
				'updates'         => $delta['updates'],
				'create_symlinks' => $delta['symlinks'],
				'deletes'         => $delta['deletes'],
				'commit'          => array(
					'message'        => $message,
					'author'         => $identity,
					'author_date'    => $date,
					'committer'      => $identity,
					'committer_date' => $date,
				),
			)
		);
	}

	private static function calculate_file_delta( $old_files, $new_files ) {
		$updates  = array();
		$symlinks = array();
		$deletes  = array();

		foreach ( $new_files as $path => $entry ) {
			if ( isset( $old_files[ $path ] ) && self::repository_entries_match( $old_files[ $path ], $entry ) ) {
				continue;
			}

			if ( TreeEntry::FILE_MODE_SYMBOLIC_LINK === $entry['mode'] ) {
				$symlinks[ $path ] = $entry['content'];
			} else {
				$updates[ $path ] = $entry['content'];
			}
		}

		foreach ( $old_files as $path => $entry ) {
			unset( $entry );
			if ( ! isset( $new_files[ $path ] ) ) {
				$deletes[] = $path;
			}
		}

		return array(
			'updates'  => $updates,
			'symlinks' => $symlinks,
			'deletes'  => $deletes,
		);
	}

	private static function export_timestamp_from_entries( $entries ) {
		$commit_timestamp = self::EPOCH_TIMESTAMP;

		foreach ( $entries as $entry ) {
			if ( isset( $entry['post'] ) && $entry['post'] instanceof WP_Post ) {
				$maybe_timestamp = self::timestamp_from_gmt_string( $entry['post']->post_modified_gmt );
				if ( false === $maybe_timestamp ) {
					$maybe_timestamp = self::timestamp_from_gmt_string( $entry['post']->post_date_gmt );
				}
				if ( false !== $maybe_timestamp ) {
					$commit_timestamp = max( $commit_timestamp, $maybe_timestamp );
				}
			}
			if ( isset( $entry['modified_timestamp'] ) ) {
				$commit_timestamp = max( $commit_timestamp, intval( $entry['modified_timestamp'] ) );
			}
		}

		return $commit_timestamp;
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
		$can_read_all_exports   = current_user_can( 'edit_others_posts' );
		foreach ( $posts as $post ) {
			if ( ! $can_read_all_exports && ! current_user_can( 'read_post', intval( $post->ID ) ) ) {
				throw new Exception( 'Git export rejected because you do not have permission to read all Push MD content.' );
			}

			$path = self::build_markdown_path( $post );
			if ( isset( $files[ $path ] ) ) {
				throw new Exception( 'Git export rejected because multiple WordPress entities map to the same Push MD path: ' . esc_html( $path ) );
			}

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

		self::add_default_agent_guidance_files( $files, $has_guideline_skills, $agent_guide_skill_path );
		self::add_global_styles_overlay_file( $files );

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

	private static function add_default_agent_guidance_files( &$files, &$has_guideline_skills, &$agent_guide_skill_path ) {
		if ( ! self::guidelines_enabled() ) {
			return;
		}

		$default_skills = array(
			self::AGENT_SKILL_SLUG => array(
				'description' => 'Guide for coding agents working in a Push MD checkout of a WordPress site.',
				'content'     => self::get_default_agent_skill_content(),
			),
			self::TEMPLATE_EDITOR_SKILL_SLUG => array(
				'description' => 'Edit Push MD block theme templates and template parts as raw Gutenberg HTML while preserving Site Editor compatibility.',
				'content'     => self::get_default_template_editor_skill_content(),
			),
		);

		foreach ( $default_skills as $slug => $skill ) {
			$path = 'wp_guideline/skills/' . $slug . '/SKILL.md';
			if ( ! isset( $files[ $path ] ) ) {
				$files[ $path ] = array(
					'post'    => null,
					'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
					'content' => self::format_skill_markdown(
						$slug,
						$skill['description'],
						$skill['content']
					),
				);
			}

			$has_guideline_skills = true;
		}

		if ( ! $agent_guide_skill_path ) {
			$agent_guide_skill_path = 'wp_guideline/skills/' . self::AGENT_SKILL_SLUG . '/SKILL.md';
		}
	}

	public static function export_theme_base_content() {
		$files = array();

		if ( ! function_exists( 'get_stylesheet' ) || ! function_exists( 'get_stylesheet_directory' ) ) {
			return $files;
		}

		$active_theme_slug = self::sanitize_repository_path_segment( get_stylesheet() );
		if ( '' === $active_theme_slug ) {
			return $files;
		}

		foreach ( self::get_theme_file_roots() as $root ) {
			self::collect_theme_block_files(
				$files,
				$root['directory'] . '/templates',
				'wp_template/' . $active_theme_slug
			);
			self::collect_theme_block_files(
				$files,
				$root['directory'] . '/parts',
				'wp_template_part/' . $active_theme_slug
			);
			self::add_theme_json_base_file( $files, $root['slug'], $root['directory'] );
		}

		ksort( $files );

		return $files;
	}

	private static function get_theme_file_roots() {
		$roots = array();

		if ( function_exists( 'get_template' ) && function_exists( 'get_template_directory' ) ) {
			$roots[] = array(
				'slug'      => self::sanitize_repository_path_segment( get_template() ),
				'directory' => get_template_directory(),
			);
		}

		$stylesheet_root = array(
			'slug'      => self::sanitize_repository_path_segment( get_stylesheet() ),
			'directory' => get_stylesheet_directory(),
		);
		$last_root       = end( $roots );
		if (
			empty( $roots ) ||
			$last_root['slug'] !== $stylesheet_root['slug'] ||
			wp_normalize_path( $last_root['directory'] ) !== wp_normalize_path( $stylesheet_root['directory'] )
		) {
			$roots[] = $stylesheet_root;
		}

		return $roots;
	}

	private static function collect_theme_block_files( &$files, $directory, $repository_directory ) {
		if ( ! is_dir( $directory ) ) {
			return;
		}

		$base_directory = trailingslashit( wp_normalize_path( $directory ) );
		$iterator       = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'html' !== strtolower( pathinfo( $file->getFilename(), PATHINFO_EXTENSION ) ) ) {
				continue;
			}

			$full_path = wp_normalize_path( $file->getPathname() );
			if ( 0 !== strpos( $full_path, $base_directory ) ) {
				continue;
			}

			$relative_path = substr( $full_path, strlen( $base_directory ) );
			$relative_path = self::sanitize_repository_relative_path( $relative_path );
			if ( '' === $relative_path ) {
				continue;
			}

			$content = file_get_contents( $full_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local theme/template file, not a remote URL.
			if ( false === $content ) {
				continue;
			}

			$path           = $repository_directory . '/' . $relative_path;
			$files[ $path ] = array(
				'post'               => null,
				'mode'               => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
				'content'            => $content,
				'modified_timestamp' => filemtime( $full_path ),
			);
		}
	}

	private static function add_theme_json_base_file( &$files, $theme_slug, $directory ) {
		$theme_json_path = wp_normalize_path( $directory . '/theme.json' );
		if ( '' === $theme_slug || ! is_file( $theme_json_path ) ) {
			return;
		}

		$content = file_get_contents( $theme_json_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local theme.json file, not a remote URL.
		if ( false === $content ) {
			return;
		}

		$files[ 'wp_theme/' . $theme_slug . '/theme.json' ] = array(
			'post'               => null,
			'mode'               => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
			'content'            => $content,
			'modified_timestamp' => filemtime( $theme_json_path ),
		);
	}

	private static function sanitize_repository_relative_path( $path ) {
		$segments = explode( '/', wp_normalize_path( $path ) );
		$safe     = array();

		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return '';
			}
			$safe_segment = self::sanitize_repository_path_segment( $segment );
			if ( '' === $safe_segment ) {
				return '';
			}
			$safe[] = $safe_segment;
		}

		return implode( '/', $safe );
	}

	private static function sanitize_repository_path_segment( $segment ) {
		$segment = sanitize_file_name( (string) $segment );
		$segment = str_replace( '\\', '', $segment );
		$segment = str_replace( '/', '', $segment );

		return $segment;
	}

	private static function get_export_post_types() {
		return self::get_supported_post_types();
	}

	private static function get_existing_raw_block_post_types() {
		$post_types = array();
		foreach ( self::$raw_block_post_types as $post_type ) {
			if ( post_type_exists( $post_type ) ) {
				$post_types[] = $post_type;
			}
		}

		return $post_types;
	}

	private static function get_existing_json_post_types() {
		$post_types = array();
		foreach ( self::$json_post_types as $post_type ) {
			if ( post_type_exists( $post_type ) ) {
				$post_types[] = $post_type;
			}
		}

		return $post_types;
	}

	private static function is_raw_block_post_type( $post_type ) {
		return in_array( $post_type, self::$raw_block_post_types, true );
	}

	private static function is_theme_scoped_raw_block_post_type( $post_type ) {
		return in_array( $post_type, self::$theme_scoped_raw_block_post_types, true );
	}

	public static function build_markdown_path( $post_or_type, $slug = null ) {
		if ( $post_or_type instanceof WP_Post ) {
			if ( 'wp_guideline' === $post_or_type->post_type ) {
				return self::build_guideline_markdown_path( $post_or_type );
			}
			if ( self::is_raw_block_post_type( $post_or_type->post_type ) ) {
				return self::build_raw_block_path(
					$post_or_type->post_type,
					self::get_export_post_slug( $post_or_type ),
					self::get_raw_block_post_theme_slug( $post_or_type )
				);
			}
			if ( 'wp_global_styles' === $post_or_type->post_type ) {
				return self::build_global_styles_path(
					self::get_post_theme_slug( $post_or_type )
				);
			}
			if ( 'page' === $post_or_type->post_type ) {
				return self::build_page_markdown_path( $post_or_type );
			}

			return ltrim( $post_or_type->post_type . '/' . self::get_export_post_slug( $post_or_type ) . '.md', '/' );
		}

		if ( self::is_raw_block_post_type( $post_or_type ) ) {
			return self::build_raw_block_path( $post_or_type, $slug );
		}
		if ( 'wp_global_styles' === $post_or_type ) {
			return self::build_global_styles_path( $slug );
		}

		return ltrim( $post_or_type . '/' . $slug . '.md', '/' );
	}

	private static function get_export_post_slug( WP_Post $post ) {
		if ( '' !== $post->post_name ) {
			return $post->post_name;
		}

		return self::get_id_fallback_slug( $post->post_type, $post->ID );
	}

	private static function get_id_fallback_slug( $post_type, $post_id ) {
		$post_type = sanitize_title( $post_type );
		if ( '' === $post_type ) {
			$post_type = 'post';
		}

		return $post_type . '-' . intval( $post_id );
	}

	private static function get_id_from_fallback_slug( $post_type, $slug ) {
		$prefix = sanitize_title( $post_type );
		if ( '' === $prefix ) {
			$prefix = 'post';
		}
		$prefix .= '-';
		if ( 0 !== strpos( $slug, $prefix ) ) {
			return 0;
		}

		$id = substr( $slug, strlen( $prefix ) );
		if ( ! preg_match( '/^[1-9][0-9]*$/', $id ) ) {
			return 0;
		}

		return intval( $id );
	}

	private static function path_uses_id_fallback_slug( $path ) {
		$post_type = self::path_to_post_type( $path );
		if ( self::is_raw_block_post_type( $post_type ) || 'wp_global_styles' === $post_type || 'wp_guideline' === $post_type ) {
			return false;
		}

		if ( 'page' === $post_type ) {
			foreach ( self::path_to_page_slugs( $path ) as $slug ) {
				if ( self::get_id_from_fallback_slug( 'page', $slug ) ) {
					return true;
				}
			}

			return false;
		}

		return (bool) self::get_id_from_fallback_slug( $post_type, self::path_to_slug( $path ) );
	}

	private static function is_current_slugless_fallback_path( $path, WP_Post $post ) {
		return '' === $post->post_name && self::path_uses_id_fallback_slug( $path ) && self::build_markdown_path( $post ) === $path;
	}

	private static function assert_id_fallback_path_is_current( $path, WP_Post $post ) {
		if ( ! self::path_uses_id_fallback_slug( $path ) || self::build_markdown_path( $post ) === $path ) {
			return;
		}

		throw new Exception( 'Push rejected because this fallback filename is stale after WordPress assigned the post a slug. Pull the latest changes and edit the slug-based file path.' );
	}

	private static function build_page_markdown_path( WP_Post $post ) {
		$segments  = array( self::get_export_post_slug( $post ) );
		$seen      = array( intval( $post->ID ) => true );
		$parent_id = intval( $post->post_parent );

		while ( $parent_id > 0 ) {
			if ( ! empty( $seen[ $parent_id ] ) ) {
				throw new Exception( 'Git export rejected because a WordPress page hierarchy contains a cycle.' );
			}

			$parent = get_post( $parent_id );
			if ( ! $parent || 'page' !== $parent->post_type ) {
				throw new Exception( 'Git export rejected because a WordPress page has an invalid parent.' );
			}
			if ( ! in_array( $parent->post_status, self::$supported_post_statuses, true ) ) {
				throw new Exception( 'Git export rejected because a WordPress page has a non-exported parent page. Restore, publish, or reparent the child page before cloning.' );
			}

			array_unshift( $segments, self::get_export_post_slug( $parent ) );
			$seen[ $parent_id ] = true;
			$parent_id          = intval( $parent->post_parent );
		}

		return 'page/' . implode( '/', $segments ) . '.md';
	}

	private static function build_raw_block_path( $post_type, $slug, $theme_slug = '' ) {
		$slug_path = str_replace( '//', '/', $slug );
		if ( '' !== $theme_slug && self::is_theme_scoped_raw_block_post_type( $post_type ) ) {
			$slug_path = $theme_slug . '/' . $slug_path;
		}

		return ltrim( $post_type . '/' . $slug_path . '.html', '/' );
	}

	private static function get_raw_block_post_theme_slug( WP_Post $post ) {
		if ( ! self::is_theme_scoped_raw_block_post_type( $post->post_type ) ) {
			return '';
		}

		return self::get_post_theme_slug( $post );
	}

	private static function get_post_theme_slug( WP_Post $post ) {
		if ( ! taxonomy_exists( 'wp_theme' ) ) {
			return '';
		}
		$terms = get_the_terms( $post->ID, 'wp_theme' );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return '';
		}

		return self::sanitize_repository_path_segment( $terms[0]->slug );
	}

	private static function build_global_styles_path( $theme_slug ) {
		$theme_slug = self::sanitize_repository_path_segment( $theme_slug );
		if ( '' === $theme_slug && function_exists( 'get_stylesheet' ) ) {
			$theme_slug = self::sanitize_repository_path_segment( get_stylesheet() );
		}

		return 'wp_global_styles/' . $theme_slug . '.json';
	}

	private static function add_global_styles_overlay_file( &$files ) {
		if ( ! post_type_exists( 'wp_global_styles' ) || ! function_exists( 'get_stylesheet' ) ) {
			return;
		}

		$theme_slug = self::sanitize_repository_path_segment( get_stylesheet() );
		if ( '' === $theme_slug ) {
			return;
		}

		$path = self::build_global_styles_path( $theme_slug );
		if ( isset( $files[ $path ] ) ) {
			return;
		}

		$files[ $path ] = array(
			'post'    => null,
			'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
			'content' => self::encode_global_styles_json(
				array(
					'version'  => self::latest_theme_json_schema_version(),
					'settings' => new stdClass(),
					'styles'   => new stdClass(),
				)
			),
		);
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
		if ( self::is_raw_block_post_type( $post->post_type ) ) {
			return $post->post_content;
		}
		if ( 'wp_global_styles' === $post->post_type ) {
			return self::export_global_styles_to_json( $post );
		}

		$metadata = array(
			'id'     => array( (string) $post->ID ),
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

	private static function export_global_styles_to_json( WP_Post $post ) {
		$config = json_decode( $post->post_content, true );
		if ( ! is_array( $config ) ) {
			$config = array();
		}

		unset( $config['isGlobalStylesUserThemeJSON'] );
		if ( ! isset( $config['version'] ) ) {
			$config['version'] = self::latest_theme_json_schema_version();
		}

		return self::encode_global_styles_json( $config );
	}

	private static function encode_global_styles_json( $config ) {
		$encoded = wp_json_encode(
			$config,
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		if ( false === $encoded ) {
			$encoded = '{}';
		}

		return $encoded . "\n";
	}

	private static function latest_theme_json_schema_version() {
		if ( class_exists( 'WP_Theme_JSON' ) ) {
			return WP_Theme_JSON::LATEST_SCHEMA;
		}

		return 3;
	}

	private static function export_guideline_skill_to_markdown( WP_Post $post ) {
		return self::format_skill_markdown(
			$post->post_name,
			trim( $post->post_excerpt ),
			$post->post_content
		);
	}

	private static function format_skill_markdown( $name, $description, $content ) {
		$frontmatter = array(
			'---',
			'name: ' . self::quote_yaml_scalar( $name ),
			'description: ' . self::quote_yaml_scalar( $description ),
			'---',
			'',
		);

		return implode( "\n", $frontmatter ) . ltrim( $content, "\r\n" );
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

	public static function get_default_agent_guidance_preview_files() {
		if ( ! self::guidelines_enabled() ) {
			return array();
		}

		$files          = array();
		$default_skills = array(
			self::AGENT_SKILL_SLUG => array(
				'description' => 'Guide for coding agents working in a Push MD checkout of a WordPress site.',
				'content'     => self::get_default_agent_skill_content(),
			),
			self::TEMPLATE_EDITOR_SKILL_SLUG => array(
				'description' => 'Edit Push MD block theme templates and template parts as raw Gutenberg HTML while preserving Site Editor compatibility.',
				'content'     => self::get_default_template_editor_skill_content(),
			),
		);

		foreach ( $default_skills as $slug => $skill ) {
			$files[ 'wp_guideline/skills/' . $slug . '/SKILL.md' ] = array(
				'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
				'content' => self::format_skill_markdown(
					$slug,
					$skill['description'],
					$skill['content']
				),
			);
		}

		foreach ( self::get_agent_skills_directory_symlink_paths() as $path => $target ) {
			$files[ $path ] = array(
				'mode'    => TreeEntry::FILE_MODE_SYMBOLIC_LINK,
				'content' => $target,
			);
		}

		foreach ( self::get_agent_entrypoint_symlink_paths( 'wp_guideline/skills/' . self::AGENT_SKILL_SLUG . '/SKILL.md' ) as $path => $target ) {
			$files[ $path ] = array(
				'mode'    => TreeEntry::FILE_MODE_SYMBOLIC_LINK,
				'content' => $target,
			);
		}

		return $files;
	}

	public static function repository_identity( GitRepository $repository ) {
		return self::get_repository_identity( $repository );
	}

	private static function apply_repository_changes_to_wordpress( GitRepository $repository, $old_commit, $new_commit ) {
		$commit_hashes = self::get_push_commit_hashes( $repository, $old_commit, $new_commit );
		$push_summary  = array();

		foreach ( $commit_hashes as $commit_hash ) {
			self::validate_single_commit_content_changes( $repository, $commit_hash );
		}

		$push_summary = self::apply_repository_diff_to_wordpress( $repository, $old_commit, $new_commit, false );

		return $push_summary;
	}

	private static function rollback_rejected_push_ref( GitRepository $repository, $push_header ) {
		$branch_name = 'refs/heads/' . self::DEFAULT_BRANCH;

		try {
			if ( $push_header['new_oid'] === $repository->get_branch_tip( $branch_name ) ) {
				$repository->set_branch_tip( $branch_name, $push_header['old_oid'] );
			}
		} catch ( Throwable $exception ) {
			// Preserve the original rejection reason for the Git client.
		}
	}

	private static function validate_single_commit_content_changes( GitRepository $repository, $commit_hash ) {
		$commit = $repository->read_object( $commit_hash )->as_commit();
		self::validate_push_commit( $repository, $commit );

		$parent_hash = empty( $commit->parents ) ? Commit::NULL_HASH : $commit->get_first_parent_hash();
		$old_files   = Commit::is_null_hash( $parent_hash )
			? array()
			: self::read_repository_entries_from_commit( $repository, $parent_hash );
		$new_files   = self::read_repository_entries_from_commit( $repository, $commit_hash );

		self::reject_symlink_file_changes( $old_files, $new_files );
		self::reject_executable_file_changes( $old_files, $new_files );
		self::reject_deleted_raw_block_files( $old_files, $new_files );
		self::reject_deleted_theme_base_files( $old_files, $new_files );
		self::reject_deleted_global_styles_files( $old_files, $new_files );
		self::reject_deleted_page_parent_files_with_remaining_children( $old_files, $new_files );

		foreach ( $new_files as $path => $entry ) {
			if ( isset( $old_files[ $path ] ) && self::repository_entries_match( $old_files[ $path ], $entry ) ) {
				continue;
			}
			if ( TreeEntry::FILE_MODE_SYMBOLIC_LINK === $entry['mode'] ) {
				continue;
			}
			if ( self::is_theme_base_path( $path ) ) {
				throw new Exception( 'Push rejected because theme base files are read-only in Push MD. Edit template HTML files to create WordPress customizations instead.' );
			}
			if ( self::is_raw_block_path( $path ) ) {
				self::assert_raw_block_html_has_no_front_matter( $entry['content'] );
				self::assert_raw_block_html_has_block_markup( $entry['content'] );
			}
			if ( self::is_global_styles_path( $path ) ) {
				self::assert_global_styles_json_is_valid( $path, $entry['content'] );
			}
		}
	}

	private static function apply_repository_diff_to_wordpress( GitRepository $repository, $old_commit, $new_commit, $skip_modified_checks ) {
		$old_files = Commit::is_null_hash( $old_commit )
			? array()
			: self::read_repository_entries_from_commit( $repository, $old_commit );
		$new_files = self::read_repository_entries_from_commit( $repository, $new_commit );

		$updated_post_ids = array();
		$changes          = array();
		$upsert_plans     = array();
		$trash_plans      = array();
		self::reject_symlink_file_changes( $old_files, $new_files );
		self::reject_executable_file_changes( $old_files, $new_files );
		self::reject_deleted_raw_block_files( $old_files, $new_files );
		self::reject_deleted_theme_base_files( $old_files, $new_files );
		self::reject_deleted_global_styles_files( $old_files, $new_files );
		self::reject_deleted_page_parent_files_with_remaining_children( $old_files, $new_files );

		foreach ( $new_files as $path => $entry ) {
			if ( isset( $old_files[ $path ] ) && self::repository_entries_match( $old_files[ $path ], $entry ) ) {
				continue;
			}
			if ( TreeEntry::FILE_MODE_SYMBOLIC_LINK === $entry['mode'] ) {
				continue;
			}

			$planned = self::upsert_post_from_markdown(
				$path,
				$entry['content'],
				array(
					'dry_run'             => true,
					'skip_modified_check' => $skip_modified_checks,
				)
			);
			$post_id = $planned['post_id'];
			if ( $post_id ) {
				if ( isset( $updated_post_ids[ $post_id ] ) ) {
					throw new Exception( 'Push rejected because multiple Markdown files reference the same WordPress post ID.' );
				}
				$updated_post_ids[ $post_id ] = true;
			}
			$upsert_plans[] = array(
				'path'    => $path,
				'content' => $entry['content'],
			);
		}

		foreach ( $old_files as $path => $entry ) {
			if ( isset( $new_files[ $path ] ) ) {
				continue;
			}
			if ( TreeEntry::FILE_MODE_SYMBOLIC_LINK === $entry['mode'] ) {
				continue;
			}

			$metadata = self::parse_markdown_metadata( $entry['content'] );
			$post_id  = self::find_post_id_by_path_metadata( $path, $metadata );

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

			$trash_plans[] = array(
				'post_id' => $post_id,
				'path'    => $path,
			);
		}

		foreach ( $upsert_plans as $plan ) {
			$applied = self::upsert_post_from_markdown(
				$plan['path'],
				$plan['content'],
				array( 'skip_modified_check' => $skip_modified_checks )
			);
			if ( $applied['post_id'] ) {
				$changes[] = $applied['change'];
			}
		}

		foreach ( $trash_plans as $plan ) {
			$post      = get_post( $plan['post_id'] );
			$changes[] = self::build_push_summary_item( 'trashed', $post, $plan['path'] );

			if ( false === wp_trash_post( $plan['post_id'] ) ) {
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

	private static function reject_deleted_raw_block_files( $old_files, $new_files ) {
		foreach ( $old_files as $path => $entry ) {
			if ( isset( $new_files[ $path ] ) ) {
				continue;
			}
			if ( TreeEntry::FILE_MODE_SYMBOLIC_LINK === $entry['mode'] ) {
				continue;
			}
			if ( self::is_raw_block_path( $path ) ) {
				throw new Exception( 'Push rejected because template HTML files cannot be deleted or renamed. Update them in place or create a new .html file.' );
			}
		}
	}

	private static function reject_deleted_theme_base_files( $old_files, $new_files ) {
		foreach ( $old_files as $path => $entry ) {
			if ( isset( $new_files[ $path ] ) ) {
				continue;
			}
			if ( TreeEntry::FILE_MODE_SYMBOLIC_LINK === $entry['mode'] ) {
				continue;
			}
			if ( self::is_theme_base_path( $path ) ) {
				throw new Exception( 'Push rejected because theme base files are read-only in Push MD. Edit template HTML files to create WordPress customizations instead.' );
			}
		}
	}

	private static function reject_deleted_global_styles_files( $old_files, $new_files ) {
		foreach ( $old_files as $path => $entry ) {
			if ( isset( $new_files[ $path ] ) ) {
				continue;
			}
			if ( TreeEntry::FILE_MODE_SYMBOLIC_LINK === $entry['mode'] ) {
				continue;
			}
			if ( self::is_global_styles_path( $path ) ) {
				throw new Exception( 'Push rejected because Global Styles JSON files cannot be deleted or renamed. Update them in place.' );
			}
		}
	}

	private static function reject_deleted_page_parent_files_with_remaining_children( $old_files, $new_files ) {
		foreach ( $old_files as $path => $entry ) {
			if ( isset( $new_files[ $path ] ) ) {
				continue;
			}
			if ( TreeEntry::FILE_MODE_SYMBOLIC_LINK === $entry['mode'] || ! self::is_page_markdown_path( $path ) ) {
				continue;
			}

			$descendant_prefix = self::page_descendant_prefix_from_path( $path );
			if ( '' === $descendant_prefix ) {
				continue;
			}

			foreach ( $new_files as $new_path => $new_entry ) {
				unset( $new_entry );
				if ( 0 === strpos( $new_path, $descendant_prefix ) ) {
					throw new Exception( 'Push rejected because deleting a parent page while keeping nested child page files would move child content. Delete the nested child page files too, or keep the parent page.' );
				}
			}
		}
	}

	private static function is_page_markdown_path( $path ) {
		$segments = explode( '/', ltrim( $path, '/' ) );
		return isset( $segments[0] )
			&& 'page' === $segments[0]
			&& 'md' === pathinfo( basename( $path ), PATHINFO_EXTENSION );
	}

	private static function page_descendant_prefix_from_path( $path ) {
		$relative_path = substr( ltrim( $path, '/' ), strlen( 'page/' ) );
		if ( '.md' !== substr( $relative_path, - strlen( '.md' ) ) ) {
			return '';
		}

		return 'page/' . substr( $relative_path, 0, - strlen( '.md' ) ) . '/';
	}

	private static function reject_symlink_file_changes( $old_files, $new_files ) {
		foreach ( $new_files as $path => $entry ) {
			if ( TreeEntry::FILE_MODE_SYMBOLIC_LINK !== $entry['mode'] ) {
				continue;
			}
			if ( ! isset( $old_files[ $path ] ) || ! self::repository_entries_match( $old_files[ $path ], $entry ) ) {
				throw new Exception( 'Push rejected because symlink files are generated by Push MD and cannot be created or modified.' );
			}
		}

		foreach ( $old_files as $path => $entry ) {
			if ( TreeEntry::FILE_MODE_SYMBOLIC_LINK !== $entry['mode'] ) {
				continue;
			}
			if ( ! isset( $new_files[ $path ] ) || ! self::repository_entries_match( $entry, $new_files[ $path ] ) ) {
				throw new Exception( 'Push rejected because symlink files are generated by Push MD and cannot be deleted or modified.' );
			}
		}
	}

	private static function reject_executable_file_changes( $old_files, $new_files ) {
		foreach ( $new_files as $path => $entry ) {
			if ( TreeEntry::FILE_MODE_REGULAR_EXECUTABLE !== $entry['mode'] ) {
				continue;
			}
			if ( ! isset( $old_files[ $path ] ) || ! self::repository_entries_match( $old_files[ $path ], $entry ) ) {
				throw new Exception( 'Push rejected because executable file modes are not supported by Push MD content exports.' );
			}
		}
	}

	private static function upsert_post_from_markdown( $path, $markdown, $options = array() ) {
		self::assert_content_has_no_nul_bytes( $markdown );
		$post_type = self::path_to_post_type( $path );
		$slug      = self::path_to_slug( $path );
		if ( 'wp_guideline' === $post_type ) {
			return self::upsert_guideline_from_markdown( $path, $markdown, $options );
		}
		if ( self::is_raw_block_post_type( $post_type ) ) {
			return self::upsert_raw_block_post_from_html( $path, $markdown, $options );
		}
		if ( 'wp_global_styles' === $post_type ) {
			return self::upsert_global_styles_from_json( $path, $markdown, $options );
		}

		self::assert_markdown_front_matter_is_closed( $markdown );
		$consumer = new MarkdownConsumer( $markdown );
		$result   = $consumer->consume();
		self::assert_block_markup_is_safe( $result->get_block_markup() );
		$metadata = array();
		foreach ( $result->get_all_metadata() as $key => $value ) {
			$metadata[ $key ] = is_array( $value ) ? reset( $value ) : $value;
		}

		self::reject_path_identity_frontmatter( $metadata );
		$metadata      = self::normalize_supported_frontmatter(
			$metadata,
			array( 'id', 'title', 'date', 'status', 'description' )
		);
		$post_id       = self::find_post_id_by_path_metadata( $path, $metadata );
		$existing_post = $post_id ? get_post( $post_id ) : null;
		if ( $existing_post ) {
			self::assert_id_fallback_path_is_current( $path, $existing_post );
		}
		$default_status = $existing_post && 'trash' !== $existing_post->post_status ? $existing_post->post_status : 'draft';
		$post_status    = self::normalize_frontmatter_status(
			isset( $metadata['status'] ) ? $metadata['status'] : $default_status
		);
		self::validate_post_status( $post_status, $post_type );
		self::assert_can_set_post_status( $post_type, $post_status, $existing_post );
		$post_parent = 'page' === $post_type ? self::path_to_page_parent_id( $path, false ) : 0;

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
			'post_title'   => isset( $metadata['title'] ) ? $metadata['title'] : ucwords( str_replace( '-', ' ', $slug ) ),
			'post_status'  => $post_status,
			'post_content' => $result->get_block_markup(),
		);
		if ( ! $existing_post || ! self::is_current_slugless_fallback_path( $path, $existing_post ) ) {
			$postarr['post_name'] = $slug;
		}
		if ( 'page' === $post_type ) {
			$postarr['post_parent'] = $post_parent;
		}

		$post_date_gmt = self::frontmatter_date_to_mysql_gmt( $metadata );
		self::assert_frontmatter_date_matches_status( $post_status, $post_date_gmt );
		if ( '' !== $post_date_gmt ) {
			$postarr['post_date_gmt'] = $post_date_gmt;
			$postarr['post_date']     = get_date_from_gmt( $post_date_gmt );
		}
		if ( array_key_exists( 'description', $metadata ) ) {
			$postarr['post_excerpt'] = $metadata['description'];
		}

		$change_action = $existing_post && 'trash' === $existing_post->post_status ? 'restored' : ( $existing_post ? 'updated' : 'created' );
		if ( ! empty( $options['dry_run'] ) ) {
			return array(
				'post_id' => $existing_post ? intval( $existing_post->ID ) : 0,
				'change'  => null,
			);
		}

		if ( $existing_post ) {
			$existing_post = self::restore_trashed_post_before_update( $existing_post );
			$postarr['ID'] = $existing_post->ID;
			$post_id       = wp_update_post( wp_slash( $postarr ), true );
		} else {
			$post_id = wp_insert_post( wp_slash( $postarr ), true );
		}

		if ( is_wp_error( $post_id ) ) {
			throw new Exception( esc_html( $post_id->get_error_message() ) );
		}

		$post = get_post( $post_id );

		return array(
			'post_id' => $post_id,
			'change'  => self::build_push_summary_item(
				$change_action,
				$post,
				$path
			),
		);
	}

	private static function upsert_raw_block_post_from_html( $path, $html, $options = array() ) {
		self::assert_content_has_no_nul_bytes( $html );
		self::assert_raw_block_html_has_no_front_matter( $html );
		self::assert_raw_block_html_has_block_markup( $html );
		self::assert_block_markup_is_safe( $html );

		$identity      = self::path_to_raw_block_identity( $path );
		$post_type     = $identity['post_type'];
		$slug          = $identity['slug'];
		$post_id       = self::find_raw_block_post_id_by_path( $path, false );
		$existing_post = $post_id ? get_post( $post_id ) : null;

		if ( $existing_post ) {
			self::assert_can_edit_post( $existing_post->ID );
			$postarr = array(
				'ID'           => $existing_post->ID,
				'post_content' => $html,
			);
		} else {
			self::assert_can_create_post_type( $post_type );
			self::assert_can_set_post_status( $post_type, 'publish' );
			$postarr = array(
				'post_type'    => $post_type,
				'post_name'    => $slug,
				'post_title'   => ucwords( str_replace( '-', ' ', $slug ) ),
				'post_status'  => 'publish',
				'post_content' => $html,
			);
		}

		if ( ! empty( $options['dry_run'] ) ) {
			return array(
				'post_id' => $existing_post ? intval( $existing_post->ID ) : 0,
				'change'  => null,
			);
		}

		if ( $existing_post ) {
			$post_id = wp_update_post( wp_slash( $postarr ), true );
		} else {
			$post_id = wp_insert_post( wp_slash( $postarr ), true );
		}

		if ( is_wp_error( $post_id ) ) {
			throw new Exception( esc_html( $post_id->get_error_message() ) );
		}

		self::assign_raw_block_theme_slug( $post_id, $identity['theme'] );

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

	private static function upsert_global_styles_from_json( $path, $json, $options = array() ) {
		self::assert_content_has_no_nul_bytes( $json );
		$config        = self::parse_global_styles_json( $path, $json );
		$theme_slug    = self::path_to_global_styles_theme_slug( $path );
		$post_id       = self::find_global_styles_post_id_by_theme_slug( $theme_slug, false );
		$existing_post = $post_id ? get_post( $post_id ) : null;

		$config['isGlobalStylesUserThemeJSON'] = true;
		if ( ! isset( $config['version'] ) ) {
			$config['version'] = self::latest_theme_json_schema_version();
		}

		$post_content = wp_json_encode( $config );
		if ( false === $post_content ) {
			throw new Exception( 'Push rejected because the Global Styles JSON could not be encoded.' );
		}

		if ( $existing_post ) {
			self::assert_can_edit_post( $existing_post->ID );
			$postarr = array(
				'ID'           => $existing_post->ID,
				'post_content' => $post_content,
			);
		} else {
			self::assert_can_create_post_type( 'wp_global_styles' );
			self::assert_can_set_post_status( 'wp_global_styles', 'publish' );
			$postarr = array(
				'post_type'    => 'wp_global_styles',
				'post_name'    => 'wp-global-styles-' . rawurlencode( $theme_slug ),
				'post_title'   => 'Custom Styles',
				'post_status'  => 'publish',
				'post_content' => $post_content,
			);
		}

		if ( ! empty( $options['dry_run'] ) ) {
			return array(
				'post_id' => $existing_post ? intval( $existing_post->ID ) : 0,
				'change'  => null,
			);
		}

		if ( $existing_post ) {
			$post_id = wp_update_post( wp_slash( $postarr ), true );
		} else {
			$post_id = wp_insert_post( wp_slash( $postarr ), true );
		}

		if ( is_wp_error( $post_id ) ) {
			throw new Exception( esc_html( $post_id->get_error_message() ) );
		}

		self::assign_post_theme_slug( $post_id, $theme_slug );
		if ( function_exists( 'wp_clean_theme_json_cache' ) ) {
			wp_clean_theme_json_cache();
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

	private static function assert_global_styles_json_is_valid( $path, $json ) {
		self::parse_global_styles_json( $path, $json );
	}

	private static function parse_global_styles_json( $path, $json ) {
		self::path_to_global_styles_theme_slug( $path );
		$config = json_decode( $json, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			throw new Exception( 'Push rejected because Global Styles JSON is invalid: ' . esc_html( json_last_error_msg() ) );
		}
		if ( ! is_array( $config ) ) {
			throw new Exception( 'Push rejected because Global Styles files must contain a JSON object.' );
		}
		if ( ! empty( $config ) && self::is_array_list( $config ) ) {
			throw new Exception( 'Push rejected because Global Styles files must contain a JSON object.' );
		}

		unset( $config['isGlobalStylesUserThemeJSON'] );

		return $config;
	}

	private static function assert_raw_block_html_has_no_front_matter( $html ) {
		if ( preg_match( '/\A---\r?\n/', $html ) ) {
			throw new Exception( 'Push rejected because template HTML files must contain raw Gutenberg block markup without front matter.' );
		}
	}

	private static function assert_content_has_no_nul_bytes( $content ) {
		if ( false !== strpos( $content, "\0" ) ) {
			throw new Exception( 'Push rejected because content files must not contain NUL bytes.' );
		}
	}

	private static function assert_raw_block_html_has_block_markup( $html ) {
		if ( false === strpos( $html, '<!-- wp:' ) ) {
			throw new Exception( 'Push rejected because template HTML files must contain serialized Gutenberg block markup.' );
		}
	}

	private static function assert_markdown_front_matter_is_closed( $markdown ) {
		if (
			preg_match( '/\A---\r?\n/', $markdown ) &&
			! preg_match( '/\A---\r?\n.*?\r?\n---(?:\r?\n|\z)/s', $markdown )
		) {
			throw new Exception( 'Push rejected because Markdown front matter is missing its closing --- fence.' );
		}
	}

	private static function assert_block_markup_is_safe( $block_markup ) {
		if (
			false === strpos( $block_markup, '<!-- wp:' ) &&
			false === strpos( $block_markup, '<!-- /wp:' )
		) {
			return;
		}

		self::assert_block_delimiters_are_well_formed( $block_markup );
		self::assert_parsed_blocks_are_safe( parse_blocks( $block_markup ) );
	}

	private static function assert_block_delimiters_are_well_formed( $block_markup ) {
		if ( ! preg_match_all( '/<!--\s*\/?wp:.*?-->/s', $block_markup, $matches ) ) {
			throw new Exception( 'Push rejected because the content contains malformed Gutenberg block markup.' );
		}

		$stack = array();
		foreach ( $matches[0] as $token ) {
			if (
				! preg_match(
					'/\A<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)(?P<attrs>\s+\{.*\})?\s+(?P<void>\/)?-->\z/s',
					$token,
					$token_match
				)
			) {
				throw new Exception( 'Push rejected because the content contains malformed Gutenberg block markup.' );
			}

			$is_closer = isset( $token_match['closer'] ) && '' !== $token_match['closer'];
			$is_void   = isset( $token_match['void'] ) && '' !== $token_match['void'];
			$namespace = isset( $token_match['namespace'] ) && '' !== $token_match['namespace'] ? $token_match['namespace'] : 'core/';
			$name      = $namespace . $token_match['name'];

			if ( isset( $token_match['attrs'] ) && '' !== $token_match['attrs'] ) {
				json_decode( trim( $token_match['attrs'] ), true );
				if ( JSON_ERROR_NONE !== json_last_error() ) {
					throw new Exception( 'Push rejected because the content contains malformed Gutenberg block attributes.' );
				}
			}

			if ( $is_closer ) {
				if ( $is_void || ( isset( $token_match['attrs'] ) && '' !== $token_match['attrs'] ) ) {
					throw new Exception( 'Push rejected because the content contains malformed Gutenberg block markup.' );
				}
				if ( empty( $stack ) || array_pop( $stack ) !== $name ) {
					throw new Exception( 'Push rejected because the content contains mismatched Gutenberg block delimiters.' );
				}
			} elseif ( ! $is_void ) {
				$stack[] = $name;
			}
		}

		if ( ! empty( $stack ) ) {
			throw new Exception( 'Push rejected because the content contains unclosed Gutenberg block delimiters.' );
		}
	}

	private static function assert_parsed_blocks_are_safe( $blocks ) {
		foreach ( $blocks as $block ) {
			$block_name   = isset( $block['blockName'] ) ? $block['blockName'] : null;
			$attrs        = array_key_exists( 'attrs', $block ) ? $block['attrs'] : array();
			$inner_html   = isset( $block['innerHTML'] ) ? $block['innerHTML'] : '';
			$inner_blocks = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();

			if ( null === $block_name && self::contains_block_delimiter( $inner_html ) ) {
				throw new Exception( 'Push rejected because the content contains malformed Gutenberg block markup.' );
			}
			if ( 'core/html' === $block_name && ( ! empty( $inner_blocks ) || self::contains_block_delimiter( $inner_html ) ) ) {
				throw new Exception( 'Push rejected because Markdown content must not embed raw Gutenberg block delimiters inside HTML blocks.' );
			}
			if ( null !== $block_name && ! is_array( $attrs ) ) {
				throw new Exception( 'Push rejected because the content contains malformed Gutenberg block attributes.' );
			}

			self::assert_parsed_blocks_are_safe( $inner_blocks );
		}
	}

	private static function contains_block_delimiter( $html ) {
		return false !== strpos( $html, '<!-- wp:' ) || false !== strpos( $html, '<!-- /wp:' );
	}

	private static function is_array_list( $items ) {
		$index = 0;
		foreach ( $items as $key => $value ) {
			unset( $value );
			if ( $key !== $index ) {
				return false;
			}
			++$index;
		}

		return true;
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
			self::assert_block_markup_is_safe( $markdown );
			self::reject_path_identity_frontmatter( $metadata );
			$metadata = self::normalize_supported_frontmatter(
				$metadata,
				array( 'name', 'description' )
			);
			if ( isset( $metadata['name'] ) && $metadata['name'] !== $slug ) {
				throw new Exception( 'Push rejected because the skill name front matter does not match its directory.' );
			}
		} else {
			self::assert_block_markup_is_safe( $markdown );
		}

		$post_id       = self::find_post_id_by_path_metadata( $path, $metadata );
		$existing_post = $post_id ? get_post( $post_id ) : null;

		$default_status = $existing_post && 'trash' !== $existing_post->post_status ? $existing_post->post_status : 'draft';
		$post_status    = self::normalize_frontmatter_status(
			isset( $metadata['status'] ) ? $metadata['status'] : $default_status
		);
		self::validate_post_status( $post_status, 'wp_guideline' );
		self::assert_can_set_post_status( 'wp_guideline', $post_status, $existing_post );

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

		$change_action = $existing_post && 'trash' === $existing_post->post_status ? 'restored' : ( $existing_post ? 'updated' : 'created' );
		if ( ! empty( $options['dry_run'] ) ) {
			return array(
				'post_id' => $existing_post ? intval( $existing_post->ID ) : 0,
				'change'  => null,
			);
		}

		if ( $existing_post ) {
			$existing_post = self::restore_trashed_post_before_update( $existing_post );
			$postarr['ID'] = $existing_post->ID;
			$post_id       = wp_update_post( wp_slash( $postarr ), true );
		} else {
			$post_id = wp_insert_post( wp_slash( $postarr ), true );
		}

		if ( is_wp_error( $post_id ) ) {
			throw new Exception( esc_html( $post_id->get_error_message() ) );
		}

		$term_id   = self::get_or_create_guideline_type_term_id( $guideline_type_slug );
		$set_terms = wp_set_object_terms( $post_id, array( $term_id ), 'wp_guideline_type' );
		if ( is_wp_error( $set_terms ) ) {
			throw new Exception( esc_html( $set_terms->get_error_message() ) );
		}

		$post = get_post( $post_id );

		return array(
			'post_id' => $post_id,
			'change'  => self::build_push_summary_item(
				$change_action,
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
			'Push MD applied %d content %s:',
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
			$parsed = self::parse_frontmatter_date( $metadata['date'] );
			if ( '' === $parsed ) {
				throw new Exception( 'Push rejected because Markdown front matter date is invalid.' );
			}

			return $parsed;
		}
		if ( isset( $metadata['date_gmt'] ) && '' !== trim( (string) $metadata['date_gmt'] ) ) {
			$parsed = self::parse_frontmatter_date( $metadata['date_gmt'] );
			if ( '' === $parsed ) {
				throw new Exception( 'Push rejected because Markdown front matter date_gmt is invalid.' );
			}

			return $parsed;
		}

		return '';
	}

	private static function parse_frontmatter_date( $date ) {
		$date = trim( (string) $date );
		if ( '' === $date ) {
			return '';
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return self::parse_frontmatter_date_format( $date . ' 00:00:00', 'Y-m-d H:i:s', $date . ' 00:00:00' );
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}Z$/', $date ) ) {
			$normalized = str_replace( 'T', ' ', substr( $date, 0, -1 ) );

			return self::parse_frontmatter_date_format( $normalized, 'Y-m-d H:i:s', $normalized );
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$/', $date ) ) {
			$normalized = str_replace( 'T', ' ', $date );

			return self::parse_frontmatter_date_format( $normalized, 'Y-m-d H:i:s', $normalized );
		}

		return '';
	}

	private static function parse_frontmatter_date_format( $date, $format, $expected ) {
		$timezone = new DateTimeZone( 'UTC' );
		$datetime = DateTime::createFromFormat( '!' . $format, $date, $timezone );
		$errors   = DateTime::getLastErrors();
		if (
			false === $datetime ||
			(
				is_array( $errors ) &&
				( 0 !== $errors['warning_count'] || 0 !== $errors['error_count'] )
			) ||
			$datetime->format( $format ) !== $expected
		) {
			return '';
		}

		return $datetime->format( 'Y-m-d H:i:s' );
	}

	private static function assert_frontmatter_date_matches_status( $post_status, $post_date_gmt ) {
		if ( '' === $post_date_gmt ) {
			if ( 'future' === $post_status ) {
				throw new Exception( 'Push rejected because scheduled posts must include a future date.' );
			}
			return;
		}

		$timestamp = self::timestamp_from_gmt_string( $post_date_gmt );
		if ( 'future' === $post_status && ( false === $timestamp || $timestamp <= time() ) ) {
			throw new Exception( 'Push rejected because scheduled posts must include a date in the future.' );
		}
		if ( 'publish' === $post_status && false !== $timestamp && $timestamp > time() ) {
			throw new Exception( 'Push rejected because published posts must not include a future date. Use scheduled status for future-dated content.' );
		}
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

	private static function reject_path_identity_frontmatter( $metadata ) {
		if ( isset( $metadata['slug'] ) ) {
			throw new Exception( 'Push rejected because Markdown front matter must not include a slug. Rename the file path only when creating distinct content.' );
		}
		if ( isset( $metadata['type'] ) ) {
			throw new Exception( 'Push rejected because Markdown front matter must not include a type. The directory determines the post type.' );
		}
	}

	private static function normalize_supported_frontmatter( $metadata, $allowed_keys ) {
		$allowed = array();
		foreach ( $allowed_keys as $key ) {
			$allowed[ $key ] = true;
		}

		$normalized = array();
		foreach ( $metadata as $key => $value ) {
			$key = (string) $key;
			if ( ! isset( $allowed[ $key ] ) ) {
				throw new Exception(
					sprintf(
						'Push rejected because Markdown front matter field "%s" is not supported.',
						esc_html( $key )
					)
				);
			}
			if ( ! is_scalar( $value ) || is_bool( $value ) ) {
				throw new Exception(
					sprintf(
						'Push rejected because Markdown front matter field "%s" must be a scalar string or number.',
						esc_html( $key )
					)
				);
			}

			$normalized[ $key ] = (string) $value;
		}

		return $normalized;
	}

	private static function restore_trashed_post_before_update( WP_Post $post ) {
		if ( 'trash' !== $post->post_status ) {
			return $post;
		}

		if ( false === wp_untrash_post( $post->ID ) ) {
			throw new Exception( 'Push rejected because WordPress could not restore the trashed content for this path.' );
		}

		$restored = get_post( $post->ID );
		if ( ! $restored ) {
			throw new Exception( 'Push rejected because WordPress could not reload the restored content.' );
		}

		return $restored;
	}

	private static function find_post_id_by_path_metadata( $path, $metadata, $include_trash = true ) {
		$post_type = self::path_to_post_type( $path );
		if ( self::is_raw_block_post_type( $post_type ) ) {
			return self::find_raw_block_post_id_by_path( $path, $include_trash );
		}
		if ( 'wp_global_styles' === $post_type ) {
			return self::find_global_styles_post_id_by_theme_slug(
				self::path_to_global_styles_theme_slug( $path ),
				$include_trash
			);
		}
		if ( 'page' === $post_type ) {
			if ( isset( $metadata['id'] ) ) {
				return self::find_post_id_by_frontmatter_id( $path, $metadata, $include_trash );
			}

			return self::find_page_id_by_path( $path, $include_trash );
		}

		if ( isset( $metadata['id'] ) ) {
			return self::find_post_id_by_frontmatter_id( $path, $metadata, $include_trash );
		}

		$slug     = self::path_to_slug( $path );
		$statuses = $include_trash
			? array_merge( self::$supported_post_statuses, array( 'trash' ) )
			: self::$supported_post_statuses;
		$posts    = get_posts(
			array(
				'post_type'      => $post_type,
				'name'           => $slug,
				'post_status'    => $statuses,
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		if ( empty( $posts ) ) {
			if ( ! $include_trash ) {
				self::reject_unsupported_status_slug_collision( $post_type, $slug, self::$supported_post_statuses );
				return 0;
			}

			$posts = get_posts(
				array(
					'post_type'      => $post_type,
					'name'           => $slug . '__trashed',
					'post_status'    => array( 'trash' ),
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			);
			if ( empty( $posts ) ) {
				self::reject_unsupported_status_slug_collision(
					$post_type,
					$slug,
					array_merge( self::$supported_post_statuses, array( 'trash' ) )
				);
				return 0;
			}
		}

		return intval( $posts[0] );
	}

	private static function find_post_id_by_frontmatter_id( $path, $metadata, $include_trash ) {
		$post_type = self::path_to_post_type( $path );
		$id        = self::normalize_frontmatter_post_id( $metadata['id'] );
		$post      = get_post( $id );

		if ( ! $post ) {
			throw new Exception( 'Push rejected because Markdown front matter id does not reference an existing WordPress post.' );
		}
		if ( $post_type !== $post->post_type ) {
			throw new Exception( 'Push rejected because Markdown front matter id references a different WordPress post type.' );
		}

		$statuses = $include_trash
			? array_merge( self::$supported_post_statuses, array( 'trash' ) )
			: self::$supported_post_statuses;
		if ( ! in_array( $post->post_status, $statuses, true ) ) {
			throw new Exception( 'Push rejected because Markdown front matter id references a non-exported WordPress post.' );
		}

		$path_metadata = $metadata;
		unset( $path_metadata['id'] );
		$path_post_id = self::find_post_id_by_path_metadata( $path, $path_metadata, $include_trash );
		if ( $path_post_id && $path_post_id !== $id ) {
			throw new Exception( 'Push rejected because Markdown front matter id conflicts with the WordPress post already mapped to this file path.' );
		}

		return $id;
	}

	private static function normalize_frontmatter_post_id( $id ) {
		$id = trim( (string) $id );
		if ( ! preg_match( '/^[1-9][0-9]*$/', $id ) ) {
			throw new Exception( 'Push rejected because Markdown front matter id must be a positive integer.' );
		}

		return intval( $id );
	}

	private static function find_page_id_by_path( $path, $include_trash = true ) {
		$slugs     = self::path_to_page_slugs( $path );
		$slug      = array_pop( $slugs );
		$parent_id = self::resolve_page_parent_id( $slugs, $include_trash );
		$statuses  = $include_trash
			? array_merge( self::$supported_post_statuses, array( 'trash' ) )
			: self::$supported_post_statuses;
		$posts     = get_posts(
			array(
				'post_type'      => 'page',
				'name'           => $slug,
				'post_parent'    => $parent_id,
				'post_status'    => $statuses,
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		if ( empty( $posts ) ) {
			$page_id = self::find_slugless_page_id_by_fallback_slug( $slug, $parent_id, $statuses );
			if ( $page_id ) {
				return $page_id;
			}

			if ( ! $include_trash ) {
				self::reject_unsupported_status_slug_collision( 'page', $slug, self::$supported_post_statuses, $parent_id );
				return 0;
			}

			$posts = get_posts(
				array(
					'post_type'      => 'page',
					'name'           => $slug . '__trashed',
					'post_parent'    => $parent_id,
					'post_status'    => array( 'trash' ),
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			);
			if ( empty( $posts ) ) {
				$page_id = self::find_slugless_page_id_by_fallback_slug( $slug, $parent_id, array( 'trash' ) );
				if ( $page_id ) {
					return $page_id;
				}

				self::reject_unsupported_status_slug_collision(
					'page',
					$slug,
					array_merge( self::$supported_post_statuses, array( 'trash' ) ),
					$parent_id
				);
				return 0;
			}
		}

		return intval( $posts[0] );
	}

	private static function resolve_page_parent_id( $parent_slugs, $include_trash = true ) {
		$parent_id = 0;
		$statuses  = $include_trash
			? array_merge( self::$supported_post_statuses, array( 'trash' ) )
			: self::$supported_post_statuses;

		foreach ( $parent_slugs as $slug ) {
			$parents = get_posts(
				array(
					'post_type'      => 'page',
					'name'           => $slug,
					'post_parent'    => $parent_id,
					'post_status'    => $statuses,
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			);
			if ( empty( $parents ) ) {
				$page_id = self::find_slugless_page_id_by_fallback_slug( $slug, $parent_id, $statuses );
				if ( ! $page_id ) {
					throw new Exception( 'Push rejected because nested page paths must reference existing WordPress parent pages.' );
				}

				$parent_id = $page_id;
				continue;
			}

			$parent_id = intval( $parents[0] );
		}

		return $parent_id;
	}

	private static function find_slugless_page_id_by_fallback_slug( $slug, $parent_id, $statuses ) {
		$id = self::get_id_from_fallback_slug( 'page', $slug );
		if ( ! $id ) {
			return 0;
		}

		$post = get_post( $id );
		if (
			! $post ||
			'page' !== $post->post_type ||
			'' !== $post->post_name ||
			intval( $post->post_parent ) !== intval( $parent_id ) ||
			! in_array( $post->post_status, $statuses, true )
		) {
			return 0;
		}

		return intval( $post->ID );
	}

	private static function path_to_page_parent_id( $path, $include_trash = true ) {
		$slugs = self::path_to_page_slugs( $path );
		array_pop( $slugs );

		return self::resolve_page_parent_id( $slugs, $include_trash );
	}

	private static function reject_unsupported_status_slug_collision( $post_type, $slug, $allowed_statuses, $post_parent = null ) {
		$args = array(
			'post_type'      => $post_type,
			'name'           => $slug,
			'post_status'    => self::get_all_post_status_names(),
			'posts_per_page' => 1,
		);
		if ( null !== $post_parent ) {
			$args['post_parent'] = $post_parent;
		}

		$posts = get_posts( $args );
		if ( empty( $posts ) || in_array( $posts[0]->post_status, $allowed_statuses, true ) ) {
			return;
		}

		throw new Exception( 'Push rejected because a non-exported WordPress post already uses this file path slug.' );
	}

	private static function get_all_post_status_names() {
		$statuses = get_post_stati( array(), 'names' );
		if ( is_array( $statuses ) && ! empty( $statuses ) ) {
			return array_values( $statuses );
		}

		return array_merge( self::$supported_post_statuses, array( 'trash', 'auto-draft', 'inherit' ) );
	}

	private static function find_raw_block_post_id_by_path( $path, $include_trash = true ) {
		$identity = self::path_to_raw_block_identity( $path );
		$statuses = $include_trash
			? array_merge( self::$supported_post_statuses, array( 'trash' ) )
			: self::$supported_post_statuses;
		$posts    = get_posts(
			array(
				'post_type'      => $identity['post_type'],
				'name'           => $identity['slug'],
				'post_status'    => $statuses,
				'posts_per_page' => -1,
			)
		);

		foreach ( $posts as $post ) {
			if ( self::get_raw_block_post_theme_slug( $post ) === $identity['theme'] ) {
				return intval( $post->ID );
			}
		}

		return 0;
	}

	private static function find_global_styles_post_id_by_theme_slug( $theme_slug, $include_trash = true ) {
		$statuses = $include_trash
			? array_merge( self::$supported_post_statuses, array( 'trash' ) )
			: self::$supported_post_statuses;
		$posts    = get_posts(
			array(
				'post_type'      => 'wp_global_styles',
				'post_status'    => $statuses,
				'posts_per_page' => -1,
			)
		);

		foreach ( $posts as $post ) {
			if ( self::get_post_theme_slug( $post ) === $theme_slug ) {
				return intval( $post->ID );
			}
		}

		return 0;
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
			self::assert_markdown_slug_is_canonical( $segments[2] );
			return $segments[2];
		}
		if ( self::is_raw_block_path( $path ) ) {
			$identity = self::path_to_raw_block_identity( $path );
			if ( '' !== $identity['theme'] ) {
				return $identity['theme'] . '//' . $identity['slug'];
			}

			return $identity['slug'];
		}
		if ( self::is_global_styles_path( $path ) ) {
			return self::path_to_global_styles_theme_slug( $path );
		}

		$segments = explode( '/', ltrim( $path, '/' ) );
		if ( 'post' === $segments[0] && 2 !== count( $segments ) ) {
			throw new Exception( 'Push rejected because post Markdown files must use post/<slug>.md paths.' );
		}
		if ( 'page' === $segments[0] ) {
			$slugs = self::path_to_page_slugs( $path );

			return end( $slugs );
		}

		$basename = basename( $path );
		if ( 'md' !== pathinfo( $basename, PATHINFO_EXTENSION ) ) {
			throw new Exception( 'Push rejected because only Markdown files are supported.' );
		}

		$slug = pathinfo( $basename, PATHINFO_FILENAME );
		self::assert_markdown_slug_is_canonical( $slug );

		return $slug;
	}

	private static function path_to_page_slugs( $path ) {
		$segments = explode( '/', ltrim( $path, '/' ) );
		if ( count( $segments ) < 2 || 'page' !== $segments[0] ) {
			throw new Exception( 'Push rejected because page Markdown files must use page/<slug>.md or page/<parent>/<slug>.md paths.' );
		}

		$basename = array_pop( $segments );
		if ( 'md' !== pathinfo( $basename, PATHINFO_EXTENSION ) ) {
			throw new Exception( 'Push rejected because only Markdown files are supported.' );
		}

		$slugs   = array_slice( $segments, 1 );
		$slugs[] = pathinfo( $basename, PATHINFO_FILENAME );
		foreach ( $slugs as $slug ) {
			self::assert_markdown_slug_is_canonical( $slug );
		}

		return $slugs;
	}

	private static function assert_markdown_slug_is_canonical( $slug ) {
		if ( '' === $slug || sanitize_title( $slug ) !== $slug ) {
			throw new Exception( 'Push rejected because Markdown file slugs must already match WordPress slug formatting.' );
		}
	}

	private static function path_to_raw_block_identity( $path ) {
		$post_type = self::path_to_post_type( $path );
		$basename  = basename( $path );
		if ( 'html' !== strtolower( pathinfo( $basename, PATHINFO_EXTENSION ) ) ) {
			throw new Exception( 'Push rejected because template files must use the .html extension.' );
		}

		$relative_path = substr( ltrim( $path, '/' ), strlen( $post_type ) + 1 );
		$slug_path     = substr( $relative_path, 0, - strlen( '.html' ) );
		$theme_slug    = '';

		if ( self::is_theme_scoped_raw_block_post_type( $post_type ) ) {
			$parts = explode( '/', $slug_path );
			if ( count( $parts ) > 1 ) {
				$theme_slug = array_shift( $parts );
				self::assert_repository_slug_path_is_canonical(
					$theme_slug,
					'Push rejected because template theme path segments must already match WordPress slug formatting.'
				);
				$slug_path = implode( '/', $parts );
			}
		}
		self::assert_repository_slug_path_is_canonical(
			$slug_path,
			'Push rejected because template file slugs must already match WordPress slug formatting.'
		);

		return array(
			'post_type' => $post_type,
			'slug'      => str_replace( '/', '//', $slug_path ),
			'theme'     => $theme_slug,
		);
	}

	private static function path_to_global_styles_theme_slug( $path ) {
		$post_type = self::path_to_post_type( $path );
		$segments  = explode( '/', ltrim( $path, '/' ) );
		if ( 'wp_global_styles' !== $post_type || 2 !== count( $segments ) ) {
			throw new Exception( 'Push rejected because Global Styles files must use wp_global_styles/<theme>.json paths.' );
		}

		$basename = basename( $path );
		if ( 'json' !== strtolower( pathinfo( $basename, PATHINFO_EXTENSION ) ) ) {
			throw new Exception( 'Push rejected because Global Styles files must use the .json extension.' );
		}

		$theme_slug = pathinfo( $basename, PATHINFO_FILENAME );
		self::assert_repository_slug_path_is_canonical(
			$theme_slug,
			'Push rejected because the Global Styles theme filename must already match WordPress slug formatting.'
		);

		return $theme_slug;
	}

	private static function assert_repository_slug_path_is_canonical( $slug_path, $message ) {
		$segments = explode( '/', $slug_path );
		foreach ( $segments as $segment ) {
			if ( '' === $segment || sanitize_title( $segment ) !== $segment ) {
				throw new Exception( esc_html( $message ) );
			}
		}
	}

	private static function assign_raw_block_theme_slug( $post_id, $theme_slug ) {
		$post = get_post( $post_id );
		if (
			! $post ||
			'' === $theme_slug ||
			! self::is_theme_scoped_raw_block_post_type( $post->post_type )
		) {
			return;
		}

		self::assign_post_theme_slug( $post_id, $theme_slug );
	}

	private static function assign_post_theme_slug( $post_id, $theme_slug ) {
		if ( '' === $theme_slug || ! taxonomy_exists( 'wp_theme' ) ) {
			return;
		}

		$terms = wp_set_object_terms( $post_id, array( $theme_slug ), 'wp_theme' );
		if ( is_wp_error( $terms ) ) {
			throw new Exception( esc_html( $terms->get_error_message() ) );
		}
	}

	private static function is_raw_block_path( $path ) {
		$segments = explode( '/', ltrim( $path, '/' ) );

		return ! empty( $segments[0] ) && self::is_raw_block_post_type( $segments[0] );
	}

	private static function is_theme_base_path( $path ) {
		$segments = explode( '/', ltrim( $path, '/' ) );

		return ! empty( $segments[0] ) && 'wp_theme' === $segments[0];
	}

	private static function is_global_styles_path( $path ) {
		$segments = explode( '/', ltrim( $path, '/' ) );

		return ! empty( $segments[0] ) && 'wp_global_styles' === $segments[0];
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

		self::assert_markdown_front_matter_is_closed( $markdown );
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
			throw new Exception( esc_html( $inserted->get_error_message() ) );
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
				esc_html( $post_status ),
				esc_html( $post_type )
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
		$commands = self::parse_push_commands( $request_bytes );
		if ( empty( $commands ) ) {
			return false;
		}
		if ( 1 !== count( $commands ) ) {
			return array(
				'error' => 'Push rejected because Push MD only accepts one ref update at a time.',
			);
		}

		$command = $commands[0];
		if ( 'refs/heads/' . self::DEFAULT_BRANCH !== $command['ref'] ) {
			return array(
				'error' => 'Push rejected because Push MD only accepts pushes to trunk.',
			);
		}
		if ( Commit::is_null_hash( $command['new_oid'] ) ) {
			return array(
				'error' => 'Push rejected because deleting trunk is not supported.',
			);
		}

		return array(
			'old_oid' => $command['old_oid'],
			'new_oid' => $command['new_oid'],
		);
	}

	private static function parse_push_commands( $request_bytes ) {
		$commands = array();
		$offset   = 0;
		$length   = strlen( $request_bytes );

		while ( $offset + 4 <= $length ) {
			$line_length_hex = substr( $request_bytes, $offset, 4 );
			if ( ! ctype_xdigit( $line_length_hex ) ) {
				break;
			}

			$line_length = hexdec( $line_length_hex );
			$offset     += 4;
			if ( 0 === $line_length ) {
				break;
			}
			if ( $line_length < 4 || $offset + $line_length - 4 > $length ) {
				break;
			}

			$line    = substr( $request_bytes, $offset, $line_length - 4 );
			$offset += $line_length - 4;
			$line    = explode( "\0", $line, 2 );
			$line    = rtrim( $line[0], "\r\n" );

			if (
				preg_match(
					'/\A(?P<old_oid>[0-9a-f]{40}) (?P<new_oid>[0-9a-f]{40}) (?P<ref>\S+)\z/',
					$line,
					$matches
				)
			) {
				$commands[] = array(
					'old_oid' => $matches['old_oid'],
					'new_oid' => $matches['new_oid'],
					'ref'     => $matches['ref'],
				);
			}
		}

		return $commands;
	}

	private static function assert_can_edit_post( $post_id ) {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			throw new Exception( 'Push rejected because you do not have permission to edit one or more posts in this change.' );
		}
	}

	private static function assert_can_create_post_type( $post_type ) {
		$post_type_object = get_post_type_object( $post_type );
		$create_posts_cap = $post_type_object && isset( $post_type_object->cap->create_posts ) ? $post_type_object->cap->create_posts : '';
		if ( '' === $create_posts_cap && $post_type_object && isset( $post_type_object->cap->edit_posts ) ) {
			$create_posts_cap = $post_type_object->cap->edit_posts;
		}
		if ( '' === $create_posts_cap ) {
			$create_posts_cap = 'edit_posts';
		}
		if ( ! current_user_can( $create_posts_cap ) ) {
			throw new Exception( 'Push rejected because you do not have permission to create this post type.' );
		}
	}

	private static function assert_can_set_post_status( $post_type, $post_status, $existing_post = null ) {
		if ( $existing_post && $existing_post->post_status === $post_status ) {
			return;
		}
		if ( ! in_array( $post_status, array( 'publish', 'future', 'private' ), true ) ) {
			return;
		}

		$post_type_object  = get_post_type_object( $post_type );
		$publish_posts_cap = $post_type_object && isset( $post_type_object->cap->publish_posts )
			? $post_type_object->cap->publish_posts
			: 'publish_posts';
		if ( ! current_user_can( $publish_posts_cap ) ) {
			throw new Exception( 'Push rejected because you do not have permission to publish this post type.' );
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

		throw new ErrorException( esc_html( $message ), 0, (int) $severity, esc_html( $file ), (int) $line );
	}
}
