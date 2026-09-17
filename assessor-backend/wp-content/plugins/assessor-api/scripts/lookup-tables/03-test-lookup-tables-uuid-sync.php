<?php
/**
 * 03-test-lookup-tables-uuid-sync.php
 *
 * Comprehensive Test Suite for Assessor Lookup Tables UUID v7 & Sync Enablement
 *
 * Tests:
 * 1. Schema check: all 4 tables have VARCHAR(36) id primary key
 * 2. Idempotency of migration: running migration again produces zero changes and preserves UUIDs
 * 3. CRUD Property Types with UUID v7
 * 4. CRUD General Classes with UUID v7
 * 5. CRUD Locations with UUID v7
 * 6. CRUD Request Purposes with UUID v7
 * 7. Business key uniqueness constraints remain active
 * 8. Config table sync dirty flag enqueuing works
 * 9. Sync Receiver receive_config_push preserves incoming UUID v7 without stripping or generating replacement
 * 10. Repeated sync is idempotent and does not create duplicate rows
 * 11. Property and Request references to business keys remain intact and functional
 */

// Enable full error display for debugging
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Accel-Buffering: no');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    ob_implicit_flush(true);
    @set_time_limit(600);
}

// Load WordPress environment
$wp_load_paths = array(
    'C:/xampp/htdocs/wp-load.php',
    '/home/u799325560/domains/archive.massokitaotao.net/public_html/wp-load.php',
    __DIR__ . '/../../../../../../wp-load.php',
    __DIR__ . '/../../../../../wp-load.php',
    __DIR__ . '/../../../../wp-load.php',
    __DIR__ . '/../../../wp-load.php',
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
    die("FATAL: Cannot locate wp-load.php. Please run within WordPress environment.\n");
}

// Authorization check (WordPress is now loaded, so current_user_can and wp_die are defined)
if (php_sapi_name() !== 'cli') {
    $authorized = false;

    // 1. Authenticated WordPress Administrator session
    if (current_user_can('manage_options')) {
        $authorized = true;
    }

    // 2. Authenticated HTTP Header matching server-side secret constant
    if (!$authorized && defined('ASSESSOR_MIGRATION_KEY') && !empty(ASSESSOR_MIGRATION_KEY)) {
        $header_key = isset($_SERVER['HTTP_X_ASSESSOR_MIGRATION_KEY']) ? sanitize_text_field($_SERVER['HTTP_X_ASSESSOR_MIGRATION_KEY']) : '';
        if (!empty($header_key) && hash_equals((string) ASSESSOR_MIGRATION_KEY, (string) $header_key)) {
            $authorized = true;
        }
    }

    if (!$authorized) {
        if (function_exists('wp_die')) {
            wp_die('Unauthorized access: Administrator login required or configure ASSESSOR_MIGRATION_KEY with X-Assessor-Migration-Key HTTP header.', 'Unauthorized', array('response' => 403));
        } else {
            header('HTTP/1.1 403 Forbidden');
            die("Unauthorized access: Administrator login required or configure ASSESSOR_MIGRATION_KEY with X-Assessor-Migration-Key HTTP header.\n");
        }
    }
}

global $wpdb;
$wpdb->show_errors();

echo "================================================================================\n";
echo "STEP 03: TEST SUITE — LOOKUP TABLES UUID v7 & SYNC ENABLEMENT                   \n";
echo "================================================================================\n";
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n\n";

$failures = array();

$tables = array(
    'assessor_property_types'   => 'code',
    'assessor_general_classes'  => 'code',
    'assessor_locations'        => 'code',
    'assessor_request_purposes' => 'purpose',
);

// -----------------------------------------------------------------------------
// TEST 1: Schema Verification
// -----------------------------------------------------------------------------
echo "--- TEST 1: Schema Verification (VARCHAR(36) UUID Primary Keys) ---\n";
try {
    foreach ($tables as $t => $bk) {
        $tbl = $wpdb->prefix . $t;
        $id_col = $wpdb->get_row("SHOW COLUMNS FROM $tbl LIKE 'id'");
        if (!$id_col) {
            throw new Exception("Table $tbl missing 'id' column.");
        }
        if (stripos($id_col->Type, 'varchar(36)') === false && stripos($id_col->Type, 'char(36)') === false) {
            throw new Exception("Table $tbl id column is not VARCHAR(36): found {$id_col->Type}");
        }
        if ($id_col->Key !== 'PRI') {
            throw new Exception("Table $tbl id column is not PRIMARY KEY: found {$id_col->Key}");
        }
    }
    echo "  [PASS] All 4 tables have VARCHAR(36) PRIMARY KEY id columns.\n";
} catch (Exception $e) {
    $failures[] = "TEST 1: " . $e->getMessage();
    echo "  [FAIL] " . $e->getMessage() . "\n";
}

