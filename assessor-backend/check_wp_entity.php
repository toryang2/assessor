<?php
$db = new mysqli('localhost', 'root', '', 'assessor_local');
if ($db->connect_error) {
    die("Connection failed to assessor_local: " . $db->connect_error);
}

$res = $db->query("SHOW TABLES LIKE '%entit%'");
while ($row = $res->fetch_array()) {
    $table = $row[0];
    echo "TABLE: $table\n";
    $res2 = $db->query("DESCRIBE $table");
    while ($r2 = $res2->fetch_assoc()) {
        echo '  ' . $r2['Field'] . ' - ' . $r2['Type'] . "\n";
    }
}
