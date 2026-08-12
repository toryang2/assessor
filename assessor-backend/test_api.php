<?php
require 'C:/xampp/htdocs/wp-load.php';
$request = new WP_REST_Request('GET', '/assessor/v1/etracs-properties');
$request->set_param('q', '22-010-0024-00234');
$api = new Assessor_Etracs();
$response = $api->get_faas_list($request);
print_r($response->get_data());
