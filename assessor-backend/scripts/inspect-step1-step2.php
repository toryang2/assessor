<?php
/**
 * Step 1 & 2: Inspect latest pull diagnostics and live shadow rows in assessor_local
 */
$pdo = new PDO('mysql:host=127.0.0.1;dbname=assessor_local', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

echo "=== Step 1: Latest Pull Diagnostics ===\n";
$stmt = $pdo->prepare("SELECT option_value FROM wp_options WHERE option_name = 'assessor_etracs_sync_diagnostics' LIMIT 1");
$stmt->execute();
$raw = $stmt->fetchColumn();
$diag = @unserialize($raw);
if ($diag === false) {
    echo "Raw diagnostic value: " . var_export($raw, true) . "\n";
} else {
    print_r($diag);
}

// Check sync progress option if any
$stmt_prog = $pdo->prepare("SELECT option_value FROM wp_options WHERE option_name = 'assessor_sync_progress' LIMIT 1");
$stmt_prog->execute();
$prog = $stmt_prog->fetchColumn();
echo "assessor_sync_progress: " . var_export($prog, true) . "\n";

echo "\n=== Table Counts ===\n";
$tables = [
    'wp_assessor_machine',
    'wp_assessor_machdetail',
    'wp_assessor_rpu',
    'wp_assessor_faas'
];
foreach ($tables as $t) {
    $c = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    echo "$t: $c rows\n";
}

echo "\n=== Step 2: Verify Exact Live Shadow Rows ===\n";
$faas_id = 'F-180b0697:19327f6fa4c:-6c84';
$rpu_id = 'RPU-180b0697:19327f6fa4c:-6c83';
$machdetail_id = 'MD16c1fd8f:1934c83b979:-766b';
$machine_id = 'MAC-4fa5cd24:165f5950c9d:-7df4';

$faas_row = $pdo->query("SELECT * FROM wp_assessor_faas WHERE objid = '$faas_id'")->fetch(PDO::FETCH_ASSOC);
echo "FAAS row ($faas_id):\n";
print_r($faas_row);

$rpu_row = $pdo->query("SELECT * FROM wp_assessor_rpu WHERE objid = '$rpu_id'")->fetch(PDO::FETCH_ASSOC);
echo "RPU row ($rpu_id):\n";
print_r($rpu_row);

$machdetail_row = $pdo->query("SELECT * FROM wp_assessor_machdetail WHERE objid = '$machdetail_id'")->fetch(PDO::FETCH_ASSOC);
echo "MACHDETAIL row ($machdetail_id):\n";
print_r($machdetail_row);

$machine_row = $pdo->query("SELECT * FROM wp_assessor_machine WHERE objid = '$machine_id'")->fetch(PDO::FETCH_ASSOC);
echo "MACHINE row ($machine_id):\n";
print_r($machine_row);

echo "\nRelationship Check:\n";
echo "machdetail->machrpuid == rpu_id: " . (($machdetail_row['machrpuid'] ?? '') === $rpu_id ? "YES" : "NO") . " (" . ($machdetail_row['machrpuid'] ?? 'null') . ")\n";
echo "machdetail->machine_objid == machine_id: " . (($machdetail_row['machine_objid'] ?? '') === $machine_id ? "YES" : "NO") . " (" . ($machdetail_row['machine_objid'] ?? 'null') . ")\n";
