<?php
require_once 'C:/xampp/htdocs/wp-load.php';

// First call handle_login to get a valid token
$api = new Assessor_API();
$auth = new Assessor_Auth();

// Let's create an authentication token directly or mock the auth header
$user = get_user_by('login', 'admin');
$token = $auth->generate_token($user->ID);

echo "Generated token for admin: " . substr($token, 0, 20) . "...\n";

// Now perform the REST request with the Authorization header
$request = new WP_REST_Request('GET', '/assessor/v1/etracs/rpu/RPU-180b0697:19327f6fa4c:-6c83/detail');
$request->set_header('Authorization', 'Bearer ' . $token);

$response = rest_do_request($request);
$status = $response->get_status();
$data = $response->get_data();

echo "HTTP Status: " . $status . PHP_EOL;

if ($status === 200) {
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
} else {
    print_r($data);
}
