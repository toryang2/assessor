<?php
/**
 * Compare code between repo and C:/xampp/htdocs
 */
$repo_plugin = 'd:/CODE/assessor/assessor-backend/wp-content/plugins/assessor-api/includes/class-assessor-etracs.php';
$htdocs_plugin = 'C:/xampp/htdocs/wp-content/plugins/assessor-api/includes/class-assessor-etracs.php';

$repo_hash = md5_file($repo_plugin);
$htdocs_hash = md5_file($htdocs_plugin);

echo "repo class-assessor-etracs.php md5: $repo_hash\n";
echo "htdocs class-assessor-etracs.php md5: $htdocs_hash\n";
echo "Plugin files match exactly: " . ($repo_hash === $htdocs_hash ? "YES" : "NO") . "\n";
