<?php

use WordPress\Git\GitRepository;
use WordPress\Git\Model\Commit;

/**
 * Async, resumable initial-import seeder for WP Origin.
 *
 * The plugin's first run on an existing WordPress install needs to
 * convert every supported post and page into a Markdown file and put
 * those files under `refs/heads/trunk` as the repository's initial
 * commit. On a site with thousands of posts that conversion blows past
 * a normal PHP request budget, so this class breaks the work into
 * small batches and drives them from WP-Cron.
 *
 * State machine, all stored in `wp_options`:
 *
 *   pending      → newly activated; a single cron tick is queued.
 *   in_progress  → batches are running. Each tick converts a chunk of
 *                  posts to Markdown, creates a "Seed batch" commit on
 *                  `refs/heads/_wp_origin_seed`, and re-schedules
 *                  itself when the time/memory budget is up.
 *   finalizing   → all batches done. Build a single parent-less
 *                  "Initial import from WordPress" commit pointing at
 *                  the seed branch's tree, and set
 *                  `refs/heads/trunk` to it. The seed branch goes
 *                  away.
 *   done         → repository is open for clone/pull/push.
 *   failed       → an exception aborted seeding; admins can retry
 *                  from the admin page.
 *
 * Smart-HTTP requests are rejected with a clear protocol error until
 * state reaches `done`, so a client cloning during seeding sees
 * "Repository is being prepared…" rather than a half-built history.
 *
 * No Action Scheduler dependency — plain WP-Cron only.
 */
class WP_Origin_Seeder {

	const STATE_OPTION    = 'wp_origin_seed_state';
	const PROGRESS_OPTION = 'wp_origin_seed_progress';
	const SEED_BRANCH     = 'refs/heads/_wp_origin_seed';
	const CRON_HOOK       = 'wp_origin_seed_tick';
	const LOCK_TRANSIENT  = 'wp_origin_seed_lock';

	const STATE_PENDING     = 'pending';
	const STATE_IN_PROGRESS = 'in_progress';
	const STATE_FINALIZING  = 'finalizing';
	const STATE_DONE        = 'done';
	const STATE_FAILED      = 'failed';

	const BATCH_SIZE              = 25;
	const TIME_BUDGET_SECONDS     = 15;
	const MEMORY_BUDGET_FRACTION  = 0.7;
	const TICK_RESCHEDULE_SECONDS = 10;

