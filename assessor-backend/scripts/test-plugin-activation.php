<?php
ob_start();
require_once 'C:/xampp/htdocs/wp-load.php';

// Deactivate first
deactivate_plugins('assessor-api/assessor-api.php');

$before = ob_get_clean();

ob_start();
$result = activate_plugin('assessor-api/assessor-api.php');
$output = ob_get_clean();

echo "RESULT: " . (is_wp_error($result) ? $result->get_error_message() : 'SUCCESS') . PHP_EOL;
echo "OUTPUT LENGTH: " . strlen($output) . PHP_EOL;
echo "OUTPUT CONTENT:\n" . $output . PHP_EOL;
