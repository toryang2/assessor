<?php
/**
 * Explore Building FAAS data structure in the ETRACS database
 * Maps the tabs seen in the original ETRACS Building FAAS form:
 *   General Information, Property Appraisal, Structural Materials, 
 *   Lands, Assessment, Signatories, Superseded FAAS, Memoranda, Restrictions
 */

$host = 'localhost';
$db   = 'etracs254_kitaotao';
$user = 'root';
$pass = '';
$port = '3306';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

$pdo = new PDO($dsn, $user, $pass, $options);

// =============================================
// PART 1: Find all building-related tables
// =============================================
echo "========================================\n";
echo "PART 1: ALL BUILDING-RELATED TABLES\n";
echo "========================================\n";

$stmt = $pdo->query('SHOW TABLES');
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

$bldgTables = [];
foreach ($tables as $t) {
    if (stripos($t, 'bldg') !== false || stripos($t, 'building') !== false) {
        $bldgTables[] = $t;
        echo "  $t\n";
    }
}

// Also check for structural/material/floor tables
echo "\n--- Other potentially related tables ---\n";
foreach ($tables as $t) {
    if (stripos($t, 'struct') !== false || stripos($t, 'material') !== false || 
        stripos($t, 'floor') !== false || stripos($t, 'additional') !== false ||
        stripos($t, 'sworn') !== false || stripos($t, 'memo') !== false ||
        stripos($t, 'restrict') !== false || stripos($t, 'supersed') !== false ||
        stripos($t, 'signator') !== false) {
        echo "  $t\n";
    }
}

// =============================================
// PART 2: Describe each building table
// =============================================
echo "\n========================================\n";
echo "PART 2: SCHEMA OF EACH BUILDING TABLE\n";
echo "========================================\n";

foreach ($bldgTables as $table) {
    echo "\n--- DESCRIBE $table ---\n";
    $stmt = $pdo->query("DESCRIBE `$table`");
    while ($row = $stmt->fetch()) {
        echo "  " . str_pad($row['Field'], 35) . " | " . str_pad($row['Type'], 25) . " | " . ($row['Key'] ?: '-') . "\n";
    }
}

// =============================================
// PART 3: Get a sample building FAAS record
// =============================================
echo "\n========================================\n";
echo "PART 3: SAMPLE BUILDING FAAS DATA\n";
echo "========================================\n";

