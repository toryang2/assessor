<?php
$db = new mysqli('localhost', 'root', '', 'etracs254_kitaotao');

$tdno = '22-010-0001-00465';

echo "FAAS:\n";
$res = $db->query("SELECT * FROM faas_list WHERE tdno = '$tdno'");
if ($res) {
    $faas = $res->fetch_assoc();
    print_r($faas);

    $rpuid = $faas['rpuid'];
    
    echo "\n\nRPU:\n";
    $res2 = $db->query("SELECT * FROM rpu WHERE objid = '$rpuid'");
    print_r($res2->fetch_assoc());
    
    echo "\n\nRPU ASSESSMENT:\n";
    $res3 = $db->query("SELECT * FROM rpu_assessment WHERE rpuid = '$rpuid'");
    while ($row = $res3->fetch_assoc()) {
        print_r($row);
    }
}
