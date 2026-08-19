<?php
if (file_exists('C:/xampp/htdocs/wp-load.php')) {
    require_once 'C:/xampp/htdocs/wp-load.php';
} else {
    die("Cannot find wp-load.php\n");
}
global $wpdb;
$table = $wpdb->prefix . 'assessor_settings';
echo "Querying $table...\n";
$results = $wpdb->get_results("SELECT * FROM $table", ARRAY_A);
print_r($results);
