<?php
/**
 * Check junction/symlink for assessor-backend in C:/xampp/htdocs
 */
$theme_path = 'C:/xampp/htdocs/wp-content/themes/assessor-local-theme';
$plugin_path = 'C:/xampp/htdocs/wp-content/plugins/assessor-api';

echo "theme_path: $theme_path\n";
echo "is_link: " . (is_link($theme_path) ? 'YES' : 'NO') . "\n";
if (function_exists('readlink') && is_link($theme_path)) {
    echo "target: " . readlink($theme_path) . "\n";
}

echo "plugin_path: $plugin_path\n";
echo "is_link: " . (is_link($plugin_path) ? 'YES' : 'NO') . "\n";
if (function_exists('readlink') && is_link($plugin_path)) {
    echo "target: " . readlink($plugin_path) . "\n";
}
