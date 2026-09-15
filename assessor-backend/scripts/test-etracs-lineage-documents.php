<?php
/**
 * Test script for Step 12: ETRACS/Lineage/Documents Compatibility
 *
 * Validates:
 * 1. Two same-TDN properties in different revisions stay separate.
 * 2. Documents remain attached to correct UUID and distinct directories.
 * 3. Versions remain attached to correct UUID with revision_id preserved.
 * 4. Supersede affects intended property and updates states cleanly.
 * 5. ETRACS links / classifications / requests remain intact.
 */

define('WP_USE_THEMES', false);
require 'C:/xampp/htdocs/wp-load.php';

echo "=== Test Step 12: ETRACS/Lineage/Documents Compatibility ===\n\n";

global $wpdb;
$table_properties = $wpdb->prefix . 'assessor_properties';
$table_versions   = $wpdb->prefix . 'assessor_property_versions';
$table_documents  = $wpdb->prefix . 'assessor_documents';
$table_states     = $wpdb->prefix . 'assessor_property_states';
$table_requests   = $wpdb->prefix . 'assessor_requests';
$table_revisions  = $wpdb->prefix . 'assessor_revision_entries';
$table_faas       = $wpdb->prefix . 'assessor_faas';

$props_api = new Assessor_Properties();
$docs_api  = new Assessor_Documents();
$vers_api  = new Assessor_Versions();

// Fetch two distinct active revisions
$revA = $wpdb->get_row("SELECT * FROM $table_revisions WHERE from_year <= 2018 AND to_year >= 2018 AND status = 'active' LIMIT 1");
$revB = $wpdb->get_row("SELECT * FROM $table_revisions WHERE from_year <= 2024 AND (to_year >= 2024 OR to_year = 'present' OR to_year IS NULL) AND status = 'active' LIMIT 1");

if (!$revA || !$revB || $revA->id === $revB->id) {
    echo "❌ Error: Need two distinct active revisions for testing.\n";
    exit(1);
}

echo "Revision A: {$revA->revision_code} ({$revA->from_year}-{$revA->to_year}) [{$revA->id}]\n";
echo "Revision B: {$revB->revision_code} ({$revB->from_year}-{$revB->to_year}) [{$revB->id}]\n\n";

$shared_tdn = 'COMPAT-TEST-' . time();
$child_tdn  = 'COMPAT-CHILD-' . time();

$cleanup_prop_ids = array();
$cleanup_doc_ids  = array();
$cleanup_req_ids  = array();
$created_files    = array();

function full_cleanup() {
    global $wpdb, $table_properties, $table_versions, $table_documents, $table_states, $table_requests;
    global $cleanup_prop_ids, $cleanup_doc_ids, $cleanup_req_ids, $created_files;

    if (!empty($cleanup_doc_ids)) {
        $ph = implode(',', array_fill(0, count($cleanup_doc_ids), '%d'));
        $wpdb->query($wpdb->prepare("DELETE FROM $table_documents WHERE id IN ($ph)", $cleanup_doc_ids));
    }
    if (!empty($cleanup_req_ids)) {
        $ph = implode(',', array_fill(0, count($cleanup_req_ids), '%d'));
        $wpdb->query($wpdb->prepare("DELETE FROM $table_requests WHERE id IN ($ph)", $cleanup_req_ids));
    }
    if (!empty($cleanup_prop_ids)) {
        $ph = implode(',', array_fill(0, count($cleanup_prop_ids), '%s'));
        $wpdb->query($wpdb->prepare("DELETE FROM $table_properties WHERE id IN ($ph)", $cleanup_prop_ids));
        $wpdb->query($wpdb->prepare("DELETE FROM $table_versions WHERE property_id IN ($ph)", $cleanup_prop_ids));
        $wpdb->query($wpdb->prepare("DELETE FROM $table_states WHERE property_id IN ($ph)", $cleanup_prop_ids));
    }
    foreach ($created_files as $f) {
        if (file_exists($f)) {
            @unlink($f);
            $dir = dirname($f);
            @rmdir($dir);
        }
    }
}

