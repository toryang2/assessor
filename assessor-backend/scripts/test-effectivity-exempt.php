<?php
/**
 * Test Effectivity Exempt logic and revision resolution.
 */

$wp_load_paths = array(
    'C:/xampp/htdocs/wp-load.php',
    '/home/u799325560/domains/archive.massokitaotao.net/public_html/wp-load.php',
    __DIR__ . '/../../../../wp-load.php',
    __DIR__ . '/../../../wp-load.php',
    __DIR__ . '/../../wp-load.php',
    dirname(__FILE__) . '/../wp-load.php',
    isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] . '/wp-load.php' : ''
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

echo "=== Testing Effectivity Exempt Logic ===\n";

global $wpdb;
$table = $wpdb->prefix . 'assessor_properties';
$table_versions = $wpdb->prefix . 'assessor_property_versions';

$properties_api = new Assessor_Properties();

// Ensure there is an active revision for test year (e.g. 2025 or whatever revisions exist)
$test_revision_year = '2025';
$matching_revision = $wpdb->get_row("SELECT id, revision_year, from_year, to_year FROM {$wpdb->prefix}assessor_revision_entries WHERE status = 'active' AND from_year <= 2025 AND (to_year = 'present' OR to_year >= 2025) LIMIT 1");
$test_revision_id = $matching_revision ? $matching_revision->id : null;
echo "Active revision found for 2025: Year {$matching_revision->revision_year} (ID: {$test_revision_id})\n";

// Helper to cleanup test TDN
function cleanup_tdn($tdn) {
    global $wpdb;
    $table = $wpdb->prefix . 'assessor_properties';
    $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE tax_declaration_number = %s", $tdn));
}

$test_tdn = 'TEST-EFF-' . time();
cleanup_tdn($test_tdn);

// Test 1: Create Normal (2025 or active revision year, exempt = 0)
echo "\nTest 1: Create Normal Year ($test_revision_year / 0)...\n";
$create_request = new WP_REST_Request('POST', '/assessor/v1/properties');
$create_request->set_body_params(array(
    'tax_declaration_number' => $test_tdn,
    'location'               => 'Test Location',
    'kind_of_property'       => 'Land',
    'effectivity_date'       => $test_revision_year,
    'effectivity_exempt'     => 0,
));
$res = $properties_api->create_property($create_request);
if (is_wp_error($res)) {
    echo "FAILED Test 1: " . $res->get_error_message() . "\n";
    exit(1);
}
$prop_id = is_object($res) ? $res->id : (is_array($res) ? $res['id'] : $res->get_data()['id']);
$row = $wpdb->get_row($wpdb->prepare("SELECT effectivity_date, effectivity_exempt, revision_id FROM $table WHERE id = %s", $prop_id), ARRAY_A);
echo "Result 1: date={$row['effectivity_date']}, exempt={$row['effectivity_exempt']}, rev={$row['revision_id']}\n";
assert($row['effectivity_date'] === $test_revision_year, "Test 1 effectivity_date matches");
assert((int)$row['effectivity_exempt'] === 0, "Test 1 effectivity_exempt is 0");
if ($test_revision_id) {
    assert($row['revision_id'] === $test_revision_id, "Test 1 revision_id matches active revision");
}
echo "PASSED Test 1\n";

// Test 2: Update Normal -> EXEMPT (effectivity_date = null, effectivity_exempt = 1, revision_id = null)
echo "\nTest 2: Update Normal -> EXEMPT...\n";
$update_request = new WP_REST_Request('PUT', "/assessor/v1/properties/{$prop_id}");
$update_request->set_url_params(array('id' => $prop_id));
$update_request->set_body_params(array(
    'effectivity_date'   => '',
    'effectivity_exempt' => true,
));
$res = $properties_api->update_property($prop_id, $update_request);
if (is_wp_error($res)) {
    echo "FAILED Test 2: " . $res->get_error_message() . "\n";
    exit(1);
}
$row = $wpdb->get_row($wpdb->prepare("SELECT effectivity_date, effectivity_exempt, revision_id FROM $table WHERE id = %s", $prop_id), ARRAY_A);
echo "Result 2: date=" . var_export($row['effectivity_date'], true) . ", exempt={$row['effectivity_exempt']}, rev=" . var_export($row['revision_id'], true) . "\n";
assert($row['effectivity_date'] === null, "Test 2 effectivity_date is null");
assert((int)$row['effectivity_exempt'] === 1, "Test 2 effectivity_exempt is 1");
assert($row['revision_id'] === null, "Test 2 revision_id is null");
echo "PASSED Test 2\n";

