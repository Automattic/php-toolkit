<?php
/**
 * Standalone runner for testing Push MD round-trip fidelity against all live WP database posts & pages.
 *
 * Usage:
 *   php plugins/push-md/Tests/run-live-db-fidelity.php [path/to/wp-load.php] [limit] [mode: dry_run|mutation|both] [output_dir]
 *
 * Examples:
 *   php plugins/push-md/Tests/run-live-db-fidelity.php /var/www/html/wp-load.php -1 both /tmp/fidelity-mismatches
 */

// If a path to wp-load.php was passed as an argument, require it.
if ( isset( $argv[1] ) && file_exists( $argv[1] ) ) {
	require_once $argv[1];
} elseif ( file_exists( dirname( __DIR__, 3 ) . '/wp-load.php' ) ) {
	require_once dirname( __DIR__, 3 ) . '/wp-load.php';
}

if ( ! function_exists( 'get_posts' ) && ! isset( $GLOBALS['wpdb'] ) ) {
	echo "Error: WordPress environment (wp-load.php) or database connection is not loaded.\n";
	echo "Usage: php plugins/push-md/Tests/run-live-db-fidelity.php /path/to/wordpress/wp-load.php\n";
	exit( 1 );
}

require_once __DIR__ . '/RoundTripFidelityTest.php';

$runner = new RoundTripFidelityTest();

$db_posts = array();
if ( function_exists( 'get_posts' ) ) {
	$db_posts = get_posts( array(
		'post_type'      => array( 'post', 'page' ),
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => 'ID',
		'order'          => 'ASC',
	) );
} elseif ( isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) ) {
	$wpdb     = $GLOBALS['wpdb'];
	$db_posts = $wpdb->get_results( "SELECT ID, post_name, post_title, post_content, post_type FROM {$wpdb->posts} WHERE post_type IN ('post', 'page') AND post_status = 'publish' ORDER BY ID ASC" );
}

$limit = isset( $argv[2] ) && is_numeric( $argv[2] ) && (int) $argv[2] > 0 ? (int) $argv[2] : -1;
if ( $limit > 0 && count( $db_posts ) > $limit ) {
	$db_posts = array_slice( $db_posts, 0, $limit );
}

$mode = isset( $argv[3] ) ? strtolower( trim( $argv[3] ) ) : 'both';
if ( ! in_array( $mode, array( 'dry_run', 'mutation', 'both' ), true ) ) {
	$mode = 'both';
}

$output_dir = isset( $argv[4] ) ? rtrim( $argv[4], '/' ) : '/tmp/fidelity-mismatches';

if ( ! file_exists( $output_dir ) ) {
	@mkdir( $output_dir, 0777, true );
}

$total_found = count( $db_posts );
echo sprintf( "Found %d live published posts and pages in the database to test.\n", $total_found );
echo sprintf( "Mode: %s | Mismatch Dump Directory: %s\n\n", strtoupper( $mode ), $output_dir );

$passes = array();
if ( 'dry_run' === $mode || 'both' === $mode ) {
	$passes['Dry Run (Direct Round-Trip)'] = null;
}
if ( 'mutation' === $mode || 'both' === $mode ) {
	$passes['Mutation Test (Change & Re-fetch)'] = array( 'word_append', 'word_prepend', 'heading_append' );
}

$grand_results = array();

foreach ( $passes as $pass_name => $strategies ) {
	echo "=======================================================================\n";
	echo sprintf( " PASS: %s\n", $pass_name );
	echo "=======================================================================\n";

	$passed  = 0;
	$failed  = 0;
	$skipped = 0;

	foreach ( $db_posts as $idx => $post ) {
		$post_id   = is_object( $post ) ? ( $post->ID ?? $idx ) : $idx;
		$post_name = is_object( $post ) ? ( $post->post_name ?? "post-$post_id" ) : "post-$post_id";
		$post_type = is_object( $post ) ? ( $post->post_type ?? 'post' ) : 'post';
		$title     = is_object( $post ) ? ( $post->post_title ?? "Post #$post_id" ) : "Post #$post_id";
		$content   = trim( (string) ( is_object( $post ) ? $post->post_content : '' ) );

		if ( '' === $content ) {
			echo sprintf( "[SKIP] ID %d (%s: '%s') - Empty content\n", $post_id, strtoupper( $post_type ), $title );
			$skipped++;
			continue;
		}

		$strategy = null;
		if ( is_array( $strategies ) ) {
			$strategy = $strategies[ $idx % count( $strategies ) ];
		}

		$label = sprintf( "%s ID %d ('%s')", strtoupper( $post_type ), $post_id, substr( $title, 0, 45 ) );

		try {
			$result = $runner->check_fidelity( $label, $content, $strategy );

			if ( $result['success'] ) {
				echo sprintf( "[PASS] %s\n", $label );
				$passed++;
			} else {
				$failed++;
				$fail_num = $failed;
				echo sprintf( "\n[FAIL #%d] %s:\n%s\n\n", $fail_num, $label, $result['diff_message'] );

				// Dump mismatch files to output directory.
				$pass_slug   = null === $strategy ? 'dry_run' : 'mutation_' . $strategy;
				$safe_slug   = preg_replace( '/[^a-z0-9_-]+/', '_', strtolower( $post_name ) );
				$target_dir  = sprintf( '%s/%s_%s_%d_%s', $output_dir, $pass_slug, $post_type, $post_id, substr( $safe_slug, 0, 30 ) );

				if ( ! file_exists( $target_dir ) ) {
					@mkdir( $target_dir, 0777, true );
				}

				file_put_contents( $target_dir . '/1-original.html', $result['original_html'] );
				file_put_contents( $target_dir . '/2-converted.md', $result['converted_md'] );
				file_put_contents( $target_dir . '/3-reconverted.html', $result['reconverted_html'] );
				if ( ! empty( $result['re_extracted_md'] ) ) {
					file_put_contents( $target_dir . '/4-re-extracted.md', $result['re_extracted_md'] );
				}
				file_put_contents( $target_dir . '/5-diff-report.txt', $result['diff_message'] );

				echo sprintf( "       -> Dumped mismatch files to: %s/\n\n", $target_dir );
			}
		} catch ( Exception $e ) {
			$failed++;
			echo sprintf( "\n[ERROR #%d] %s: %s\n\n", $failed, $label, $e->getMessage() );
		} catch ( Throwable $t ) {
			$failed++;
			echo sprintf( "\n[ERROR #%d] %s: %s\n\n", $failed, $label, $t->getMessage() );
		}
	}

	$grand_results[ $pass_name ] = array(
		'total'   => $total_found,
		'passed'  => $passed,
		'failed'  => $failed,
		'skipped' => $skipped,
	);
}

echo "\n========================================================\n";
echo " LIVE DATABASE ROUND-TRIP FIDELITY SUMMARY REPORT        \n";
echo "========================================================\n";
foreach ( $grand_results as $pass_name => $res ) {
	echo sprintf( "%-38s Passed: %3d | Failed: %3d | Skipped: %2d\n", $pass_name . ':', $res['passed'], $res['failed'], $res['skipped'] );
}
echo "========================================================\n";
if ( ! empty( glob( $output_dir . '/*' ) ) ) {
	echo sprintf( "Mismatch folders written to: %s/\n", $output_dir );
} else {
	echo "Zero mismatches found across all tested posts!\n";
}
echo "========================================================\n";
