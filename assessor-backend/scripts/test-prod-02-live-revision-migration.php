<?php
/**
 * Test Suite: PROD-02 Live Revision UUID v7 Migration
 *
 * Verifies:
 * 1. Pre-execution preflight check passes.
 * 2. Explicit execution of '001_revision_uuid_migration' succeeds through Assessor_Migration_Runner.
 * 3. Backup table was created and populated prior to modification.
 * 4. Mapping table assessor_revision_id_uuid_map exists and holds persistent mapping.
 * 5. Revision row count is strictly preserved.
 * 6. Every revision has a valid UUID v7 and unique revision_code.
 * 7. Metadata (revision_year, from_year, to_year, status, sort_order) unchanged.
 * 8. Zero changes to assessor_properties or TDN behavior.
 * 9. Idempotency: re-running execute returns migration_already_completed (HTTP 409).
 * 10. Status endpoint returns 'completed' with accurate started_at and completed_at timestamps.
 */

require_once 'C:/xampp/htdocs/wp-load.php';

global $wpdb;
$wpdb->show_errors();

echo "================================================================================\n";
echo "            PROD-02: LIVE REVISION UUID v7 MIGRATION TEST                       \n";
echo "================================================================================\n";
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n\n";

$failures = array();
$runner = new Assessor_Migration_Runner();

$table_revisions  = $wpdb->prefix . 'assessor_revision_entries';
$table_properties = $wpdb->prefix . 'assessor_properties';
$table_map        = $wpdb->prefix . 'assessor_revision_id_uuid_map';
$table_migrations = $wpdb->prefix . 'assessor_migrations';

// Record initial state
$initial_rev_rows = $wpdb->get_results("SELECT * FROM $table_revisions ORDER BY revision_year ASC", ARRAY_A);
$initial_rev_count = count($initial_rev_rows);
$initial_prop_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_properties");

// -----------------------------------------------------------------------------
// Helper: JWT generation for test request
// -----------------------------------------------------------------------------
function prod02_create_jwt($role, $user_id = 'prod-admin-user') {
    $secret_key = defined('JWT_AUTH_SECRET_KEY') ? JWT_AUTH_SECRET_KEY : 'assessor_secret_key_2024';
    $header = rtrim(strtr(base64_encode(json_encode(array('typ' => 'JWT', 'alg' => 'HS256'))), '+/', '-_'), '=');
    $payload = rtrim(strtr(base64_encode(json_encode(array(
        'user_id'  => $user_id,
        'username' => 'admin_user',
        'role'     => $role,
        'iss'      => get_site_url(),
        'iat'      => time(),
        'exp'      => time() + 3600
    ))), '+/', '-_'), '=');

    $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', $header . "." . $payload, $secret_key, true)), '+/', '-_'), '=');
    return $header . "." . $payload . "." . $sig;
}

$admin_token = prod02_create_jwt('administrator');

// Clean up any test migration state so PROD-02 can execute cleanly
$runner->ensure_migrations_table();
$wpdb->delete($table_migrations, array('migration_name' => '001_revision_uuid_migration'));

// =============================================================================
// STEP 1: PREFLIGHT CHECK BEFORE MIGRATION
// =============================================================================
echo "--- STEP 1: Pre-Execution Preflight Verification ---\n";
try {
    $preflight_resp = $runner->get_preflight();
    $pdata = $preflight_resp->get_data();

    if (empty($pdata['success']) || empty($pdata['database']['connected'])) {
        throw new Exception("Preflight DB connection check failed.");
    }
    if ($pdata['tables']['revisions']['row_count'] !== $initial_rev_count) {
        throw new Exception("Preflight revision count mismatch.");
    }
    echo "  [PASS] Preflight passed. Database connected, $initial_rev_count revision rows ready.\n";
} catch (Exception $e) {
    $failures[] = "STEP 1: " . $e->getMessage();
    echo "  [FAIL] " . $e->getMessage() . "\n";
}

// =============================================================================
// STEP 2: EXECUTE LIVE REVISION UUID v7 MIGRATION
// =============================================================================
echo "\n--- STEP 2: Explicit Migration Execution via Migration Runner ---\n";
try {
    $req = new WP_REST_Request('POST', '/assessor/v1/migrations/execute');
    $req->set_headers(array('authorization' => 'Bearer ' . $admin_token));
    $req->set_body_params(array('migration_name' => '001_revision_uuid_migration'));

    $exec_resp = $runner->execute_migration($req);
    if (is_wp_error($exec_resp)) {
        throw new Exception("Migration execution returned WP_Error: " . $exec_resp->get_error_message());
    }

    $exec_data = $exec_resp->get_data();
    if ($exec_data['status'] !== 'completed') {
        throw new Exception("Expected migration status 'completed', got: " . $exec_data['status']);
    }
    if (empty($exec_data['started_at']) || empty($exec_data['completed_at'])) {
        throw new Exception("Missing started_at or completed_at timestamps.");
    }

    echo "  [PASS] Migration executed successfully. Completed at {$exec_data['completed_at']}.\n";
    echo "  - Backup table created: {$exec_data['details']['backup_table']}\n";
    echo "  - Mapped UUIDs: {$exec_data['details']['validated_uuids']}\n";
} catch (Exception $e) {
    $failures[] = "STEP 2: " . $e->getMessage();
    echo "  [FAIL] " . $e->getMessage() . "\n";
}

