<?php

use WordPress\Git\GitRepository;
use WordPress\Git\Model\Commit;
use WordPress\Git\Model\TreeEntry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Async, resumable initial-import seeder for Push MD.
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
 *                  `refs/heads/_pmd_seed`, and re-schedules
 *                  itself when the time/memory budget is up.
 *   finalizing   → all batches done. Keep the parent-less theme base
 *                  commit when one exists, build a single
 *                  "Initial import from WordPress" overlay commit
 *                  pointing at the seed branch's tree, and set
 *                  `refs/heads/trunk` to it. The seed branch goes away.
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
class PMD_Seeder {

	const STATE_OPTION    = 'pmd_seed_state';
	const PROGRESS_OPTION = 'pmd_seed_progress';
	const SEED_BRANCH     = 'refs/heads/_pmd_seed';
	const CRON_HOOK       = 'push_md_seed_tick';
	const LOCK_TRANSIENT  = 'pmd_seed_lock';

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
	 * `push_md_seed_batch_size`,
	 * `push_md_seed_time_budget_seconds`, and
	 * `push_md_seed_tick_reschedule_seconds` to force the seeder to
	 * span multiple cron ticks.
	 */
	private static function batch_size() {
		return (int) apply_filters( 'push_md_seed_batch_size', self::BATCH_SIZE );
	}

	private static function time_budget_seconds() {
		return (float) apply_filters( 'push_md_seed_time_budget_seconds', self::TIME_BUDGET_SECONDS );
	}

	private static function tick_reschedule_seconds() {
		return (int) apply_filters( 'push_md_seed_tick_reschedule_seconds', self::TICK_RESCHEDULE_SECONDS );
	}

	public static function on_activation() {
		$state = get_option( self::STATE_OPTION );
		if ( self::STATE_DONE === $state ) {
			return;
		}

		// Wipe any leftover repository tables from a previous failed
		// activation so the new seeder starts on a clean object store.
		// Without this, re-activating after a partial seed hits PK
		// collisions on /objects/ paths the moment Git tries to write
		// a blob it had already started writing last time.
		PMD_Plugin::drop_repository_tables();

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
		PMD_Plugin::drop_repository_tables();
	}

	public static function get_state() {
		$state = get_option( self::STATE_OPTION );

		return $state ? $state : self::STATE_PENDING;
	}

	public static function get_progress( $include_checkout = true ) {
		$progress = get_option( self::PROGRESS_OPTION, array() );
		$state    = self::get_state();
		$total    = isset( $progress['total'] ) ? intval( $progress['total'] ) : 0;
		$done     = isset( $progress['processed'] ) ? intval( $progress['processed'] ) : 0;
		$percent  = self::STATE_DONE === $state ? 100 : ( $total > 0 ? min( 99, intval( floor( $done * 100 / $total ) ) ) : 0 );

		$response = array(
			'state'       => $state,
			'percent'     => $percent,
			'processed'   => $done,
			'total'       => $total,
			'tick_count'  => isset( $progress['tick_count'] ) ? intval( $progress['tick_count'] ) : 0,
			'message'     => isset( $progress['message'] ) ? $progress['message'] : '',
			'last_id'     => isset( $progress['last_id'] ) ? intval( $progress['last_id'] ) : 0,
			'started_at'  => isset( $progress['started_at'] ) ? intval( $progress['started_at'] ) : 0,
			'finished_at' => isset( $progress['finished_at'] ) ? intval( $progress['finished_at'] ) : 0,
			'commits'     => self::get_commit_log( 25 ),
		);
		if ( $include_checkout ) {
			$response['checkout'] = self::get_checkout_preview();
		}

		return $response;
	}

	/**
	 * Walk back from the seed branch (during import) or trunk (after
	 * finalization) and return the latest commit subjects for the
	 * admin UI. Returns an empty array if the repository is not yet
	 * initialised.
	 */
	public static function get_commit_log( $limit = 25 ) {
		try {
			global $wpdb;
			$table = $wpdb->prefix . PMD_Plugin::TABLE_PREFIX . 'files';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
				return array();
			}
			$repository = PMD_Plugin::open_repository();
		} catch ( Throwable $exception ) {
			return array();
		}

