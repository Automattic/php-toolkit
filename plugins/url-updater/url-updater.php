<?php
/**
 * Plugin Name: Remote Plugin Installer
 * Description: Installs a plugin from a given URL and allows updates from a user-defined URL.
 */

/**
 * UNSAFE! DO NOT USE IN PRODUCTION!
 *
 * Disables request filtering for easy testing and development.
 */
add_filter('block_local_requests', '__return_false');

add_filter('http_request_args', function($args, $url) {
// Disable certificate verification
$args['sslverify'] = false;
// Allow local IP addresses or unsafe URLs
$args['reject_unsafe_urls'] = false;
return $args;
}, 9999, 2);

add_action('admin_menu', function() {
  add_menu_page('Remote Installer', 'Remote Installer', 'manage_options', 'remote-installer', 'rpi_render_admin_page');
});

function rpi_render_admin_page() {
  if (!current_user_can('manage_options')) return;

  // If we're updating, show a form to change the URL or handle the update
  if (isset($_GET['rpi_update_plugin'])) {
    $plugin_file = sanitize_text_field($_GET['rpi_update_plugin']);
    $stored_url = rpi_get_stored_plugin_url($plugin_file);

    // Direct update without changing URL
    if (isset($_GET['direct_update']) && $_GET['direct_update'] === 'true') {
      $installed_plugin_file = rpi_install_plugin_from_url($stored_url, true);
      if ($installed_plugin_file) {
        echo '<div class="notice notice-success"><p>Plugin updated successfully.</p></div>';
      } else {
        echo '<div class="notice notice-error"><p>Update failed.</p></div>';
      }
      echo '<a href="' . esc_url(admin_url('admin.php?page=remote-installer')) . '" class="button">Back to Plugin List</a>';
      return;
    }

    // If the user just submitted the new URL, do the update
    if (isset($_POST['rpi_new_url'])) {
      $new_url = sanitize_text_field($_POST['rpi_new_url']);
      $installed_plugin_file = rpi_install_plugin_from_url($new_url, true);
      if ($installed_plugin_file) {
        rpi_store_plugin_url($installed_plugin_file, $new_url);
        echo '<div class="notice notice-success"><p>Plugin updated successfully.</p></div>';
      } else {
        echo '<div class="notice notice-error"><p>Update failed.</p></div>';
      }
      echo '<a href="' . esc_url(admin_url('admin.php?page=remote-installer')) . '" class="button">Back to Plugin List</a>';
    } else {
      // Show the form to change the URL
      echo '<div class="wrap">';
      echo '<h1>Update Plugin</h1>';
      echo '<p>You can change the URL here before updating.</p>';
      echo '<form method="POST">';
      echo '<input type="text" name="rpi_new_url" value="' . esc_attr($stored_url) . '" style="width: 50%;" />';
      echo '<br><br><input type="submit" value="Update Plugin" class="button button-primary" />';
      echo '</form>';
      echo '<a href="' . esc_url(admin_url('admin.php?page=remote-installer')) . '" class="button">Back to Plugin List</a>';
      echo '</div>';
    }
    return;
  }

  // If we're installing a new plugin
  if (isset($_POST['rpi_plugin_url'])) {
    $plugin_url = sanitize_text_field($_POST['rpi_plugin_url']);
    $installed_plugin_file = rpi_install_plugin_from_url($plugin_url);
    if ($installed_plugin_file) {
      rpi_store_plugin_url($installed_plugin_file, $plugin_url);
      echo '<div class="notice notice-success"><p>Plugin installed and activated successfully.</p></div>';
    } else {
      echo '<div class="notice notice-error"><p>Installation failed.</p></div>';
    }
  }

  // Default interface
  echo '<div class="wrap">';
  echo '<h1>Remote Plugin Installer</h1>';
  echo '<form method="POST">';
  echo '<p>Install a new plugin from a given URL.</p>';
  echo '<input type="text" name="rpi_plugin_url" placeholder="Plugin ZIP URL" style="width: 50%;" />';
  echo '<br><br><input type="submit" value="Install Plugin" class="button button-primary" />';
  echo '</form>';
  echo '</div>';

  // List installed plugins
  $installed_plugins = get_option('rpi_installed_plugins', []);
  if (!empty($installed_plugins)) {
    echo '<h2>Installed Plugins</h2>';
    echo '<table class="widefat fixed" cellspacing="0">';
    echo '<thead><tr><th>Plugin</th><th>Last Update</th><th>URL</th><th>Actions</th></tr></thead>';
    echo '<tbody>';
    foreach ($installed_plugins as $plugin_file => $plugin_url) {
      $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file);
      $last_update = get_option('rpi_last_update_' . $plugin_file, 'Never');
      $update_url = admin_url('admin.php?page=remote-installer&rpi_update_plugin=' . urlencode($plugin_file) . '&direct_update=true');
      $change_url = admin_url('admin.php?page=remote-installer&rpi_update_plugin=' . urlencode($plugin_file));
      echo '<tr>';
      echo '<td>' . esc_html($plugin_data['Name']) . '</td>';
      echo '<td>' . esc_html($last_update) . '</td>';
      echo '<td>' . esc_url($plugin_url) . '</td>';
      echo '<td><a href="' . esc_url($update_url) . '" class="button">Update</a> <a href="' . esc_url($change_url) . '" class="button">Change URL</a></td>';
      echo '</tr>';
    }
    echo '</tbody>';
    echo '</table>';
  }
}

