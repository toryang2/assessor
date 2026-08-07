<?php
$pdo = new PDO('mysql:host=localhost;dbname=etracs254_kitaotao;charset=utf8mb4', 'root', '');
$s = $pdo->query('DESCRIBE rpu');
while ($r = $s->fetch(PDO::FETCH_ASSOC)) {
    echo str_pad($r['Field'], 30) . $r['Type'] . "\n";
}
?>
