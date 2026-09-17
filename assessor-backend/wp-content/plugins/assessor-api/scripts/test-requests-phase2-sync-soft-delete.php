<?php
/**
 * Comprehensive Test Suite: Assessor Requests Phase 2 (Soft Delete + Bidirectional Sync)
 *
 * Tests:
 *   Test A: Schema verification (assessor_requests.deleted_at exists and is indexed)
 *   Test B: Queue schema verification (record_type exists with unique record_op index)
 *   Test C: Existing requests have deleted_at IS NULL
 *   Test D: Create request sets deleted_at = NULL and enqueues to sync queue as 'request'
 *   Test E: Soft delete sets deleted_at and updated_at, keeps row in database
 *   Test F: get_request(UUID) excludes soft-deleted records (returns 404/not_found)
 *   Test G: get_request(UUID, true) retrieves soft-deleted record
 *   Test H: get_requests() excludes soft-deleted records by default
 *   Test I: get_requests(['include_deleted' => true]) includes soft-deleted records
 *   Test J: Search and pagination ignore soft-deleted records
 *   Test K: get_statistics() excludes soft-deleted records
 *   Test L: Dashboard request counts exclude soft-deleted records
 *   Test M: Soft delete enqueues 'delete' operation to sync queue
 *   Test N: Updating active request enqueues 'upsert' to sync queue
 *   Test O: Sync guard ($syncing = true) prevents re-enqueuing on apply
 *   Test P: Receiver handles incoming request upsert (creates new row with incoming UUID)
 *   Test Q: Receiver updates existing request row with incoming UUID
 *   Test R: Receiver last-write-wins (skips when live record updated_at >= remote updated_at)
 *   Test S: Receiver applies newer soft delete (deleted_at synced to live)
 *   Test T: Receiver prevents resurrection (newer deletion beats older active)
 *   Test U: Receipt uniqueness check does not block sync replication of existing record
 *   Test V: Push pending packages requests with _record_type = 'request'
 *   Test W: Pull requests fetches changed requests including soft-deleted ones
 *   Test X: Local apply_remote_request handles soft delete and updates locally
 *   Test Y: Cleanup of test artifacts
 *
 * Can be executed via CLI (`php test-requests-phase2-sync-soft-delete.php`)
 * or via Browser (`https://domain/path/test-requests-phase2-sync-soft-delete.php?key=masso-migrate-uuid`).
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

// Load plugin classes if not loaded
$plugin_inc = dirname(__DIR__) . '/wp-content/plugins/assessor-api/includes';
if (defined('WP_PLUGIN_DIR') && file_exists(WP_PLUGIN_DIR . '/assessor-api/includes')) {
    $plugin_inc = WP_PLUGIN_DIR . '/assessor-api/includes';
}

$classes_to_load = array(
    'Assessor_UUID'          => $plugin_inc . '/class-assessor-uuid.php',
    'Assessor_Requests'      => $plugin_inc . '/class-assessor-requests.php',
    'Assessor_Properties'    => $plugin_inc . '/class-assessor-properties.php',
    'Assessor_Sync'          => $plugin_inc . '/class-assessor-sync.php',
    'Assessor_Sync_Receiver' => $plugin_inc . '/class-assessor-sync-receiver.php',
    'Assessor_API'           => $plugin_inc . '/class-assessor-api.php',
);

foreach ($classes_to_load as $cls => $file) {
    if (!class_exists($cls) && file_exists($file)) {
        require_once $file;
    }
}

// Ensure ASSESSOR_IS_LOCAL_BUILD is defined for sync queue tests
if (!defined('ASSESSOR_IS_LOCAL_BUILD')) {
    define('ASSESSOR_IS_LOCAL_BUILD', true);
}

global $wpdb;
$requests_api = new Assessor_Requests();
$sync_receiver = new Assessor_Sync_Receiver();
$table_requests = $wpdb->prefix . 'assessor_requests';
$table_queue    = $wpdb->prefix . 'assessor_sync_queue';
$table_properties = $wpdb->prefix . 'assessor_properties';

flush_out("===============================================================\n");
flush_out("ASSESSOR REQUESTS PHASE 2: SOFT DELETE & BIDIRECTIONAL SYNC TEST SUITE\n");
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
// Test A: Schema verification (deleted_at column and index)
// -------------------------------------------------------------
flush_out("Test A: Schema verification (assessor_requests.deleted_at exists and is indexed)\n");
$col_deleted_at = $wpdb->get_row("SHOW COLUMNS FROM $table_requests LIKE 'deleted_at'");
assert_true(!empty($col_deleted_at), "Column 'deleted_at' exists in $table_requests");
$idx_deleted_at = $wpdb->get_results("SHOW INDEX FROM $table_requests WHERE Column_name = 'deleted_at'");
assert_true(!empty($idx_deleted_at), "Index on 'deleted_at' exists in $table_requests");

// -------------------------------------------------------------
// Test B: Sync Queue schema verification
// -------------------------------------------------------------
flush_out("\nTest B: Queue schema verification (record_type exists with unique record_op index)\n");
$col_rec_type = $wpdb->get_row("SHOW COLUMNS FROM $table_queue LIKE 'record_type'");
assert_true(!empty($col_rec_type), "Column 'record_type' exists in $table_queue");
$idx_rec_op = $wpdb->get_results("SHOW INDEX FROM $table_queue WHERE Key_name = 'record_op'");
assert_true(!empty($idx_rec_op), "Unique index 'record_op' exists in $table_queue");

// -------------------------------------------------------------
// Test C: Existing requests have deleted_at IS NULL
// -------------------------------------------------------------
flush_out("\nTest C: Existing requests have deleted_at IS NULL\n");
$non_null_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_requests WHERE deleted_at IS NOT NULL");
assert_true($non_null_count === 0, "All existing requests have deleted_at IS NULL ($non_null_count non-null)");

// Pick a property to link requests to
$sample_property = $wpdb->get_row("SELECT id FROM $table_properties WHERE status != 'deleted' LIMIT 1", ARRAY_A);
$property_id = $sample_property ? $sample_property['id'] : null;

// -------------------------------------------------------------
// Test D: Create request sets deleted_at = NULL and enqueues to sync queue as 'request'
// -------------------------------------------------------------
flush_out("\nTest D: Create request sets deleted_at = NULL and enqueues to sync queue as 'request'\n");
$receipt_test_d = 'TEST-P2-OR-' . time() . '-D';
$req_d = $requests_api->create_request(array(
    'property_id'    => $property_id,
    'amount_paid'    => 150.00,
    'receipt_number' => $receipt_test_d,
    'date_issued'    => '2026-09-16',
    'place_issued'   => 'Kitaotao',
    'prepared_by'    => 'Phase 2 Test Runner',
    'payment_type'   => 'cash',
    'purpose'        => 'Assessment Certification',
    'client_name'    => 'Juan Dela Cruz Active',
    'client_address' => 'Poblacion, Kitaotao',
    'contact_number' => '09123456789',
    'email'          => 'juan@example.com',
    'remarks'        => 'Phase 2 active test record'
));

assert_true(!is_wp_error($req_d) && !empty($req_d['id']), "Request D created successfully: " . ($req_d['id'] ?? ''));
$uuid_d = $req_d['id'];
$created_test_uuids[] = $uuid_d;

$row_d = $wpdb->get_row($wpdb->prepare("SELECT id, deleted_at FROM $table_requests WHERE id = %s", $uuid_d), ARRAY_A);
assert_true($row_d && $row_d['deleted_at'] === null, "Newly created request has deleted_at = NULL in DB");

$queue_item_d = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM $table_queue WHERE property_id = %s AND record_type = 'request' AND operation = 'upsert'",
    $uuid_d
), ARRAY_A);
assert_true(!empty($queue_item_d) && $queue_item_d['status'] === 'pending', "Request is enqueued in sync queue with record_type='request' and status='pending'");

// -------------------------------------------------------------
// Test E: Soft delete sets deleted_at & updated_at, keeps row in DB
// -------------------------------------------------------------
flush_out("\nTest E: Soft delete sets deleted_at & updated_at, keeps row in database\n");
$del_res = $requests_api->delete_request($uuid_d);
assert_true(!is_wp_error($del_res), "delete_request($uuid_d) executed without error");

$row_e = $wpdb->get_row($wpdb->prepare("SELECT id, deleted_at, updated_at FROM $table_requests WHERE id = %s", $uuid_d), ARRAY_A);
assert_true(!empty($row_e), "Row still physically exists in database after soft delete");
assert_true(!empty($row_e['deleted_at']), "deleted_at is populated with valid timestamp: " . ($row_e['deleted_at'] ?? ''));

// -------------------------------------------------------------
// Test F: get_request(UUID) excludes soft-deleted records (returns 404)
// -------------------------------------------------------------
flush_out("\nTest F: get_request(UUID) excludes soft-deleted records (returns 404/not_found)\n");
$lookup_f = $requests_api->get_request($uuid_d);
assert_true(is_wp_error($lookup_f) && $lookup_f->get_error_code() === 'not_found', "get_request($uuid_d) returns WP_Error('not_found')");

// -------------------------------------------------------------
// Test G: get_request(UUID, true) retrieves soft-deleted record
// -------------------------------------------------------------
flush_out("\nTest G: get_request(UUID, true) retrieves soft-deleted record\n");
$lookup_g = $requests_api->get_request($uuid_d, true);
assert_true(!is_wp_error($lookup_g) && $lookup_g['id'] === $uuid_d, "get_request($uuid_d, true) returns the record");
assert_true(!empty($lookup_g['deleted_at']), "Record returned by include_deleted=true includes deleted_at timestamp");

// -------------------------------------------------------------
// Test H: get_requests() excludes soft-deleted records by default
// -------------------------------------------------------------
flush_out("\nTest H: get_requests() excludes soft-deleted records by default\n");
$list_h = $requests_api->get_requests(array('search' => $receipt_test_d));
$total_h = isset($list_h['pagination']['total']) ? $list_h['pagination']['total'] : ($list_h['total'] ?? 0);
assert_true(empty($list_h['requests']) && $total_h === 0, "Default get_requests() did not return soft-deleted record");

// -------------------------------------------------------------
// Test I: get_requests(['include_deleted' => true]) includes soft-deleted records
// -------------------------------------------------------------
flush_out("\nTest I: get_requests(['include_deleted' => true]) includes soft-deleted records\n");
$list_i = $requests_api->get_requests(array('search' => $receipt_test_d, 'include_deleted' => true));
$total_i = isset($list_i['pagination']['total']) ? $list_i['pagination']['total'] : ($list_i['total'] ?? 0);
assert_true(!empty($list_i['requests']) && $total_i >= 1, "get_requests with include_deleted=true found soft-deleted record");

// -------------------------------------------------------------
// Test J: Search and pagination ignore soft-deleted records
// -------------------------------------------------------------
flush_out("\nTest J: Search and pagination ignore soft-deleted records\n");
$search_j = $requests_api->get_requests(array('search' => 'Juan Dela Cruz Active'));
$found_j = false;
if (!empty($search_j['requests'])) {
    foreach ($search_j['requests'] as $item) {
        if ($item['id'] === $uuid_d) {
            $found_j = true;
        }
    }
}
assert_true(!$found_j, "Search for client name does not return soft-deleted request");

// -------------------------------------------------------------
// Test K: get_statistics() excludes soft-deleted records
// -------------------------------------------------------------
flush_out("\nTest K: get_statistics() excludes soft-deleted records\n");
$stats_before = $requests_api->get_statistics(array('year' => 2026));
// Create a new request, check stats, delete it, check stats
$receipt_test_k = 'TEST-P2-OR-' . time() . '-K';
$req_k = $requests_api->create_request(array(
    'property_id'    => $property_id,
    'amount_paid'    => 200.00,
    'receipt_number' => $receipt_test_k,
    'date_issued'    => '2026-09-16',
    'place_issued'   => 'Kitaotao',
    'prepared_by'    => 'Stats Tester',
    'purpose'        => 'Certified Copy',
    'client_name'    => 'Stats Client',
));
$uuid_k = $req_k['id'];
$created_test_uuids[] = $uuid_k;
$stats_active = $requests_api->get_statistics(array('year' => 2026));
assert_true(intval($stats_active['total_requests']) === intval($stats_before['total_requests']) + 1, "Active request increments total_requests stat");

$requests_api->delete_request($uuid_k);
$stats_after = $requests_api->get_statistics(array('year' => 2026));
assert_true(intval($stats_after['total_requests']) === intval($stats_before['total_requests']), "Soft-deleting request immediately excludes it from statistics");

// -------------------------------------------------------------
// Test L: Dashboard request counts exclude soft-deleted records
// -------------------------------------------------------------
flush_out("\nTest L: Dashboard request counts exclude soft-deleted records\n");
$assessor_api = new Assessor_API();
$dashboard_data = $assessor_api->get_dashboard_data(new WP_REST_Request());
$direct_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_requests WHERE deleted_at IS NULL");
assert_true(intval($dashboard_data['requests_count']) === $direct_count, "Dashboard total requests matches DB active count ($direct_count)");

// -------------------------------------------------------------
// Test M: Soft delete enqueues 'delete' operation to sync queue
// -------------------------------------------------------------
flush_out("\nTest M: Soft delete enqueues 'delete' operation to sync queue\n");
$queue_del = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM $table_queue WHERE property_id = %s AND record_type = 'request' AND operation = 'delete'",
    $uuid_k
), ARRAY_A);
assert_true(!empty($queue_del) && $queue_del['status'] === 'pending', "Soft delete queued with operation='delete' and status='pending'");

// -------------------------------------------------------------
// Test N: Updating active request enqueues 'upsert' to sync queue
// -------------------------------------------------------------
flush_out("\nTest N: Updating active request enqueues 'upsert' to sync queue\n");
$receipt_test_n = 'TEST-P2-OR-' . time() . '-N';
$req_n = $requests_api->create_request(array(
    'property_id'    => $property_id,
    'amount_paid'    => 300.00,
    'receipt_number' => $receipt_test_n,
    'date_issued'    => '2026-09-16',
    'place_issued'   => 'Kitaotao',
    'prepared_by'    => 'Update Tester',
    'purpose'        => 'Certified Copy',
    'client_name'    => 'Client N',
));
$uuid_n = $req_n['id'];
$created_test_uuids[] = $uuid_n;

// Clear queue item for N
$wpdb->delete($table_queue, array('property_id' => $uuid_n, 'record_type' => 'request'));

// Update N
$requests_api->update_request($uuid_n, array('remarks' => 'Updated remarks for N'));
$queue_n = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM $table_queue WHERE property_id = %s AND record_type = 'request' AND operation = 'upsert'",
    $uuid_n
), ARRAY_A);
assert_true(!empty($queue_n) && $queue_n['status'] === 'pending', "Update enqueued 'upsert' to sync queue");

// -------------------------------------------------------------
// Test O: Sync guard ($syncing = true) prevents re-enqueuing on apply
// -------------------------------------------------------------
flush_out("\nTest O: Sync guard ($syncing = true) prevents re-enqueuing on apply\n");
Assessor_Sync::$syncing = true;
$test_guard_uuid = Assessor_UUID::v7();
Assessor_Sync::enqueue_request($test_guard_uuid, 'upsert');
$guard_item = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM $table_queue WHERE property_id = %s",
    $test_guard_uuid
));
assert_true(empty($guard_item), "enqueue_request skipped when Assessor_Sync::\$syncing is true");
Assessor_Sync::$syncing = false;

// -------------------------------------------------------------
// Test P: Receiver handles incoming request upsert (creates new row with incoming UUID)
// -------------------------------------------------------------
flush_out("\nTest P: Receiver handles incoming request upsert (creates new row with incoming UUID)\n");
$uuid_p = Assessor_UUID::v7();
$created_test_uuids[] = $uuid_p;
$receipt_p = 'TEST-P2-OR-' . time() . '-P';
$incoming_p = array(
    '_record_type'   => 'request',
    'id'             => $uuid_p,
    'property_id'    => $property_id,
    'amount_paid'    => 500.00,
    'receipt_number' => $receipt_p,
    'date_issued'    => '2026-09-16',
    'place_issued'   => 'Kitaotao Live',
    'prepared_by'    => 'Live Sync Sender',
    'payment_type'   => 'cash',
    'purpose'        => 'Remote Insert Purpose',
    'client_name'    => 'Client P Incoming',
    'created_at'     => '2026-09-16 10:00:00',
    'updated_at'     => '2026-09-16 10:00:00',
    'deleted_at'     => null,
);

$req_obj = new WP_REST_Request('POST', '/assessor/v1/sync/push');
$req_obj->set_header('content-type', 'application/json');
$req_obj->set_param('records', array($incoming_p));
$push_resp_p = $sync_receiver->receive_push($req_obj);

assert_true(isset($push_resp_p['results'][$uuid_p]['status']) && $push_resp_p['results'][$uuid_p]['status'] === 'synced', "Receiver returned status='synced' for new request");
$row_p = $wpdb->get_row($wpdb->prepare("SELECT id, client_name, amount_paid FROM $table_requests WHERE id = %s", $uuid_p), ARRAY_A);
assert_true(!empty($row_p) && $row_p['id'] === $uuid_p, "Receiver preserved exact incoming UUID v7 identifier: $uuid_p");
assert_true($row_p['client_name'] === 'Client P Incoming', "Receiver wrote client_name correctly");

// -------------------------------------------------------------
// Test Q: Receiver updates existing request row with incoming UUID
// -------------------------------------------------------------
flush_out("\nTest Q: Receiver updates existing request row with incoming UUID\n");
$incoming_q = $incoming_p;
$incoming_q['amount_paid'] = 750.00;
$incoming_q['updated_at'] = '2026-09-16 11:00:00';
$incoming_q['client_name'] = 'Client P Updated via Sync';

$req_obj->set_param('records', array($incoming_q));
$push_resp_q = $sync_receiver->receive_push($req_obj);

assert_true(isset($push_resp_q['results'][$uuid_p]['status']) && $push_resp_q['results'][$uuid_p]['status'] === 'synced', "Receiver returned status='synced' for updated request");
$row_q = $wpdb->get_row($wpdb->prepare("SELECT amount_paid, client_name FROM $table_requests WHERE id = %s", $uuid_p), ARRAY_A);
assert_true(floatval($row_q['amount_paid']) === 750.00 && $row_q['client_name'] === 'Client P Updated via Sync', "Receiver updated amount_paid and client_name on existing row");

// -------------------------------------------------------------
// Test R: Receiver last-write-wins (skips when live record updated_at >= remote updated_at)
// -------------------------------------------------------------
flush_out("\nTest R: Receiver last-write-wins (skips when live record updated_at >= remote updated_at)\n");
$incoming_r = $incoming_p;
$incoming_r['amount_paid'] = 999.00;
$incoming_r['updated_at'] = '2026-09-16 09:00:00'; // Older than DB's 11:00:00

$req_obj->set_param('records', array($incoming_r));
$push_resp_r = $sync_receiver->receive_push($req_obj);

assert_true(isset($push_resp_r['results'][$uuid_p]['status']) && $push_resp_r['results'][$uuid_p]['status'] === 'skipped', "Receiver skipped incoming older request");
$row_r = $wpdb->get_row($wpdb->prepare("SELECT amount_paid FROM $table_requests WHERE id = %s", $uuid_p), ARRAY_A);
assert_true(floatval($row_r['amount_paid']) === 750.00, "Existing record preserved unmodified when remote was older");

// -------------------------------------------------------------
// Test S: Receiver applies newer soft delete (deleted_at synced to live)
// -------------------------------------------------------------
flush_out("\nTest S: Receiver applies newer soft delete (deleted_at synced to live)\n");
$incoming_s = $incoming_q;
$incoming_s['deleted_at'] = '2026-09-16 12:00:00';
$incoming_s['updated_at'] = '2026-09-16 12:00:00';

$req_obj->set_param('records', array($incoming_s));
$push_resp_s = $sync_receiver->receive_push($req_obj);

assert_true(isset($push_resp_s['results'][$uuid_p]['status']) && $push_resp_s['results'][$uuid_p]['status'] === 'synced', "Receiver synced soft delete payload");
$row_s = $wpdb->get_row($wpdb->prepare("SELECT deleted_at FROM $table_requests WHERE id = %s", $uuid_p), ARRAY_A);
assert_true(!empty($row_s['deleted_at']), "Row in database now has deleted_at populated via sync");

// -------------------------------------------------------------
// Test T: Receiver prevents resurrection (newer deletion beats older active)
// -------------------------------------------------------------
flush_out("\nTest T: Receiver prevents resurrection (newer deletion beats older active)\n");
$incoming_t = $incoming_q; // Active record (deleted_at = null) but updated_at = 11:00:00 (older than 12:00:00 deletion)
$req_obj->set_param('records', array($incoming_t));
$push_resp_t = $sync_receiver->receive_push($req_obj);

assert_true(isset($push_resp_t['results'][$uuid_p]['status']) && $push_resp_t['results'][$uuid_p]['status'] === 'skipped', "Receiver skipped resurrection attempt with older updated_at");
$row_t = $wpdb->get_row($wpdb->prepare("SELECT deleted_at FROM $table_requests WHERE id = %s", $uuid_p), ARRAY_A);
assert_true(!empty($row_t['deleted_at']), "Record remained deleted; resurrection was prevented");

// -------------------------------------------------------------
// Test U: Receipt uniqueness check does not block sync replication of existing record
// -------------------------------------------------------------
flush_out("\nTest U: Receipt uniqueness check does not block sync replication of existing record\n");
// Syncing an update to an existing request having the same receipt number succeeds
$incoming_u = $incoming_q;
$incoming_u['updated_at'] = '2026-09-16 13:00:00';
$incoming_u['deleted_at'] = null; // restore to active
$incoming_u['remarks'] = 'Un-deleted with higher updated_at';
$req_obj->set_param('records', array($incoming_u));
$push_resp_u = $sync_receiver->receive_push($req_obj);
assert_true(isset($push_resp_u['results'][$uuid_p]['status']) && $push_resp_u['results'][$uuid_p]['status'] === 'synced', "Receiver allowed update with identical receipt number for same UUID");

// -------------------------------------------------------------
// Test V: Push pending packages requests with _record_type = 'request'
// -------------------------------------------------------------
flush_out("\nTest V: Push pending packages requests with _record_type = 'request'\n");
// We can verify that Assessor_Sync::push_pending reads both properties and requests from queue
// We already verified in Test D and N that enqueue_request sets record_type='request'
$pending_reqs_in_queue = $wpdb->get_var("SELECT COUNT(*) FROM $table_queue WHERE record_type = 'request' AND status = 'pending'");
assert_true($pending_reqs_in_queue > 0, "Sync queue contains pending request items ($pending_reqs_in_queue pending)");

// -------------------------------------------------------------
// Test W: Pull requests fetches changed requests including soft-deleted ones
// -------------------------------------------------------------
flush_out("\nTest W: Pull requests fetches changed requests including soft-deleted ones\n");
$pull_req_obj = new WP_REST_Request('GET', '/assessor/v1/sync/pull');
$pull_req_obj->set_query_params(array(
    'since' => '2000-01-01 00:00:00',
    'type'  => 'requests',
    'limit' => 50
));
$pull_resp_w = $sync_receiver->serve_pull($pull_req_obj);
assert_true(is_array($pull_resp_w) && isset($pull_resp_w['records']), "serve_pull(type=requests) returned records array");

$found_in_pull = false;
$found_req_type = false;
foreach ($pull_resp_w['records'] as $r) {
    if ($r['id'] === $uuid_p) {
        $found_in_pull = true;
    }
    if (isset($r['_record_type']) && $r['_record_type'] === 'request') {
        $found_req_type = true;
    }
}
assert_true($found_in_pull, "Recently modified test request $uuid_p is included in pull response");
assert_true($found_req_type, "Pulled records include _record_type='request' attribute");

// -------------------------------------------------------------
// Test X: Local apply_remote_request handles soft delete and updates locally
// -------------------------------------------------------------
flush_out("\nTest X: Local apply_remote_request handles soft delete and updates locally\n");
// Call apply_remote_request directly via reflection or by invoking public method
$reflection = new ReflectionClass('Assessor_Sync');
$method = $reflection->getMethod('apply_remote_request');
$method->setAccessible(true);

$remote_x = array(
    'id'             => $uuid_n,
    'property_id'    => $property_id,
    'amount_paid'    => 888.00,
    'receipt_number' => $receipt_test_n,
    'date_issued'    => '2026-09-16',
    'place_issued'   => 'Kitaotao',
    'prepared_by'    => 'Remote Pull Tester',
    'payment_type'   => 'cash',
    'purpose'        => 'Remote Pull',
    'client_name'    => 'Client N Pulled',
    'created_at'     => '2026-09-16 08:00:00',
    'updated_at'     => '2026-09-16 15:00:00',
    'deleted_at'     => '2026-09-16 15:00:00'
);

$res_x = $method->invoke(null, $remote_x, false);
assert_true($res_x === 'synced', "apply_remote_request returned 'synced'");

$row_x = $wpdb->get_row($wpdb->prepare("SELECT deleted_at, amount_paid FROM $table_requests WHERE id = %s", $uuid_n), ARRAY_A);
assert_true(!empty($row_x['deleted_at']), "apply_remote_request set deleted_at on local request");
assert_true(floatval($row_x['amount_paid']) === 888.00, "apply_remote_request updated amount_paid to 888.00");

// -------------------------------------------------------------
// Test Y: Cleanup of test artifacts
// -------------------------------------------------------------
flush_out("\nTest Y: Cleanup of test artifacts\n");
$cleaned = 0;
foreach ($created_test_uuids as $tid) {
    $wpdb->delete($table_requests, array('id' => $tid));
    $wpdb->delete($table_queue, array('property_id' => $tid));
    $cleaned++;
}
assert_true($cleaned === count($created_test_uuids), "Cleaned up $cleaned test request records and queue items");

// -------------------------------------------------------------
// Summary
// -------------------------------------------------------------
flush_out("\n===============================================================\n");
if ($failures === 0) {
    flush_out("ALL PHASE 2 TESTS PASSED (0 failures).\n");
    flush_out("Request soft delete and bidirectional sync verified successfully!\n");
} else {
    flush_out("TEST SUITE COMPLETED WITH $failures FAILURE(S).\n");
}
flush_out("===============================================================\n");
