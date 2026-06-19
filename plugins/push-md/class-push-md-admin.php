<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI for Push MD.
 */
class Push_MD_Admin {

	const PAGE_SLUG            = 'push-md';
	const REST_NAMESPACE       = 'push-md/v1';
	const STATUS_ROUTE         = '/seed-status';
	const RETRY_ROUTE          = '/seed-retry';
	const ASSET_VERSION        = '0.6.7';
	const COLLISIONS_TRANSIENT = 'push_md_collisions';

	public static function bootstrap() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
	}

	public static function register_menu() {
		add_management_page(
			__( 'Push MD', 'push-md' ),
			__( 'Push MD', 'push-md' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function enqueue_assets( $hook_suffix ) {
		if ( 'tools_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		$plugin_file = defined( 'PUSH_MD_PLUGIN_FILE' ) ? PUSH_MD_PLUGIN_FILE : __FILE__;
		wp_enqueue_style(
			'push-md-admin-shell',
			plugins_url( 'admin-shell.css', $plugin_file ),
			array(),
			self::ASSET_VERSION
		);
		wp_enqueue_script(
			'push-md-admin-shell',
			plugins_url( 'admin-shell.js', $plugin_file ),
			array( 'wp-i18n' ),
			self::ASSET_VERSION,
			true
		);
		wp_set_script_translations( 'push-md-admin-shell', 'push-md' );
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
		Push_MD_Seeder::drive( 1.5 );

		$progress               = Push_MD_Seeder::get_progress();
		$progress['collisions'] = self::collisions_for_display();

		return rest_ensure_response( $progress );
	}

	public static function rest_retry() {
		Push_MD_Seeder::reset();
		Push_MD_Seeder::on_activation();
		Push_MD_Seeder::tick();
		delete_transient( self::COLLISIONS_TRANSIENT );

		$progress               = Push_MD_Seeder::get_progress();
		$progress['collisions'] = self::collisions_for_display();

		return rest_ensure_response( $progress );
	}

	/**
	 * Build the collision report consumed by the admin page and status route.
	 *
	 * Detection mirrors the export query and can scan every supported post, so
	 * the result is cached briefly because the status route is polled.
	 *
	 * @return array List of array( 'path' => string, 'posts' => array ) where
	 *               each post carries id, title, status, and an edit URL.
	 */
	private static function collisions_for_display() {
		$cached = get_transient( self::COLLISIONS_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$payload = array();
		foreach ( Push_MD_Plugin::detect_export_path_collisions() as $collision ) {
			$posts = array();
			foreach ( $collision['post_ids'] as $post_id ) {
				$post = get_post( $post_id );
				if ( ! $post ) {
					continue;
				}

				$edit_url = get_edit_post_link( $post_id, 'raw' );
				$posts[]  = array(
					'id'       => intval( $post_id ),
					'title'    => $post->post_title,
					'status'   => $post->post_status,
					'edit_url' => $edit_url ? $edit_url : '',
				);
			}

			$payload[] = array(
				'path'  => $collision['path'],
				'posts' => $posts,
			);
		}

		set_transient( self::COLLISIONS_TRANSIENT, $payload, 30 );

		return $payload;
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
		Push_MD_Seeder::drive( 1.5 );
		$progress               = Push_MD_Seeder::get_progress();
		$collisions             = self::collisions_for_display();
		$progress['collisions'] = $collisions;
		$nonce                  = wp_create_nonce( 'wp_rest' );
		$status_url             = esc_url_raw( rest_url( self::REST_NAMESPACE . self::STATUS_ROUTE ) );
		$retry_url              = esc_url_raw( rest_url( self::REST_NAMESPACE . self::RETRY_ROUTE ) );
		$git_url                = esc_url_raw( rest_url( Push_MD_Plugin::ROUTE_NAMESPACE . '/md.git' ) );
		$user                   = wp_get_current_user();
		if ( $user && $user->exists() ) {
			$git_url = self::add_username_to_url( $git_url, $user->user_login );
		}
		$site_slug = sanitize_title( get_bloginfo( 'name' ) );
		if ( '' === $site_slug ) {
			$site_slug = 'push-md-site';
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
		$copy_remote_url_label = __( 'Copy remote URL', 'push-md' );
		$copy_clone_label      = __( 'Copy clone command', 'push-md' );
		$copy_branch_label     = __( 'Copy branch', 'push-md' );
		$copy_setup_label      = __( 'Copy setup command', 'push-md' );
		$copy_status_label     = __( 'Copy import status API URL', 'push-md' );
		$copy_button_label     = __( 'Copy', 'push-md' );
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
		wp_add_inline_script(
			'push-md-admin-shell',
			'window.pushMdAdminShell = ' . wp_json_encode( $config ) . ';',
			'before'
		);
		?>
		<div class="wrap push-md-shell-page" id="push-md-admin">
			<div class="push-md-shell-frame">
				<div class="push-md-shell-header">
					<div class="push-md-shell-title">
						<h1><?php esc_html_e( 'Push MD', 'push-md' ); ?></h1>
						<p>
							<?php
							printf(
								/* translators: %s: Site name. */
								esc_html__( '%s as a Git checkout, live from WordPress.', 'push-md' ),
								esc_html( $site_name )
							);
							?>
						</p>
					</div>
					<div class="push-md-state-pill" id="push-md-state-pill">
						<span class="push-md-state-dot" aria-hidden="true"></span>
						<span id="push-md-state"><?php echo esc_html( $progress['state'] ); ?></span>
					</div>
				</div>

				<div class="push-md-panel push-md-collisions-panel" id="push-md-collisions-panel"<?php echo empty( $collisions ) ? ' hidden' : ''; ?>>
					<h2><?php esc_html_e( 'Path collisions', 'push-md' ); ?></h2>
					<p><?php esc_html_e( 'Two or more items map to the same file path, which blocks Push MD from exporting until you resolve it. Change a slug or trash a duplicate, then pull again.', 'push-md' ); ?></p>
					<ul class="push-md-collisions-list" id="push-md-collisions-list"></ul>
				</div>

				<div class="push-md-emulator-note">
					<strong><?php esc_html_e( 'Command emulator.', 'push-md' ); ?></strong>
					<?php esc_html_e( 'This terminal is a guided preview of commands you can run in your real terminal after cloning Push MD. It does not execute a server shell or write changes to the site.', 'push-md' ); ?>
				</div>

				<div class="push-md-terminal" role="application" aria-label="<?php esc_attr_e( 'Push MD command emulator', 'push-md' ); ?>">
					<div class="push-md-terminal-bar">
						<div class="push-md-terminal-controls" aria-hidden="true">
							<span class="push-md-terminal-dot"></span>
							<span class="push-md-terminal-dot"></span>
							<span class="push-md-terminal-dot"></span>
						</div>
						<div id="push-md-terminal-title">
							<?php
							printf(
								/* translators: %s: Checkout directory name. */
								esc_html__( 'emulator:~/%s', 'push-md' ),
								esc_html( $site_slug )
							);
							?>
						</div>
						<div><?php echo esc_html( $host ); ?></div>
					</div>
					<div class="push-md-terminal-body">
						<div class="push-md-terminal-output" id="push-md-terminal-output" aria-live="polite"></div>
						<label class="push-md-terminal-input-row" for="push-md-terminal-input">
							<span class="push-md-prompt-chip">
								<span class="push-md-prompt-user">push-md</span>
								<span class="push-md-prompt-mark">:</span>
								<span class="push-md-prompt-path" id="push-md-prompt-cwd">~/<?php echo esc_html( $site_slug ); ?></span>
								<span class="push-md-prompt-mark">$</span>
							</span>
							<input class="push-md-terminal-input" id="push-md-terminal-input" type="text" autocomplete="off" spellcheck="false" placeholder="<?php esc_attr_e( 'Type an emulated command', 'push-md' ); ?>" />
						</label>
					</div>
				</div>

				<div class="push-md-panel push-md-copy-panel">
					<h2><?php esc_html_e( 'Use In Your Terminal', 'push-md' ); ?></h2>
					<p><?php esc_html_e( 'Use this Git remote with your WordPress username. When Git asks for a password, use an Application Password.', 'push-md' ); ?></p>
					<p>
						<a href="<?php echo esc_url( admin_url( 'profile.php#application-passwords-section' ) ); ?>"><?php esc_html_e( 'Create or manage Application Passwords', 'push-md' ); ?></a>
					</p>
					<table class="widefat striped push-md-copy-table">
						<tbody>
							<tr>
								<th scope="row"><?php esc_html_e( 'Remote URL', 'push-md' ); ?></th>
								<td><code><?php echo esc_html( $git_url ); ?></code></td>
								<td><button type="button" class="button push-md-copy-button" data-copy-value="<?php echo esc_attr( $git_url ); ?>" aria-label="<?php echo esc_attr( $copy_remote_url_label ); ?>"><?php echo esc_html( $copy_button_label ); ?></button></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Clone command', 'push-md' ); ?></th>
								<td><code><?php echo esc_html( $clone_command ); ?></code></td>
								<td><button type="button" class="button push-md-copy-button" data-copy-value="<?php echo esc_attr( $clone_command ); ?>" aria-label="<?php echo esc_attr( $copy_clone_label ); ?>"><?php echo esc_html( $copy_button_label ); ?></button></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Branch', 'push-md' ); ?></th>
								<td><code><?php echo esc_html( Push_MD_Plugin::DEFAULT_BRANCH ); ?></code></td>
								<td><button type="button" class="button push-md-copy-button" data-copy-value="<?php echo esc_attr( Push_MD_Plugin::DEFAULT_BRANCH ); ?>" aria-label="<?php echo esc_attr( $copy_branch_label ); ?>"><?php echo esc_html( $copy_button_label ); ?></button></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Connect an existing repo', 'push-md' ); ?></th>
								<td><code><?php echo esc_html( $existing_repo_command ); ?></code></td>
								<td><button type="button" class="button push-md-copy-button" data-copy-value="<?php echo esc_attr( $existing_repo_command ); ?>" aria-label="<?php echo esc_attr( $copy_setup_label ); ?>"><?php echo esc_html( $copy_button_label ); ?></button></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Import status API', 'push-md' ); ?></th>
								<td><code><?php echo esc_html( $status_url ); ?></code></td>
								<td><button type="button" class="button push-md-copy-button" data-copy-value="<?php echo esc_attr( $status_url ); ?>" aria-label="<?php echo esc_attr( $copy_status_label ); ?>"><?php echo esc_html( $copy_button_label ); ?></button></td>
							</tr>
						</tbody>
					</table>
				</div>

				<div class="push-md-panel push-md-commit-panel">
					<h2><?php esc_html_e( 'Commit History', 'push-md' ); ?></h2>
					<ul class="push-md-commit-list" id="push-md-commit-list"></ul>
				</div>

				<div class="push-md-panel push-md-import-panel">
					<div class="push-md-import-header">
						<h2><?php esc_html_e( 'Import Status', 'push-md' ); ?></h2>
						<div class="push-md-retry-row">
							<span>
								<?php esc_html_e( 'Seed state:', 'push-md' ); ?>
								<code id="push-md-state-copy"><?php echo esc_html( $progress['state'] ); ?></code>
							</span>
							<button type="button" class="button" id="push-md-retry"><?php esc_html_e( 'Retry import', 'push-md' ); ?></button>
						</div>
					</div>
					<div class="push-md-progress-track">
						<div class="push-md-progress-bar" id="push-md-bar" style="width: <?php echo intval( $progress['percent'] ); ?>%;"></div>
					</div>
					<div class="push-md-progress-meta">
						<span><span id="push-md-percent"><?php echo intval( $progress['percent'] ); ?></span>%</span>
						<span>
							<span id="push-md-counts"><?php echo intval( $progress['processed'] ); ?> / <?php echo intval( $progress['total'] ); ?></span>
							<?php esc_html_e( 'items', 'push-md' ); ?>
						</span>
					</div>
					<p class="push-md-message" id="push-md-message"><?php echo esc_html( $progress['message'] ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}
}