		$tip = null;
		foreach ( array( 'refs/heads/trunk', self::SEED_BRANCH ) as $ref ) {
			try {
				if ( ! $repository->branch_exists( $ref ) ) {
					continue;
				}
				$candidate = $repository->get_branch_tip( $ref );
			} catch ( Throwable $exception ) {
				continue;
			}
			if ( is_string( $candidate ) && '' !== $candidate && ! Commit::is_null_hash( $candidate ) ) {
				$tip = $candidate;
				break;
			}
		}

		if ( null === $tip ) {
			return array();
		}

		$commits = array();
		$current = $tip;
		while ( $current && ! Commit::is_null_hash( $current ) && count( $commits ) < $limit ) {
			try {
				$commit = $repository->read_object( $current )->as_commit();
			} catch ( Throwable $exception ) {
				break;
			}
			$lines     = preg_split( "/\r\n|\n|\r/", (string) $commit->message );
			$subject   = is_array( $lines ) && isset( $lines[0] ) ? trim( $lines[0] ) : '';
			$commits[] = array(
				'oid'     => substr( $current, 0, 7 ),
				'subject' => '' !== $subject ? $subject : '(no message)',
			);
			$current   = empty( $commit->parents ) ? null : $commit->parents[0];
		}

		return $commits;
	}

	/**
	 * Return a small, safe preview of the current repository tree for
	 * the first-run admin shell. This is intentionally bounded so the
	 * polling endpoint stays cheap on large sites.
	 */
	public static function get_checkout_preview( $file_limit = 120, $content_limit = 16, $content_length = 6000 ) {
		$preview = array(
			'available'  => false,
			'branch'     => 'trunk',
			'head'       => '',
			'files'      => array(),
			'truncated'  => false,
			'path_count' => 0,
		);

		try {
			global $wpdb;
			$table = $wpdb->prefix . PMD_Plugin::TABLE_PREFIX . 'files';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
				return $preview;
			}

			$repository = PMD_Plugin::open_repository();
			$tip        = self::get_preview_tip( $repository );
			if ( ! $tip ) {
				return $preview;
			}

			$commit = $repository->read_object( $tip )->as_commit();
			if ( Commit::is_null_hash( $commit->tree ) ) {
				$preview['available'] = true;
				$preview['head']      = substr( $tip, 0, 7 );

				return $preview;
			}

			$files                   = array();
			$remaining_content_slots = max( 0, intval( $content_limit ) );
			$truncated               = false;
			self::add_checkout_preview_priority_paths(
				$repository,
				$tip,
				$files,
				max( 1, intval( $content_length ) )
			);
			self::add_checkout_preview_default_guidance_files(
				$files,
				max( 1, intval( $content_length ) )
			);
			self::collect_checkout_preview_files(
				$repository,
				$commit->tree,
				'',
				$files,
				$remaining_content_slots,
				max( 1, intval( $file_limit ) ),
				max( 1, intval( $content_length ) ),
				$truncated
			);

			$preview['available']  = true;
			$preview['head']       = substr( $tip, 0, 7 );
			$preview['files']      = $files;
			$preview['truncated']  = $truncated;
			$preview['path_count'] = count( $files );
		} catch ( Throwable $exception ) {
			return $preview;
		}

		return $preview;
	}

	private static function get_preview_tip( GitRepository $repository ) {
		foreach ( array( 'refs/heads/trunk', self::SEED_BRANCH ) as $ref ) {
			try {
				if ( ! $repository->branch_exists( $ref ) ) {
					continue;
				}
				$candidate = $repository->get_branch_tip( $ref );
			} catch ( Throwable $exception ) {
				continue;
			}
			if ( is_string( $candidate ) && '' !== $candidate && ! Commit::is_null_hash( $candidate ) ) {
				return $candidate;
			}
		}

		return '';
	}

	private static function collect_checkout_preview_files( GitRepository $repository, $tree_hash, $prefix, &$files, &$remaining_content_slots, $file_limit, $content_length, &$truncated ) {
		if ( count( $files ) >= $file_limit ) {
			$truncated = true;

			return;
		}

		$tree    = $repository->read_object( $tree_hash )->as_tree();
		$entries = $tree->entries;
		ksort( $entries );
		foreach ( $entries as $entry ) {
			if ( count( $files ) >= $file_limit ) {
				$truncated = true;

				return;
			}

			$path = ltrim( $prefix . '/' . $entry->name, '/' );
			if ( self::checkout_preview_has_path( $files, $path ) ) {
				continue;
			}
			if ( TreeEntry::FILE_MODE_DIRECTORY === $entry->get_mode_bucket() ) {
				self::collect_checkout_preview_files(
					$repository,
					$entry->hash,
					$path,
					$files,
					$remaining_content_slots,
					$file_limit,
					$content_length,
					$truncated
				);
				continue;
			}

			if (
				TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE !== $entry->get_mode_bucket() &&
				TreeEntry::FILE_MODE_REGULAR_EXECUTABLE !== $entry->get_mode_bucket() &&
				TreeEntry::FILE_MODE_SYMBOLIC_LINK !== $entry->get_mode_bucket()
			) {
				continue;
			}

			$file = array(
				'path' => $path,
				'mode' => $entry->get_mode_bucket(),
				'type' => TreeEntry::FILE_MODE_SYMBOLIC_LINK === $entry->get_mode_bucket() ? 'symlink' : 'file',
			);

			if ( TreeEntry::FILE_MODE_SYMBOLIC_LINK === $entry->get_mode_bucket() ) {
				$content         = $repository->read_object( $entry->hash )->consume_all();
				$file['size']    = strlen( $content );
				$file['content'] = $content;
			} elseif ( $remaining_content_slots > 0 && self::is_checkout_preview_content_path( $path ) ) {
				$content         = $repository->read_object( $entry->hash )->consume_all();
				$file['size']    = strlen( $content );
				$file['content'] = self::trim_checkout_preview_content( $content, $content_length );
				--$remaining_content_slots;
			}

			$files[] = $file;
		}
	}

	private static function add_checkout_preview_priority_paths( GitRepository $repository, $commit_hash, &$files, $content_length ) {
		foreach ( self::get_checkout_preview_priority_paths() as $path ) {
			self::add_checkout_preview_file_from_path(
				$repository,
				$commit_hash,
				$path,
				$files,
				$content_length
			);
		}
	}

	private static function get_checkout_preview_priority_paths() {
		return array(
			'.agents/skills',
			'.claude/skills',
			'AGENTS.md',
			'CLAUDE.md',
			'wp_guideline/skills/push-md/SKILL.md',
			'wp_guideline/skills/push-md-template-editor/SKILL.md',
		);
	}

	private static function add_checkout_preview_file_from_path( GitRepository $repository, $commit_hash, $path, &$files, $content_length ) {
		if ( self::checkout_preview_has_path( $files, $path ) ) {
			return;
		}

		try {
			$entry = self::find_checkout_preview_entry_by_path( $repository, $commit_hash, $path );
			if ( ! $entry || TreeEntry::FILE_MODE_DIRECTORY === $entry->get_mode_bucket() ) {
				return;
			}
			if (
				TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE !== $entry->get_mode_bucket() &&
				TreeEntry::FILE_MODE_REGULAR_EXECUTABLE !== $entry->get_mode_bucket() &&
				TreeEntry::FILE_MODE_SYMBOLIC_LINK !== $entry->get_mode_bucket()
			) {
				return;
			}

			$content = $repository->read_object( $entry->hash )->consume_all();
			$file    = array(
				'path'    => $path,
				'mode'    => $entry->get_mode_bucket(),
				'type'    => TreeEntry::FILE_MODE_SYMBOLIC_LINK === $entry->get_mode_bucket() ? 'symlink' : 'file',
				'size'    => strlen( $content ),
				'content' => TreeEntry::FILE_MODE_SYMBOLIC_LINK === $entry->get_mode_bucket()
					? $content
					: self::trim_checkout_preview_content( $content, $content_length ),
			);
			$files[] = $file;
		} catch ( Throwable $exception ) {
			return;
		}
	}

	private static function find_checkout_preview_entry_by_path( GitRepository $repository, $commit_hash, $path ) {
		$commit = $repository->read_object( $commit_hash )->as_commit();
		if ( Commit::is_null_hash( $commit->tree ) ) {
			return null;
		}

		$tree_hash = $commit->tree;
		$segments  = explode( '/', trim( $path, '/' ) );
		foreach ( $segments as $index => $segment ) {
			$tree = $repository->read_object( $tree_hash )->as_tree();
			if ( ! $tree->has_entry( $segment ) ) {
				return null;
			}

			$entry = $tree->get_entry( $segment );
			if ( count( $segments ) - 1 === $index ) {
				return $entry;
			}
			if ( TreeEntry::FILE_MODE_DIRECTORY !== $entry->get_mode_bucket() ) {
				return null;
			}
			$tree_hash = $entry->hash;
		}

		return null;
	}

	private static function checkout_preview_has_path( $files, $path ) {
		foreach ( $files as $file ) {
			if ( isset( $file['path'] ) && $path === $file['path'] ) {
				return true;
			}
		}

		return false;
	}

	private static function add_checkout_preview_default_guidance_files( &$files, $content_length ) {
		foreach ( PMD_Plugin::get_default_agent_guidance_preview_files() as $path => $entry ) {
			if ( self::checkout_preview_has_path( $files, $path ) ) {
				continue;
			}
			if ( ! isset( $entry['mode'], $entry['content'] ) ) {
				continue;
			}

			$content = (string) $entry['content'];
			$mode    = $entry['mode'];
			$files[] = array(
				'path'    => $path,
				'mode'    => $mode,
				'type'    => TreeEntry::FILE_MODE_SYMBOLIC_LINK === $mode ? 'symlink' : 'file',
				'size'    => strlen( $content ),
				'content' => TreeEntry::FILE_MODE_SYMBOLIC_LINK === $mode
					? $content
					: self::trim_checkout_preview_content( $content, $content_length ),
			);
		}
	}

	private static function is_checkout_preview_content_path( $path ) {
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( in_array( $extension, array( 'md', 'html', 'json', 'txt' ), true ) ) {
			return true;
		}

		return in_array( $path, array( 'AGENTS.md', 'CLAUDE.md' ), true );
	}

	private static function trim_checkout_preview_content( $content, $max_length ) {
		$max_length = intval( $max_length );
		if ( strlen( $content ) <= $max_length ) {
			return $content;
		}

		return substr( $content, 0, $max_length ) . "\n... output truncated ...\n";
	}

	public static function is_ready() {
		return self::STATE_DONE === self::get_state();
	}

	public static function not_ready_message() {
		$progress = self::get_progress( false );
		if ( self::STATE_FAILED === $progress['state'] ) {
			return 'Push MD import failed: ' . $progress['message'];
		}

		return sprintf(
			'Push MD is preparing the repository (%d%%, %d / %d posts). Please try again shortly.',
			$progress['percent'],
			$progress['processed'],
			$progress['total']
		);
	}

	public static function drive( $seconds ) {
		$deadline = microtime( true ) + $seconds;
		while ( ! self::is_ready() && microtime( true ) < $deadline ) {
			$state_before = self::get_state();
			self::tick();
			if ( self::get_state() === $state_before
				&& self::STATE_IN_PROGRESS !== $state_before
				&& self::STATE_PENDING !== $state_before
			) {
				break;
			}
		}
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

		$post_types          = PMD_Plugin::get_supported_post_types();
		$post_statuses       = PMD_Plugin::$supported_post_statuses;
		$post_type_markers   = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
		$post_status_markers = implode( ', ', array_fill( 0, count( $post_statuses ), '%s' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ($post_type_markers) AND post_status IN ($post_status_markers)",
				array_merge( $post_types, $post_statuses )
			)
		);
		// phpcs:enable

		$existing   = self::get_progress_storage();
		$tick_count = isset( $existing['tick_count'] ) ? intval( $existing['tick_count'] ) : 0;
		$progress   = array(
			'processed'    => 0,
			'total'        => $total,
			'last_id'      => 0,
			'tick_count'   => $tick_count,
			'started_at'   => time(),
			'last_tick_at' => time(),
			'message'      => sprintf( 'Found %d content items to import.', $total ),
		);

		$theme_base_oid = self::initialize_theme_base_commit();
		if ( $theme_base_oid ) {
			$progress['theme_base_oid'] = $theme_base_oid;
			$progress['message']        = sprintf( 'Found %d content items to import after staging theme base files.', $total );
		}

		update_option( self::PROGRESS_OPTION, $progress, false );

		// If there's nothing to import, skip straight to finalization so
		// trunk gets an empty initial commit and clones stop being
		// rejected.
		if ( 0 === $total ) {
			update_option( self::STATE_OPTION, self::STATE_FINALIZING, false );
		} else {
			update_option( self::STATE_OPTION, self::STATE_IN_PROGRESS, false );
		}
	}

	private static function initialize_theme_base_commit() {
		$theme_base_files = PMD_Plugin::export_theme_base_content();
		if ( empty( $theme_base_files ) ) {
			return '';
		}

		$updates = array();
		foreach ( $theme_base_files as $path => $entry ) {
			$updates[ $path ] = $entry['content'];
		}

		$repository = PMD_Plugin::open_repository();
		if ( ! $repository->branch_exists( self::SEED_BRANCH ) ) {
			$repository->set_branch_tip( self::SEED_BRANCH, Commit::NULL_HASH );
		}
		$repository->set_branch_tip( 'HEAD', 'ref: ' . self::SEED_BRANCH . "\n" );

		$identity = PMD_Plugin::repository_identity( $repository );
		$now      = gmdate( Commit::DATE_FORMAT );
		$base_oid = $repository->commit(
			array(
				'updates' => $updates,
				'commit'  => array(
					'message'        => PMD_Plugin::THEME_BASE_COMMIT_MESSAGE,
					'author'         => $identity,
					'author_date'    => $now,
					'committer'      => $identity,
					'committer_date' => $now,
				),
			)
		);

		$repository->set_branch_tip( PMD_Plugin::THEME_BASE_REF, $base_oid );
		$repository->set_branch_tip( 'HEAD', "ref: refs/heads/trunk\n" );

		return $base_oid;
	}

	private static function process_batches() {
		$repository = PMD_Plugin::open_repository();
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
				$path             = PMD_Plugin::build_markdown_path( $post );
				$updates[ $path ] = PMD_Plugin::export_post_to_markdown( $post );
				$last_id          = $post->ID;
				++$processed;
			}

			$identity = PMD_Plugin::repository_identity( $repository );
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
		$repository = PMD_Plugin::open_repository();
		$repository->set_branch_tip( 'HEAD', "ref: refs/heads/trunk\n" );

		$seed_tip = $repository->branch_exists( self::SEED_BRANCH )
			? $repository->get_branch_tip( self::SEED_BRANCH )
			: Commit::NULL_HASH;
		$progress = self::get_progress_storage();
		$identity = PMD_Plugin::repository_identity( $repository );
		$now      = gmdate( Commit::DATE_FORMAT );
		$base_oid = isset( $progress['theme_base_oid'] ) ? $progress['theme_base_oid'] : '';

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

			if ( $base_oid && $seed_tip === $base_oid ) {
				$initial_oid         = $base_oid;
				$progress['message'] = 'Initial import complete (theme base only).';
			} else {
				$parents = array();
				if ( $base_oid ) {
					$parents[] = $base_oid;
				}

				// Build a single commit pointing at the seed branch's
				// final tree. This becomes trunk's content overlay, so
				// clones see one clean "Initial import from WordPress"
				// regardless of how many seed batches ran.
				$initial             = new Commit(
					array(
						'tree'           => $seed_commit->tree,
						'parents'        => $parents,
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

		$post_types          = PMD_Plugin::get_supported_post_types();
		$post_statuses       = PMD_Plugin::$supported_post_statuses;
		$post_type_markers   = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
		$post_status_markers = implode( ', ', array_fill( 0, count( $post_statuses ), '%s' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_type IN ($post_type_markers)
				AND post_status IN ($post_status_markers)
				AND ID > %d
				ORDER BY ID ASC
				LIMIT %d",
				array_merge( $post_types, $post_statuses, array( intval( $after_id ), self::batch_size() ) )
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
