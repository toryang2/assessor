<?php
$mysqli = new mysqli('localhost', 'root', '');

$dbs = ['assessor_local'];

foreach ($dbs as $db) {
    if ($mysqli->select_db($db)) {
        echo "Updating $db...\n";
        $mysqli->query("ALTER TABLE wp_assessor_faas ADD COLUMN publicland int DEFAULT NULL;");
        if ($mysqli->error) echo "  publicland error: " . $mysqli->error . "\n";
    }
}
echo "Done\n";
?>
