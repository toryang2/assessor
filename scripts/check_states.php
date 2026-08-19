<?php
define('WP_USE_THEMES', false);
require_once('c:/xampp/htdocs/wp-load.php');
global $wpdb;

// Check properties
$properties = $wpdb->get_results("SELECT id FROM {$wpdb->prefix}assessor_properties LIMIT 5");
print_r($properties);

// Check states
$states = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}assessor_property_states LIMIT 5");
print_r($states);
