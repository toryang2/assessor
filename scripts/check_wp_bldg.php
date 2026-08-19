<?php
define('WP_USE_THEMES', false);
require_once('c:/xampp/htdocs/wp-load.php');
global $wpdb;
$t_faas = $wpdb->prefix . 'assessor_faas';
$t_rpu = $wpdb->prefix . 'assessor_rpu';
$sql = "SELECT f.objid, r.objid as rpu_id, r.classification FROM $t_faas f JOIN $t_rpu r ON f.rpuid = r.objid WHERE r.rpu_type = 'BLDG' LIMIT 1";
$faas = $wpdb->get_row($sql);
if ($faas) {
    echo "Found FAAS: " . $faas->objid . "\n";
    $id = $faas->objid;
    $t_bldg = $wpdb->prefix . 'assessor_bldgrpu';
    $bldg = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_bldg WHERE objid = %s", $faas->rpu_id), ARRAY_A);
    if ($bldg) {
        $bldg['classification_objid'] = $faas->classification;
        print_r($bldg);
    } else {
        echo "No bldgrpu found for " . $faas->rpu_id;
    }
    
    $t_sig = $wpdb->prefix . 'assessor_faas_signatory';
    $sig = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_sig WHERE objid = %s", $id), ARRAY_A);
    print_r($sig);
} else {
    echo "No BLDG found";
}
