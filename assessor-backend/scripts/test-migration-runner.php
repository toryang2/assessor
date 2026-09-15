<?php
/**
 * Test Suite: Assessor Migration Runner & Preflight Framework
 *
 * Verifies:
 * 1. Read-only preflight:
 *    - Database connectivity check
 *    - Schema inspection of assessor_revision_entries
 *    - Row count of assessor_revision_entries and assessor_properties
 *    - Status of migration registry
 *    - Verification that zero mutations or table changes occurred
 * 2. Status reporting:
 *    - Lists registered migrations with their state, timestamps, and error fields
 * 3. Execution restrictions & security:
 *    - Unauthorized request rejection (no token / viewer token)
 *    - Authorized manager / admin token accepted
 *    - Parameter validation: missing or invalid migration_name
 *    - Prevention of double-running completed migrations
 *    - Safe stub execution does not modify revision entries or properties
 */

require_once 'C:/xampp/htdocs/wp-load.php';

global $wpdb;
$wpdb->show_errors();

echo "================================================================================\n";
echo "            TEST SUITE: ASSESSOR MIGRATION RUNNER & PREFLIGHT                  \n";
echo "================================================================================\n";
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n\n";

$failures = array();

// -----------------------------------------------------------------------------
// Helper: JWT generation for tests
// -----------------------------------------------------------------------------
function test_create_jwt($role, $user_id = 'test-user-id') {
    $secret_key = defined('JWT_AUTH_SECRET_KEY') ? JWT_AUTH_SECRET_KEY : 'assessor_secret_key_2024';
    $header = rtrim(strtr(base64_encode(json_encode(array('typ' => 'JWT', 'alg' => 'HS256'))), '+/', '-_'), '=');
    $payload = rtrim(strtr(base64_encode(json_encode(array(
        'user_id'  => $user_id,
        'username' => 'test_' . $role,
        'role'     => $role,
        'iss'      => get_site_url(),
        'iat'      => time(),
        'exp'      => time() + 3600
    ))), '+/', '-_'), '=');

    $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', $header . "." . $payload, $secret_key, true)), '+/', '-_'), '=');
    return $header . "." . $payload . "." . $sig;
}

$admin_token  = test_create_jwt('administrator');
$manager_token = test_create_jwt('assessor');
$viewer_token = test_create_jwt('viewer');

$runner = new Assessor_Migration_Runner();
$api = new Assessor_API();

// Snapshot existing state of revisions and properties before test
$rev_table = $wpdb->prefix . 'assessor_revision_entries';
$prop_table = $wpdb->prefix . 'assessor_properties';
$initial_rev_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $rev_table");
$initial_prop_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $prop_table");
$initial_rev_schema = $wpdb->get_results("DESCRIBE $rev_table", ARRAY_A);

// Clean up migrations tracking table test row if any exists from prior runs
$mig_table = $wpdb->prefix . 'assessor_migrations';
if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $mig_table)) === $mig_table) {
    $wpdb->delete($mig_table, array('migration_name' => '001_revision_uuid_migration'));
    $wpdb->delete($mig_table, array('migration_name' => 'test_mock_migration'));
}

// =============================================================================
// TEST 1: READ-ONLY PREFLIGHT (Class Method Direct)
// =============================================================================
echo "--- TEST 1: Read-Only Preflight Method ---\n";
try {
    $preflight_resp = $runner->get_preflight();
    $data = $preflight_resp->get_data();

    if (empty($data['success']) || !$data['success']) {
        throw new Exception("Preflight response did not indicate success.");
    }
    if (empty($data['database']['connected']) || !$data['database']['connected']) {
        throw new Exception("Database connection not reported as connected.");
    }
    if ($data['tables']['revisions']['row_count'] !== $initial_rev_count) {
        throw new Exception("Revision row count mismatch: expected $initial_rev_count, got " . $data['tables']['revisions']['row_count']);
    }
    if ($data['tables']['properties']['row_count'] !== $initial_prop_count) {
        throw new Exception("Property row count mismatch: expected $initial_prop_count, got " . $data['tables']['properties']['row_count']);
    }
    if (empty($data['tables']['revisions']['columns'])) {
        throw new Exception("Revision table columns were not inspected.");
    }

    echo "  [PASS] Preflight returns live DB connection (v{$data['database']['version']}), schema, and row counts ($initial_rev_count revisions, $initial_prop_count properties).\n";
} catch (Exception $e) {
    $failures[] = "TEST 1: " . $e->getMessage();
    echo "  [FAIL] " . $e->getMessage() . "\n";
}

