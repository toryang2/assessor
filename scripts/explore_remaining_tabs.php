<?php
/**
 * Explore Superseded FAAS and Restrictions tables in ETRACS
 */

$pdo = new PDO('mysql:host=localhost;port=3306;dbname=etracs254_kitaotao;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$allTables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

// ==========================================
// PART 1: Find Superseded FAAS tables
// ==========================================
echo "=== SUPERSEDED / PREVIOUS FAAS TABLES ===\n";
foreach ($allTables as $t) {
    if (stripos($t, 'supersed') !== false || stripos($t, 'previous') !== false || stripos($t, 'prev') !== false) {
        echo "  $t\n";
    }
}

// Check faas_previous
echo "\n--- faas_previous SCHEMA ---\n";
try {
    $stmt = $pdo->query("DESCRIBE faas_previous");
    while ($row = $stmt->fetch()) {
        echo "  " . str_pad($row['Field'], 35) . str_pad($row['Type'], 25) . ($row['Key'] ?: '') . "\n";
    }
    $count = $pdo->query("SELECT COUNT(*) FROM faas_previous")->fetchColumn();
    echo "  [Row count: $count]\n";
    
    echo "\n--- faas_previous SAMPLE (for building) ---\n";
    $stmt = $pdo->query("
        SELECT fp.* 
        FROM faas_previous fp 
        JOIN faas f ON fp.faasid = f.objid 
        JOIN rpu r ON f.rpuid = r.objid 
        WHERE r.rputype = 'bldg' 
        LIMIT 3
    ");
    foreach ($stmt->fetchAll() as $r) print_r($r);
} catch (Exception $e) { echo "  " . $e->getMessage() . "\n"; }

// Check prevfaas if exists
echo "\n--- prevfaas SCHEMA ---\n";
try {
    $stmt = $pdo->query("DESCRIBE prevfaas");
    while ($row = $stmt->fetch()) {
        echo "  " . str_pad($row['Field'], 35) . str_pad($row['Type'], 25) . ($row['Key'] ?: '') . "\n";
    }
} catch (Exception $e) { echo "  Not found\n"; }

// Try to find the actual superseded FAAS for the record in screenshot
// The screenshot shows: TD 10-024-07725, PIN 059-10-0024-001-(01)-1050, Owner LUCITA A. ESPINA
echo "\n--- Searching for TD 10-024-07725 (from screenshot superseded tab) ---\n";
$stmt = $pdo->query("SELECT objid, tdno, prevtdno, state, cancelledbytdnos FROM faas WHERE tdno LIKE '%07725%'");
foreach ($stmt->fetchAll() as $r) print_r($r);

// Also check if prevtdno links to it
echo "\n--- FAAS records with prevtdno containing 07725 ---\n";
$stmt = $pdo->query("SELECT objid, tdno, prevtdno, state FROM faas WHERE prevtdno LIKE '%07725%' LIMIT 5");
foreach ($stmt->fetchAll() as $r) print_r($r);

// ==========================================
// PART 2: Restrictions tables
// ==========================================
echo "\n\n=== RESTRICTION TABLES ===\n";
foreach ($allTables as $t) {
    if (stripos($t, 'restrict') !== false) {
        echo "  $t\n";
    }
}

// Check faas_restriction
echo "\n--- faas_restriction SCHEMA ---\n";
try {
    $stmt = $pdo->query("DESCRIBE faas_restriction");
    while ($row = $stmt->fetch()) {
        echo "  " . str_pad($row['Field'], 35) . str_pad($row['Type'], 25) . ($row['Key'] ?: '') . "\n";
    }
    $count = $pdo->query("SELECT COUNT(*) FROM faas_restriction")->fetchColumn();
    echo "  [Row count: $count]\n";
    
    echo "\nSample:\n";
    $stmt = $pdo->query("SELECT * FROM faas_restriction LIMIT 3");
    foreach ($stmt->fetchAll() as $r) print_r($r);
} catch (Exception $e) { echo "  " . $e->getMessage() . "\n"; }

// Check restriction lookup
echo "\n--- restriction SCHEMA ---\n";
try {
    $stmt = $pdo->query("DESCRIBE restriction");
    while ($row = $stmt->fetch()) {
        echo "  " . str_pad($row['Field'], 35) . str_pad($row['Type'], 25) . ($row['Key'] ?: '') . "\n";
    }
    echo "\nAll restrictions:\n";
    $stmt = $pdo->query("SELECT * FROM restriction");
    foreach ($stmt->fetchAll() as $r) print_r($r);
} catch (Exception $e) { echo "  Not found\n"; }

// ==========================================
// PART 3: Signatories - confirm with actual data  
// ==========================================
echo "\n\n=== FAAS_SIGNATORY SAMPLE (for building) ===\n";
$stmt = $pdo->query("
    SELECT fs.appraiser_name, fs.appraiser_title, fs.appraiser_dtsigned,
           fs.taxmapper_name, fs.taxmapper_title, fs.taxmapper_dtsigned,
           fs.recommender_name, fs.recommender_title, fs.recommender_dtsigned,
           fs.approver_name, fs.approver_title, fs.approver_dtsigned
    FROM faas_signatory fs
    JOIN faas f ON fs.objid = f.objid
    JOIN rpu r ON f.rpuid = r.objid
    WHERE r.rputype = 'bldg'
    LIMIT 2
");
foreach ($stmt->fetchAll() as $r) print_r($r);

// ==========================================
// PART 4: Memoranda template
// ==========================================
echo "\n\n=== MEMORANDA / TEMPLATE ===\n";
foreach ($allTables as $t) {
    if (stripos($t, 'template') !== false || stripos($t, 'memo') !== false) {
        echo "  $t\n";
    }
}

// Check faas_memoranda
echo "\n--- faas_memoranda SCHEMA ---\n";
try {
    $stmt = $pdo->query("DESCRIBE faas_memoranda");
    while ($row = $stmt->fetch()) {
        echo "  " . str_pad($row['Field'], 35) . str_pad($row['Type'], 25) . ($row['Key'] ?: '') . "\n";
    }
} catch (Exception $e) { echo "  Not found\n"; }

// Check rptparameter or sys_template
echo "\n--- Looking for template tables ---\n";
foreach ($allTables as $t) {
    if (stripos($t, 'template') !== false) {
        echo "  Found: $t\n";
        $stmt = $pdo->query("DESCRIBE `$t`");
        while ($row = $stmt->fetch()) {
            echo "    " . str_pad($row['Field'], 30) . $row['Type'] . "\n";
        }
    }
}

// Check faas.memoranda for building sample
echo "\n--- FAAS memoranda field samples ---\n";
$stmt = $pdo->query("
    SELECT f.tdno, f.memoranda 
    FROM faas f 
    JOIN rpu r ON f.rpuid = r.objid 
    WHERE r.rputype = 'bldg' AND f.memoranda IS NOT NULL AND f.memoranda != ''
    LIMIT 3
");
foreach ($stmt->fetchAll() as $r) {
    echo "TD: {$r['tdno']}\n";
    echo "Memo: " . substr($r['memoranda'], 0, 200) . "\n\n";
}

echo "\nDONE.\n";
?>
