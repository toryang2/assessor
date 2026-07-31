<?php
$db = new mysqli('localhost', 'root', '', 'etracs254_kitaotao');
if ($db->connect_error) die("Connection failed: " . $db->connect_error);

$res = $db->query("SELECT tdno, prevtdno, prevpin, prevowner, prevadministrator, prevmv, prevav, prevareasqm FROM faas WHERE prevtdno LIKE '%10-021-07268%' LIMIT 1");
if ($res) {
    $row = $res->fetch_assoc();
    print_r($row);
} else {
    echo "Error: " . $db->error;
}
