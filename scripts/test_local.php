<?php 
require_once 'c:/xampp/htdocs/wp-load.php'; 
global $wpdb;
$stmt = $wpdb->get_results("SELECT * FROM wp_assessor_bldgrpu_structuraltype WHERE bldgrpuid = 'RPUfc7a625:1862a553a16:-b76'", ARRAY_A);
print_r("LOCAL BLDGRPU_STRUCTURALTYPE: "); print_r($stmt);

$stmt2 = $wpdb->get_results("SELECT * FROM wp_assessor_bldguse WHERE bldgrpuid = 'RPUfc7a625:1862a553a16:-b76'", ARRAY_A);
print_r("LOCAL BLDGUSE: "); print_r($stmt2);
?>
