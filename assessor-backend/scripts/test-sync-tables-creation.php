<?php
require_once 'C:/xampp/htdocs/wp-load.php';
$db = new Assessor_Database();
$db->create_tables();

global $wpdb;
$r = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}assessor_sync_runs'");
$ri = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}assessor_sync_run_items'");
echo "Runs table: " . ($r ? "YES" : "NO") . "\n";
echo "Run items table: " . ($ri ? "YES" : "NO") . "\n";

$q_col = $wpdb->get_row("SHOW COLUMNS FROM {$wpdb->prefix}assessor_sync_queue LIKE 'id'");
$m_col = $wpdb->get_row("SHOW COLUMNS FROM {$wpdb->prefix}assessor_sync_meta LIKE 'id'");
echo "Queue id type: " . ($q_col ? $q_col->Type : 'none') . "\n";
echo "Meta id type: " . ($m_col ? $m_col->Type : 'none') . "\n";
