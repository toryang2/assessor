<?php
$pdo = new PDO('mysql:host=localhost;port=3306;dbname=etracs254_kitaotao', 'root', '');

echo "=== LAND RPUS (Sample) ===\n";
$stmt = $pdo->query("SELECT r.objid, r.rputype, lr.* FROM rpu r JOIN landrpu lr ON r.objid = lr.objid LIMIT 3");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "=== LAND DETAILS (Sample) ===\n";
$stmt = $pdo->query("SELECT * FROM landdetail LIMIT 3");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "=== BUILDING RPUS (Sample) ===\n";
$stmt = $pdo->query("SELECT r.objid, r.rputype, br.* FROM rpu r JOIN bldgrpu br ON r.objid = br.objid LIMIT 3");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "=== BUILDING TYPES (Sample) ===\n";
$stmt = $pdo->query("SELECT * FROM bldgtype LIMIT 3");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "=== BUILDING KINDS (Sample) ===\n";
$stmt = $pdo->query("SELECT * FROM bldgkind LIMIT 3");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "=== MISC RPUS (Sample) ===\n";
$stmt = $pdo->query("SELECT r.objid, r.rputype, mr.* FROM rpu r JOIN miscrpu mr ON r.objid = mr.objid LIMIT 3");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "=== MISC RPU ITEMS (Sample) ===\n";
$stmt = $pdo->query("SELECT mri.*, mi.name as miscitem_name FROM miscrpuitem mri LEFT JOIN miscitem mi ON mri.miscitem_objid = mi.objid LIMIT 3");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "=== PLANT/TREE RPUS (Sample) ===\n";
$stmt = $pdo->query("SELECT r.objid, r.rputype, ptr.* FROM rpu r JOIN planttreerpu ptr ON r.objid = ptr.objid LIMIT 3");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

$stmt = $pdo->query("SELECT ptd.*, pt.name as planttree_name FROM planttreedetail ptd LEFT JOIN planttree pt ON ptd.planttree_objid = pt.objid LIMIT 3");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
