<?php
$pdo = new PDO("mysql:host=localhost;dbname=etracs254_kitaotao;charset=utf8mb4", "root", "");
$stmt = $pdo->query("DESCRIBE entityindividual");
echo "=== entityindividual ===\n";
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo str_pad($row['Field'], 25) . " | " . $row['Type'] . "\n";
}

$stmt2 = $pdo->query("DESCRIBE entityjuridical");
echo "=== entityjuridical ===\n";
while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
    echo str_pad($row['Field'], 25) . " | " . $row['Type'] . "\n";
}
?>
