<?php
require 'C:/xampp/htdocs/assessor/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once 'd:/CODE/assessor/assessor-backend/wp-content/plugins/assessor-api/includes/class-assessor-database.php';

echo "Triggering table creation...\n";
$db = new Assessor_Database();
$db->create_tables();

global $wpdb;
echo "Checking wp_assessor_faas_list schema:\n";
$res = $wpdb->get_results('DESCRIBE ' . $wpdb->prefix . 'assessor_faas_list', ARRAY_N);
foreach ($res as $row) {
    if (strpos($row[0], 'prev') === 0 || strpos($row[0], 'actualuse') === 0) {
        echo $row[0] . " - " . $row[1] . "\n";
    }
}
