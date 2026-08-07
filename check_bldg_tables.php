<?php
$pdo = new PDO('mysql:host=localhost;port=3306;dbname=etracs254_kitaotao;charset=utf8mb4', 'root', '');
$id = 'RPUfc7a625:1862a553a16:-b76';
$st = $pdo->query("SELECT * FROM bldgrpu_structuraltype WHERE bldgrpuid = '$id'")->fetchAll(PDO::FETCH_ASSOC);
$uses = $pdo->query("SELECT * FROM bldguse WHERE bldgrpuid = '$id'")->fetchAll(PDO::FETCH_ASSOC);
$structs = $pdo->query("SELECT * FROM bldgstructure WHERE bldgrpuid = '$id'")->fetchAll(PDO::FETCH_ASSOC);
print_r(['st' => $st, 'uses' => $uses, 'structs' => $structs]);
