<?php

use WordPress\DataLiberation\DataFormatConsumer\BlocksWithMetadata;
use WordPress\Filesystem\InMemoryFilesystem;
use WordPress\Git\GitEndpoint;
use WordPress\Git\GitException;
use WordPress\Git\GitRepository;
use WordPress\Git\Model\Commit;
use WordPress\Git\Model\TreeEntry;
use WordPress\Markdown\MarkdownConsumer;
use WordPress\Markdown\MarkdownProducer;

class WP_Origin_Plugin {
	const DEFAULT_BRANCH          = 'trunk';
	const ROUTE_NAMESPACE         = 'git/v1';
	const ROUTE_PATTERN           = '/md\.git(?P<path>/.*)?';
	const EPOCH_TIMESTAMP         = 946684800;
	const COMMIT_POST_TYPE        = 'wp_origin_commit';
	const HEAD_OPTION             = 'wp_origin_head_commit_id';
	const MARKDOWN_META_KEY       = '_wp_origin_markdown';
	const COMMIT_MESSAGE_META_KEY = '_wp_origin_commit_message';
	const COMMIT_AUTHOR_META_KEY  = '_wp_origin_commit_author';
	const COMMIT_AUTHOR_DATE_META = '_wp_origin_commit_author_date';
	const COMMITTER_META_KEY      = '_wp_origin_committer';
	const COMMITTER_DATE_META     = '_wp_origin_committer_date';

	private static $supported_post_types    = array( 'post', 'page' );
	private static $supported_post_statuses = array( 'publish', 'draft', 'pending', 'private', 'future' );

