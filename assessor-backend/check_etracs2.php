<?php
$pdo = new PDO('mysql:host=localhost;dbname=etracs254_kitaotao', 'root', '');

// Let's get the specific FAAS record the user is complaining about (22-010-0024-00234)
$stmt = $pdo->query("SELECT * FROM faas WHERE tdno = '22-010-0024-00234'");
$faas = $stmt->fetch(PDO::FETCH_ASSOC);
print_r($faas);

if ($faas) {
    // Check faas_previous
    $stmt = $pdo->query("SELECT * FROM faas_previous WHERE faasid = '{$faas['objid']}'");
    $faas_prev = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "\n--- faas_previous ---\n";
    print_r($faas_prev);
    
    // Check faas_list
    $stmt = $pdo->query("SELECT * FROM faas_list WHERE objid = '{$faas['objid']}'");
    $faas_list = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "\n--- faas_list ---\n";
    print_r($faas_list);
}
