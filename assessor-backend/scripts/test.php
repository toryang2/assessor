<?php
require_once 'wp-load.php';
global $wpdb;
$t = $wpdb->prefix . 'assessor_faas';
$cols = $wpdb->get_results("SHOW COLUMNS FROM $t");
print_r($cols);
