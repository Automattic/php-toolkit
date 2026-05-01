<?php

use PHPUnit\Framework\TestCase;

/**
 * End-to-end test for the wp-origin plugin against a real WordPress
 * install backed by the new wpdb-backed Git filesystem.
 *
 * Skips unless the CI workflow sets WP_ORIGIN_E2E_BASE_URL,
 * WP_ORIGIN_E2E_USERNAME, and WP_ORIGIN_E2E_PASSWORD. The CI workflow
 * (`.github/workflows/wp-origin-e2e.yml`) handles all of the WordPress
 * setup (MySQL, wp-cli install, plugin activation, Application Password
 * creation, php -S launch). This file only exercises the running
 * server with the real `git` CLI plus a few REST assertions, so the
 * green build is direct evidence that the plugin can clone, push,
 * pull, and round-trip content end-to-end.
 */
class WP_Origin_End_To_End_Test extends TestCase {

	private $base_url;
	private $username;
	private $password;
	private $auth_header;
	private $work_dir;

	/** @before */
	public function set_up() {
		$this->base_url = getenv( 'WP_ORIGIN_E2E_BASE_URL' );
		$this->username = getenv( 'WP_ORIGIN_E2E_USERNAME' );
		$this->password = getenv( 'WP_ORIGIN_E2E_PASSWORD' );

		if ( ! $this->base_url || ! $this->username || ! $this->password ) {
			$this->markTestSkipped(
				'Set WP_ORIGIN_E2E_BASE_URL, WP_ORIGIN_E2E_USERNAME, and WP_ORIGIN_E2E_PASSWORD to run.'
			);
		}

		$this->auth_header = 'Authorization: Basic ' . base64_encode( $this->username . ':' . $this->password );
		$this->work_dir    = sys_get_temp_dir() . '/wp-origin-e2e-' . uniqid();
		mkdir( $this->work_dir, 0700, true );
	}

	/** @after */
	public function tear_down() {
		if ( $this->work_dir && is_dir( $this->work_dir ) ) {
			$this->run_cmd( array( 'rm', '-rf', $this->work_dir ) );
		}
	}

	public function testSeedStatusReportsDone() {
		$body  = $this->curl_get( $this->base_url . '/wp-json/wp-origin/v1/seed-status' );
		$state = json_decode( $body, true );
		$this->assertIsArray( $state, "Unexpected seed-status response: $body" );
		$this->assertSame( 'done', $state['state'], "Seeder is not done: $body" );
		$this->assertSame( 100, $state['percent'], "Seeder percent is not 100: $body" );
		$this->assertGreaterThan( 0, $state['total'], "Seeder reports zero total posts: $body" );
	}

	public function testSeedingSpansMultipleCronTicks() {
		// The CI workflow seeds 30 posts and drops a mu-plugin that
		// shrinks the batch size to 5 and the time budget to 0
		// seconds. That guarantees the seeder reschedules itself after
		// every batch, so finishing requires several cron ticks. If
		// any of that machinery breaks we'd silently lose resumability
		// — assert directly that the import didn't fit in one tick.
		$body  = $this->curl_get( $this->base_url . '/wp-json/wp-origin/v1/seed-status' );
		$state = json_decode( $body, true );
		$this->assertIsArray( $state, "Unexpected seed-status response: $body" );
		$this->assertGreaterThanOrEqual( 30, $state['total'], "Expected the bulk seed to leave >=30 posts to import: $body" );
		$this->assertGreaterThan( 1, $state['tick_count'], "Seeder finished in a single tick — resumability untested: $body" );
	}

	public function testInitialCommitIsParentless() {
		$clone_dir = $this->clone_repo( 'initial-commit' );
		$result    = $this->run_cmd(
			array( 'git', '-C', $clone_dir, 'log', '--all', '--format=%H %s', '--reverse' )
		);
		$lines = array_values( array_filter( explode( "\n", trim( $result['output'] ) ) ) );
		$this->assertNotEmpty( $lines );

		// First commit must be parent-less. Block themes also get a
		// theme-base commit before the squashed WordPress overlay.
		list( $first_oid, $first_subject ) = explode( ' ', $lines[0], 2 );
		$parents = $this->run_cmd( array( 'git', '-C', $clone_dir, 'rev-list', '--parents', '-n', '1', $first_oid ) );
		$this->assertSame( $first_oid, trim( $parents['output'] ), 'Initial commit must have no parents.' );
		if ( 'Initial theme base from WordPress' === $first_subject ) {
			$this->assertGreaterThanOrEqual( 2, count( $lines ) );
			list( , $second_subject ) = explode( ' ', $lines[1], 2 );
			$this->assertSame( 'Initial import from WordPress', $second_subject );
		} else {
			$this->assertSame( 'Initial import from WordPress', $first_subject );
		}

		// And no "Seed batch" commits should have leaked into trunk.
		$this->assertStringNotContainsString( 'Seed batch', $result['output'] );
	}

