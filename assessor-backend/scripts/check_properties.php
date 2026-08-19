<?php
require 'wp-load.php';
global $wpdb;
$res = $wpdb->get_results("SELECT id, status FROM wp_assessor_properties LIMIT 5", ARRAY_A);
print_r($res);
