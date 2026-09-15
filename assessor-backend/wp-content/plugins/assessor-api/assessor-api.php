<?php
/**
 * Plugin Name: Assessor History Archiving API
 * Description: Backend API for Assessor System
 * Version: 1.1.16
 * Author: toryang2
 * Author URI: https://github.com/toryang2
 * Text Domain: assessor-api
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('ASSESSOR_API_VERSION', '1.1.16');
define('ASSESSOR_API_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ASSESSOR_API_PLUGIN_URL', plugin_dir_url(__FILE__));

// Set global timezone for the plugin
date_default_timezone_set('Asia/Manila');

// Set database timezone to match PHP timezone
add_action('init', function() {
    global $wpdb;
    $wpdb->query("SET time_zone = '+08:00'");
});

// Include required files
require_once ASSESSOR_API_PLUGIN_DIR . 'includes/class-assessor-api.php';
require_once ASSESSOR_API_PLUGIN_DIR . 'includes/class-assessor-database.php';
require_once ASSESSOR_API_PLUGIN_DIR . 'includes/class-assessor-auth.php';
require_once ASSESSOR_API_PLUGIN_DIR . 'includes/class-assessor-properties.php';
require_once ASSESSOR_API_PLUGIN_DIR . 'includes/class-assessor-versions.php';
require_once ASSESSOR_API_PLUGIN_DIR . 'includes/class-assessor-documents.php';
require_once ASSESSOR_API_PLUGIN_DIR . 'includes/class-assessor-audit.php';
require_once ASSESSOR_API_PLUGIN_DIR . 'includes/class-assessor-export.php';
require_once ASSESSOR_API_PLUGIN_DIR . 'includes/class-assessor-settings.php';
require_once ASSESSOR_API_PLUGIN_DIR . 'includes/class-assessor-requests.php';
require_once ASSESSOR_API_PLUGIN_DIR . 'includes/class-assessor-public-api.php';
require_once ASSESSOR_API_PLUGIN_DIR . 'includes/class-assessor-sync.php';
require_once ASSESSOR_API_PLUGIN_DIR . 'includes/class-assessor-sync-receiver.php';
require_once ASSESSOR_API_PLUGIN_DIR . 'includes/class-assessor-hardware-lock.php';
require_once ASSESSOR_API_PLUGIN_DIR . 'includes/class-assessor-uuid.php';
require_once ASSESSOR_API_PLUGIN_DIR . 'includes/class-assessor-etracs.php';
require_once ASSESSOR_API_PLUGIN_DIR . 'includes/class-assessor-etracs-sync.php';
require_once ASSESSOR_API_PLUGIN_DIR . 'includes/class-assessor-migration-runner.php';

// Initialize the plugin
function assessor_api_init() {
    error_log('🔍 Assessor API: Plugin init function called!');
    $assessor_api = new Assessor_API();
    error_log('🔍 Assessor API: Plugin class instantiated!');
    $assessor_api->init();
    error_log('🔍 Assessor API: Plugin init completed!');
    
    // Register sync cron
    Assessor_Sync::register_cron();
}
add_action('init', 'assessor_api_init');

// Auto-migrate database if version changes
add_action('plugins_loaded', 'assessor_api_check_version');
function assessor_api_check_version() {
    if (get_option('assessor_db_version') !== ASSESSOR_API_VERSION) {
        $database = new Assessor_Database();
        $database->create_tables();
        update_option('assessor_db_version', ASSESSOR_API_VERSION);
    }
}

// Hook the background file downloader
add_action('assessor_sync_files_cron', array('Assessor_Sync', 'download_missing_files'));

// Activation hook
register_activation_hook(__FILE__, 'assessor_api_activate');
function assessor_api_activate() {
    $database = new Assessor_Database();
    $database->create_tables();
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'assessor_api_deactivate');
function assessor_api_deactivate() {
    // Deregister sync cron job
    Assessor_Sync::deregister_cron();
}

