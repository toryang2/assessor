<?php
$db = new mysqli('localhost', 'root', '', 'etracs254_kitaotao');
if ($db->connect_error) die("Connection failed: " . $db->connect_error);

$res = $db->query("SHOW TABLES LIKE '%faas%'");
if ($res) {
    while ($row = $res->fetch_array()) {
        echo $row[0] . "\n";
    }
}
