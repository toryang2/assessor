<?php
$pdo = new PDO('mysql:host=localhost;dbname=etracs254_kitaotao', 'root', '');
$stmt = $pdo->query("SHOW TABLES LIKE 'faas%'");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

// Check if faas_list is a view or table, and describe it
$stmt = $pdo->query("DESCRIBE faas_list");
if ($stmt) {
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
}