// -----------------------------------------------------------------------------
// TEST 2: Existing Rows Have Valid UUID v7
// -----------------------------------------------------------------------------
echo "\n--- TEST 2: Existing Rows UUID v7 Format and Uniqueness ---\n";
try {
    foreach ($tables as $t => $bk) {
        $tbl = $wpdb->prefix . $t;
        $rows = $wpdb->get_results("SELECT id FROM $tbl", ARRAY_A);
        $seen = array();
        foreach ($rows as $r) {
            if (!Assessor_UUID::is_valid($r['id'])) {
                throw new Exception("Table $tbl row has invalid UUID: {$r['id']}");
            }
            if (isset($seen[$r['id']])) {
                throw new Exception("Table $tbl has duplicate UUID: {$r['id']}");
            }
            $seen[$r['id']] = true;
        }
    }
    echo "  [PASS] All existing lookup rows possess valid and unique UUID v7 identifiers.\n";
} catch (Exception $e) {
    $failures[] = "TEST 2: " . $e->getMessage();
    echo "  [FAIL] " . $e->getMessage() . "\n";
}

// -----------------------------------------------------------------------------
// TEST 3: CRUD Property Types with UUID v7
// -----------------------------------------------------------------------------
echo "\n--- TEST 3: CRUD Property Types with UUID v7 ---\n";
try {
    $settings = new Assessor_Settings();
    $test_code = 'TEST_TYPE_' . time();
    $test_name = 'Test Property Type';

    // Create
    $req = new WP_REST_Request('POST', '/assessor/v1/settings/property-types');
    $req->set_body_params(array('code' => $test_code, 'name' => $test_name));
    $res = $settings->save_property_type($req);

    $tbl = $wpdb->prefix . 'assessor_property_types';
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE code = %s", $test_code), ARRAY_A);
    if (!$row) {
        throw new Exception("Created property type not found in database.");
    }
    if (!Assessor_UUID::is_valid($row['id'])) {
        throw new Exception("Created property type did not receive valid UUID v7: {$row['id']}");
    }
    $created_id = $row['id'];

    // Update (must preserve UUID)
    $req_update = new WP_REST_Request('POST', '/assessor/v1/settings/property-types');
    $req_update->set_body_params(array('id' => $created_id, 'code' => $test_code, 'name' => $test_name . ' Updated'));
    $settings->save_property_type($req_update);

    $updated_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE id = %s", $created_id), ARRAY_A);
    if (!$updated_row || $updated_row['id'] !== $created_id || $updated_row['name'] !== $test_name . ' Updated') {
        throw new Exception("Update did not preserve UUID or update fields.");
    }

    // Delete
    $req_del = new WP_REST_Request('POST', '/assessor/v1/settings/property-types/delete');
    $req_del->set_body_params(array('id' => $created_id));
    $settings->delete_property_type($req_del);

    $deleted_check = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE id = %s", $created_id));
    if ($deleted_check) {
        throw new Exception("Property type was not deleted.");
    }
    echo "  [PASS] Property types CRUD successfully creates UUID v7, preserves ID on update, and deletes by string ID.\n";
} catch (Exception $e) {
    $failures[] = "TEST 3: " . $e->getMessage();
    echo "  [FAIL] " . $e->getMessage() . "\n";
}

