<?php
$host = 'localhost';
$user = 'root';
$pass = '';

$dsn = "mysql:host=$host;port=3306";
try {
    $pdo = new PDO($dsn, $user, $pass);
    $stmt = $pdo->query("SHOW DATABASES");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo $row['Database'] . "\n";
    }
} catch (PDOException $e) {}
