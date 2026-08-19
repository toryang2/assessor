<?php
$pdo = new PDO("mysql:host=localhost;dbname=etracs254_kitaotao;charset=utf8mb4;port=3306", 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

$stmt = $pdo->query("SHOW TABLES LIKE 'faas%'");
echo "faas% tables:\n";
while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
    echo $row[0] . "\n";
}

$stmt2 = $pdo->query("SHOW TABLES LIKE '%prev%'");
echo "\n%prev% tables:\n";
while ($row = $stmt2->fetch(PDO::FETCH_NUM)) {
    echo $row[0] . "\n";
}
