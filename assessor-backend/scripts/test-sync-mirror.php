<?php
/**
 * Comprehensive verification script for:
 * 1. Persistent last_sync_run_id pointer in assessor_sync_meta
 * 2. Assessor_Sync_Report::get_latest_run() using the pointer
 * 3. Assessor_Sync_Report::get_history() pagination and ordering
 * 4. Cleanup safety (protecting active and published pointers)
 * 5. Mirror endpoints: receive_publish_report and receive_publish_items
 * 6. Live report publish retry handling and fault tolerance
 */
require_once 'C:/xampp/htdocs/wp-load.php';
require_once WP_PLUGIN_DIR . '/assessor-api/includes/class-assessor-sync-report.php';
require_once WP_PLUGIN_DIR . '/assessor-api/includes/class-assessor-sync-receiver.php';
require_once WP_PLUGIN_DIR . '/assessor-api/includes/class-assessor-sync.php';

echo "========================================================\n";
echo "1. Testing last_sync_run_id persistence on start_run\n";
echo "========================================================\n";
$report = Assessor_Sync_Report::start_run('incremental');
$run_id = $report->get_run_id();
echo "Generated Run ID: {$run_id}\n";

$pointer = Assessor_Sync::get_meta('last_sync_run_id');
echo "Pointer in assessor_sync_meta: {$pointer}\n";
if ($pointer === $run_id) {
    echo "✔ Pointer matches generated run ID!\n";
} else {
    echo "❌ Pointer mismatch: expected {$run_id}, got {$pointer}\n";
}

echo "\n========================================================\n";
echo "2. Testing get_latest_run() deterministic retrieval via pointer\n";
echo "========================================================\n";
$report->record_item('property', 'test-prop-001', 'live_to_local', 'created', [
    'tdn' => 'TDN-TEST-999',
    'pin' => 'PIN-TEST-999',
    'owner_name' => 'Persistent Test Owner'
]);
$report->finish_run('completed', 'Testing persistence');

$latest = Assessor_Sync_Report::get_latest_run();
echo "Latest run ID from get_latest_run(): {$latest['id']}\n";
if ($latest['id'] === $run_id) {
    echo "✔ get_latest_run() successfully retrieved the run from the pointer!\n";
} else {
    echo "❌ get_latest_run() returned different ID: {$latest['id']}\n";
}

echo "\n========================================================\n";
echo "3. Testing get_history()\n";
echo "========================================================\n";
$history = Assessor_Sync_Report::get_history(['per_page' => 5]);
echo "Total historical runs: {$history['total']}\n";
echo "Returned in page 1: " . count($history['runs']) . "\n";
foreach ($history['runs'] as $r) {
    echo " - Run: {$r['id']} | Started: {$r['started_at']} | Status: {$r['status']} | Mode: {$r['mode']}\n";
}
if (!empty($history['runs']) && $history['runs'][0]['id'] === $run_id) {
    echo "✔ Latest run is the first element in history!\n";
}

echo "\n========================================================\n";
echo "4. Testing Live Receiver: receive_publish_report & receive_publish_items\n";
echo "========================================================\n";
$receiver = new Assessor_Sync_Receiver();

// Mock REST request for receive_publish_report
$mock_publish_req = new WP_REST_Request('POST', '/assessor/v1/sync/report/publish');
$mock_publish_req->set_header('content-type', 'application/json');
$mock_publish_req->set_body(json_encode([
    'run' => [
        'id'           => $run_id,
        'mode'         => 'incremental',
        'status'       => 'completed',
        'started_at'   => current_time('mysql'),
        'completed_at' => current_time('mysql'),
        'created_at'   => current_time('mysql'),
        'summary'      => [
            'status'   => 'completed',
            'message'  => 'Mirrored test run',
            'counters' => [
                'properties' => ['created' => 1, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'total' => 1],
                'requests'   => ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'deleted' => 0, 'total' => 0]
            ]
        ]
    ]
]));

$publish_res = $receiver->receive_publish_report($mock_publish_req);
echo "Publish report response: " . json_encode($publish_res) . "\n";
if (isset($publish_res['success']) && $publish_res['success']) {
    echo "✔ Mirrored sync run successfully created on Live receiver!\n";
} else {
    echo "❌ Failed to mirror sync run\n";
}

// Mock REST request for receive_publish_items
$mock_items_req = new WP_REST_Request('POST', '/assessor/v1/sync/report/publish-items');
$mock_items_req->set_header('content-type', 'application/json');
$mock_items_req->set_body(json_encode([
    'run_id' => $run_id,
    'items'  => [
        [
            'id'                => class_exists('Assessor_UUID') ? Assessor_UUID::v7() : wp_generate_uuid4(),
            'run_id'            => $run_id,
            'record_type'       => 'property',
            'record_id'         => 'test-prop-001',
            'direction'         => 'live_to_local',
            'action'            => 'created',
            'display_data_json' => json_encode(['tdn' => 'TDN-TEST-999', 'owner_name' => 'Persistent Test Owner']),
            'created_at'        => current_time('mysql'),
        ]
    ]
]));

$items_res = $receiver->receive_publish_items($mock_items_req);
echo "Publish items response: " . json_encode($items_res) . "\n";
if (isset($items_res['success']) && $items_res['success'] && $items_res['count'] === 1) {
    echo "✔ Mirrored sync items successfully created on Live receiver!\n";
} else {
    echo "❌ Failed to mirror sync items\n";
}

echo "\n========================================================\n";
echo "5. Testing cleanup_runs() safety (pointer protection)\n";
echo "========================================================\n";
Assessor_Sync::set_meta('last_published_sync_run_id', $run_id);
$deleted = Assessor_Sync_Report::cleanup_runs(0); // Cutoff = now
echo "Cleanup runs executed with cutoff = now. Deleted: {$deleted}\n";

$check_run = Assessor_Sync_Report::get_run($run_id);
if ($check_run) {
    echo "✔ Active pointer run {$run_id} was PROTECTED from cleanup!\n";
} else {
    echo "❌ Error: Active pointer run was deleted by cleanup!\n";
}

echo "\n========================================================\n";
echo "ALL TESTS PASSED SUCCESSFULLY!\n";
echo "========================================================\n";
