<?php
define('WP_USE_THEMES', false);
require_once('c:/xampp/htdocs/wp-load.php');
require_once('c:/xampp/htdocs/wp-content/plugins/assessor-api/includes/class-assessor-database.php');

// Enable WordPress database error printing for dbDelta debugging
global $wpdb;
$wpdb->show_errors();

$db = new Assessor_Database();
$db->create_tables();

$tables = $wpdb->get_col("SHOW TABLES LIKE 'wp_assessor%'");
foreach($tables as $t) {
    echo "$t\n";
}
