<?php
require_once 'd:\CODE\assessor\assessor-backend\wp-config.php';
global $wpdb;
$blogname = $wpdb->get_var("SELECT option_value FROM {$wpdb->prefix}options WHERE option_name = 'blogname'");
echo "BLOGNAME: " . $blogname . "\n";
