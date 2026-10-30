<?php
$db = new mysqli('localhost', 'root', '', 'assessor_local');
if ($db->connect_error) die($db->connect_error);

echo "=== wp_assessor_barangay ===\n";
$res = $db->query("SELECT objid, indexno, pin, name, fullname FROM wp_assessor_barangay ORDER BY pin, name");
while ($r = $res->fetch_assoc()) {
    print_r($r);
}
