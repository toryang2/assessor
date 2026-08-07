<?php
define('WP_USE_THEMES', false);
require_once('c:/xampp/htdocs/wp-load.php');
global $wpdb;

$tdno = '22-010-0024-00234';
$faas = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}assessor_faas WHERE tdno = '$tdno'");
if (!$faas) die('FAAS not found in WP');

$rpuid = $faas->rpuid;
echo "RPU ID in WP: $rpuid\n";

$bldgrpu = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}assessor_bldgrpu WHERE objid = '$rpuid'", ARRAY_A);
print_r(['bldgrpu' => $bldgrpu]);

$structType = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}assessor_bldgrpu_structuraltype WHERE bldgrpuid = '$rpuid'", ARRAY_A);
print_r(['structType' => $structType]);

$uses = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}assessor_bldguse WHERE bldgrpuid = '$rpuid'", ARRAY_A);
print_r(['uses' => $uses]);

$floors = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}assessor_bldgfloor WHERE bldgrpuid = '$rpuid'", ARRAY_A);
print_r(['floors' => $floors]);

$structs = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}assessor_bldgstructure WHERE bldgrpuid = '$rpuid'", ARRAY_A);
print_r(['structs' => $structs]);
