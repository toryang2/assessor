<?php
$pdo = new PDO('mysql:host=localhost;dbname=assessor_local', 'root', '');
$res = $pdo->query('SELECT COUNT(*) FROM wp_assessor_rpu')->fetch();
print_r($res);
$res2 = $pdo->query('SELECT COUNT(*) FROM wp_assessor_real_property')->fetch();
print_r($res2);
