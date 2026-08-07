<?php
define('WP_USE_THEMES', false);
require_once('c:/xampp/htdocs/wp-load.php');
global $wpdb;

$rpuid = 'RPUfc7a625:1862a553a16:-b76';
$ass = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}assessor_rpu_assessment WHERE rpuid = '$rpuid'", ARRAY_A);
print_r(['assessments' => $ass]);
