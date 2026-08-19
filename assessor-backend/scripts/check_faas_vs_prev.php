<?php
$pdo = new PDO('mysql:host=localhost;port=3306;dbname=etracs254_kitaotao', 'root', '');
$stmt = $pdo->query("
    SELECT f.objid, f.prevowner as faas_prevowner, p.prevowner as p_prevowner
    FROM faas f
    LEFT JOIN faas_previous p ON f.objid = p.faasid
    WHERE p.prevowner IS NOT NULL AND p.prevowner != ''
    LIMIT 10
");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "faas: " . $row['faas_prevowner'] . "\n";
    echo "faas_previous: " . $row['p_prevowner'] . "\n\n";
}
