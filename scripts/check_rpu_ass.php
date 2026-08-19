<?php
$pdo = new PDO('mysql:host=localhost;port=3306;dbname=etracs254_kitaotao;charset=utf8mb4', 'root', '');
$id = 'RPUfc7a625:1862a553a16:-b76';
$ass = $pdo->query("SELECT * FROM rpu_assessment WHERE rpuid = '$id'")->fetchAll(PDO::FETCH_ASSOC);
print_r(['assessments' => $ass]);
