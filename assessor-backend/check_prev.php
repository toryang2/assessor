<?php
$pdo = new PDO('mysql:host=localhost;port=3306;dbname=etracs254_kitaotao', 'root', ''); 
$stmt = $pdo->query("SELECT * FROM faas_previous WHERE prevtdno = '10-021-07268'"); 
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
