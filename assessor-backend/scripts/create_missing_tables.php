<?php
$mysqli = new mysqli('localhost', 'root', '', 'assessor_local');

$sql_rp = "CREATE TABLE IF NOT EXISTS wp_assessor_real_property (
    id mediumint(9) NOT NULL AUTO_INCREMENT,
    pin varchar(50) DEFAULT NULL,
    cadastral_lot_no varchar(100) DEFAULT NULL,
    survey_no varchar(100) DEFAULT NULL,
    block_no varchar(100) DEFAULT NULL,
    barangay varchar(100) DEFAULT NULL,
    municipality varchar(100) DEFAULT NULL,
    province varchar(100) DEFAULT NULL,
    north varchar(255) DEFAULT NULL,
    south varchar(255) DEFAULT NULL,
    east varchar(255) DEFAULT NULL,
    west varchar(255) DEFAULT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

$mysqli->query($sql_rp);
if ($mysqli->error) echo "Error RP: " . $mysqli->error . "\n";
else echo "RP table created.\n";

$sql_rpu = "CREATE TABLE IF NOT EXISTS wp_assessor_rpu (
    id mediumint(9) NOT NULL AUTO_INCREMENT,
    real_property_id mediumint(9) NOT NULL,
    rpu_type varchar(50) DEFAULT NULL,
    classification varchar(50) DEFAULT NULL,
    ry int DEFAULT 0,
    total_market_value float DEFAULT 0,
    total_assessed_value float DEFAULT 0,
    total_area_hectare float DEFAULT NULL,
    total_area_sqm float DEFAULT NULL,
    taxable tinyint(1) DEFAULT 1,
    PRIMARY KEY (id),
    KEY real_property_id (real_property_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

$mysqli->query($sql_rpu);
if ($mysqli->error) echo "Error RPU: " . $mysqli->error . "\n";
else echo "RPU table created.\n";
?>
