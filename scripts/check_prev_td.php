<?php
$db = new mysqli('localhost', 'root', '', 'etracs254_kitaotao');
if ($db->connect_error) die("Connection failed: " . $db->connect_error);

$res = $db->query("SELECT tdno, pin, owner_name, administrator_name, totalareasqm, totalmv, totalav FROM faas_list WHERE tdno = '10-021-07268'");
if ($res) {
    $row = $res->fetch_assoc();
    print_r($row);
} else {
    echo "Error: " . $db->error;
}
