<?php
require 'C:/xampp/htdocs/wp-load.php';

// Inspect what 10-001-02425 has as revision_id on LIVE
$url = rtrim(ASSESSOR_LIVE_SITE_URL, '/') . '/wp-json/assessor/v1/sync/pull?limit=50&since=2000-01-01%2000:00:00';
// We can also query the live API specifically or search
$response = Assessor_Sync::live_api_request('GET', '/assessor/v1/sync/pull', array('limit' => 500, 'offset' => 1000));
$body = json_decode(wp_remote_retrieve_body($response), true);
$found = null;
if (!empty($body['records'])) {
    foreach ($body['records'] as $r) {
        if (($r['tax_declaration_number'] ?? '') === '10-001-02425') {
            $found = $r;
            break;
        }
    }
}

echo "FOUND RECORD:\n";
print_r($found);

global $wpdb;
$revs = $wpdb->get_results("SELECT id, revision_code FROM {$wpdb->prefix}assessor_revision_entries", ARRAY_A);
echo "LOCAL REVISIONS:\n";
print_r($revs);
