<?php
/**
 * Push MD Draft Previews class.
 *
 * Manages single-revision draft updates for published WordPress posts,
 * 7-day public preview tokens via transients, and frontend content swapping.
 *
 * @package Push_MD
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Push_MD_Draft_Previews
 *
 * Handles draft previews, temporary revision storage, and token validation.
 */
class Push_MD_Draft_Previews {

	/**
	 * Transient key prefix.
	 */
	const TRANSIENT_PREFIX = 'pushmd_preview_';

	/**
	 * Default token expiration time in seconds (7 days).
	 */
	const TOKEN_TTL = 604800; // 7 * DAY_IN_SECONDS.

	/**
	 * Active revision post ID for the current request.
	 *
	 * @var int|null
	 */
	private static $active_preview_post_id = null;

	/**
	 * Active revision content for the current request.
	 *
	 * @var string|null
	 */
	private static $active_preview_content = null;

	/**
	 * Bootstrap frontend preview hooks.
	 */
	public static function bootstrap() {
		add_action( 'template_redirect', array( __CLASS__, 'handle_template_redirect' ) );
	}

	/**
	 * Check if an incoming update is a draft push for an existing published post.
	 *
	 * @param WP_Post|null $existing_post Existing post object, if any.
	 * @param string       $incoming_status Status requested in Markdown frontmatter.
	 * @return bool True if this is a draft push for an already published post.
	 */
	public static function is_draft_preview_for_published_post( $existing_post, $incoming_status ) {
		if ( ! $existing_post instanceof WP_Post ) {
			return false;
		}

		return 'publish' === $existing_post->post_status && 'draft' === $incoming_status;
	}