	public static function bootstrap() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'tick' ) );
	}

	/**
	 * Resolve budget knobs through filters. The defaults are the
	 * production values; tests and unusual hosts can shrink them via
	 * `wp_origin_seed_batch_size`,
	 * `wp_origin_seed_time_budget_seconds`, and
	 * `wp_origin_seed_tick_reschedule_seconds` to force the seeder to
	 * span multiple cron ticks.
	 */
	private static function batch_size() {
		return (int) apply_filters( 'wp_origin_seed_batch_size', self::BATCH_SIZE );
	}

	private static function time_budget_seconds() {
		return (float) apply_filters( 'wp_origin_seed_time_budget_seconds', self::TIME_BUDGET_SECONDS );
	}

	private static function tick_reschedule_seconds() {
		return (int) apply_filters( 'wp_origin_seed_tick_reschedule_seconds', self::TICK_RESCHEDULE_SECONDS );
	}

	public static function on_activation() {
		$state = get_option( self::STATE_OPTION );
		if ( self::STATE_DONE === $state ) {
			return;
		}

		update_option( self::STATE_OPTION, self::STATE_PENDING, false );
		update_option(
			self::PROGRESS_OPTION,
			array(
				'processed'    => 0,
				'total'        => 0,
				'last_id'      => 0,
				'started_at'   => time(),
				'last_tick_at' => time(),
				'message'      => 'Queued. Waiting for the first cron tick.',
			),
			false
		);

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK );
		}
	}

	public static function reset() {
		delete_option( self::STATE_OPTION );
		delete_option( self::PROGRESS_OPTION );
		delete_transient( self::LOCK_TRANSIENT );
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public static function get_state() {
		$state = get_option( self::STATE_OPTION );

		return $state ? $state : self::STATE_PENDING;
	}

	public static function get_progress() {
		$progress = get_option( self::PROGRESS_OPTION, array() );
		$state    = self::get_state();
		$total    = isset( $progress['total'] ) ? intval( $progress['total'] ) : 0;
		$done     = isset( $progress['processed'] ) ? intval( $progress['processed'] ) : 0;
		$percent  = self::STATE_DONE === $state ? 100 : ( $total > 0 ? min( 99, intval( floor( $done * 100 / $total ) ) ) : 0 );

		return array(
			'state'       => $state,
			'percent'     => $percent,
			'processed'   => $done,
			'total'       => $total,
			'tick_count'  => isset( $progress['tick_count'] ) ? intval( $progress['tick_count'] ) : 0,
			'message'     => isset( $progress['message'] ) ? $progress['message'] : '',
			'last_id'     => isset( $progress['last_id'] ) ? intval( $progress['last_id'] ) : 0,
			'started_at'  => isset( $progress['started_at'] ) ? intval( $progress['started_at'] ) : 0,
			'finished_at' => isset( $progress['finished_at'] ) ? intval( $progress['finished_at'] ) : 0,
		);
	}

	public static function is_ready() {
		return self::STATE_DONE === self::get_state();
	}

	public static function not_ready_message() {
		$progress = self::get_progress();
		if ( self::STATE_FAILED === $progress['state'] ) {
			return 'WP Origin import failed: ' . $progress['message'];
		}

		return sprintf(
			'WP Origin is preparing the repository (%d%%, %d / %d posts). Please try again shortly.',
			$progress['percent'],
			$progress['processed'],
			$progress['total']
		);
	}

	public static function tick() {
		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			return;
		}
		set_transient( self::LOCK_TRANSIENT, 1, 90 );

		try {
			$state = self::get_state();
			if ( self::STATE_DONE === $state || self::STATE_FAILED === $state ) {
				return;
			}

			$progress                 = self::get_progress_storage();
			$progress['tick_count']   = isset( $progress['tick_count'] ) ? intval( $progress['tick_count'] ) + 1 : 1;
			$progress['last_tick_at'] = time();
			update_option( self::PROGRESS_OPTION, $progress, false );

			if ( self::STATE_PENDING === $state ) {
				self::initialize();
				$state = self::get_state();
			}

			if ( self::STATE_IN_PROGRESS === $state ) {
				self::process_batches();
				$state = self::get_state();
			}

			if ( self::STATE_FINALIZING === $state ) {
				self::finalize();
			}
		} catch ( Throwable $exception ) {
			self::record_failure( $exception );
		} finally {
			delete_transient( self::LOCK_TRANSIENT );
		}
	}

	private static function initialize() {
		global $wpdb;

		$types_in    = "'" . implode( "','", array_map( 'esc_sql', WP_Origin_Plugin::$supported_post_types ) ) . "'";
		$statuses_in = "'" . implode( "','", array_map( 'esc_sql', WP_Origin_Plugin::$supported_post_statuses ) ) . "'";
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ($types_in) AND post_status IN ($statuses_in)"
		);
		// phpcs:enable

		$existing   = self::get_progress_storage();
		$tick_count = isset( $existing['tick_count'] ) ? intval( $existing['tick_count'] ) : 0;
		update_option(
			self::PROGRESS_OPTION,
			array(
				'processed'    => 0,
				'total'        => $total,
				'last_id'      => 0,
				'tick_count'   => $tick_count,
				'started_at'   => time(),
				'last_tick_at' => time(),
				'message'      => sprintf( 'Found %d posts and pages to import.', $total ),
			),
			false
		);

		// If there's nothing to import, skip straight to finalization so
		// trunk gets an empty initial commit and clones stop being
		// rejected.
		if ( 0 === $total ) {
			update_option( self::STATE_OPTION, self::STATE_FINALIZING, false );
		} else {
			update_option( self::STATE_OPTION, self::STATE_IN_PROGRESS, false );
		}
	}

	private static function process_batches() {
		$repository = WP_Origin_Plugin::open_repository();
		// Stage seed commits on a side branch so trunk stays empty (and
		// clones keep being rejected) until finalization. The branch
		// file must exist before commit() runs, otherwise it falls back
		// to overwriting HEAD with the new commit OID.
		if ( ! $repository->branch_exists( self::SEED_BRANCH ) ) {
			$repository->set_branch_tip( self::SEED_BRANCH, Commit::NULL_HASH );
		}
		$repository->set_branch_tip( 'HEAD', 'ref: ' . self::SEED_BRANCH . "\n" );

		$started_at = microtime( true );

		while ( true ) {
			$progress = self::get_progress_storage();
			$batch    = self::next_batch( $progress['last_id'] );

			if ( empty( $batch ) ) {
				$progress['message'] = 'All posts staged. Finalizing initial commit…';
				update_option( self::PROGRESS_OPTION, $progress, false );
				update_option( self::STATE_OPTION, self::STATE_FINALIZING, false );
				break;
			}

			$updates   = array();
			$last_id   = $progress['last_id'];
			$processed = $progress['processed'];
			foreach ( $batch as $post ) {
				$path             = WP_Origin_Plugin::build_markdown_path( $post->post_type, $post->post_name );
				$updates[ $path ] = WP_Origin_Plugin::export_post_to_markdown( $post );
				$last_id          = $post->ID;
				++$processed;
			}

			$identity = WP_Origin_Plugin::repository_identity( $repository );
			$now      = gmdate( Commit::DATE_FORMAT );
			$repository->commit(
				array(
					'updates' => $updates,
					'commit'  => array(
						'message'        => sprintf(
							'Seed batch ending at post %d (%d/%d)',
							$last_id,
							$processed,
							$progress['total']
						),
						'author'         => $identity,
						'author_date'    => $now,
						'committer'      => $identity,
						'committer_date' => $now,
					),
				)
			);

			$progress['last_id']      = $last_id;
			$progress['processed']    = $processed;
			$progress['last_tick_at'] = time();
			$progress['message']      = sprintf( 'Imported %d / %d posts.', $processed, $progress['total'] );
			update_option( self::PROGRESS_OPTION, $progress, false );

			// Free per-batch memory before the next iteration. WP_Post
			// objects accumulate in the object cache and the producer
			// holds the full Markdown string.
			unset( $batch, $updates );
			wp_cache_flush_runtime();

			if ( self::budget_exhausted( $started_at ) ) {
				wp_schedule_single_event( time() + self::tick_reschedule_seconds(), self::CRON_HOOK );
				break;
			}
		}

		// Restore HEAD to trunk so request-time code paths read trunk.
		$repository->set_branch_tip( 'HEAD', "ref: refs/heads/trunk\n" );
	}

	private static function finalize() {
		$repository = WP_Origin_Plugin::open_repository();
		$repository->set_branch_tip( 'HEAD', "ref: refs/heads/trunk\n" );

		$seed_tip = $repository->get_branch_tip( self::SEED_BRANCH );
		$progress = self::get_progress_storage();
		$identity = WP_Origin_Plugin::repository_identity( $repository );
		$now      = gmdate( Commit::DATE_FORMAT );

		if ( ! is_string( $seed_tip ) || '' === $seed_tip || Commit::is_null_hash( $seed_tip ) ) {
			// Nothing was staged (empty site). Create an empty initial
			// commit so trunk has a valid root and clones can start
			// succeeding.
			$initial_oid         = $repository->commit(
				array(
					'updates' => array(),
					'commit'  => array(
						'message'        => 'Initial import from WordPress (empty site)',
						'author'         => $identity,
						'author_date'    => $now,
						'committer'      => $identity,
						'committer_date' => $now,
					),
				)
			);
			$progress['message'] = 'Initial import complete (no content found).';
		} else {
			$seed_commit = $repository->read_object( $seed_tip )->as_commit();

			// Build a single parent-less commit pointing at the seed
			// branch's final tree. This becomes trunk's only commit, so
			// clones see one clean "Initial import from WordPress"
			// regardless of how many seed batches ran.
			$initial             = new Commit(
				array(
					'tree'           => $seed_commit->tree,
					'parents'        => array(),
					'author'         => $identity,
					'author_date'    => $now,
					'committer'      => $identity,
					'committer_date' => $now,
					'message'        => 'Initial import from WordPress',
				)
			);
			$initial_oid         = $repository->add_object( 'commit', $initial->get_commit_string() );
			$progress['message'] = sprintf( 'Initial import complete. %d posts imported.', intval( $progress['processed'] ) );
		}

		$repository->set_branch_tip( 'refs/heads/trunk', $initial_oid );
		// Drop the staging branch entirely. Leaving a NULL_HASH file on
		// disk would cause `info/refs` to advertise a ref with an
		// invalid OID and break clones.
		if ( $repository->branch_exists( self::SEED_BRANCH ) ) {
			$repository->delete_branch( self::SEED_BRANCH );
		}

		$progress['finished_at'] = time();
		$progress['percent']     = 100;
		update_option( self::PROGRESS_OPTION, $progress, false );
		update_option( self::STATE_OPTION, self::STATE_DONE, false );
	}

	private static function next_batch( $after_id ) {
		global $wpdb;

		$types_in    = "'" . implode( "','", array_map( 'esc_sql', WP_Origin_Plugin::$supported_post_types ) ) . "'";
		$statuses_in = "'" . implode( "','", array_map( 'esc_sql', WP_Origin_Plugin::$supported_post_statuses ) ) . "'";
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_type IN ($types_in)
				AND post_status IN ($statuses_in)
				AND ID > %d
				ORDER BY ID ASC
				LIMIT %d",
				intval( $after_id ),
				self::batch_size()
			)
		);
		// phpcs:enable

		$posts = array();
		foreach ( $ids as $id ) {
			$post = get_post( intval( $id ) );
			if ( $post instanceof WP_Post ) {
				$posts[] = $post;
			}
		}

		return $posts;
	}

	private static function get_progress_storage() {
		$progress = get_option( self::PROGRESS_OPTION, array() );
		$defaults = array(
			'processed'    => 0,
			'total'        => 0,
			'last_id'      => 0,
			'started_at'   => time(),
			'last_tick_at' => time(),
			'message'      => '',
		);

		return array_merge( $defaults, is_array( $progress ) ? $progress : array() );
	}

	private static function budget_exhausted( $started_at ) {
		if ( microtime( true ) - $started_at > self::time_budget_seconds() ) {
			return true;
		}

		$limit = self::memory_limit_bytes();
		if ( $limit > 0 && memory_get_usage( true ) / $limit > self::MEMORY_BUDGET_FRACTION ) {
			return true;
		}

		return false;
	}

	private static function memory_limit_bytes() {
		$limit = ini_get( 'memory_limit' );
		if ( '' === $limit || '-1' === $limit ) {
			return 0;
		}

		$value = intval( $limit );
		$unit  = strtolower( substr( $limit, -1 ) );
		switch ( $unit ) {
			case 'g':
				return $value * 1024 * 1024 * 1024;
			case 'm':
				return $value * 1024 * 1024;
			case 'k':
				return $value * 1024;
			default:
				return $value;
		}
	}

	private static function record_failure( Throwable $exception ) {
		$progress                 = self::get_progress_storage();
		$progress['message']      = $exception->getMessage();
		$progress['last_tick_at'] = time();
		update_option( self::PROGRESS_OPTION, $progress, false );
		update_option( self::STATE_OPTION, self::STATE_FAILED, false );
	}
}
