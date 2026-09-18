<?php
require_once 'C:/xampp/htdocs/wp-load.php';
require_once WP_PLUGIN_DIR . '/assessor-api/includes/class-assessor-sync-report.php';

echo "=== 1. Starting run ===\n";
$report = Assessor_Sync_Report::start_run('incremental');
$run_id = $report->get_run_id();
echo "Run ID created: {$run_id}\n";

echo "=== 2. Updating Phase ===\n";
$report->update_phase('properties', 'completed', ['count' => 1]);

echo "=== 3. Recording items ===\n";
$report->record_item('property', 'prop-uuid-1', 'live_to_local', 'created', [
    'tdn' => 'TDN-2026-001',
    'pin' => 'PIN-100-001',
    'owner_name' => 'Juan Dela Cruz'
]);
$report->record_item('request', 'req-uuid-1', 'local_to_live', 'updated', [
    'request_no' => 'REQ-2026-001',
    'client_name' => 'Maria Santos'
]);

echo "=== 4. Finishing run ===\n";
$summary = $report->finish_run('completed', 'Sync completed successfully');
echo "Finished run summary status: {$summary['status']}\n";

echo "=== 5. Querying latest run ===\n";
$latest = Assessor_Sync_Report::get_latest_run();
echo "Latest run ID: {$latest['id']}\n";

echo "=== 6. Querying items ===\n";
$items_res = Assessor_Sync_Report::get_run_items($run_id);
echo "Items returned: " . count($items_res['items']) . " (Total: {$items_res['total']})\n";
foreach ($items_res['items'] as $item) {
    echo " - [{$item['record_type']}] Action: {$item['action']}, Details: " . json_encode($item['display_data']) . "\n";
}

echo "=== SUCCESS! ===\n";
