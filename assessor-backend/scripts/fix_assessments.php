<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('memory_limit', '-1');

require_once 'C:\xampp\htdocs\wp-load.php';
global $wpdb;

$pdo = new PDO('mysql:host=localhost;port=3306;dbname=etracs254_kitaotao', 'root', '');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "Fetching ETRACS areatypes...\n";
$areatypes = [];
$stmt = $pdo->query("SELECT landrpuid, MAX(areatype) as areatype FROM landdetail GROUP BY landrpuid");
while ($row = $stmt->fetch()) {
    $areatypes[$row['landrpuid']] = $row['areatype'];
}

echo "Fetching wp_assessor_rpu mapping...\n";
$rpus = $wpdb->get_results("SELECT id, etracs_objid FROM {$wpdb->prefix}assessor_rpu");
$rpu_map = [];
foreach ($rpus as $r) {
    $rpu_map[$r->id] = $r->etracs_objid;
}

echo "Updating wp_assessor_faas assessments...\n";
$faas_list = $wpdb->get_results("SELECT objid, rpuid, assessments FROM {$wpdb->prefix}assessor_faas WHERE assessments IS NOT NULL AND assessments != '[]'");
$updated = 0;
foreach ($faas_list as $f) {
    $etracs_rpuid = $rpu_map[$f->rpuid] ?? null;
    if (!$etracs_rpuid) continue;

    $assessments = json_decode($f->assessments, true);
    if (!is_array($assessments)) continue;
    
    $changed = false;
    foreach ($assessments as &$a) {
        $type = $areatypes[$etracs_rpuid] ?? 'SQM';
        if (!isset($a['areatype']) || $a['areatype'] !== $type) {
            $a['areatype'] = $type;
            $changed = true;
        }
    }
    
    if ($changed) {
        $wpdb->update(
            "{$wpdb->prefix}assessor_faas",
            ['assessments' => wp_json_encode($assessments)],
            ['objid' => $f->objid]
        );
        $updated++;
    }
}
echo "Done! Updated $updated FAAS records with areatype.\n";
