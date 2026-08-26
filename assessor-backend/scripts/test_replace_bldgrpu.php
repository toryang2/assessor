<?php
define('WP_USE_THEMES', false);
require_once 'wp-load.php';
global $wpdb;

$pdo = new PDO("mysql:host=localhost;dbname=etracs254_kitaotao", 'root', '');
$stmt = $pdo->query("SELECT * FROM bldgrpu LIMIT 1");
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row) {
    echo "Trying to replace row into wp_assessor_bldgrpu...\n";
    $result = $wpdb->replace('wp_assessor_bldgrpu', $row);
    if ($result === false) {
        echo "Failed! Error: " . $wpdb->last_error . "\n";
    } else {
        echo "Success! Result: $result\n";
    }
} else {
    echo "No rows in ETRACS bldgrpu.\n";
}
