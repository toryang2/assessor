<?php
define('WP_USE_THEMES', false);
require_once('c:/xampp/htdocs/wp-load.php');
$etracs = new Assessor_Etracs();
$req = new WP_REST_Request();
$req->set_param('limit', 1);
$list = $etracs->get_faas_list($req);
if (is_wp_error($list)) {
    print_r($list);
    exit;
}
$data = $list->get_data();
$id = $data['data'][0]['id'];
$res = $etracs->get_faas($id);
if (is_wp_error($res)) {
    print_r($res);
} else {
    print_r($res->get_data());
}
