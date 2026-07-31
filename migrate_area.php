<?php
require 'C:/xampp/htdocs/wp-load.php';
global $wpdb;

$sql1 = "ALTER TABLE {$wpdb->prefix}assessor_real_property DROP COLUMN total_area_hectare, DROP COLUMN total_area_sqm";
$wpdb->query($sql1);
echo "Dropped columns from wp_assessor_real_property.\n";

$sql2 = "ALTER TABLE {$wpdb->prefix}assessor_rpu ADD COLUMN total_area_hectare float DEFAULT NULL, ADD COLUMN total_area_sqm float DEFAULT NULL";
$wpdb->query($sql2);
echo "Added columns to wp_assessor_rpu.\n";
?>
