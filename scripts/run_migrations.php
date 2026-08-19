<?php
define('WP_USE_THEMES', false);
require_once('c:/xampp/htdocs/wp-load.php');
$db = new Assessor_Database();
$db->create_tables();
echo 'Migrations run successfully.';
