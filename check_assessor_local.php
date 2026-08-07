<?php
$pdo = new PDO('mysql:host=localhost;port=3306;dbname=assessor_local;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$stmt = $pdo->query("SHOW TABLES LIKE '%bldg%'");
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo "Building tables in assessor_local:\n";
print_r($tables);

$stmt = $pdo->query("SHOW TABLES LIKE '%structure%'");
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo "\nStructure tables:\n";
print_r($tables);

echo "\nDONE.\n";
?>
