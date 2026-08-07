<?php
define('WP_DEBUG', true);
define('WP_DEBUG_DISPLAY', true);
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'c:/xampp/htdocs/wp-load.php';
global $wpdb;

require_once 'c:/xampp/htdocs/wp-content/plugins/assessor-api/includes/class-assessor-etracs.php';
$controller = new Assessor_Etracs();
$request = new WP_REST_Request('GET', '/assessor/v1/etracs/faas');
$request->set_param('page', 1);
$request->set_param('per_page', 20);

$response = $controller->get_faas_list($request);

echo "WPDB Last Error: " . $wpdb->last_error . "\n";
echo "Response Data: \n";
print_r($response->get_data());
