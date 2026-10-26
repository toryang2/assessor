<?php
require_once 'C:/xampp/htdocs/wp-load.php';

$user = get_user_by('login', 'admin');
wp_set_current_user($user->ID);

$request = new WP_REST_Request('GET', '/assessor/v1/etracs/rpu/RPU-180b0697:19327f6fa4c:-6c83/detail');
$response = rest_do_request($request);
$data = $response->get_data();

echo "HTTP Status: " . $response->get_status() . PHP_EOL;
echo "rpu_type: " . ($data['rpu_type'] ?? 'N/A') . PHP_EOL;
$machdetails = $data['machdetails'] ?? [];
$machines = $data['machines'] ?? [];

echo "machdetails count: " . count($machdetails) . PHP_EOL;
if (!empty($machdetails)) {
    $first = (object)$machdetails[0];
    echo "machdetails[0].machinename: " . ($first->machinename ?? 'N/A') . PHP_EOL;
    echo "machdetails[0].machinecode: " . ($first->machinecode ?? 'N/A') . PHP_EOL;
    echo "machdetails[0].brand: " . ($first->brand ?? 'N/A') . PHP_EOL;
}

echo "machines count: " . count($machines) . PHP_EOL;
if (!empty($machines)) {
    $first_m = (object)$machines[0];
    echo "machines[0].machinename: " . ($first_m->machinename ?? 'N/A') . PHP_EOL;
}
