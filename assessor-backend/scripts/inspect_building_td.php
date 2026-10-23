<?php
$pdo = new PDO('mysql:host=localhost;port=3306;dbname=etracs254_kitaotao', 'root', '');
$tdno = '22-010-0001-00007';
$faas = $pdo->query("SELECT * FROM faas WHERE tdno = '$tdno'")->fetch(PDO::FETCH_ASSOC);
echo "=== FAAS ===\n"; print_r($faas);
if ($faas) {
    $rpuid = $faas['rpuid'];
    $rpu = $pdo->query("SELECT * FROM rpu WHERE objid = '$rpuid'")->fetch(PDO::FETCH_ASSOC);
    echo "=== RPU ===\n"; print_r($rpu);
    $bldgrpu = $pdo->query("SELECT * FROM bldgrpu WHERE objid = '$rpuid'")->fetch(PDO::FETCH_ASSOC);
    echo "=== BLDGRPU ===\n"; print_r($bldgrpu);
    if (!empty($bldgrpu['bldgtype_objid'])) {
        $bt = $pdo->query("SELECT * FROM bldgtype WHERE objid = '{$bldgrpu['bldgtype_objid']}'")->fetch(PDO::FETCH_ASSOC);
        echo "=== BLDGTYPE ===\n"; print_r($bt);
    }
    if (!empty($bldgrpu['bldgkindbucc_objid'])) {
        $bu = $pdo->query("SELECT * FROM bldgkindbucc WHERE objid = '{$bldgrpu['bldgkindbucc_objid']}'")->fetch(PDO::FETCH_ASSOC);
        echo "=== BLDGKINDBUCC ===\n"; print_r($bu);
        if ($bu && !empty($bu['bldgkind_objid'])) {
            $bk = $pdo->query("SELECT * FROM bldgkind WHERE objid = '{$bu['bldgkind_objid']}'")->fetch(PDO::FETCH_ASSOC);
            echo "=== BLDGKIND ===\n"; print_r($bk);
        }
    }
    $st = $pdo->query("SELECT * FROM bldgrpu_structuraltype WHERE bldgrpuid = '$rpuid'")->fetchAll(PDO::FETCH_ASSOC);
    echo "=== BLDGRPU_STRUCTURALTYPE ===\n"; print_r($st);
    if (!empty($st)) {
        foreach ($st as $row) {
            if (!empty($row['bldgkindbucc_objid'])) {
                $buSt = $pdo->query("SELECT * FROM bldgkindbucc WHERE objid = '{$row['bldgkindbucc_objid']}'")->fetch(PDO::FETCH_ASSOC);
                echo "=== ST BLDGKINDBUCC ===\n"; print_r($buSt);
                if ($buSt && !empty($buSt['bldgkind_objid'])) {
                    $bkSt = $pdo->query("SELECT * FROM bldgkind WHERE objid = '{$buSt['bldgkind_objid']}'")->fetch(PDO::FETCH_ASSOC);
                    echo "=== ST BLDGKIND ===\n"; print_r($bkSt);
                }
            }
        }
    }
}
