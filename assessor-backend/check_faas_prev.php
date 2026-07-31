<?php
$pdo = new PDO('mysql:host=localhost;port=3306;dbname=etracs254_kitaotao', 'root', ''); 
$res = $pdo->query('DESCRIBE faas_previous'); 
while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
    echo $row['Field'] . "\n";
}
