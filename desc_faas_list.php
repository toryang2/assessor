<?php
$db = new mysqli('localhost', 'root', '', 'etracs254_kitaotao');
if ($db->connect_error) die("Connection failed: " . $db->connect_error);

echo "faas_list:\n";
$res = $db->query('DESCRIBE faas_list');
if ($res) {
    while ($row = $res->fetch_assoc()) {
        echo $row['Field'] . " - " . $row['Type'] . "\n";
    }
}
