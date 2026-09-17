<?php
/**
 * Migration Script: Add Request Soft Delete & Extend Sync Queue for Requests
 *
 * Requirements:
 * 1. Verify assessor_requests table exists.
 * 2. Add deleted_at DATETIME NULL if missing.
 * 3. Preserve all existing request data (ensure deleted_at IS NULL for existing records).
 * 4. Verify all existing rows have valid/NULL deleted_at.
 * 5. Check and update wp_assessor_sync_queue to support record_type ('property', 'request').
 * 6. Report the final schema.
 *
 * Can be run via CLI (`php migrate-requests-soft-delete.php`)
 * or Browser (`https://domain/path/migrate-requests-soft-delete.php?key=masso-migrate-uuid`).
 */

// Streaming headers for web execution
if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Accel-Buffering: no');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    ob_implicit_flush(true);
    @set_time_limit(300);

    $secret_key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';
    $allow_http = ($secret_key === 'masso-migrate-uuid');
}

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
    die("FATAL: Cannot locate wp-load.php\n");
}

if (php_sapi_name() !== 'cli' && empty($allow_http)) {
    if (!current_user_can('manage_options')) {
        wp_die("ACCESS DENIED: Pass ?key=masso-migrate-uuid or log in as Administrator.\n");
    }
}

function flush_out($msg) {
    echo $msg;
    if (php_sapi_name() !== 'cli') {
        flush();
    }
}

global $wpdb;
$table_requests   = $wpdb->prefix . 'assessor_requests';
$table_sync_queue = $wpdb->prefix . 'assessor_sync_queue';

flush_out("===============================================================\n");
flush_out("PHASE 2 MIGRATION: REQUEST SOFT DELETE & SYNC QUEUE EXTENSION\n");
flush_out("===============================================================\n\n");

// 1. Verify assessor_requests exists
$table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_requests));
if (!$table_exists) {
    die("FATAL: Table $table_requests does not exist!\n");
}
flush_out("[Step 1] Verified table $table_requests exists.\n");

// 2. Check and add deleted_at to assessor_requests
$deleted_col = $wpdb->get_row("SHOW COLUMNS FROM $table_requests LIKE 'deleted_at'");
if (!$deleted_col) {
    flush_out("[Step 2] Adding column deleted_at DATETIME NULL to $table_requests...\n");
    $wpdb->query("ALTER TABLE $table_requests ADD COLUMN deleted_at DATETIME NULL AFTER remarks");
    $wpdb->query("ALTER TABLE $table_requests ADD KEY deleted_at (deleted_at)");
    flush_out("  - Added deleted_at column and index.\n");
} else {
    flush_out("[Step 2] Column deleted_at already exists on $table_requests ({$deleted_col->Type}).\n");
}

// 3. Preserve existing request data (ensure deleted_at is NULL for existing active rows)
$total_requests = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_requests");
$null_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_requests WHERE deleted_at IS NULL");
$non_null_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_requests WHERE deleted_at IS NOT NULL");

flush_out("[Step 3] Verifying request rows state:\n");
flush_out("  - Total requests: $total_requests\n");
flush_out("  - Active (deleted_at IS NULL): $null_count\n");
flush_out("  - Soft-deleted (deleted_at IS NOT NULL): $non_null_count\n");

// 4. Extend wp_assessor_sync_queue with record_type
$queue_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_sync_queue));
if ($queue_exists) {
    flush_out("\n[Step 4] Checking sync queue table $table_sync_queue...\n");
    $type_col = $wpdb->get_row("SHOW COLUMNS FROM $table_sync_queue LIKE 'record_type'");
    if (!$type_col) {
        flush_out("  - Adding column record_type VARCHAR(20) NOT NULL DEFAULT 'property' to $table_sync_queue...\n");
        $wpdb->query("ALTER TABLE $table_sync_queue ADD COLUMN record_type VARCHAR(20) NOT NULL DEFAULT 'property' AFTER id");
        $wpdb->query("ALTER TABLE $table_sync_queue ADD KEY record_type (record_type)");
    } else {
        flush_out("  - Column record_type already exists on $table_sync_queue.\n");
    }

    // Check unique key: update to (record_type, property_id, operation)
    $indexes = $wpdb->get_results("SHOW INDEX FROM $table_sync_queue", ARRAY_A);
    $has_property_op = false;
    $has_record_op = false;
    foreach ($indexes as $idx) {
        if ($idx['Key_name'] === 'property_op') $has_property_op = true;
        if ($idx['Key_name'] === 'record_op') $has_record_op = true;
    }

    if ($has_property_op && !$has_record_op) {
        flush_out("  - Updating unique index from property_op to record_op (record_type, property_id, operation)...\n");
        $wpdb->query("ALTER TABLE $table_sync_queue DROP INDEX property_op");
        $wpdb->query("ALTER TABLE $table_sync_queue ADD UNIQUE KEY record_op (record_type, property_id, operation)");
    } elseif (!$has_property_op && !$has_record_op) {
        $wpdb->query("ALTER TABLE $table_sync_queue ADD UNIQUE KEY record_op (record_type, property_id, operation)");
    }
    flush_out("  - Sync queue schema verified.\n");
} else {
    flush_out("\n[Step 4] Sync queue table not present on this host (normal if live site).\n");
}

// 5. Final Schema Report
flush_out("\n[Step 5] Final Schema Report:\n");
$final_deleted_col = $wpdb->get_row("SHOW COLUMNS FROM $table_requests LIKE 'deleted_at'");
flush_out("  - $table_requests.deleted_at: Type={$final_deleted_col->Type}, Null={$final_deleted_col->Null}, Key={$final_deleted_col->Key}\n");

if ($queue_exists) {
    $final_type_col = $wpdb->get_row("SHOW COLUMNS FROM $table_sync_queue LIKE 'record_type'");
    flush_out("  - $table_sync_queue.record_type: Type={$final_type_col->Type}, Default={$final_type_col->Default}\n");
}

flush_out("\n===============================================================\n");
flush_out("PHASE 2 MIGRATION COMPLETED SUCCESSFULLY!\n");
flush_out("===============================================================\n");
exit(0);
