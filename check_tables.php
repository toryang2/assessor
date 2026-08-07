<?php
define('WP_USE_THEMES', false);
require_once('c:/xampp/htdocs/wp-load.php');
global $wpdb;

print_r(['property_types' => $wpdb->get_results("SELECT * FROM {$wpdb->prefix}assessor_property_types LIMIT 2")]);
print_r(['general_classes' => $wpdb->get_results("SELECT * FROM {$wpdb->prefix}assessor_general_classes LIMIT 2")]);
print_r(['material' => $wpdb->get_results("SELECT * FROM {$wpdb->prefix}assessor_material LIMIT 2")]);

// Find the table that has the actual use ID AL-5961e074:1843c511a0c:724f
$tables = $wpdb->get_col("SHOW TABLES");
foreach ($tables as $t) {
    // just check if any column has it
    $cols = $wpdb->get_col("SHOW COLUMNS FROM $t");
    foreach($cols as $c) {
        $res = $wpdb->get_row("SELECT * FROM $t WHERE $c = 'AL-5961e074:1843c511a0c:724f' LIMIT 1");
        if ($res) echo "FOUND IN $t.$c\n";
    }
}
