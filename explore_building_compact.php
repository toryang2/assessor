<?php
/**
 * Get ONLY the table list and bldgrpu columns + sample
 */

$pdo = new PDO('mysql:host=localhost;port=3306;dbname=etracs254_kitaotao;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$allTables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

$bldgTables = [];
foreach ($allTables as $t) {
    if (stripos($t, 'bldg') !== false) {
        $bldgTables[] = $t;
    }
}

// Show only table names and column names (no types for brevity)
echo "=== BUILDING TABLES ===\n";
foreach ($bldgTables as $table) {
    $count = $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
    $cols = $pdo->query("DESCRIBE `$table`")->fetchAll(PDO::FETCH_COLUMN, 0);
    echo "\n$table (rows: $count)\n";
    echo "  Columns: " . implode(', ', $cols) . "\n";
}

// bldgrpu full schema
echo "\n\n=== BLDGRPU FULL SCHEMA ===\n";
$stmt = $pdo->query("DESCRIBE bldgrpu");
while ($row = $stmt->fetch()) {
    echo "  " . str_pad($row['Field'], 35) . str_pad($row['Type'], 20) . ($row['Key'] ?: '') . "\n";
}

// Check for bldg_land or land connection tables
echo "\n\n=== TABLES WITH 'LAND' IN NAME ===\n";
foreach ($allTables as $t) {
    if (stripos($t, 'land') !== false) {
        echo "  $t\n";
    }
}

// Check bldgrpu_land table
echo "\n=== BLDGRPU_LAND SCHEMA ===\n";
try {
    $stmt = $pdo->query("DESCRIBE bldgrpu_land");
    while ($row = $stmt->fetch()) {
        echo "  " . str_pad($row['Field'], 35) . str_pad($row['Type'], 20) . ($row['Key'] ?: '') . "\n";
    }
    echo "\nSample:\n";
    $stmt = $pdo->query("SELECT * FROM bldgrpu_land LIMIT 2");
    foreach ($stmt->fetchAll() as $r) print_r($r);
} catch (Exception $e) { echo "  " . $e->getMessage() . "\n"; }

// Check additional items table
echo "\n=== BLDG ADDITIONAL ITEMS ===\n";
foreach ($allTables as $t) {
    if (stripos($t, 'additional') !== false) {
        echo "  Found: $t\n";
        $cols = $pdo->query("DESCRIBE `$t`")->fetchAll(PDO::FETCH_COLUMN, 0);
        echo "  Columns: " . implode(', ', $cols) . "\n";
    }
}

echo "\n=== BLDGFLOOR SCHEMA ===\n";
$stmt = $pdo->query("DESCRIBE bldgfloor");
while ($row = $stmt->fetch()) {
    echo "  " . str_pad($row['Field'], 35) . str_pad($row['Type'], 20) . ($row['Key'] ?: '') . "\n";
}

echo "\n=== BLDGFLOORADDITIONAL SCHEMA ===\n";
$stmt = $pdo->query("DESCRIBE bldgflooradditional");
while ($row = $stmt->fetch()) {
    echo "  " . str_pad($row['Field'], 35) . str_pad($row['Type'], 20) . ($row['Key'] ?: '') . "\n";
}

echo "\nDONE.\n";
?>
