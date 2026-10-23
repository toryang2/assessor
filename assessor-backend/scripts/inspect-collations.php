<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=assessor_local', 'root', '');
$res = $pdo->query("SELECT TABLE_NAME, TABLE_COLLATION FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = 'assessor_local' AND TABLE_NAME LIKE 'wp_assessor_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
foreach ($res as $tbl => $coll) {
    echo "$tbl => $coll\n";
}
