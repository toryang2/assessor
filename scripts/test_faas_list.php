<?php
define('WP_USE_THEMES', false);
require_once('c:/xampp/htdocs/wp-load.php');
$etracs = new Assessor_Etracs();
$request = new WP_REST_Request();
$request->set_param('page', 0);
$request->set_param('limit', 10);
$res = $etracs->get_faas_list($request);
if (is_wp_error($res)) {
    echo "WP_Error: " . $res->get_error_message();
} else {
    // $res is WP_REST_Response
    $data = $res->get_data();
    echo "Success. Rows returned: " . count($data['data']);
}