// Find a building FAAS - use the one from screenshots: tdno = 22-010-0024-00234
$stmt = $pdo->prepare("SELECT f.objid, f.tdno, f.rpuid, f.realpropertyid, f.taxpayer_objid, f.state 
    FROM faas f 
    JOIN rpu r ON f.rpuid = r.objid 
    WHERE r.rputype = 'bldg' AND f.state = 'CURRENT' 
    LIMIT 1");
$stmt->execute();
$sample = $stmt->fetch();

if ($sample) {
    echo "\n--- Sample Building FAAS ---\n";
    print_r($sample);
    
    $rpuid = $sample['rpuid'];
    $faasid = $sample['objid'];
    
    // Get RPU details
    echo "\n--- RPU record for this building ---\n";
    $stmt = $pdo->prepare("SELECT * FROM rpu WHERE objid = ?");
    $stmt->execute([$rpuid]);
    print_r($stmt->fetch());
    
    // Check each building sub-table
    foreach ($bldgTables as $table) {
        echo "\n--- $table (linked to rpuid=$rpuid or faasid=$faasid) ---\n";
        
        // Try rpuid first
        $cols = $pdo->query("DESCRIBE `$table`")->fetchAll(PDO::FETCH_COLUMN, 0);
        
        $found = false;
        // Try various FK column names
        foreach (['bldgrpuid', 'rpuid', 'landrpuid', 'parentid', 'faasid', 'objid'] as $fk) {
            if (in_array($fk, $cols)) {
                $val = ($fk === 'faasid') ? $faasid : $rpuid;
                $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE `$fk` = ? LIMIT 5");
                $stmt->execute([$val]);
                $rows = $stmt->fetchAll();
                if ($rows) {
                    echo "  (found via $fk)\n";
                    foreach ($rows as $r) {
                        print_r($r);
                    }
                    $found = true;
                    break;
                }
            }
        }
        if (!$found) {
            echo "  (no matching rows found)\n";
        }
    }
} else {
    echo "No building FAAS found!\n";
}

// =============================================
// PART 4: Check RPU Assessment for building
// =============================================
echo "\n========================================\n";
echo "PART 4: RPU_ASSESSMENT FOR BUILDING\n";
echo "========================================\n";

if (isset($rpuid)) {
    $stmt = $pdo->prepare("SELECT * FROM rpu_assessment WHERE rpuid = ?");
    $stmt->execute([$rpuid]);
    $rows = $stmt->fetchAll();
    echo "Found " . count($rows) . " assessment rows:\n";
    foreach ($rows as $r) {
        print_r($r);
    }
}

// =============================================
// PART 5: Check FAAS signatories, memoranda, etc.
// =============================================
echo "\n========================================\n";
echo "PART 5: FAAS SUPPLEMENTARY TABLES\n";
echo "========================================\n";

$supplementaryTables = ['faas_signatory', 'faas_restriction', 'faas_memoranda', 
                        'faas_supersededfaas', 'faas_previous', 'faas_task',
                        'faas_txnlog', 'faas_list'];

foreach ($supplementaryTables as $table) {
    // Check if table exists
    try {
        $stmt = $pdo->query("DESCRIBE `$table`");
        echo "\n--- DESCRIBE $table ---\n";
        while ($row = $stmt->fetch()) {
            echo "  " . str_pad($row['Field'], 35) . " | " . str_pad($row['Type'], 25) . " | " . ($row['Key'] ?: '-') . "\n";
        }
        
        if (isset($faasid)) {
            // Try to get data
            $cols = $pdo->query("DESCRIBE `$table`")->fetchAll(PDO::FETCH_COLUMN, 0);
            foreach (['parentid', 'faasid', 'objid'] as $fk) {
                if (in_array($fk, $cols)) {
                    $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE `$fk` = ? LIMIT 3");
                    $stmt->execute([$faasid]);
                    $rows = $stmt->fetchAll();
                    if ($rows) {
                        echo "  Sample data (via $fk):\n";
                        foreach ($rows as $r) {
                            print_r($r);
                        }
                    }
                    break;
                }
            }
        }
    } catch (Exception $e) {
        echo "\n  Table $table does not exist\n";
    }
}

// =============================================
// PART 6: Check for Structural Materials tables
// =============================================
echo "\n========================================\n";
echo "PART 6: STRUCTURAL MATERIALS\n";
echo "========================================\n";

$structTables = [];
foreach ($tables as $t) {
    if (stripos($t, 'struct') !== false) {
        $structTables[] = $t;
    }
}

foreach ($structTables as $table) {
    echo "\n--- DESCRIBE $table ---\n";
    $stmt = $pdo->query("DESCRIBE `$table`");
    while ($row = $stmt->fetch()) {
        echo "  " . str_pad($row['Field'], 35) . " | " . str_pad($row['Type'], 25) . " | " . ($row['Key'] ?: '-') . "\n";
    }
    
    // Try to get sample data
    if (isset($rpuid)) {
        $cols = $pdo->query("DESCRIBE `$table`")->fetchAll(PDO::FETCH_COLUMN, 0);
        foreach (['bldgrpuid', 'rpuid', 'parentid', 'objid'] as $fk) {
            if (in_array($fk, $cols)) {
                $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE `$fk` = ? LIMIT 5");
                $stmt->execute([$rpuid]);
                $rows = $stmt->fetchAll();
                if ($rows) {
                    echo "  Sample data (via $fk):\n";
                    foreach ($rows as $r) {
                        print_r($r);
                    }
                }
                break;
            }
        }
    }
}

echo "\n\nDONE.\n";
?>
