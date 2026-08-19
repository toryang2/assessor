<?php
define('WP_USE_THEMES', false);
require_once('c:/xampp/htdocs/wp-load.php');
global $wpdb;

echo "bldgrpu_structuraltype: " . $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}assessor_bldgrpu_structuraltype") . "\n";
echo "bldgstructure: " . $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}assessor_bldgstructure") . "\n";
echo "bldguse: " . $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}assessor_bldguse") . "\n";
echo "bldgfloor: " . $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}assessor_bldgfloor") . "\n";
