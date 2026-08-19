<?php
/**
 * Focused exploration of building-related tables in ETRACS
 * Outputs only schemas + sample data for each key table
 */

$pdo = new PDO('mysql:host=localhost;port=3306;dbname=etracs254_kitaotao;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Get all tables
$allTables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

// Find all bldg tables
echo "=== ALL BUILDING-RELATED TABLES ===\n";
$bldgTables = [];
foreach ($allTables as $t) {
    if (stripos($t, 'bldg') !== false) {
        $bldgTables[] = $t;
        echo "  $t\n";
    }
}

// Describe each building table
echo "\n\n=== SCHEMA FOR EACH BLDG TABLE ===\n";
foreach ($bldgTables as $table) {
    echo "\n--- $table ---\n";
    $stmt = $pdo->query("DESCRIBE `$table`");
    while ($row = $stmt->fetch()) {
        echo "  " . str_pad($row['Field'], 35) . " | " . str_pad($row['Type'], 25) . " | " . ($row['Key'] ?: '-') . "\n";
    }
    
    // Count rows
    $count = $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
    echo "  [Row count: $count]\n";
}

// Get a sample bldg RPU
echo "\n\n=== SAMPLE BLDGRPU WITH FULL DATA ===\n";
$stmt = $pdo->query("SELECT * FROM bldgrpu LIMIT 1");
$bldgrpu = $stmt->fetch();
if ($bldgrpu) {
    print_r($bldgrpu);
    $rpuid = $bldgrpu['objid'];
    
    // Get sub-tables
    echo "\n--- bldgrpu_structuraltype for this bldg ---\n";
    $stmt = $pdo->prepare("SELECT * FROM bldgrpu_structuraltype WHERE bldgrpuid = ?");
    $stmt->execute([$rpuid]);
    foreach ($stmt->fetchAll() as $r) print_r($r);
    
    echo "\n--- bldgfloor for this bldg ---\n";
    try {
        $stmt = $pdo->prepare("SELECT * FROM bldgfloor WHERE bldgrpuid = ? LIMIT 3");
        $stmt->execute([$rpuid]);
        foreach ($stmt->fetchAll() as $r) print_r($r);
    } catch (Exception $e) { echo "  Not found: " . $e->getMessage() . "\n"; }
    
    echo "\n--- bldgflooradditional for this bldg ---\n";
    try {
        $stmt = $pdo->prepare("SELECT * FROM bldgflooradditional WHERE bldgrpuid = ? LIMIT 3");
        $stmt->execute([$rpuid]);
        foreach ($stmt->fetchAll() as $r) print_r($r);
    } catch (Exception $e) { echo "  Not found: " . $e->getMessage() . "\n"; }

    echo "\n--- bldgstructure for this bldg ---\n";
    $stmt = $pdo->prepare("SELECT * FROM bldgstructure WHERE bldgrpuid = ? LIMIT 5");
    $stmt->execute([$rpuid]);
    foreach ($stmt->fetchAll() as $r) print_r($r);
    
    echo "\n--- bldguse for this bldg ---\n";
    try {
        $stmt = $pdo->prepare("SELECT * FROM bldguse WHERE bldgrpuid = ? LIMIT 3");
        $stmt->execute([$rpuid]);
        foreach ($stmt->fetchAll() as $r) print_r($r);
    } catch (Exception $e) { echo "  Not found: " . $e->getMessage() . "\n"; }

    echo "\n--- bldgland for this bldg ---\n";
    try {
        $stmt = $pdo->prepare("SELECT * FROM bldgland WHERE bldgrpuid = ? LIMIT 3");
        $stmt->execute([$rpuid]);
        foreach ($stmt->fetchAll() as $r) print_r($r);
    } catch (Exception $e) { echo "  Not found: " . $e->getMessage() . "\n"; }
}

// Also check the reference tables
echo "\n\n=== REFERENCE / LOOKUP TABLES ===\n";

echo "\n--- structure (list of structure types like TRUSS, ROOF, etc.) ---\n";
$stmt = $pdo->query("SELECT * FROM structure ORDER BY indexno LIMIT 20");
foreach ($stmt->fetchAll() as $r) print_r($r);

echo "\n--- structurematerial (link structure to material) ---\n";
$stmt = $pdo->query("SELECT sm.*, s.name as structure_name FROM structurematerial sm LEFT JOIN structure s ON sm.structure_objid = s.objid LIMIT 10");
foreach ($stmt->fetchAll() as $r) print_r($r);

echo "\n--- material (list of materials) ---\n";
try {
    $stmt = $pdo->query("SELECT * FROM material LIMIT 10");
    foreach ($stmt->fetchAll() as $r) print_r($r);
} catch (Exception $e) { echo "  Not found: " . $e->getMessage() . "\n"; }

echo "\n--- bldgtype (building type lookup) ---\n";
try {
    $stmt = $pdo->query("SELECT * FROM bldgtype LIMIT 5");
    foreach ($stmt->fetchAll() as $r) print_r($r);
} catch (Exception $e) { echo "  Not found: " . $e->getMessage() . "\n"; }

echo "\n--- bldgkind (building kind lookup) ---\n";
try {
    $stmt = $pdo->query("SELECT * FROM bldgkind LIMIT 5");
    foreach ($stmt->fetchAll() as $r) print_r($r);
} catch (Exception $e) { echo "  Not found: " . $e->getMessage() . "\n"; }

echo "\n--- bldgkindbucc (building kind BUCC) ---\n";
try {
    $stmt = $pdo->query("SELECT * FROM bldgkindbucc LIMIT 5");
    foreach ($stmt->fetchAll() as $r) print_r($r);
} catch (Exception $e) { echo "  Not found: " . $e->getMessage() . "\n"; }

echo "\n\nDONE.\n";
?>
