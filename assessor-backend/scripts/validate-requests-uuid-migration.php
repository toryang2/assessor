<?php
/**
 * Validation Script: Verify Assessor Requests UUID v7 Schema and Data Integrity
 *
 * Can be executed via CLI (`php validate-requests-uuid-migration.php`)
 * or via Browser (`https://domain/path/validate-requests-uuid-migration.php?key=masso-migrate-uuid`).
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

// Ensure Assessor_UUID is available
if (!class_exists('Assessor_UUID')) {
    $uuid_file = defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR . '/assessor-api/includes/class-assessor-uuid.php' : '';
    if (!$uuid_file || !file_exists($uuid_file)) {
        $uuid_file = dirname(__DIR__) . '/wp-content/plugins/assessor-api/includes/class-assessor-uuid.php';
    }
    if (file_exists($uuid_file)) {
        require_once $uuid_file;
    }
}

global $wpdb;
$table_requests   = $wpdb->prefix . 'assessor_requests';
$table_properties = $wpdb->prefix . 'assessor_properties';
$table_map        = $wpdb->prefix . 'assessor_request_id_uuid_map';

flush_out("===============================================================\n");
flush_out("ASSESSOR REQUESTS UUID v7 VALIDATION REPORT\n");
flush_out("===============================================================\n\n");

$exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_requests));
if (!$exists) {
    die("ERROR: Table $table_requests does not exist!\n");
}

// 1. Schema check
$id_col = $wpdb->get_row("SHOW COLUMNS FROM $table_requests LIKE 'id'");
flush_out("1. Primary Key Column: {$id_col->Field}\n");
flush_out("   Primary Key Type:   {$id_col->Type}\n");
flush_out("   Key Status:         {$id_col->Key}\n");
flush_out("   Extra:              {$id_col->Extra}\n");

$is_varchar36 = (stripos($id_col->Type, 'varchar(36)') !== false);
$is_pk = ($id_col->Key === 'PRI');
$has_no_autoincrement = (stripos($id_col->Extra, 'auto_increment') === false);

flush_out("   - Is VARCHAR(36):   " . ($is_varchar36 ? "YES (PASS)" : "NO (FAIL)") . "\n");
flush_out("   - Is PRIMARY KEY:   " . ($is_pk ? "YES (PASS)" : "NO (FAIL)") . "\n");
flush_out("   - AUTO_INCREMENT:   " . ($has_no_autoincrement ? "REMOVED (PASS)" : "PRESENT (FAIL)") . "\n\n");

// 2. Row count and UUID metrics
$total_rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_requests");
$valid_uuid_rows = (int) $wpdb->get_var("
    SELECT COUNT(*) FROM $table_requests 
    WHERE id REGEXP '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-7[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$'
");
$missing_uuid_rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_requests WHERE id IS NULL OR id = ''");
$distinct_uuid_count = (int) $wpdb->get_var("SELECT COUNT(DISTINCT id) FROM $table_requests");
$duplicate_uuid_count = $total_rows - $distinct_uuid_count;

// Any old integer IDs still present?
$integer_id_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_requests WHERE id REGEXP '^[0-9]+$'");

flush_out("2. Row & Identity Metrics:\n");
flush_out("   Total request rows:           $total_rows\n");
flush_out("   Rows with valid UUID v7:      $valid_uuid_rows\n");
flush_out("   Rows missing UUID:            $missing_uuid_rows\n");
flush_out("   Duplicate UUID count:         $duplicate_uuid_count\n");
flush_out("   Old integer IDs still present: $integer_id_count\n\n");

// 3. Mapping table status
$map_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_map));
if ($map_exists) {
    $map_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_map");
    flush_out("3. Persistent Map Table ($table_map):\n");
    flush_out("   Mapped records count:         $map_count\n\n");
} else {
    flush_out("3. Persistent Map Table: Not created yet.\n\n");
}

// 4. Property Relationships
$linked_count = (int) $wpdb->get_var("
    SELECT COUNT(*) FROM $table_requests r
    JOIN $table_properties p ON r.property_id = p.id
");
$null_prop_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_requests WHERE property_id IS NULL OR property_id = ''");
flush_out("4. Property Linkage Integrity:\n");
flush_out("   Requests linked to properties: $linked_count / $total_rows\n");
flush_out("   Requests without property_id:  $null_prop_count\n\n");

// 5. Final summary assessment
$overall_pass = $is_varchar36 && $is_pk && $has_no_autoincrement && ($valid_uuid_rows === $total_rows) && ($missing_uuid_rows === 0) && ($duplicate_uuid_count === 0) && ($integer_id_count === 0);

flush_out("5. Overall Status: " . ($overall_pass ? "PASSED ALL CHECKS" : "VALIDATION FAILED") . "\n");
flush_out("===============================================================\n");
exit($overall_pass ? 0 : 1);
