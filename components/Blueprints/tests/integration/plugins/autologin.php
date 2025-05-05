<?php
/**
 * Returns the username to auto-login as, if any.
 * @return string|false
 */
function playground_get_username_for_auto_login() {
	/**
	 * Allow users to auto-login as a specific user on their first visit.
	 *
	 * Prevent the auto-login if it already happened by checking for the
	 * playground_auto_login_already_happened cookie.
	 * This is used to allow the user to logout.
	 */
	if ( defined('PLAYGROUND_AUTO_LOGIN_AS_USER') && !isset($_COOKIE['playground_auto_login_already_happened']) ) {
		return PLAYGROUND_AUTO_LOGIN_AS_USER;
	}
	/**
	 * Allow users to auto-login as a specific user by passing the
	 * playground_force_auto_login_as_user GET parameter.
	 */
	if ( defined('PLAYGROUND_FORCE_AUTO_LOGIN_ENABLED') && isset($_GET['playground_force_auto_login_as_user']) ) {
		return $_GET['playground_force_auto_login_as_user'];
	}
	return false;
}

/**
 * Logs the user in on their first visit if the Playground runtime told us to.
 */
function playground_auto_login() {
	/**
	 * The redirect should only run if the current PHP request is
	 * a HTTP request. If it's a PHP CLI run, we can't login the user
	 * because logins require cookies which aren't available in the CLI.
	 *
	 * Currently all Playground requests use the "cli" SAPI name
	 * to ensure support for WP-CLI, so the best way to distinguish
	 * between a CLI run and an HTTP request is by checking if the
	 * $_SERVER['REQUEST_URI'] global is set.
	 *
	 * If $_SERVER['REQUEST_URI'] is not set, we assume it's a CLI run.
	 */
	if (empty($_SERVER['REQUEST_URI'])) {
		return;
	}
	$user_name = playground_get_username_for_auto_login();
	if ( false === $user_name ) {
		return;
	}
	if (wp_doing_ajax() || defined('REST_REQUEST')) {
		return;
	}
	if ( is_user_logged_in() ) {
		return;
	}
	$user = get_user_by('login', $user_name);
	if (!$user) {
		return;
	}

	/**
	 * We're about to set cookies and redirect. It will log the user in
	 * if the headers haven't been sent yet.
	 *
	 * However, if they have been sent already – e.g. there a PHP
	 * notice was printed, we'll exit the script with a bunch of errors
	 * on the screen and without the user being logged in. This
	 * will happen on every page load and will effectively make Playground
	 * unusable.
	 *
	 * Therefore, we just won't auto-login if headers have been sent. Maybe
	 * we'll be able to finish the operation in one of the future requests
	 * or maybe not, but at least we won't end up with a permanent white screen.
	 */
	if (headers_sent()) {
		_doing_it_wrong('playground_auto_login', 'Headers already sent, the Playground runtime will not auto-login the user', '1.0.0');
		return;
	}

	/**
	 * This approach is described in a comment on
	 * https://developer.wordpress.org/reference/functions/wp_set_current_user/
	 */
	wp_set_current_user( $user->ID, $user->user_login );
	wp_set_auth_cookie( $user->ID );
	do_action( 'wp_login', $user->user_login, $user );

	setcookie('playground_auto_login_already_happened', '1');

	/**
	 * Confirm that nothing in WordPress, plugins, or filters have finalized
	 * the headers sending phase. See the comment above for more context.
	 */
	if (headers_sent()) {
		_doing_it_wrong('playground_auto_login', 'Headers already sent, the Playground runtime will not auto-login the user', '1.0.0');
		return;
	}

	/**
	 * Reload page to ensure the user is logged in correctly.
	 * WordPress uses cookies to determine if the user is logged in,
	 * so we need to reload the page to ensure the cookies are set.
	 */
	$redirect_url = $_SERVER['REQUEST_URI'];
	/**
	 * Intentionally do not use wp_redirect() here. It removes
	 * %0A and %0D sequences from the URL, which we don't want.
	 * There are valid use-cases for encoded newlines in the query string,
	 * for example html-api-debugger accepts markup with newlines
	 * encoded as %0A via the query string.
	 */
	header( "Location: $redirect_url", true, 302 );
	exit;
}
/**
 * Autologin users from the wp-login.php page.
 *
 * The wp hook isn't triggered on
 **/
add_action('init', 'playground_auto_login', 1);

/**
 * Disable the Site Admin Email Verification Screen for any session started
 * via autologin.
 */
add_filter('admin_email_check_interval', function($interval) {
	if(false === playground_get_username_for_auto_login()) {
		return 0;
	}
	return $interval;
});
