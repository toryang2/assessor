<?php
require_once 'c:/xampp/htdocs/wp-load.php';

// Simulate get_faas
global $wpdb;
$t_faas = $wpdb->prefix . 'assessor_faas';
$t_rpu = $wpdb->prefix . 'assessor_rpu';
$t_rp = $wpdb->prefix . 'assessor_real_property';
$t_entity = $wpdb->prefix . 'assessor_entity';

$tdno = '22-010-0024-00234';
$faas_id = $wpdb->get_var($wpdb->prepare("SELECT objid FROM $t_faas WHERE tdno = %s", $tdno));
echo "FAAS ID: $faas_id\n\n";

// Test get_faas fields
$record = $wpdb->get_row($wpdb->prepare("
    SELECT
        f.prevtdno AS prev_tdno,
        f.prevpin AS prev_pin,
        f.prevowner AS prev_owner,
        f.prevav AS prev_assessed_value,
        f.prevmv AS prev_market_value,
        f.prevareasqm AS prev_area_sqm,
        f.prevadministrator AS prev_administrator,
        f.memoranda,
        f.fullpin,
        r.taxable,
        r.total_area_sqm,
        r.total_area_hectare,
        r.rpu_type
    FROM $t_faas f
    LEFT JOIN $t_rpu r ON f.rpuid = r.objid
    WHERE f.tdno = %s
", $tdno));

echo "=== FAAS Record Fields ===\n";
echo "prev_tdno: " . ($record->prev_tdno ?? 'NULL') . "\n";
echo "prev_pin: " . ($record->prev_pin ?? 'NULL') . "\n";
echo "prev_owner: " . ($record->prev_owner ?? 'NULL') . "\n";
echo "prev_assessed_value: " . ($record->prev_assessed_value ?? 'NULL') . "\n";
echo "prev_market_value: " . ($record->prev_market_value ?? 'NULL') . "\n";
echo "prev_area_sqm: " . ($record->prev_area_sqm ?? 'NULL') . "\n";
echo "prev_administrator: " . ($record->prev_administrator ?? 'NULL') . "\n";
echo "memoranda: " . substr($record->memoranda ?? 'NULL', 0, 80) . "\n";
echo "fullpin: " . ($record->fullpin ?? 'NULL') . "\n";
echo "taxable: " . ($record->taxable ?? 'NULL') . "\n";
echo "total_area_sqm: " . ($record->total_area_sqm ?? 'NULL') . "\n";
echo "rpu_type: " . ($record->rpu_type ?? 'NULL') . "\n";

// Test RPU detail
$rpu_id = $wpdb->get_var($wpdb->prepare("SELECT rpuid FROM $t_faas WHERE tdno = %s", $tdno));
echo "\n=== RPU Detail ===\n";
echo "RPU ID: $rpu_id\n";

$assessments = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}assessor_rpu_assessment WHERE rpuid = %s", $rpu_id));
echo "Assessments count: " . count($assessments) . "\n";
if ($assessments) {
    foreach ($assessments as $a) {
        echo "  classcode: $a->classcode, classname: $a->classname, areasqm: $a->areasqm, marketvalue: $a->marketvalue, actualuse: $a->actualuse, assesslevel: $a->assesslevel, assessedvalue: $a->assessedvalue\n";
    }
}

$subtype = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}assessor_bldgrpu WHERE objid = %s", $rpu_id));
if ($subtype) {
    echo "\n=== Building Subtype ===\n";
    echo "floorcount: $subtype->floorcount\n";
    echo "bldgtypename: $subtype->bldgtypename\n";
    echo "bldgclass: $subtype->bldgclass\n";
    echo "bldgage: $subtype->bldgage\n";
    echo "effectiveage: $subtype->effectiveage\n";
    echo "depreciation: $subtype->depreciation\n";
    echo "depreciationvalue: $subtype->depreciationvalue\n";
    echo "cdurating: $subtype->cdurating\n";
    echo "percentcompleted: $subtype->percentcompleted\n";
}
