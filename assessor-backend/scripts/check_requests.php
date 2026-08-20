<?php
$pdo = new PDO('mysql:host=localhost;dbname=assessor-archiving-test', 'root', '');
$stmt = $pdo->query("SELECT id, created_at, verifier_signatory_name, municipal_assessor_name FROM wp_assessor_requests ORDER BY id DESC LIMIT 5");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
