<?php
/**
 * Manual Installation Script for Assessor History Archiving API
 * 
 * This script can be used to manually install the plugin if the WordPress
 * automatic activation doesn't work properly.
 * 
 * Usage:
 * 1. Upload this file to your WordPress root directory
 * 2. Run it once by visiting: yourdomain.com/install.php
 * 3. Delete this file after successful installation
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    // Try to find WordPress
    $wp_path = dirname(__FILE__);
    $wp_config = $wp_path . '/wp-config.php';
    
    if (file_exists($wp_config)) {
        require_once($wp_config);
    } else {
        die('WordPress not found. Please place this file in your WordPress root directory.');
    }
}

// Check if WordPress is loaded
if (!function_exists('wp_install')) {
    die('WordPress is not properly loaded.');
}

// Check if plugin is already active
if (is_plugin_active('assessor-api/assessor-api.php')) {
    die('Plugin is already active. No need to run this script.');
}

// Include WordPress functions
require_once(ABSPATH . 'wp-admin/includes/plugin.php');
require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

// Plugin directory
$plugin_dir = WP_PLUGIN_DIR . '/assessor-api';

// Check if plugin files exist
if (!file_exists($plugin_dir . '/assessor-api.php')) {
    die('Plugin files not found. Please ensure the assessor-api folder is in wp-content/plugins/');
}

// Activate the plugin
$result = activate_plugin('assessor-api/assessor-api.php');

if (is_wp_error($result)) {
    echo '<h2>Installation Failed</h2>';
    echo '<p>Error: ' . $result->get_error_message() . '</p>';
    echo '<p>Please check your WordPress installation and try again.</p>';
} else {
    echo '<h2>Installation Successful!</h2>';
    echo '<p>The Assessor History Archiving API plugin has been successfully installed and activated.</p>';
    echo '<h3>Default Login Credentials:</h3>';
    echo '<ul>';
    echo '<li><strong>Username:</strong> admin</li>';
    echo '<li><strong>Password:</strong> admin123</li>';
    echo '<li><strong>Email:</strong> admin@localgov.ph</li>';
    echo '</ul>';
    echo '<p><strong>⚠️ IMPORTANT:</strong> Please change the default password immediately after logging in!</p>';
    echo '<h3>Next Steps:</h3>';
    echo '<ol>';
    echo '<li>Go to your WordPress admin panel</li>';
    echo '<li>Log in with the credentials above</li>';
    echo '<li>Change the default password</li>';
    echo '<li>Configure your React frontend to connect to the API</li>';
    echo '<li>Delete this install.php file</li>';
    echo '</ol>';
    echo '<h3>API Endpoint:</h3>';
    echo '<p>Your API is now available at: <code>' . get_site_url() . '/wp-json/assessor/v1</code></p>';
}

// Clean up - remove this file
unlink(__FILE__);
echo '<p><em>This installation file has been automatically removed for security.</em></p>';
?>




