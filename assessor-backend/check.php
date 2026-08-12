<?php
$pdo = new PDO('mysql:host=localhost;dbname=etracs254_kitaotao', 'root', '');
$stmt = $pdo->query("SELECT * FROM faas_previous WHERE prevtdno LIKE '%10-024-07725%'");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

$stmt = $pdo->query("SELECT faasid, prevtdno FROM faas_previous LIMIT 10");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
