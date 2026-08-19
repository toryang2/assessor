<?php
$pdo = new PDO('mysql:host=localhost;port=3306;dbname=etracs254_kitaotao', 'root', ''); 
$stmt = $pdo->query('SELECT signatories FROM faas WHERE signatories IS NOT NULL LIMIT 1'); 
echo $stmt->fetchColumn();
