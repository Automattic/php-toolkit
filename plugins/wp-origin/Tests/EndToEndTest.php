<?php
// phpcs:disable WordPress.WP.AlternativeFunctions -- These E2E tests run outside WordPress and exercise HTTP/git behavior directly.

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) && ! class_exists( TestCase::class ) ) {
	exit;
}

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

	public function testCloneFailsClosedWhenPageParentIsNotExported() {
		$suffix    = uniqid( 'trashed-parent-' );
		$parent_id = $this->create_page_via_rest(
			array(
				'slug'    => 'parent-' . $suffix,
				'title'   => 'Parent ' . $suffix,
				'status'  => 'publish',
				'content' => '<!-- wp:paragraph --><p>Parent ' . $suffix . '</p><!-- /wp:paragraph -->',
			)
		);
		$this->create_page_via_rest(
			array(
				'slug'    => 'child-' . $suffix,
				'title'   => 'Child ' . $suffix,
				'status'  => 'publish',
				'parent'  => $parent_id,
				'content' => '<!-- wp:paragraph --><p>Child ' . $suffix . '</p><!-- /wp:paragraph -->',
			)
		);

		try {
			$this->delete_page_via_rest( $parent_id );
			$result = $this->run_cmd(
				array( 'git', '-c', 'protocol.version=2', 'clone', $this->remote_url(), $this->work_dir . '/trashed-parent' ),
				true
			);
			$this->assertNotSame( 0, $result['code'], 'Clone should fail closed when an exported page has a trashed parent.' );
		} finally {
			$this->update_page_via_rest( $parent_id, array( 'status' => 'publish' ) );
		}
	}

	public function testFullRoundTrip() {
		$hierarchy_suffix = uniqid( 'hierarchy-' );
		$parent_a_slug    = 'parent-a-' . $hierarchy_suffix;
		$parent_b_slug    = 'parent-b-' . $hierarchy_suffix;
		$child_slug       = 'shared-child-' . $hierarchy_suffix;
		$parent_a_id      = $this->create_page_via_rest(
			array(
				'slug'    => $parent_a_slug,
				'title'   => 'Parent A ' . $hierarchy_suffix,
				'status'  => 'publish',
				'content' => '<!-- wp:paragraph --><p>Parent A ' . $hierarchy_suffix . '</p><!-- /wp:paragraph -->',
			)
		);
		$parent_b_id      = $this->create_page_via_rest(
			array(
				'slug'    => $parent_b_slug,
				'title'   => 'Parent B ' . $hierarchy_suffix,
				'status'  => 'publish',
				'content' => '<!-- wp:paragraph --><p>Parent B ' . $hierarchy_suffix . '</p><!-- /wp:paragraph -->',
			)
		);
		$this->create_page_via_rest(
			array(
				'slug'    => $child_slug,
				'title'   => 'Shared Child A ' . $hierarchy_suffix,
				'status'  => 'publish',
				'parent'  => $parent_a_id,
				'content' => '<!-- wp:paragraph --><p>Shared Child A ' . $hierarchy_suffix . '</p><!-- /wp:paragraph -->',
			)
		);
		$this->create_page_via_rest(
			array(
				'slug'    => $child_slug,
				'title'   => 'Shared Child B ' . $hierarchy_suffix,
				'status'  => 'publish',
				'parent'  => $parent_b_id,
				'content' => '<!-- wp:paragraph --><p>Shared Child B ' . $hierarchy_suffix . '</p><!-- /wp:paragraph -->',
			)
		);

		$clone_dir = $this->clone_repo( 'clone' );

		$this->assertFileExists( $clone_dir . '/post/hello-world.md' );
		$this->assertFileExists( $clone_dir . '/page/sample-page.md' );
		$this->assertFileExists( $clone_dir . '/page/' . $parent_a_slug . '/' . $child_slug . '.md' );
		$this->assertFileExists( $clone_dir . '/page/' . $parent_b_slug . '/' . $child_slug . '.md' );
		$this->assertStringContainsString(
			'Shared Child A ' . $hierarchy_suffix,
			file_get_contents( $clone_dir . '/page/' . $parent_a_slug . '/' . $child_slug . '.md' )
		);
		$this->assertStringContainsString(
			'Shared Child B ' . $hierarchy_suffix,
			file_get_contents( $clone_dir . '/page/' . $parent_b_slug . '/' . $child_slug . '.md' )
		);
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
		// `renamed-sample-page__trashed` once we trash the page in step 6, so a
		// later slug lookup would not find it.
		$sample_page_id = $this->fetch_id_by_slug( 'sample-page', 'pages' );
		$this->assertStringContainsString(
			'id: "' . $sample_page_id . '"',
			file_get_contents( $clone_dir . '/page/sample-page.md' )
		);

		$this->configure_git( $clone_dir );

		$this->run_cmd( array( 'git', '-C', $clone_dir, 'mv', 'page/sample-page.md', 'page/renamed-sample-page.md' ) );
		$this->edit_file(
			$clone_dir . '/page/renamed-sample-page.md',
			'Page from WordPress',
			'Renamed page from Git'
		);
		$this->commit_and_push( $clone_dir, 'page/renamed-sample-page.md', 'Rename page from Git' );
		$this->assertSame( $sample_page_id, $this->fetch_id_by_slug( 'renamed-sample-page', 'pages' ) );
		$this->assert_slug_absent( 'sample-page', 'pages' );
		$this->assertStringContainsString(
			'Renamed page from Git',
			$this->fetch_content( $sample_page_id, 'pages' )
		);
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'pull', '--rebase', 'origin', 'trunk' ) );

		$git_child_slug = 'git-child-' . $hierarchy_suffix;
		file_put_contents(
			$clone_dir . '/page/' . $parent_a_slug . '/' . $git_child_slug . '.md',
			"---\nstatus: \"publish\"\ntitle: \"Git Child $hierarchy_suffix\"\n---\n\nNested child from Git.\n"
		);
		$this->commit_and_push(
			$clone_dir,
			'page/' . $parent_a_slug . '/' . $git_child_slug . '.md',
			'Create nested child page from Git'
		);
		$git_child_id = $this->fetch_id_by_slug( $git_child_slug, 'pages' );
		$git_child    = $this->fetch_rest_item( $git_child_id, 'pages' );
		$this->assertSame( $parent_a_id, intval( $git_child['parent'] ) );
		$this->assertStringContainsString( 'Nested child from Git', $git_child['content']['raw'] );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'pull', '--rebase', 'origin', 'trunk' ) );

		unlink( $clone_dir . '/page/' . $parent_a_slug . '.md' );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'add', '-A' ) );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'commit', '-m', 'Reject parent page delete with children' ) );
		$push_result = $this->run_cmd(
			array( 'git', '-C', $clone_dir, 'push', 'origin', 'trunk' ),
			true
		);
		$this->assertNotSame( 0, $push_result['code'], 'Parent page deletion with remaining children should have been rejected.' );
		$this->assertStringContainsString( 'Push rejected because deleting a parent page while keeping nested child page files would move child content.', $push_result['output'] );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'reset', '--hard', 'HEAD~1' ) );

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
			"---\nstatus: \"publish\"\ntitle: \"Created From Git\"\n---\n\nCreated from Git.\n"
		);
		unlink( $clone_dir . '/page/renamed-sample-page.md' );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'add', '-A' ) );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'commit', '-m', 'Create and delete content from Git' ) );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'push', 'origin', 'trunk' ) );

		$created_id = $this->fetch_id_by_slug( 'created-from-git', 'posts' );
		$this->assertStringContainsString(
			'Created from Git',
			$this->fetch_content( $created_id, 'posts' )
		);
		$this->assertSame( 'trash', $this->fetch_status( $sample_page_id, 'pages' ) );

		// 7) Front matter may carry the WordPress ID for rename
		// continuity, but invalid IDs, slugs, and types are rejected.
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'pull', '--rebase', 'origin', 'trunk' ) );
		file_put_contents(
			$clone_dir . '/post/rejected-id-frontmatter.md',
			"---\nid: \"0\"\nstatus: \"publish\"\ntitle: \"Rejected ID Front Matter\"\n---\n\nThis push must be rejected.\n"
		);
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'add', 'post/rejected-id-frontmatter.md' ) );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'commit', '-m', 'Reject post id front matter' ) );
		$push_result = $this->run_cmd(
			array( 'git', '-C', $clone_dir, 'push', 'origin', 'trunk' ),
			true
		);
		$this->assertNotSame( 0, $push_result['code'], 'ID front matter should have been rejected.' );
		$this->assertStringContainsString( 'Push rejected because Markdown front matter id must be a positive integer.', $push_result['output'] );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'reset', '--hard', 'HEAD~1' ) );

		file_put_contents(
			$clone_dir . '/post/rejected-slug-frontmatter.md',
			"---\nslug: \"rejected-slug-frontmatter\"\nstatus: \"publish\"\ntitle: \"Rejected Slug Front Matter\"\n---\n\nThis push must be rejected.\n"
		);
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'add', 'post/rejected-slug-frontmatter.md' ) );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'commit', '-m', 'Reject post slug front matter' ) );
		$push_result = $this->run_cmd(
			array( 'git', '-C', $clone_dir, 'push', 'origin', 'trunk' ),
			true
		);
		$this->assertNotSame( 0, $push_result['code'], 'Slug front matter should have been rejected.' );
		$this->assertStringContainsString( 'Push rejected because Markdown front matter must not include a slug.', $push_result['output'] );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'reset', '--hard', 'HEAD~1' ) );

		file_put_contents(
			$clone_dir . '/post/rejected-type-frontmatter.md',
			"---\ntype: \"post\"\nstatus: \"publish\"\ntitle: \"Rejected Type Front Matter\"\n---\n\nThis push must be rejected.\n"
		);
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'add', 'post/rejected-type-frontmatter.md' ) );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'commit', '-m', 'Reject post type front matter' ) );
		$push_result = $this->run_cmd(
			array( 'git', '-C', $clone_dir, 'push', 'origin', 'trunk' ),
			true
		);
		$this->assertNotSame( 0, $push_result['code'], 'Type front matter should have been rejected.' );
		$this->assertStringContainsString( 'Push rejected because Markdown front matter must not include a type.', $push_result['output'] );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'reset', '--hard', 'HEAD~1' ) );

		mkdir( $clone_dir . '/post/nested-path' );
		file_put_contents(
			$clone_dir . '/post/nested-path/rejected-nested-path.md',
			"---\nstatus: \"publish\"\ntitle: \"Rejected Nested Path\"\n---\n\nThis push must be rejected.\n"
		);
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'add', 'post/nested-path/rejected-nested-path.md' ) );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'commit', '-m', 'Reject nested post path' ) );
		$push_result = $this->run_cmd(
			array( 'git', '-C', $clone_dir, 'push', 'origin', 'trunk' ),
			true
		);
		$this->assertNotSame( 0, $push_result['code'], 'Nested post paths should have been rejected.' );
		$this->assertStringContainsString( 'Push rejected because post Markdown files must use post/<slug>.md paths.', $push_result['output'] );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'reset', '--hard', 'HEAD~1' ) );

		file_put_contents(
			$clone_dir . '/post/Rejected Upper Slug.md',
			"---\nstatus: \"publish\"\ntitle: \"Rejected Upper Slug\"\n---\n\nThis push must be rejected.\n"
		);
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'add', 'post/Rejected Upper Slug.md' ) );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'commit', '-m', 'Reject noncanonical post slug' ) );
		$push_result = $this->run_cmd(
			array( 'git', '-C', $clone_dir, 'push', 'origin', 'trunk' ),
			true
		);
		$this->assertNotSame( 0, $push_result['code'], 'Noncanonical post slugs should have been rejected.' );
		$this->assertStringContainsString( 'Push rejected because Markdown file slugs must already match WordPress slug formatting.', $push_result['output'] );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'reset', '--hard', 'HEAD~1' ) );

		mkdir( $clone_dir . '/wp_template/Bad Theme' );
		file_put_contents(
			$clone_dir . '/wp_template/Bad Theme/rejected-raw-path.html',
			'<!-- wp:paragraph --><p>This push must be rejected.</p><!-- /wp:paragraph -->'
		);
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'add', 'wp_template/Bad Theme/rejected-raw-path.html' ) );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'commit', '-m', 'Reject noncanonical template theme path' ) );
		$push_result = $this->run_cmd(
			array( 'git', '-C', $clone_dir, 'push', 'origin', 'trunk' ),
			true
		);
		$this->assertNotSame( 0, $push_result['code'], 'Noncanonical template theme paths should have been rejected.' );
		$this->assertStringContainsString( 'Push rejected because template theme path segments must already match WordPress slug formatting.', $push_result['output'] );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'reset', '--hard', 'HEAD~1' ) );

		file_put_contents(
			$clone_dir . '/wp_template/Rejected Raw Slug.html',
			'<!-- wp:paragraph --><p>This push must be rejected.</p><!-- /wp:paragraph -->'
		);
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'add', 'wp_template/Rejected Raw Slug.html' ) );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'commit', '-m', 'Reject noncanonical template slug path' ) );
		$push_result = $this->run_cmd(
			array( 'git', '-C', $clone_dir, 'push', 'origin', 'trunk' ),
			true
		);
		$this->assertNotSame( 0, $push_result['code'], 'Noncanonical template slug paths should have been rejected.' );
		$this->assertStringContainsString( 'Push rejected because template file slugs must already match WordPress slug formatting.', $push_result['output'] );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'reset', '--hard', 'HEAD~1' ) );

		file_put_contents(
			$clone_dir . '/wp_template/rejected-plain-html.html',
			"<div>This push must be rejected.</div>\n"
		);
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'add', 'wp_template/rejected-plain-html.html' ) );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'commit', '-m', 'Reject plain template HTML' ) );
		$push_result = $this->run_cmd(
			array( 'git', '-C', $clone_dir, 'push', 'origin', 'trunk' ),
			true
		);
		$this->assertNotSame( 0, $push_result['code'], 'Plain template HTML should have been rejected.' );
		$this->assertStringContainsString( 'Push rejected because template HTML files must contain serialized Gutenberg block markup.', $push_result['output'] );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'reset', '--hard', 'HEAD~1' ) );

		file_put_contents(
			$clone_dir . '/wp_global_styles/Bad-Theme.json',
			"{\"version\":3,\"settings\":{},\"styles\":{}}\n"
		);
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'add', 'wp_global_styles/Bad-Theme.json' ) );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'commit', '-m', 'Reject noncanonical Global Styles path' ) );
		$push_result = $this->run_cmd(
			array( 'git', '-C', $clone_dir, 'push', 'origin', 'trunk' ),
			true
		);
		$this->assertNotSame( 0, $push_result['code'], 'Noncanonical Global Styles filenames should have been rejected.' );
		$this->assertStringContainsString( 'Push rejected because the Global Styles theme filename must already match WordPress slug formatting.', $push_result['output'] );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'reset', '--hard', 'HEAD~1' ) );

		if ( @symlink( '../post/hello-world.md', $clone_dir . '/post/rejected-symlink.md' ) ) {
			$this->run_cmd( array( 'git', '-C', $clone_dir, 'add', 'post/rejected-symlink.md' ) );
			$this->run_cmd( array( 'git', '-C', $clone_dir, 'commit', '-m', 'Reject arbitrary symlink' ) );
			$push_result = $this->run_cmd(
				array( 'git', '-C', $clone_dir, 'push', 'origin', 'trunk' ),
				true
			);
			$this->assertNotSame( 0, $push_result['code'], 'Arbitrary symlinks should have been rejected.' );
			$this->assertStringContainsString( 'Push rejected because symlink files are generated by WP Origin and cannot be created or modified.', $push_result['output'] );
			$this->run_cmd( array( 'git', '-C', $clone_dir, 'reset', '--hard', 'HEAD~1' ) );
		}

		if ( is_link( $clone_dir . '/AGENTS.md' ) ) {
			$this->run_cmd( array( 'git', '-C', $clone_dir, 'rm', 'AGENTS.md' ) );
			$this->run_cmd( array( 'git', '-C', $clone_dir, 'commit', '-m', 'Reject generated symlink deletion' ) );
			$push_result = $this->run_cmd(
				array( 'git', '-C', $clone_dir, 'push', 'origin', 'trunk' ),
				true
			);
			$this->assertNotSame( 0, $push_result['code'], 'Generated symlink deletion should have been rejected.' );
			$this->assertStringContainsString( 'Push rejected because symlink files are generated by WP Origin and cannot be deleted or modified.', $push_result['output'] );
			$this->run_cmd( array( 'git', '-C', $clone_dir, 'reset', '--hard', 'HEAD~1' ) );
		}

		file_put_contents(
			$clone_dir . '/post/rejected-executable.md',
			"---\nstatus: \"publish\"\ntitle: \"Rejected Executable\"\n---\n\nThis push must be rejected.\n"
		);
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'add', 'post/rejected-executable.md' ) );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'update-index', '--chmod=+x', 'post/rejected-executable.md' ) );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'commit', '-m', 'Reject executable content file' ) );
		$push_result = $this->run_cmd(
			array( 'git', '-C', $clone_dir, 'push', 'origin', 'trunk' ),
			true
		);
		$this->assertNotSame( 0, $push_result['code'], 'Executable content files should have been rejected.' );
		$this->assertStringContainsString( 'Push rejected because executable file modes are not supported by WP Origin content exports.', $push_result['output'] );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'reset', '--hard', 'HEAD~1' ) );

		file_put_contents(
			$clone_dir . '/post/rejected-multi-ref.md',
			"---\nstatus: \"publish\"\ntitle: \"Rejected Multi Ref\"\n---\n\nThis push must be rejected before WordPress writes.\n"
		);
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'add', 'post/rejected-multi-ref.md' ) );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'commit', '-m', 'Reject multi-ref push' ) );
		$push_result = $this->run_cmd(
			array( 'git', '-C', $clone_dir, 'push', 'origin', 'HEAD:trunk', 'HEAD:refs/heads/rejected-multi-ref' ),
			true
		);
		$this->assertNotSame( 0, $push_result['code'], 'Multi-ref pushes should have been rejected.' );
		$this->assertStringContainsString( 'Push rejected because WP Origin only accepts one ref update at a time.', $push_result['output'] );
		$this->assert_slug_absent( 'rejected-multi-ref', 'posts' );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'reset', '--hard', 'HEAD~1' ) );

		$push_result = $this->run_cmd(
			array( 'git', '-C', $clone_dir, 'push', 'origin', ':trunk' ),
			true
		);
		$this->assertNotSame( 0, $push_result['code'], 'Deleting trunk should have been rejected.' );
		$this->assertStringContainsString( 'Push rejected because deleting trunk is not supported.', $push_result['output'] );

		// 8) Push validation must finish before any WordPress writes.
		file_put_contents(
			$clone_dir . '/page/atomic-valid-page.md',
			"---\nstatus: \"publish\"\ntitle: \"Atomic Valid Page\"\n---\n\nThis page must not be written if the push is rejected.\n"
		);
		file_put_contents(
			$clone_dir . '/post/atomic-invalid-status.md',
			"---\nstatus: \"invalid-status\"\ntitle: \"Atomic Invalid Status\"\n---\n\nThis push must be rejected.\n"
		);
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'add', 'page/atomic-valid-page.md', 'post/atomic-invalid-status.md' ) );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'commit', '-m', 'Reject atomic partial writes' ) );
		$push_result = $this->run_cmd(
			array( 'git', '-C', $clone_dir, 'push', 'origin', 'trunk' ),
			true
		);
		$this->assertNotSame( 0, $push_result['code'], 'Invalid status push should have been rejected.' );
		$this->assertStringContainsString( 'Push rejected because "invalid-status" is not a supported post status.', $push_result['output'] );
		$this->assert_slug_absent( 'atomic-valid-page', 'pages' );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'reset', '--hard', 'HEAD~1' ) );

		file_put_contents(
			$clone_dir . '/post/rejected-frontmatter.md',
			"---\nstatus: \"publish\"\ntitle: \"Rejected Front Matter\"\n\nThis push must be rejected.\n"
		);
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'add', 'post/rejected-frontmatter.md' ) );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'commit', '-m', 'Reject malformed front matter' ) );
		$push_result = $this->run_cmd(
			array( 'git', '-C', $clone_dir, 'push', 'origin', 'trunk' ),
			true
		);
		$this->assertNotSame( 0, $push_result['code'], 'Malformed front matter should have been rejected.' );
		$this->assertStringContainsString( 'Push rejected because Markdown front matter is missing its closing --- fence.', $push_result['output'] );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'reset', '--hard', 'HEAD~1' ) );

		file_put_contents(
			$clone_dir . '/post/rejected-invalid-date.md',
			"---\nstatus: \"publish\"\ndate: \"not a date\"\ntitle: \"Rejected Invalid Date\"\n---\n\nThis push must be rejected.\n"
		);
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'add', 'post/rejected-invalid-date.md' ) );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'commit', '-m', 'Reject invalid front matter date' ) );
		$push_result = $this->run_cmd(
			array( 'git', '-C', $clone_dir, 'push', 'origin', 'trunk' ),
			true
		);
		$this->assertNotSame( 0, $push_result['code'], 'Invalid date front matter should have been rejected.' );
		$this->assertStringContainsString( 'Push rejected because Markdown front matter date is invalid.', $push_result['output'] );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'reset', '--hard', 'HEAD~1' ) );

		file_put_contents(
			$clone_dir . '/post/rejected-impossible-date.md',
			"---\nstatus: \"publish\"\ndate: \"2024-02-31\"\ntitle: \"Rejected Impossible Date\"\n---\n\nThis push must be rejected.\n"
		);
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'add', 'post/rejected-impossible-date.md' ) );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'commit', '-m', 'Reject impossible front matter date' ) );
		$push_result = $this->run_cmd(
			array( 'git', '-C', $clone_dir, 'push', 'origin', 'trunk' ),
			true
		);
		$this->assertNotSame( 0, $push_result['code'], 'Impossible date front matter should have been rejected.' );
		$this->assertStringContainsString( 'Push rejected because Markdown front matter date is invalid.', $push_result['output'] );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'reset', '--hard', 'HEAD~1' ) );

		file_put_contents(
			$clone_dir . '/post/rejected-scheduled-no-date.md',
			"---\nstatus: \"scheduled\"\ntitle: \"Rejected Scheduled No Date\"\n---\n\nThis push must be rejected.\n"
		);
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'add', 'post/rejected-scheduled-no-date.md' ) );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'commit', '-m', 'Reject scheduled post without date' ) );
		$push_result = $this->run_cmd(
			array( 'git', '-C', $clone_dir, 'push', 'origin', 'trunk' ),
			true
		);
		$this->assertNotSame( 0, $push_result['code'], 'Scheduled posts without dates should have been rejected.' );
		$this->assertStringContainsString( 'Push rejected because scheduled posts must include a future date.', $push_result['output'] );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'reset', '--hard', 'HEAD~1' ) );

		file_put_contents(
			$clone_dir . '/post/rejected-scheduled-past-date.md',
			"---\nstatus: \"scheduled\"\ndate: \"2000-01-01T00:00:00Z\"\ntitle: \"Rejected Scheduled Past Date\"\n---\n\nThis push must be rejected.\n"
		);
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'add', 'post/rejected-scheduled-past-date.md' ) );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'commit', '-m', 'Reject scheduled post with past date' ) );
		$push_result = $this->run_cmd(
			array( 'git', '-C', $clone_dir, 'push', 'origin', 'trunk' ),
			true
		);
			$this->assertNotSame( 0, $push_result['code'], 'Scheduled posts with past dates should have been rejected.' );
			$this->assertStringContainsString( 'Push rejected because scheduled posts must include a date in the future.', $push_result['output'] );
			$this->run_cmd( array( 'git', '-C', $clone_dir, 'reset', '--hard', 'HEAD~1' ) );

			file_put_contents(
				$clone_dir . '/post/rejected-published-future-date.md',
				"---\nstatus: \"publish\"\ndate: \"2099-01-01T00:00:00Z\"\ntitle: \"Rejected Published Future Date\"\n---\n\nThis push must be rejected.\n"
			);
			$this->run_cmd( array( 'git', '-C', $clone_dir, 'add', 'post/rejected-published-future-date.md' ) );
			$this->run_cmd( array( 'git', '-C', $clone_dir, 'commit', '-m', 'Reject published post with future date' ) );
			$push_result = $this->run_cmd(
				array( 'git', '-C', $clone_dir, 'push', 'origin', 'trunk' ),
				true
			);
			$this->assertNotSame( 0, $push_result['code'], 'Published posts with future dates should have been rejected.' );
			$this->assertStringContainsString( 'Push rejected because published posts must not include a future date.', $push_result['output'] );
			$this->run_cmd( array( 'git', '-C', $clone_dir, 'reset', '--hard', 'HEAD~1' ) );

			file_put_contents(
				$clone_dir . '/post/rejected-nul-byte.md',
				"---\nstatus: \"publish\"\ntitle: \"Rejected NUL Byte\"\n---\n\nBefore " . "\0" . " after.\n"
		);
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'add', 'post/rejected-nul-byte.md' ) );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'commit', '-m', 'Reject NUL byte content' ) );
		$push_result = $this->run_cmd(
			array( 'git', '-C', $clone_dir, 'push', 'origin', 'trunk' ),
			true
		);
		$this->assertNotSame( 0, $push_result['code'], 'NUL byte content should have been rejected.' );
		$this->assertStringContainsString( 'Push rejected because content files must not contain NUL bytes.', $push_result['output'] );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'reset', '--hard', 'HEAD~1' ) );

		file_put_contents(
			$clone_dir . '/post/rejected-array-title.md',
			"---\nstatus: \"publish\"\ntitle:\n  - \"Array Title\"\n---\n\nThis push must be rejected.\n"
		);
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'add', 'post/rejected-array-title.md' ) );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'commit', '-m', 'Reject array front matter title' ) );
		$push_result = $this->run_cmd(
			array( 'git', '-C', $clone_dir, 'push', 'origin', 'trunk' ),
			true
		);
		$this->assertNotSame( 0, $push_result['code'], 'Array title front matter should have been rejected.' );
		$this->assertStringContainsString( 'Push rejected because Markdown front matter field "title" must be a scalar string or number.', $push_result['output'] );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'reset', '--hard', 'HEAD~1' ) );

		file_put_contents(
			$clone_dir . '/post/rejected-unknown-frontmatter.md',
			"---\nstatus: \"publish\"\nauthor: \"admin\"\ntitle: \"Rejected Unknown Front Matter\"\n---\n\nThis push must be rejected.\n"
		);
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'add', 'post/rejected-unknown-frontmatter.md' ) );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'commit', '-m', 'Reject unknown front matter' ) );
		$push_result = $this->run_cmd(
			array( 'git', '-C', $clone_dir, 'push', 'origin', 'trunk' ),
			true
		);
		$this->assertNotSame( 0, $push_result['code'], 'Unknown front matter should have been rejected.' );
		$this->assertStringContainsString( 'Push rejected because Markdown front matter field "author" is not supported.', $push_result['output'] );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'reset', '--hard', 'HEAD~1' ) );

		file_put_contents(
			$clone_dir . '/post/rejected-block-markup.md',
			"---\nstatus: \"publish\"\ntitle: \"Rejected Block Markup\"\n---\n\n<!-- wp:group {bad json} -->\nBroken block markup.\n<!-- /wp:group -->\n"
		);
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'add', 'post/rejected-block-markup.md' ) );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'commit', '-m', 'Reject malformed block markup' ) );
		$push_result = $this->run_cmd(
			array( 'git', '-C', $clone_dir, 'push', 'origin', 'trunk' ),
			true
		);
		$this->assertNotSame( 0, $push_result['code'], 'Malformed block markup should have been rejected.' );
		$this->assertStringContainsString( 'Push rejected because the content contains malformed Gutenberg block', $push_result['output'] );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'reset', '--hard', 'HEAD~1' ) );

		file_put_contents(
			$clone_dir . '/post/rejected-mismatched-blocks.md',
			"---\nstatus: \"publish\"\ntitle: \"Rejected Mismatched Blocks\"\n---\n\n<!-- wp:paragraph -->\n<p>Broken block markup.</p>\n<!-- /wp:group -->\n"
		);
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'add', 'post/rejected-mismatched-blocks.md' ) );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'commit', '-m', 'Reject mismatched block markup' ) );
		$push_result = $this->run_cmd(
			array( 'git', '-C', $clone_dir, 'push', 'origin', 'trunk' ),
			true
		);
		$this->assertNotSame( 0, $push_result['code'], 'Mismatched block delimiters should have been rejected.' );
		$this->assertStringContainsString( 'Push rejected because the content contains mismatched Gutenberg block delimiters.', $push_result['output'] );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'reset', '--hard', 'HEAD~1' ) );

		file_put_contents(
			$clone_dir . '/post/rejected-raw-block-in-markdown.md',
			"---\nstatus: \"publish\"\ntitle: \"Rejected Raw Block In Markdown\"\n---\n\n<!-- wp:acme/custom-block-2 {\"flag\":true} /-->\n"
		);
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'add', 'post/rejected-raw-block-in-markdown.md' ) );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'commit', '-m', 'Reject raw block in Markdown' ) );
		$push_result = $this->run_cmd(
			array( 'git', '-C', $clone_dir, 'push', 'origin', 'trunk' ),
			true
		);
		$this->assertNotSame( 0, $push_result['code'], 'Raw block delimiters in Markdown should have been rejected.' );
		$this->assertStringContainsString( 'Push rejected because Markdown content must not embed raw Gutenberg block delimiters inside HTML blocks.', $push_result['output'] );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'reset', '--hard', 'HEAD~1' ) );

		file_put_contents(
			$clone_dir . '/wp_template/custom-acme-block.html',
			'<!-- wp:acme/custom-block-2 {"flag":true} /-->'
		);
		$this->commit_and_push( $clone_dir, 'wp_template/custom-acme-block.html', 'Accept custom block markup' );

		file_put_contents(
			$clone_dir . '/post/delete-restore-e2e.md',
			"---\nstatus: \"publish\"\ntitle: \"Delete Restore E2E\"\n---\n\nRestore the same WordPress post.\n"
		);
		$this->commit_and_push( $clone_dir, 'post/delete-restore-e2e.md', 'Create delete restore post' );
		$restore_id = $this->fetch_id_by_slug( 'delete-restore-e2e', 'posts' );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'pull', '--rebase', 'origin', 'trunk' ) );
		unlink( $clone_dir . '/post/delete-restore-e2e.md' );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'add', '-A' ) );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'commit', '-m', 'Trash delete restore post' ) );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'push', 'origin', 'trunk' ) );
		$this->assertSame( 'trash', $this->fetch_status( $restore_id, 'posts' ) );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'pull', '--rebase', 'origin', 'trunk' ) );
		file_put_contents(
			$clone_dir . '/post/delete-restore-e2e.md',
			"---\nstatus: \"publish\"\ntitle: \"Delete Restore E2E\"\n---\n\nRestored through Git.\n"
		);
		$this->commit_and_push( $clone_dir, 'post/delete-restore-e2e.md', 'Restore delete restore post' );
		$this->assertSame( $restore_id, $this->fetch_id_by_slug( 'delete-restore-e2e', 'posts' ) );
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'pull', '--rebase', 'origin', 'trunk' ) );

		// 9) Stale push: an out-of-date local commit must be rejected.
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

		// 10) Persistence proof: a brand-new clone (no shared state with
		// $clone_dir) must see every commit and file from above.
		$fresh = $this->clone_repo( 'fresh' );
		$this->assertFileExists( $fresh . '/post/hello-world.md' );
		$this->assertFileExists( $fresh . '/post/created-from-git.md' );
		$this->assertFileExists( $fresh . '/wp_template/blog-home.html' );
		$this->assertFileExists( $fresh . '/wp_template/custom-blog-card.html' );
		$this->assertFileExists( $fresh . '/wp_template/custom-acme-block.html' );
		$this->assertFileDoesNotExist( $fresh . '/page/sample-page.md' );
		$this->assertFileDoesNotExist( $fresh . '/page/renamed-sample-page.md' );
		$this->assertFileDoesNotExist( $fresh . '/wp_template/renamed-blog-card.html' );
		$this->assertFileExists( $fresh . '/post/delete-restore-e2e.md' );
		$this->assertStringContainsString(
			'Template updated from Git',
			file_get_contents( $fresh . '/wp_template/blog-home.html' )
		);
		$this->assertStringContainsString(
			'Created template from Git',
			file_get_contents( $fresh . '/wp_template/custom-blog-card.html' )
		);
		$this->assertStringContainsString(
			'wp:acme/custom-block-2',
			file_get_contents( $fresh . '/wp_template/custom-acme-block.html' )
		);
		$log = $this->run_cmd( array( 'git', '-C', $fresh, 'log', '--format=%s' ) );
		$this->assertStringContainsString( 'Update hello world from Git', $log['output'] );
		$this->assertStringContainsString( 'Rename page from Git', $log['output'] );
		$this->assertStringContainsString( 'Update template HTML from Git', $log['output'] );
		$this->assertStringContainsString( 'Create and delete content from Git', $log['output'] );

		// 11) The CPT-based persistence model is gone.
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

	private function assert_slug_absent( $slug, $endpoint ) {
		$url = $this->base_url . '/wp-json/wp/v2/' . $endpoint
			. '?slug=' . rawurlencode( $slug ) . '&context=edit';
		$body  = $this->curl_get( $url );
		$items = json_decode( $body, true );
		$this->assertIsArray( $items, "Unexpected REST response for $endpoint?slug=$slug: $body" );
		$this->assertSame( array(), $items, "Unexpected $endpoint match for slug $slug" );
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

	private function fetch_rest_item( $id, $endpoint ) {
		$body = $this->curl_get( $this->base_url . '/wp-json/wp/v2/' . $endpoint . '/' . $id . '?context=edit' );
		$item = json_decode( $body, true );
		$this->assertIsArray( $item, "Unexpected REST response for $endpoint/$id: $body" );

		return $item;
	}

	private function create_page_via_rest( array $payload ) {
		$ch = curl_init( $this->base_url . '/wp-json/wp/v2/pages?context=edit' );
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
		$this->assertTrue( 200 === $status || 201 === $status, "REST page create failed: $response" );

		$page = json_decode( $response, true );
		$this->assertIsArray( $page, "Unexpected REST page create response: $response" );
		$this->assertArrayHasKey( 'id', $page, "REST page create response had no ID: $response" );

		return intval( $page['id'] );
	}

	private function update_page_via_rest( $id, array $payload ) {
		$ch = curl_init( $this->base_url . '/wp-json/wp/v2/pages/' . $id . '?context=edit' );
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
		$this->assertSame( 200, $status, "REST page update failed: $response" );
	}

	private function delete_page_via_rest( $id ) {
		$ch = curl_init( $this->base_url . '/wp-json/wp/v2/pages/' . $id . '?context=edit' );
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_CUSTOMREQUEST  => 'DELETE',
				CURLOPT_HTTPHEADER     => array( $this->auth_header ),
			)
		);
		$response = curl_exec( $ch );
		$status   = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );
		$this->assertSame( 200, $status, "REST page delete failed: $response" );
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
