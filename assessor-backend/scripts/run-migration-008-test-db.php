<?php
/**
 * Run Migration 008 against the active WordPress database (`test`) via Assessor_Migration_Runner
 * Loads the active WordPress environment directly.
 */
require_once 'C:/xampp/htdocs/wp-load.php';

global $wpdb;

echo "=== Step 1: Preflight Verification ===\n";
$current_db = $wpdb->get_var("SELECT DATABASE()");
echo "Active database: " . $current_db . "\n";
echo "Active table prefix: " . $wpdb->prefix . "\n";

if ($current_db !== 'test') {
    die("FATAL: Target database is not 'test'! Aborting.\n");
}
echo "Database verification: PASS (exact match 'test')\n";

// Check existing migration runner
require_once ABSPATH . 'wp-content/plugins/assessor-api/includes/class-assessor-database.php';
require_once ABSPATH . 'wp-content/plugins/assessor-api/includes/class-assessor-migration-runner.php';

$runner = new Assessor_Migration_Runner();

echo "\n=== Step 2: Running Migration 008 ===\n";
$migration_result = $runner->execute_migration('008_property_assessed_descriptions_schema');
echo "Migration 008 Execution Result:\n";
print_r($migration_result);

// Verify tables and columns
$table_machine = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}assessor_machine'");
$table_planttree = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}assessor_planttree'");
$table_planttreedetail = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}assessor_planttreedetail'");

echo "\nSchema verification in test:\n";
echo "wp_assessor_machine: " . ($table_machine ? "EXISTS" : "MISSING") . "\n";
echo "wp_assessor_planttree: " . ($table_planttree ? "EXISTS" : "MISSING") . "\n";
echo "wp_assessor_planttreedetail: " . ($table_planttreedetail ? "EXISTS" : "MISSING") . "\n";

$col_origin = $wpdb->get_var("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'test' AND TABLE_NAME = '{$wpdb->prefix}assessor_planttreedetail' AND COLUMN_NAME = 'origin_type'");
$idx_origin = $wpdb->get_var("SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = 'test' AND TABLE_NAME = '{$wpdb->prefix}assessor_planttreedetail' AND INDEX_NAME = 'idx_origin_type' LIMIT 1");

echo "wp_assessor_planttreedetail.origin_type column: " . ($col_origin ? "EXISTS" : "MISSING") . "\n";
echo "wp_assessor_planttreedetail.idx_origin_type index: " . ($idx_origin ? "EXISTS" : "MISSING") . "\n";

$status = $runner->get_status();
echo "\nRegistry Status in wp_assessor_migrations:\n";
foreach ($status->data as $item) {
    if ($item['name'] === '008_property_assessed_descriptions_schema') {
        echo "Migration 008 status: " . $item['status'] . " (completed_at: " . ($item['completed_at'] ?? 'N/A') . ")\n";
    }
}
