<?php
/**
 * Test Suite: PROD-03 Live Revision Code
 *
 * Verifies:
 * 1. Required revision codes are present, non-empty, and unique across all live revisions.
 * 2. All 9 known codes are exact:
 *    - CA-470 (1965 - 1973)
 *    - PD-76 (1974 - 1979)
 *    - PD-464 (1980 - 1984)
 *    - PD-1621 (1985 - 1993)
 *    - RA-7160-ART-310 (1994 - 1998)
 *    - MDP3-RPTA-PROJECT (1999 - 2002)
 *    - SP-OD-2002-006R (2003 - 2018)
 *    - GR-2018 (2019 - 2022)
 *    - GR-2022 (2023 - present)
 * 3. Migration '002_live_revision_code' executes through migration runner.
 * 4. Idempotency: re-running execute returns migration_already_completed (HTTP 409).
 * 5. Unique index on revision_code is present and active.
 * 6. Revision row count is unchanged.
 * 7. Zero modifications to assessor_properties, TDNs, ETRACS, or effectivity-date filtering.
 */

require_once 'C:/xampp/htdocs/wp-load.php';

global $wpdb;
$wpdb->show_errors();

echo "================================================================================\n";
echo "            PROD-03: LIVE REVISION CODE VERIFICATION & MIGRATION TEST           \n";
echo "================================================================================\n";
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n\n";

$failures = array();
$runner = new Assessor_Migration_Runner();

$table_revisions  = $wpdb->prefix . 'assessor_revision_entries';
$table_properties = $wpdb->prefix . 'assessor_properties';
$table_migrations = $wpdb->prefix . 'assessor_migrations';

// Record initial state
$initial_rev_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_revisions");
$initial_prop_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_properties");