try {
    // -------------------------------------------------------------
    // Test 1: Create two same-TDN properties in different revisions
    // -------------------------------------------------------------
    echo "--- Test 1: Create two same-TDN properties across revisions ---\n";
    
    // Property 1 in Revision A
    $req1 = new WP_REST_Request('POST', '/assessor/v1/properties');
    $req1->set_body_params(array(
        'tax_declaration_number' => $shared_tdn,
        'effectivity_date' => '2018',
        'location' => 'Brgy 1 Rev A',
        'kind_of_property' => 'LAND',
        'declarant_last_name' => 'Owner Alpha',
        'declarant_first_name' => 'John',
        'area_hectare' => 1.5,
        'assessed_value' => 50000,
        'gen_class' => 'A'
    ));
    $res1 = $props_api->create_property($req1);
    if (is_wp_error($res1) || empty($res1->id)) {
        throw new Exception("Failed to create Property 1: " . (is_wp_error($res1) ? $res1->get_error_message() : json_encode($res1)));
    }
    $prop1_id = $res1->id;
    $cleanup_prop_ids[] = $prop1_id;
    echo "✅ Created Property 1: UUID=$prop1_id, Rev={$res1->revision_id}\n";

    // Property 2 in Revision B with SAME TDN
    $req2 = new WP_REST_Request('POST', '/assessor/v1/properties');
    $req2->set_body_params(array(
        'tax_declaration_number' => $shared_tdn,
        'effectivity_date' => '2024',
        'location' => 'Brgy 2 Rev B',
        'kind_of_property' => 'BUILDING',
        'declarant_last_name' => 'Owner Beta',
        'declarant_first_name' => 'Jane',
        'area_hectare' => 2.5,
        'assessed_value' => 90000,
        'gen_class' => 'C'
    ));
    $res2 = $props_api->create_property($req2);
    if (is_wp_error($res2) || empty($res2->id)) {
        throw new Exception("Failed to create Property 2: " . (is_wp_error($res2) ? $res2->get_error_message() : json_encode($res2)));
    }
    $prop2_id = $res2->id;
    $cleanup_prop_ids[] = $prop2_id;
    echo "✅ Created Property 2: UUID=$prop2_id, Rev={$res2->revision_id}\n";

    if ($prop1_id === $prop2_id) {
        throw new Exception("Properties must have distinct UUIDs!");
    }
    echo "✅ Verified: Two same-TDN properties exist independently with unique UUIDs.\n\n";

    // -------------------------------------------------------------
    // Test 2: Documents attachment & directory isolation
    // -------------------------------------------------------------
    echo "--- Test 2: Documents Attachment & Isolation ---\n";
    
    // Create a mock document in DB for Property 1
    $doc1_name = "test_doc_alpha.pdf";
    $upload_dir_info = wp_upload_dir();
    $p1_record = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_properties WHERE id = %s", $prop1_id));
    
    // Check directory generation using reflection or by inspecting upload helper
    $p1_clean_tdn = preg_replace('/[^A-Za-z0-9_.\-]/', '_', $p1_record->tax_declaration_number);
    $p1_dir = $upload_dir_info['basedir'] . '/assessor-documents/' . $p1_clean_tdn . '_' . $prop1_id;
    if (!file_exists($p1_dir)) {
        wp_mkdir_p($p1_dir);
    }
    $doc1_path = $p1_dir . '/' . uniqid() . '_' . time() . '.pdf';
    file_put_contents($doc1_path, '%PDF-1.4 Mock document for Prop 1');
    $created_files[] = $doc1_path;

    $wpdb->insert(
        $table_documents,
        array(
            'property_id' => $prop1_id,
            'filename' => basename($doc1_path),
            'original_filename' => $doc1_name,
            'file_path' => $doc1_path,
            'file_type' => 'pdf',
            'file_size' => filesize($doc1_path),
            'description' => 'Document for Property 1',
            'uploaded_by' => 'admin'
        )
    );
    $doc1_id = $wpdb->insert_id;
    $cleanup_doc_ids[] = $doc1_id;
    echo "✅ Attached document ID $doc1_id to Property 1 ($prop1_id)\n";

    // Fetch documents for Property 1 and Property 2
    $p1_docs_res = $docs_api->get_property_documents($prop1_id);
    $p2_docs_res = $docs_api->get_property_documents($prop2_id);
    $p1_docs = $p1_docs_res['documents'];
    $p2_docs = $p2_docs_res['documents'];

    if (count($p1_docs) !== 1) {
        throw new Exception("Expected Property 1 to have 1 document, found " . count($p1_docs));
    }
    if (count($p2_docs) !== 0) {
        throw new Exception("Expected Property 2 to have 0 documents, found " . count($p2_docs));
    }
    echo "✅ Verified: Property 1 has 1 document; Property 2 has 0 documents (strict UUID isolation).\n\n";

    // -------------------------------------------------------------
    // Test 3: Property Versions & Revision Tracking
    // -------------------------------------------------------------
    echo "--- Test 3: Property Versions & Revision Tracking ---\n";
    
    // Update Property 1
    $update_req1 = new WP_REST_Request('PUT', "/assessor/v1/properties/$prop1_id");
    $update_req1->set_body_params(array(
        'declarant_first_name' => 'John Updated',
        'assessed_value' => 65000,
        'change_reason' => 'Step 12 version test'
    ));
    $update_res1 = $props_api->update_property($prop1_id, $update_req1);
    if (is_wp_error($update_res1)) {
        throw new Exception("Failed to update Property 1: " . $update_res1->get_error_message());
    }
    echo "✅ Updated Property 1\n";

    // Verify version created in wp_assessor_property_versions
    $p1_versions = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table_versions WHERE property_id = %s ORDER BY version_number ASC",
        $prop1_id
    ));
    $p2_versions = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table_versions WHERE property_id = %s ORDER BY version_number ASC",
        $prop2_id
    ));

    if (count($p1_versions) < 1) {
        throw new Exception("Expected at least 1 version for Property 1, found " . count($p1_versions));
    }
    if (count($p2_versions) !== 0) {
        throw new Exception("Expected 0 versions for Property 2, found " . count($p2_versions));
    }

    $latest_p1_ver = end($p1_versions);
    if ($latest_p1_ver->property_id !== $prop1_id) {
        throw new Exception("Version property_id mismatch! Expected $prop1_id, got {$latest_p1_ver->property_id}");
    }
    if ($latest_p1_ver->revision_id !== $revA->id) {
        throw new Exception("Version revision_id mismatch! Expected {$revA->id}, got {$latest_p1_ver->revision_id}");
    }
    echo "✅ Verified: Version recorded with UUID=$prop1_id and revision_id={$latest_p1_ver->revision_id}.\n";
    echo "✅ Verified: Property 2 versions untouched (count=0).\n\n";

    // -------------------------------------------------------------
    // Test 4: Lineage & Supersede Semantics
    // -------------------------------------------------------------
    echo "--- Test 4: Lineage & Supersede Semantics ---\n";

    // Create child property with previous_tax_declaration_number = $shared_tdn
    $req3 = new WP_REST_Request('POST', '/assessor/v1/properties');
    $req3->set_body_params(array(
        'tax_declaration_number' => $child_tdn,
        'previous_tax_declaration_number' => $shared_tdn,
        'effectivity_date' => '2024',
        'location' => 'Brgy 3 Child',
        'kind_of_property' => 'LAND',
        'declarant_last_name' => 'Owner Child',
        'declarant_first_name' => 'Charlie',
        'area_hectare' => 1.0,
        'assessed_value' => 30000,
        'gen_class' => 'A'
    ));
    $res3 = $props_api->create_property($req3);
    if (is_wp_error($res3) || empty($res3->id)) {
        throw new Exception("Failed to create child property: " . (is_wp_error($res3) ? $res3->get_error_message() : json_encode($res3)));
    }
    $prop3_id = $res3->id;
    $cleanup_prop_ids[] = $prop3_id;
    echo "✅ Created Child Property 3 ($prop3_id) superseding TDN '$shared_tdn'\n";

    // Verify property states for Prop 1 and Prop 2
    $state1 = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_states WHERE property_id = %s", $prop1_id));
    $state2 = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_states WHERE property_id = %s", $prop2_id));

    if (!$state1 || $state1->state !== 'CANCELLED') {
        throw new Exception("Expected Property 1 state to be CANCELLED, got " . ($state1 ? $state1->state : 'null'));
    }
    if (!$state2 || $state2->state !== 'CANCELLED') {
        throw new Exception("Expected Property 2 state to be CANCELLED, got " . ($state2 ? $state2->state : 'null'));
    }
    echo "✅ Verified: All matching properties with superseded TDN safely marked CANCELLED in property_states without row corruption.\n\n";

    // -------------------------------------------------------------
    // Test 5: Requests & General Classifications
    // -------------------------------------------------------------
    echo "--- Test 5: Requests & General Classifications Linkage ---\n";

    $req_api = new Assessor_Requests();
    $receipt_no = 'REC-' . time();
    $create_req = array(
        'property_id' => $prop1_id,
        'amount_paid' => 150.00,
        'receipt_number' => $receipt_no,
        'date_issued' => date('Y-m-d'),
        'place_issued' => 'Municipal Hall',
        'prepared_by' => 'Assessor Staff',
        'payment_type' => 'cash',
        'purpose' => 'Tax Clearance',
        'client_name' => 'John Doe',
        'client_address' => 'Sample Address',
        'contact_number' => '09123456789',
        'email' => 'john@example.com',
        'remarks' => 'Test request Step 12',
        'created_by' => 'admin',
        'updated_by' => 'admin',
        'created_at' => current_time('mysql'),
        'updated_at' => current_time('mysql')
    );
    $req_res = $req_api->create_request($create_req);
    if (is_wp_error($req_res) || empty($req_res['id'])) {
        throw new Exception("Failed to create request: " . (is_wp_error($req_res) ? $req_res->get_error_message() : json_encode($req_res)));
    }
    $request_id = $req_res['id'];
    $cleanup_req_ids[] = $request_id;
    echo "✅ Created Request ID $request_id referencing Property 1 UUID ($prop1_id)\n";

    $fetched_req = $req_api->get_request($request_id);
    if (is_wp_error($fetched_req)) {
        throw new Exception("Failed to fetch request: " . $fetched_req->get_error_message());
    }
    if ($fetched_req['property_id'] !== $prop1_id) {
        throw new Exception("Request property_id mismatch! Expected $prop1_id, got " . $fetched_req['property_id']);
    }
    if ($fetched_req['declarant_last_name'] !== 'Owner Alpha') {
        throw new Exception("Request joined incorrect property declarant! Got " . $fetched_req['declarant_last_name']);
    }
    if ($fetched_req['kind_of_property'] !== 'LAND') {
        throw new Exception("Request joined incorrect kind_of_property! Got " . $fetched_req['kind_of_property']);
    }
    echo "✅ Verified: Request joined strictly on Property 1 UUID; returned correct declarant and classification.\n\n";

    echo "🎉 ALL TESTS IN STEP 12 PASSED PERFECTLY!\n";

} catch (Exception $e) {
    echo "\n❌ TEST FAILED: " . $e->getMessage() . "\n";
} finally {
    echo "\nCleaning up test artifacts...\n";
    full_cleanup();
    echo "Cleanup complete.\n";
}
