<?php
require 'C:/xampp/htdocs/wp-load.php';
global $wpdb;
echo "--- RPU ---\n";
print_r($wpdb->get_results('DESCRIBE ' . $wpdb->prefix . 'assessor_rpu'));
echo "--- RP ---\n";
print_r($wpdb->get_results('DESCRIBE ' . $wpdb->prefix . 'assessor_real_property'));
?>
