<?php
$mysqli = new mysqli('localhost', 'root', '', 'assessor_local');
$res = $mysqli->query("DESCRIBE wp_assessor_faas");
while ($row = $res->fetch_assoc()) {
    if ($row['Field'] == 'txntimestamp' || $row['Field'] == 'cancelledtimestamp') {
        echo $row['Field'] . " : " . $row['Type'] . "\n";
    }
}
?>
