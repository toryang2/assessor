<?php
$mysqli = new mysqli('localhost', 'root', '', 'assessor');
if ($mysqli->connect_error) { die('Connect Error'); }
$queries = [
    "ALTER TABLE wp_assessor_settings ADD COLUMN etracs_db_host varchar(255) DEFAULT ''",
    "ALTER TABLE wp_assessor_settings ADD COLUMN etracs_db_port varchar(10) DEFAULT '3306'",
    "ALTER TABLE wp_assessor_settings ADD COLUMN etracs_db_user varchar(255) DEFAULT ''",
    "ALTER TABLE wp_assessor_settings ADD COLUMN etracs_db_password varchar(255) DEFAULT ''",
    "ALTER TABLE wp_assessor_settings ADD COLUMN etracs_db_name varchar(255) DEFAULT ''",
    "ALTER TABLE wp_assessor_settings ADD COLUMN etracs_last_sync datetime NULL"
];
foreach ($queries as $q) {
    $mysqli->query($q);
    echo $mysqli->error . "\n";
}
echo 'Done';
?>
