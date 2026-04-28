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

	public function testFullRoundTrip() {
		$clone_dir = $this->clone_repo( 'clone' );

		$this->assertFileExists( $clone_dir . '/post/hello-world.md' );
		$this->assertFileExists( $clone_dir . '/page/sample-page.md' );
		$this->assertStringContainsString(
			'Hello from WordPress',
			file_get_contents( $clone_dir . '/post/hello-world.md' )
		);

		// Capture the page ID up front: WordPress mangles the slug to
		// `sample-page__trashed` once we trash the page in step 3, so a
		// later slug lookup would not find it.
		$sample_page_id = $this->fetch_id_by_slug( 'sample-page', 'pages' );

		$this->configure_git( $clone_dir );

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

		// 3) Create a new post and delete an existing page in one push.
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

		// 4) Stale push: an out-of-date local commit must be rejected.
		$this->run_cmd( array( 'git', '-C', $clone_dir, 'pull', '--rebase', 'origin', 'trunk' ) );
		$this->edit_file(
			$clone_dir . '/post/hello-world.md',
			'Updated from Git',
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

		// 5) Persistence proof: a brand-new clone (no shared state with
		// $clone_dir) must see every commit and file from above.
		$fresh = $this->clone_repo( 'fresh' );
		$this->assertFileExists( $fresh . '/post/hello-world.md' );
		$this->assertFileExists( $fresh . '/post/created-from-git.md' );
		$this->assertFileDoesNotExist( $fresh . '/page/sample-page.md' );
		$log = $this->run_cmd( array( 'git', '-C', $fresh, 'log', '--format=%s' ) );
		$this->assertStringContainsString( 'Update hello world from Git', $log['output'] );
		$this->assertStringContainsString( 'Create and delete content from Git', $log['output'] );

		// 6) The CPT-based persistence model is gone.
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
