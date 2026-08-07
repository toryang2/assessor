<?php
define('WP_USE_THEMES', false);
require_once('c:/xampp/htdocs/wp-load.php');
global $wpdb;

$pdo = new PDO('mysql:host=localhost;port=3306;dbname=etracs254_kitaotao;charset=utf8mb4', 'root', '');

// 1. Structural Type
echo "Syncing bldgrpu_structuraltype...\n";
$stmt = $pdo->query("SELECT * FROM bldgrpu_structuraltype");
while ($row = $stmt->fetch()) {
    $wpdb->replace($wpdb->prefix . 'assessor_bldgrpu_structuraltype', [
        'objid'              => $row['objid'],
        'bldgrpuid'          => $row['bldgrpuid'],
        'bldgtype_objid'     => $row['bldgtype_objid'],
        'bldgkindbucc_objid' => $row['bldgkindbucc_objid'],
        'classification_objid'=> $row['classification_objid'] ?? null,
        'floorcount'         => intval($row['floorcount']),
        'basefloorarea'      => floatval($row['basefloorarea']),
        'totalfloorarea'     => floatval($row['totalfloorarea']),
        'basevalue'          => floatval($row['basevalue']),
        'unitvalue'          => floatval($row['unitvalue'])
    ]);
}

// 2. Building Structure
echo "Syncing bldgstructure...\n";
$stmt = $pdo->query("SELECT * FROM bldgstructure");
while ($row = $stmt->fetch()) {
    $wpdb->replace($wpdb->prefix . 'assessor_bldgstructure', [
        'objid'           => $row['objid'],
        'bldgrpuid'       => $row['bldgrpuid'],
        'structure_objid' => $row['structure_objid'],
        'material_objid'  => $row['material_objid'],
        'floor'           => intval($row['floor'])
    ]);
}

// 3. Building Use
echo "Syncing bldguse...\n";
$stmt = $pdo->query("SELECT * FROM bldguse");
while ($row = $stmt->fetch()) {
    $wpdb->replace($wpdb->prefix . 'assessor_bldguse', [
        'objid'                => $row['objid'],
        'bldgrpuid'            => $row['bldgrpuid'],
        'structuraltype_objid' => $row['structuraltype_objid'],
        'actualuse_objid'      => $row['actualuse_objid'],
        'basevalue'            => floatval($row['basevalue']),
        'area'                 => floatval($row['area']),
        'basemarketvalue'      => floatval($row['basemarketvalue']),
        'depreciationvalue'    => floatval($row['depreciationvalue']),
        'adjustment'           => floatval($row['adjustment']),
        'marketvalue'          => floatval($row['marketvalue']),
        'assesslevel'          => floatval($row['assesslevel']),
        'assessedvalue'        => floatval($row['assessedvalue']),
        'addlinfo'             => $row['addlinfo'],
        'adjfordepreciation'   => floatval($row['adjfordepreciation']),
        'taxable'              => intval($row['taxable'])
    ]);
}

echo "Done specific sync.\n";
