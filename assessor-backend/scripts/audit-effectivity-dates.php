<?php
require 'C:/xampp/htdocs/wp-load.php';
global $wpdb;

echo "=== ACTIVE REVISIONS ===" . PHP_EOL;
$revisions = $wpdb->get_results("SELECT id, revision_code, revision_year, from_year, to_year, status FROM {$wpdb->prefix}assessor_revision_entries ORDER BY from_year ASC", ARRAY_A);
foreach ($revisions as $r) {
    echo "ID: {$r['id']} | Code: {$r['revision_code']} | Year: {$r['revision_year']} | From: {$r['from_year']} | To: {$r['to_year']} | Status: {$r['status']}\n";
}

echo "\n=== DISTINCT EFFECTIVITY DATES IN PROPERTIES ===" . PHP_EOL;
$dates = $wpdb->get_results("SELECT effectivity_date, COUNT(*) as cnt FROM {$wpdb->prefix}assessor_properties GROUP BY effectivity_date ORDER BY effectivity_date ASC", ARRAY_A);
echo "Total distinct effectivity dates: " . count($dates) . "\n";
foreach ($dates as $d) {
    echo "Date: '{$d['effectivity_date']}' | Count: {$d['cnt']}\n";
}
