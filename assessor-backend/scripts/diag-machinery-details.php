<?php
/**
 * Read-Only Diagnostic Script: Empty Machinery Details Investigation
 * Target TD: 22-010-0004-05935
 * Target RPU: RPU-180b0697:19327f6fa4c:-6c83
 */

// Step 1: Check WordPress configuration in local XAMPP/Apache
echo "=== Step 0: Check WordPress wp-config.php and database target ===\n";
$wp_config_paths = [
    'C:/xampp/htdocs/wp-config.php',
    'd:/CODE/assessor/assessor-backend/wp-config.php'
];

foreach ($wp_config_paths as $p) {
    if (file_exists($p)) {
        echo "Found wp-config at: $p\n";
        $content = file_get_contents($p);
        if (preg_match("/define\(\s*['\"]DB_NAME['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/i", $content, $m)) {
            echo " - DB_NAME: " . $m[1] . "\n";
        }
        if (preg_match("/define\(\s*['\"]DB_USER['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/i", $content, $m)) {
            echo " - DB_USER: " . $m[1] . "\n";
        }
        if (preg_match("/define\(\s*['\"]DB_HOST['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/i", $content, $m)) {
            echo " - DB_HOST: " . $m[1] . "\n";
        }
        if (preg_match('/\$table_prefix\s*=\s*[\'"]([^\'"]+)[\'"]/i', $content, $m)) {
            echo " - \$table_prefix: " . $m[1] . "\n";
        }
    } else {
        echo "Not found: $p\n";
    }
}

// Connect to assessor_local
$pdo = new PDO('mysql:host=127.0.0.1;dbname=assessor_local;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

$current_db = $pdo->query("SELECT DATABASE()")->fetchColumn();
echo "\nConnected PDO Database: $current_db\n";

echo "\n=== Step 1: Check Table Schemas in assessor_local ===\n";
$tables_to_check = [
    'wp_assessor_rpu',
    'wp_assessor_machrpu',
    'wp_assessor_machdetail',
    'wp_assessor_machine_smv',
    'wp_assessor_machine'
];

foreach ($tables_to_check as $tbl) {
    $exists = $pdo->query("SHOW TABLES LIKE '$tbl'")->fetchColumn();
    echo "Table $tbl: " . ($exists ? "EXISTS" : "DOES NOT EXIST") . "\n";
    if ($exists) {
        $cols = $pdo->query("SHOW COLUMNS FROM `$tbl`")->fetchAll();
        $col_names = array_column($cols, 'Field');
        echo "   Columns: " . implode(', ', $col_names) . "\n";
    }
}

echo "\n=== Step 2: Query exact rows for RPU-180b0697:19327f6fa4c:-6c83 ===\n";
$rpu_id = 'RPU-180b0697:19327f6fa4c:-6c83';

// 1. wp_assessor_rpu
$rpu_row = $pdo->query("SELECT * FROM wp_assessor_rpu WHERE objid = '$rpu_id'")->fetch();
echo "wp_assessor_rpu (by objid = '$rpu_id'):\n";
print_r($rpu_row);

// Also search by etracs_objid if exists
if (isset($rpu_row['etracs_objid'])) {
    echo "rpu_row etracs_objid: " . $rpu_row['etracs_objid'] . "\n";
}

// 2. wp_assessor_machrpu
$machrpu_row = $pdo->query("SELECT * FROM wp_assessor_machrpu WHERE objid = '$rpu_id'")->fetch();
echo "\nwp_assessor_machrpu (by objid = '$rpu_id'):\n";
print_r($machrpu_row);

// 3. wp_assessor_machdetail
$machdetail_rows = $pdo->query("SELECT * FROM wp_assessor_machdetail WHERE machrpuid = '$rpu_id'")->fetchAll();
echo "\nwp_assessor_machdetail (WHERE machrpuid = '$rpu_id'): count = " . count($machdetail_rows) . "\n";
print_r($machdetail_rows);

// Search wp_assessor_machdetail by the reported MD id
$md_id = 'MD16c1fd8f:1934c83b979:-766b';
$md_row = $pdo->query("SELECT * FROM wp_assessor_machdetail WHERE objid = '$md_id'")->fetch();
echo "\nwp_assessor_machdetail (WHERE objid = '$md_id'):\n";
print_r($md_row);

// 4. wp_assessor_machine_smv
$smv_rows = $pdo->query("SELECT * FROM wp_assessor_machine_smv WHERE parent_objid = '$rpu_id'")->fetchAll();
echo "\nwp_assessor_machine_smv (WHERE parent_objid = '$rpu_id'): count = " . count($smv_rows) . "\n";
print_r($smv_rows);

// 5. wp_assessor_machine
$mac_id = 'MAC-4fa5cd24:165f5950c9d:-7df4';
$mac_row = $pdo->query("SELECT * FROM wp_assessor_machine WHERE objid = '$mac_id'")->fetch();
echo "\nwp_assessor_machine (WHERE objid = '$mac_id'):\n";
print_r($mac_row);

// Search wp_assessor_machine by code GSWCA
$mac_by_code = $pdo->query("SELECT * FROM wp_assessor_machine WHERE code = 'GSWCA'")->fetch();
echo "\nwp_assessor_machine (WHERE code = 'GSWCA'):\n";
print_r($mac_by_code);
