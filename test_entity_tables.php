<?php
$pdo = new PDO("mysql:host=localhost;dbname=etracs254_kitaotao;charset=utf8mb4", "root", "");
$stmt = $pdo->query("SHOW TABLES LIKE '%entity%'");
echo "=== Entity Tables ===\n";
while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
    echo $row[0] . "\n";
}
?>
