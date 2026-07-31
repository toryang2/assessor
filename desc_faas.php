<?php
require 'C:/xampp/htdocs/wp-load.php';
$pdo = new PDO("mysql:host=localhost;dbname=etracs254_kitaotao;charset=utf8mb4", "root", "");
$stmt = $pdo->query("DESCRIBE faas");
echo "=== faas Table Schema ===\n";
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo str_pad($row['Field'], 25) . " | " . $row['Type'] . "\n";
}
?>
