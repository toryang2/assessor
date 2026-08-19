<?php
$pdo = new PDO('mysql:host=localhost;port=3306;dbname=etracs254_kitaotao;charset=utf8mb4', 'root', '');
$stmt = $pdo->query('SHOW TABLES');
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo "Tables with barangay:\n";
foreach($tables as $t) {
    if (strpos($t, 'barangay') !== false) {
        echo "$t\n";
    }
}

echo "\nTables with class:\n";
foreach($tables as $t) {
    if (strpos($t, 'class') !== false) {
        echo "$t\n";
    }
}

echo "\nDescribe barangay:\n";
$stmt = $pdo->query('DESCRIBE barangay');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "\nDescribe propertyclassification:\n";
$stmt = $pdo->query('DESCRIBE propertyclassification');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
?>
