<?php
require_once 'd:\CODE\assessor\assessor-backend\wp-load.php';

$api = new Assessor_Properties();

// Create TD-1
$td1 = $api->create_property([
    'tax_declaration_number' => 'TD-TEST-1',
    'declarant_last_name' => 'TEST',
    'area_sqm' => 100
], 1);
echo "Created TD-1: ID=" . $td1->id . "\n";

// Update state of TD-1 to CURRENT explicitly
$api->update_property_state($td1->id, 'CURRENT', 1);

// Create TD-2 with previous TD-1
$td2 = $api->create_property([
    'tax_declaration_number' => 'TD-TEST-2',
    'previous_tax_declaration_number' => 'TD-TEST-1',
    'declarant_last_name' => 'TEST2',
    'area_sqm' => 100
], 1);
echo "Created TD-2: ID=" . $td2->id . "\n";

// Check state of TD-1
global $wpdb;
$state1 = $wpdb->get_var("SELECT state FROM {$wpdb->prefix}assessor_property_states WHERE property_id = {$td1->id}");
echo "State of TD-1 after TD-2 created: " . ($state1 ?: 'CURRENT') . "\n";

// Update state of TD-2 to CURRENT
$api->update_property_state($td2->id, 'CURRENT', 1);

$state1 = $wpdb->get_var("SELECT state FROM {$wpdb->prefix}assessor_property_states WHERE property_id = {$td1->id}");
echo "State of TD-1 after TD-2 update state to CURRENT: " . ($state1 ?: 'CURRENT') . "\n";

// Create TD-3 with previous TD-2 (but set to INTERIM)
$td3 = $api->create_property([
    'tax_declaration_number' => 'TD-TEST-3',
    'previous_tax_declaration_number' => 'TD-TEST-2',
    'declarant_last_name' => 'TEST3',
    'area_sqm' => 100
], 1);
echo "Created TD-3: ID=" . $td3->id . "\n";

$api->update_property_state($td3->id, 'INTERIM', 1);

$state2 = $wpdb->get_var("SELECT state FROM {$wpdb->prefix}assessor_property_states WHERE property_id = {$td2->id}");
echo "State of TD-2 after TD-3 created as INTERIM: " . ($state2 ?: 'CURRENT') . "\n";
