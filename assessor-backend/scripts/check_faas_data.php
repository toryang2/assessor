<?php
$host = 'localhost';
$db   = 'assessor_local';
$user = 'root';
$pass = '';

$pdo = new PDO("mysql:host=$host;dbname=$db", $user, $pass);
$stmt = $pdo->query("SELECT tdno, prevpin, prevowner, actualuse FROM wp_assessor_faas_list WHERE prevpin IS NOT NULL AND prevpin != '' LIMIT 5");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Rows with prevpin: " . count($rows) . "\n";
print_r($rows);

$stmt2 = $pdo->query("SELECT tdno, actualuse FROM wp_assessor_faas_list WHERE actualuse IS NOT NULL AND actualuse != '' LIMIT 5");
$rows2 = $stmt2->fetchAll(PDO::FETCH_ASSOC);
echo "Rows with actualuse: " . count($rows2) . "\n";
print_r($rows2);
