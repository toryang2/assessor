<?php
$pdo = new PDO('mysql:host=localhost;port=3306;dbname=etracs254_kitaotao', 'root', ''); 
$wp_pdo = new PDO('mysql:host=localhost;port=3306;dbname=assessor_local', 'root', ''); 

echo "Syncing faas_signatory...\n";
$stmt = $pdo->query("SELECT * FROM faas_signatory");

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $sig_json = json_encode($row);
    
    $upd = $wp_pdo->prepare("UPDATE wp_assessor_faas SET signatories = ? WHERE objid = ?");
    $upd->execute([$sig_json, $row['objid']]);

}
echo "Done!\n";
