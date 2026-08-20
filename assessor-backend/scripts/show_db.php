<?php
$pdo = new PDO('mysql:host=localhost;dbname=assessor-archiving-test', 'root', '');
$stmt = $pdo->query('DESCRIBE wp_assessor_requests');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
