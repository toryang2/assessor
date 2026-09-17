<?php
/**
 * Comprehensive Test Suite: Assessor Requests UUID v7 CRUD & Integration
 *
 * Can be executed via CLI (`php test-requests-uuid-crud.php`)
 * or via Browser (`https://domain/path/test-requests-uuid-crud.php?key=masso-migrate-uuid`).
 */

// Streaming headers for web execution
if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Accel-Buffering: no');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    ob_implicit_flush(true);
    @set_time_limit(300);

    $secret_key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';
    $allow_http = ($secret_key === 'masso-migrate-uuid');
}

$wp_load_paths = array(
    'C:/xampp/htdocs/wp-load.php',
    '/home/u799325560/domains/archive.massokitaotao.net/public_html/wp-load.php',
    __DIR__ . '/../../../../wp-load.php',
    __DIR__ . '/../../../wp-load.php',
    __DIR__ . '/../../wp-load.php',
    dirname(__FILE__) . '/../wp-load.php',
    $_SERVER['DOCUMENT_ROOT'] . '/wp-load.php'
);

$loaded = false;
foreach ($wp_load_paths as $path) {
    if (!empty($path) && file_exists($path)) {
        require_once $path;
        $loaded = true;
        break;
    }
}

if (!$loaded) {
    die("FATAL: Cannot locate wp-load.php\n");
}

if (php_sapi_name() !== 'cli' && empty($allow_http)) {
    if (!current_user_can('manage_options')) {
        wp_die("ACCESS DENIED: Pass ?key=masso-migrate-uuid or log in as Administrator.\n");
    }
}

function flush_out($msg) {
    echo $msg;
    if (php_sapi_name() !== 'cli') {
        flush();
    }
}

// Ensure classes are loaded whether running standalone or within WordPress plugin context
if (!class_exists('Assessor_UUID')) {
    $uuid_file = defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR . '/assessor-api/includes/class-assessor-uuid.php' : '';
    if (!$uuid_file || !file_exists($uuid_file)) {
        $uuid_file = dirname(__DIR__) . '/wp-content/plugins/assessor-api/includes/class-assessor-uuid.php';
    }
    if (file_exists($uuid_file)) {
        require_once $uuid_file;
    }
}

if (!class_exists('Assessor_Requests')) {
    $req_file = defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR . '/assessor-api/includes/class-assessor-requests.php' : '';
    if (!$req_file || !file_exists($req_file)) {
        $req_file = dirname(__DIR__) . '/wp-content/plugins/assessor-api/includes/class-assessor-requests.php';
    }
    if (file_exists($req_file)) {
        require_once $req_file;
    }
}

if (!class_exists('Assessor_Properties')) {
    $prop_file = defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR . '/assessor-api/includes/class-assessor-properties.php' : '';
    if (!$prop_file || !file_exists($prop_file)) {
        $prop_file = dirname(__DIR__) . '/wp-content/plugins/assessor-api/includes/class-assessor-properties.php';
    }
    if (file_exists($prop_file)) {
        require_once $prop_file;
    }
}

global $wpdb;
$requests_api = new Assessor_Requests();
$table_requests = $wpdb->prefix . 'assessor_requests';
$table_properties = $wpdb->prefix . 'assessor_properties';

flush_out("===============================================================\n");
flush_out("ASSESSOR REQUESTS UUID v7 TEST SUITE\n");
flush_out("===============================================================\n\n");

$failures = 0;
$created_test_uuids = array();

function assert_true($cond, $msg) {
    global $failures;
    if ($cond) {
        flush_out("  [PASS] $msg\n");
    } else {
        flush_out("  [FAIL] $msg\n");
        $failures++;
    }
}

// -------------------------------------------------------------
// Test A: Existing requests have unique UUID v7 IDs
// -------------------------------------------------------------
flush_out("Test A: Existing requests have valid unique UUID v7 identifiers\n");
$all_reqs = $wpdb->get_results("SELECT id FROM $table_requests", ARRAY_A);
$all_valid = true;
$seen_uuids = array();
foreach ($all_reqs as $r) {
    if (!Assessor_UUID::is_valid($r['id'])) {
        $all_valid = false;
    }
    if (isset($seen_uuids[$r['id']])) {
        $all_valid = false;
    }
    $seen_uuids[$r['id']] = true;
}
assert_true($all_valid && count($all_reqs) > 0, "All " . count($all_reqs) . " existing requests have valid, unique UUIDs.");

// Pick a property to link requests to
$sample_property = $wpdb->get_row("SELECT id, tax_declaration_number, declarant_last_name FROM $table_properties WHERE status != 'deleted' LIMIT 1", ARRAY_A);
$property_id = $sample_property ? $sample_property['id'] : null;

// -------------------------------------------------------------
// Test B: Create new request generates UUID v7 automatically
// -------------------------------------------------------------
flush_out("\nTest B: Create new request generates UUID v7 automatically\n");
$receipt_test_1 = 'TEST-OR-' . time() . '-1';
$create_payload = array(
    'property_id'    => $property_id,
    'amount_paid'    => 125.50,
    'receipt_number' => $receipt_test_1,
    'date_issued'    => date('Y-m-d'),
    'place_issued'   => 'Assessor Testing Office',
    'prepared_by'    => 'Auto Tester',
    'payment_type'   => 'cash',
    'purpose'        => 'Certification of Property Holdings',
    'client_name'    => 'Juan Dela Cruz',
    'client_address' => 'Poblacion, Kitaotao',
    'contact_number' => '09123456789',
    'email'          => 'juan@example.com',
    'remarks'        => 'Automated test request',
    'created_by'     => '1',
    'updated_by'     => '1'
);

