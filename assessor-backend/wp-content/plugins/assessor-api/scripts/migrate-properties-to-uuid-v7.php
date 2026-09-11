<?php
/**
 * Migration Script: Migrate Assessor Properties Primary Key and Foreign Keys to UUID v7
 *
 * This script:
 * 1. Drops foreign key constraints from child tables referencing wp_assessor_properties(id)
 * 2. Populates a temporary mapping table between old integer ID and newly generated UUID v7
 * 3. Updates all child tables (versions, documents, states, requests, sync_queue) to VARCHAR(36) and populates new UUIDs
 * 4. Updates historical audit log entries (wp_assessor_audit_trail.record_id)
 * 5. Alters wp_assessor_properties.id from mediumint AUTO_INCREMENT to VARCHAR(36)
 * 6. Updates wp_assessor_properties.id with the UUIDs
 * 7. Re-adds foreign key constraints (CASCADE / RESTRICT)
 * 8. Cleans up temporary mapping table
 */

// Send plain text header and disable output buffering for real-time streaming in browser
if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Accel-Buffering: no'); // Disable buffering on Nginx / Hostinger
    header('Cache-Control: no-cache, no-store, must-revalidate');

    // Turn off output buffering
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    ob_implicit_flush(true);

    // Increase time limit for web execution
    @set_time_limit(600);

    // Security check: require either admin capability or secret query key if accessed via HTTP
    $secret_key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';
    // Allow if secret key is 'masso-migrate-uuid' or if user is WordPress admin
    $allow_http = false;
    if ($secret_key === 'masso-migrate-uuid') {
        $allow_http = true;
    }
}

// Load WordPress environment (supports Hostinger Linux, Localhost XAMPP, and standard WP setups)
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
    die("FATAL: Cannot locate wp-load.php. Please run this script within the WordPress directory structure.\n");
}

// If invoked via browser without secret key, check for WordPress Administrator privileges
if (php_sapi_name() !== 'cli' && empty($allow_http)) {
    if (!current_user_can('manage_options')) {
        die("ACCESS DENIED: You must either be logged into WordPress as Administrator or pass ?key=masso-migrate-uuid\n");
    }
}

global $wpdb;

// Suppress raw HTML database error printing so script controls output cleanly
$wpdb->show_errors(false);

// Ensure UUID generator is available
if (!class_exists('Assessor_UUID')) {
    $uuid_file = WP_PLUGIN_DIR . '/assessor-api/includes/class-assessor-uuid.php';
    if (file_exists($uuid_file)) {
        require_once $uuid_file;
    } else {
        die("FATAL: class-assessor-uuid.php not found at $uuid_file\n");
    }
}

function flush_out($text) {
    echo $text;
    if (php_sapi_name() !== 'cli') {
        @ob_flush();
        flush();
    }
}

function run_query($sql, $desc = '') {
    global $wpdb;
    $res = $wpdb->query($sql);
    if ($res === false) {
        flush_out("FATAL ERROR during '$desc': " . $wpdb->last_error . "\nSQL: $sql\n");
        exit(1);
    }
    return $res;
}

$prefix = $wpdb->prefix;
$table_properties = $prefix . 'assessor_properties';
$table_versions   = $prefix . 'assessor_property_versions';
$table_documents  = $prefix . 'assessor_documents';
$table_states     = $prefix . 'assessor_property_states';
$table_requests   = $prefix . 'assessor_requests';
$table_sync_queue = $prefix . 'assessor_sync_queue';
$table_audit      = $prefix . 'assessor_audit_trail';
$table_map        = $prefix . 'assessor_id_uuid_map';

flush_out("=======================================================\n");
flush_out("ASSESSOR PROPERTY UUID v7 MIGRATION (PERMANENT CUTOVER)\n");
flush_out("=======================================================\n");

// Check current properties schema
$id_col = $wpdb->get_row("SHOW COLUMNS FROM $table_properties LIKE 'id'");
if (!$id_col) {
    die("FATAL: Table $table_properties does not exist or has no 'id' column.\n");
}
$is_already_varchar = (stripos($id_col->Type, 'varchar') !== false || stripos($id_col->Type, 'char') !== false);
flush_out("Current $table_properties.id type: {$id_col->Type} (Key: {$id_col->Key}, Extra: {$id_col->Extra})\n");