// Test 3: Update EXEMPT -> BLANK (effectivity_date = null, effectivity_exempt = 0, revision_id = null)
echo "\nTest 3: Update EXEMPT -> BLANK...\n";
$update_request = new WP_REST_Request('PUT', "/assessor/v1/properties/{$prop_id}");
$update_request->set_url_params(array('id' => $prop_id));
$update_request->set_body_params(array(
    'effectivity_date'   => '',
    'effectivity_exempt' => false,
));
$res = $properties_api->update_property($prop_id, $update_request);
if (is_wp_error($res)) {
    echo "FAILED Test 3: " . $res->get_error_message() . "\n";
    exit(1);
}
$row = $wpdb->get_row($wpdb->prepare("SELECT effectivity_date, effectivity_exempt, revision_id FROM $table WHERE id = %s", $prop_id), ARRAY_A);
echo "Result 3: date=" . var_export($row['effectivity_date'], true) . ", exempt={$row['effectivity_exempt']}, rev=" . var_export($row['revision_id'], true) . "\n";
assert($row['effectivity_date'] === null, "Test 3 effectivity_date is null");
assert((int)$row['effectivity_exempt'] === 0, "Test 3 effectivity_exempt is 0");
assert($row['revision_id'] === null, "Test 3 revision_id is null");
echo "PASSED Test 3\n";

// Test 4: Update BLANK -> Normal Year
echo "\nTest 4: Update BLANK -> Normal Year ($test_revision_year)...\n";
$update_request = new WP_REST_Request('PUT', "/assessor/v1/properties/{$prop_id}");
$update_request->set_url_params(array('id' => $prop_id));
$update_request->set_body_params(array(
    'effectivity_date'   => $test_revision_year,
    'effectivity_exempt' => false,
));
$res = $properties_api->update_property($prop_id, $update_request);
if (is_wp_error($res)) {
    echo "FAILED Test 4: " . $res->get_error_message() . "\n";
    exit(1);
}
$row = $wpdb->get_row($wpdb->prepare("SELECT effectivity_date, effectivity_exempt, revision_id FROM $table WHERE id = %s", $prop_id), ARRAY_A);
echo "Result 4: date={$row['effectivity_date']}, exempt={$row['effectivity_exempt']}, rev={$row['revision_id']}\n";
assert($row['effectivity_date'] === $test_revision_year, "Test 4 effectivity_date matches");
assert((int)$row['effectivity_exempt'] === 0, "Test 4 effectivity_exempt is 0");
if ($test_revision_id) {
    assert($row['revision_id'] === $test_revision_id, "Test 4 revision_id matches active revision");
}
echo "PASSED Test 4\n";

// Test 4b: Update with effectivity_exempt = "0" (must NOT be interpreted as EXEMPT)
echo "\nTest 4b: Update with effectivity_exempt = \"0\" and effectivity_date = NULL (revision_id = NULL)...\n";
$update_request = new WP_REST_Request('PUT', "/assessor/v1/properties/{$prop_id}");
$update_request->set_url_params(array('id' => $prop_id));
$update_request->set_body_params(array(
    'effectivity_date'   => '',
    'effectivity_exempt' => "0",
));
$res = $properties_api->update_property($prop_id, $update_request);
if (is_wp_error($res)) {
    echo "FAILED Test 4b: " . $res->get_error_message() . "\n";
    exit(1);
}
$row = $wpdb->get_row($wpdb->prepare("SELECT effectivity_date, effectivity_exempt, revision_id FROM $table WHERE id = %s", $prop_id), ARRAY_A);
echo "Result 4b: date=" . var_export($row['effectivity_date'], true) . ", exempt={$row['effectivity_exempt']}, rev=" . var_export($row['revision_id'], true) . "\n";
assert($row['effectivity_date'] === null, "Test 4b effectivity_date is null");
assert((int)$row['effectivity_exempt'] === 0, "Test 4b effectivity_exempt is 0 (string '0' was not treated as true)");
assert($row['revision_id'] === null, "Test 4b revision_id is null");
echo "PASSED Test 4b\n";

// Test 4c: Update with effectivity_exempt = "1" (must be interpreted as EXEMPT)
echo "\nTest 4c: Update with effectivity_exempt = \"1\"...\n";
$update_request = new WP_REST_Request('PUT', "/assessor/v1/properties/{$prop_id}");
$update_request->set_url_params(array('id' => $prop_id));
$update_request->set_body_params(array(
    'effectivity_date'   => '',
    'effectivity_exempt' => "1",
));
$res = $properties_api->update_property($prop_id, $update_request);
if (is_wp_error($res)) {
    echo "FAILED Test 4c: " . $res->get_error_message() . "\n";
    exit(1);
}
$row = $wpdb->get_row($wpdb->prepare("SELECT effectivity_date, effectivity_exempt, revision_id FROM $table WHERE id = %s", $prop_id), ARRAY_A);
echo "Result 4c: date=" . var_export($row['effectivity_date'], true) . ", exempt={$row['effectivity_exempt']}, rev=" . var_export($row['revision_id'], true) . "\n";
assert($row['effectivity_date'] === null, "Test 4c effectivity_date is null");
assert((int)$row['effectivity_exempt'] === 1, "Test 4c effectivity_exempt is 1 (string '1' was treated as exempt)");
assert($row['revision_id'] === null, "Test 4c revision_id is null");
echo "PASSED Test 4c\n";

