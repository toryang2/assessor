<?php
$_SERVER['HTTP_HOST'] = 'localhost';
define('WP_USE_THEMES', false);
require_once 'd:\CODE\assessor\assessor-backend\wp-load.php';
echo "BLOGNAME: " . get_option('blogname') . "\n";
