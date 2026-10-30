<?php
$db = new mysqli('localhost', 'root', '', 'assessor_local');
if ($db->connect_error) die($db->connect_error);

$res = $db->query("SELECT DISTINCT realpropertyid, barangayid FROM wp_assessor_rpu LIMIT 10");
while ($r = $res->fetch_assoc()) {
    print_r($r);
}

$res = $db->query("SELECT objid, pin, barangay, barangayid FROM wp_assessor_real_property LIMIT 10");
while ($r = $res->fetch_assoc()) {
    print_r($r);
}
