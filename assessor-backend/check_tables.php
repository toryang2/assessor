<?php
require_once 'C:\xampp\htdocs\wp-load.php';
global $wpdb;

$res = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}assessor_faas");
foreach ($res as $r) {
    echo $r->Field . "\n";
}
