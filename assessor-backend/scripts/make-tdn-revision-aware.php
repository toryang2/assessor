<?php
/**
 * Migration Script: 10 - Make TDN Uniqueness Revision-Aware
 *
 * Database updates:
 * - Remove global UNIQUE index on tax_declaration_number
 * - Retain normal index on tax_declaration_number
 * - Add composite index on (tax_declaration_number, revision_id)
 * - Idempotent and safe to run multiple times
 */

if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Accel-Buffering: no');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    ob_implicit_flush(true);
    @set_time_limit(600);

    $secret_key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';
    $allow_http = ($secret_key === 'masso-migrate-uuid');
}

// Load WordPress environment
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
    die("FATAL: Cannot locate wp-load.php.\n");
}

if (php_sapi_name() !== 'cli' && empty($allow_http)) {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized access: Administrator privileges required.');
    }
}

function flush_out($msg) {
    echo $msg;
    if (php_sapi_name() !== 'cli') {
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

global $wpdb;
$table_properties = $wpdb->prefix . 'assessor_properties';

flush_out("===============================================================\n");
flush_out("MAKE TDN UNIQUENESS REVISION-AWARE (10)\n");
flush_out("===============================================================\n\n");

// 1. Inspect existing indexes on tax_declaration_number
flush_out("[Step 1] Inspecting indexes on $table_properties.tax_declaration_number...\n");
$indexes = $wpdb->get_results("SHOW INDEX FROM $table_properties WHERE Column_name = 'tax_declaration_number'", ARRAY_A);

$has_unique_tdn = false;
$has_non_unique_tdn = false;

foreach ($indexes as $idx) {
    flush_out(sprintf("  - Index: '%s' | Non_unique: %d | Seq: %d\n", $idx['Key_name'], $idx['Non_unique'], $idx['Seq_in_index']));
    if ($idx['Key_name'] === 'tax_declaration_number' && $idx['Non_unique'] == 0) {
        $has_unique_tdn = true;
    }
    if ($idx['Key_name'] === 'tax_declaration_number' && $idx['Non_unique'] == 1) {
        $has_non_unique_tdn = true;
    }
}

// 2. Drop global UNIQUE index if present
if ($has_unique_tdn) {
    flush_out("\n[Step 2] Dropping global UNIQUE index 'tax_declaration_number'...\n");
    run_query("ALTER TABLE $table_properties DROP INDEX tax_declaration_number", "Drop unique index on TDN");
    flush_out("  - Global UNIQUE index dropped successfully.\n");
    $has_non_unique_tdn = false; // Need to recreate as non-unique
} else {
    flush_out("\n[Step 2] Global UNIQUE index 'tax_declaration_number' is already not unique or absent.\n");
}

// 3. Ensure standard (non-unique) index on tax_declaration_number
flush_out("\n[Step 3] Ensuring non-unique index 'tax_declaration_number'...\n");
$current_tdn_idx = $wpdb->get_results("SHOW INDEX FROM $table_properties WHERE Key_name = 'tax_declaration_number'", ARRAY_A);
if (empty($current_tdn_idx)) {
    run_query("ALTER TABLE $table_properties ADD KEY tax_declaration_number (tax_declaration_number)", "Add standard index on TDN");
    flush_out("  - Standard index 'tax_declaration_number' created.\n");
} else {
    flush_out("  - Standard index 'tax_declaration_number' exists.\n");
}

// 4. Ensure composite index on (tax_declaration_number, revision_id)
flush_out("\n[Step 4] Ensuring composite index 'idx_tdn_revision' (tax_declaration_number, revision_id)...\n");
$composite_idx = $wpdb->get_results("SHOW INDEX FROM $table_properties WHERE Key_name = 'idx_tdn_revision'", ARRAY_A);
if (empty($composite_idx)) {
    run_query("ALTER TABLE $table_properties ADD KEY idx_tdn_revision (tax_declaration_number, revision_id)", "Add composite index on TDN + revision_id");
    flush_out("  - Composite index 'idx_tdn_revision' created successfully.\n");
} else {
    flush_out("  - Composite index 'idx_tdn_revision' already exists.\n");
}

// 5. Final index verification
flush_out("\n[Step 5] Verifying final index configuration on $table_properties...\n");
$final_indexes = $wpdb->get_results("SHOW INDEX FROM $table_properties WHERE Column_name IN ('tax_declaration_number', 'revision_id')", ARRAY_A);
foreach ($final_indexes as $idx) {
    flush_out(sprintf("  - Key: '%s' | Column: '%s' | Non_unique: %d | Seq: %d\n", $idx['Key_name'], $idx['Column_name'], $idx['Non_unique'], $idx['Seq_in_index']));
}

flush_out("\n===============================================================\n");
flush_out("INDEX MIGRATION (10) COMPLETED SUCCESSFULLY!\n");
flush_out("===============================================================\n");
