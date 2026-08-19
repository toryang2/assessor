<?php
require_once 'wp-load.php';
ini_set('display_errors', 1);
error_reporting(E_ALL);
$request = new WP_REST_Request('GET', '/assessor/v1/properties/45642');
$request->set_param('id', 45642);
$response = rest_do_request($request);
if ($response->is_error()) { print_r($response->as_error()); } else { print_r($response->get_data()); }
