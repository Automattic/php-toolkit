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

// CI-only route so the e2e suite can flip push_md_allow_create_on_missing_id
// (local one-way seeding mode) over HTTP -- the test process has no wp-cli.
// Admin-only; this mu-plugin is dropped in only by the e2e workflow.
add_action( 'rest_api_init', static function () {
	register_rest_route(
		'push-md-test/v1',
		'/allow-create-on-missing-id',
		array(
			'methods'             => 'POST',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function ( $request ) {
				$enabled = (bool) $request->get_param( 'enabled' );
				if ( $enabled ) {
					update_option( 'push_md_allow_create_on_missing_id', 1 );
				} else {
					delete_option( 'push_md_allow_create_on_missing_id' );
				}

				return array( 'enabled' => $enabled );
			},
		)
	);
} );
