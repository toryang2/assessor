<?php
define('WP_USE_THEMES', false);
require_once('c:/xampp/htdocs/wp-load.php');
global $wpdb;
$assessments = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}assessor_rpu_assessment WHERE rpuid = 'RPUfc7a625:1862a553a16:-b76'");
print_r(['assessments' => $assessments]);
