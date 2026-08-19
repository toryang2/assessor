<?php
$_SERVER['HTTP_HOST'] = 'localhost';
require_once 'd:\CODE\assessor\assessor-backend\wp-load.php';

$api = new Assessor_Properties();
$user_id = 1;

// Cleanup old tests
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->prefix}assessor_properties WHERE tax_declaration_number LIKE 'TEST-TD-%'");
$wpdb->query("DELETE FROM {$wpdb->prefix}assessor_property_states WHERE property_id NOT IN (SELECT id FROM {$wpdb->prefix}assessor_properties)");

// Create TD-1
$td1 = $api->create_property([
    'tax_declaration_number' => 'TEST-TD-1',
    'declarant_last_name' => 'TEST',
    'area_sqm' => 100,
    'kind_of_property' => 'LAND'
], $user_id);
if (is_wp_error($td1)) { die("Error: " . $td1->get_error_message()); }
$td1_id = $td1->id;
$api->update_property_state($td1_id, 'CURRENT', null);
echo "TD-1 Created and set to CURRENT.\n";

// Create TD-2 as INTERIM superseding TD-1
$td2 = $api->create_property([
    'tax_declaration_number' => 'TEST-TD-2',
    'previous_tax_declaration_number' => 'TEST-TD-1',
    'declarant_last_name' => 'TEST',
    'area_sqm' => 100,
    'kind_of_property' => 'LAND'
], $user_id);
if (is_wp_error($td2)) { die("Error: " . $td2->get_error_message()); }
$td2_id = $td2->id;
$api->update_property_state($td2_id, 'INTERIM', null);
echo "TD-2 Created and set to INTERIM.\n";

$state1 = $wpdb->get_var("SELECT state FROM {$wpdb->prefix}assessor_property_states WHERE property_id = $td1_id");
$state2 = $wpdb->get_var("SELECT state FROM {$wpdb->prefix}assessor_property_states WHERE property_id = $td2_id");
echo "After creating TD-2 (INTERIM):\n";
echo "  TD-1 State: $state1 (Expected: CURRENT)\n";
echo "  TD-2 State: $state2 (Expected: INTERIM)\n";

// Change TD-2 to CURRENT
$api->update_property_state($td2_id, 'CURRENT', null);
$state1 = $wpdb->get_var("SELECT state FROM {$wpdb->prefix}assessor_property_states WHERE property_id = $td1_id");
$state2 = $wpdb->get_var("SELECT state FROM {$wpdb->prefix}assessor_property_states WHERE property_id = $td2_id");
echo "After changing TD-2 to CURRENT:\n";
echo "  TD-1 State: $state1 (Expected: CANCELLED)\n";
echo "  TD-2 State: $state2 (Expected: CURRENT)\n";

// Change TD-2 back to INTERIM
$api->update_property_state($td2_id, 'INTERIM', null);
$state1 = $wpdb->get_var("SELECT state FROM {$wpdb->prefix}assessor_property_states WHERE property_id = $td1_id");
$state2 = $wpdb->get_var("SELECT state FROM {$wpdb->prefix}assessor_property_states WHERE property_id = $td2_id");
echo "After changing TD-2 back to INTERIM:\n";
echo "  TD-1 State: $state1 (Expected: CURRENT)\n";
echo "  TD-2 State: $state2 (Expected: INTERIM)\n";
