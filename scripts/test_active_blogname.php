<?php
require_once 'C:\xampp\htdocs\assessor\wp-config.php';
global $wpdb;
$blogname = get_option('blogname');
echo "BLOGNAME: " . $blogname . "\n";
