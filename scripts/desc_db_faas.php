<?php
$db = new mysqli('localhost', 'root', '', 'assessor_local');
if ($db->connect_error) die("Connection failed: " . $db->connect_error);

echo "wp_assessor_faas:\n";
$res = $db->query('DESCRIBE wp_assessor_faas');
if ($res) {
    while ($row = $res->fetch_assoc()) {
        echo $row['Field'] . " - " . $row['Type'] . "\n";
    }
} else {
    echo "wp_assessor_faas not found\n";
}
