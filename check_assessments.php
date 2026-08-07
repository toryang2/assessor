<?php
require_once 'c:/xampp/htdocs/wp-load.php';
global $wpdb;
$cols = $wpdb->get_results("DESCRIBE {$wpdb->prefix}assessor_rpu_assessments");
print_r($cols);

$cols2 = $wpdb->get_results("DESCRIBE {$wpdb->prefix}assessor_rpu_assessment");
print_r($cols2);