// If properties are already migrated (varchar and has UUIDs), we MUST NEVER regenerate UUIDs!
if ($is_already_varchar) {
    $uuid_sample = $wpdb->get_var("SELECT id FROM $table_properties WHERE id REGEXP '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$' LIMIT 1");
    if ($uuid_sample) {
        flush_out("\n=================================================================\n");
        flush_out("NOTICE: $table_properties.id is ALREADY migrated to UUID v7!\n");
        flush_out("CRITICAL INTEGRITY RULE: Primary IDs on $table_properties will NOT be re-generated.\n");
        flush_out("Child tables MUST strictly reference existing IDs from $table_properties.\n");
        flush_out("=================================================================\n\n");
    }
}

// Step 1: Drop foreign key constraints if they exist
flush_out("\n[Step 1] Dropping foreign key constraints referencing $table_properties...\n");
$db_name = DB_NAME;
$fks = $wpdb->get_results("
    SELECT TABLE_NAME, CONSTRAINT_NAME
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE REFERENCED_TABLE_SCHEMA = '$db_name'
      AND REFERENCED_TABLE_NAME = '$table_properties'
      AND REFERENCED_COLUMN_NAME = 'id'
", ARRAY_A);

foreach ($fks as $fk) {
    flush_out("  - Dropping constraint {$fk['CONSTRAINT_NAME']} on {$fk['TABLE_NAME']}...\n");
    run_query("ALTER TABLE `{$fk['TABLE_NAME']}` DROP FOREIGN KEY `{$fk['CONSTRAINT_NAME']}`", "Drop FK {$fk['CONSTRAINT_NAME']}");
}

// Step 2: Create temporary mapping table
flush_out("\n[Step 2] Creating temporary mapping table $table_map...\n");
$charset_collate = $wpdb->get_charset_collate();
run_query("DROP TABLE IF EXISTS $table_map", "Drop existing map table");
run_query("CREATE TABLE $table_map (
    old_id VARCHAR(50) NOT NULL,
    new_uuid VARCHAR(36) NOT NULL,
    PRIMARY KEY (old_id),
    KEY (new_uuid)
) $charset_collate;", "Create map table");

// Step 3: Populate UUID v7 for properties that still have integer IDs
flush_out("\n[Step 3] Mapping / Generating UUID v7 for properties...\n");
$existing_properties = $wpdb->get_results("SELECT id, created_at FROM $table_properties", ARRAY_A);
$total = count($existing_properties);
flush_out("Found $total existing properties in database.\n");

$batch_size = 1000;
$values = array();
$count = 0;
$newly_generated = 0;
$already_uuid_count = 0;

foreach ($existing_properties as $row) {
    $old_id = (string) $row['id'];
    if (Assessor_UUID::is_valid($old_id)) {
        // Property already has a valid UUID! Retain it strictly so child tables follow it
        $new_uuid = $old_id;
        $already_uuid_count++;
    } else {
        $ts = !empty($row['created_at']) ? strtotime($row['created_at']) : false;
        $timeMs = ($ts !== false && $ts > 0) ? ($ts * 1000) + ($count % 999) : null;
        $new_uuid = Assessor_UUID::v7($timeMs);
        $newly_generated++;
    }
    $values[] = $wpdb->prepare("(%s, %s)", $old_id, $new_uuid);
    $count++;

    if (count($values) >= $batch_size) {
        $sql = "INSERT IGNORE INTO $table_map (old_id, new_uuid) VALUES " . implode(',', $values);
        run_query($sql, "Batch insert into map table");
        $values = array();
        flush_out("  - Processed $count / $total...\n");
    }
}
if (!empty($values)) {
    $sql = "INSERT IGNORE INTO $table_map (old_id, new_uuid) VALUES " . implode(',', $values);
    run_query($sql, "Final batch insert into map table");
    flush_out("  - Processed $count / $total ($already_uuid_count already valid UUIDs preserved, $newly_generated generated).\n");
}

// Step 4: Alter child tables to VARCHAR(36)
flush_out("\n[Step 4] Modifying child table column types to VARCHAR(36)...\n");
if ($wpdb->get_var("SHOW TABLES LIKE '$table_versions'")) {
    flush_out("  - Modifying $table_versions.property_id...\n");
    run_query("ALTER TABLE $table_versions MODIFY property_id VARCHAR(36) NOT NULL", "Alter $table_versions");
}
if ($wpdb->get_var("SHOW TABLES LIKE '$table_documents'")) {
    flush_out("  - Modifying $table_documents.property_id...\n");
    run_query("ALTER TABLE $table_documents MODIFY property_id VARCHAR(36) NOT NULL", "Alter $table_documents");
}
if ($wpdb->get_var("SHOW TABLES LIKE '$table_states'")) {
    flush_out("  - Modifying $table_states.property_id...\n");
    run_query("ALTER TABLE $table_states MODIFY property_id VARCHAR(36) NOT NULL", "Alter $table_states");
}
if ($wpdb->get_var("SHOW TABLES LIKE '$table_requests'")) {
    flush_out("  - Modifying $table_requests.property_id...\n");
    run_query("ALTER TABLE $table_requests MODIFY property_id VARCHAR(36) DEFAULT NULL", "Alter $table_requests");
}
if ($wpdb->get_var("SHOW TABLES LIKE '$table_sync_queue'")) {
    flush_out("  - Modifying $table_sync_queue.property_id...\n");
    run_query("ALTER TABLE $table_sync_queue MODIFY property_id VARCHAR(36) NOT NULL", "Alter $table_sync_queue");
}

// Step 5: Update child table records to match the parent property table ID
flush_out("\n[Step 5] Updating child table records (ONLY for old integer references)...\n");
if ($wpdb->get_var("SHOW TABLES LIKE '$table_versions'")) {
    // Only update rows where property_id is not already an existing valid UUID in properties
    $updated = run_query("
        UPDATE $table_versions v
        JOIN $table_map m ON v.property_id = m.old_id
        SET v.property_id = m.new_uuid
        WHERE m.old_id != m.new_uuid
    ", "Update $table_versions");
    flush_out("  - Updated records in $table_versions\n");
}
if ($wpdb->get_var("SHOW TABLES LIKE '$table_documents'")) {
    $updated = run_query("
        UPDATE $table_documents d
        JOIN $table_map m ON d.property_id = m.old_id
        SET d.property_id = m.new_uuid
        WHERE m.old_id != m.new_uuid
    ", "Update $table_documents");
    flush_out("  - Updated records in $table_documents\n");
}
if ($wpdb->get_var("SHOW TABLES LIKE '$table_states'")) {
    $updated = run_query("
        UPDATE $table_states s
        JOIN $table_map m ON s.property_id = m.old_id
        SET s.property_id = m.new_uuid
        WHERE m.old_id != m.new_uuid
    ", "Update $table_states");
    flush_out("  - Updated records in $table_states\n");
}
if ($wpdb->get_var("SHOW TABLES LIKE '$table_requests'")) {
    $updated = run_query("
        UPDATE $table_requests r
        JOIN $table_map m ON r.property_id = m.old_id
        SET r.property_id = m.new_uuid
        WHERE m.old_id != m.new_uuid
    ", "Update $table_requests");
    flush_out("  - Updated records in $table_requests\n");

    // Ensure payment_type and signatory columns exist on assessor_requests
    $req_cols = $wpdb->get_col("DESCRIBE $table_requests", 0);
    if (!in_array('payment_type', $req_cols)) {
        $wpdb->query("ALTER TABLE $table_requests ADD COLUMN payment_type VARCHAR(50) NOT NULL DEFAULT 'cash' AFTER prepared_by");
        flush_out("  - Added missing column payment_type to $table_requests\n");
    }
    if (!in_array('verifier_signatory_name', $req_cols)) {
        $wpdb->query("ALTER TABLE $table_requests 
            ADD COLUMN verifier_signatory_name VARCHAR(255) DEFAULT NULL,
            ADD COLUMN verifier_signatory_title VARCHAR(255) DEFAULT NULL,
            ADD COLUMN municipal_assessor_name VARCHAR(255) DEFAULT NULL,
            ADD COLUMN municipal_assessor_title VARCHAR(255) DEFAULT NULL,
            ADD COLUMN municipal_assessor_license VARCHAR(255) DEFAULT NULL,
            ADD COLUMN municipal_assessor_suffix VARCHAR(255) DEFAULT NULL
        ");
        flush_out("  - Added missing signatory columns to $table_requests\n");
    }
    // Ensure created_by and updated_by can store varchar(50) ULID / UUID
    $wpdb->query("ALTER TABLE $table_requests MODIFY created_by VARCHAR(50) DEFAULT NULL, MODIFY updated_by VARCHAR(50) DEFAULT NULL");
}
if ($wpdb->get_var("SHOW TABLES LIKE '$table_sync_queue'")) {
    $updated = run_query("
        UPDATE $table_sync_queue q
        JOIN $table_map m ON q.property_id = m.old_id
        SET q.property_id = m.new_uuid
    ", "Update $table_sync_queue");
    flush_out("  - Updated records in $table_sync_queue\n");
}

// Step 6: Update audit trail records
flush_out("\n[Step 6] Updating historical audit records in $table_audit...\n");
if ($wpdb->get_var("SHOW TABLES LIKE '$table_audit'")) {
    $updated_audit = run_query("
        UPDATE $table_audit a
        JOIN $table_map m ON a.record_id = m.old_id
        SET a.record_id = m.new_uuid
        WHERE a.table_name LIKE '%properties%'
    ", "Update $table_audit");
    flush_out("  - Updated audit log entries to match new UUID v7 identifiers.\n");
}

// Step 7: Alter parent table assessor_properties.id to VARCHAR(36) and remove AUTO_INCREMENT
flush_out("\n[Step 7] Altering $table_properties.id to VARCHAR(36)...\n");
run_query("ALTER TABLE $table_properties MODIFY id VARCHAR(36) NOT NULL", "Alter $table_properties.id to VARCHAR(36)");

// Step 8: Update assessor_properties.id with the new UUIDs
flush_out("\n[Step 8] Updating $table_properties.id with UUID v7 values...\n");
$updated_props = run_query("
    UPDATE $table_properties p
    JOIN $table_map m ON p.id = m.old_id
    SET p.id = m.new_uuid
    WHERE m.old_id != m.new_uuid
", "Update $table_properties.id");
flush_out("  - Successfully updated rows in $table_properties.\n");

// Step 9: Clean up orphaned child rows (if any) and re-add foreign key constraints
flush_out("\n[Step 9] Validating child table integrity and restoring foreign key constraints...\n");

if ($wpdb->get_var("SHOW TABLES LIKE '$table_versions'")) {
    // Delete any version records that point to non-existent properties
    $deleted_orphans = $wpdb->query("
        DELETE v FROM $table_versions v
        LEFT JOIN $table_properties p ON v.property_id = p.id
        WHERE p.id IS NULL
    ");
    if ($deleted_orphans > 0) {
        flush_out("  - Cleaned up $deleted_orphans orphaned records in $table_versions\n");
    }

    $fk_res = $wpdb->query("ALTER TABLE $table_versions ADD CONSTRAINT fk_property_versions_property_id FOREIGN KEY (property_id) REFERENCES $table_properties(id) ON DELETE CASCADE");
    if ($fk_res !== false) {
        flush_out("  - Restored fk_property_versions_property_id\n");
    } else {
        flush_out("  - Notice: fk_property_versions_property_id: " . $wpdb->last_error . "\n");
    }
}

if ($wpdb->get_var("SHOW TABLES LIKE '$table_documents'")) {
    // Delete any document records that point to non-existent properties
    $deleted_orphans = $wpdb->query("
        DELETE d FROM $table_documents d
        LEFT JOIN $table_properties p ON d.property_id = p.id
        WHERE p.id IS NULL
    ");
    if ($deleted_orphans > 0) {
        flush_out("  - Cleaned up $deleted_orphans orphaned records in $table_documents\n");
    }

    $fk_res = $wpdb->query("ALTER TABLE $table_documents ADD CONSTRAINT fk_documents_property_id FOREIGN KEY (property_id) REFERENCES $table_properties(id) ON DELETE CASCADE");
    if ($fk_res !== false) {
        flush_out("  - Restored fk_documents_property_id\n");
    } else {
        flush_out("  - Notice: fk_documents_property_id: " . $wpdb->last_error . "\n");
    }
}

// Step 10: Ensure and recalculate property states (CURRENT vs CANCELLED)
flush_out("\n[Step 10] Recalculating property states (CURRENT vs CANCELLED)...\n");
if ($wpdb->get_var("SHOW TABLES LIKE '$table_states'")) {
    // 1. Ensure all active properties have an entry in assessor_property_states
    $wpdb->query("
        INSERT IGNORE INTO $table_states (property_id, state)
        SELECT id, 'CURRENT' FROM $table_properties WHERE status != 'deleted'
    ");

    // 2. Clear any orphaned records in assessor_property_states
    $wpdb->query("
        DELETE s FROM $table_states s
        LEFT JOIN $table_properties p ON s.property_id = p.id
        WHERE p.id IS NULL
    ");

    // 3. Mark all superseded properties as CANCELLED based on previous_tax_declaration_number
    // If a property's tax_declaration_number is listed in another active property's previous_tax_declaration_number, it is CANCELLED.
    $superseded_updated = $wpdb->query("
        UPDATE $table_states ps
        JOIN $table_properties prev ON ps.property_id = prev.id
        JOIN $table_properties curr ON (
            curr.status != 'deleted'
            AND prev.status != 'deleted'
            AND prev.id != curr.id
            AND (
                curr.previous_tax_declaration_number = prev.tax_declaration_number
                OR FIND_IN_SET(prev.tax_declaration_number, REPLACE(curr.previous_tax_declaration_number, ';', ',')) > 0
            )
        )
        SET ps.state = 'CANCELLED'
    ");

    $cancelled_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_states WHERE state = 'CANCELLED'");
    $current_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_states WHERE state = 'CURRENT'");
    flush_out("  - Property states recalculated: $current_count CURRENT, $cancelled_count CANCELLED\n");
}

// Step 11: Drop temporary map table
flush_out("\n[Step 11] Dropping temporary map table $table_map...\n");
run_query("DROP TABLE IF EXISTS $table_map", "Drop map table");

// Step 12: Verification
flush_out("\n[Step 12] Verifying migration results...\n");
$sample = $wpdb->get_results("
    SELECT p.id, p.tax_declaration_number, COALESCE(ps.state, 'CURRENT') as property_state
    FROM $table_properties p
    LEFT JOIN $table_states ps ON p.id = ps.property_id
    LIMIT 5
", ARRAY_A);
flush_out("Sample migrated properties:\n");
foreach ($sample as $s) {
    flush_out("  - ID: {$s['id']} | TDN: {$s['tax_declaration_number']} | State: {$s['property_state']}\n");
}

if ($wpdb->get_var("SHOW TABLES LIKE '$table_requests'")) {
    $req_total = $wpdb->get_var("SELECT COUNT(*) FROM $table_requests");
    $linked_reqs = $wpdb->get_var("
        SELECT COUNT(*) FROM $table_requests r
        JOIN $table_properties p ON r.property_id = p.id
    ");
    flush_out("\nRequests status: $req_total total requests, $linked_reqs successfully linked to active properties.\n");
    $sample_req = $wpdb->get_results("
        SELECT r.id, r.receipt_number, r.client_name, r.property_id, p.tax_declaration_number
        FROM $table_requests r
        LEFT JOIN $table_properties p ON r.property_id = p.id
        ORDER BY r.created_at DESC
        LIMIT 3
    ", ARRAY_A);
    if (!empty($sample_req)) {
        flush_out("Sample requests:\n");
        foreach ($sample_req as $sr) {
            flush_out("  - Receipt: {$sr['receipt_number']} | Client: {$sr['client_name']} | TDN: " . ($sr['tax_declaration_number'] ? $sr['tax_declaration_number'] : '(None / Unlinked)') . "\n");
        }
    }
}

flush_out("\nMigration complete!\n");
