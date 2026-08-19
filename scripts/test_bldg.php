<?php
define('WP_USE_THEMES', false);
require_once('c:/xampp/htdocs/wp-load.php');
global $wpdb;
$table = $wpdb->prefix . 'assessor_bldgrpu';
$row = $wpdb->get_row("SELECT bldgclass, floorcount, bldgtype_objid FROM $table WHERE bldgclass != '' LIMIT 1", ARRAY_A);
print_r($row);
