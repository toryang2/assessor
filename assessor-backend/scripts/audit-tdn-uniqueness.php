<?php
require 'C:/xampp/htdocs/wp-load.php';
global $wpdb;

$table = $wpdb->prefix . 'assessor_properties';

echo "=== CHECKING CURRENT TDN INDEXES ===\n";
$indexes = $wpdb->get_results("SHOW INDEX FROM $table WHERE Column_name = 'tax_declaration_number'", ARRAY_A);
foreach ($indexes as $idx) {
    echo "Key_name: {$idx['Key_name']} | Non_unique: {$idx['Non_unique']} | Seq: {$idx['Seq_in_index']}\n";
}

echo "\n=== CHECKING EXISTING DUPLICATE TDNs (ANY STATUS) ===\n";
$dups = $wpdb->get_results("
    SELECT tax_declaration_number, COUNT(*) as cnt, GROUP_CONCAT(status) as statuses, GROUP_CONCAT(revision_id) as revs
    FROM $table
    GROUP BY tax_declaration_number
    HAVING cnt > 1
", ARRAY_A);
echo "Total TDNs with multiple rows: " . count($dups) . "\n";
foreach (array_slice($dups, 0, 10) as $d) {
    echo "TDN: '{$d['tax_declaration_number']}' | Count: {$d['cnt']} | Statuses: {$d['statuses']} | Revisions: {$d['revs']}\n";
}

echo "\n=== CHECKING EXISTING (TDN + revision_id) DUPLICATES (ANY STATUS) ===\n";
$rev_dups = $wpdb->get_results("
    SELECT tax_declaration_number, revision_id, COUNT(*) as cnt, GROUP_CONCAT(status) as statuses
    FROM $table
    WHERE revision_id IS NOT NULL
    GROUP BY tax_declaration_number, revision_id
    HAVING cnt > 1
", ARRAY_A);
echo "Total (TDN + revision_id) with multiple rows: " . count($rev_dups) . "\n";
foreach (array_slice($rev_dups, 0, 10) as $rd) {
    echo "TDN: '{$rd['tax_declaration_number']}' | Revision: {$rd['revision_id']} | Count: {$rd['cnt']} | Statuses: {$rd['statuses']}\n";
}

echo "\n=== CHECKING EXISTING (TDN + revision_id) DUPLICATES (status != 'deleted') ===\n";
$active_rev_dups = $wpdb->get_results("
    SELECT tax_declaration_number, revision_id, COUNT(*) as cnt, GROUP_CONCAT(id) as ids
    FROM $table
    WHERE revision_id IS NOT NULL AND status != 'deleted'
    GROUP BY tax_declaration_number, revision_id
    HAVING cnt > 1
", ARRAY_A);
echo "Total active (TDN + revision_id) with multiple rows: " . count($active_rev_dups) . "\n";
