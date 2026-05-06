<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI for WP Origin.
 */
class WP_Origin_Admin {

	const PAGE_SLUG      = 'wp-origin';
	const REST_NAMESPACE = 'wp-origin/v1';
	const STATUS_ROUTE   = '/seed-status';
	const RETRY_ROUTE    = '/seed-retry';
	const ASSET_VERSION  = '0.1.0';

	public static function bootstrap() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
	}

	public static function register_menu() {
		add_management_page(
			__( 'WP Origin', 'wp-origin' ),
			__( 'WP Origin', 'wp-origin' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function enqueue_assets( $hook_suffix ) {
		if ( 'tools_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		$plugin_file = defined( 'WP_ORIGIN_PLUGIN_FILE' ) ? WP_ORIGIN_PLUGIN_FILE : __FILE__;
		wp_enqueue_style(
			'wp-origin-admin-shell',
			plugins_url( 'admin-shell.css', $plugin_file ),
			array(),
			self::ASSET_VERSION
		);
		wp_enqueue_script(
			'wp-origin-admin-shell',
			plugins_url( 'admin-shell.js', $plugin_file ),
			array(),
			self::ASSET_VERSION,
			true
		);
	}

	public static function register_rest_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::STATUS_ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_status' ),
				'permission_callback' => array( __CLASS__, 'admin_only' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			self::RETRY_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_retry' ),
				'permission_callback' => array( __CLASS__, 'admin_only' ),
			)
		);
	}

	public static function admin_only() {
		return current_user_can( 'manage_options' );
	}

	public static function rest_status() {
		WP_Origin_Seeder::drive( 1.5 );

		return rest_ensure_response( WP_Origin_Seeder::get_progress() );
	}

	public static function rest_retry() {
		WP_Origin_Seeder::reset();
		WP_Origin_Seeder::on_activation();
		WP_Origin_Seeder::tick();

		return rest_ensure_response( WP_Origin_Seeder::get_progress() );
	}

	private static function add_username_to_url( $url, $username ) {
		if ( '' === $username ) {
			return $url;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return $url;
		}

		$authority = rawurlencode( $username ) . '@' . $parts['host'];
		if ( isset( $parts['port'] ) ) {
			$authority .= ':' . intval( $parts['port'] );
		}

		$path     = isset( $parts['path'] ) ? $parts['path'] : '';
		$query    = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		$fragment = isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '';

		return $parts['scheme'] . '://' . $authority . $path . $query . $fragment;
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Drive the seeder before painting so the first screen already
		// has real checkout state whenever the host can do quick work.
		WP_Origin_Seeder::drive( 1.5 );
		$progress   = WP_Origin_Seeder::get_progress();
		$nonce      = wp_create_nonce( 'wp_rest' );
		$status_url = esc_url_raw( rest_url( self::REST_NAMESPACE . self::STATUS_ROUTE ) );
		$retry_url  = esc_url_raw( rest_url( self::REST_NAMESPACE . self::RETRY_ROUTE ) );
		$git_url    = esc_url_raw( rest_url( WP_Origin_Plugin::ROUTE_NAMESPACE . '/md.git' ) );
		$user       = wp_get_current_user();
		if ( $user && $user->exists() ) {
			$git_url = self::add_username_to_url( $git_url, $user->user_login );
		}
		$site_slug = sanitize_title( get_bloginfo( 'name' ) );
		if ( '' === $site_slug ) {
			$site_slug = 'wp-origin-site';
		}
		$clone_command         = sprintf(
			'git clone %s %s && cd %s',
			$git_url,
			$site_slug,
			$site_slug
		);
		$existing_repo_command = sprintf(
			'git remote add origin %s && git fetch origin trunk && git checkout -b trunk --track origin/trunk',
			$git_url
		);
		$copy_remote_url_label = __( 'Copy remote URL', 'wp-origin' );
		$copy_clone_label      = __( 'Copy clone command', 'wp-origin' );
		$copy_setup_label      = __( 'Copy setup command', 'wp-origin' );
		$site_name             = get_bloginfo( 'name' );
		$host                  = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! is_string( $host ) ) {
			$host = '';
		}
		$config = array(
			'nonce'           => $nonce,
			'statusUrl'       => $status_url,
			'retryUrl'        => $retry_url,
			'remoteUrl'       => $git_url,
			'cloneCommand'    => $clone_command,
			'checkoutDir'     => $site_slug,
			'initialProgress' => $progress,
		);
		?>
		<div class="wrap wp-origin-shell-page" id="wp-origin-admin">
			<div class="wp-origin-shell-frame">
				<div class="wp-origin-shell-header">
					<div class="wp-origin-shell-title">
						<h1>WP Origin</h1>
						<p><?php echo esc_html( $site_name ); ?> as a Git checkout, live from WordPress.</p>
					</div>
					<div class="wp-origin-state-pill" id="wp-origin-state-pill">
						<span class="wp-origin-state-dot" aria-hidden="true"></span>
						<span id="wp-origin-state"><?php echo esc_html( $progress['state'] ); ?></span>
					</div>
				</div>

				<div class="wp-origin-emulator-note">
					<strong>Command emulator.</strong> This terminal is a guided preview of commands you can run in your real terminal after cloning WP Origin. It does not execute a server shell or write changes to the site.
				</div>

				<div class="wp-origin-terminal" role="application" aria-label="WP Origin command emulator">
					<div class="wp-origin-terminal-bar">
						<div class="wp-origin-terminal-controls" aria-hidden="true">
							<span class="wp-origin-terminal-dot"></span>
							<span class="wp-origin-terminal-dot"></span>
							<span class="wp-origin-terminal-dot"></span>
						</div>
						<div id="wp-origin-terminal-title">emulator:~/<?php echo esc_html( $site_slug ); ?></div>
						<div><?php echo esc_html( $host ); ?></div>
					</div>
					<div class="wp-origin-terminal-body">
						<div class="wp-origin-terminal-output" id="wp-origin-terminal-output" aria-live="polite"></div>
						<label class="wp-origin-terminal-input-row" for="wp-origin-terminal-input">
							<span class="wp-origin-prompt-chip">
								<span class="wp-origin-prompt-user">wp-origin</span>
								<span class="wp-origin-prompt-mark">:</span>
								<span class="wp-origin-prompt-path" id="wp-origin-prompt-cwd">~/<?php echo esc_html( $site_slug ); ?></span>
								<span class="wp-origin-prompt-mark">$</span>
							</span>
							<input class="wp-origin-terminal-input" id="wp-origin-terminal-input" type="text" autocomplete="off" spellcheck="false" placeholder="Type an emulated command" />
						</label>
					</div>
				</div>

				<div class="wp-origin-panel wp-origin-copy-panel">
					<h2>Use In Your Terminal</h2>
					<p>Use this Git remote with your WordPress username. When Git asks for a password, use an Application Password.</p>
					<p>
						<a href="<?php echo esc_url( admin_url( 'profile.php#application-passwords-section' ) ); ?>">Create or manage Application Passwords</a>
					</p>
					<table class="widefat striped wp-origin-copy-table">
						<tbody>
							<tr>
								<th scope="row">Remote URL</th>
								<td><code><?php echo esc_html( $git_url ); ?></code></td>
								<td><button type="button" class="button wp-origin-copy-button" data-copy-value="<?php echo esc_attr( $git_url ); ?>" aria-label="<?php echo esc_attr( $copy_remote_url_label ); ?>">Copy</button></td>
							</tr>
							<tr>
								<th scope="row">Clone command</th>
								<td><code><?php echo esc_html( $clone_command ); ?></code></td>
								<td><button type="button" class="button wp-origin-copy-button" data-copy-value="<?php echo esc_attr( $clone_command ); ?>" aria-label="<?php echo esc_attr( $copy_clone_label ); ?>">Copy</button></td>
							</tr>
							<tr>
								<th scope="row">Branch</th>
								<td><code><?php echo esc_html( WP_Origin_Plugin::DEFAULT_BRANCH ); ?></code></td>
								<td><button type="button" class="button wp-origin-copy-button" data-copy-value="<?php echo esc_attr( WP_Origin_Plugin::DEFAULT_BRANCH ); ?>">Copy</button></td>
							</tr>
							<tr>
								<th scope="row">Connect an existing repo</th>
								<td><code><?php echo esc_html( $existing_repo_command ); ?></code></td>
								<td><button type="button" class="button wp-origin-copy-button" data-copy-value="<?php echo esc_attr( $existing_repo_command ); ?>" aria-label="<?php echo esc_attr( $copy_setup_label ); ?>">Copy</button></td>
							</tr>
							<tr>
								<th scope="row">Import status API</th>
								<td><code><?php echo esc_html( $status_url ); ?></code></td>
								<td><button type="button" class="button wp-origin-copy-button" data-copy-value="<?php echo esc_attr( $status_url ); ?>">Copy</button></td>
							</tr>
						</tbody>
					</table>
				</div>

				<div class="wp-origin-panel wp-origin-commit-panel">
					<h2>Commit History</h2>
					<ul class="wp-origin-commit-list" id="wp-origin-commit-list"></ul>
				</div>

				<div class="wp-origin-panel wp-origin-import-panel">
					<div class="wp-origin-import-header">
						<h2>Import Status</h2>
						<div class="wp-origin-retry-row">
							<span>Seed state: <code id="wp-origin-state-copy"><?php echo esc_html( $progress['state'] ); ?></code></span>
							<button type="button" class="button" id="wp-origin-retry">Retry import</button>
						</div>
					</div>
					<div class="wp-origin-progress-track">
						<div class="wp-origin-progress-bar" id="wp-origin-bar" style="width: <?php echo intval( $progress['percent'] ); ?>%;"></div>
					</div>
					<div class="wp-origin-progress-meta">
						<span><span id="wp-origin-percent"><?php echo intval( $progress['percent'] ); ?></span>%</span>
						<span><span id="wp-origin-counts"><?php echo intval( $progress['processed'] ); ?> / <?php echo intval( $progress['total'] ); ?></span> items</span>
					</div>
					<p class="wp-origin-message" id="wp-origin-message"><?php echo esc_html( $progress['message'] ); ?></p>
				</div>
			</div>
			<script>
			window.wpOriginAdminShell = <?php echo wp_json_encode( $config ); ?>;
			</script>
		</div>
		<?php
	}
}
