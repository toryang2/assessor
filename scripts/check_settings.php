<?php
$_SERVER['HTTP_HOST'] = 'localhost';
require_once 'd:\CODE\assessor\assessor-backend\wp-load.php';

global $wpdb;
$table = $wpdb->prefix . 'assessor_settings';
$row = $wpdb->get_row("SELECT * FROM $table LIMIT 1", ARRAY_A);
print_r($row);
