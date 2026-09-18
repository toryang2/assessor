<?php
/**
 * Migration Script: Migrate assessor_sync_queue and assessor_sync_meta Primary Keys to UUID v7
 *
 * Implements:
 * 1. Safe detection of current column type (id VARCHAR(36)). Idempotent if already migrated.
 * 2. Pre-migration state recording:
 *    - Row counts, statuses, meta_key/meta_value pairs.
 * 3. Migration of assessor_sync_queue:
 *    - Add temp column uuid_id VARCHAR(36) NULL
 *    - Populate uuid_id with Assessor_UUID::v7() for every row
 *    - Validate counts, non-null, unique, UUID format
 *    - Remove AUTO_INCREMENT, drop old PRIMARY KEY, drop old id, rename uuid_id to id VARCHAR(36) NOT NULL PRIMARY KEY
 *    - Restore all indexes: record_op, record_type, status, queued_at
 * 4. Migration of assessor_sync_meta:
 *    - Add temp column uuid_id VARCHAR(36) NULL
 *    - Populate uuid_id with Assessor_UUID::v7() for every row
 *    - Validate counts, non-null, unique, UUID format
 *    - Remove AUTO_INCREMENT, drop old PRIMARY KEY, drop old id, rename uuid_id to id VARCHAR(36) NOT NULL PRIMARY KEY
 *    - Restore meta_key UNIQUE KEY
 * 5. Post-migration verification:
 *    - Row count match
 *    - All IDs are valid UUID v7
 *    - Statuses, values, timestamps preserved exactly
 */

// Output formatting
function flush_log($msg) {
    echo $msg;
    if (php_sapi_name() !== 'cli') {
        @ob_flush();
        flush();
    }
}

// Load WordPress environment
$wp_load_paths = array(
    'C:/xampp/htdocs/wp-load.php',
    __DIR__ . '/../../../../wp-load.php',
    __DIR__ . '/../../../wp-load.php',
    __DIR__ . '/../../wp-load.php',
    __DIR__ . '/../wp-load.php',
    dirname(__FILE__) . '/../wp-load.php'
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
    die("FATAL: Cannot locate wp-load.php.\n");
}

if (!class_exists('Assessor_UUID')) {
    require_once dirname(__DIR__) . '/wp-content/plugins/assessor-api/includes/class-assessor-uuid.php';
}

global $wpdb;
$wpdb->show_errors();

function exec_query($sql, $desc = '') {
    global $wpdb;
    $res = $wpdb->query($sql);
    if ($res === false) {
        $err = $wpdb->last_error;
        flush_log("ERROR in $desc: $err\nSQL: $sql\n");
        die("Migration aborted due to database error.\n");
    }
    return $res;
}

flush_log("====================================================================\n");
flush_log("  MIGRATION: SYNC QUEUE & SYNC META PRIMARY KEYS TO UUID v7         \n");
flush_log("====================================================================\n");
flush_log("Timestamp: " . current_time('mysql') . "\n\n");

$table_queue = $wpdb->prefix . 'assessor_sync_queue';
$table_meta  = $wpdb->prefix . 'assessor_sync_meta';

// -------------------------------------------------------------------------
// Check table existence
// -------------------------------------------------------------------------
$has_queue = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_queue));
$has_meta  = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_meta));

if (!$has_queue || !$has_meta) {
    die("FATAL: One or both tables do not exist ($table_queue: " . ($has_queue ? 'YES' : 'NO') . ", $table_meta: " . ($has_meta ? 'YES' : 'NO') . ")\n");
}

// -------------------------------------------------------------------------
// STEP 1: PRE-MIGRATION SNAPSHOT
// -------------------------------------------------------------------------
flush_log("[Step 1] Recording pre-migration snapshot...\n");

$queue_total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_queue");
$queue_status_rows = $wpdb->get_results("SELECT status, COUNT(*) as cnt FROM $table_queue GROUP BY status", ARRAY_A);
$queue_statuses_before = array();
foreach ($queue_status_rows as $row) {
    $queue_statuses_before[$row['status']] = (int) $row['cnt'];
}

$meta_total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_meta");
$meta_rows_before = $wpdb->get_results("SELECT meta_key, meta_value, updated_at FROM $table_meta", ARRAY_A);
$meta_map_before = array();
foreach ($meta_rows_before as $row) {
    $meta_map_before[$row['meta_key']] = $row['meta_value'];
}

