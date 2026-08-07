<?php
define('WP_USE_THEMES', false);
require_once('c:/xampp/htdocs/wp-load.php');
global $wpdb;
$t = $wpdb->prefix . 'assessor_settings';
print_r($wpdb->get_row("SELECT * FROM $t ORDER BY id DESC LIMIT 1", ARRAY_A));