	public static function bootstrap() {
		add_action( 'init', array( __CLASS__, 'register_commit_post_type' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'add_authentication_challenge' ), 10, 3 );
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'serve_git_response' ), 10, 4 );
	}

	public static function register_commit_post_type() {
		register_post_type(
			self::COMMIT_POST_TYPE,
			array(
				'public'             => false,
				'show_ui'            => false,
				'show_in_rest'       => false,
				'exclude_from_search' => true,
				'query_var'          => false,
				'rewrite'            => false,
				'delete_with_user'   => false,
				'hierarchical'       => true,
				'supports'           => array( 'title', 'editor', 'author', 'page-attributes' ),
				'labels'             => array(
					'name' => 'WP Origin Commits',
				),
			)
		);
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
					self::apply_repository_changes_to_wordpress(
						$repository,
						$push_header['old_oid'],
						$push_header['new_oid']
					);
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
		$repository = new GitRepository(
			InMemoryFilesystem::create(),
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
		$repository->set_branch_tip( 'HEAD', 'ref: refs/heads/' . self::DEFAULT_BRANCH );

		self::load_repository_history( $repository );

		return $repository;
	}

	private static function load_repository_history( GitRepository $repository ) {
		$commits           = self::get_persisted_commits();
		$previous_manifest = array();

		foreach ( $commits as $commit_post ) {
			$manifest = self::get_commit_manifest_from_post( $commit_post );
			$updates  = self::get_manifest_update_bytes( $previous_manifest, $manifest );
			$deletes  = self::get_manifest_deletes( $previous_manifest, $manifest );
			$commit   = array(
				'message'        => (string) get_post_meta( $commit_post->ID, self::COMMIT_MESSAGE_META_KEY, true ),
				'author'         => (string) get_post_meta( $commit_post->ID, self::COMMIT_AUTHOR_META_KEY, true ),
				'author_date'    => (string) get_post_meta( $commit_post->ID, self::COMMIT_AUTHOR_DATE_META, true ),
				'committer'      => (string) get_post_meta( $commit_post->ID, self::COMMITTER_META_KEY, true ),
				'committer_date' => (string) get_post_meta( $commit_post->ID, self::COMMITTER_DATE_META, true ),
			);

			$commit_oid = $repository->commit(
				array(
					'updates' => $updates,
					'deletes' => $deletes,
					'commit'  => $commit,
				)
			);

			if ( $commit_oid !== $commit_post->post_name ) {
				throw new Exception(
					sprintf(
						'Stored WP Origin history does not match the reconstructed Git history for commit %s (rebuilt as %s).',
						$commit_post->post_name,
						$commit_oid
					)
				);
			}

			$previous_manifest = $manifest;
		}
	}

	private static function get_persisted_commits() {
		$head_commit_id = intval( get_option( self::HEAD_OPTION, 0 ) );
		if ( ! $head_commit_id ) {
			return array();
		}

		$commits = array();
		$visited = array();
		while ( $head_commit_id ) {
			if ( isset( $visited[ $head_commit_id ] ) ) {
				throw new Exception( 'WP Origin commit history is cyclic.' );
			}
			$visited[ $head_commit_id ] = true;

			$commit_post = get_post( $head_commit_id );
			if ( ! $commit_post || self::COMMIT_POST_TYPE !== $commit_post->post_type ) {
				throw new Exception( 'WP Origin HEAD points to a missing commit.' );
			}

			$commits[]      = $commit_post;
			$head_commit_id = intval( $commit_post->post_parent );
		}

		return array_reverse( $commits );
	}

	private static function get_head_commit_post() {
		$head_commit_id = intval( get_option( self::HEAD_OPTION, 0 ) );
		if ( ! $head_commit_id ) {
			return null;
		}

		$commit_post = get_post( $head_commit_id );
		if ( ! $commit_post || self::COMMIT_POST_TYPE !== $commit_post->post_type ) {
			return null;
		}

		return $commit_post;
	}

	private static function get_commit_post_by_oid( $oid ) {
		$commits = get_posts(
			array(
				'name'           => $oid,
				'post_type'      => self::COMMIT_POST_TYPE,
				'post_status'    => 'private',
				'posts_per_page' => 1,
			)
		);

		if ( empty( $commits ) ) {
			return null;
		}

		return $commits[0];
	}

	private static function get_commit_manifest_from_post( WP_Post $commit_post ) {
		$manifest = json_decode( $commit_post->post_content, true );
		if ( ! is_array( $manifest ) ) {
			return array();
		}

		foreach ( $manifest as $path => $revision_id ) {
			$manifest[ $path ] = intval( $revision_id );
		}
		ksort( $manifest );

		return $manifest;
	}

	private static function sync_repository_from_wordpress( GitRepository $repository ) {
		$exported_files    = self::export_wordpress_content();
		$head_commit_post  = self::get_head_commit_post();
		$previous_manifest = $head_commit_post ? self::get_commit_manifest_from_post( $head_commit_post ) : array();
		$new_manifest      = array();
		$commit_timestamp  = self::EPOCH_TIMESTAMP;

		foreach ( $exported_files as $path => $entry ) {
			$markdown = $entry['markdown'];
			$post     = $entry['post'];

			if ( isset( $previous_manifest[ $path ] ) && self::get_markdown_for_revision( $previous_manifest[ $path ] ) === $markdown ) {
				$new_manifest[ $path ] = $previous_manifest[ $path ];
			} else {
				$new_manifest[ $path ] = self::capture_post_snapshot( $post, $markdown );
			}

			$maybe_timestamp = self::timestamp_from_gmt_string( $post->post_modified_gmt );
			if ( false !== $maybe_timestamp ) {
				$commit_timestamp = max( $commit_timestamp, $maybe_timestamp );
			} else {
				$maybe_timestamp = self::timestamp_from_gmt_string( $post->post_date_gmt );
				if ( false !== $maybe_timestamp ) {
					$commit_timestamp = max( $commit_timestamp, $maybe_timestamp );
				}
			}
		}

		if ( self::manifests_are_equal( $previous_manifest, $new_manifest ) ) {
			return;
		}

		self::commit_manifest_to_repository(
			$repository,
			$new_manifest,
			array(
				'message'        => 'Sync from WordPress',
				'author'         => self::get_repository_identity( $repository ),
				'author_date'    => gmdate( Commit::DATE_FORMAT, $commit_timestamp ),
				'committer'      => self::get_repository_identity( $repository ),
				'committer_date' => gmdate( Commit::DATE_FORMAT, $commit_timestamp ),
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

	private static function commit_manifest_to_repository( GitRepository $repository, $manifest, $commit_meta ) {
		$head_commit_post  = self::get_head_commit_post();
		$previous_manifest = $head_commit_post ? self::get_commit_manifest_from_post( $head_commit_post ) : array();
		$updates           = self::get_manifest_update_bytes( $previous_manifest, $manifest );
		$deletes           = self::get_manifest_deletes( $previous_manifest, $manifest );
		$commit_oid        = $repository->commit(
			array(
				'updates' => $updates,
				'deletes' => $deletes,
				'commit'  => $commit_meta,
			)
		);

		self::persist_repository_commit( $repository, $commit_oid, $manifest );

		return $commit_oid;
	}

	private static function persist_repository_commit( GitRepository $repository, $commit_oid, $manifest ) {
		$existing_commit = self::get_commit_post_by_oid( $commit_oid );
		if ( $existing_commit ) {
			update_option( self::HEAD_OPTION, $existing_commit->ID, false );

			return $existing_commit->ID;
		}

		$commit            = $repository->read_object( $commit_oid )->as_commit();
		$parent_commit_oid = empty( $commit->parents ) ? Commit::NULL_HASH : $commit->get_first_parent_hash();
		$parent_commit     = Commit::is_null_hash( $parent_commit_oid ) ? null : self::get_commit_post_by_oid( $parent_commit_oid );
		if ( ! Commit::is_null_hash( $parent_commit_oid ) && ! $parent_commit ) {
			throw new Exception( 'Push rejected because the parent commit is missing from WordPress storage.' );
		}

		$commit_date_gmt = self::git_date_to_mysql_gmt( $commit->committer_date );
		$postarr         = array(
			'post_type'    => self::COMMIT_POST_TYPE,
			'post_status'  => 'private',
			'post_name'    => $commit_oid,
			'post_title'   => self::get_commit_subject( $commit->message ),
			'post_content' => self::encode_manifest( $manifest ),
			'post_parent'  => $parent_commit ? $parent_commit->ID : 0,
		);

		if ( $commit_date_gmt ) {
			$postarr['post_date_gmt'] = $commit_date_gmt;
			$postarr['post_date']     = get_date_from_gmt( $commit_date_gmt );
		}
		if ( get_current_user_id() ) {
			$postarr['post_author'] = get_current_user_id();
		}

		$commit_post_id = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $commit_post_id ) ) {
			throw new Exception( $commit_post_id->get_error_message() );
		}

		update_post_meta( $commit_post_id, self::COMMIT_MESSAGE_META_KEY, $commit->message );
		update_post_meta( $commit_post_id, self::COMMIT_AUTHOR_META_KEY, $commit->author );
		update_post_meta( $commit_post_id, self::COMMIT_AUTHOR_DATE_META, $commit->author_date );
		update_post_meta( $commit_post_id, self::COMMITTER_META_KEY, $commit->committer );
		update_post_meta( $commit_post_id, self::COMMITTER_DATE_META, $commit->committer_date );
		update_option( self::HEAD_OPTION, $commit_post_id, false );

		return $commit_post_id;
	}

	private static function apply_repository_changes_to_wordpress( GitRepository $repository, $old_commit, $new_commit ) {
		$commit_plans      = self::build_push_plan( $repository, $old_commit, $new_commit );
		$head_commit_post  = self::get_head_commit_post();
		$previous_manifest = $head_commit_post ? self::get_commit_manifest_from_post( $head_commit_post ) : array();

		foreach ( $commit_plans as $commit_plan ) {
			$previous_manifest = self::apply_single_commit_plan_to_wordpress( $commit_plan, $previous_manifest );
			self::persist_repository_commit( $repository, $commit_plan['commit_hash'], $previous_manifest );
		}
	}

	private static function build_push_plan( GitRepository $repository, $old_commit, $new_commit ) {
		$commit_hashes     = self::get_push_commit_hashes( $repository, $old_commit, $new_commit );
		$head_commit_post  = self::get_head_commit_post();
		$previous_manifest = $head_commit_post ? self::get_commit_manifest_from_post( $head_commit_post ) : array();
		$commit_plans      = array();

		foreach ( $commit_hashes as $index => $commit_hash ) {
			$commit = $repository->read_object( $commit_hash )->as_commit();
			self::validate_push_commit( $repository, $commit );

			$parent_hash = empty( $commit->parents ) ? Commit::NULL_HASH : $commit->get_first_parent_hash();
			$old_files   = Commit::is_null_hash( $parent_hash ) ? array() : self::read_markdown_files_from_commit( $repository, $parent_hash );
			$new_files   = self::read_markdown_files_from_commit( $repository, $commit_hash );

			$commit_plan                = self::build_single_commit_plan(
				$old_files,
				$new_files,
				$previous_manifest,
				0 !== $index
			);
			$commit_plan['commit_hash'] = $commit_hash;
			$commit_plans[]             = $commit_plan;
			$previous_manifest          = $commit_plan['manifest'];
		}

		return $commit_plans;
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

	private static function build_single_commit_plan( $old_files, $new_files, $previous_manifest, $skip_modified_checks ) {
		$next_manifest    = $previous_manifest;
		$updated_post_ids = array();
		$operations       = array();

		foreach ( $new_files as $path => $contents ) {
			if ( isset( $old_files[ $path ] ) && $old_files[ $path ] === $contents ) {
				continue;
			}

			$operation              = self::plan_post_upsert_from_markdown(
				$path,
				$contents,
				array(
					'skip_modified_check' => $skip_modified_checks,
				)
			);
			$operations[]           = $operation;
			$next_manifest[ $path ] = 0;
			if ( $operation['post_id'] ) {
				$updated_post_ids[ $operation['post_id'] ] = true;
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

			unset( $next_manifest[ $path ] );

			if ( isset( $updated_post_ids[ $post_id ] ) ) {
				continue;
			}

			if ( $post_id && ! $skip_modified_checks && isset( $metadata['modified_gmt'] ) ) {
				$current_modified = get_post_field( 'post_modified_gmt', $post_id );
				if ( $current_modified && $current_modified !== $metadata['modified_gmt'] ) {
					throw new Exception( 'Push rejected because a deleted post changed in WordPress. Pull the latest changes and try again.' );
				}
			}
			if ( $post_id ) {
				self::assert_can_edit_post( $post_id );
			}

			$operations[] = array(
				'type'     => 'delete',
				'path'     => $path,
				'metadata' => $metadata,
			);
		}

		ksort( $next_manifest );

		return array(
			'manifest'   => $next_manifest,
			'operations' => $operations,
		);
	}

	private static function apply_single_commit_plan_to_wordpress( $commit_plan, $previous_manifest ) {
		$next_manifest    = $previous_manifest;
		$updated_post_ids = array();

		foreach ( $commit_plan['operations'] as $operation ) {
			if ( 'upsert' === $operation['type'] ) {
				$post_id                             = self::apply_post_upsert_plan( $operation );
				$updated_post_ids[ $post_id ]        = true;
				$next_manifest[ $operation['path'] ] = self::capture_post_snapshot( get_post( $post_id ), $operation['contents'] );
				continue;
			}

			if ( 'delete' === $operation['type'] ) {
				unset( $next_manifest[ $operation['path'] ] );
				self::apply_post_delete_plan( $operation, $updated_post_ids );
			}
		}

		ksort( $next_manifest );

		return $next_manifest;
	}

	private static function upsert_post_from_markdown( $path, $markdown, $options = array() ) {
		return self::apply_post_upsert_plan(
			self::plan_post_upsert_from_markdown( $path, $markdown, $options )
		);
	}

	private static function plan_post_upsert_from_markdown( $path, $markdown, $options = array() ) {
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

		return array(
			'type'      => 'upsert',
			'path'      => $path,
			'contents'  => $markdown,
			'metadata'  => $metadata,
			'post_id'   => $post_id,
			'postarr'   => $postarr,
		);
	}

	private static function apply_post_upsert_plan( $operation ) {
		$metadata = $operation['metadata'];
		$postarr  = $operation['postarr'];
		$post_id  = isset( $metadata['id'] ) ? intval( $metadata['id'] ) : 0;
		if ( $post_id && get_post( $post_id ) ) {
			$existing_post = get_post( $post_id );
		} else {
			$post_id       = self::find_post_id_by_path_metadata( $operation['path'], $metadata );
			$existing_post = $post_id ? get_post( $post_id ) : null;
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

		return $post_id;
	}

	private static function apply_post_delete_plan( $operation, $updated_post_ids ) {
		$metadata = $operation['metadata'];
		$post_id  = isset( $metadata['id'] ) ? intval( $metadata['id'] ) : 0;
		if ( ! $post_id ) {
			$post_id = self::find_post_id_by_path_metadata( $operation['path'], $metadata );
		}

		if ( ! $post_id || isset( $updated_post_ids[ $post_id ] ) ) {
			return;
		}

		if ( false === wp_trash_post( $post_id ) ) {
			throw new Exception( 'Push rejected because WordPress could not trash the deleted content.' );
		}
	}

	private static function capture_post_snapshot( WP_Post $post, $markdown ) {
		$revision_id = 0;

		if ( function_exists( '_wp_put_post_revision' ) ) {
			$revision_id = _wp_put_post_revision( get_post( $post->ID, ARRAY_A ) );
		}

		if ( ! $revision_id && function_exists( 'wp_save_post_revision' ) ) {
			wp_save_post_revision( $post->ID );
			$revisions = wp_get_post_revisions(
				$post->ID,
				array(
					'posts_per_page' => 1,
					'orderby'        => 'ID',
					'order'          => 'DESC',
				)
			);
			if ( ! empty( $revisions ) ) {
				$revision    = reset( $revisions );
				$revision_id = $revision->ID;
			}
		}

		if ( is_wp_error( $revision_id ) || ! $revision_id ) {
			throw new Exception( 'WP Origin could not create a revision snapshot for this post.' );
		}

		update_metadata( 'post', $revision_id, self::MARKDOWN_META_KEY, $markdown );

		return intval( $revision_id );
	}

	private static function get_manifest_update_bytes( $previous_manifest, $manifest ) {
		$updates = array();

		foreach ( $manifest as $path => $revision_id ) {
			if ( isset( $previous_manifest[ $path ] ) && intval( $previous_manifest[ $path ] ) === intval( $revision_id ) ) {
				continue;
			}

			$updates[ $path ] = self::get_markdown_for_revision( $revision_id );
		}

		return $updates;
	}

	private static function get_manifest_deletes( $previous_manifest, $manifest ) {
		$deletes = array();

		foreach ( $previous_manifest as $path => $revision_id ) {
			unset( $revision_id );
			if ( ! isset( $manifest[ $path ] ) ) {
				$deletes[] = $path;
			}
		}

		return $deletes;
	}

	private static function get_markdown_for_revision( $revision_id ) {
		$markdown = get_metadata( 'post', $revision_id, self::MARKDOWN_META_KEY, true );
		if ( ! is_string( $markdown ) ) {
			throw new Exception( 'WP Origin is missing Markdown bytes for a stored revision snapshot.' );
		}

		return $markdown;
	}

	private static function manifests_are_equal( $left, $right ) {
		ksort( $left );
		ksort( $right );

		return $left === $right;
	}

	private static function encode_manifest( $manifest ) {
		ksort( $manifest );
		$encoded_manifest = wp_json_encode( $manifest );
		if ( false === $encoded_manifest ) {
			throw new Exception( 'WP Origin could not encode the commit manifest.' );
		}

		return $encoded_manifest;
	}

	private static function get_repository_identity( GitRepository $repository ) {
		return $repository->get_config_value( 'user.name' ) . ' <' . $repository->get_config_value( 'user.email' ) . '>';
	}

	private static function get_commit_subject( $message ) {
		$lines = preg_split( "/\r\n|\n|\r/", $message );
		$line  = is_array( $lines ) && isset( $lines[0] ) ? trim( $lines[0] ) : '';

		return '' === $line ? 'WP Origin Commit' : $line;
	}

	private static function timestamp_from_gmt_string( $gmt_string ) {
		if ( ! is_string( $gmt_string ) || '' === $gmt_string || '0000-00-00 00:00:00' === $gmt_string ) {
			return false;
		}

		return strtotime( $gmt_string . ' UTC' );
	}

	private static function git_date_to_mysql_gmt( $git_date ) {
		if ( ! is_string( $git_date ) ) {
			return '';
		}
		if ( ! preg_match( '/^(\d+)\s+[+-]\d{4}$/', $git_date, $matches ) ) {
			return '';
		}

		return gmdate( 'Y-m-d H:i:s', intval( $matches[1] ) );
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
