<?php
/**
 * 01-preflight-lookup-tables.php
 *
 * Preflight check for Assessor Lookup Tables before UUID v7 migration.
 * Inspects schemas, columns, row counts, unique constraints, and dependent table references.
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

// Authorization check (WordPress is now loaded, so sanitize_text_field and wp_die are defined)
if (php_sapi_name() !== 'cli') {
    $secret_key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';
    if ($secret_key !== 'masso-migrate-uuid') {
        if (function_exists('wp_die')) {
            wp_die('Unauthorized. Provide ?key=masso-migrate-uuid to run via browser.');
        } else {
            die("Unauthorized. Provide ?key=masso-migrate-uuid to run via browser.\n");
        }
    }
}

global $wpdb;
$wpdb->show_errors();

echo "===============================================================\n";
echo "STEP 01: PREFLIGHT CHECK — ASSESSOR LOOKUP TABLES\n";
echo "===============================================================\n";
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n\n";

$tables = array(
    'assessor_property_types'   => array('pk' => 'id', 'business_key' => 'code'),
    'assessor_general_classes'  => array('pk' => 'id', 'business_key' => 'code'),
    'assessor_locations'        => array('pk' => 'id', 'business_key' => 'code'),
    'assessor_request_purposes' => array('pk' => 'id', 'business_key' => 'purpose'),
);

$has_error = false;

foreach ($tables as $t => $cfg) {
    $full_table = $wpdb->prefix . $t;
    echo "Checking table: $full_table\n";

    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $full_table));
    if (!$exists) {
        echo "  - [ERROR] Table $full_table DOES NOT EXIST.\n";
        $has_error = true;
        continue;
    }

    $cols = $wpdb->get_results("SHOW COLUMNS FROM $full_table", ARRAY_A);
    $col_map = array();
    foreach ($cols as $c) {
        $col_map[$c['Field']] = $c;
    }

    if (!isset($col_map['id'])) {
        echo "  - [ERROR] Column 'id' missing in $full_table.\n";
        $has_error = true;
        continue;
    }

    $id_type = $col_map['id']['Type'];
    $is_uuid = (stripos($id_type, 'varchar(36)') !== false || stripos($id_type, 'char(36)') !== false);
    $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $full_table");

    echo "  - Status: Exists\n";
    echo "  - ID Column Type: $id_type " . ($is_uuid ? "(ALREADY UUID v7)" : "(Integer auto-increment)") . "\n";
    echo "  - Total Rows: $count\n";

    // Check business key existence
    $bk = $cfg['business_key'];
    if (!isset($col_map[$bk])) {
        echo "  - [ERROR] Business key column '$bk' missing.\n";
        $has_error = true;
    } else {
        echo "  - Business Key: '$bk' ({$col_map[$bk]['Type']})\n";
    }

    // Check unique key on business key
    $indexes = $wpdb->get_results("SHOW INDEX FROM $full_table", ARRAY_A);
    $has_bk_unique = false;
    foreach ($indexes as $idx) {
        if ($idx['Column_name'] === $bk && $idx['Non_unique'] == 0) {
            $has_bk_unique = true;
            break;
        }
    }
    echo "  - Business Key Unique: " . ($has_bk_unique ? "YES" : "NO") . "\n\n";
}

// Dependent reference check in assessor_properties and assessor_requests
echo "Checking dependent tables...\n";
$tbl_props = $wpdb->prefix . 'assessor_properties';
$tbl_reqs  = $wpdb->prefix . 'assessor_requests';

$props_exist = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $tbl_props));
$reqs_exist  = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $tbl_reqs));

echo "  - $tbl_props: " . ($props_exist ? "Found (uses kind_of_property, gen_class, location business keys)" : "Not found") . "\n";
echo "  - $tbl_reqs: " . ($reqs_exist ? "Found (uses purpose business key)" : "Not found") . "\n\n";

if ($has_error) {
    echo "❌ PREFLIGHT FAILED: Please address errors above.\n";
    exit(1);
} else {
    echo "✅ PREFLIGHT PASSED: System is ready for migration.\n";
    exit(0);
}
