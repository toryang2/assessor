<?php
/**
 * Plugin Name: Assessor History Archiving API
 * Description: Backend API for Assessor System
 * Version: 1.1.17
 * Author: toryang2
 * Author URI: https://github.com/toryang2
 * Text Domain: assessor-api
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('ASSESSOR_API_VERSION', '1.1.17');
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
require_once ASSESSOR_API_PLUGIN_DIR . 'includes/class-assessor-timezone.php';
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
require_once ASSESSOR_API_PLUGIN_DIR . 'includes/class-assessor-uuid.php';
require_once ASSESSOR_API_PLUGIN_DIR . 'includes/class-assessor-sync-report.php';
require_once ASSESSOR_API_PLUGIN_DIR . 'includes/class-assessor-sync.php';
require_once ASSESSOR_API_PLUGIN_DIR . 'includes/class-assessor-sync-receiver.php';
require_once ASSESSOR_API_PLUGIN_DIR . 'includes/class-assessor-hardware-lock.php';
require_once ASSESSOR_API_PLUGIN_DIR . 'includes/class-assessor-etracs.php';
require_once ASSESSOR_API_PLUGIN_DIR . 'includes/class-assessor-etracs-sync.php';
require_once ASSESSOR_API_PLUGIN_DIR . 'includes/class-assessor-migration-runner.php';
require_once ASSESSOR_API_PLUGIN_DIR . 'includes/class-assessor-security-setup.php';

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

// Initialize security setup during plugins_loaded
add_action('plugins_loaded', array('Assessor_Security_Setup', 'init'));

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

    // Ensure deployment has an installation ID without overwriting JWT secrets
    Assessor_Security_Setup::ensure_installation_id();

    // Automatically configure the hardware-lock deployment mode.
    assessor_api_ensure_local_build_constant();
}

/**
 * Determine whether the current WordPress installation is a local build.
 *
 * Local:
 * - localhost
 * - 127.0.0.1
 * - ::1
 * - private IPv4 addresses
 * - .local / .test hostnames
 *
 * Everything else is considered a remote/live deployment.
 */
function assessor_api_detect_local_build() {
    $host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));

    if ($host === '') {
        return false;
    }

    // Loopback / localhost
    if (in_array($host, array(
        'localhost',
        '127.0.0.1',
        '::1',
    ), true)) {
        return true;
    }

    // Local development hostnames
    if (
        substr($host, -6) === '.local' ||
        substr($host, -5) === '.test'
    ) {
        return true;
    }

    // Private IPv4 networks
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        if (
            strpos($host, '10.') === 0 ||
            strpos($host, '192.168.') === 0 ||
            preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $host)
        ) {
            return true;
        }
    }

    // Anything else is treated as live/remote.
    return false;
}

/**
 * Ensure ASSESSOR_IS_LOCAL_BUILD exists in wp-config.php.
 *
 * Existing explicit configuration is never overwritten.
 */
function assessor_api_ensure_local_build_constant() {
    if (defined('ASSESSOR_IS_LOCAL_BUILD')) {
        return;
    }

    $is_local = assessor_api_detect_local_build();
    $value = $is_local ? 'true' : 'false';

    /*
     * wp-config.php normally lives one directory above ABSPATH.
     * For standard WordPress installs ABSPATH points to the WordPress root.
     */
    $config_file = ABSPATH . 'wp-config.php';

    if (!file_exists($config_file)) {
        $config_file = dirname(ABSPATH) . '/wp-config.php';
    }

    if (!file_exists($config_file) || !is_writable($config_file)) {
        error_log(
            'Assessor Hardware Lock: Could not update wp-config.php. ' .
            'Expected file: ' . $config_file
        );

        // Still define it for the current request.
        if (!defined('ASSESSOR_IS_LOCAL_BUILD')) {
            define('ASSESSOR_IS_LOCAL_BUILD', $is_local);
        }

        return;
    }

    $config_contents = file_get_contents($config_file);

    if ($config_contents === false) {
        error_log('Assessor Hardware Lock: Failed to read wp-config.php.');

        if (!defined('ASSESSOR_IS_LOCAL_BUILD')) {
            define('ASSESSOR_IS_LOCAL_BUILD', $is_local);
        }

        return;
    }

    /*
     * Insert the constant before the standard WordPress
     * "That's all, stop editing!" line when possible.
     */
    $constant_line =
        "define('ASSESSOR_IS_LOCAL_BUILD', {$value});\n";

    $marker = "/* That's all, stop editing! Happy publishing. */";

    if (strpos($config_contents, $marker) !== false) {
        $config_contents = str_replace(
            $marker,
            $constant_line . "\n" . $marker,
            $config_contents
        );
    } else {
        /*
         * Fallback: append before the final PHP closing tag if one exists.
         */
        $php_close_pos = strrpos($config_contents, '?>');

        if ($php_close_pos !== false) {
            $config_contents =
                substr($config_contents, 0, $php_close_pos) .
                "\n" . $constant_line . "\n" .
                substr($config_contents, $php_close_pos);
        } else {
            $config_contents .= "\n" . $constant_line;
        }
    }

    $written = file_put_contents($config_file, $config_contents, LOCK_EX);

    if ($written === false) {
        error_log(
            'Assessor Hardware Lock: Failed to write ASSESSOR_IS_LOCAL_BUILD to wp-config.php.'
        );
    } else {
        error_log(
            'Assessor Hardware Lock: ASSESSOR_IS_LOCAL_BUILD=' .
            $value .
            ' configured automatically.'
        );
    }

    /*
     * Define it for this request too.
     */
    if (!defined('ASSESSOR_IS_LOCAL_BUILD')) {
        define('ASSESSOR_IS_LOCAL_BUILD', $is_local);
    }
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'assessor_api_deactivate');
function assessor_api_deactivate() {
    // Deregister sync cron job
    Assessor_Sync::deregister_cron();
}

