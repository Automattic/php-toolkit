<?php
define( 'WP_ADMIN', true );

// Define a dummy skin for the upgrader.
// This allows us to install themes without generating HTML output,
// and capture installation results and errors.
if ( ! class_exists( '\WP_Upgrader_Skin', false ) ) {
	require_once getenv( 'DOCROOT' ) . '/wp-admin/includes/class-wp-upgrader.php';
	class Blueprint_WP_Upgrader_Skin extends \WP_Upgrader_Skin {
		public $destination;
		public $options = array(
			'type'   => '',
			'title'  => '',
			'url'    => '',
			'nonce'  => '',
			'theme' => '', // Adjusted from 'plugin' for clarity, though WP core might not use it directly here.
			'api'    => null,
			'extra'  => array(),
		);
		public $result = null;
		public function add_strings() {}
		public function set_upgrader( &$upgrader ) {
			if ( is_object( $upgrader ) ) {
				$this->upgrader = &$upgrader;
			}
			$this->add_strings();
		}
		public function set_result( $result ) {
			$this->result = $result;
		}
		public function request_filesystem_credentials( $error = false, $context = '', $allow_relaxed_file_ownership = false ) {
			// Always return true to avoid prompting for FS credentials.
			// This assumes the environment is set up with correct file permissions.
			return true;
		}
		public function error( $errors ) {
			// Store the error internally if it's a WP_Error object
			if ( is_wp_error( $errors ) ) {
				$this->result = $errors;
			}
			// Log errors for debugging purposes
			if ( is_string( $errors ) ) {
				$this->feedback( $errors );
				return;
			}
			if ( is_wp_error( $errors ) && $errors->has_errors() ) {
				foreach ( $errors->get_error_messages() as $message ) {
					if ( $errors->get_error_data() && is_string( $errors->get_error_data() ) ) {
						$this->feedback( $message . ': ' . esc_html( strip_tags( $errors->get_error_data() ) ) );
					} else {
						$this->feedback( $message );
					}
				}
			}
		}
		public function feedback( $string, ...$args ) {
			// Keep this empty to avoid output unless debugging.
			// error_log( "Blueprint Skin Feedback: " . $string );
		}
		// Empty implementations for UI methods to prevent any output
		public function header() {}
		public function footer() {}
		public function bulk_header() {}
		public function bulk_footer() {}
		public function before( $title = '' ) {}
		public function after( $title = '' ) {}
	}
}

// Load WordPress environment
require_once getenv( 'DOCROOT' ) . '/wp-load.php';
// Load WordPress Administration Upgrade API
require_once getenv( 'DOCROOT' ) . '/wp-admin/includes/file.php';
require_once getenv( 'DOCROOT' ) . '/wp-admin/includes/theme.php'; // Contains Theme_Upgrader

// Ensure class-wp-upgrader.php is loaded if not already done by the skin check
if ( ! class_exists( '\WP_Upgrader', false ) ) {
	require_once getenv( 'DOCROOT' ) . '/wp-admin/includes/class-wp-upgrader.php';
}


// Set current user to an administrator to ensure permissions for theme installation
$admins = get_users( array( 'role' => 'Administrator' ) );
if ( ! empty( $admins ) ) {
	set_current_user( $admins[0]->ID );
} else {
	error_log( "Blueprint Error: No admin user found to perform theme installation." );
	exit( 1 );
}

// Get the path to the theme zip file from environment variable
$theme_zip_path = getenv( 'THEME_ZIP_PATH' );
if ( ! $theme_zip_path ) {
	error_log( "Blueprint Error: THEME_ZIP_PATH environment variable not set." );
	exit( 1 );
}

// Check if the theme zip file exists
if ( ! file_exists( $theme_zip_path ) ) {
	error_log( "Blueprint Error: Theme zip file not found at " . $theme_zip_path );
	exit( 1 );
}

// Use the Theme_Upgrader class with our custom skin to install the theme
$skin     = new Blueprint_WP_Upgrader_Skin();
$upgrader = new \Theme_Upgrader( $skin );
// Clear the destination directory if it already exists.
// This prevents errors if the theme was partially installed before.
// Note: $upgrader->init() might be needed before accessing skin->options, but install() calls it.
// $upgrader->init(); // Usually called within install()
// $skin->options['clear_destination'] = true; // This option might be useful but needs testing if it works as expected here.
// Let's proceed without clear_destination for now, relying on default behavior.

$result   = $upgrader->install( $theme_zip_path, array( 'overwrite_package' => false ) ); // overwrite_package=false is default, but explicit

// Check for installation errors reported by the upgrader directly
if ( is_wp_error( $result ) ) {
	error_log( "Blueprint Error: Failed to install theme (Upgrader Error): " . $result->get_error_message() );
	exit( 1 );
}

// Check for errors reported via the skin (sometimes errors don't return via $result)
if ( is_wp_error( $skin->result ) ) {
	error_log( "Blueprint Error: Failed to install theme (Skin Error): " . $skin->result->get_error_message() );
	exit( 1 );
}

// Check for null or false result, which also indicates failure
if ( $result === false || $result === null ) {
	error_log( "Blueprint Error: Failed to install theme for an unknown reason (Upgrader returned false/null)." );
	exit( 1 );
}

// Installation successful, get the theme folder name (stylesheet) from the result array
$theme_folder_name = $upgrader->result['destination_name'] ?? null;
if ( ! $theme_folder_name ) {
	error_log( "Blueprint Error: Could not determine theme folder name (stylesheet) after installation." );
	// Log the full result for debugging if needed
	// error_log("Blueprint Debug: Upgrader result: " . print_r($upgrader->result, true));
	exit( 1 );
}

// Output the theme folder name (stylesheet) to stdout. This is captured by the runner.
echo $theme_folder_name;

// Exit with success status code
exit( 0 );
