<?php
require_once('C:/xampp/htdocs/wp-load.php');
require_once('C:/xampp/htdocs/wp-admin/includes/upgrade.php');
require_once('wp-content/plugins/assessor-api/includes/class-assessor-database.php');

$db = new Assessor_Database();
$db->create_tables();
echo "Tables migrated successfully.\n";
