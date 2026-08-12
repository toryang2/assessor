<?php
$pdo = new PDO('mysql:host=localhost;dbname=assessor_local', 'root', '');
$res = $pdo->query('SELECT * FROM wp_assessor_real_property LIMIT 1')->fetch(PDO::FETCH_ASSOC);
print_r($res);
