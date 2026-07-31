<?php
require 'C:/xampp/htdocs/assessor/wp-load.php';
global $wpdb;

$res = $wpdb->get_results('SHOW TABLES LIKE "%faas%"', ARRAY_N);
foreach ($res as $row) {
    echo $row[0] . "\n";
    print_r($wpdb->get_results("DESCRIBE " . $row[0]));
}
