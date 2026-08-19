<?php
require_once 'c:/xampp/htdocs/wp-load.php';
wp_set_current_user(1);
$request = new WP_REST_Request('GET', '/assessor/v1/etracs/faas');
$response = rest_do_request($request);
if (is_wp_error($response)) {
    echo "WP Error: " . $response->get_error_message() . "\n";
} else if (method_exists($response, 'get_data')) {
    echo json_encode($response->get_data(), JSON_PRETTY_PRINT);
} else {
    print_r($response);
}
