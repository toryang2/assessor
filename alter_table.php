<?php
define('WP_USE_THEMES', false);
$_SERVER['HTTP_HOST'] = 'localhost';
require_once('C:/xampp/htdocs/assessor/wp-load.php');

global $wpdb;
$table_settings = $wpdb->prefix . 'assessor_settings';

$queries = [
    "ALTER TABLE $table_settings ADD COLUMN etracs_db_host varchar(255) DEFAULT ''",
    "ALTER TABLE $table_settings ADD COLUMN etracs_db_port varchar(10) DEFAULT '3306'",
    "ALTER TABLE $table_settings ADD COLUMN etracs_db_user varchar(255) DEFAULT ''",
    "ALTER TABLE $table_settings ADD COLUMN etracs_db_password varchar(255) DEFAULT ''",
    "ALTER TABLE $table_settings ADD COLUMN etracs_db_name varchar(255) DEFAULT ''",
    "ALTER TABLE $table_settings ADD COLUMN etracs_last_sync datetime NULL"
];

foreach ($queries as $query) {
    $wpdb->query($query);
    echo "Executed: $query\n";
}
echo "Done\n";
?>
