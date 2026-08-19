<?php
require_once('wp-load.php');
global $wpdb;
$table_types = $wpdb->prefix . 'assessor_property_types';
$table_classes = $wpdb->prefix . 'assessor_general_classes';
$table_locations = $wpdb->prefix . 'assessor_locations';

echo "Types count: " . $wpdb->get_var("SELECT COUNT(*) FROM $table_types") . "\n";
echo "Classes count: " . $wpdb->get_var("SELECT COUNT(*) FROM $table_classes") . "\n";
echo "Locations count: " . $wpdb->get_var("SELECT COUNT(*) FROM $table_locations") . "\n";