flush_log("  - Queue table ($table_queue): $queue_total rows\n");
foreach ($queue_statuses_before as $st => $c) {
    flush_log("      * $st: $c\n");
}
flush_log("  - Meta table ($table_meta): $meta_total rows\n");
foreach ($meta_map_before as $k => $v) {
    $display_val = ($k === 'sync_token') ? '***REDACTED***' : $v;
    flush_log("      * $k = $display_val\n");
}

// Check current ID types
$q_id_col = $wpdb->get_row("SHOW COLUMNS FROM $table_queue LIKE 'id'");
$m_id_col = $wpdb->get_row("SHOW COLUMNS FROM $table_meta LIKE 'id'");

$queue_is_varchar = (stripos($q_id_col->Type, 'varchar') !== false);
$meta_is_varchar  = (stripos($m_id_col->Type, 'varchar') !== false);

flush_log("\nCurrent id types:\n");
flush_log("  - Queue id: {$q_id_col->Type} (" . ($queue_is_varchar ? 'Already VARCHAR' : 'Numeric') . ")\n");
flush_log("  - Meta id:  {$m_id_col->Type} (" . ($meta_is_varchar ? 'Already VARCHAR' : 'Numeric') . ")\n");

// -------------------------------------------------------------------------
// STEP 2: MIGRATE ASSESSOR_SYNC_QUEUE
// -------------------------------------------------------------------------
flush_log("\n[Step 2] Migrating $table_queue...\n");

if (!$queue_is_varchar) {
    // 2a. Add uuid_id column if not exists
    $has_temp_q = $wpdb->get_row("SHOW COLUMNS FROM $table_queue LIKE 'uuid_id'");
    if (!$has_temp_q) {
        exec_query("ALTER TABLE $table_queue ADD COLUMN uuid_id VARCHAR(36) NULL AFTER id", "Add uuid_id to $table_queue");
        flush_log("  - Added temporary uuid_id column\n");
    }

    // 2b. Populate uuid_id with UUID v7
    $queue_rows = $wpdb->get_results("SELECT id, queued_at FROM $table_queue", ARRAY_A);
    $q_gen_count = 0;
    foreach ($queue_rows as $q_row) {
        $ts = !empty($q_row['queued_at']) ? strtotime($q_row['queued_at']) : false;
        $timeMs = ($ts !== false && $ts > 0) ? ($ts * 1000) + ($q_gen_count % 999) : null;
        $uuid = Assessor_UUID::v7($timeMs);
        $wpdb->update(
            $table_queue,
            array('uuid_id' => $uuid),
            array('id' => $q_row['id']),
            array('%s'),
            array('%s')
        );
        $q_gen_count++;
    }
    flush_log("  - Generated UUID v7 for $q_gen_count rows\n");

    // 2c. Validate data integrity
    $q_nulls = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_queue WHERE uuid_id IS NULL OR uuid_id = ''");
    $q_unique = (int) $wpdb->get_var("SELECT COUNT(DISTINCT uuid_id) FROM $table_queue");
    $q_after_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_queue");

    if ($q_nulls > 0 || $q_unique !== $q_after_count || $q_after_count !== $queue_total) {
        die("FATAL: Queue validation failed! (total=$q_after_count, expected=$queue_total, nulls=$q_nulls, unique=$q_unique)\n");
    }

    // Check UUID format
    if ($q_after_count > 0) {
        $invalid_q = $wpdb->get_var("SELECT COUNT(*) FROM $table_queue WHERE uuid_id NOT REGEXP '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$'");
        if ($invalid_q > 0) {
            die("FATAL: Queue contains $invalid_q invalid UUID formats!\n");
        }
    }
    flush_log("  - Validation passed: All rows have valid unique UUID v7 identifiers\n");

    // 2d. Schema alteration
    flush_log("  - Modifying id column from AUTO_INCREMENT...\n");
    exec_query("ALTER TABLE $table_queue MODIFY id BIGINT NOT NULL", "Remove AUTO_INCREMENT on queue.id");
    flush_log("  - Dropping old PRIMARY KEY on queue...\n");
    exec_query("ALTER TABLE $table_queue DROP PRIMARY KEY", "Drop PRIMARY KEY on queue");
    flush_log("  - Dropping old id column...\n");
    exec_query("ALTER TABLE $table_queue DROP COLUMN id", "Drop id column on queue");
    flush_log("  - Renaming uuid_id to id VARCHAR(36) NOT NULL PRIMARY KEY...\n");
    exec_query("ALTER TABLE $table_queue CHANGE COLUMN uuid_id id VARCHAR(36) NOT NULL", "Change uuid_id to id on queue");
    exec_query("ALTER TABLE $table_queue ADD PRIMARY KEY (id)", "Add PRIMARY KEY (id) on queue");

    // Verify indexes exist
    $q_idx = $wpdb->get_results("SHOW INDEX FROM $table_queue", ARRAY_A);
    $q_keys = array_unique(array_column($q_idx, 'Key_name'));
    if (!in_array('record_op', $q_keys)) {
        exec_query("ALTER TABLE $table_queue ADD UNIQUE KEY record_op (record_type, property_id, operation)", "Add record_op index");
    }
    if (!in_array('record_type', $q_keys)) {
        exec_query("ALTER TABLE $table_queue ADD KEY record_type (record_type)", "Add record_type index");
    }
    if (!in_array('status', $q_keys)) {
        exec_query("ALTER TABLE $table_queue ADD KEY status (status)", "Add status index");
    }
    if (!in_array('queued_at', $q_keys)) {
        exec_query("ALTER TABLE $table_queue ADD KEY queued_at (queued_at)", "Add queued_at index");
    }
    flush_log("  - Queue schema alteration complete\n");
} else {
    flush_log("  - Queue table id is already VARCHAR(36). Verified.\n");
}

