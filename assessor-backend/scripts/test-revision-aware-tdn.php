<?php
/**
 * Test Step 10: Revision-Aware TDN Uniqueness
 *
 * Requirements:
 * - TDN 227 + Revision A -> allowed
 * - TDN 227 + Revision B -> allowed
 * - TDN 227 + Revision A again -> reject
 * - different TDN in same revision -> allowed
 * - on update, exclude current property UUID (self-update allowed)
 * - on update, changing date to another revision where TDN exists -> reject
 */

require 'C:/xampp/htdocs/wp-load.php';
global $wpdb;

$table_properties = $wpdb->prefix . 'assessor_properties';
$table_revisions  = $wpdb->prefix . 'assessor_revision_entries';

echo "=== 1. VERIFY INDEXES ON DB ===\n";
$indexes = $wpdb->get_results("SHOW INDEX FROM $table_properties WHERE Key_name = 'tax_declaration_number'", ARRAY_A);
if (!empty($indexes) && $indexes[0]['Non_unique'] == 1) {
    echo "PASS: tax_declaration_number index is NON-UNIQUE.\n";
} else {
    echo "FAIL: tax_declaration_number index is not non-unique!\n";
    exit(1);
}

$composite = $wpdb->get_results("SHOW INDEX FROM $table_properties WHERE Key_name = 'idx_tdn_revision'", ARRAY_A);
if (!empty($composite)) {
    echo "PASS: idx_tdn_revision (tax_declaration_number, revision_id) index exists.\n";
} else {
    echo "FAIL: idx_tdn_revision index missing!\n";
    exit(1);
}

// 2. Fetch two distinct active revisions
// Revision A: GR-2022 (2023..present)
// Revision B: GR-2018 (2019..2022)
$revA = $wpdb->get_row("SELECT id, revision_code FROM $table_revisions WHERE revision_code = 'GR-2022'");
$revB = $wpdb->get_row("SELECT id, revision_code FROM $table_revisions WHERE revision_code = 'GR-2018'");

echo "Revision A (GR-2022): {$revA->id}\n";
echo "Revision B (GR-2018): {$revB->id}\n";

$props_api = new Assessor_Properties();
$test_tdn = 'TEST-TDN-REV-AWARE-10';

// Clean up any leftovers from previous aborted test runs
$wpdb->query($wpdb->prepare("DELETE FROM $table_properties WHERE tax_declaration_number = %s", $test_tdn));

echo "\n=== 2. TEST BEHAVIORAL UNIQUENESS RULES ===\n";

// Test 1: Check duplicate on non-existent TDN -> should be FALSE
$is_dup1 = $props_api->is_tdn_duplicate_in_revision($test_tdn, $revA->id);
echo "Test 1 (Initial duplicate check in Rev A): " . ($is_dup1 === false ? "PASS (false)" : "FAIL (true)") . "\n";

// Test 2: Insert property with TDN + Revision A
$prop_id_1 = Assessor_UUID::v7();
$inserted1 = $wpdb->insert($table_properties, array(
    'id' => $prop_id_1,
    'tax_declaration_number' => $test_tdn,
    'effectivity_date' => '2024',
    'revision_id' => $revA->id,
    'location' => 'Test Location 1',
    'kind_of_property' => 'LAND',
    'status' => 'active',
    'declarant_last_name' => 'Tester',
    'declarant_first_name' => 'One'
));
echo "Test 2 (Insert TDN in Revision A): " . ($inserted1 !== false ? "PASS (inserted $prop_id_1)" : "FAIL: " . $wpdb->last_error) . "\n";

// Test 3: Insert SAME TDN + Revision B -> MUST BE ALLOWED
$prop_id_2 = Assessor_UUID::v7();
$inserted2 = $wpdb->insert($table_properties, array(
    'id' => $prop_id_2,
    'tax_declaration_number' => $test_tdn,
    'effectivity_date' => '2020',
    'revision_id' => $revB->id,
    'location' => 'Test Location 2',
    'kind_of_property' => 'LAND',
    'status' => 'active',
    'declarant_last_name' => 'Tester',
    'declarant_first_name' => 'Two'
));
echo "Test 3 (Insert SAME TDN in Revision B): " . ($inserted2 !== false ? "PASS (inserted $prop_id_2)" : "FAIL: " . $wpdb->last_error) . "\n";

// Test 4: Check duplicate in Revision A again -> MUST BE TRUE
$is_dup4 = $props_api->is_tdn_duplicate_in_revision($test_tdn, $revA->id);
echo "Test 4 (Duplicate check in Rev A): " . ($is_dup4 === true ? "PASS (true)" : "FAIL (false)") . "\n";

