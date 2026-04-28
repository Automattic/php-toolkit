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
	const DEFAULT_BRANCH  = 'trunk';
	const ROUTE_NAMESPACE = 'git/v1';
	const ROUTE_PATTERN   = '/md\.git(?P<path>/.*)?';
	const EPOCH_TIMESTAMP = 946684800;
	const TABLE_PREFIX    = 'wp_origin_';

	private static $supported_post_types    = array( 'post', 'page' );
	private static $supported_post_statuses = array( 'publish', 'draft', 'pending', 'private', 'future' );

	public static function bootstrap() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'add_authentication_challenge' ), 10, 3 );
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'serve_git_response' ), 10, 4 );
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

	private static function open_repository() {
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
			? self::read_markdown_files_from_commit( $repository, $head_oid )
			: array();

		$updates          = array();
		$deletes          = array();
		$commit_timestamp = self::EPOCH_TIMESTAMP;

		foreach ( $exported_files as $path => $entry ) {
			$markdown = $entry['markdown'];
			$post     = $entry['post'];

			if ( ! isset( $existing_files[ $path ] ) || $existing_files[ $path ] !== $markdown ) {
				$updates[ $path ] = $markdown;
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

		if ( empty( $updates ) && empty( $deletes ) ) {
			return;
		}

		$identity = self::get_repository_identity( $repository );
		$date     = gmdate( Commit::DATE_FORMAT, $commit_timestamp );
		$repository->commit(
			array(
				'updates' => $updates,
				'deletes' => $deletes,
				'commit'  => array(
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
				'post_type'      => self::$supported_post_types,
				'post_status'    => self::$supported_post_statuses,
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		$files = array();
		foreach ( $posts as $post ) {
			if ( ! current_user_can( 'read_post', $post->ID ) ) {
				continue;
			}

			$metadata = array(
				'id'           => array( (string) $post->ID ),
				'type'         => array( $post->post_type ),
				'slug'         => array( $post->post_name ),
				'status'       => array( $post->post_status ),
				'title'        => array( $post->post_title ),
				'date_gmt'     => array( $post->post_date_gmt ),
				'modified_gmt' => array( $post->post_modified_gmt ),
			);

			$producer = new MarkdownProducer(
				new BlocksWithMetadata(
					$post->post_content,
					$metadata
				)
			);
			$path     = self::build_markdown_path( $post->post_type, $post->post_name );

			$files[ $path ] = array(
				'post'     => $post,
				'markdown' => $producer->produce(),
			);
		}

		ksort( $files );

		return $files;
	}

	private static function build_markdown_path( $post_type, $slug ) {
		return ltrim( $post_type . '/' . $slug . '.md', '/' );
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
			: self::read_markdown_files_from_commit( $repository, $parent_hash );
		$new_files   = self::read_markdown_files_from_commit( $repository, $commit_hash );

		$updated_post_ids = array();
		$changes          = array();

		foreach ( $new_files as $path => $contents ) {
			if ( isset( $old_files[ $path ] ) && $old_files[ $path ] === $contents ) {
				continue;
			}

			$applied = self::upsert_post_from_markdown(
				$path,
				$contents,
				array( 'skip_modified_check' => $skip_modified_checks )
			);
			$post_id = $applied['post_id'];
			if ( $post_id ) {
				$updated_post_ids[ $post_id ] = true;
				$changes[]                    = $applied['change'];
			}
		}

		foreach ( $old_files as $path => $contents ) {
			if ( isset( $new_files[ $path ] ) ) {
				continue;
			}

			$metadata = self::parse_markdown_metadata( $contents );
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
		$consumer  = new MarkdownConsumer( $markdown );
		$result    = $consumer->consume();
		$metadata  = array();
		foreach ( $result->get_all_metadata() as $key => $value ) {
			$metadata[ $key ] = is_array( $value ) ? reset( $value ) : $value;
		}

		if ( isset( $metadata['type'] ) && $metadata['type'] !== $post_type ) {
			throw new Exception( 'Push rejected because the file post type does not match its directory.' );
		}
		if ( isset( $metadata['slug'] ) && $metadata['slug'] !== $slug ) {
			throw new Exception( 'Push rejected because the file slug does not match its filename.' );
		}
		self::validate_post_status( isset( $metadata['status'] ) ? $metadata['status'] : 'draft', $post_type );

		$post_id = isset( $metadata['id'] ) ? intval( $metadata['id'] ) : 0;
		if ( $post_id && get_post( $post_id ) ) {
			$existing_post = get_post( $post_id );
		} else {
			$post_id       = self::find_post_id_by_path_metadata( $path, $metadata );
			$existing_post = $post_id ? get_post( $post_id ) : null;
		}

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
			'post_status'  => isset( $metadata['status'] ) ? $metadata['status'] : 'draft',
			'post_content' => $result->get_block_markup(),
		);

		if ( isset( $metadata['date_gmt'] ) && '' !== $metadata['date_gmt'] ) {
			$postarr['post_date_gmt'] = $metadata['date_gmt'];
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
		if ( empty( $segments[0] ) || ! in_array( $segments[0], self::$supported_post_types, true ) ) {
			throw new Exception( 'Push rejected because the file path is outside the supported post type directories.' );
		}

		return $segments[0];
	}

	private static function path_to_slug( $path ) {
		$basename = basename( $path );
		if ( 'md' !== pathinfo( $basename, PATHINFO_EXTENSION ) ) {
			throw new Exception( 'Push rejected because only Markdown files are supported.' );
		}

		return pathinfo( $basename, PATHINFO_FILENAME );
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

	private static function read_markdown_files_from_commit( GitRepository $repository, $commit_hash ) {
		$commit = $repository->read_object( $commit_hash )->as_commit();
		$files  = array();

		if ( Commit::is_null_hash( $commit->tree ) ) {
			return $files;
		}

		self::collect_tree_files( $repository, $commit->tree, '', $files );
		ksort( $files );

		return $files;
	}

	private static function collect_tree_files( GitRepository $repository, $tree_hash, $prefix, &$files ) {
		$tree = $repository->read_object( $tree_hash )->as_tree();
		foreach ( $tree->entries as $entry ) {
			$path = ltrim( $prefix . '/' . $entry->name, '/' );
			if ( TreeEntry::FILE_MODE_DIRECTORY === $entry->get_mode_bucket() ) {
				self::collect_tree_files( $repository, $entry->hash, $path, $files );
				continue;
			}
			if ( 'md' !== pathinfo( $path, PATHINFO_EXTENSION ) ) {
				throw new Exception( 'Push rejected because only Markdown files are supported.' );
			}
			$files[ $path ] = $repository->read_object( $entry->hash )->consume_all();
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
