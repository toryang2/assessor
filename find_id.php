<?php
define('WP_USE_THEMES', false);
require_once('c:/xampp/htdocs/wp-load.php');
global $wpdb;
$tables = $wpdb->get_col("SHOW TABLES");
foreach ($tables as $table) {
    // get columns
    $cols = $wpdb->get_col("SHOW COLUMNS FROM $table");
    foreach ($cols as $col) {
        $res = $wpdb->get_row("SELECT * FROM $table WHERE $col = 'AL-5961e074:1843c511a0c:724f' LIMIT 1");
        if ($res) {
            echo "Found in $table.$col\n";
        }
    }
}
