<?php
/**
 * Test script for Step 11: Fix TDN Lookup/History Ambiguity
 *
 * Validates:
 * 1. Exact property operations use property UUID or disambiguated TDN+revision.
 * 2. TDN history traversal returns multiple records when the same TDN exists across revisions.
 * 3. Auto-cancellation of previous TDNs updates all matching properties instead of picking an arbitrary row with LIMIT 1.
 * 4. Revert cancelled states restores all matching properties when no longer superseded.
 * 5. get_property_by_tax_number supports all_matches=true and revision_id filtering.
 */

define('WP_USE_THEMES', false);
require 'C:/xampp/htdocs/wp-load.php';

echo "=== Test Step 11: Fix TDN Lookup/History Ambiguity ===\n\n";

global $wpdb;
$table_properties = $wpdb->prefix . 'assessor_properties';
$table_states = $wpdb->prefix . 'assessor_property_states';
$table_revisions = $wpdb->prefix . 'assessor_revision_entries';

$props_api = new Assessor_Properties();

// Fetch two distinct active revisions
$revA = $wpdb->get_row("SELECT * FROM $table_revisions WHERE from_year <= 2020 AND to_year >= 2020 AND status = 'active' LIMIT 1");
$revB = $wpdb->get_row("SELECT * FROM $table_revisions WHERE from_year <= 2024 AND (to_year >= 2024 OR to_year = 'present' OR to_year IS NULL) AND status = 'active' LIMIT 1");

if (!$revA || !$revB || $revA->id === $revB->id) {
    echo "❌ Error: Need two distinct active revisions for testing.\n";
    exit(1);
}

echo "Using Revision A: {$revA->revision_code} ({$revA->from_year}-{$revA->to_year}) [{$revA->id}]\n";
echo "Using Revision B: {$revB->revision_code} ({$revB->from_year}-{$revB->to_year}) [{$revB->id}]\n\n";

$test_tdn = 'AMBIG-TEST-' . time();
$child_tdn = 'AMBIG-CHILD-' . time();

$cleanup_ids = array();

function cleanup($ids, $table_properties, $table_states, $wpdb) {
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '%s'));
        $wpdb->query($wpdb->prepare("DELETE FROM $table_properties WHERE id IN ($placeholders)", $ids));
        $wpdb->query($wpdb->prepare("DELETE FROM $table_states WHERE property_id IN ($placeholders)", $ids));
    }
}

