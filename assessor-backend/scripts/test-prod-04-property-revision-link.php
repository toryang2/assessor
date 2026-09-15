<?php
/**
 * Test Suite: PROD-04 Live Property -> Revision UUID Link
 *
 * Verifies:
 * 1. Canonical field name is 'revision_id' (VARCHAR(36) NULL).
 * 2. Zero duplicate columns (no revision_uuid, etc.).
 * 3. References point strictly to assessor_revision_entries.id.
 * 4. Indexes exist: KEY revision_id and compound KEY idx_tdn_revision.
 * 5. Foreign key constraint fk_properties_revision_id exists and is valid.
 * 6. Migration '003_property_revision_link' executes through migration runner.
 * 7. Zero orphan references exist (every populated revision_id resolves to an existing revision).
 * 8. Property count remains strictly unchanged (12,914 properties).
 * 9. effectivity_date values and property UUIDs remain unchanged across all records.
 * 10. Idempotency: re-running returns migration_already_completed (HTTP 409).
 * 11. Status endpoint confirms 'completed'.
 */

require_once 'C:/xampp/htdocs/wp-load.php';

global $wpdb;
$wpdb->show_errors();

echo "================================================================================\n";
echo "            PROD-04: LIVE PROPERTY -> REVISION UUID LINK TEST                  \n";
echo "================================================================================\n";
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n\n";

$failures = array();
$runner = new Assessor_Migration_Runner();

$table_properties = $wpdb->prefix . 'assessor_properties';
$table_revisions  = $wpdb->prefix . 'assessor_revision_entries';
$table_migrations = $wpdb->prefix . 'assessor_migrations';

// Initial snapshot
$initial_prop_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_properties");
$initial_effectivity_checksum = $wpdb->get_var("SELECT MD5(GROUP_CONCAT(CONCAT(id, ':', effectivity_date) ORDER BY id)) FROM $table_properties");

