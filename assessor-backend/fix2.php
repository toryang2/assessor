<?php
$mysqli = new mysqli('localhost', 'root', '');
$res = $mysqli->query('SHOW DATABASES;');
while ($row = $res->fetch_row()) {
    echo $row[0] . "\n";
}
?>
