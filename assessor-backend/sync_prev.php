<?php
$pdo = new PDO('mysql:host=localhost;port=3306;dbname=etracs254_kitaotao', 'root', ''); 
$wp_pdo = new PDO('mysql:host=localhost;port=3306;dbname=assessor_local', 'root', ''); 

echo "Syncing faas_previous...\n";
$stmt = $pdo->query("SELECT faasid, prevpin, prevowner, prevadministrator, prevav, prevmv, prevareasqm, prevareaha FROM faas_previous");

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $upd = $wp_pdo->prepare("
        UPDATE wp_assessor_faas 
        SET prevpin = ?, prevowner = ?, prevadministrator = ?, prevav = ?, prevmv = ?, prevareasqm = ?, prevareaha = ?
        WHERE objid = ?
    ");
    $upd->execute([
        $row['prevpin'] ?? '',
        $row['prevowner'] ?? '',
        $row['prevadministrator'] ?? '',
        $row['prevav'] ?? '',
        $row['prevmv'] ?? '',
        $row['prevareasqm'] ?? '',
        $row['prevareaha'] ?? '',
        $row['faasid']
    ]);

}
echo "Done!\n";
