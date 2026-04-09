<?php
/**
 * Pressable_OnePress_Login_Plugin class
 */
final class Pressable_OnePress_Login_Plugin {
	/** Constructor for the class */
	public function __construct() {
		/** Load after plugins have loaded - https://developer.wordpress.org/reference/hooks/plugins_loaded/ */
		if ( $this->is_ready_to_handle_mpcp_login_request() ) {
			// Whitelist MPCP hostname for redirecting on errors.
			add_filter( 'allowed_redirect_hosts', array( $this, 'allowed_redirect_hosts' ) );

			// Handle login request.
			add_action( 'plugins_loaded', array( $this, 'handle_server_login_request' ) );
		}

		// Display OnePress error messages on the login page.
		add_filter( 'login_message', array( $this, 'display_onepress_error' ) );
	}

	/** Function for handling an incoming login request */
	public function handle_server_login_request() {
		// Get the Auth Token from the request.
		// Inbound URL Example: https://pressable.com/wp-login.php?mpcp_token=MS0wZWQ.
		$base64_token = $_REQUEST['mpcp_token'];

		// Base64 Decode the provided token.
		$token_details = base64_decode( $base64_token );

		// Get reference to user_id, token and site_id.
		list( $user_id, $token, $site_id, $user_agent ) = explode( '-', $token_details );

		// Reference to the WP User.
		$user = new WP_User( $user_id );

		// Reference the stored user meta value.
		$user_meta_value = get_user_meta( $user->ID, 'mpcp_auth_token', true );

		// Remove the stored token details from the user meta.
		delete_user_meta( $user->ID, 'mpcp_auth_token' );

		// Verify token is set on user.
		if ( empty( $user_meta_value ) ) {
			error_log( sprintf( 'OnePress Login user meta value (mpcp_auth_token) not found for user (%d), please try again.', $user->ID ) );

			$message = 'User not found, please try logging in again.';

			wp_safe_redirect(
				add_query_arg(
					'one_click_error',
					rawurlencode( $message ),
					$this->filter_redirect_url( $site_id, $user )
				)
			);

			exit;
		}

		// Validate expiration time on token.
		$time = time();
		if ( $user_meta_value['exp'] < $time ) {
			error_log( sprintf( 'OnePress Login authentication token has expired (exp_time: %d, time: %s), please try again.', $user_meta_value['exp'], $time ) );

			$message = 'Authentication token has expired, please try again.';

			wp_safe_redirect(
				add_query_arg(
					'one_click_error',
					rawurlencode( $message ),
					$this->filter_redirect_url( $site_id, $user )
				)
			);

			exit;
		}

		// Validate user agent is matching.
		if ( md5( $_SERVER['HTTP_USER_AGENT'] ) !== $user_agent ) {
			error_log( sprintf( 'OnePress Login could not validate user agent (%s), please try again.', $_SERVER['HTTP_USER_AGENT'] ) );

			$message = 'Sorry, we could not validate your request user agent, please try again.';

			wp_safe_redirect(
				add_query_arg(
					'one_click_error',
					rawurlencode( $message ),
					$this->filter_redirect_url( $site_id, $user )
				)
			);

			exit;
		}

		// Validate URL token with stored token value.
		if ( md5( $token ) !== $user_meta_value['value'] ) {
			error_log( sprintf( 'OnePress Login invalid authentication token provided (%s), please try again.', $token ) );

			$message = 'Invalid authentication token provided, please try again.';

			wp_safe_redirect(
				add_query_arg(
					'one_click_error',
					rawurlencode( $message ),
					$this->filter_redirect_url( $site_id, $user )
				)
			);

			exit;
		}

		// Set cookie for user.
		wp_set_auth_cookie( $user->ID );

		// Handle login action.
		do_action( 'wp_login', $user->user_login, $user );

		// Apply login redirect filter.
		$redirect_to = apply_filters( 'login_redirect', get_dashboard_url( $user->ID ), '', $user );

		// Redirect to the user's dashboard url.
		wp_safe_redirect( $redirect_to );

		exit;
	}

	/**
	 * Whitelist hosts that are allowed to be redirected to.
	 *
	 * @param array $hosts Allowed hosts list.
	 *
	 * @return array Collection of allowed hosts with MPCP host added.
	 */
	public function allowed_redirect_hosts( $hosts ) {
		$default_additional_hosts = array(
			'my.pressable.com',
		);

		// Apply a new filter to allow adding custom hosts
		$additional_hosts = apply_filters( 'onepress_login_additional_hosts', $default_additional_hosts );

		return array_merge( $hosts, $additional_hosts );
	}

	/**
	 * Display OnePress error message on the WordPress login page.
	 *
	 * @param string $message Existing login message HTML.
	 *
	 * @return string Login message HTML with error appended if present.
	 */
	public function display_onepress_error( $message ) {
		if ( isset( $_GET['one_click_error'] ) && ! empty( $_GET['one_click_error'] ) ) {
			$error = esc_html( rawurldecode( $_GET['one_click_error'] ) );
			$message .= '<div id="login_error"><strong>OnePress Login:</strong> ' . $error . '</div>';
		}

		return $message;
	}

	/**
	 * Decide if request should be handled
	 *
	 * @return bool True if eligible, False if not.
	 */
	private function is_ready_to_handle_mpcp_login_request() {
		// Do not handle if WP is installing, or running a cron or handling AJAX request or if WPCLI request.
		if ( wp_installing() || wp_doing_cron() || wp_doing_ajax() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}

		// Must include the MPCP login path with mpcp_token.
		if ( 'wp-login.php' === $GLOBALS['pagenow'] && isset( $_REQUEST['mpcp_token'] ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Filter for allowing customers to adjust the redirect url.
	 *
	 * @param $site_id
	 * @param $user
	 *
	 * @return string Redirect Url.
	 */
	private function filter_redirect_url( $site_id, $user ) {
		$default_redirect_url = wp_login_url();

		return apply_filters( 'onepress_login_custom_redirect_url', $default_redirect_url, $site_id, $user );
	}
}
