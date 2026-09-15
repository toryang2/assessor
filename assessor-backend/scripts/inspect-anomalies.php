<?php
require 'C:/xampp/htdocs/wp-load.php';
global $wpdb;

$table_props = $wpdb->prefix . 'assessor_properties';

$results = $wpdb->get_results("SELECT id, tax_declaration_number, effectivity_date, assessment_date, created_at, status FROM $table_props WHERE effectivity_date IN ('1963', '2923', '20023', '20199', '20230', '202', '201+', '2023-', '29,500.00', '-', 'EXEMPT', 'EXEMPT.') OR effectivity_date IS NULL OR effectivity_date = ''", ARRAY_A);

echo "Total unmappable/anomalous properties: " . count($results) . "\n";
foreach ($results as $r) {
    echo "ID: {$r['id']} | TDN: {$r['tax_declaration_number']} | Date: '{$r['effectivity_date']}' | AssessDate: '{$r['assessment_date']}' | Status: {$r['status']}\n";
}