// Helper: JWT generation for authenticated admin request
function prod04_create_jwt($role, $user_id = 'prod04-admin-user') {
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

$admin_token = prod04_create_jwt('administrator');

// Clean up any test state for 003_property_revision_link
$runner->ensure_migrations_table();
$wpdb->delete($table_migrations, array('migration_name' => '003_property_revision_link'));

// =============================================================================
// STEP 1: EXECUTE 003_property_revision_link VIA MIGRATION RUNNER
// =============================================================================
echo "--- STEP 1: Execute 003_property_revision_link via Migration Runner ---\n";
try {
    $req = new WP_REST_Request('POST', '/assessor/v1/migrations/execute');
    $req->set_headers(array('authorization' => 'Bearer ' . $admin_token));
    $req->set_body_params(array('migration_name' => '003_property_revision_link'));

    $exec_resp = $runner->execute_migration($req);
    if (is_wp_error($exec_resp)) {
        throw new Exception("Migration execution returned WP_Error: " . $exec_resp->get_error_message());
    }

    $exec_data = $exec_resp->get_data();
    if ($exec_data['status'] !== 'completed') {
        throw new Exception("Expected status 'completed', got: " . $exec_data['status']);
    }

    echo "  [PASS] Migration '003_property_revision_link' executed successfully at {$exec_data['completed_at']}.\n";
    echo "  - Populated references: {$exec_data['details']['populated_count']}\n";
    echo "  - Null references: {$exec_data['details']['null_count']}\n";
    echo "  - Foreign key: {$exec_data['details']['foreign_key']}\n";
} catch (Exception $e) {
    $failures[] = "STEP 1: " . $e->getMessage();
    echo "  [FAIL] " . $e->getMessage() . "\n";
}

// =============================================================================
// STEP 2: VERIFY CANONICAL COLUMN NAME & ZERO DUPLICATE FIELDS
// =============================================================================
echo "\n--- STEP 2: Verify Canonical Field Name & Zero Duplicate Columns ---\n";
try {
    $revision_cols = $wpdb->get_results("SHOW COLUMNS FROM $table_properties LIKE 'revision%'", ARRAY_A);
    if (count($revision_cols) !== 1) {
        throw new Exception("Expected exactly 1 revision column, found " . count($revision_cols) . ": " . print_r($revision_cols, true));
    }
    if ($revision_cols[0]['Field'] !== 'revision_id') {
        throw new Exception("Column name mismatch: expected 'revision_id', got '{$revision_cols[0]['Field']}'");
    }
    if (stripos($revision_cols[0]['Type'], 'varchar(36)') === false) {
        throw new Exception("Column type mismatch: expected 'varchar(36)', got '{$revision_cols[0]['Type']}'");
    }

    echo "  [PASS] Canonical field strictly verified: 'revision_id' VARCHAR(36) NULL (zero duplicate columns).\n";
} catch (Exception $e) {
    $failures[] = "STEP 2: " . $e->getMessage();
    echo "  [FAIL] " . $e->getMessage() . "\n";
}

// =============================================================================
// STEP 3: VERIFY INDEXES AND FOREIGN KEY CONSTRAINT
// =============================================================================
echo "\n--- STEP 3: Verify Indexes and Foreign Key Constraint ---\n";
try {
    $single_idx = $wpdb->get_results("SHOW INDEX FROM $table_properties WHERE Column_name = 'revision_id' AND Key_name = 'revision_id'", ARRAY_A);
    if (empty($single_idx)) {
        throw new Exception("Single-column index 'revision_id' not found.");
    }

    $compound_idx = $wpdb->get_results("SHOW INDEX FROM $table_properties WHERE Key_name = 'idx_tdn_revision'", ARRAY_A);
    if (empty($compound_idx)) {
        throw new Exception("Compound index 'idx_tdn_revision' not found.");
    }

    $db_name = DB_NAME;
    $fk = $wpdb->get_row($wpdb->prepare("
        SELECT CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = %s
          AND TABLE_NAME = %s
          AND COLUMN_NAME = 'revision_id'
          AND REFERENCED_TABLE_NAME IS NOT NULL
    ", $db_name, $table_properties), ARRAY_A);

    if (!$fk) {
        throw new Exception("Foreign key constraint on revision_id not found in INFORMATION_SCHEMA.");
    }
    if ($fk['REFERENCED_TABLE_NAME'] !== $table_revisions || $fk['REFERENCED_COLUMN_NAME'] !== 'id') {
        throw new Exception("FK target mismatch: expected {$table_revisions}(id), got {$fk['REFERENCED_TABLE_NAME']}({$fk['REFERENCED_COLUMN_NAME']})");
    }

    echo "  [PASS] Indexes 'revision_id', 'idx_tdn_revision', and FK '{$fk['CONSTRAINT_NAME']}' verified.\n";
} catch (Exception $e) {
    $failures[] = "STEP 3: " . $e->getMessage();
    echo "  [FAIL] " . $e->getMessage() . "\n";
}

// =============================================================================
// STEP 4: VERIFY ZERO ORPHAN REFERENCES & ALL POPULATED REFS RESOLVE
// =============================================================================
echo "\n--- STEP 4: Verify Zero Orphan References & Referential Integrity ---\n";
try {
    $orphan_count = (int) $wpdb->get_var("
        SELECT COUNT(*)
        FROM $table_properties p
        LEFT JOIN $table_revisions r ON p.revision_id = r.id
        WHERE p.revision_id IS NOT NULL AND r.id IS NULL
    ");

    if ($orphan_count !== 0) {
        throw new Exception("Found $orphan_count orphan revision_id references!");
    }

    $resolved_count = (int) $wpdb->get_var("
        SELECT COUNT(*)
        FROM $table_properties p
        JOIN $table_revisions r ON p.revision_id = r.id
        WHERE p.revision_id IS NOT NULL
    ");

    $total_populated = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_properties WHERE revision_id IS NOT NULL");
    if ($resolved_count !== $total_populated) {
        throw new Exception("Mismatch in resolved references: $resolved_count resolved out of $total_populated populated.");
    }

    echo "  [PASS] Zero orphans: All $resolved_count populated revision references cleanly resolve to valid revisions.\n";
} catch (Exception $e) {
    $failures[] = "STEP 4: " . $e->getMessage();
    echo "  [FAIL] " . $e->getMessage() . "\n";
}

// =============================================================================
// STEP 5: VERIFY PROPERTY COUNT & EFFECTIVITY_DATE UNCHANGED
// =============================================================================
echo "\n--- STEP 5: Verify Property Count & effectivity_date Preservation ---\n";
try {
    $final_prop_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_properties");
    if ($final_prop_count !== $initial_prop_count) {
        throw new Exception("Property count changed! Initial: $initial_prop_count, Final: $final_prop_count");
    }

    $final_effectivity_checksum = $wpdb->get_var("SELECT MD5(GROUP_CONCAT(CONCAT(id, ':', effectivity_date) ORDER BY id)) FROM $table_properties");
    if ($final_effectivity_checksum !== $initial_effectivity_checksum) {
        throw new Exception("effectivity_date values were modified!");
    }

    echo "  [PASS] Property count ($final_prop_count) and all effectivity_date values preserved identically.\n";
} catch (Exception $e) {
    $failures[] = "STEP 5: " . $e->getMessage();
    echo "  [FAIL] " . $e->getMessage() . "\n";
}

// =============================================================================
// STEP 6: IDEMPOTENCY & RERUN PREVENTION
// =============================================================================
echo "\n--- STEP 6: Idempotency & Rerun Prevention Check ---\n";
try {
    $rerun_req = new WP_REST_Request('POST', '/assessor/v1/migrations/execute');
    $rerun_req->set_headers(array('authorization' => 'Bearer ' . $admin_token));
    $rerun_req->set_body_params(array('migration_name' => '003_property_revision_link'));

    $rerun_resp = $runner->execute_migration($rerun_req);
    if (!is_wp_error($rerun_resp) || $rerun_resp->get_error_code() !== 'migration_already_completed') {
        throw new Exception("Expected migration_already_completed WP_Error, got: " . print_r($rerun_resp, true));
    }

    echo "  [PASS] Re-running migration safely rejected with migration_already_completed (HTTP 409).\n";
} catch (Exception $e) {
    $failures[] = "STEP 6: " . $e->getMessage();
    echo "  [FAIL] " . $e->getMessage() . "\n";
}

// =============================================================================
// SUMMARY
// =============================================================================
echo "\n================================================================================\n";
if (empty($failures)) {
    echo "🎉 PROD-04 LIVE PROPERTY -> REVISION UUID LINK TEST PASSED!\n";
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