$res1 = $requests_api->create_request($create_payload);
assert_true(!is_wp_error($res1) && isset($res1['id']), "Request created successfully.");
$req_uuid_1 = $res1['id'] ?? null;
assert_true(Assessor_UUID::is_valid($req_uuid_1), "Generated ID is a valid UUID: $req_uuid_1");
if ($req_uuid_1) $created_test_uuids[] = $req_uuid_1;

// -------------------------------------------------------------
// Test C: Get request by UUID works
// -------------------------------------------------------------
flush_out("\nTest C: Get request by UUID works\n");
$get_res = $requests_api->get_request($req_uuid_1);
assert_true(!is_wp_error($get_res), "Fetched request by UUID without error.");
assert_true($get_res['id'] === $req_uuid_1, "Fetched request ID strictly matches requested UUID string.");
assert_true($get_res['receipt_number'] === $receipt_test_1, "Fetched receipt number matches created data.");
assert_true((float)$get_res['amount_paid'] === 125.50, "Amount paid matches created data.");

// -------------------------------------------------------------
// Test D: Update request by UUID works
// -------------------------------------------------------------
flush_out("\nTest D: Update request by UUID works\n");
$update_payload = array(
    'amount_paid' => 200.00,
    'remarks'     => 'Updated automated test request',
    'purpose'     => 'Updated Purpose'
);
$update_res = $requests_api->update_request($req_uuid_1, $update_payload);
assert_true(!is_wp_error($update_res), "Updated request by UUID without error.");
assert_true((float)$update_res['amount_paid'] === 200.00, "Updated amount reflected in response.");
assert_true($update_res['remarks'] === 'Updated automated test request', "Updated remarks reflected in response.");

// -------------------------------------------------------------
// Test E: Existing request -> property relationship still works
// -------------------------------------------------------------
flush_out("\nTest E: Existing request -> property relationship still works\n");
if ($property_id) {
    assert_true($get_res['property_id'] === $property_id, "Request property_id matches property UUID.");
    assert_true($get_res['tax_declaration_number'] === $sample_property['tax_declaration_number'], "Joined tax declaration number matches parent property.");
    assert_true($get_res['declarant_last_name'] === $sample_property['declarant_last_name'], "Joined declarant last name matches parent property.");
}

// -------------------------------------------------------------
// Test F: Receipt-number uniqueness behavior remains unchanged
// -------------------------------------------------------------
flush_out("\nTest F: Receipt-number uniqueness behavior\n");
// Attempt to create duplicate receipt
$dup_payload = $create_payload;
$dup_payload['receipt_number'] = $receipt_test_1;
$dup_res = $requests_api->create_request($dup_payload);
assert_true(is_wp_error($dup_res) && $dup_res->get_error_code() === 'duplicate_receipt', "Duplicate receipt creation correctly rejected with 'duplicate_receipt'.");

// -------------------------------------------------------------
// Test G: Request pagination still works
// -------------------------------------------------------------
flush_out("\nTest G: Request pagination still works\n");
$list_res = $requests_api->get_requests(array('page' => 1, 'per_page' => 10));
assert_true(isset($list_res['requests']) && isset($list_res['pagination']), "get_requests returns requests and pagination.");
assert_true(count($list_res['requests']) <= 10, "Paginated requests respects per_page parameter.");
assert_true($list_res['pagination']['total'] >= 1, "Total count includes existing + newly created requests.");

// -------------------------------------------------------------
// Test H: Request search still works
// -------------------------------------------------------------
flush_out("\nTest H: Request search still works\n");
$search_res = $requests_api->get_requests(array('search' => $receipt_test_1));
assert_true(count($search_res['requests']) >= 1, "Search by receipt number found created request.");
assert_true($search_res['requests'][0]['id'] === $req_uuid_1, "Search result matches expected UUID.");

// -------------------------------------------------------------
// Test I: Request statistics work
// -------------------------------------------------------------
flush_out("\nTest I: Request statistics work\n");
$stats = $requests_api->get_statistics();
assert_true(isset($stats['total_amount']) && $stats['total_amount'] > 0, "Statistics returns positive total_amount.");
assert_true(isset($stats['total_requests']) && $stats['total_requests'] >= 1, "Statistics returns total_requests.");

// -------------------------------------------------------------
// Test J: Delete request by UUID works
// -------------------------------------------------------------
flush_out("\nTest J: Delete request by UUID works\n");
$del_res = $requests_api->delete_request($req_uuid_1);
assert_true(!is_wp_error($del_res), "Delete request succeeded without error.");

// Verify request no longer exists
$fetch_after_del = $requests_api->get_request($req_uuid_1);
assert_true(is_wp_error($fetch_after_del) && $fetch_after_del->get_error_code() === 'not_found', "Deleted request returned 404 not_found.");

flush_out("\n===============================================================\n");
if ($failures === 0) {
    flush_out("ALL TESTS PASSED SUCCESSFULLY! (0 Failures)\n");
    flush_out("===============================================================\n");
    exit(0);
} else {
    flush_out("TEST SUITE FAILED with $failures failure(s).\n");
    flush_out("===============================================================\n");
    exit(1);
}