	public function testFullRoundTrip() {
		$clone_dir = $this->clone_repo( 'clone' );

		$this->assertFileExists( $clone_dir . '/post/hello-world.md' );
		$this->assertFileExists( $clone_dir . '/page/sample-page.md' );
		$this->assertFileExists( $clone_dir . '/wp_template/blog-home.html' );
		$this->assertNotEmpty( glob( $clone_dir . '/wp_template/*/*.html' ), 'Expected active theme base templates to be exported.' );
		$this->assertNotEmpty( glob( $clone_dir . '/wp_template_part/*/*.html' ), 'Expected active theme base template parts to be exported.' );
		$this->assertNotEmpty( glob( $clone_dir . '/wp_theme/*/theme.json' ), 'Expected active theme theme.json to be exported.' );
		$this->assertNotEmpty( glob( $clone_dir . '/wp_global_styles/*.json' ), 'Expected active theme Global Styles overlay to be exported.' );
		$this->assertFileExists( $clone_dir . '/wp_guideline/skills/wp-origin/SKILL.md' );
		$this->assertFileExists( $clone_dir . '/wp_guideline/skills/wp-origin-template-editor/SKILL.md' );
		$this->assertStringContainsString(
			'Hello from WordPress',
			file_get_contents( $clone_dir . '/post/hello-world.md' )
		);
		$this->assertStringContainsString(
			'Template from WordPress',
			file_get_contents( $clone_dir . '/wp_template/blog-home.html' )
		);
		$wp_origin_skill          = file_get_contents( $clone_dir . '/wp_guideline/skills/wp-origin/SKILL.md' );
		$template_editor_skill    = file_get_contents( $clone_dir . '/wp_guideline/skills/wp-origin-template-editor/SKILL.md' );
		$this->assertStringContainsString(
			'Use the `wp-origin-template-editor` skill before editing',
			$wp_origin_skill
		);
		$this->assertStringContainsString(
			'`wp_global_styles/{theme}.json` contains the editable Global Styles overlay',
			$wp_origin_skill
		);
		$this->assertStringContainsString(
			'do not create flattened files such as `wp_template_part/twentytwentyfive-footer.html`',
			$wp_origin_skill
		);
		$this->assertStringContainsString(
			'The customized database post keeps the slug `footer` and stores `twentytwentyfive` in the `wp_theme` taxonomy.',
			$wp_origin_skill
		);
		$this->assertStringContainsString(
			'maps to the template-part ID `twentytwentyfive//footer`',
			$template_editor_skill
		);
		$this->assertStringContainsString(
			'Do not flatten theme-scoped paths into files such as `wp_template_part/twentytwentyfive-footer.html`',
			$template_editor_skill
		);
		$this->assertStringContainsString(
			'Edit `wp_global_styles/{theme}.json` when the user asks for site-wide theme.json-style changes.',
			$template_editor_skill
		);
		$this->assertStringContainsString(
			'Prefer editable core blocks',
			$template_editor_skill
		);
		$this->assertStringContainsString(
			'Run `git status --short` before committing or pushing',
			$template_editor_skill
		);

		// Capture the page ID up front: WordPress mangles the slug to
		// `sample-page__trashed` once we trash the page in step 6, so a
		// later slug lookup would not find it.
		$sample_page_id = $this->fetch_id_by_slug( 'sample-page', 'pages' );

		$this->configure_git( $clone_dir );

		// Theme source JSON is exported for context, not edited through
		// WP Origin.
		$theme_json_files = glob( $clone_dir . '/wp_theme/*/theme.json' );
		file_put_contents( $theme_json_files[0], "\n", FILE_APPEND );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'add', $theme_json_files[0] ) );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'commit', '-m', 'Edit theme base JSON from Git' ) );
		$push_result = $this->run_cmd(
			array( 'git', '-C', $clone_dir, 'push', 'origin', 'trunk' ),
			true
		);
		$this->assertNotSame( 0, $push_result['code'], 'Theme base JSON edits should have been rejected.' );
		$this->assertStringContainsString( 'Push rejected because theme base files are read-only in WP Origin.', $push_result['output'] );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'reset', '--hard', 'HEAD~1' ) );

		$global_styles_files = glob( $clone_dir . '/wp_global_styles/*.json' );
		$this->assertNotEmpty( $global_styles_files, 'Expected an editable Global Styles overlay.' );
		$global_styles_path = $global_styles_files[0];
		file_put_contents(
			$global_styles_path,
			'{' . "\n"
			. "\t" . '"version": 3,' . "\n"
			. "\t" . '"styles": {' . "\n"
			. "\t\t" . '"color": {' . "\n"
			. "\t\t\t" . '"background": "#123456",' . "\n"
			. "\t\t\t" . '"text": "#ffffff"' . "\n"
			. "\t\t" . '}' . "\n"
			. "\t" . '}' . "\n"
			. '}'
		);
		$this->commit_and_push(
			$clone_dir,
			substr( $global_styles_path, strlen( $clone_dir ) + 1 ),
			'Customize global styles from Git'
		);
		$this->assertStringContainsString( '#123456', $this->curl_get( $this->base_url . '/' ) );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'pull', '--rebase', 'origin', 'trunk' ) );
		$this->assertStringContainsString( '#123456', file_get_contents( $global_styles_path ) );
		$this->assertStringNotContainsString( 'isGlobalStylesUserThemeJSON', file_get_contents( $global_styles_path ) );

		$footer_files = glob( $clone_dir . '/wp_template_part/*/footer.html' );
		$this->assertNotEmpty( $footer_files, 'Expected an active theme footer template part.' );
		$footer_path  = $footer_files[0];
		$footer_theme = basename( dirname( $footer_path ) );
		file_put_contents(
			$footer_path,
			'<!-- wp:group {"align":"full","style":{"color":{"background":"#ff0000"}},"layout":{"type":"constrained"}} -->' . "\n"
			. '<div class="wp-block-group alignfull" style="background-color:#ff0000">' . "\n"
			. "\t" . '<!-- wp:paragraph {"style":{"color":{"text":"#ffffff"}}} -->' . "\n"
			. "\t" . '<p style="color:#ffffff">Theme footer customized from Git</p>' . "\n"
			. "\t" . '<!-- /wp:paragraph -->' . "\n"
			. '</div>' . "\n"
			. '<!-- /wp:group -->'
		);
		$this->commit_and_push(
			$clone_dir,
			substr( $footer_path, strlen( $clone_dir ) + 1 ),
			'Customize theme footer from Git'
		);
		$footer = json_decode(
			$this->curl_get( $this->base_url . '/wp-json/wp/v2/template-parts/' . rawurlencode( $footer_theme ) . '//footer?context=edit' ),
			true
		);
		$this->assertSame( 'custom', $footer['source'] );
		$this->assertGreaterThan( 0, intval( $footer['wp_id'] ) );
		$this->assertStringContainsString( 'Theme footer customized from Git', $footer['content']['raw'] );
		$this->assertStringContainsString( 'Theme footer customized from Git', $this->curl_get( $this->base_url . '/' ) );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'pull', '--rebase', 'origin', 'trunk' ) );
		$this->assertFileDoesNotExist( $clone_dir . '/wp_template_part/' . $footer_theme . '-footer.html' );

		// 1) Update an existing post via Git push.
		$this->edit_file(
			$clone_dir . '/post/hello-world.md',
			'Hello from WordPress',
			'Updated from Git'
		);
		$this->commit_and_push( $clone_dir, 'post/hello-world.md', 'Update hello world from Git' );

		$post_id = $this->fetch_id_by_slug( 'hello-world', 'posts' );
		$this->assertStringContainsString(
			'Updated from Git',
			$this->fetch_content( $post_id, 'posts' )
		);

		// 2) Pull the resulting sync commit so HEAD is in sync with the
		// server before the next push.
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'pull', '--rebase', 'origin', 'trunk' ) );

		// 2a) A WordPress editor save with Gutenberg inline markup must
		// create a visible Git delta on the next fetch.
		$this->update_post_via_rest(
			$post_id,
			array(
				'content' => '<!-- wp:heading --><h2 class="wp-block-heading">Updated from Git <s>from editor</s></h2><!-- /wp:heading -->',
			)
		);
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'pull', '--rebase', 'origin', 'trunk' ) );
		$this->assertStringContainsString(
			'## Updated from Git ~~from editor~~',
			file_get_contents( $clone_dir . '/post/hello-world.md' )
		);

		// 3) Update and create template HTML files without front matter.
		$this->edit_file(
			$clone_dir . '/wp_template/blog-home.html',
			'Template from WordPress',
			'Template updated from Git'
		);
		file_put_contents(
			$clone_dir . '/wp_template/custom-blog-card.html',
			'<!-- wp:paragraph --><p>Created template from Git.</p><!-- /wp:paragraph -->'
		);
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'add', 'wp_template/blog-home.html', 'wp_template/custom-blog-card.html' ) );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'commit', '-m', 'Update template HTML from Git' ) );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'push', 'origin', 'trunk' ) );

		// 4) Template HTML files may be updated or created, but not deleted.
		unlink( $clone_dir . '/wp_template/custom-blog-card.html' );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'add', '-A' ) );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'commit', '-m', 'Delete template HTML from Git' ) );
		$push_result = $this->run_cmd(
			array( 'git', '-C', $clone_dir, 'push', 'origin', 'trunk' ),
			true
		);
		$this->assertNotSame( 0, $push_result['code'], 'Template deletion should have been rejected.' );
		$this->assertStringContainsString( 'Push rejected because template HTML files cannot be deleted or renamed.', $push_result['output'] );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'reset', '--hard', 'HEAD~1' ) );

		// 5) Renames are also rejected because the path is the identity.
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'mv', 'wp_template/custom-blog-card.html', 'wp_template/renamed-blog-card.html' ) );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'commit', '-m', 'Rename template HTML from Git' ) );
		$push_result = $this->run_cmd(
			array( 'git', '-C', $clone_dir, 'push', 'origin', 'trunk' ),
			true
		);
		$this->assertNotSame( 0, $push_result['code'], 'Template rename should have been rejected.' );
		$this->assertStringContainsString( 'Push rejected because template HTML files cannot be deleted or renamed.', $push_result['output'] );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'reset', '--hard', 'HEAD~1' ) );

		// 6) Create a new post and delete an existing page in one push.
		file_put_contents(
			$clone_dir . '/post/created-from-git.md',
			"---\ntype: \"post\"\nslug: \"created-from-git\"\nstatus: \"publish\"\ntitle: \"Created From Git\"\n---\n\nCreated from Git.\n"
		);
		unlink( $clone_dir . '/page/sample-page.md' );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'add', '-A' ) );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'commit', '-m', 'Create and delete content from Git' ) );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'push', 'origin', 'trunk' ) );

		$created_id = $this->fetch_id_by_slug( 'created-from-git', 'posts' );
		$this->assertStringContainsString(
			'Created from Git',
			$this->fetch_content( $created_id, 'posts' )
		);
		$this->assertSame( 'trash', $this->fetch_status( $sample_page_id, 'pages' ) );

		// 7) Stale push: an out-of-date local commit must be rejected.
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'pull', '--rebase', 'origin', 'trunk' ) );
		$this->edit_file(
			$clone_dir . '/post/hello-world.md',
			'Updated from Git ~~from editor~~',
			'Stale local edit'
		);
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'add', 'post/hello-world.md' ) );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'commit', '-m', 'Stale local edit' ) );

		$this->update_post_via_rest(
			$post_id,
			array(
				'content' => '<!-- wp:paragraph --><p>Updated in WordPress</p><!-- /wp:paragraph -->',
			)
		);

		$push_result = $this->run_cmd(
			array( 'git', '-C', $clone_dir, 'push', 'origin', 'trunk' ),
			true
		);
		$this->assertNotSame( 0, $push_result['code'], 'Stale push should have been rejected.' );

		// 8) Persistence proof: a brand-new clone (no shared state with
		// $clone_dir) must see every commit and file from above.
		$fresh = $this->clone_repo( 'fresh' );
		$this->assertFileExists( $fresh . '/post/hello-world.md' );
		$this->assertFileExists( $fresh . '/post/created-from-git.md' );
		$this->assertFileExists( $fresh . '/wp_template/blog-home.html' );
		$this->assertFileExists( $fresh . '/wp_template/custom-blog-card.html' );
		$this->assertFileDoesNotExist( $fresh . '/page/sample-page.md' );
		$this->assertFileDoesNotExist( $fresh . '/wp_template/renamed-blog-card.html' );
		$this->assertStringContainsString(
			'Template updated from Git',
			file_get_contents( $fresh . '/wp_template/blog-home.html' )
		);
		$this->assertStringContainsString(
			'Created template from Git',
			file_get_contents( $fresh . '/wp_template/custom-blog-card.html' )
		);
		$log = $this->run_cmd( array( 'git', '-C', $fresh, 'log', '--format=%s' ) );
		$this->assertStringContainsString( 'Update hello world from Git', $log['output'] );
		$this->assertStringContainsString( 'Update template HTML from Git', $log['output'] );
		$this->assertStringContainsString( 'Create and delete content from Git', $log['output'] );

		// 9) The CPT-based persistence model is gone.
		$this->assertNotSame(
			200,
			$this->http_status( $this->base_url . '/wp-json/wp/v2/types/wp_origin_commit' )
		);
	}

	private function remote_url() {
		$parts = parse_url( $this->base_url );
		$auth  = rawurlencode( $this->username ) . ':' . rawurlencode( $this->password );
		$host  = $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );

		return $parts['scheme'] . '://' . $auth . '@' . $host . '/wp-json/git/v1/md.git';
	}

	private function clone_repo( $name ) {
		$dir = $this->work_dir . '/' . $name;
		$this->run_cmd(
			array( 'git', '-c', 'protocol.version=2', 'clone', $this->remote_url(), $dir )
		);
		$status = $this->run_cmd( array( 'git', '-C', $dir, 'status', '--porcelain' ) );
		$this->assertSame( '', trim( $status['output'] ), 'Fresh clones should not have staged or unstaged changes.' );

		return $dir;
	}

	private function configure_git( $dir ) {
		$this->run_cmd( array( 'git', '-C', $dir, 'config', 'user.name', 'WP Origin E2E' ) );
		$this->run_cmd( array( 'git', '-C', $dir, 'config', 'user.email', 'wp-origin-e2e@example.com' ) );
	}

	private function edit_file( $path, $needle, $replacement ) {
		$contents = file_get_contents( $path );
		file_put_contents( $path, str_replace( $needle, $replacement, $contents ) );
	}

	private function commit_and_push( $dir, $relative_path, $message ) {
		$this->run_cmd( array( 'git', '-C', $dir, 'add', $relative_path ) );
		$this->run_cmd( array( 'git', '-C', $dir, 'commit', '-m', $message ) );
		$this->run_cmd( array( 'git', '-C', $dir, 'push', 'origin', 'trunk' ) );
	}

	private function fetch_id_by_slug( $slug, $endpoint ) {
		$url = $this->base_url . '/wp-json/wp/v2/' . $endpoint
			. '?slug=' . rawurlencode( $slug ) . '&context=edit';
		$body  = $this->curl_get( $url );
		$items = json_decode( $body, true );
		$this->assertIsArray( $items, "Unexpected REST response for $endpoint?slug=$slug: $body" );
		$this->assertNotEmpty( $items, "No $endpoint match for slug $slug" );

		return intval( $items[0]['id'] );
	}

	private function fetch_content( $id, $endpoint ) {
		$body = $this->curl_get( $this->base_url . '/wp-json/wp/v2/' . $endpoint . '/' . $id . '?context=edit' );
		$post = json_decode( $body, true );

		return $post['content']['raw'];
	}

	private function fetch_status( $id, $endpoint ) {
		$body = $this->curl_get( $this->base_url . '/wp-json/wp/v2/' . $endpoint . '/' . $id . '?context=edit' );
		$post = json_decode( $body, true );

		return $post['status'];
	}

	private function update_post_via_rest( $id, array $payload ) {
		$ch = curl_init( $this->base_url . '/wp-json/wp/v2/posts/' . $id . '?context=edit' );
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_CUSTOMREQUEST  => 'POST',
				CURLOPT_HTTPHEADER     => array( $this->auth_header, 'Content-Type: application/json' ),
				CURLOPT_POSTFIELDS     => wp_origin_e2e_json_encode( $payload ),
			)
		);
		$response = curl_exec( $ch );
		$status   = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );
		$this->assertSame( 200, $status, "REST update failed: $response" );
	}

	private function curl_get( $url ) {
		$ch = curl_init( $url );
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HTTPHEADER     => array( $this->auth_header ),
			)
		);
		$body = curl_exec( $ch );
		curl_close( $ch );

		return $body;
	}

	private function http_status( $url ) {
		$ch = curl_init( $url );
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_NOBODY         => true,
				CURLOPT_HTTPHEADER     => array( $this->auth_header ),
			)
		);
		curl_exec( $ch );
		$status = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );

		return $status;
	}

	private function run_cmd( array $args, $allow_failure = false ) {
		$command = '';
		foreach ( $args as $arg ) {
			$command .= escapeshellarg( $arg ) . ' ';
		}
		$command .= '2>&1';
		exec( $command, $output, $code );
		if ( ! $allow_failure && 0 !== $code ) {
			$this->fail( "Command failed (exit $code): $command\n" . implode( "\n", $output ) );
		}

		return array(
			'code'   => $code,
			'output' => implode( "\n", $output ),
		);
	}
}

function wp_origin_e2e_json_encode( $value ) {
	return json_encode( $value );
}