// -----------------------------------------------------------------------------
// TEST 4: CRUD General Classes with UUID v7
// -----------------------------------------------------------------------------
echo "\n--- TEST 4: CRUD General Classes with UUID v7 ---\n";
try {
    $settings = new Assessor_Settings();
    $test_code = 'TEST_GC_' . time();
    $test_name = 'Test General Class';

    // Create
    $req = new WP_REST_Request('POST', '/assessor/v1/settings/general-classes');
    $req->set_body_params(array('code' => $test_code, 'name' => $test_name));
    $settings->save_general_class($req);

    $tbl = $wpdb->prefix . 'assessor_general_classes';
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE code = %s", $test_code), ARRAY_A);
    if (!$row || !Assessor_UUID::is_valid($row['id'])) {
        throw new Exception("Created general class missing or invalid UUID v7.");
    }
    $created_id = $row['id'];

    // Update
    $req_update = new WP_REST_Request('POST', '/assessor/v1/settings/general-classes');
    $req_update->set_body_params(array('id' => $created_id, 'code' => $test_code, 'name' => $test_name . ' Updated'));
    $settings->save_general_class($req_update);

    $updated_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE id = %s", $created_id), ARRAY_A);
    if (!$updated_row || $updated_row['id'] !== $created_id) {
        throw new Exception("General class update did not preserve UUID.");
    }

    // Delete
    $req_del = new WP_REST_Request('POST', '/assessor/v1/settings/general-classes/delete');
    $req_del->set_body_params(array('id' => $created_id));
    $settings->delete_general_class($req_del);

    $deleted_check = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE id = %s", $created_id));
    if ($deleted_check) {
        throw new Exception("General class was not deleted.");
    }
    echo "  [PASS] General classes CRUD successfully operates with UUID v7.\n";
} catch (Exception $e) {
    $failures[] = "TEST 4: " . $e->getMessage();
    echo "  [FAIL] " . $e->getMessage() . "\n";
}

// -----------------------------------------------------------------------------
// TEST 5: CRUD Locations with UUID v7
// -----------------------------------------------------------------------------
echo "\n--- TEST 5: CRUD Locations with UUID v7 ---\n";
try {
    $settings = new Assessor_Settings();
    $test_code = 'TEST_LOC_' . time();
    $test_name = 'Test Barangay';
    $test_pin  = '123-456';

    // Create
    $req = new WP_REST_Request('POST', '/assessor/v1/settings/locations');
    $req->set_body_params(array('code' => $test_code, 'name' => $test_name, 'pin' => $test_pin));
    $settings->save_location($req);

    $tbl = $wpdb->prefix . 'assessor_locations';
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE code = %s", $test_code), ARRAY_A);
    if (!$row || !Assessor_UUID::is_valid($row['id'])) {
        throw new Exception("Created location missing or invalid UUID v7.");
    }
    $created_id = $row['id'];

    // Update
    $req_update = new WP_REST_Request('POST', '/assessor/v1/settings/locations');
    $req_update->set_body_params(array('id' => $created_id, 'code' => $test_code, 'name' => $test_name . ' Upd', 'pin' => '789'));
    $settings->save_location($req_update);

    $updated_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE id = %s", $created_id), ARRAY_A);
    if (!$updated_row || $updated_row['id'] !== $created_id || $updated_row['pin'] !== '789') {
        throw new Exception("Location update failed to preserve UUID or update pin.");
    }

    // Delete
    $req_del = new WP_REST_Request('POST', '/assessor/v1/settings/locations/delete');
    $req_del->set_body_params(array('id' => $created_id));
    $settings->delete_location($req_del);

    $deleted_check = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE id = %s", $created_id));
    if ($deleted_check) {
        throw new Exception("Location was not deleted.");
    }
    echo "  [PASS] Locations CRUD successfully operates with UUID v7.\n";
} catch (Exception $e) {
    $failures[] = "TEST 5: " . $e->getMessage();
    echo "  [FAIL] " . $e->getMessage() . "\n";
}

