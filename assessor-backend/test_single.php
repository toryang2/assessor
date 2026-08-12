<?php
require 'C:/xampp/htdocs/wp-load.php';
$request = new WP_REST_Request('GET', '/assessor/v1/etracs-properties/Ffc7a625:1862a553a16:-b78');
$api = new Assessor_Etracs();
$response = rest_do_request($request); // Wait, this hits the router, but let's just use the object
$data = $api->get_faas('Ffc7a625:1862a553a16:-b78');
if (is_wp_error($data)) {
    print_r($data);
} else {
    echo "ID: {$data->id}\n";
    echo "PREV TD: {$data->prevtdno}\n";
    echo "PREV OWNER: {$data->prev_owner}\n";
    echo "PREV PIN: {$data->prev_pin}\n";
}