// =============================================================================
// TEST 2: VERIFY ZERO MUTATION DURING PREFLIGHT
// =============================================================================
echo "\n--- TEST 2: Verify Zero Mutations During Preflight ---\n";
try {
    $post_rev_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $rev_table");
    $post_prop_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $prop_table");
    $post_rev_schema = $wpdb->get_results("DESCRIBE $rev_table", ARRAY_A);

    if ($post_rev_count !== $initial_rev_count) {
        throw new Exception("Revision count changed after preflight!");
    }
    if ($post_prop_count !== $initial_prop_count) {
        throw new Exception("Property count changed after preflight!");
    }
    if (serialize($post_rev_schema) !== serialize($initial_rev_schema)) {
        throw new Exception("Revision schema was altered during preflight!");
    }

    echo "  [PASS] Zero mutations confirmed: row counts and schema are completely untouched.\n";
} catch (Exception $e) {
    $failures[] = "TEST 2: " . $e->getMessage();
    echo "  [FAIL] " . $e->getMessage() . "\n";
}

// =============================================================================
// TEST 3: REST ENDPOINT PERMISSIONS & AUTHORIZATION
// =============================================================================
echo "\n--- TEST 3: Authorization Checks on Migration Endpoints ---\n";
try {
    // 3.1 Unauthenticated preflight request
    $req_unauth = new WP_REST_Request('GET', '/assessor/v1/migrations/preflight');
    $res_unauth = $api->check_manager($req_unauth);
    if ($res_unauth !== false) {
        throw new Exception("Unauthenticated request was not rejected by check_manager.");
    }

    // 3.2 Viewer role request (insufficient permissions)
    $req_viewer = new WP_REST_Request('GET', '/assessor/v1/migrations/preflight');
    $req_viewer->set_headers(array('authorization' => 'Bearer ' . $viewer_token));
    $res_viewer = $api->check_manager($req_viewer);
    if ($res_viewer !== false) {
        throw new Exception("Viewer role request was not rejected by check_manager.");
    }

    // 3.3 Manager role request (authorized)
    $req_mgr = new WP_REST_Request('GET', '/assessor/v1/migrations/preflight');
    $req_mgr->set_headers(array('authorization' => 'Bearer ' . $manager_token));
    $res_mgr = $api->check_manager($req_mgr);
    if ($res_mgr !== true) {
        throw new Exception("Manager role request was rejected by check_manager.");
    }

    // 3.4 Admin role request (authorized)
    $req_admin = new WP_REST_Request('GET', '/assessor/v1/migrations/preflight');
    $req_admin->set_headers(array('authorization' => 'Bearer ' . $admin_token));
    $res_admin = $api->check_manager($req_admin);
    if ($res_admin !== true) {
        throw new Exception("Admin role request was rejected by check_manager.");
    }

    echo "  [PASS] Permission checks strictly enforce manager/admin authentication.\n";
} catch (Exception $e) {
    $failures[] = "TEST 3: " . $e->getMessage();
    echo "  [FAIL] " . $e->getMessage() . "\n";
}

// =============================================================================
// TEST 4: STATUS ENDPOINT INSPECTION
// =============================================================================
echo "\n--- TEST 4: Migration Status Method ---\n";
try {
    $status_resp = $runner->get_status();
    $status_data = $status_resp->get_data();

    if (empty($status_data['success']) || !$status_data['success']) {
        throw new Exception("Status response did not indicate success.");
    }
    if (empty($status_data['migrations'])) {
        throw new Exception("No registered migrations found in status response.");
    }

    $found_target = false;
    foreach ($status_data['migrations'] as $m) {
        if ($m['migration_name'] === '001_revision_uuid_migration') {
            $found_target = true;
            if ($m['status'] !== 'pending') {
                throw new Exception("Expected status 'pending', got '{$m['status']}'.");
            }
            if (!empty($m['error'])) {
                throw new Exception("Expected error to be empty, got '{$m['error']}'.");
            }
        }
    }

    if (!$found_target) {
        throw new Exception("001_revision_uuid_migration was not found in status list.");
    }

    echo "  [PASS] Status endpoint reports registered migrations with pending status.\n";
} catch (Exception $e) {
    $failures[] = "TEST 4: " . $e->getMessage();
    echo "  [FAIL] " . $e->getMessage() . "\n";
}

