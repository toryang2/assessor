<?php
define('WP_USE_THEMES', false);
require_once('c:/xampp/htdocs/wp-load.php');
global $wpdb;
$table = $wpdb->prefix . 'assessor_rpu';
$row = $wpdb->get_row("SELECT rpu_type, classification FROM $table WHERE rpu_type = 'BLDG' AND classification != '' LIMIT 1", ARRAY_A);
print_r($row);
