<?php
$db = new mysqli('localhost', 'root', '', 'assessor_local');
if ($db->connect_error) {
    die("Connection failed to assessor_local: " . $db->connect_error);
}

$tables = [
    'wp_assessor_faas',
    'wp_assessor_faas_list'
];

foreach ($tables as $t) {
    $cols_res = $db->query("SHOW COLUMNS FROM $t");
    $cols = [];
    while ($r = $cols_res->fetch_assoc()) {
        $cols[] = $r['Field'];
    }
    
    if (!in_array('assessments', $cols)) {
        $db->query("ALTER TABLE $t ADD COLUMN assessments LONGTEXT DEFAULT NULL");
        echo "Added assessments to $t\n";
    }
}

$entity_table = 'wp_assessor_entity';
$cols_res = $db->query("SHOW COLUMNS FROM $entity_table");
$cols = [];
while ($r = $cols_res->fetch_assoc()) {
    $cols[] = $r['Field'];
}
if (!in_array('telephone_no', $cols)) {
    $db->query("ALTER TABLE $entity_table ADD COLUMN telephone_no VARCHAR(100) DEFAULT NULL");
    echo "Added telephone_no to $entity_table\n";
}