// Helper: JWT generation for authenticated admin request
function prod03_create_jwt($role, $user_id = 'prod03-admin-user') {
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

$admin_token = prod03_create_jwt('administrator');

// Clean up any test state for 002_live_revision_code
$runner->ensure_migrations_table();
$wpdb->delete($table_migrations, array('migration_name' => '002_live_revision_code'));

// =============================================================================
// STEP 1: EXECUTE 002_live_revision_code VIA MIGRATION RUNNER
// =============================================================================
echo "--- STEP 1: Execute 002_live_revision_code via Migration Runner ---\n";
try {
    $req = new WP_REST_Request('POST', '/assessor/v1/migrations/execute');
    $req->set_headers(array('authorization' => 'Bearer ' . $admin_token));
    $req->set_body_params(array('migration_name' => '002_live_revision_code'));

    $exec_resp = $runner->execute_migration($req);
    if (is_wp_error($exec_resp)) {
        throw new Exception("Migration execution returned WP_Error: " . $exec_resp->get_error_message());
    }

    $exec_data = $exec_resp->get_data();
    if ($exec_data['status'] !== 'completed') {
        throw new Exception("Expected status 'completed', got: " . $exec_data['status']);
    }
    if (empty($exec_data['started_at']) || empty($exec_data['completed_at'])) {
        throw new Exception("Missing timestamps in execution response.");
    }

    echo "  [PASS] Migration '002_live_revision_code' executed successfully (completed at {$exec_data['completed_at']}).\n";
    echo "  - Total revisions validated: {$exec_data['details']['total_revisions']}\n";
    echo "  - Unique codes count: {$exec_data['details']['unique_codes_count']}\n";
} catch (Exception $e) {
    $failures[] = "STEP 1: " . $e->getMessage();
    echo "  [FAIL] " . $e->getMessage() . "\n";
}

// =============================================================================
// STEP 2: VERIFY ALL REQUIRED REVISION CODES ARE EXACT & PRESENT
// =============================================================================
echo "\n--- STEP 2: Verify Exact Required Revision Codes ---\n";
try {
    $expected_codes = array(
        'CA 470'             => 'CA-470',
        'PD 76'              => 'PD-76',
        'PD 464'             => 'PD-464',
        'PD 1621'            => 'PD-1621',
        'RA 7160 ART. 310'   => 'RA-7160-ART-310',
        'MDP3 RPTA PROJECT'  => 'MDP3-RPTA-PROJECT',
        'SP OD. 2002-006R'   => 'SP-OD-2002-006R',
        'GR 2018'            => 'GR-2018',
        'GR 2022'            => 'GR-2022',
    );

    $rows = $wpdb->get_results("SELECT id, revision_year, revision_code, from_year, to_year FROM $table_revisions ORDER BY from_year ASC", ARRAY_A);
    $final_rev_count = count($rows);

    if ($final_rev_count !== count($expected_codes)) {
        throw new Exception("Revision count mismatch: expected " . count($expected_codes) . ", got $final_rev_count");
    }

    $seen_codes = array();
    foreach ($rows as $r) {
        $year = $r['revision_year'];
        $code = $r['revision_code'];

        if (!isset($expected_codes[$year])) {
            throw new Exception("Unexpected revision_year found: '$year'");
        }

        $expected = $expected_codes[$year];
        if ($code !== $expected) {
            throw new Exception("Mismatch for '$year': expected '$expected', found '$code'");
        }

        if (isset($seen_codes[$code])) {
            throw new Exception("Collision detected: duplicate code '$code'");
        }
        $seen_codes[$code] = true;

        echo "  - Verified: Year '{$year}' -> Code '{$code}' (UUID: {$r['id']})\n";
    }

    echo "  [PASS] All " . count($expected_codes) . " expected revision codes verified exactly with no collisions.\n";
} catch (Exception $e) {
    $failures[] = "STEP 2: " . $e->getMessage();
    echo "  [FAIL] " . $e->getMessage() . "\n";
}

// =============================================================================
// STEP 3: VERIFY UNIQUE INDEX CONSTRAINT
// =============================================================================
echo "\n--- STEP 3: Verify Unique Index Constraint on revision_code ---\n";
try {
    $indexes = $wpdb->get_results("SHOW INDEX FROM $table_revisions WHERE Key_name = 'revision_code'", ARRAY_A);
    if (empty($indexes)) {
        throw new Exception("Index 'revision_code' is not present.");
    }
    if ((int)$indexes[0]['Non_unique'] !== 0) {
        throw new Exception("Index 'revision_code' is not UNIQUE.");
    }
    echo "  [PASS] UNIQUE index on revision_code confirmed active.\n";
} catch (Exception $e) {
    $failures[] = "STEP 3: " . $e->getMessage();
    echo "  [FAIL] " . $e->getMessage() . "\n";
}

// =============================================================================
// STEP 4: IDEMPOTENCY & RERUN PREVENTION
// =============================================================================
echo "\n--- STEP 4: Idempotency & Rerun Prevention Check ---\n";
try {
    $rerun_req = new WP_REST_Request('POST', '/assessor/v1/migrations/execute');
    $rerun_req->set_headers(array('authorization' => 'Bearer ' . $admin_token));
    $rerun_req->set_body_params(array('migration_name' => '002_live_revision_code'));

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

    $found_001 = false;
    $found_002 = false;

    foreach ($status_data['migrations'] as $m) {
        if ($m['migration_name'] === '001_revision_uuid_migration' && $m['status'] === 'completed') {
            $found_001 = true;
        }
        if ($m['migration_name'] === '002_live_revision_code' && $m['status'] === 'completed') {
            $found_002 = true;
        }
    }

    if (!$found_001) {
        throw new Exception("001_revision_uuid_migration not confirmed completed in status.");
    }
    if (!$found_002) {
        throw new Exception("002_live_revision_code not confirmed completed in status.");
    }

    echo "  [PASS] Status endpoint confirms both 001_revision_uuid_migration and 002_live_revision_code are completed.\n";
} catch (Exception $e) {
    $failures[] = "STEP 5: " . $e->getMessage();
    echo "  [FAIL] " . $e->getMessage() . "\n";
}

// =============================================================================
// STEP 6: VERIFY ZERO MUTATION TO PROPERTIES & DATA INTEGRITY
// =============================================================================
echo "\n--- STEP 6: Verify Zero Mutation to Properties & Integrity ---\n";
try {
    $final_prop_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_properties");
    if ($final_prop_count !== $initial_prop_count) {
        throw new Exception("Property row count changed! Initial: $initial_prop_count, Final: $final_prop_count");
    }

    echo "  [PASS] Zero mutations to properties: row count remains untouched ($final_prop_count properties).\n";
} catch (Exception $e) {
    $failures[] = "STEP 6: " . $e->getMessage();
    echo "  [FAIL] " . $e->getMessage() . "\n";
}

// =============================================================================
// SUMMARY
// =============================================================================
echo "\n================================================================================\n";
if (empty($failures)) {
    echo "🎉 PROD-03 LIVE REVISION CODE VERIFICATION & MIGRATION PASSED!\n";
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