function rpi_install_plugin_from_url(string $url, bool $is_update = false) {
  require_once ABSPATH . 'wp-admin/includes/file.php';
  require_once ABSPATH . 'wp-admin/includes/misc.php';
  require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
  require_once ABSPATH . 'wp-admin/includes/plugin.php';
  
  $cache_busting_url = add_query_arg('_cache_buster', time(), $url);
  $tmp_file = download_url($cache_busting_url);
  if (is_wp_error($tmp_file)) {
    return false;
  }

  $upgrader = new Plugin_Upgrader();
  $upgrader->init();
  $run_result = $upgrader->run([
    'package'           => $tmp_file,
    'destination'       => WP_PLUGIN_DIR,
    'clear_destination' => $is_update,  // Only clear the existing folder if this is truly an update
    'clear_working'     => true,
    'hook_extra'        => [
      'type'   => 'plugin',
      'action' => $is_update ? 'update' : 'install',
    ],
  ]);

  if (is_wp_error($run_result) || !$run_result) {
    return false;
  }

  $plugin_file = $upgrader->plugin_info();
  if ($plugin_file) {
    // If it's a fresh install, activate immediately
    if (!$is_update) {
      activate_plugin($plugin_file);
    } else {
      // If it was already active, re-activate after update
      $active_plugins = get_option('active_plugins', []);
      if (in_array($plugin_file, $active_plugins)) {
        activate_plugin($plugin_file);
      }
    }
    // Update the last update timestamp
    update_option('rpi_last_update_' . $plugin_file, current_time('mysql'));
  }
  return $plugin_file;
}

function rpi_store_plugin_url($plugin_file, $url) {
  $plugins = get_option('rpi_installed_plugins', []);
  $plugins[$plugin_file] = $url;
  update_option('rpi_installed_plugins', $plugins);
}

function rpi_get_stored_plugin_url($plugin_file) {
  $plugins = get_option('rpi_installed_plugins', []);
  return isset($plugins[$plugin_file]) ? $plugins[$plugin_file] : false;
}

// Add "Update" and "Change URL" links to the plugin list
add_filter('plugin_action_links', function($actions, $plugin_file, $plugin_data, $context) {
    if (is_network_admin()) return $actions;

    $stored_url = rpi_get_stored_plugin_url($plugin_file);
    if ($stored_url) {
        $update_url = admin_url('admin.php?page=remote-installer&rpi_update_plugin=' . urlencode($plugin_file) . '&direct_update=true');
        $change_url = admin_url('admin.php?page=remote-installer&rpi_update_plugin=' . urlencode($plugin_file));

        $actions['rpi_update'] = '<a href="' . esc_url($update_url) . '">Update</a>';
        $actions['rpi_change_url'] = '<a href="' . esc_url($change_url) . '">Change URL</a>';
    }
    return $actions;
}, 10, 4);
