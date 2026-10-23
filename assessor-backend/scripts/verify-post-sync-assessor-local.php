<?php
/**
 * Post-Migration & Post-Sync Verification Script
 */
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}
if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

$pdo = new PDO("mysql:host=127.0.0.1;dbname=assessor_local;charset=utf8mb4", 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$check_db = $pdo->query("SELECT DATABASE()")->fetchColumn();
if ($check_db !== 'assessor_local') {
    die("FATAL: Target database is NOT assessor_local!\n");
}

echo "=== 1. Table Counts in assessor_local ===\n";
$tables = [
    'wp_assessor_machine',
    'wp_assessor_machdetail',
    'wp_assessor_planttree',
    'wp_assessor_planttreedetail',
    'wp_assessor_miscitem',
    'wp_assessor_landrpu',
    'wp_assessor_landdetail',
    'wp_assessor_bldgrpu',
    'wp_assessor_bldgkind',
];

foreach ($tables as $t) {
    $cnt = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    echo str_pad($t, 32) . ": " . number_format($cnt) . " rows\n";
}

echo "\n=== 2. Machinery Verification ===\n";
// TD: 22-010-0004-05935
$mach_faas = $pdo->query("SELECT objid, tdno, rpuid FROM wp_assessor_faas WHERE tdno = '22-010-0004-05935'")->fetch();
print_r($mach_faas);
if ($mach_faas) {
    $rpu_id = $mach_faas['rpuid'];
    $mach_rpu = $pdo->query("SELECT * FROM wp_assessor_machrpu WHERE objid = '$rpu_id'")->fetch();
    echo "machrpu found: " . ($mach_rpu ? "YES" : "NO") . "\n";
    $mach_details = $pdo->query("
        SELECT md.objid, md.machrpuid, md.machine_objid,
               m.code as machine_code, m.name as machine_name
        FROM wp_assessor_machdetail md
        LEFT JOIN wp_assessor_machine m ON md.machine_objid = m.objid
        WHERE md.machrpuid = '$rpu_id'
    ")->fetchAll();
    echo "mach_details count: " . count($mach_details) . "\n";
    print_r($mach_details);
}

echo "\n=== 3. Building Verification ===\n";
// TD: 22-010-0001-00007
$bldg_faas = $pdo->query("SELECT objid, tdno, rpuid FROM wp_assessor_faas WHERE tdno = '22-010-0001-00007'")->fetch();
print_r($bldg_faas);
if ($bldg_faas) {
    $rpu_id = $bldg_faas['rpuid'];
    $bldg_rpu = $pdo->query("
        SELECT br.objid, br.bldgtype_objid, br.bldgkindbucc_objid, br.floorcount,
               bk.name as bldgkind_name, bt.name as bldgtype_name
        FROM wp_assessor_bldgrpu br
        LEFT JOIN wp_assessor_bldgkindbucc bucc ON br.bldgkindbucc_objid = bucc.objid
        LEFT JOIN wp_assessor_bldgkind bk ON bucc.bldgkind_objid = bk.objid
        LEFT JOIN wp_assessor_bldgtype bt ON br.bldgtype_objid = bt.objid
        WHERE br.objid = '$rpu_id'
    ")->fetch();
    print_r($bldg_rpu);
}

echo "\n=== 4. Plant/Tree Verification ===\n";
$pt_sample = $pdo->query("
    SELECT ptd.objid, ptd.planttreerpuid, ptd.planttree_objid, ptd.origin_type,
           pt.code as pt_code, pt.name as pt_name
    FROM wp_assessor_planttreedetail ptd
    LEFT JOIN wp_assessor_planttree pt ON ptd.planttree_objid = pt.objid
    LIMIT 3
")->fetchAll();
print_r($pt_sample);

echo "\n=== 5. Miscellaneous Verification ===\n";
$misc_sample = $pdo->query("
    SELECT mri.objid, mri.miscrpuid, mri.miscitem_objid,
           mi.code as misc_code, mi.name as misc_name
    FROM wp_assessor_miscrpuitem mri
    LEFT JOIN wp_assessor_miscitem mi ON mri.miscitem_objid = mi.objid
    LIMIT 3
")->fetchAll();
print_r($misc_sample);

echo "\n=== 6. Land RPU 2-Row Difference Investigation ===\n";
$source_cnt = $pdo->query("SELECT COUNT(*) FROM etracs254_kitaotao.landrpu")->fetchColumn();
$shadow_cnt = $pdo->query("SELECT COUNT(*) FROM wp_assessor_landrpu")->fetchColumn();
echo "Source etracs254_kitaotao.landrpu count: $source_cnt\n";
echo "Shadow wp_assessor_landrpu count:       $shadow_cnt\n";

if ($source_cnt != $shadow_cnt) {
    $diff_rows = $pdo->query("
        SELECT s.objid, s.realpropertyid, s.landbmv
        FROM etracs254_kitaotao.landrpu s
        LEFT JOIN wp_assessor_landrpu l ON s.objid = l.objid
        WHERE l.objid IS NULL
    ")->fetchAll();
    echo "Missing rows in shadow:\n";
    print_r($diff_rows);
} else {
    echo "Source and Shadow landrpu counts MATCH EXACTLY!\n";
}