// Test 5: Rejection of invalid years
echo "\nTest 5: Rejection of invalid years...\n";
$invalid_years = array('1799', '2101', '202A', '2025.5', '123', '12345');
foreach ($invalid_years as $inv) {
    $bad_request = new WP_REST_Request('PUT', "/assessor/v1/properties/{$prop_id}");
    $bad_request->set_url_params(array('id' => $prop_id));
    $bad_request->set_body_params(array(
        'effectivity_date'   => $inv,
        'effectivity_exempt' => false,
    ));
    $bad_res = $properties_api->update_property($prop_id, $bad_request);
    assert(is_wp_error($bad_res), "Expected WP_Error for invalid year '{$inv}'");
    echo "  Invalid year '{$inv}' correctly rejected with status: " . $bad_res->get_error_data()['status'] . "\n";
}
echo "PASSED Test 5\n";

// Test 6: Versioning and restore
echo "\nTest 6: Version history and restore...\n";
$versions_api = new Assessor_Versions();
$versions = $wpdb->get_results($wpdb->prepare("SELECT id, effectivity_date, effectivity_exempt FROM $table_versions WHERE property_id = %s ORDER BY version_number DESC", $prop_id), ARRAY_A);
echo "  Property versions recorded: " . count($versions) . "\n";
foreach ($versions as $v) {
    echo "    Version {$v['id']}: date=" . var_export($v['effectivity_date'], true) . ", exempt=" . var_export($v['effectivity_exempt'], true) . "\n";
}

// Check restore of an exempt version or blank version
$exempt_version = null;
foreach ($versions as $v) {
    if ((int)$v['effectivity_exempt'] === 1) {
        $exempt_version = $v;
        break;
    }
}
if ($exempt_version) {
    echo "  Restoring version {$exempt_version['id']} (EXEMPT)...\n";
    $restored = $versions_api->restore_version($prop_id, $exempt_version['id']);
    assert(!is_wp_error($restored), "Restore succeeded");
    $row = $wpdb->get_row($wpdb->prepare("SELECT effectivity_date, effectivity_exempt, revision_id FROM $table WHERE id = %s", $prop_id), ARRAY_A);
    echo "  After restore: date=" . var_export($row['effectivity_date'], true) . ", exempt={$row['effectivity_exempt']}, rev=" . var_export($row['revision_id'], true) . "\n";
    assert($row['effectivity_date'] === null, "Restored effectivity_date is null");
    assert((int)$row['effectivity_exempt'] === 1, "Restored effectivity_exempt is 1");
    assert($row['revision_id'] === null, "Restored revision_id is null");
    echo "PASSED Test 6\n";
}

// Test 7: Sync receiver sanitization
echo "\nTest 7: Sync receiver sanitization...\n";
$sync_receiver = new Assessor_Sync_Receiver();
$ref_class = new ReflectionClass('Assessor_Sync_Receiver');
$method_incoming = $ref_class->getMethod('sanitize_incoming_record');
$method_incoming->setAccessible(true);
$method_outgoing = $ref_class->getMethod('sanitize_outgoing_record');
$method_outgoing->setAccessible(true);

$raw_sync_record = array(
    'id' => $prop_id,
    'tax_declaration_number' => $test_tdn,
    'effectivity_date' => null,
    'effectivity_exempt' => 1,
    'unwanted_field' => 'should be stripped'
);

$sanitized_in = $method_incoming->invoke($sync_receiver, $raw_sync_record);
assert(array_key_exists('effectivity_date', $sanitized_in), "effectivity_date present in sanitized incoming");
assert(array_key_exists('effectivity_exempt', $sanitized_in), "effectivity_exempt present in sanitized incoming");
assert(!array_key_exists('unwanted_field', $sanitized_in), "unwanted_field stripped in incoming");
assert($sanitized_in['effectivity_exempt'] === 1, "effectivity_exempt retained value 1");

$sanitized_out = $method_outgoing->invoke($sync_receiver, $raw_sync_record);
assert(array_key_exists('effectivity_date', $sanitized_out), "effectivity_date present in sanitized outgoing");
assert(array_key_exists('effectivity_exempt', $sanitized_out), "effectivity_exempt present in sanitized outgoing");
assert(!array_key_exists('unwanted_field', $sanitized_out), "unwanted_field stripped in outgoing");
assert($sanitized_out['effectivity_exempt'] === 1, "effectivity_exempt retained value 1");
echo "PASSED Test 7\n";

// Cleanup
cleanup_tdn($test_tdn);
$wpdb->query($wpdb->prepare("DELETE FROM $table_versions WHERE property_id = %s", $prop_id));

echo "\nALL EFFECTIVITY EXEMPT TESTS PASSED SUCCESSFULLY!\n";
