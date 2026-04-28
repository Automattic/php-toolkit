<?php

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
			'WP Origin',
			'WP Origin',
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
		// Each poll from the admin page also drives the seeder forward
		// by one tick. WP-Cron only fires when something hits the site,
		// so on a brand-new install — where no visitor has shown up yet
		// — the cron event would otherwise sit idle. The transient lock
		// inside tick() makes this safe to call on every poll.
		if ( ! WP_Origin_Seeder::is_ready() ) {
			WP_Origin_Seeder::tick();
		}

		return rest_ensure_response( WP_Origin_Seeder::get_progress() );
	}

	public static function rest_retry() {
		WP_Origin_Seeder::reset();
		WP_Origin_Seeder::on_activation();
		WP_Origin_Seeder::tick();

		return rest_ensure_response( WP_Origin_Seeder::get_progress() );
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// Drive the seeder forward before painting the page so the user
		// never lands on "Queued. Waiting for the first cron tick." —
		// by the time they see the progress bar it's already moving.
		if ( ! WP_Origin_Seeder::is_ready() ) {
			WP_Origin_Seeder::tick();
		}
		$progress   = WP_Origin_Seeder::get_progress();
		$nonce      = wp_create_nonce( 'wp_rest' );
		$status_url = esc_url_raw( rest_url( self::REST_NAMESPACE . self::STATUS_ROUTE ) );
		$retry_url  = esc_url_raw( rest_url( self::REST_NAMESPACE . self::RETRY_ROUTE ) );
		?>
		<div class="wrap" id="wp-origin-admin">
			<h1>WP Origin</h1>
			<p>Status of the initial Markdown import. Once this finishes you can clone, push, and pull the site as a Git repository.</p>

			<table class="widefat striped" style="max-width: 720px;">
				<tbody>
					<tr><th scope="row">State</th><td><code id="wp-origin-state"><?php echo esc_html( $progress['state'] ); ?></code></td></tr>
					<tr>
						<th scope="row">Progress</th>
						<td>
							<div style="background:#eee;border:1px solid #ccd0d4;height:18px;width:100%;border-radius:3px;overflow:hidden;">
								<div id="wp-origin-bar" style="background:#2271b1;height:100%;width:<?php echo intval( $progress['percent'] ); ?>%;transition:width 0.4s;"></div>
							</div>
							<p><span id="wp-origin-percent"><?php echo intval( $progress['percent'] ); ?></span>%
								— <span id="wp-origin-counts"><?php echo intval( $progress['processed'] ); ?> / <?php echo intval( $progress['total'] ); ?></span> posts</p>
						</td>
					</tr>
					<tr><th scope="row">Last update</th><td id="wp-origin-message"><?php echo esc_html( $progress['message'] ); ?></td></tr>
				</tbody>
			</table>

			<p>
				<button type="button" class="button" id="wp-origin-retry">Retry import</button>
			</p>
		</div>

		<script>
		(function () {
			var nonce = <?php echo wp_json_encode( $nonce ); ?>;
			var statusUrl = <?php echo wp_json_encode( $status_url ); ?>;
			var retryUrl = <?php echo wp_json_encode( $retry_url ); ?>;
			var stateEl = document.getElementById('wp-origin-state');
			var barEl = document.getElementById('wp-origin-bar');
			var percentEl = document.getElementById('wp-origin-percent');
			var countsEl = document.getElementById('wp-origin-counts');
			var messageEl = document.getElementById('wp-origin-message');

			function render(data) {
				stateEl.textContent = data.state;
				barEl.style.width = data.percent + '%';
				percentEl.textContent = data.percent;
				countsEl.textContent = data.processed + ' / ' + data.total;
				messageEl.textContent = data.message;
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