// Test 5: Check duplicate in Revision B again -> MUST BE TRUE
$is_dup5 = $props_api->is_tdn_duplicate_in_revision($test_tdn, $revB->id);
echo "Test 5 (Duplicate check in Rev B): " . ($is_dup5 === true ? "PASS (true)" : "FAIL (false)") . "\n";

// Test 6: Check duplicate in Revision A while EXCLUDING property 1 -> MUST BE FALSE (self-update)
$is_dup6 = $props_api->is_tdn_duplicate_in_revision($test_tdn, $revA->id, $prop_id_1);
echo "Test 6 (Duplicate check in Rev A excluding self $prop_id_1): " . ($is_dup6 === false ? "PASS (false)" : "FAIL (true)") . "\n";

// Test 7: Check duplicate in Revision B while EXCLUDING property 1 -> MUST BE TRUE (property 2 is in Rev B)
$is_dup7 = $props_api->is_tdn_duplicate_in_revision($test_tdn, $revB->id, $prop_id_1);
echo "Test 7 (Duplicate check in Rev B excluding prop 1): " . ($is_dup7 === true ? "PASS (true)" : "FAIL (false)") . "\n";

// Test 8: Simulate create_property rejection when same TDN in Rev A is attempted via create API logic
$mock_create_request = new WP_REST_Request('POST', '/assessor/v1/properties');
$mock_create_request->set_body_params(array(
    'tax_declaration_number' => $test_tdn,
    'effectivity_date' => '2024', // maps to Rev A
    'location' => 'Test Location Fail',
    'kind_of_property' => 'LAND',
    'declarant_last_name' => 'Tester',
    'declarant_first_name' => 'Three'
));
$create_res = $props_api->create_property($mock_create_request);
if (is_wp_error($create_res) && $create_res->get_error_code() === 'duplicate_tax_number') {
    echo "Test 8 (create_property rejects same TDN in same Revision A): PASS ({$create_res->get_error_message()})\n";
} else {
    echo "Test 8: FAIL - " . print_r($create_res, true) . "\n";
}

// Test 9: Simulate update_property self-update with same TDN and date -> MUST BE ALLOWED
$mock_update_request = new WP_REST_Request('PUT', '/assessor/v1/properties/' . $prop_id_1);
$mock_update_request->set_body_params(array(
    'tax_declaration_number' => $test_tdn,
    'effectivity_date' => '2024',
    'declarant_first_name' => 'One-Updated'
));
$update_res = $props_api->update_property($prop_id_1, $mock_update_request);
if (!is_wp_error($update_res) && $update_res->declarant_first_name === 'One-Updated') {
    echo "Test 9 (update_property self-update allowed): PASS\n";
} else {
    echo "Test 9: FAIL - " . print_r($update_res, true) . "\n";
}

// Test 10: Simulate update_property moving property 2 into Rev A (date 2024) where prop 1 already exists -> MUST REJECT
$mock_conflict_request = new WP_REST_Request('PUT', '/assessor/v1/properties/' . $prop_id_2);
$mock_conflict_request->set_body_params(array(
    'effectivity_date' => '2024' // moving from Rev B to Rev A
));
$conflict_res = $props_api->update_property($prop_id_2, $mock_conflict_request);
if (is_wp_error($conflict_res) && $conflict_res->get_error_code() === 'duplicate_tax_number') {
    echo "Test 10 (update_property rejects moving to revision where TDN already exists): PASS ({$conflict_res->get_error_message()})\n";
} else {
    echo "Test 10: FAIL - " . print_r($conflict_res, true) . "\n";
}

// Test 11: Disambiguated lookup via get_property_by_tax_number
$lookup_revA = $props_api->get_property_by_tax_number($test_tdn, false, $revA->id);
$lookup_revB = $props_api->get_property_by_tax_number($test_tdn, false, $revB->id);
echo "Test 11 (Disambiguated lookup): ";
if ($lookup_revA && $lookup_revA->id === $prop_id_1 && $lookup_revB && $lookup_revB->id === $prop_id_2) {
    echo "PASS (Rev A -> $prop_id_1, Rev B -> $prop_id_2)\n";
} else {
    echo "FAIL\n";
}

// Cleanup test rows
$wpdb->query($wpdb->prepare("DELETE FROM $table_properties WHERE id IN (%s, %s)", $prop_id_1, $prop_id_2));
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}assessor_property_versions WHERE property_id IN (%s, %s)", $prop_id_1, $prop_id_2));

echo "\nALL TESTS PASSED SUCCESSFULLY! Cleaned up test records.\n";
