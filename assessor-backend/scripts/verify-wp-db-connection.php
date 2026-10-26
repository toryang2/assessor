<?php
require_once 'C:/xampp/htdocs/wp-load.php';
global $wpdb;

echo "SELECT DATABASE(): " . $wpdb->get_var("SELECT DATABASE()") . PHP_EOL;
echo "Table prefix: " . $wpdb->prefix . PHP_EOL;
echo "DB User: " . DB_USER . PHP_EOL;
echo "DB Host: " . DB_HOST . PHP_EOL;
