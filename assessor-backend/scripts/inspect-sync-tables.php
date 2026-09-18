<?php
require_once 'C:/xampp/htdocs/wp-load.php';
global $wpdb;

$q_table = $wpdb->prefix . 'assessor_sync_queue';
$m_table = $wpdb->prefix . 'assessor_sync_meta';

$q_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $q_table));
$m_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $m_table));

echo "Queue table exists: " . ($q_exists ? "YES" : "NO") . "\n";
if ($q_exists) {
    $q_col = $wpdb->get_row("SHOW COLUMNS FROM $q_table LIKE 'id'");
    $count = $wpdb->get_var("SELECT COUNT(*) FROM $q_table");
    echo "Queue count: $count, id type: " . ($q_col ? $q_col->Type : 'none') . "\n";
    $status_counts = $wpdb->get_results("SELECT status, COUNT(*) as cnt FROM $q_table GROUP BY status", ARRAY_A);
    foreach ($status_counts as $sc) {
        echo "  - {$sc['status']}: {$sc['cnt']}\n";
    }
}

echo "Meta table exists: " . ($m_exists ? "YES" : "NO") . "\n";
if ($m_exists) {
    $m_col = $wpdb->get_row("SHOW COLUMNS FROM $m_table LIKE 'id'");
    $count = $wpdb->get_var("SELECT COUNT(*) FROM $m_table");
    echo "Meta count: $count, id type: " . ($m_col ? $m_col->Type : 'none') . "\n";
    $meta_rows = $wpdb->get_results("SELECT meta_key, meta_value FROM $m_table", ARRAY_A);
    foreach ($meta_rows as $mr) {
        if ($mr['meta_key'] === 'sync_token') continue;
        echo "  - {$mr['meta_key']}: {$mr['meta_value']}\n";
    }
}
