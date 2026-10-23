<?php
$pdo = new PDO('mysql:host=localhost;port=3306;dbname=etracs254_kitaotao', 'root', '');

// 1. Check all tables with 'mach' or 'bldg' or 'land' or 'misc' or 'plant'
$stmt = $pdo->query("SHOW TABLES LIKE '%mach%'");
echo "Machinery tables in ETRACS:\n";
print_r($stmt->fetchAll(PDO::FETCH_COLUMN));

$stmt = $pdo->query("SHOW TABLES LIKE '%machine%'");
echo "\nMachine tables in ETRACS:\n";
print_r($stmt->fetchAll(PDO::FETCH_COLUMN));

// 2. Describe machine table
$stmt = $pdo->query("DESCRIBE machine");
echo "\nColumns in machine:\n";
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

// 3. Check machine table rows
$stmt = $pdo->query("SELECT * FROM machine LIMIT 5");
echo "\nSample rows in machine:\n";
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

// 4. Check land tables and description fields
$stmt = $pdo->query("SHOW TABLES LIKE '%land%'");
echo "\nLand tables in ETRACS:\n";
print_r($stmt->fetchAll(PDO::FETCH_COLUMN));

$stmt = $pdo->query("DESCRIBE landdetail");
echo "\nColumns in landdetail:\n";
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

$stmt = $pdo->query("DESCRIBE landrpu");
echo "\nColumns in landrpu:\n";
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

// 5. Check bldgrpu and bldgtype
$stmt = $pdo->query("DESCRIBE bldgrpu");
echo "\nColumns in bldgrpu:\n";
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

// 6. Check miscrpu and miscrpuitem
$stmt = $pdo->query("DESCRIBE miscrpu");
echo "\nColumns in miscrpu:\n";
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

$stmt = $pdo->query("DESCRIBE miscrpuitem");
echo "\nColumns in miscrpuitem:\n";
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

$stmt = $pdo->query("DESCRIBE miscitem");
echo "\nColumns in miscitem:\n";
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

// 7. Check planttree
$stmt = $pdo->query("SHOW TABLES LIKE '%plant%'");
echo "\nPlant tables in ETRACS:\n";
print_r($stmt->fetchAll(PDO::FETCH_COLUMN));

$stmt = $pdo->query("DESCRIBE planttreerpu");
echo "\nColumns in planttreerpu:\n";
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
