<?php
define('WP_USE_THEMES', false);
require('c:/xampp/htdocs/wp-load.php');
global $wpdb;
$table = $wpdb->prefix . 'assessor_property_types';
$rows = $wpdb->get_results("SELECT * FROM $table", ARRAY_A);
print_r($rows);