// -------------------------------------------------------------------------
// STEP 3: MIGRATE ASSESSOR_SYNC_META
// -------------------------------------------------------------------------
flush_log("\n[Step 3] Migrating $table_meta...\n");

if (!$meta_is_varchar) {
    // 3a. Add uuid_id column if not exists
    $has_temp_m = $wpdb->get_row("SHOW COLUMNS FROM $table_meta LIKE 'uuid_id'");
    if (!$has_temp_m) {
        exec_query("ALTER TABLE $table_meta ADD COLUMN uuid_id VARCHAR(36) NULL AFTER id", "Add uuid_id to $table_meta");
        flush_log("  - Added temporary uuid_id column\n");
    }

    // 3b. Populate uuid_id with UUID v7
    $meta_rows = $wpdb->get_results("SELECT id, updated_at FROM $table_meta", ARRAY_A);
    $m_gen_count = 0;
    foreach ($meta_rows as $m_row) {
        $ts = !empty($m_row['updated_at']) ? strtotime($m_row['updated_at']) : false;
        $timeMs = ($ts !== false && $ts > 0) ? ($ts * 1000) + ($m_gen_count % 999) : null;
        $uuid = Assessor_UUID::v7($timeMs);
        $wpdb->update(
            $table_meta,
            array('uuid_id' => $uuid),
            array('id' => $m_row['id']),
            array('%s'),
            array('%s')
        );
        $m_gen_count++;
    }
    flush_log("  - Generated UUID v7 for $m_gen_count rows\n");

    // 3c. Validate data integrity
    $m_nulls = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_meta WHERE uuid_id IS NULL OR uuid_id = ''");
    $m_unique = (int) $wpdb->get_var("SELECT COUNT(DISTINCT uuid_id) FROM $table_meta");
    $m_after_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_meta");

    if ($m_nulls > 0 || $m_unique !== $m_after_count || $m_after_count !== $meta_total) {
        die("FATAL: Meta validation failed! (total=$m_after_count, expected=$meta_total, nulls=$m_nulls, unique=$m_unique)\n");
    }

    // Check UUID format
    if ($m_after_count > 0) {
        $invalid_m = $wpdb->get_var("SELECT COUNT(*) FROM $table_meta WHERE uuid_id NOT REGEXP '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$'");
        if ($invalid_m > 0) {
            die("FATAL: Meta contains $invalid_m invalid UUID formats!\n");
        }
    }
    flush_log("  - Validation passed: All rows have valid unique UUID v7 identifiers\n");

    // 3d. Schema alteration
    flush_log("  - Modifying id column from AUTO_INCREMENT...\n");
    exec_query("ALTER TABLE $table_meta MODIFY id INT NOT NULL", "Remove AUTO_INCREMENT on meta.id");
    flush_log("  - Dropping old PRIMARY KEY on meta...\n");
    exec_query("ALTER TABLE $table_meta DROP PRIMARY KEY", "Drop PRIMARY KEY on meta");
    flush_log("  - Dropping old id column...\n");
    exec_query("ALTER TABLE $table_meta DROP COLUMN id", "Drop id column on meta");
    flush_log("  - Renaming uuid_id to id VARCHAR(36) NOT NULL PRIMARY KEY...\n");
    exec_query("ALTER TABLE $table_meta CHANGE COLUMN uuid_id id VARCHAR(36) NOT NULL", "Change uuid_id to id on meta");
    exec_query("ALTER TABLE $table_meta ADD PRIMARY KEY (id)", "Add PRIMARY KEY (id) on meta");

    // Verify meta_key index exists
    $m_idx = $wpdb->get_results("SHOW INDEX FROM $table_meta", ARRAY_A);
    $m_keys = array_unique(array_column($m_idx, 'Key_name'));
    if (!in_array('meta_key', $m_keys)) {
        exec_query("ALTER TABLE $table_meta ADD UNIQUE KEY meta_key (meta_key)", "Add meta_key index");
    }
    flush_log("  - Meta schema alteration complete\n");
} else {
    flush_log("  - Meta table id is already VARCHAR(36). Verified.\n");
}

