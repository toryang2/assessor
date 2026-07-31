<?php
$db = new mysqli('localhost', 'root', '', 'etracs254_kitaotao');
if ($db->connect_error) die("Connection failed: " . $db->connect_error);

$res = $db->query("SELECT * FROM faas_previous WHERE prevtdno = '10-021-07268'");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        print_r($row);
    }
} else {
    echo "Error: " . $db->error;
}
