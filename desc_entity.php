<?php
require 'C:/xampp/htdocs/wp-load.php';
global $wpdb;
echo "=== wp_assessor_entity ===\n";
print_r($wpdb->get_results('DESCRIBE ' . $wpdb->prefix . 'assessor_entity'));
?>
