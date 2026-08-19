<?php
/**
 * Get ONLY the schemas of all bldg tables + the bldgrpu sample
 */

$pdo = new PDO('mysql:host=localhost;port=3306;dbname=etracs254_kitaotao;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$allTables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

// Find all bldg tables
$bldgTables = [];
foreach ($allTables as $t) {
    if (stripos($t, 'bldg') !== false) {
        $bldgTables[] = $t;
    }
}

echo "=== BUILDING TABLES AND THEIR COLUMNS ===\n\n";
foreach ($bldgTables as $table) {
    $count = $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
    echo "TABLE: $table  (rows: $count)\n";
    $stmt = $pdo->query("DESCRIBE `$table`");
    while ($row = $stmt->fetch()) {
        echo "  " . str_pad($row['Field'], 35) . str_pad($row['Type'], 20) . ($row['Key'] ?: '') . "\n";
    }
    echo "\n";
}

echo "\n=== BLDGRPU SAMPLE (1 row) ===\n";
$stmt = $pdo->query("SELECT * FROM bldgrpu LIMIT 1");
print_r($stmt->fetch());

echo "\n=== BLDGFLOOR SAMPLE ===\n";
try {
    $stmt = $pdo->query("SELECT * FROM bldgfloor LIMIT 2");
    foreach ($stmt->fetchAll() as $r) print_r($r);
} catch (Exception $e) { echo $e->getMessage() . "\n"; }

echo "\n=== BLDGFLOORADDITIONAL SAMPLE ===\n";
try {
    $stmt = $pdo->query("SELECT * FROM bldgflooradditional LIMIT 2");
    foreach ($stmt->fetchAll() as $r) print_r($r);
} catch (Exception $e) { echo $e->getMessage() . "\n"; }

echo "\n=== BLDGUSE SAMPLE ===\n";
try {
    $stmt = $pdo->query("SELECT * FROM bldguse LIMIT 2");
    foreach ($stmt->fetchAll() as $r) print_r($r);
} catch (Exception $e) { echo $e->getMessage() . "\n"; }

echo "\n=== BLDGLAND SAMPLE ===\n";
try {
    $stmt = $pdo->query("SELECT * FROM bldgland LIMIT 2");
    foreach ($stmt->fetchAll() as $r) print_r($r);
} catch (Exception $e) { echo $e->getMessage() . "\n"; }

echo "\n=== RPU_ASSESSMENT for a building ===\n";
$stmt = $pdo->query("SELECT ra.* FROM rpu_assessment ra JOIN rpu r ON ra.rpuid = r.objid WHERE r.rputype = 'bldg' LIMIT 2");
foreach ($stmt->fetchAll() as $r) print_r($r);

echo "\n=== FAAS_SIGNATORY SCHEMA ===\n";
try {
    $stmt = $pdo->query("DESCRIBE faas_signatory");
    while ($row = $stmt->fetch()) {
        echo "  " . str_pad($row['Field'], 35) . str_pad($row['Type'], 20) . ($row['Key'] ?: '') . "\n";
    }
} catch (Exception $e) { echo "  " . $e->getMessage() . "\n"; }

echo "\n=== FAAS_SUPERSEDEDFAAS (or superseded) ===\n";
foreach ($allTables as $t) {
    if (stripos($t, 'supersed') !== false) {
        echo "Found table: $t\n";
        $stmt = $pdo->query("DESCRIBE `$t`");
        while ($row = $stmt->fetch()) {
            echo "  " . str_pad($row['Field'], 35) . str_pad($row['Type'], 20) . ($row['Key'] ?: '') . "\n";
        }
    }
}

echo "\nDONE.\n";
?>
