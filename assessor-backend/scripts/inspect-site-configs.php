<?php
/**
 * Investigate Apache VirtualHost / docroot or database mapping
 */
echo "=== Apache & WordPress Site URL check ===\n";
$pdo_test = new PDO('mysql:host=127.0.0.1;dbname=test', 'root', '');
$siteurl_test = $pdo_test->query("SELECT option_value FROM wp_options WHERE option_name = 'siteurl'")->fetchColumn();
$home_test = $pdo_test->query("SELECT option_value FROM wp_options WHERE option_name = 'home'")->fetchColumn();
echo "test db: siteurl=$siteurl_test, home=$home_test\n";

$pdo_local = new PDO('mysql:host=127.0.0.1;dbname=assessor_local', 'root', '');
$siteurl_local = $pdo_local->query("SELECT option_value FROM wp_options WHERE option_name = 'siteurl'")->fetchColumn();
$home_local = $pdo_local->query("SELECT option_value FROM wp_options WHERE option_name = 'home'")->fetchColumn();
echo "assessor_local db: siteurl=$siteurl_local, home=$home_local\n";

// Check active plugins and themes in both
$plugins_test = $pdo_test->query("SELECT option_value FROM wp_options WHERE option_name = 'active_plugins'")->fetchColumn();
$theme_test = $pdo_test->query("SELECT option_value FROM wp_options WHERE option_name = 'stylesheet'")->fetchColumn();
echo "test db: stylesheet=$theme_test, active_plugins=" . var_export(@unserialize($plugins_test), true) . "\n";

$plugins_local = $pdo_local->query("SELECT option_value FROM wp_options WHERE option_name = 'active_plugins'")->fetchColumn();
$theme_local = $pdo_local->query("SELECT option_value FROM wp_options WHERE option_name = 'stylesheet'")->fetchColumn();
echo "assessor_local db: stylesheet=$theme_local, active_plugins=" . var_export(@unserialize($plugins_local), true) . "\n";
