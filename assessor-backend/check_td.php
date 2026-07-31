<?php
$pdo = new PDO('mysql:host=localhost;port=3306;dbname=etracs254_kitaotao', 'root', '');

$stmt = $pdo->query("SELECT * FROM faas WHERE tdno = '10-023-06669'");
$faas = $stmt->fetch(PDO::FETCH_ASSOC);

echo "FAAS data:\n";
print_r($faas);

$stmt2 = $pdo->query("SELECT * FROM rpu_assessment WHERE rpuid = '{$faas['rpuid']}'");
$assess = $stmt2->fetchAll(PDO::FETCH_ASSOC);

echo "\nAssessment data:\n";
print_r($assess);

$stmt3 = $pdo->query("SELECT * FROM rpu WHERE objid = '{$faas['rpuid']}'");
$rpu = $stmt3->fetch(PDO::FETCH_ASSOC);

echo "\nRPU data:\n";
print_r($rpu);
