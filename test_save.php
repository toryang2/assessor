<?php
require_once 'c:/xampp/htdocs/wp-load.php'; 
$request = new WP_REST_Request('POST', '/assessor/v1/settings');
$request->set_body_params(array('enable_etracs_features' => 0));
$controller = new Assessor_Settings_API();
$response = $controller->save_settings($request);
print_r($response->get_data());
?>
