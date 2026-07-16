<?php
/**
 * Plugin Name: Push MD CI Test Helper (must-use)
 * Description: Shrinks the seeder's batch size, time budget, and tick
 *              reschedule delay so CI can verify that a multi-tick
 *              import really resumes correctly across cron runs. Drop
 *              this file in `wp-content/mu-plugins/` from the e2e
 *              workflow only.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'init',
	static function () {
		register_post_type(
			'push_md_test_doc',
			array(
				'public'       => false,
				'show_ui'      => true,
				'show_in_rest' => true,
				'rest_base'    => 'push-md-test-documents',
				'hierarchical' => true,
				'supports'     => array( 'title', 'editor', 'excerpt', 'revisions', 'page-attributes' ),
			)
		);
		register_taxonomy(
			'push_md_test_collection',
			'push_md_test_doc',
			array(
				'public'       => false,
				'show_ui'      => true,
				'show_in_rest' => true,
				'rest_base'    => 'push-md-test-collections',
				'hierarchical' => true,
			)
		);
		register_taxonomy(
			'push_md_test_topic',
			'push_md_test_doc',
			array(
				'public'       => false,
				'show_ui'      => true,
				'show_in_rest' => true,
				'rest_base'    => 'push-md-test-topics',
				'hierarchical' => false,
			)
		);

		if ( ! function_exists( 'push_md_register_content_adapter' ) ) {
			return;
		}

		push_md_register_content_adapter(
			'push_md_test_doc',
			array(
				'hierarchical'       => true,
				'frontmatter_fields' => array( 'collections', 'topics' ),
				'export_metadata'    => 'push_md_test_export_metadata',
				'validate_metadata'  => 'push_md_test_validate_metadata',
				'apply_metadata'     => 'push_md_test_apply_metadata',
			)
		);
	},
	10
);

function push_md_test_export_metadata( WP_Post $post ) {
	return array(
		'collections' => push_md_test_term_slugs( $post->ID, 'push_md_test_collection' ),
		'topics'      => push_md_test_term_slugs( $post->ID, 'push_md_test_topic' ),
	);
}

function push_md_test_term_slugs( $post_id, $taxonomy ) {
	$terms = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'slugs' ) );
	if ( is_wp_error( $terms ) ) {
		throw new RuntimeException( $terms->get_error_message() );
	}
	sort( $terms, SORT_STRING );
	return $terms;
}

function push_md_test_validate_metadata( $metadata ) {
	$mapping = array(
		'collections' => 'push_md_test_collection',
		'topics'      => 'push_md_test_topic',
	);
	foreach ( $mapping as $field => $taxonomy ) {
		if ( ! array_key_exists( $field, $metadata ) ) {
			continue;
		}
		$requested = array_values( array_unique( $metadata[ $field ] ) );
		$existing  = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'slug'       => $requested,
				'fields'     => 'slugs',
			)
		);
		if ( is_wp_error( $existing ) ) {
			throw new RuntimeException( $existing->get_error_message() );
		}
		sort( $requested, SORT_STRING );
		sort( $existing, SORT_STRING );
		if ( $requested !== $existing ) {
			throw new InvalidArgumentException( 'Push MD test adapter terms must already exist.' );
		}
	}
}

function push_md_test_apply_metadata( $post_id, $metadata ) {
	$mapping = array(
		'collections' => 'push_md_test_collection',
		'topics'      => 'push_md_test_topic',
	);
	foreach ( $mapping as $field => $taxonomy ) {
		if ( ! array_key_exists( $field, $metadata ) ) {
			continue;
		}
		$result = wp_set_object_terms( $post_id, $metadata[ $field ], $taxonomy, false );
		if ( is_wp_error( $result ) ) {
			throw new RuntimeException( $result->get_error_message() );
		}
	}
}

add_action(
	'rest_api_init',
	static function () {
		$permission = static function () {
			return current_user_can( 'manage_options' );
		};
		register_rest_route(
			'push-md-test/v1',
			'/adapter-fixture',
			array(
				'methods'             => 'POST',
				'permission_callback' => $permission,
				'callback'            => 'push_md_test_create_adapter_fixture',
			)
		);
		register_rest_route(
			'push-md-test/v1',
			'/adapter-document/(?P<id>[\d]+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => $permission,
				'callback'            => static function ( WP_REST_Request $request ) {
					$post = get_post( intval( $request->get_param( 'id' ) ) );
					if ( ! $post || 'push_md_test_doc' !== $post->post_type ) {
						return new WP_Error( 'not_found', 'Adapter document not found.', array( 'status' => 404 ) );
					}
					return rest_ensure_response(
						array(
							'id'          => $post->ID,
							'content'     => $post->post_content,
							'collections' => push_md_test_term_slugs( $post->ID, 'push_md_test_collection' ),
							'topics'      => push_md_test_term_slugs( $post->ID, 'push_md_test_topic' ),
						)
					);
				},
			)
		);
	}
);

function push_md_test_create_adapter_fixture( WP_REST_Request $request ) {
	$suffix = sanitize_title( $request->get_param( 'suffix' ) );
	if ( '' === $suffix ) {
		return new WP_Error( 'missing_suffix', 'A fixture suffix is required.', array( 'status' => 400 ) );
	}

	$collection_slugs = array( 'guides-' . $suffix, 'reference-' . $suffix );
	$topic_slugs      = array( 'blocks-' . $suffix, 'wordpress-' . $suffix );
	$parent_term      = wp_insert_term( 'Guides ' . $suffix, 'push_md_test_collection', array( 'slug' => $collection_slugs[0] ) );
	if ( is_wp_error( $parent_term ) ) {
		return $parent_term;
	}
	$child_term = wp_insert_term(
		'Reference ' . $suffix,
		'push_md_test_collection',
		array(
			'slug'   => $collection_slugs[1],
			'parent' => $parent_term['term_id'],
		)
	);
	if ( is_wp_error( $child_term ) ) {
		return $child_term;
	}
	foreach ( $topic_slugs as $topic_slug ) {
		$term = wp_insert_term( ucwords( str_replace( '-', ' ', $topic_slug ) ), 'push_md_test_topic', array( 'slug' => $topic_slug ) );
		if ( is_wp_error( $term ) ) {
			return $term;
		}
	}

	$parent_slug = 'docs-' . $suffix;
	$child_slug  = 'getting-started-' . $suffix;
	$parent_id   = wp_insert_post(
		array(
			'post_type'    => 'push_md_test_doc',
			'post_status'  => 'publish',
			'post_name'    => $parent_slug,
			'post_title'   => 'Docs ' . $suffix,
			'post_content' => '<!-- wp:paragraph --><p>Adapter parent ' . esc_html( $suffix ) . '</p><!-- /wp:paragraph -->',
		),
		true
	);
	if ( is_wp_error( $parent_id ) ) {
		return $parent_id;
	}
	$child_id = wp_insert_post(
		array(
			'post_type'    => 'push_md_test_doc',
			'post_status'  => 'publish',
			'post_name'    => $child_slug,
			'post_parent'  => $parent_id,
			'post_title'   => 'Getting Started ' . $suffix,
			'post_content' => '<!-- wp:paragraph --><p>Adapter child ' . esc_html( $suffix ) . '</p><!-- /wp:paragraph -->',
		),
		true
	);
	if ( is_wp_error( $child_id ) ) {
		return $child_id;
	}
	wp_set_object_terms( $child_id, $collection_slugs, 'push_md_test_collection', false );
	wp_set_object_terms( $child_id, $topic_slugs, 'push_md_test_topic', false );

	return rest_ensure_response(
		array(
			'child_id'    => $child_id,
			'path'        => 'push_md_test_doc/' . $parent_slug . '/' . $child_slug . '.md',
			'collections' => $collection_slugs,
			'topics'      => $topic_slugs,
		)
	);
}

add_filter( 'push_md_seed_batch_size', static function () {
	return 5;
} );

// Zero-second budget forces budget_exhausted() to fire after every
// batch, so the seeder reschedules itself even if the host can run
// the whole thing in one tick.
add_filter( 'push_md_seed_time_budget_seconds', static function () {
	return 0.0;
} );

// Reschedule "in the future" with no delay so wp-cli's
// `cron event run --due-now` picks up the next tick on the very next
// invocation.
add_filter( 'push_md_seed_tick_reschedule_seconds', static function () {
	return 0;
} );

add_filter(
	'push_md_review_states',
	static function ( $states ) {
		$states['changes_requested'] = array(
			'label' => 'Changes requested',
		);

		return $states;
	}
);

add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'push-md-test/v1',
			'/migrate-legacy-preview',
			array(
				'methods'             => 'POST',
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
				'callback'            => static function ( WP_REST_Request $request ) {
					$branch_name = sanitize_text_field( $request->get_param( 'branch' ) );
					update_option(
						'push_md_branch_previews',
						array(
							$branch_name => array(
								'branch'     => $branch_name,
								'owner'      => get_current_user_id(),
								'base_oid'   => str_repeat( 'a', 40 ),
								'tip_oid'    => str_repeat( 'b', 40 ),
								'created_at' => 1700000000,
								'updated_at' => 1700000100,
							),
						),
						false
					);
					Push_MD_Pull_Requests::migrate_legacy_branch_previews();

					$posts = get_posts(
						array(
							'post_type'      => Push_MD_Pull_Requests::POST_TYPE,
							'post_status'    => Push_MD_Pull_Requests::STATUS_ACTIVE,
							'posts_per_page' => 1,
							'meta_key'       => 'push_md_branch', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
							'meta_value'     => $branch_name, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
						)
					);
					$post  = empty( $posts ) ? null : $posts[0];

					return rest_ensure_response(
						array(
							'id'             => $post ? $post->ID : 0,
							'status'         => $post ? $post->post_status : '',
							'branch'         => $post ? get_post_meta( $post->ID, 'push_md_branch', true ) : '',
							'base_oid'       => $post ? get_post_meta( $post->ID, 'push_md_base_oid', true ) : '',
							'tip_oid'        => $post ? get_post_meta( $post->ID, 'push_md_tip_oid', true ) : '',
							'review_state'   => $post ? get_post_meta( $post->ID, 'push_md_review_state', true ) : '',
							'option_removed' => null === get_option( 'push_md_branch_previews', null ),
						)
					);
				},
				'args'                => array(
					'branch' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}
);
