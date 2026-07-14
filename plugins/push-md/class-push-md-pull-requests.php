<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores preview branches as Pull Requests and exposes their review data.
 */
class Push_MD_Pull_Requests {

	const POST_TYPE     = 'push_md_pull_request';
	const STATUS_ACTIVE = 'push_md_active';
	const STATUS_MERGED = 'push_md_merged';
	const STATUS_CLOSED = 'push_md_closed';

	private static $review_states = null;

	public static function bootstrap() {
		add_action( 'init', array( __CLASS__, 'register_data_model' ), 5 );
		add_action( 'init', array( __CLASS__, 'migrate_legacy_branch_previews' ), 30 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_fields' ) );
		add_filter( 'rest_pre_insert_' . self::POST_TYPE, array( __CLASS__, 'validate_rest_pull_request_write' ), 10, 2 );
		add_filter( 'rest_pre_insert_comment', array( __CLASS__, 'validate_rest_note' ), 10, 2 );
		add_action( 'rest_after_insert_comment', array( __CLASS__, 'store_note_tip_oid' ), 10, 3 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'guard_review_routes' ), 10, 3 );
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'protect_review_request' ), 10, 3 );
		add_filter( 'notify_post_author', array( __CLASS__, 'disable_note_notifications' ), 10, 2 );
		add_filter( 'notify_moderator', array( __CLASS__, 'disable_note_notifications' ), 10, 2 );
	}

	public static function register_data_model() {
		$review_states = array_keys( self::get_review_states() );

		register_post_status(
			self::STATUS_ACTIVE,
			array(
				'label'       => __( 'Open', 'push-md' ),
				'public'      => false,
				'internal'    => true,
				'show_in_rest' => true,
			)
		);
		register_post_status(
			self::STATUS_MERGED,
			array(
				'label'       => __( 'Merged', 'push-md' ),
				'public'      => false,
				'internal'    => true,
				'show_in_rest' => true,
			)
		);
		register_post_status(
			self::STATUS_CLOSED,
			array(
				'label'       => __( 'Closed', 'push-md' ),
				'public'      => false,
				'internal'    => true,
				'show_in_rest' => true,
			)
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'label'              => __( 'Push MD Pull Requests', 'push-md' ),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => false,
				'show_in_rest'       => true,
				'rest_base'          => 'push-md-pull-requests',
				'supports'           => array(
					'title',
					'author',
					'comments',
					'custom-fields',
					'editor' => array( 'notes' => true ),
				),
				'capabilities'       => array(
					'read_post'          => 'manage_options',
					'read_private_posts' => 'manage_options',
					'edit_post'          => 'manage_options',
					'edit_posts'         => 'manage_options',
					'edit_others_posts'  => 'manage_options',
					'create_posts'       => 'do_not_allow',
					'publish_posts'      => 'do_not_allow',
					'delete_post'        => 'do_not_allow',
					'delete_posts'       => 'do_not_allow',
				),
			)
		);

		$post_meta = array(
			'push_md_branch'      => 'string',
			'push_md_base_oid'    => 'string',
			'push_md_tip_oid'     => 'string',
			'push_md_merged_oid'  => 'string',
			'push_md_merged_by'   => 'integer',
		);
		foreach ( $post_meta as $meta_key => $type ) {
			register_post_meta(
				self::POST_TYPE,
				$meta_key,
				array(
					'type'              => $type,
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'integer' === $type ? 'absint' : 'sanitize_text_field',
					'auth_callback'     => array( __CLASS__, 'can_access_review_meta' ),
				)
			);
		}
		register_post_meta(
			self::POST_TYPE,
			'push_md_review_state',
			array(
				'type'              => 'string',
				'single'            => true,
				'default'           => 'pending',
				'show_in_rest'      => array(
					'schema' => array(
						'type'    => 'string',
						'enum'    => $review_states,
						'default' => 'pending',
					),
				),
				'sanitize_callback' => array( __CLASS__, 'sanitize_review_state' ),
				'auth_callback'     => array( __CLASS__, 'can_access_review_meta' ),
			)
		);

		register_meta(
			'comment',
			'push_md_path',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => array( __CLASS__, 'can_access_note_meta' ),
			)
		);
		register_meta(
			'comment',
			'push_md_side',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => array(
					'schema' => array(
						'type' => 'string',
						'enum' => array( 'old', 'new' ),
					),
				),
				'sanitize_callback' => 'sanitize_key',
				'auth_callback'     => array( __CLASS__, 'can_access_note_meta' ),
			)
		);
		register_meta(
			'comment',
			'push_md_line',
			array(
				'type'              => 'integer',
				'single'            => true,
				'show_in_rest'      => array(
					'schema' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'sanitize_callback' => 'absint',
				'auth_callback'     => array( __CLASS__, 'can_access_note_meta' ),
			)
		);
		register_meta(
			'comment',
			'push_md_review_state',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => array(
					'schema' => array(
						'type' => 'string',
						'enum' => $review_states,
					),
				),
				'sanitize_callback' => array( __CLASS__, 'sanitize_review_state' ),
				'auth_callback'     => array( __CLASS__, 'can_access_note_meta' ),
			)
		);
	}

	public static function get_review_states() {
		if ( null !== self::$review_states ) {
			return self::$review_states;
		}

		$defaults = array(
			'pending'  => array( 'label' => __( 'Pending', 'push-md' ) ),
			'approved' => array( 'label' => __( 'Approved', 'push-md' ) ),
		);
		$states   = apply_filters( 'push_md_review_states', $defaults );
		$states   = is_array( $states ) ? $states : array();
		$clean    = array();
		foreach ( $states as $state => $config ) {
			$state = sanitize_key( $state );
			$label = is_array( $config ) && isset( $config['label'] ) ? sanitize_text_field( $config['label'] ) : '';
			if ( '' === $state || '' === $label ) {
				continue;
			}
			$clean[ $state ] = array( 'label' => $label );
		}
		foreach ( $defaults as $state => $config ) {
			if ( ! isset( $clean[ $state ] ) ) {
				$clean[ $state ] = $config;
			}
		}

		self::$review_states = $clean;

		return self::$review_states;
	}

	public static function sanitize_review_state( $state ) {
		$state = sanitize_key( $state );

		return isset( self::get_review_states()[ $state ] ) ? $state : 'pending';
	}

	public static function register_rest_fields() {
		register_rest_field(
			self::POST_TYPE,
			'push_md_diff',
			array(
				'get_callback' => array( __CLASS__, 'get_rest_diff' ),
				'schema'       => array(
					'description' => __( 'Changed files and line diff for this Pull Request.', 'push-md' ),
					'type'        => 'object',
					'context'     => array( 'edit' ),
					'readonly'    => true,
				),
			)
		);
		register_rest_field(
			self::POST_TYPE,
			'push_md_preview_url',
			array(
				'get_callback' => array( __CLASS__, 'get_rest_preview_url' ),
				'schema'       => array(
					'type'     => 'string',
					'format'   => 'uri',
					'context'  => array( 'edit' ),
					'readonly' => true,
				),
			)
		);
		register_rest_field(
			'comment',
			'push_md_tip_oid',
			array(
				'get_callback' => array( __CLASS__, 'get_rest_note_tip_oid' ),
				'schema'       => array(
					'type'     => 'string',
					'context'  => array( 'edit' ),
					'readonly' => true,
				),
			)
		);
	}

	public static function can_access_review_meta() {
		return current_user_can( 'manage_options' );
	}

	public static function can_access_note_meta( $allowed, $meta_key, $comment_id ) {
		unset( $allowed, $meta_key );

		$comment = get_comment( $comment_id );

		return current_user_can( 'manage_options' )
			&& $comment
			&& self::POST_TYPE === get_post_type( $comment->comment_post_ID );
	}

	public static function validate_rest_pull_request_write( $prepared_post, WP_REST_Request $request ) {
		if ( ! $request->get_param( 'id' ) ) {
			return new WP_Error(
				'push_md_pull_request_read_only',
				__( 'Push MD Pull Requests are created only by Git branch operations.', 'push-md' ),
				array( 'status' => 403 )
			);
		}

		$post = get_post( intval( $request->get_param( 'id' ) ) );
		if ( ! $post || self::STATUS_ACTIVE !== $post->post_status ) {
			return new WP_Error(
				'push_md_pull_request_read_only',
				__( 'Merged and closed Pull Requests are read-only.', 'push-md' ),
				array( 'status' => 409 )
			);
		}

		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : $request->get_body_params();
		if ( ! is_array( $body ) || array( 'content' ) !== array_keys( $body ) ) {
			return new WP_Error(
				'push_md_pull_request_fields_read_only',
				__( 'Only the Pull Request description can be updated directly.', 'push-md' ),
				array( 'status' => 403 )
			);
		}

		return $prepared_post;
	}

	public static function migrate_legacy_branch_previews() {
		$branches = get_option( Push_MD_Plugin::BRANCH_PREVIEWS_OPTION, null );
		if ( ! is_array( $branches ) ) {
			return;
		}

		foreach ( $branches as $branch_name => $branch ) {
			if ( ! is_array( $branch ) || '' === (string) $branch_name ) {
				return;
			}

			$status = empty( $branch['merged_at'] ) ? self::STATUS_ACTIVE : self::STATUS_MERGED;
			if ( self::find_pull_request( $branch_name, $status ) ) {
				continue;
			}

			$post_id = self::insert_pull_request(
				$branch_name,
				$status,
				isset( $branch['owner'] ) ? intval( $branch['owner'] ) : 0,
				isset( $branch['base_oid'] ) ? $branch['base_oid'] : '',
				isset( $branch['tip_oid'] ) ? $branch['tip_oid'] : '',
				isset( $branch['created_at'] ) ? intval( $branch['created_at'] ) : time(),
				isset( $branch['updated_at'] ) ? intval( $branch['updated_at'] ) : time()
			);
			if ( is_wp_error( $post_id ) ) {
				return;
			}
			if ( ! empty( $branch['merged_oid'] ) ) {
				update_post_meta( $post_id, 'push_md_merged_oid', sanitize_text_field( $branch['merged_oid'] ) );
			}
			if ( ! empty( $branch['merged_by'] ) ) {
				update_post_meta( $post_id, 'push_md_merged_by', intval( $branch['merged_by'] ) );
			}
		}

		delete_option( Push_MD_Plugin::BRANCH_PREVIEWS_OPTION );
	}

	public static function update_active_pull_request( $push_header ) {
		$branch_name = $push_header['branch_name'];
		$post        = self::find_pull_request( $branch_name, self::STATUS_ACTIVE );
		if ( ! $post ) {
			$post_id = self::insert_pull_request(
				$branch_name,
				self::STATUS_ACTIVE,
				get_current_user_id(),
				$push_header['base_oid'],
				$push_header['new_oid'],
				time(),
				time()
			);
			if ( is_wp_error( $post_id ) ) {
				throw new Exception( $post_id->get_error_message() );
			}

			return $post_id;
		}

		update_post_meta( $post->ID, 'push_md_base_oid', sanitize_text_field( $push_header['base_oid'] ) );
		update_post_meta( $post->ID, 'push_md_tip_oid', sanitize_text_field( $push_header['new_oid'] ) );
		$result = wp_update_post(
			array(
				'ID'            => $post->ID,
				'post_modified' => current_time( 'mysql' ),
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			throw new Exception( $result->get_error_message() );
		}

		return $post->ID;
	}

	public static function close_pull_request( $branch_name ) {
		$post = self::find_pull_request( $branch_name, self::STATUS_ACTIVE );
		if ( ! $post ) {
			return;
		}

		$result = wp_update_post(
			array(
				'ID'             => $post->ID,
				'post_status'    => self::STATUS_CLOSED,
				'comment_status' => 'closed',
				'post_modified'  => current_time( 'mysql' ),
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			throw new Exception( $result->get_error_message() );
		}
	}

	public static function merge_pull_request( $branch_name, $merged_oid ) {
		$post = self::find_pull_request( $branch_name, self::STATUS_ACTIVE );
		if ( ! $post ) {
			throw new Exception( 'Active Pull Request not found.' );
		}

		update_post_meta( $post->ID, 'push_md_merged_oid', sanitize_text_field( $merged_oid ) );
		update_post_meta( $post->ID, 'push_md_merged_by', get_current_user_id() );
		$result = wp_update_post(
			array(
				'ID'             => $post->ID,
				'post_status'    => self::STATUS_MERGED,
				'comment_status' => 'closed',
				'post_modified'  => current_time( 'mysql' ),
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			throw new Exception( $result->get_error_message() );
		}
	}

	public static function get_branch_metadata_map() {
		$posts    = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( self::STATUS_ACTIVE, self::STATUS_MERGED ),
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);
		$branches = array();
		foreach ( $posts as $post ) {
			$branch_name = get_post_meta( $post->ID, 'push_md_branch', true );
			if ( '' === $branch_name ) {
				continue;
			}
			if ( isset( $branches[ $branch_name ] ) ) {
				if ( self::STATUS_ACTIVE !== $post->post_status || empty( $branches[ $branch_name ]['merged_at'] ) ) {
					continue;
				}
			}
			$branches[ $branch_name ] = self::post_to_branch_metadata( $post );
		}

		return $branches;
	}

	public static function get_pull_request_admin_url( $post_id ) {
		return add_query_arg(
			array(
				'page' => Push_MD_Admin::PAGE_SLUG,
				'pr'   => intval( $post_id ),
			),
			admin_url( 'tools.php' )
		);
	}

	public static function get_active_pull_request_for_branch( $branch_name ) {
		return self::find_pull_request( $branch_name, self::STATUS_ACTIVE );
	}

	public static function get_rest_diff( $item ) {
		$post_id = isset( $item['id'] ) ? intval( $item['id'] ) : 0;

		return Push_MD_Plugin::get_pull_request_diff( $post_id );
	}

	public static function get_rest_preview_url( $item ) {
		$post_id = isset( $item['id'] ) ? intval( $item['id'] ) : 0;
		$post    = get_post( $post_id );
		if ( ! $post || self::STATUS_ACTIVE !== $post->post_status ) {
			return '';
		}

		return Push_MD_Plugin::get_preview_branch_url( get_post_meta( $post_id, 'push_md_branch', true ) );
	}

	public static function validate_rest_note( $prepared_comment, WP_REST_Request $request ) {
		$post_id = isset( $prepared_comment['comment_post_ID'] ) ? intval( $prepared_comment['comment_post_ID'] ) : 0;
		if ( ! $post_id && $request->get_param( 'id' ) ) {
			$comment = get_comment( intval( $request->get_param( 'id' ) ) );
			$post_id = $comment ? intval( $comment->comment_post_ID ) : 0;
		}
		$post = get_post( $post_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return $prepared_comment;
		}
		if ( 'note' !== $request->get_param( 'type' ) && ! $request->get_param( 'id' ) ) {
			return new WP_Error( 'push_md_note_type_required', __( 'Pull Request feedback must use the WordPress note type.', 'push-md' ), array( 'status' => 400 ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'push_md_note_forbidden', __( 'You cannot review this Pull Request.', 'push-md' ), array( 'status' => 403 ) );
		}
		if ( self::STATUS_ACTIVE !== $post->post_status ) {
			return new WP_Error( 'push_md_pull_request_read_only', __( 'Merged and closed Pull Requests are read-only.', 'push-md' ), array( 'status' => 409 ) );
		}

		$meta              = $request->get_param( 'meta' );
		$meta              = is_array( $meta ) ? $meta : array();
		$anchor_validation = self::validate_note_anchor( $post_id, $meta );
		if ( is_wp_error( $anchor_validation ) ) {
			return $anchor_validation;
		}
		$prepared_comment['comment_approved'] = 1;

		return $prepared_comment;
	}

	public static function store_note_tip_oid( WP_Comment $comment, WP_REST_Request $request, $creating ) {
		unset( $request );
		$post = get_post( $comment->comment_post_ID );
		if ( ! $creating || ! $post || self::POST_TYPE !== $post->post_type || 'note' !== $comment->comment_type ) {
			return;
		}

		update_comment_meta( $comment->comment_ID, 'push_md_tip_oid', get_post_meta( $post->ID, 'push_md_tip_oid', true ) );
		$review_state = get_comment_meta( $comment->comment_ID, 'push_md_review_state', true );
		if ( isset( self::get_review_states()[ $review_state ] ) ) {
			update_post_meta( $post->ID, 'push_md_review_state', $review_state );
		}
	}

	public static function get_rest_note_tip_oid( $item ) {
		$comment_id = isset( $item['id'] ) ? intval( $item['id'] ) : 0;
		$comment    = get_comment( $comment_id );
		if ( ! current_user_can( 'manage_options' ) || ! $comment || self::POST_TYPE !== get_post_type( $comment->comment_post_ID ) ) {
			return '';
		}

		return (string) get_comment_meta( $comment_id, 'push_md_tip_oid', true );
	}

	public static function guard_review_routes( $response, $server, WP_REST_Request $request ) {
		unset( $server );
		if ( null !== $response || current_user_can( 'manage_options' ) ) {
			return $response;
		}
		if ( preg_match( '#^/wp/v2/push-md-pull-requests(?:/\d+)?$#', $request->get_route() ) ) {
			return new WP_Error( 'push_md_pull_request_forbidden', __( 'You cannot review Pull Requests.', 'push-md' ), array( 'status' => 403 ) );
		}

		$post = self::get_comment_request_post( $request );
		if ( $post && self::POST_TYPE === $post->post_type ) {
			return new WP_Error( 'push_md_note_forbidden', __( 'You cannot review this Pull Request.', 'push-md' ), array( 'status' => 403 ) );
		}

		return $response;
	}

	public static function protect_review_request( $response, $handler, WP_REST_Request $request ) {
		unset( $handler );
		if ( null !== $response ) {
			return $response;
		}

		if ( preg_match( '#^/wp/v2/push-md-pull-requests(?:/\d+)?$#', $request->get_route() ) ) {
			return current_user_can( 'manage_options' )
				? $response
				: new WP_Error( 'push_md_pull_request_forbidden', __( 'You cannot review Pull Requests.', 'push-md' ), array( 'status' => 403 ) );
		}

		$post = self::get_comment_request_post( $request );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return $response;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'push_md_note_forbidden', __( 'You cannot review this Pull Request.', 'push-md' ), array( 'status' => 403 ) );
		}
		if ( preg_match( '#^/wp/v2/comments/(?P<id>\d+)$#', $request->get_route(), $match ) && 'GET' !== $request->get_method() ) {
			$review_state = get_comment_meta( intval( $match['id'] ), 'push_md_review_state', true );
			if ( '' !== $review_state ) {
				return new WP_Error( 'push_md_review_state_note_immutable', __( 'Notes that change Pull Request state cannot be edited or deleted.', 'push-md' ), array( 'status' => 409 ) );
			}
			$meta = $request->get_param( 'meta' );
			if ( is_array( $meta ) && isset( $meta['push_md_review_state'] ) ) {
				return new WP_Error( 'push_md_review_state_create_only', __( 'Pull Request state can be changed only by creating a new Note.', 'push-md' ), array( 'status' => 409 ) );
			}
		}
		if ( preg_match( '#^/wp/v2/comments/\d+$#', $request->get_route() ) && 'GET' !== $request->get_method() && self::STATUS_ACTIVE !== $post->post_status ) {
			return new WP_Error( 'push_md_pull_request_read_only', __( 'Merged and closed Pull Requests are read-only.', 'push-md' ), array( 'status' => 409 ) );
		}
		if ( preg_match( '#^/wp/v2/comments/\d+$#', $request->get_route() ) && 'GET' !== $request->get_method() && $request->has_param( 'meta' ) ) {
			$meta              = $request->get_param( 'meta' );
			$anchor_validation = self::validate_note_anchor( $post->ID, is_array( $meta ) ? $meta : array() );
			if ( is_wp_error( $anchor_validation ) ) {
				return $anchor_validation;
			}
		}

		return $response;
	}

	public static function disable_note_notifications( $notify, $comment_id ) {
		$comment = get_comment( $comment_id );
		if ( $comment && 'note' === $comment->comment_type && self::POST_TYPE === get_post_type( $comment->comment_post_ID ) ) {
			return false;
		}

		return $notify;
	}

	private static function get_comment_request_post( WP_REST_Request $request ) {
		if ( preg_match( '#^/wp/v2/comments/(?P<id>\d+)$#', $request->get_route(), $match ) ) {
			$comment = get_comment( intval( $match['id'] ) );

			return $comment ? get_post( $comment->comment_post_ID ) : null;
		}
		if ( '/wp/v2/comments' === $request->get_route() && $request->get_param( 'post' ) ) {
			return get_post( intval( $request->get_param( 'post' ) ) );
		}

		return null;
	}

	private static function validate_note_anchor( $post_id, $meta ) {
		if ( isset( $meta['push_md_tip_oid'] ) ) {
			return new WP_Error( 'push_md_note_tip_read_only', __( 'The Pull Request tip is set by Push MD.', 'push-md' ), array( 'status' => 400 ) );
		}

		$anchor_keys = array( 'push_md_path', 'push_md_side', 'push_md_line' );
		$present     = 0;
		foreach ( $anchor_keys as $key ) {
			if ( isset( $meta[ $key ] ) && '' !== (string) $meta[ $key ] ) {
				++$present;
			}
		}
		$review_state = isset( $meta['push_md_review_state'] ) ? sanitize_key( $meta['push_md_review_state'] ) : '';
		if ( '' !== $review_state && ! isset( self::get_review_states()[ $review_state ] ) ) {
			return new WP_Error( 'push_md_review_state_invalid', __( 'That Pull Request review state is not registered.', 'push-md' ), array( 'status' => 400 ) );
		}
		if ( '' !== $review_state && 0 < $present ) {
			return new WP_Error( 'push_md_review_state_inline', __( 'Inline Notes cannot change Pull Request state.', 'push-md' ), array( 'status' => 400 ) );
		}
		if ( 0 === $present ) {
			return true;
		}
		if ( count( $anchor_keys ) !== $present ) {
			return new WP_Error( 'push_md_note_anchor_incomplete', __( 'Inline feedback requires a path, side, and line.', 'push-md' ), array( 'status' => 400 ) );
		}

		$path = sanitize_text_field( $meta['push_md_path'] );
		$side = sanitize_key( $meta['push_md_side'] );
		$line = absint( $meta['push_md_line'] );
		if ( ! Push_MD_Plugin::pull_request_diff_has_anchor( $post_id, $path, $side, $line ) ) {
			return new WP_Error( 'push_md_note_anchor_invalid', __( 'That diff line no longer exists.', 'push-md' ), array( 'status' => 409 ) );
		}

		return true;
	}

	private static function insert_pull_request( $branch_name, $status, $owner, $base_oid, $tip_oid, $created_at, $updated_at ) {
		$created  = wp_date( 'Y-m-d H:i:s', $created_at );
		$modified = wp_date( 'Y-m-d H:i:s', $updated_at );
		$post_id  = wp_insert_post(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => $status,
				'post_title'     => $branch_name,
				'post_author'    => $owner,
				'post_date'      => $created,
				'post_modified'  => $modified,
				'comment_status' => self::STATUS_ACTIVE === $status ? 'open' : 'closed',
				'meta_input'     => array(
					'push_md_branch'      => sanitize_text_field( $branch_name ),
					'push_md_base_oid'    => sanitize_text_field( $base_oid ),
					'push_md_tip_oid'     => sanitize_text_field( $tip_oid ),
					'push_md_review_state' => 'pending',
				),
			),
			true
		);

		return $post_id;
	}

	private static function find_pull_request( $branch_name, $status ) {
		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => $status,
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'meta_key'       => 'push_md_branch', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $branch_name, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		return empty( $posts ) ? null : $posts[0];
	}

	private static function post_to_branch_metadata( WP_Post $post ) {
		$branch_name = get_post_meta( $post->ID, 'push_md_branch', true );
		$metadata    = array(
			'pull_request_id'  => $post->ID,
			'pull_request_url' => self::get_pull_request_admin_url( $post->ID ),
			'branch'           => $branch_name,
			'ref'              => 'refs/heads/' . $branch_name,
			'owner'            => intval( $post->post_author ),
			'base_oid'         => get_post_meta( $post->ID, 'push_md_base_oid', true ),
			'tip_oid'          => get_post_meta( $post->ID, 'push_md_tip_oid', true ),
			'url'              => Push_MD_Plugin::get_preview_branch_url( $branch_name ),
			'created_at'       => get_post_time( 'U', true, $post ),
			'updated_at'       => get_post_modified_time( 'U', true, $post ),
		);
		if ( self::STATUS_MERGED === $post->post_status ) {
			$metadata['merged_oid'] = get_post_meta( $post->ID, 'push_md_merged_oid', true );
			$metadata['merged_by']  = intval( get_post_meta( $post->ID, 'push_md_merged_by', true ) );
			$metadata['merged_at']  = get_post_modified_time( 'U', true, $post );
		}

		return $metadata;
	}
}
