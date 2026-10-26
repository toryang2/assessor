<?php
/**
 * Run Shadow ETRACS Pull directly within WordPress environment against active database `test`
 */
require_once 'C:/xampp/htdocs/wp-load.php';

global $wpdb;

echo "=== Step 1: Preflight Verification for Pull ===\n";
$current_db = $wpdb->get_var("SELECT DATABASE()");
echo "Active database: " . $current_db . "\n";
if ($current_db !== 'test') {
    die("FATAL: Target database is NOT 'test'! Aborting.\n");
}

// Verify configured upstream source in options
$host = get_option('assessor_etracs_db_host', 'localhost');
$port = get_option('assessor_etracs_db_port', '3306');
$db   = get_option('assessor_etracs_db_name', 'etracs254_kitaotao');
echo "Upstream source host: " . $host . "\n";
echo "Upstream source port: " . $port . "\n";
echo "Upstream source database: " . $db . "\n";

if ($host === '192.168.0.156' || strpos($host, '192.168.0.156') !== false) {
    die("FATAL: Prohibited remote host 192.168.0.156 detected in options! Aborting.\n");
}

// Collision preflight check on native properties
$native_count_before = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}assessor_properties");
echo "Native wp_assessor_properties count before pull: " . $native_count_before . "\n";

require_once ABSPATH . 'wp-content/plugins/assessor-api/includes/class-assessor-database.php';
require_once ABSPATH . 'wp-content/plugins/assessor-api/includes/class-assessor-etracs.php';
require_once ABSPATH . 'wp-content/plugins/assessor-api/includes/class-assessor-etracs-sync.php';

echo "\n=== Step 2: Triggering Controlled Shadow ETRACS Pull on 'test' ===\n";
$start_time = microtime(true);
$result = Assessor_Etracs_Sync::trigger_sync();
$duration = round(microtime(true) - $start_time, 2);

echo "Sync completed in {$duration} seconds.\n";
echo "Result:\n";
print_r($result);

// Collision preflight check on native properties after pull
$native_count_after = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}assessor_properties");
echo "\nNative wp_assessor_properties count after pull: " . $native_count_after . "\n";
if ($native_count_before !== $native_count_after) {
    echo "WARNING: Native properties row count changed!\n";
} else {
    echo "Integrity check: PASS (Native properties untouched)\n";
}

// Table row counts in test
$c_machine = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}assessor_machine");
$c_machdetail = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}assessor_machdetail");
$c_planttree = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}assessor_planttree");
$c_planttreedetail = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}assessor_planttreedetail");

echo "\nShadow ETRACS Table Counts in 'test':\n";
echo "wp_assessor_machine: " . $c_machine . " rows\n";
echo "wp_assessor_machdetail: " . $c_machdetail . " rows\n";
echo "wp_assessor_planttree: " . $c_planttree . " rows\n";
echo "wp_assessor_planttreedetail: " . $c_planttreedetail . " rows\n";
