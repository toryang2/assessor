<?php
require 'C:/xampp/htdocs/wp-load.php';
global $wpdb;

echo "=== RESUMING SYNC FROM OFFSET " . Assessor_Sync::get_meta('pull_offset') . " ===\n";
$start = microtime(true);
$res = Assessor_Sync::manual_sync(false);
$duration = round(microtime(true) - $start, 2);

echo "COMPLETED IN {$duration}s.\n";
echo "Success: " . ($res['success'] ? 'YES' : 'NO') . "\n";
echo "Pulled this run: " . ($res['pull']['pulled'] ?? 0) . "\n";
echo "Skipped: " . ($res['pull']['skipped'] ?? 0) . "\n";
echo "Errors: " . count($res['pull']['errors'] ?? []) . "\n";
if (!empty($res['pull']['errors'])) {
    foreach (array_slice($res['pull']['errors'], 0, 5) as $err) {
        echo "  - $err\n";
    }
}

$props = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}assessor_properties");
$null_rev = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}assessor_properties WHERE revision_id IS NULL");
$not_null_rev = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}assessor_properties WHERE revision_id IS NOT NULL");
$revs = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}assessor_revision_entries");
$offset = Assessor_Sync::get_meta('pull_offset');

echo "\nTOTAL DATABASE STATUS:\n";
echo "Total Revisions: $revs\n";
echo "Total Properties: $props\n";
echo "Properties revision_id NULL: $null_rev\n";
echo "Properties revision_id NOT NULL: $not_null_rev\n";
echo "Current pull_offset: $offset\n";
