<?php
$pdo = new PDO('mysql:host=localhost;dbname=assessor_local', 'root', '');
$stmt = $pdo->query('DESCRIBE wp_assessor_faas_previous');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
