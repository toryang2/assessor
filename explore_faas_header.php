<?php
/**
 * Check FAAS table columns that map to the Building form header fields
 */

$pdo = new PDO('mysql:host=localhost;port=3306;dbname=etracs254_kitaotao;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

echo "=== FAAS TABLE - Full Schema ===\n";
$stmt = $pdo->query("DESCRIBE faas");
while ($row = $stmt->fetch()) {
    echo "  " . str_pad($row['Field'], 35) . str_pad($row['Type'], 20) . ($row['Key'] ?: '') . "\n";
}

echo "\n=== SAMPLE BUILDING FAAS RECORD ===\n";
$stmt = $pdo->query("
    SELECT f.*, rp.pin, rp.cadastrallotno, rp.surveyno, rp.north, rp.south, rp.east, rp.west,
           rp.barangayid,
           e.name as owner_name, e.address_text as owner_address,
           r.rputype, r.ry, r.suffix, r.fullpin, r.classification_objid,
           r.totalareaha, r.totalareasqm, r.totalmv, r.totalav,
           b.bldgage, b.effectiveage, b.cdurating, b.depreciation, b.depreciationvalue,
           b.floorcount, b.percentcompleted, b.dtcompleted, b.dtoccupied,
           b.dtconstructed, b.permitno, b.permitdate, b.permitissuedby,
           b.bldgclass, b.condominium, b.condocerttitle, b.dtcertcompletion, b.dtcertoccupancy,
           b.occpermitno, b.additionalinfo, b.landrpuid
    FROM faas f
    JOIN rpu r ON f.rpuid = r.objid
    JOIN bldgrpu b ON b.objid = r.objid
    JOIN realproperty rp ON f.realpropertyid = rp.objid
    LEFT JOIN entity e ON f.taxpayer_objid = e.objid
    WHERE r.rputype = 'bldg' AND f.state = 'CURRENT'
    LIMIT 1
");
$row = $stmt->fetch();
if ($row) {
    print_r($row);
    
    $rpuid = $row['rpuid'];
    
    // Get the land connection via landrpuid
    echo "\n=== LAND RPU CONNECTED (via bldgrpu.landrpuid) ===\n";
    if ($row['landrpuid']) {
        $stmt = $pdo->prepare("
            SELECT f.tdno, f.state, rp.pin, e.name as owner_name
            FROM faas f
            JOIN rpu r ON f.rpuid = r.objid
            JOIN realproperty rp ON f.realpropertyid = rp.objid
            LEFT JOIN entity e ON f.taxpayer_objid = e.objid
            WHERE f.rpuid = ? AND f.state = 'CURRENT'
        ");
        $stmt->execute([$row['landrpuid']]);
        $landFaas = $stmt->fetch();
        if ($landFaas) {
            echo "Land FAAS connected:\n";
            print_r($landFaas);
        } else {
            echo "No current FAAS found for landrpuid: " . $row['landrpuid'] . "\n";
        }
    } else {
        echo "No landrpuid set.\n";
    }
}

// Check faas for taxability, exempt fields
echo "\n=== FAAS TAXABILITY FIELDS ===\n";
$stmt = $pdo->query("SELECT objid, taxable, exemptiontype_objid, effectivityyear, effectivityqtr, memoranda, restrictions, state FROM faas WHERE rpuid IN (SELECT objid FROM rpu WHERE rputype = 'bldg') LIMIT 2");
foreach ($stmt->fetchAll() as $r) print_r($r);

// Check FAAS previousfaas
echo "\n=== FAAS_PREVIOUSFAAS / FAAS PREVIOUS ===\n";
$allTables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
foreach ($allTables as $t) {
    if (stripos($t, 'previous') !== false || stripos($t, 'prev') !== false) {
        echo "  Found: $t\n";
    }
}

echo "\nDONE.\n";
?>
