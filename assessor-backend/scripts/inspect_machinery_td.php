<?php
$pdo = new PDO('mysql:host=localhost;port=3306;dbname=etracs254_kitaotao', 'root', '');

$tdno = '22-010-0004-05935';
$stmt = $pdo->prepare("SELECT * FROM faas WHERE tdno = ?");
$stmt->execute([$tdno]);
$faas = $stmt->fetch(PDO::FETCH_ASSOC);

echo "=== FAAS ===\n";
print_r($faas);

if ($faas) {
    $rpuid = $faas['rpuid'];
    $stmtRpu = $pdo->prepare("SELECT * FROM rpu WHERE objid = ?");
    $stmtRpu->execute([$rpuid]);
    $rpu = $stmtRpu->fetch(PDO::FETCH_ASSOC);
    echo "\n=== RPU ===\n";
    print_r($rpu);

    // Check machrpu
    $stmtMach = $pdo->prepare("SELECT * FROM machrpu WHERE objid = ?");
    $stmtMach->execute([$rpuid]);
    $machrpu = $stmtMach->fetch(PDO::FETCH_ASSOC);
    echo "\n=== MACHRPU ===\n";
    print_r($machrpu);

    // Check machdetail
    $stmtMachDetail = $pdo->prepare("SELECT * FROM machdetail WHERE machrpuid = ?");
    $stmtMachDetail->execute([$rpuid]);
    $machdetails = $stmtMachDetail->fetchAll(PDO::FETCH_ASSOC);
    echo "\n=== MACHDETAIL ===\n";
    print_r($machdetails);

    // Check if machine table exists and query machine
    if (!empty($machdetails)) {
        foreach ($machdetails as $md) {
            if (!empty($md['machine_objid'])) {
                $stmtM = $pdo->prepare("SELECT * FROM machine WHERE objid = ?");
                $stmtM->execute([$md['machine_objid']]);
                echo "\n=== MACHINE (from machine_objid {$md['machine_objid']}) ===\n";
                print_r($stmtM->fetch(PDO::FETCH_ASSOC));
            }
        }
    }
}
