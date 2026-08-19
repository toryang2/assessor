<?php
$pdo = new PDO('mysql:host=localhost;port=3306;dbname=etracs254_kitaotao;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

echo "=== faas_restriction_type ===\n";
$stmt = $pdo->query("DESCRIBE faas_restriction_type");
while ($row = $stmt->fetch()) {
    echo "  " . str_pad($row['Field'], 30) . $row['Type'] . "\n";
}
$stmt = $pdo->query("SELECT * FROM faas_restriction_type LIMIT 10");
foreach ($stmt->fetchAll() as $r) print_r($r);

echo "\n=== previousfaas TABLE ===\n";
$stmt = $pdo->query("DESCRIBE previousfaas");
while ($row = $stmt->fetch()) {
    echo "  " . str_pad($row['Field'], 30) . $row['Type'] . "\n";
}
$count = $pdo->query("SELECT COUNT(*) FROM previousfaas")->fetchColumn();
echo "  [Row count: $count]\n";
echo "\nSample:\n";
$stmt = $pdo->query("SELECT * FROM previousfaas LIMIT 3");
foreach ($stmt->fetchAll() as $r) print_r($r);

echo "\n=== memoranda_template SAMPLE ===\n";
$stmt = $pdo->query("SELECT * FROM memoranda_template LIMIT 5");
foreach ($stmt->fetchAll() as $r) print_r($r);

echo "\n=== rptledger_restriction ===\n";
$stmt = $pdo->query("DESCRIBE rptledger_restriction");
while ($row = $stmt->fetch()) {
    echo "  " . str_pad($row['Field'], 30) . $row['Type'] . "\n";
}
$count = $pdo->query("SELECT COUNT(*) FROM rptledger_restriction")->fetchColumn();
echo "  [Row count: $count]\n";

echo "\nDONE.\n";
?>
