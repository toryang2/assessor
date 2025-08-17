<?php
/**
 * Plugin Name: Assessor History Archiving API
 * Description: Comprehensive REST API for property assessor history archiving system
 * Version: 1.0.7
 * Author: Philippine Local Government
 * Text Domain: assessor-api
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('ASSESSOR_API_VERSION', '1.0.7');
define('ASSESSOR_API_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ASSESSOR_API_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include required files
require_once ASSESSOR_API_PLUGIN_DIR . 'includes/class-assessor-api.php';
require_once ASSESSOR_API_PLUGIN_DIR . 'includes/class-assessor-database.php';
require_once ASSESSOR_API_PLUGIN_DIR . 'includes/class-assessor-auth.php';
require_once ASSESSOR_API_PLUGIN_DIR . 'includes/class-assessor-properties.php';
require_once ASSESSOR_API_PLUGIN_DIR . 'includes/class-assessor-versions.php';
require_once ASSESSOR_API_PLUGIN_DIR . 'includes/class-assessor-documents.php';
require_once ASSESSOR_API_PLUGIN_DIR . 'includes/class-assessor-audit.php';
require_once ASSESSOR_API_PLUGIN_DIR . 'includes/class-assessor-export.php';

// Initialize the plugin
function assessor_api_init() {
    error_log('🔍 Assessor API: Plugin init function called!');
    $assessor_api = new Assessor_API();
    error_log('🔍 Assessor API: Plugin class instantiated!');
    $assessor_api->init();
    error_log('🔍 Assessor API: Plugin init completed!');
}
add_action('init', 'assessor_api_init');

// Activation hook
register_activation_hook(__FILE__, 'assessor_api_activate');
function assessor_api_activate() {
    $database = new Assessor_Database();
    $database->create_tables();
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'assessor_api_deactivate');
function assessor_api_deactivate() {
    // Cleanup if needed
}

