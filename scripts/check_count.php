<?php
$db = new mysqli('localhost', 'root', '', 'assessor_local');
if ($db->connect_error) die("Connection failed: " . $db->connect_error);
$res = $db->query('SELECT COUNT(*) FROM wp_assessor_faas');
if ($res) {
    $row = $res->fetch_array();
    echo $row[0] . "\n";
}
