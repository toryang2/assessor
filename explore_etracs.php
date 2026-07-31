<?php

$host = 'localhost';
$db   = 'etracs254_kitaotao';
$user = 'root';
$pass = '';
$port = '3306';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

$pdo = new PDO($dsn, $user, $pass, $options);

echo "\n=== 1 Sample Row from faas_list (valid) ===\n";
$stmt = $pdo->query("SELECT * FROM faas_list WHERE tdno IS NOT NULL AND tdno != '' LIMIT 1");
$faas_list_row = $stmt->fetch();
print_r($faas_list_row);

echo "\n=== rpu Table Schema ===\n";
$stmt = $pdo->query("DESCRIBE rpu");
while ($row = $stmt->fetch()) {
    echo str_pad($row['Field'], 25) . " | " . $row['Type'] . "\n";
}

echo "\n=== realproperty Table Schema ===\n";
$stmt = $pdo->query("DESCRIBE realproperty");
while ($row = $stmt->fetch()) {
    echo str_pad($row['Field'], 25) . " | " . $row['Type'] . "\n";
}

echo "\n=== entity Table Schema ===\n";
$stmt = $pdo->query("DESCRIBE entity");
while ($row = $stmt->fetch()) {
    echo str_pad($row['Field'], 25) . " | " . $row['Type'] . "\n";
}

?>
