<?php
$db = new mysqli('localhost', 'root', '', 'etracs254_kitaotao');
if ($db->connect_error) die("Connection failed: " . $db->connect_error);

$res = $db->query("SELECT f.tdno, f.owner_name, f.administrator_name, f.fullpin as pin FROM faas f WHERE f.tdno = '10-021-07268'");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        print_r($row);
    }
} else {
    echo "Error: " . $db->error;
}
