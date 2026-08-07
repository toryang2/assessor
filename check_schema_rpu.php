<?php
require_once 'c:/xampp/htdocs/wp-load.php';
global $wpdb;
$cols = $wpdb->get_results("DESCRIBE {$wpdb->prefix}assessor_rpu");
print_r($cols);
