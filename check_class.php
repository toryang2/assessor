<?php
define('WP_USE_THEMES', false);
require_once('c:/xampp/htdocs/wp-load.php');
global $wpdb;
$c = $wpdb->get_results("SELECT objid, name, state FROM {$wpdb->prefix}assessor_propertyclassification", ARRAY_A);
print_r($c);
