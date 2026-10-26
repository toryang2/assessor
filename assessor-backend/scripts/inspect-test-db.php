<?php
/**
 * Inspect 'test' database tables and compare with 'assessor_local'
 */
$pdo_test = new PDO('mysql:host=127.0.0.1;dbname=test', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

echo "=== Tables in 'test' DB (The running XAMPP WordPress database!) ===\n";
$tables_to_check = [
    'wp_assessor_rpu',
    'wp_assessor_machrpu',
    'wp_assessor_machdetail',
    'wp_assessor_machine_smv',
    'wp_assessor_machine',
    'wp_assessor_faas'
];

foreach ($tables_to_check as $tbl) {
    $exists = $pdo_test->query("SHOW TABLES LIKE '$tbl'")->fetchColumn();
    echo "Table $tbl: " . ($exists ? "EXISTS" : "DOES NOT EXIST");
    if ($exists) {
        $count = $pdo_test->query("SELECT COUNT(*) FROM `$tbl`")->fetchColumn();
        echo " ($count rows)\n";
    } else {
        echo "\n";
    }
}

$rpu_id = 'RPU-180b0697:19327f6fa4c:-6c83';
$td_no = '22-010-0004-05935';

echo "\n=== Query target TD in 'test' DB ===\n";
$faas_test = $pdo_test->query("SELECT objid, tdno, rpuid FROM wp_assessor_faas WHERE tdno = '$td_no'")->fetch(PDO::FETCH_ASSOC);
print_r($faas_test);

echo "\n=== Query target RPU in 'test' DB ===\n";
$rpu_test = $pdo_test->query("SELECT * FROM wp_assessor_rpu WHERE objid = '$rpu_id'")->fetch(PDO::FETCH_ASSOC);
print_r($rpu_test);

echo "\n=== Query machrpu in 'test' DB ===\n";
$machrpu_test = $pdo_test->query("SELECT * FROM wp_assessor_machrpu WHERE objid = '$rpu_id'")->fetch(PDO::FETCH_ASSOC);
print_r($machrpu_test);

echo "\n=== Query machdetail in 'test' DB ===\n";
$md_test = $pdo_test->query("SELECT * FROM wp_assessor_machdetail WHERE machrpuid = '$rpu_id'")->fetchAll(PDO::FETCH_ASSOC);
echo "Count machdetail with machrpuid = '$rpu_id': " . count($md_test) . "\n";
print_r($md_test);

$md_all = $pdo_test->query("SELECT COUNT(*) FROM wp_assessor_machdetail")->fetchColumn();
echo "Total rows in wp_assessor_machdetail in 'test' DB: $md_all\n";

$mac_all = $pdo_test->query("SELECT COUNT(*) FROM wp_assessor_machine")->fetchColumn();
echo "Total rows in wp_assessor_machine in 'test' DB: $mac_all\n";
