<?php
$mysqli = new mysqli('localhost', 'root', '', 'assessor_local');
$res = $mysqli->query("DELETE FROM wp_assessor_faas WHERE rpuid = '0' OR realpropertyid = '0'");
if ($mysqli->error) echo "Error: " . $mysqli->error . "\n";
else echo "Deleted " . $mysqli->affected_rows . " corrupted records.\n";
?>
