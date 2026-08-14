<?php
define('WP_USE_THEMES', false);
require_once('c:/xampp/htdocs/wp-load.php');
global $wpdb;

// Check cancelled states
$states = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}assessor_property_states WHERE state='CANCELLED' LIMIT 5");
print_r($states);
