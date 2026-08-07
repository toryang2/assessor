<?php
require_once 'c:/xampp/htdocs/wp-load.php';
global $wpdb;
$table = $wpdb->prefix . 'assessor_faas';
$count = $wpdb->get_var("SELECT COUNT(*) FROM $table");
echo "FAAS Count: " . $count . "\n";
