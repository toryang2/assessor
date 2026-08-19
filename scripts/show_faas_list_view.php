<?php
$db = new mysqli('localhost', 'root', '', 'etracs254_kitaotao');
if ($db->connect_error) die("Connection failed: " . $db->connect_error);

$res = $db->query("SHOW CREATE VIEW faas_list");
if ($res) {
    $row = $res->fetch_array();
    echo $row[1] . "\n";
} else {
    echo "Error: " . $db->error;
}
