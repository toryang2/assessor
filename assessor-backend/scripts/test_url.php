<?php
require_once('wp-load.php');
echo "WP_HOME: " . (defined('WP_HOME') ? WP_HOME : 'Not defined') . "\n";
echo "get_site_url(): " . get_site_url() . "\n";
echo "get_template_directory_uri(): " . get_template_directory_uri() . "\n";
?>
