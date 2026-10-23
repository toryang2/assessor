<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=assessor_local', 'root', '');
$res = $pdo->query("
    SELECT TABLE_NAME, TABLE_COLLATION 
    FROM INFORMATION_SCHEMA.TABLES 
    WHERE TABLE_SCHEMA = 'assessor_local' 
      AND TABLE_NAME IN ('wp_assessor_bldgrpu', 'wp_assessor_bldgtype', 'wp_assessor_bldgkindbucc', 'wp_assessor_bldgkind', 'wp_assessor_bldgrpu_structuraltype')
")->fetchAll(PDO::FETCH_KEY_PAIR);
print_r($res);
