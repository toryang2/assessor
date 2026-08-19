<?php
$mysqli = new mysqli('localhost', 'root', '', 'production_database2');
$mysqli->query('ALTER TABLE wp_assessor_faas MODIFY txntimestamp varchar(50) DEFAULT NULL;');
$mysqli->query('ALTER TABLE wp_assessor_faas MODIFY cancelledtimestamp varchar(50) DEFAULT NULL;');
echo $mysqli->error;
echo 'Done';
?>
