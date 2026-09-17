<?php
/**
 * 04-rollback-lookup-tables.php
 *
 * Rollback utility: Restores lookup tables from the latest timestamped backup tables
 * created during migration.
 */

// Enable full error display for debugging
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Accel-Buffering: no');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    ob_implicit_flush(true);
    @set_time_limit(600);
}

// Load WordPress environment
$wp_load_paths = array(
    'C:/xampp/htdocs/wp-load.php',
    '/home/u799325560/domains/archive.massokitaotao.net/public_html/wp-load.php',
    __DIR__ . '/../../../../../../wp-load.php',
    __DIR__ . '/../../../../../wp-load.php',
    __DIR__ . '/../../../../wp-load.php',
    __DIR__ . '/../../../wp-load.php',
    isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] . '/wp-load.php' : ''
);

$loaded = false;
foreach ($wp_load_paths as $path) {
    if (!empty($path) && file_exists($path)) {
        require_once $path;
        $loaded = true;
        break;
    }
}

if (!$loaded) {
    die("FATAL: Cannot locate wp-load.php.\n");
}

// Authorization check (WordPress is now loaded, so sanitize_text_field and wp_die are defined)
if (php_sapi_name() !== 'cli') {
    $secret_key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';
    if ($secret_key !== 'masso-migrate-uuid') {
        if (function_exists('wp_die')) {
            wp_die('Unauthorized. Provide ?key=masso-migrate-uuid to run via browser.');
        } else {
            die("Unauthorized. Provide ?key=masso-migrate-uuid to run via browser.\n");
        }
    }
}

global $wpdb;
$wpdb->show_errors();

echo "===============================================================\n";
echo "STEP 04: ROLLBACK LOOKUP TABLES FROM BACKUP\n";
echo "===============================================================\n";

$tables = array(
    'assessor_property_types',
    'assessor_general_classes',
    'assessor_locations',
    'assessor_request_purposes',
);

foreach ($tables as $t) {
    $tbl = $wpdb->prefix . $t;
    // Find latest backup table
    $pattern = $tbl . '_backup_%';
    $backups = $wpdb->get_col($wpdb->prepare("SHOW TABLES LIKE %s", $pattern));
    if (empty($backups)) {
        echo "No backup found for $tbl. Skipping.\n";
        continue;
    }
    rsort($backups);
    $latest_backup = $backups[0];
    echo "Restoring $tbl from $latest_backup...\n";

    // Drop target table, recreate like backup, populate
    $wpdb->query("DROP TABLE IF EXISTS $tbl");
    $wpdb->query("CREATE TABLE $tbl LIKE $latest_backup");
    $wpdb->query("INSERT INTO $tbl SELECT * FROM $latest_backup");

    $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $tbl");
    echo "  - Restored $tbl successfully ($count rows).\n";
}

echo "\nRollback complete.\n";
