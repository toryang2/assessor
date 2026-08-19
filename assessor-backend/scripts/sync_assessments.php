<?php
$host = 'localhost';
$port = '3306';
$user = 'root';
$pass = '';
$db   = 'etracs254_kitaotao';

$pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$wp_pdo = new PDO("mysql:host=localhost;port=3306;dbname=assessor_local;charset=utf8mb4", 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

echo "Syncing FAAS assessments...\n";
$assessment_map = [];
$stmt_assess = $pdo->query("
    SELECT rpuid, actualuse_objid as actual_use, classification_objid as classification,
           assesslevel as assessment_level, marketvalue as market_value,
           assessedvalue as assessed_value, areaha as area_ha, areasqm as area_sqm
    FROM rpu_assessment
");
while ($a_row = $stmt_assess->fetch()) {
    if (!isset($assessment_map[$a_row['rpuid']])) {
        $assessment_map[$a_row['rpuid']] = [];
    }
    $assessment_map[$a_row['rpuid']][] = $a_row;
}

$stmt = $pdo->query("SELECT objid, rpuid FROM faas");
while ($row = $stmt->fetch()) {
    $assessments = isset($assessment_map[$row['rpuid']]) ? json_encode($assessment_map[$row['rpuid']]) : null;
    if ($assessments) {
        $upd = $wp_pdo->prepare("UPDATE wp_assessor_faas SET assessments = ? WHERE objid = ?");
        $upd->execute([$assessments, $row['objid']]);
        
        $upd2 = $wp_pdo->prepare("UPDATE wp_assessor_faas_list SET assessments = ? WHERE objid = ?");
        $upd2->execute([$assessments, $row['objid']]);
    }
}

echo "Syncing Entity contacts...\n";
$stmt = $pdo->query("
    SELECT entityid, contact 
    FROM entitycontact 
    WHERE contacttype IN ('mobile', 'phone')
");
$contacts = [];
while ($row = $stmt->fetch()) {
    if (!isset($contacts[$row['entityid']])) {
        $contacts[$row['entityid']] = $row['contact'];
    }
}
foreach ($contacts as $entityid => $contact) {
    $upd = $wp_pdo->prepare("UPDATE wp_assessor_entity SET telephone_no = ? WHERE objid = ?");
    $upd->execute([$contact, $entityid]);
}

echo "Done!\n";
