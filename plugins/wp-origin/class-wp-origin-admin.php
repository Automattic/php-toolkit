<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI for WP Origin: a Tools → "WP Origin" page that shows the
 * seeder's progress with a live progress bar, plus a small REST
 * endpoint the page polls for status. The endpoint also doubles as a
 * way for tests to wait for `state === done` programmatically.
 */
class WP_Origin_Admin {

	const PAGE_SLUG      = 'wp-origin';
	const REST_NAMESPACE = 'wp-origin/v1';
	const STATUS_ROUTE   = '/seed-status';
	const RETRY_ROUTE    = '/seed-retry';

	public static function bootstrap() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
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
		// Each poll drives the seeder for up to ~1.5 seconds before
		// returning. With the demo's 0-second batch budget that's many
		// batches per poll, so the progress bar always reflects fresh
		// work — instead of stalling for one tick per 2-second JS
		// interval. The transient lock inside tick() makes repeated
		// calls safe.
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
		// Drive the seeder forward for ~1.5s before painting the page,
		// so the user never lands on "Queued. Waiting for the first
		// cron tick." — by the time they see the progress bar it's
		// already moving and several commits exist.
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
		?>
		<div class="wrap" id="wp-origin-admin">
			<style>
				#wp-origin-admin .wp-origin-wide-table {
					max-width: none;
					width: 100%;
				}

				#wp-origin-admin .wp-origin-wide-table th {
					width: 220px;
				}

				#wp-origin-admin .wp-origin-copy-field {
					align-items: center;
					display: flex;
					gap: 8px;
					width: 100%;
				}

				#wp-origin-admin .wp-origin-copy-field input {
					flex: 1;
					max-width: none;
					min-width: 0;
					width: 100%;
				}

				#wp-origin-admin .wp-origin-copy {
					align-items: center;
					display: inline-flex;
					flex: 0 0 auto;
					height: 30px;
					justify-content: center;
					min-width: 34px;
					padding: 0;
					width: 34px;
				}

				#wp-origin-admin .wp-origin-copy .dashicons {
					font-size: 18px;
					height: 18px;
					line-height: 18px;
					width: 18px;
				}

				#wp-origin-admin #wp-origin-commits {
					margin-left: 0;
					width: 100%;
				}
			</style>
			<h1><?php esc_html_e( 'WP Origin', 'wp-origin' ); ?></h1>
			<p><?php esc_html_e( 'Clone, push, and pull the site as a Git repository.', 'wp-origin' ); ?></p>

			<h2><?php esc_html_e( 'Repository setup', 'wp-origin' ); ?></h2>
			<p><?php esc_html_e( 'Use this Git remote with your WordPress username. When Git asks for a password, use an Application Password.', 'wp-origin' ); ?></p>
			<p>
				<a href="<?php echo esc_url( admin_url( 'profile.php#application-passwords-section' ) ); ?>"><?php esc_html_e( 'Create or manage Application Passwords', 'wp-origin' ); ?></a>
			</p>

			<table class="widefat striped wp-origin-wide-table">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Remote URL', 'wp-origin' ); ?></th>
						<td>
							<div class="wp-origin-copy-field">
								<input type="text" class="regular-text code" id="wp-origin-remote-url" readonly value="<?php echo esc_attr( $git_url ); ?>">
								<button type="button" class="button wp-origin-copy" data-copy-target="wp-origin-remote-url" aria-label="<?php echo esc_attr( $copy_remote_url_label ); ?>" title="<?php echo esc_attr( $copy_remote_url_label ); ?>">
									<span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
								</button>
							</div>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Branch', 'wp-origin' ); ?></th>
						<td>
							<div class="wp-origin-copy-field">
								<input type="text" class="regular-text code" id="wp-origin-branch" readonly value="<?php echo esc_attr( WP_Origin_Plugin::DEFAULT_BRANCH ); ?>">
							</div>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Clone a new checkout', 'wp-origin' ); ?></th>
						<td>
							<div class="wp-origin-copy-field">
								<input type="text" class="regular-text code" id="wp-origin-clone-command" readonly value="<?php echo esc_attr( $clone_command ); ?>">
								<button type="button" class="button wp-origin-copy" data-copy-target="wp-origin-clone-command" aria-label="<?php echo esc_attr( $copy_clone_label ); ?>" title="<?php echo esc_attr( $copy_clone_label ); ?>">
									<span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
								</button>
							</div>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Connect an existing repo', 'wp-origin' ); ?></th>
						<td>
							<div class="wp-origin-copy-field">
								<input type="text" class="regular-text code" id="wp-origin-existing-repo-command" readonly value="<?php echo esc_attr( $existing_repo_command ); ?>">
								<button type="button" class="button wp-origin-copy" data-copy-target="wp-origin-existing-repo-command" aria-label="<?php echo esc_attr( $copy_setup_label ); ?>" title="<?php echo esc_attr( $copy_setup_label ); ?>">
									<span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
								</button>
							</div>
						</td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Recent commits', 'wp-origin' ); ?></h2>
			<ul id="wp-origin-commits">
				<?php if ( empty( $progress['commits'] ) ) : ?>
					<li><?php esc_html_e( 'No commits yet.', 'wp-origin' ); ?></li>
				<?php else : ?>
					<?php foreach ( $progress['commits'] as $commit ) : ?>
						<li><code><?php echo esc_html( $commit['oid'] ); ?></code> <?php echo esc_html( $commit['subject'] ); ?></li>
					<?php endforeach; ?>
				<?php endif; ?>
			</ul>

			<h2><?php esc_html_e( 'Import status', 'wp-origin' ); ?></h2>
			<p><?php esc_html_e( 'Status of the initial Markdown import.', 'wp-origin' ); ?></p>

			<table class="widefat striped wp-origin-wide-table">
				<tbody>
					<tr><th scope="row"><?php esc_html_e( 'State', 'wp-origin' ); ?></th><td><code id="wp-origin-state"><?php echo esc_html( $progress['state'] ); ?></code></td></tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Progress', 'wp-origin' ); ?></th>
						<td>
							<div style="background:#eee;border:1px solid #ccd0d4;height:18px;width:100%;border-radius:3px;overflow:hidden;">
								<div id="wp-origin-bar" style="background:#2271b1;height:100%;width:<?php echo intval( $progress['percent'] ); ?>%;transition:width 0.4s;"></div>
							</div>
							<p><span id="wp-origin-percent"><?php echo intval( $progress['percent'] ); ?></span>%
								— <span id="wp-origin-counts"><?php echo intval( $progress['processed'] ); ?> / <?php echo intval( $progress['total'] ); ?> <?php echo esc_html( _n( 'post', 'posts', intval( $progress['total'] ), 'wp-origin' ) ); ?></span></p>
						</td>
					</tr>
					<tr><th scope="row"><?php esc_html_e( 'Last update', 'wp-origin' ); ?></th><td id="wp-origin-message"><?php echo esc_html( $progress['message'] ); ?></td></tr>
				</tbody>
			</table>

			<p>
				<button type="button" class="button" id="wp-origin-retry"><?php esc_html_e( 'Retry import', 'wp-origin' ); ?></button>
			</p>
		</div>

		<script>
		(function () {
			var nonce = <?php echo wp_json_encode( $nonce ); ?>;
			var statusUrl = <?php echo wp_json_encode( $status_url ); ?>;
			var retryUrl = <?php echo wp_json_encode( $retry_url ); ?>;
			var noCommitsText = <?php echo wp_json_encode( __( 'No commits yet.', 'wp-origin' ) ); ?>;
			var copiedText = <?php echo wp_json_encode( __( 'Copied', 'wp-origin' ) ); ?>;
			var postText = <?php echo wp_json_encode( __( 'post', 'wp-origin' ) ); ?>;
			var postsText = <?php echo wp_json_encode( __( 'posts', 'wp-origin' ) ); ?>;
			var stateEl = document.getElementById('wp-origin-state');
			var barEl = document.getElementById('wp-origin-bar');
			var percentEl = document.getElementById('wp-origin-percent');
			var countsEl = document.getElementById('wp-origin-counts');
			var messageEl = document.getElementById('wp-origin-message');
			var commitsEl = document.getElementById('wp-origin-commits');

			function render(data) {
				stateEl.textContent = data.state;
				barEl.style.width = data.percent + '%';
				percentEl.textContent = data.percent;
				countsEl.textContent = data.processed + ' / ' + data.total + ' ' + (parseInt(data.total, 10) === 1 ? postText : postsText);
				messageEl.textContent = data.message;
				renderCommits(data.commits || []);
			}

			function renderCommits(commits) {
				var index;
				var item;
				var oid;

				commitsEl.textContent = '';
				if (!commits.length) {
					item = document.createElement('li');
					item.textContent = noCommitsText;
					commitsEl.appendChild(item);
					return;
				}

				for (index = 0; index < commits.length; index++) {
					item = document.createElement('li');
					oid = document.createElement('code');
					oid.textContent = commits[index].oid;
					item.appendChild(oid);
					item.appendChild(document.createTextNode(' ' + commits[index].subject));
					commitsEl.appendChild(item);
				}
			}

			function getCopyText(targetId) {
				var target = document.getElementById(targetId);
				if (!target) {
					return '';
				}

				return 'value' in target ? target.value : target.textContent;
			}

			function fallbackCopyText(text) {
				var textarea;

				textarea = document.createElement('textarea');
				textarea.value = text;
				textarea.setAttribute('readonly', 'readonly');
				textarea.style.position = 'fixed';
				textarea.style.top = '-1000px';
				document.body.appendChild(textarea);
				textarea.select();
				document.execCommand('copy');
				document.body.removeChild(textarea);

				return Promise.resolve();
			}

			function copyText(text) {
				if (navigator.clipboard && navigator.clipboard.writeText) {
					return navigator.clipboard.writeText(text).catch(function () {
						return fallbackCopyText(text);
					});
				}

				return fallbackCopyText(text);
			}

			function poll() {
				fetch(statusUrl, { credentials: 'same-origin', headers: { 'X-WP-Nonce': nonce } })
					.then(function (r) { return r.json(); })
					.then(function (data) {
						render(data);
						if (data.state !== 'done' && data.state !== 'failed') {
							setTimeout(poll, 2000);
						}
					})
					.catch(function () { setTimeout(poll, 5000); });
			}

			Array.prototype.forEach.call(document.querySelectorAll('.wp-origin-copy'), function (button) {
				button.addEventListener('click', function () {
					var icon = button.querySelector('.dashicons');
					var originalIconClass = icon ? icon.className : '';
					var originalLabel = button.getAttribute('aria-label');
					var originalTitle = button.getAttribute('title');
					copyText(getCopyText(button.getAttribute('data-copy-target'))).then(function () {
						if (icon) {
							icon.className = 'dashicons dashicons-yes';
						}
						button.setAttribute('aria-label', copiedText);
						button.setAttribute('title', copiedText);
						setTimeout(function () {
							if (icon) {
								icon.className = originalIconClass;
							}
							button.setAttribute('aria-label', originalLabel);
							button.setAttribute('title', originalTitle);
						}, 1500);
					});
				});
			});

			document.getElementById('wp-origin-retry').addEventListener('click', function () {
				fetch(retryUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': nonce }
				}).then(function () { setTimeout(poll, 1000); });
			});

			poll();
		}());
		</script>
		<?php
	}
}
