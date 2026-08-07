<?php
require_once 'c:/xampp/htdocs/wp-load.php';
global $wpdb;
$res = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}assessor_rpu_assessment WHERE rpuid = 'RPUff9a58:173c1174121:-4956'");
print_r($res);
