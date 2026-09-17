<?php
require 'C:/xampp/htdocs/wp-load.php';
global $wpdb;

// Test 2 — Verify revision identity
$sample = $wpdb->get_row("SELECT id, tax_declaration_number, revision_id FROM {$wpdb->prefix}assessor_properties WHERE revision_id IS NOT NULL LIMIT 1", ARRAY_A);
echo "SAMPLE LOCAL PROPERTY:\n";
print_r($sample);

$rev_entry = $wpdb->get_row($wpdb->prepare("SELECT id, revision_code FROM {$wpdb->prefix}assessor_revision_entries WHERE id = %s", $sample['revision_id']), ARRAY_A);
echo "\nMATCHING LOCAL REVISION ENTRY:\n";
print_r($rev_entry);

// Test 4 — Verify state
$state = $wpdb->get_var($wpdb->prepare("SELECT state FROM {$wpdb->prefix}assessor_property_states WHERE property_id = %s", $sample['id']));
echo "\nLOCAL PROPERTY STATE for property {$sample['id']}: $state\n";
