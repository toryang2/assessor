<?php
define('WP_USE_THEMES', false);
require_once('c:/xampp/htdocs/wp-load.php');
global $wpdb;
$locations = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}assessor_locations");
print_r($locations);
