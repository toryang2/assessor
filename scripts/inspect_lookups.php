<?php
$db = new mysqli('localhost', 'root', '', 'assessor_local');
if ($db->connect_error) die($db->connect_error);

function descTable($db, $table) {
    echo "=== $table ===\n";
    $res = $db->query("DESCRIBE $table");
    if (!$res) {
        echo "Error: " . $db->error . "\n";
        return;
    }
    while ($row = $res->fetch_assoc()) {
        echo sprintf("%-25s %-20s %-6s %-6s %s\n", $row['Field'], $row['Type'], $row['Null'], $row['Key'], $row['Default']);
    }
    echo "\n";
}

descTable($db, 'wp_assessor_properties');
descTable($db, 'wp_assessor_property_states');
descTable($db, 'wp_assessor_locations');
descTable($db, 'wp_assessor_barangay');
descTable($db, 'wp_assessor_general_classes');
descTable($db, 'wp_assessor_property_types');
