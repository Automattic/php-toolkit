<?php
define( 'WP_ADMIN', true );

// Define a dummy skin for the upgrader.
if ( ! class_exists( '\WP_Upgrader_Skin', false ) ) {
	require_once getenv( 'DOCROOT' ) . '/wp-admin/includes/class-wp-upgrader.php';
	class Blueprint_WP_Upgrader_Skin extends \WP_Upgrader_Skin {
		public $destination;
		public $options = array(
			'type'   => '',
			'title'  => '',
			'url'    => '',
			'nonce'  => '',
			'plugin' => '',
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
			return true;
		}
		public function error( $errors ) {
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
			// Or use error_log( $string );
		}
		public function header() {}
		public function footer() {}
		public function bulk_header() {}
		public function bulk_footer() {}
		public function before( $title = '' ) {}
		public function after( $title = '' ) {}
	}
}

require_once getenv( 'DOCROOT' ) . '/wp-load.php';
require_once getenv( 'DOCROOT' ) . '/wp-admin/includes/plugin.php';
require_once getenv( 'DOCROOT' ) . '/wp-admin/includes/file.php';
require_once getenv( 'DOCROOT' ) . '/wp-admin/includes/plugin-install.php';

// Set current user to admin
$admins = get_users( array( 'role' => 'Administrator' ) );
if ( ! empty( $admins ) ) {
	set_current_user( $admins[0] );
} else {
	error_log( "Blueprint Error: No admin user found to perform plugin installation." );
	exit( 1 );
}

$plugin_zip_path = getenv( 'PLUGIN_ZIP_PATH' );
if ( ! $plugin_zip_path ) {
	error_log( "Blueprint Error: PLUGIN_ZIP_PATH environment variable not set." );
	exit( 1 );
}

if ( ! file_exists( $plugin_zip_path ) ) {
	error_log( "Blueprint Error: Plugin zip file not found at " . $plugin_zip_path );
	exit( 1 );
}

// Use the Plugin_Upgrader class to install the plugin.
$skin     = new Blueprint_WP_Upgrader_Skin();
$upgrader = new \Plugin_Upgrader( $skin );
$result   = $upgrader->install( $plugin_zip_path );

if ( is_wp_error( $result ) ) {
	error_log( "Blueprint Error: Failed to install plugin: " . $result->get_error_message() );
	exit( 1 );
}

if ( $result === false || $result === null ) {
	// Check skin for errors if $result is not specific.
	if ( isset( $skin->result ) && is_wp_error( $skin->result ) ) {
		error_log( "Blueprint Error: Failed to install plugin: " . $skin->result->get_error_message() );
	} else {
		error_log( "Blueprint Error: Failed to install plugin for an unknown reason." );
	}
	exit( 1 );
}

// Installation successful, find the main plugin file.
$plugin_folder_name = $upgrader->result['destination_name'] ?? null;
if ( ! $plugin_folder_name ) {
	error_log( "Blueprint Error: Could not determine plugin folder name after installation." );
	exit( 1 );
}

// Get all plugins within the newly installed folder.
$plugins_in_folder = get_plugins( '/' . $plugin_folder_name );
if ( empty( $plugins_in_folder ) ) {
	error_log( "Blueprint Error: Could not find any plugin files in the installed folder: " . $plugin_folder_name );
	exit( 1 );
}

// The key of the first plugin entry is the relative path needed for activation.
$plugin_file_relative_path = array_key_first( $plugins_in_folder );

// Output the relative path of the main plugin file.
echo $plugin_file_relative_path;

exit( 0 );