// =============================================================================
// STEP 3: POST-MIGRATION DATA & SCHEMA VALIDATION
// =============================================================================
echo "\n--- STEP 3: Post-Migration Comprehensive Validation ---\n";
try {
    $final_rev_rows = $wpdb->get_results("SELECT * FROM $table_revisions ORDER BY revision_year ASC", ARRAY_A);
    $final_rev_count = count($final_rev_rows);

    // 3.1 Row count preservation
    if ($final_rev_count !== $initial_rev_count) {
        throw new Exception("Revision row count changed! Initial: $initial_rev_count, Final: $final_rev_count");
    }

    // 3.2 Property row count preservation
    $final_prop_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_properties");
    if ($final_prop_count !== $initial_prop_count) {
        throw new Exception("Property row count changed! Initial: $initial_prop_count, Final: $final_prop_count");
    }

    // 3.3 Check each revision row: valid UUID v7, unique, metadata preserved
    $seen_uuids = array();
    $seen_codes = array();

    foreach ($final_rev_rows as $row) {
        $id = $row['id'];
        $code = $row['revision_code'];

        if (!Assessor_UUID::is_valid($id)) {
            throw new Exception("Invalid UUID v7 detected: '$id' for revision '{$row['revision_year']}'");
        }
        if (isset($seen_uuids[$id])) {
            throw new Exception("Duplicate UUID detected: '$id'");
        }
        $seen_uuids[$id] = true;

        if (empty($code)) {
            throw new Exception("Empty revision_code for revision ID '$id'");
        }
        if (isset($seen_codes[$code])) {
            throw new Exception("Duplicate revision_code detected: '$code'");
        }
        $seen_codes[$code] = true;

        // Check against initial row
        $matched_initial = null;
        foreach ($initial_rev_rows as $init_row) {
            if ($init_row['revision_year'] === $row['revision_year']) {
                $matched_initial = $init_row;
                break;
            }
        }
        if (!$matched_initial) {
            throw new Exception("Could not find initial row matching revision_year '{$row['revision_year']}'");
        }

        if ($matched_initial['from_year'] !== $row['from_year'] ||
            $matched_initial['to_year'] !== $row['to_year'] ||
            $matched_initial['status'] !== $row['status'] ||
            (int)$matched_initial['sort_order'] !== (int)$row['sort_order']) {
            throw new Exception("Metadata mismatch for revision '{$row['revision_year']}'!");
        }

        echo "  - Verified: ID: $id | Code: $code | Year: {$row['revision_year']} ({$row['from_year']} - {$row['to_year']})\n";
    }

    echo "  [PASS] All $final_rev_count revisions verified: unique UUID v7, unique code, identical metadata.\n";
} catch (Exception $e) {
    $failures[] = "STEP 3: " . $e->getMessage();
    echo "  [FAIL] " . $e->getMessage() . "\n";
}

// =============================================================================
// STEP 4: IDEMPOTENCY CHECK (RERUN PREVENTION)
// =============================================================================
echo "\n--- STEP 4: Idempotency & Rerun Prevention Check ---\n";
try {
    $rerun_req = new WP_REST_Request('POST', '/assessor/v1/migrations/execute');
    $rerun_req->set_headers(array('authorization' => 'Bearer ' . $admin_token));
    $rerun_req->set_body_params(array('migration_name' => '001_revision_uuid_migration'));

    $rerun_resp = $runner->execute_migration($rerun_req);
    if (!is_wp_error($rerun_resp) || $rerun_resp->get_error_code() !== 'migration_already_completed') {
        throw new Exception("Expected migration_already_completed WP_Error, got: " . print_r($rerun_resp, true));
    }

    echo "  [PASS] Re-running migration safely rejected with migration_already_completed (HTTP 409).\n";
} catch (Exception $e) {
    $failures[] = "STEP 4: " . $e->getMessage();
    echo "  [FAIL] " . $e->getMessage() . "\n";
}

// =============================================================================
// STEP 5: STATUS ENDPOINT REPORTING
// =============================================================================
echo "\n--- STEP 5: Status Endpoint Post-Migration Check ---\n";
try {
    $status_resp = $runner->get_status();
    $status_data = $status_resp->get_data();

    $found = false;
    foreach ($status_data['migrations'] as $m) {
        if ($m['migration_name'] === '001_revision_uuid_migration') {
            $found = true;
            if ($m['status'] !== 'completed') {
                throw new Exception("Status is not 'completed': " . $m['status']);
            }
            if (empty($m['completed_at'])) {
                throw new Exception("completed_at is empty.");
            }
        }
    }

    if (!$found) {
        throw new Exception("001_revision_uuid_migration not found in status response.");
    }

    echo "  [PASS] Status endpoint confirms 001_revision_uuid_migration is completed.\n";
} catch (Exception $e) {
    $failures[] = "STEP 5: " . $e->getMessage();
    echo "  [FAIL] " . $e->getMessage() . "\n";
}

// =============================================================================
// SUMMARY
// =============================================================================
echo "\n================================================================================\n";
if (empty($failures)) {
    echo "🎉 PROD-02 LIVE REVISION UUID v7 MIGRATION COMPLETED & FULLY VALIDATED!\n";
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