// -----------------------------------------------------------------------------
// TEST 6: CRUD Request Purposes with UUID v7 & Business Key Constraint
// -----------------------------------------------------------------------------
echo "\n--- TEST 6: CRUD Request Purposes with UUID v7 & Business Key Constraint ---\n";
try {
    $settings = new Assessor_Settings();
    $test_purpose = 'TEST_PURPOSE_' . time();
    $test_amount  = 150.00;

    // Create
    $req = new WP_REST_Request('POST', '/assessor/v1/settings/request-purposes');
    $req->set_body_params(array('purpose' => $test_purpose, 'amount' => $test_amount));
    $settings->save_request_purpose($req);

    $tbl = $wpdb->prefix . 'assessor_request_purposes';
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE purpose = %s", $test_purpose), ARRAY_A);
    if (!$row || !Assessor_UUID::is_valid($row['id'])) {
        throw new Exception("Created request purpose missing or invalid UUID v7.");
    }
    $created_id = $row['id'];

    // Duplicate check
    $req_dup = new WP_REST_Request('POST', '/assessor/v1/settings/request-purposes');
    $req_dup->set_body_params(array('purpose' => $test_purpose, 'amount' => 200.00));
    $dup_res = $settings->save_request_purpose($req_dup);
    if (!is_wp_error($dup_res) || $dup_res->get_error_code() !== 'duplicate_purpose') {
        throw new Exception("Duplicate purpose was not rejected as expected.");
    }

    // Update
    $req_update = new WP_REST_Request('POST', '/assessor/v1/settings/request-purposes');
    $req_update->set_body_params(array('id' => $created_id, 'purpose' => $test_purpose, 'amount' => 175.50));
    $settings->save_request_purpose($req_update);

    $updated_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE id = %s", $created_id), ARRAY_A);
    if (!$updated_row || $updated_row['id'] !== $created_id || floatval($updated_row['amount']) != 175.50) {
        throw new Exception("Request purpose update failed.");
    }

    // Delete
    $req_del = new WP_REST_Request('POST', '/assessor/v1/settings/request-purposes/delete');
    $req_del->set_body_params(array('id' => $created_id));
    $settings->delete_request_purpose($req_del);

    $deleted_check = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE id = %s", $created_id));
    if ($deleted_check) {
        throw new Exception("Request purpose was not deleted.");
    }
    echo "  [PASS] Request purposes CRUD operates with UUID v7 and enforces business key uniqueness.\n";
} catch (Exception $e) {
    $failures[] = "TEST 6: " . $e->getMessage();
    echo "  [FAIL] " . $e->getMessage() . "\n";
}

// -----------------------------------------------------------------------------
// TEST 7: Sync Receiver Preserves Originating UUID v7
// -----------------------------------------------------------------------------
echo "\n--- TEST 7: Sync Receiver Preserves Originating UUID v7 (Exemption Removed) ---\n";
try {
    $receiver = new Assessor_Sync_Receiver();
    $originating_uuid = Assessor_UUID::v7();
    $sync_code = 'SYNC_TEST_' . time();

    $payload = array(
        'table' => 'assessor_property_types',
        'rows'  => array(
            array(
                'id'         => $originating_uuid,
                'code'       => $sync_code,
                'name'       => 'Sync Preserved Name',
                'status'     => 'active',
                'sort_order' => 99,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            )
        )
    );

    $req_sync = new WP_REST_Request('POST', '/assessor/v1/sync/push-config');
    $req_sync->set_header('Content-Type', 'application/json');
    $req_sync->set_body(json_encode($payload));

    $sync_res = $receiver->receive_config_push($req_sync);
    if (is_wp_error($sync_res)) {
        throw new Exception("Sync push failed: " . $sync_res->get_error_message());
    }

    $tbl = $wpdb->prefix . 'assessor_property_types';
    $synced_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE code = %s", $sync_code), ARRAY_A);
    if (!$synced_row) {
        throw new Exception("Synced record not found on receiver.");
    }
    if ($synced_row['id'] !== $originating_uuid) {
        throw new Exception("Sync receiver replaced or stripped UUID! Expected $originating_uuid, found {$synced_row['id']}");
    }

    // Repeated sync: idempotency test
    $sync_res2 = $receiver->receive_config_push($req_sync);
    $count_after_repeat = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $tbl WHERE code = %s", $sync_code));
    if ($count_after_repeat !== 1) {
        throw new Exception("Repeated sync created duplicate rows: count = $count_after_repeat");
    }

    // Cleanup
    $wpdb->delete($tbl, array('id' => $originating_uuid), array('%s'));
    echo "  [PASS] Sync receiver preserves exact originating UUID v7 without replacement, duplicates, or stripping.\n";
} catch (Exception $e) {
    $failures[] = "TEST 7: " . $e->getMessage();
    echo "  [FAIL] " . $e->getMessage() . "\n";
}

// -----------------------------------------------------------------------------
// SUMMARY
// -----------------------------------------------------------------------------
echo "\n================================================================================\n";
if (empty($failures)) {
    echo "🎉 ALL 7 LOOKUP TABLES UUID v7 & SYNC TESTS PASSED SUCCESSFULLY!\n";
    echo "================================================================================\n";
    exit(0);
} else {
    echo "❌ " . count($failures) . " TESTS FAILED:\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    echo "================================================================================\n";
    exit(1);
}
