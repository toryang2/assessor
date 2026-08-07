<?php
$pdo = new PDO('mysql:host=localhost;port=3306;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$stmt = $pdo->query("SHOW DATABASES");
$dbs = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo "Databases:\n";
print_r($dbs);

echo "\nDONE.\n";
?>
