<?php
$pdo = new PDO('mysql:host=localhost;dbname=assessor_local', 'root', '');
$res = $pdo->query("SELECT * FROM wp_assessor_real_property WHERE etracs_objid IS NOT NULL AND etracs_objid != '' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
print_r($res);