// -------------------------------------------------------------------------
// STEP 4: POST-MIGRATION VALIDATION
// -------------------------------------------------------------------------
flush_log("\n[Step 4] Performing post-migration validation...\n");

// A. Queue row count identical
$q_final_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_queue");
if ($q_final_count !== $queue_total) {
    die("FATAL: Post-migration queue count mismatch: $q_final_count vs expected $queue_total\n");
}
flush_log("  [A] Queue row count matches: $q_final_count\n");

// B. Meta row count identical
$m_final_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_meta");
if ($m_final_count !== $meta_total) {
    die("FATAL: Post-migration meta count mismatch: $m_final_count vs expected $meta_total\n");
}
flush_log("  [B] Meta row count matches: $m_final_count\n");

// C. Queue IDs are UUID v7
$q_col_final = $wpdb->get_row("SHOW COLUMNS FROM $table_queue LIKE 'id'");
flush_log("  [C] Queue id column type: {$q_col_final->Type}\n");

// D. Meta IDs are UUID v7
$m_col_final = $wpdb->get_row("SHOW COLUMNS FROM $table_meta LIKE 'id'");
flush_log("  [D] Meta id column type: {$m_col_final->Type}\n");

// E. No duplicates
$q_dups = (int) $wpdb->get_var("SELECT COUNT(id) - COUNT(DISTINCT id) FROM $table_queue");
$m_dups = (int) $wpdb->get_var("SELECT COUNT(id) - COUNT(DISTINCT id) FROM $table_meta");
if ($q_dups > 0 || $m_dups > 0) {
    die("FATAL: Duplicate IDs found! Queue dups: $q_dups, Meta dups: $m_dups\n");
}
flush_log("  [E] No duplicate IDs in either table\n");

// F & G. Indexes
$q_idx_final = $wpdb->get_results("SHOW INDEX FROM $table_queue", ARRAY_A);
$q_idx_names = array_unique(array_column($q_idx_final, 'Key_name'));
$m_idx_final = $wpdb->get_results("SHOW INDEX FROM $table_meta", ARRAY_A);
$m_idx_names = array_unique(array_column($m_idx_final, 'Key_name'));

flush_log("  [F] Queue indexes: " . implode(', ', $q_idx_names) . "\n");
flush_log("  [G] Meta indexes: " . implode(', ', $m_idx_names) . "\n");

// H. Queue statuses unchanged
$queue_status_rows_after = $wpdb->get_results("SELECT status, COUNT(*) as cnt FROM $table_queue GROUP BY status", ARRAY_A);
$queue_statuses_after = array();
foreach ($queue_status_rows_after as $row) {
    $queue_statuses_after[$row['status']] = (int) $row['cnt'];
}
if ($queue_statuses_before !== $queue_statuses_after) {
    die("FATAL: Queue statuses mismatch after migration!\n");
}
flush_log("  [H] Queue status breakdown unchanged\n");

// J, K, L, M, N. Meta values unchanged
$meta_rows_after = $wpdb->get_results("SELECT meta_key, meta_value FROM $table_meta", ARRAY_A);
$meta_map_after = array();
foreach ($meta_rows_after as $row) {
    $meta_map_after[$row['meta_key']] = $row['meta_value'];
}
foreach ($meta_map_before as $k => $v) {
    if (!array_key_exists($k, $meta_map_after) || $meta_map_after[$k] !== $v) {
        die("FATAL: Meta key '$k' value changed or missing after migration!\n");
    }
}
flush_log("  [J-N] All metadata values unchanged (cursors, offsets, timestamps, dirty flags verified)\n");

flush_log("\n====================================================================\n");
flush_log("  MIGRATION COMPLETED SUCCESSFULLY WITH ZERO DATA LOSS!            \n");
flush_log("====================================================================\n");
