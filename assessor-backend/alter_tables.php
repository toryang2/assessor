<?php
require 'C:/xampp/htdocs/assessor/wp-load.php';
global $wpdb;

$tables = [
    $wpdb->prefix . 'assessor_faas',
    $wpdb->prefix . 'assessor_faas_list'
];

foreach ($tables as $t) {
    $cols = $wpdb->get_col("DESC $t", 0);
    if (!in_array('assessments', $cols)) {
        $wpdb->query("ALTER TABLE $t ADD COLUMN assessments LONGTEXT DEFAULT NULL");
        echo "Added assessments to $t\n";
    }
}

$entity_table = $wpdb->prefix . 'assessor_entity';
$cols = $wpdb->get_col("DESC $entity_table", 0);
if (!in_array('telephone_no', $cols)) {
    $wpdb->query("ALTER TABLE $entity_table ADD COLUMN telephone_no VARCHAR(100) DEFAULT NULL");
    echo "Added telephone_no to $entity_table\n";
}
