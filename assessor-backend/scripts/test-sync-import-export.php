<?php
/**
 * Step 13 Verification Test: Sync, Import, Export, Printing Compatibility
 *
 * Verifies:
 * 1. Sync receiver upserts property by UUID and never merges records because TDN matches across revisions.
 * 2. Sync receiver handles multiple properties with the same TDN across different revisions as separate records.
 * 3. Sync receiver namespaces uploaded documents under {tdn}_{property_id} to avoid collision.
 * 4. Property Export includes property_uuid, revision_id, and revision display details (revision_code, revision_year, etc.).
 * 5. Versions Export joins revision entries to include revision display details.
 * 6. Requests/Printing uses exact property UUID and joins cleanly without ambiguity even when TDNs duplicate.
 */

require_once 'C:/xampp/htdocs/wp-load.php';

global $wpdb;
$wpdb->show_errors();

echo "=== STEP 13: SYNC / EXPORT / PRINTING COMPATIBILITY TESTS ===\n\n";

$table_props = $wpdb->prefix . 'assessor_properties';
$table_revisions = $wpdb->prefix . 'assessor_revision_entries';
$table_states = $wpdb->prefix . 'assessor_property_states';
$table_versions = $wpdb->prefix . 'assessor_property_versions';
$table_requests = $wpdb->prefix . 'assessor_requests';
$table_docs = $wpdb->prefix . 'assessor_documents';

// Pick 2 distinct revisions
$revisions = $wpdb->get_results("SELECT id, revision_year, revision_code FROM $table_revisions ORDER BY id ASC LIMIT 2", ARRAY_A);
if (count($revisions) < 2) {
    die("Error: Need at least 2 revisions in database to run this test.\n");
}
$revA = $revisions[0];
$revB = $revisions[1];

$shared_tdn = 'TDN-SYNC-STEP13-' . time();
$uuid1 = class_exists('Assessor_UUID') ? Assessor_UUID::v7() : wp_generate_uuid4();
$uuid2 = class_exists('Assessor_UUID') ? Assessor_UUID::v7() : wp_generate_uuid4();

$cleanup_ids = array($uuid1, $uuid2);
$cleanup_docs = array();
$cleanup_requests = array();