	/**
	 * Create or update the single dedicated preview revision for a published post.
	 *
	 * @param int    $post_id Main published post ID.
	 * @param string $content Parsed block markup content.
	 * @return array Array containing 'revision_id' and 'is_new' boolean.
	 * @throws Exception If revision creation or update fails.
	 */
	public static function create_or_update_preview_revision( $post_id, $content ) {
		$post_id  = intval( $post_id );
		$revision = self::find_preview_revision( $post_id );

		if ( $revision ) {
			$revision_id = $revision->ID;
			$postarr     = array(
				'ID'           => $revision_id,
				'post_content' => $content,
			);
			$updated     = wp_update_post( wp_slash( $postarr ), true );
			if ( is_wp_error( $updated ) ) {
				throw new Exception( esc_html( $updated->get_error_message() ) );
			}

			return array(
				'revision_id' => $revision_id,
				'is_new'      => false,
			);
		}

		$parent_post = get_post( $post_id );
		$post_title  = $parent_post ? $parent_post->post_title : '';

		$postarr = array(
			'post_type'    => 'revision',
			'post_status'  => 'inherit',
			'post_parent'  => $post_id,
			'post_title'   => $post_title,
			'post_content' => $content,
			'post_name'    => $post_id . '-pushmd-preview',
		);

		$revision_id = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $revision_id ) ) {
			throw new Exception( esc_html( $revision_id->get_error_message() ) );
		}

		return array(
			'revision_id' => intval( $revision_id ),
			'is_new'      => true,
		);
	}

	/**
	 * Find an existing preview revision for a post ID.
	 *
	 * @param int $post_id Main post ID.
	 * @return WP_Post|null Preview revision post or null if none found.
	 */
	public static function find_preview_revision( $post_id ) {
		$post_id = intval( $post_id );
		$data    = get_transient( self::TRANSIENT_PREFIX . $post_id );
		if ( is_array( $data ) && ! empty( $data['revision_id'] ) ) {
			$revision = get_post( intval( $data['revision_id'] ) );
			if ( $revision instanceof WP_Post && 'revision' === $revision->post_type ) {
				return $revision;
			}
		}

		$revisions = get_posts(
			array(
				'post_type'      => 'revision',
				'post_parent'    => $post_id,
				'name'           => $post_id . '-pushmd-preview',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'orderby'        => 'ID',
				'order'          => 'DESC',
			)
		);

		if ( ! empty( $revisions ) && $revisions[0] instanceof WP_Post ) {
			return $revisions[0];
		}

		return null;
	}

	/**
	 * Generate a new preview token or reuse existing token and refresh transient.
	 *
	 * @param int $post_id Main published post ID.
	 * @param int $revision_id Revision post ID.
	 * @return array Array containing 'token' and 'url'.
	 */
	public static function generate_or_refresh_preview_token( $post_id, $revision_id ) {
		$post_id       = intval( $post_id );
		$revision_id   = intval( $revision_id );
		$transient_key = self::TRANSIENT_PREFIX . $post_id;
		$existing_data = get_transient( $transient_key );

		if ( is_array( $existing_data ) && ! empty( $existing_data['token'] ) && 32 === strlen( $existing_data['token'] ) ) {
			$token = $existing_data['token'];
		} else {
			$token = self::generate_random_token();
		}

		$transient_value = array(
			'token'       => $token,
			'revision_id' => $revision_id,
		);

		$ttl = defined( 'DAY_IN_SECONDS' ) ? 7 * DAY_IN_SECONDS : self::TOKEN_TTL;
		set_transient( $transient_key, $transient_value, $ttl );

		$base_url = function_exists( 'get_permalink' ) && get_permalink( $post_id ) ? get_permalink( $post_id ) : home_url( '/?p=' . $post_id );
		$url      = add_query_arg( 'pushmd_preview', $token, $base_url );

		return array(
			'token' => $token,
			'url'   => $url,
		);
	}

	/**
	 * Verify token for a post ID.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $token Token string.
	 * @return int|false Revision ID or false if invalid/expired.
	 */
	public static function verify_token( $post_id, $token ) {
		$post_id = intval( $post_id );
		if ( ! $post_id || ! is_string( $token ) || 32 !== strlen( $token ) ) {
			return false;
		}

		$data = get_transient( self::TRANSIENT_PREFIX . $post_id );
		if ( ! is_array( $data ) || empty( $data['token'] ) || empty( $data['revision_id'] ) ) {
			return false;
		}

		if ( ! hash_equals( $data['token'], $token ) ) {
			return false;
		}

		return intval( $data['revision_id'] );
	}

	/**
	 * Clean up preview revision and transient when a post is published or deleted.
	 *
	 * @param int $post_id Main post ID.
	 */
	public static function cleanup_preview( $post_id ) {
		$post_id  = intval( $post_id );
		$revision = self::find_preview_revision( $post_id );

		delete_transient( self::TRANSIENT_PREFIX . $post_id );

		if ( $revision ) {
			wp_delete_post( $revision->ID, true );
		}
	}

	/**
	 * Generate a 32-character hex token string.
	 *
	 * @return string 32-character hex string.
	 */
	public static function generate_random_token() {
		if ( function_exists( 'random_bytes' ) ) {
			try {
				return bin2hex( random_bytes( 16 ) );
			} catch ( Exception $exception ) {
				unset( $exception );
			}
		}

		return md5( uniqid( (string) wp_rand(), true ) );
	}

	/**
	 * Handle frontend request in template_redirect to swap content for valid preview tokens.
	 */
	public static function handle_template_redirect() {
		if ( is_admin() || empty( $_GET['pushmd_preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$post_id = function_exists( 'get_queried_object_id' ) ? get_queried_object_id() : 0;
		if ( ! $post_id && isset( $_GET['p'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$post_id = intval( $_GET['p'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		if ( ! $post_id ) {
			return;
		}

		$raw_token = sanitize_text_field( wp_unslash( $_GET['pushmd_preview'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $raw_token ) {
			return;
		}

		$revision_id = self::verify_token( $post_id, $raw_token );
		if ( ! $revision_id ) {
			// Fail-closed security: invalid/stale token always redirects to clean permalink.
			// Prevents old token holders from discovering newly generated draft preview tokens.
			$clean_url = remove_query_arg( 'pushmd_preview' );
			if ( function_exists( 'wp_safe_redirect' ) ) {
				wp_safe_redirect( $clean_url );
				exit;
			}

			return;
		}

		$revision = get_post( $revision_id );
		if ( ! $revision instanceof WP_Post ) {
			return;
		}

		self::$active_preview_post_id = intval( $post_id );
		self::$active_preview_content = $revision->post_content;

		add_filter( 'the_content', array( __CLASS__, 'filter_the_content' ), PHP_INT_MAX );
		add_action( 'send_headers', array( __CLASS__, 'send_draft_preview_headers' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render_draft_preview_notice' ) );
	}

	/**
	 * Swap post content with the draft revision content.
	 *
	 * @param string $content Original post content.
	 * @return string Draft revision content.
	 */
	public static function filter_the_content( $content ) {
		if ( null !== self::$active_preview_content && null !== self::$active_preview_post_id ) {
			$current_id = function_exists( 'get_the_ID' ) ? get_the_ID() : 0;
			if ( ! $current_id || $current_id === self::$active_preview_post_id ) {
				return self::$active_preview_content;
			}
		}

		return $content;
	}

	/**
	 * Send HTTP headers for draft previews (no-cache and noindex for SEO safety).
	 */
	public static function send_draft_preview_headers() {
		if ( null === self::$active_preview_content || headers_sent() ) {
			return;
		}

		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'X-Push-MD-Draft-Preview: 1' );
	}

	/**
	 * Render floating notice banner in wp_footer for active draft preview.
	 */
	public static function render_draft_preview_notice() {
		if ( null === self::$active_preview_content ) {
			return;
		}
		?>
		<div id="push-md-draft-preview-notice" style="position:fixed;right:16px;bottom:16px;z-index:99999;padding:8px 12px;border-radius:4px;background:#1d2327;color:#f6f7f7;font:13px/1.4 -apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;box-shadow:0 6px 18px rgba(0,0,0,.2);">
			<?php esc_html_e( 'Draft preview (unpublished changes)', 'push-md' ); ?>
		</div>
		<?php
	}
}
