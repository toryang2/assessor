<?php
define('WP_USE_THEMES', false);
require_once('c:/xampp/htdocs/wp-load.php');
global $wpdb;
$t = $wpdb->prefix . 'assessor_settings';
$wpdb->query("ALTER TABLE $t ADD COLUMN enable_etracs_features tinyint(1) NOT NULL DEFAULT 0");
echo "Column added.";
