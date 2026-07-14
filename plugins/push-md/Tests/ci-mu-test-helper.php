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
