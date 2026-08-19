<?php
$pdo = new PDO('mysql:host=localhost;dbname=etracs254_kitaotao', 'root', '');
$res = $pdo->query('SHOW COLUMNS FROM faas')->fetchAll(PDO::FETCH_ASSOC);
print_r($res);
