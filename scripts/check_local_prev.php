<?php
$db = new mysqli('localhost', 'root', '', 'assessor_local');
if ($db->connect_error) die("Connection failed: " . $db->connect_error);
$res = $db->query("SELECT prevpin, prevowner, prevav FROM wp_assessor_faas WHERE prevtdno='10-021-07268'");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        print_r($row);
    }
}