// =============================================================================
// TEST 5: EXECUTION VALIDATION & SAFETY GATES
// =============================================================================
echo "\n--- TEST 5: Execution Method Validation & Safety Gates ---\n";
try {
    // 5.1 Missing migration_name parameter
    $req_empty = new WP_REST_Request('POST', '/assessor/v1/migrations/execute');
    $req_empty->set_body_params(array());
    $res_empty = $runner->execute_migration($req_empty);
    if (!is_wp_error($res_empty) || $res_empty->get_error_code() !== 'missing_migration_name') {
        throw new Exception("Expected missing_migration_name WP_Error, got: " . print_r($res_empty, true));
    }

    // 5.2 Non-existent migration name
    $req_unknown = new WP_REST_Request('POST', '/assessor/v1/migrations/execute');
    $req_unknown->set_body_params(array('migration_name' => 'non_existent_migration_999'));
    $res_unknown = $runner->execute_migration($req_unknown);
    if (!is_wp_error($res_unknown) || $res_unknown->get_error_code() !== 'unknown_migration') {
        throw new Exception("Expected unknown_migration WP_Error, got: " . print_r($res_unknown, true));
    }

    // 5.3 Explicit execution of registered stub migration
    $req_exec = new WP_REST_Request('POST', '/assessor/v1/migrations/execute');
    $req_exec->set_body_params(array('migration_name' => '001_revision_uuid_migration'));
    $res_exec = $runner->execute_migration($req_exec);

    if (is_wp_error($res_exec)) {
        throw new Exception("Failed to execute registered migration: " . $res_exec->get_error_message());
    }

    $exec_data = $res_exec->get_data();
    if ($exec_data['status'] !== 'completed') {
        throw new Exception("Expected execution status 'completed', got: " . $exec_data['status']);
    }
    if (empty($exec_data['started_at']) || empty($exec_data['completed_at'])) {
        throw new Exception("Missing started_at or completed_at timestamps.");
    }

    // Verify row in assessor_migrations table
    $rec = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $mig_table WHERE migration_name = %s",
        '001_revision_uuid_migration'
    ), ARRAY_A);
    if (!$rec || $rec['status'] !== 'completed') {
        throw new Exception("assessor_migrations record not found or not completed.");
    }

    // 5.4 Prevent rerunning a completed migration
    $res_rerun = $runner->execute_migration($req_exec);
    if (!is_wp_error($res_rerun) || $res_rerun->get_error_code() !== 'migration_already_completed') {
        throw new Exception("Expected migration_already_completed WP_Error, got: " . print_r($res_rerun, true));
    }

    echo "  [PASS] Explicit execution succeeded, recorded timestamps, and rejected rerun of completed migration.\n";
} catch (Exception $e) {
    $failures[] = "TEST 5: " . $e->getMessage();
    echo "  [FAIL] " . $e->getMessage() . "\n";
}

// =============================================================================
// TEST 6: STATUS METHOD POST-EXECUTION
// =============================================================================
echo "\n--- TEST 6: Status Method Post-Execution ---\n";
try {
    $status_resp2 = $runner->get_status();
    $status_data2 = $status_resp2->get_data();

    $found_completed = false;
    foreach ($status_data2['migrations'] as $m) {
        if ($m['migration_name'] === '001_revision_uuid_migration') {
            if ($m['status'] === 'completed' && !empty($m['started_at']) && !empty($m['completed_at'])) {
                $found_completed = true;
            }
        }
    }

    if (!$found_completed) {
        throw new Exception("Status endpoint did not reflect completed migration with timestamps.");
    }

    echo "  [PASS] Status endpoint accurately reflects completed migration state and execution metadata.\n";
} catch (Exception $e) {
    $failures[] = "TEST 6: " . $e->getMessage();
    echo "  [FAIL] " . $e->getMessage() . "\n";
}

// =============================================================================
// TEST 7: FINAL DATA INTEGRITY SANITY CHECK
// =============================================================================
echo "\n--- TEST 7: Post-Execution Data Integrity Sanity Check ---\n";
try {
    $final_rev_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $rev_table");
    $final_prop_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $prop_table");

    if ($final_rev_count !== $initial_rev_count) {
        throw new Exception("Final revision count ($final_rev_count) differs from initial ($initial_rev_count)!");
    }
    if ($final_prop_count !== $initial_prop_count) {
        throw new Exception("Final property count ($final_prop_count) differs from initial ($initial_prop_count)!");
    }

    echo "  [PASS] All data rows intact ($final_rev_count revisions, $final_prop_count properties). Zero data alteration.\n";
} catch (Exception $e) {
    $failures[] = "TEST 7: " . $e->getMessage();
    echo "  [FAIL] " . $e->getMessage() . "\n";
}

// Clean up test migration record
$wpdb->delete($mig_table, array('migration_name' => '001_revision_uuid_migration'));

// =============================================================================
// SUMMARY
// =============================================================================
echo "\n================================================================================\n";
if (empty($failures)) {
    echo "🎉 ALL 7 MIGRATION RUNNER & PREFLIGHT TESTS PASSED!\n";
    echo "================================================================================\n";
    exit(0);
} else {
    echo "❌ " . count($failures) . " FAILURES DETECTED:\n";
    foreach ($failures as $f) {
        echo " - " . $f . "\n";
    }
    echo "================================================================================\n";
    exit(1);
}