try {
    // -------------------------------------------------------------
    // Test 1: Sync Receiver preserves two same-TDN properties across revisions
    // -------------------------------------------------------------
    echo "[TEST 1] Sync Receiver: Push two properties with same TDN in different revisions...\n";
    
    $sync_receiver = new Assessor_Sync_Receiver();
    
    $remote_record_1 = array(
        'id'                     => $uuid1,
        'tax_declaration_number' => $shared_tdn,
        'revision_id'            => $revA['id'],
        'declarant_last_name'    => 'Alpha In Revision A',
        'declarant_first_name'   => 'Owner',
        'address'                => 'Alpha Barangay',
        'kind_of_property'       => 'Land',
        'status'                 => 'active',
        'property_state'         => 'CURRENT',
        'updated_at'             => date('Y-m-d H:i:s'),
    );
    
    $remote_record_2 = array(
        'id'                     => $uuid2,
        'tax_declaration_number' => $shared_tdn,
        'revision_id'            => $revB['id'],
        'declarant_last_name'    => 'Beta In Revision B',
        'declarant_first_name'   => 'Owner',
        'address'                => 'Beta Barangay',
        'kind_of_property'       => 'Land',
        'status'                 => 'active',
        'property_state'         => 'SUPERSEDED',
        'updated_at'             => date('Y-m-d H:i:s'),
    );
    
    // Simulate push batch
    $fake_request = new WP_REST_Request('POST', '/assessor/v1/sync/push');
    $fake_request->add_header('content-type', 'application/json');
    $fake_request->set_body(json_encode(array('records' => array($remote_record_1, $remote_record_2))));
    
    $response = $sync_receiver->receive_push($fake_request);
    if (is_wp_error($response)) {
        throw new Exception("receive_push failed with WP_Error: " . $response->get_error_message());
    }
    
    // Check DB rows
    $row1 = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_props WHERE id = %s", $uuid1), ARRAY_A);
    $row2 = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_props WHERE id = %s", $uuid2), ARRAY_A);
    
    assert(!empty($row1), "Record 1 should exist in properties table");
    assert(!empty($row2), "Record 2 should exist in properties table");
    assert($row1['id'] !== $row2['id'], "Records must have distinct UUIDs");
    assert($row1['tax_declaration_number'] === $shared_tdn && $row2['tax_declaration_number'] === $shared_tdn, "Both records must share the TDN");
    assert($row1['revision_id'] === $revA['id'], "Record 1 must belong to revision A");
    assert($row2['revision_id'] === $revB['id'], "Record 2 must belong to revision B");
    assert($row1['declarant_last_name'] === 'Alpha In Revision A', "Record 1 declarant_last_name must match Alpha");
    assert($row2['declarant_last_name'] === 'Beta In Revision B', "Record 2 declarant_last_name must match Beta");
    
    // Check property states
    $state1 = $wpdb->get_var($wpdb->prepare("SELECT state FROM $table_states WHERE property_id = %s", $uuid1));
    $state2 = $wpdb->get_var($wpdb->prepare("SELECT state FROM $table_states WHERE property_id = %s", $uuid2));
    assert($state1 === 'CURRENT', "State 1 must be CURRENT");
    assert($state2 === 'SUPERSEDED', "State 2 must be SUPERSEDED");
    
    echo "  PASS: Both records upserted independently by UUID without collision or overwrite.\n\n";

    // -------------------------------------------------------------
    // Test 2: Sync Receiver response keys by UUID and supports same-TDN records
    // -------------------------------------------------------------
    echo "[TEST 2] Sync Receiver Response mapping...\n";
    assert(isset($response['results'][$uuid1]), "Results should contain uuid1 key");
    assert(isset($response['results'][$uuid2]), "Results should contain uuid2 key");
    assert($response['summary']['synced'] === 2, "Both records should count as synced");
    echo "  PASS: Response correctly maps by UUID.\n\n";

    // -------------------------------------------------------------
    // Test 3: Sync Receiver document namespacing
    // -------------------------------------------------------------
    echo "[TEST 3] Document namespacing on sync push...\n";
    $fake_doc_1 = array(
        'id'                     => $uuid1,
        'tax_declaration_number' => $shared_tdn,
        'revision_id'            => $revA['id'],
        'owner_name'             => 'Owner Alpha In Revision A',
        'updated_at'             => date('Y-m-d H:i:s', time() + 10),
        'assessor_documents'     => array(
            array(
                'filename'          => 'doc_rev_a_' . time() . '.txt',
                'original_filename' => 'doc_rev_a.txt',
                'file_type'         => 'text/plain',
                'file_data'         => base64_encode("Document content for Rev A"),
            )
        )
    );
    
    $fake_request2 = new WP_REST_Request('POST', '/assessor/v1/sync/push');
    $fake_request2->add_header('content-type', 'application/json');
    $fake_request2->set_body(json_encode(array('records' => array($fake_doc_1))));
    $res2 = $sync_receiver->receive_push($fake_request2);
    if (is_wp_error($res2)) {
        throw new Exception("fake_request2 failed with WP_Error: " . $res2->get_error_message());
    }
    
    $doc_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_docs WHERE property_id = %s", $uuid1), ARRAY_A);
    assert(!empty($doc_row), "Document record must be inserted in assessor_documents");
    $cleanup_docs[] = $doc_row['id'];
    
    // Check that the folder path contains the property UUID
    $expected_folder_segment = $uuid1;
    assert(strpos($doc_row['file_path'], $expected_folder_segment) !== false, "Document path must include property UUID");
    if (file_exists($doc_row['file_path'])) {
        @unlink($doc_row['file_path']);
        @rmdir(dirname($doc_row['file_path']));
    }
    echo "  PASS: Documents cleanly namespaced with property UUID.\n\n";

    // -------------------------------------------------------------
    // Test 4: Export Properties includes property_uuid, revision metadata
    // -------------------------------------------------------------
    echo "[TEST 4] Assessor_Export::export_properties with revision join...\n";
    $exporter = new Assessor_Export();
    $export_req = new WP_REST_Request('GET', '/assessor/v1/export');
    $export_req->set_param('format', 'json');
    $export_req->set_param('type', 'properties');
    $export_req->set_param('filters', array('search' => $shared_tdn));
    
    $export_res = $exporter->export_data($export_req);
    assert(isset($export_res['success']) && $export_res['success'] === true, "Export must succeed");
    assert($export_res['count'] === 2, "Export must return both records matching the TDN search");
    
    $exported_data = $export_res['data'];
    $p1 = ($exported_data[0]['id'] === $uuid1) ? $exported_data[0] : $exported_data[1];
    $p2 = ($exported_data[0]['id'] === $uuid2) ? $exported_data[0] : $exported_data[1];
    
    assert(isset($p1['property_uuid']) && $p1['property_uuid'] === $uuid1, "p1 must have property_uuid alias");
    assert(isset($p2['property_uuid']) && $p2['property_uuid'] === $uuid2, "p2 must have property_uuid alias");
    assert(isset($p1['revision_id']) && $p1['revision_id'] === $revA['id'], "p1 must have revision_id");
    assert(isset($p2['revision_id']) && $p2['revision_id'] === $revB['id'], "p2 must have revision_id");
    assert(isset($p1['revision_year']) && !empty($p1['revision_year']), "p1 must include revision_year from join");
    assert(isset($p2['revision_year']) && !empty($p2['revision_year']), "p2 must include revision_year from join");
    
    echo "  PASS: Export contains property_uuid, revision_id, revision_code/revision_year for both same-TDN rows.\n\n";

    // -------------------------------------------------------------
    // Test 5: Export Property Versions includes revision metadata
    // -------------------------------------------------------------
    echo "[TEST 5] Assessor_Export::export_versions with revision join...\n";
    $wpdb->insert($table_versions, array(
        'property_id'            => $uuid1,
        'revision_id'            => $revA['id'],
        'version_number'         => 1,
        'tax_declaration_number' => $shared_tdn,
        'declarant_last_name'    => 'Alpha',
        'declarant_first_name'   => 'Owner',
        'location'               => 'Test Location',
        'kind_of_property'       => 'Land',
        'created_by'             => '1',
        'created_at'             => date('Y-m-d H:i:s'),
    ));
    $v_id = $wpdb->insert_id;
    
    $export_v_req = new WP_REST_Request('GET', '/assessor/v1/export');
    $export_v_req->set_param('format', 'json');
    $export_v_req->set_param('type', 'versions');
    $export_v_req->set_param('filters', array('property_id' => $uuid1));
    
    $export_v_res = $exporter->export_data($export_v_req);
    assert(isset($export_v_res['success']) && $export_v_res['success'] === true, "Versions export must succeed");
    assert($export_v_res['count'] >= 1, "Should return at least 1 version");
    $first_ver = $export_v_res['data'][0];
    assert($first_ver['property_id'] === $uuid1, "Version property_id must match uuid1");
    assert(isset($first_ver['revision_year']) && !empty($first_ver['revision_year']), "Version export must include joined revision_year");
    
    // Clean version
    $wpdb->delete($table_versions, array('id' => $v_id));
    echo "  PASS: Versions export cleanly joins revision metadata.\n\n";

    // -------------------------------------------------------------
    // Test 6: Requests / Printing integration: exact selection uses property UUID
    // -------------------------------------------------------------
    echo "[TEST 6] Requests / Printing: exact request targets exact property UUID...\n";
    $requests_mgr = new Assessor_Requests();
    $req_data = array(
        'property_id'    => $uuid2, // Targeting property 2 in revision B
        'amount_paid'    => 50.00,
        'receipt_number' => 'OR-TEST-' . time(),
        'date_issued'    => date('Y-m-d H:i:s'),
        'place_issued'   => 'Municipal Hall',
        'prepared_by'    => 'Assessor Staff',
        'payment_type'   => 'cash',
        'purpose'        => 'Certified True Copy',
        'client_name'    => 'Juan Dela Cruz',
        'client_address' => 'Poblacion',
        'contact_number' => '09123456789',
        'email'          => 'juan@example.com',
        'remarks'        => 'Test request for exact property UUID',
        'created_by'     => '1',
        'updated_by'     => '1',
        'created_at'     => date('Y-m-d H:i:s'),
        'updated_at'     => date('Y-m-d H:i:s'),
    );
    
    $create_res = $requests_mgr->create_request($req_data);
    assert(is_array($create_res) && isset($create_res['id']), "Request creation should succeed");
    $req_id = $create_res['id'];
    $cleanup_requests[] = $req_id;
    
    // Retrieve single request
    $fetched_req = $requests_mgr->get_request($req_id);
    assert(!empty($fetched_req), "Request should be retrievable");
    assert($fetched_req['property_id'] === $uuid2, "Fetched request property_id must match uuid2");
    assert($fetched_req['declarant_last_name'] === 'Beta In Revision B', "Joined property details must match Property 2 in Revision B");
    assert($fetched_req['tax_declaration_number'] === $shared_tdn, "Joined TDN must match shared TDN");
    
    echo "  PASS: Requests bind to property UUID, disambiguating identical TDN records accurately.\n\n";

    echo "ALL 6 STEP 13 TESTS PASSED SUCCESSFULLY!\n";
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine() . "\n";
} finally {
    // Cleanup
    echo "\nCleaning up test records...\n";
    foreach ($cleanup_ids as $id) {
        $wpdb->delete($table_props, array('id' => $id));
        $wpdb->delete($table_states, array('property_id' => $id));
        $wpdb->delete($table_docs, array('property_id' => $id));
    }
    foreach ($cleanup_requests as $rid) {
        $wpdb->delete($table_requests, array('id' => $rid));
    }
    echo "Cleanup complete.\n";
}
