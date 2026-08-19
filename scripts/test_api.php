<?php
define('WP_USE_THEMES', false);
require_once('c:/xampp/htdocs/wp-load.php');

$request = new WP_REST_Request('GET', '/assessor/v1/properties');
$request->set_param('q', 'current td');
$request->set_param('property_state', 'cancelled');
$controller = new Assessor_Properties();
$response = $controller->get_properties($request);
print_r($response);
