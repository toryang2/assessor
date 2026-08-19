<?php
$_SERVER['HTTP_HOST'] = 'localhost';
require_once 'd:\CODE\assessor\assessor-backend\wp-load.php';

$api = new Assessor_Properties();
$user_id = 1;

global $wpdb;

// Cleanup old tests
$wpdb->query("DELETE FROM {$wpdb->prefix}assessor_properties WHERE tax_declaration_number LIKE 'TEST-TD-%'");
$wpdb->query("DELETE FROM {$wpdb->prefix}assessor_property_states WHERE property_id NOT IN (SELECT id FROM {$wpdb->prefix}assessor_properties)");

// Create TD-1
$td1 = $api->create_property([
    'tax_declaration_number' => 'TEST-TD-1',
    'declarant_last_name' => 'TEST',
    'area_sqm' => 100,
    'kind_of_property' => 'LAND'
], $user_id);
$td1_id = $td1->id;
$api->update_property_state($td1_id, 'CURRENT', null);

// Create TD-2 (with previous=TD-1)
$td2 = $api->create_property([
    'tax_declaration_number' => 'TEST-TD-2',
    'previous_tax_declaration_number' => 'TEST-TD-1',
    'declarant_last_name' => 'TEST',
    'area_sqm' => 100,
    'kind_of_property' => 'LAND'
], $user_id);
$td2_id = $td2->id;

// Note: at this exact point, TD-2 is CURRENT because create_property cancels previous TDs assuming it's CURRENT.
// Let's call update_property_state to set it to INTERIM
$api->update_property_state($td2_id, 'INTERIM', null);

$state1 = $wpdb->get_var("SELECT state FROM {$wpdb->prefix}assessor_property_states WHERE property_id = $td1_id");
$state2 = $wpdb->get_var("SELECT state FROM {$wpdb->prefix}assessor_property_states WHERE property_id = $td2_id");

echo "TEST 1: Creating TD-2 as INTERIM (previous=TD-1)\n";
echo "TD-1 State: $state1 (Expected: CURRENT)\n";
echo "TD-2 State: $state2 (Expected: INTERIM)\n";
echo "-----------------------\n";

// Now edit TD-2 (which is INTERIM) and save it again as INTERIM
$api->update_property($td2_id, new WP_REST_Request('POST', '')); // mock request without params? Wait, update_property uses request params.
// Let's just simulate the API call sequence manually.
// update_property($td2_id, $req);
// update_property_state($td2_id, 'INTERIM', null);

