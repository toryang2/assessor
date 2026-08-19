<?php
$_SERVER['HTTP_HOST'] = 'localhost';
require_once 'd:\CODE\assessor\assessor-backend\wp-load.php';

global $wpdb;
$table_name = $wpdb->prefix . 'assessor_settings';
$setting = $wpdb->get_var("SELECT header_municipality FROM $table_name LIMIT 1");
echo "Setting is: '$setting'\n";
