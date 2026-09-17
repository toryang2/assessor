<?php
require 'C:/xampp/htdocs/wp-load.php';
global $wpdb;

echo "PREPARING FRESH DATABASE FOR TESTS...\n";
// Disable FK checks momentarily ONLY to truncate tables cleanly for the fresh sync test
$wpdb->query("SET FOREIGN_KEY_CHECKS=0");
$wpdb->query("TRUNCATE TABLE {$wpdb->prefix}assessor_properties");
$wpdb->query("TRUNCATE TABLE {$wpdb->prefix}assessor_revision_entries");
$wpdb->query("TRUNCATE TABLE {$wpdb->prefix}assessor_property_states");
$wpdb->query("TRUNCATE TABLE {$wpdb->prefix}assessor_sync_queue");
$wpdb->query("SET FOREIGN_KEY_CHECKS=1");

Assessor_Sync::set_meta('last_pull_at', '2000-01-01 00:00:00');
Assessor_Sync::set_meta('pull_offset', 0);
Assessor_Sync::set_meta('last_pull_requests_at', '2000-01-01 00:00:00');
Assessor_Sync::set_meta('pull_requests_offset', 0);

echo "CLEANUP COMPLETE. STARTING MANUAL FULL SYNC...\n";
$start = microtime(true);
$result = Assessor_Sync::manual_sync(true);
$duration = round(microtime(true) - $start, 2);

echo "FULL SYNC FINISHED IN {$duration}s.\n";
echo "RESULT SUMMARY:\n";
echo "Success: " . ($result['success'] ? 'YES' : 'NO') . "\n";
echo "Message: " . ($result['message'] ?? '') . "\n";
echo "Revisions pulled & upserted: " . ($result['revisions']['upserted'] ?? 0) . " / " . ($result['revisions']['count'] ?? 0) . "\n";
echo "Revisions success: " . ($result['revisions']['success'] ? 'YES' : 'NO') . "\n";
echo "Properties pulled: " . ($result['pull']['pulled'] ?? 0) . "\n";
echo "Properties skipped: " . ($result['pull']['skipped'] ?? 0) . "\n";
echo "Properties errors count: " . count($result['pull']['errors'] ?? []) . "\n";

if (!empty($result['pull']['errors'])) {
    echo "First 10 pull errors:\n";
    foreach (array_slice($result['pull']['errors'], 0, 10) as $e) {
        echo "  - $e\n";
    }
}

// Database verification queries
$total_props = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}assessor_properties");
$props_null_rev = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}assessor_properties WHERE revision_id IS NULL");
$props_not_null_rev = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}assessor_properties WHERE revision_id IS NOT NULL");
$total_revs = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}assessor_revision_entries");
$total_states = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}assessor_property_states");

echo "\nDATABASE VERIFICATION:\n";
echo "Total revision entries: $total_revs\n";
echo "Total properties: $total_props\n";
echo "Properties with revision_id = NULL: $props_null_rev\n";
echo "Properties with revision_id != NULL: $props_not_null_rev\n";
echo "Total property states: $total_states\n";

// Check for any remaining fk errors
$fk_errors = 0;
if (!empty($result['pull']['errors'])) {
    foreach ($result['pull']['errors'] as $err) {
        if (strpos($err, 'fk_properties_revision_id') !== false) {
            $fk_errors++;
        }
    }
}
echo "FK constraint errors in pull: $fk_errors\n";
