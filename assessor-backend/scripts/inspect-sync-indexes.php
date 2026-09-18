<?php
require_once 'C:/xampp/htdocs/wp-load.php';
global $wpdb;

$q_table = $wpdb->prefix . 'assessor_sync_queue';
$m_table = $wpdb->prefix . 'assessor_sync_meta';

echo "=== QUEUE KEYS ===\n";
$q_keys = $wpdb->get_results("SHOW INDEX FROM $q_table", ARRAY_A);
foreach ($q_keys as $k) {
    echo "{$k['Key_name']} | {$k['Column_name']} | Non_unique: {$k['Non_unique']}\n";
}

echo "\n=== META KEYS ===\n";
$m_keys = $wpdb->get_results("SHOW INDEX FROM $m_table", ARRAY_A);
foreach ($m_keys as $k) {
    echo "{$k['Key_name']} | {$k['Column_name']} | Non_unique: {$k['Non_unique']}\n";
}
