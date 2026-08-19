<?php
$mysqli = new mysqli('localhost', 'root', '', 'assessor_local');

$sql = "ALTER TABLE wp_assessor_entity
    ADD COLUMN first_name varchar(100) DEFAULT NULL,
    ADD COLUMN last_name varchar(100) DEFAULT NULL,
    ADD COLUMN middle_name varchar(500) DEFAULT NULL,
    ADD COLUMN birthdate date DEFAULT NULL,
    ADD COLUMN birthplace varchar(160) DEFAULT NULL,
    ADD COLUMN gender varchar(10) DEFAULT NULL,
    ADD COLUMN civil_status varchar(15) DEFAULT NULL,
    ADD COLUMN citizenship varchar(50) DEFAULT NULL,
    ADD COLUMN profession varchar(50) DEFAULT NULL,
    ADD COLUMN tin varchar(50) DEFAULT NULL,
    ADD COLUMN sss varchar(25) DEFAULT NULL,
    ADD COLUMN acr varchar(50) DEFAULT NULL,
    ADD COLUMN religion varchar(50) DEFAULT NULL,
    ADD COLUMN height varchar(10) DEFAULT NULL,
    ADD COLUMN weight varchar(10) DEFAULT NULL,
    ADD COLUMN date_registered datetime DEFAULT NULL,
    ADD COLUMN org_type varchar(25) DEFAULT NULL,
    ADD COLUMN nature_of_business varchar(50) DEFAULT NULL,
    ADD COLUMN place_registered varchar(100) DEFAULT NULL,
    ADD COLUMN admin_name varchar(100) DEFAULT NULL,
    ADD COLUMN admin_position varchar(50) DEFAULT NULL,
    ADD COLUMN admin_address varchar(255) DEFAULT NULL;";

$mysqli->query($sql);
if ($mysqli->error) {
    echo "Error: " . $mysqli->error . "\n";
} else {
    echo "Entity columns added successfully.\n";
}
?>
