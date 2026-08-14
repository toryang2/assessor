<?php
define('WP_USE_THEMES', false);
require('c:/xampp/htdocs/wp-load.php');
global $wpdb;
$table_property_types = $wpdb->prefix . 'assessor_property_types';
$wpdb->show_errors();
$count = $wpdb->get_var("SELECT COUNT(*) FROM $table_property_types");
if ($wpdb->last_error) {
    echo "Error: " . $wpdb->last_error . "\n";
} else {
    echo "Count: " . $count . "\n";
}
