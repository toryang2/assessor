<?php
$pdo = new PDO("mysql:host=localhost;dbname=etracs254_kitaotao;charset=utf8mb4", "root", "");
$stmt = $pdo->prepare("SELECT * FROM faas_list WHERE tdno = ?");
$stmt->execute(['10-023-06669']);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if ($row) {
    echo "=== faas_list ===\n";
    print_r($row);
} else {
    echo "No record found in faas_list.\n";
}

$stmt2 = $pdo->prepare("SELECT * FROM faas WHERE tdno = ?");
$stmt2->execute(['10-023-06669']);
$row2 = $stmt2->fetch(PDO::FETCH_ASSOC);
if ($row2) {
    echo "=== faas ===\n";
    print_r($row2);
} else {
    echo "No record found in faas.\n";
}
?>
