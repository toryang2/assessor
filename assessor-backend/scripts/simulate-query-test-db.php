<?php
// Simulate running WordPress environment on the database configured in C:/xampp/htdocs/wp-config.php ('test')
$pdo_test = new PDO('mysql:host=127.0.0.1;dbname=test', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]);

$id = 'RPU-180b0697:19327f6fa4c:-6c83';
$sql = "
    SELECT md.*,
           COALESCE(m.name, md.brand, md.model) as machinename,
           m.code as machinecode,
           m.name as machine_name
    FROM wp_assessor_machdetail md
    LEFT JOIN wp_assessor_machine m ON md.machine_objid = m.objid
    WHERE md.machrpuid = '$id'
";

$stmt = $pdo_test->query($sql);
if (!$stmt) {
    echo "Query failed in 'test' database!\n";
    echo "PDO ErrorInfo: " . print_r($pdo_test->errorInfo(), true) . "\n";
} else {
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Query succeeded with " . count($rows) . " rows.\n";
}