try {
    // -------------------------------------------------------------
    // 1. Create Property 1 in Revision A
    // -------------------------------------------------------------
    $req1 = new WP_REST_Request('POST', '/assessor/v1/properties');
    $req1->set_body_params(array(
        'tax_declaration_number' => $test_tdn,
        'effectivity_date' => '2020',
        'location' => 'Test Location A',
        'kind_of_property' => 'LAND',
        'declarant_last_name' => 'Owner RevA',
        'declarant_first_name' => 'John',
    ));
    $res1 = $props_api->create_property($req1);
    if (is_wp_error($res1)) {
        throw new Exception("Failed to create Prop 1: " . $res1->get_error_message());
    }
    $id1 = $res1->id;
    $cleanup_ids[] = $id1;
    echo "✓ Test 1: Created Prop 1 in Rev A (UUID: $id1, TDN: $test_tdn)\n";

    // -------------------------------------------------------------
    // 2. Create Property 2 with SAME TDN in Revision B
    // -------------------------------------------------------------
    $req2 = new WP_REST_Request('POST', '/assessor/v1/properties');
    $req2->set_body_params(array(
        'tax_declaration_number' => $test_tdn,
        'effectivity_date' => '2024',
        'location' => 'Test Location B',
        'kind_of_property' => 'LAND',
        'declarant_last_name' => 'Owner RevB',
        'declarant_first_name' => 'Jane',
    ));
    $res2 = $props_api->create_property($req2);
    if (is_wp_error($res2)) {
        throw new Exception("Failed to create Prop 2: " . $res2->get_error_message());
    }
    $id2 = $res2->id;
    $cleanup_ids[] = $id2;
    echo "✓ Test 2: Created Prop 2 with same TDN in Rev B (UUID: $id2, TDN: $test_tdn)\n";

    // -------------------------------------------------------------
    // 3. Exact Lookup & Disambiguation (Category A)
    // -------------------------------------------------------------
    // Lookup by UUID
    $prop_by_uuid1 = $props_api->get_property($id1);
    $prop_by_uuid2 = $props_api->get_property($id2);
    if ($prop_by_uuid1->id !== $id1 || $prop_by_uuid2->id !== $id2) {
        throw new Exception("Exact UUID lookup failed to return matching property.");
    }
    echo "✓ Test 3a: Exact lookup by UUID returns correct distinct properties.\n";

    // Disambiguated lookup by TDN + Revision ID
    $prop_revA = $props_api->get_property_by_tax_number($test_tdn, false, $revA->id);
    $prop_revB = $props_api->get_property_by_tax_number($test_tdn, false, $revB->id);
    if ($prop_revA->id !== $id1 || $prop_revB->id !== $id2) {
        throw new Exception("Disambiguated lookup by revision_id failed.");
    }
    echo "✓ Test 3b: Disambiguated lookup by (TDN + revision_id) correctly resolved Prop 1 and Prop 2.\n";

    // Multi-match lookup with all_matches = true
    $all_matches = $props_api->get_property_by_tax_number($test_tdn, false, null, true);
    if (count($all_matches) !== 2) {
        throw new Exception("all_matches expected 2 properties, got " . count($all_matches));
    }
    echo "✓ Test 3c: get_property_by_tax_number with all_matches=true returned both properties without LIMIT 1 truncation.\n";

    // -------------------------------------------------------------
    // 4. History / Group Lookup (Category B)
    // -------------------------------------------------------------
    $history = $props_api->get_tax_declaration_history($test_tdn);
    $history_ids = array_column($history, 'id');
    if (!in_array($id1, $history_ids) || !in_array($id2, $history_ids)) {
        throw new Exception("get_tax_declaration_history failed to return both revision records. Returned IDs: " . implode(', ', $history_ids));
    }
    echo "✓ Test 4: get_tax_declaration_history returned BOTH properties across revisions for TDN $test_tdn.\n";

    // -------------------------------------------------------------
    // 5. Lineage & Auto-cancellation of Previous TDN (Category C)
    // -------------------------------------------------------------
    // Verify initial states are CURRENT
    $state1_before = $wpdb->get_var($wpdb->prepare("SELECT state FROM $table_states WHERE property_id = %s", $id1));
    $state2_before = $wpdb->get_var($wpdb->prepare("SELECT state FROM $table_states WHERE property_id = %s", $id2));
    echo "Initial states: Prop1 = $state1_before, Prop2 = $state2_before\n";

    // Create a child property superseding $test_tdn
    $req3 = new WP_REST_Request('POST', '/assessor/v1/properties');
    $req3->set_body_params(array(
        'tax_declaration_number' => $child_tdn,
        'previous_tax_declaration_number' => $test_tdn,
        'effectivity_date' => '2024',
        'location' => 'Test Location C',
        'kind_of_property' => 'LAND',
        'declarant_last_name' => 'New Child Owner',
        'declarant_first_name' => 'Bob',
    ));
    $res3 = $props_api->create_property($req3);
    if (is_wp_error($res3)) {
        throw new Exception("Failed to create Child Prop: " . $res3->get_error_message());
    }
    $id3 = $res3->id;
    $cleanup_ids[] = $id3;

    // Both Prop 1 and Prop 2 must now be CANCELLED (not just one chosen via LIMIT 1)
    $state1_after = $wpdb->get_var($wpdb->prepare("SELECT state FROM $table_states WHERE property_id = %s", $id1));
    $state2_after = $wpdb->get_var($wpdb->prepare("SELECT state FROM $table_states WHERE property_id = %s", $id2));
    if ($state1_after !== 'CANCELLED' || $state2_after !== 'CANCELLED') {
        throw new Exception("Expected BOTH Prop 1 and Prop 2 to be CANCELLED, but got Prop 1: $state1_after, Prop 2: $state2_after");
    }
    echo "✓ Test 5: Child property creation cancelled ALL matching previous TDN rows (Prop 1: CANCELLED, Prop 2: CANCELLED).\n";

    // -------------------------------------------------------------
    // 6. Lineage Revert on Child Deletion (Category C)
    // -------------------------------------------------------------
    $del_req = new WP_REST_Request('DELETE', "/assessor/v1/properties/$id3");
    $del_res = $props_api->delete_property($id3, $del_req);
    if (is_wp_error($del_res)) {
        throw new Exception("Failed to delete child property: " . $del_res->get_error_message());
    }

    $state1_revert = $wpdb->get_var($wpdb->prepare("SELECT state FROM $table_states WHERE property_id = %s", $id1));
    $state2_revert = $wpdb->get_var($wpdb->prepare("SELECT state FROM $table_states WHERE property_id = %s", $id2));
    if ($state1_revert !== 'CURRENT' || $state2_revert !== 'CURRENT') {
        throw new Exception("Expected BOTH Prop 1 and Prop 2 to revert to CURRENT, but got Prop 1: $state1_revert, Prop 2: $state2_revert");
    }
    echo "✓ Test 6: Deleting superseding child property reverted ALL matching TDN properties back to CURRENT.\n";

    // -------------------------------------------------------------
    // 7. REST API Controller Endpoint Disambiguation
    // -------------------------------------------------------------
    $api = new Assessor_API();
    $api_req_all = new WP_REST_Request('GET', "/assessor/v1/properties/by-tax-number/$test_tdn");
    $api_req_all->set_param('tax_number', $test_tdn);
    $api_req_all->set_param('all', 'true');
    $api_res_all = $api->get_property_by_tax_number($api_req_all);
    if (!is_array($api_res_all) || count($api_res_all) !== 2) {
        throw new Exception("REST API endpoint with ?all=true failed to return 2 properties.");
    }

    $api_req_revA = new WP_REST_Request('GET', "/assessor/v1/properties/by-tax-number/$test_tdn");
    $api_req_revA->set_param('tax_number', $test_tdn);
    $api_req_revA->set_param('revision_id', $revA->id);
    $api_res_revA = $api->get_property_by_tax_number($api_req_revA);
    if ($api_res_revA->id !== $id1) {
        throw new Exception("REST API endpoint with ?revision_id= failed to return Prop 1.");
    }
    echo "✓ Test 7: REST API /properties/by-tax-number handles ?all=true and ?revision_id= correctly.\n";

    echo "\n🎉 ALL TESTS PASSED SUCCESSFULLY!\n";

} catch (Exception $e) {
    echo "\n❌ TEST FAILED: " . $e->getMessage() . "\n";
} finally {
    cleanup($cleanup_ids, $table_properties, $table_states, $wpdb);
    echo "Cleaned up " . count($cleanup_ids) . " test properties.\n";
}
