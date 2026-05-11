<?php
/**
 * Plugin Name: WP Origin CI Test Helper (must-use)
 * Description: Shrinks the seeder's batch size, time budget, and tick
 *              reschedule delay so CI can verify that a multi-tick
 *              import really resumes correctly across cron runs. Drop
 *              this file in `wp-content/mu-plugins/` from the e2e
 *              workflow only.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'wporigin_seed_batch_size', static function () {
	return 5;
} );

// Zero-second budget forces budget_exhausted() to fire after every
// batch, so the seeder reschedules itself even if the host can run
// the whole thing in one tick.
add_filter( 'wporigin_seed_time_budget_seconds', static function () {
	return 0.0;
} );

// Reschedule "in the future" with no delay so wp-cli's
// `cron event run --due-now` picks up the next tick on the very next
// invocation.
add_filter( 'wporigin_seed_tick_reschedule_seconds', static function () {
	return 0;
} );
