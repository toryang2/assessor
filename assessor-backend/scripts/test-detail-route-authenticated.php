<?php
require_once 'C:/xampp/htdocs/wp-load.php';
global $wpdb;

$table_users = $wpdb->prefix . 'assessor_users';
$user = $wpdb->get_row("SELECT * FROM $table_users WHERE role IN ('admin', 'superadmin') AND status = 'active' LIMIT 1");
if (!$user) {
    echo "No admin found in assessor_users!\n";
    exit;
}
echo "Found user: {$user->username} (role: {$user->role})\n";

$auth = new Assessor_Auth();
$token = $auth->generate_token($user);
echo "Token generated: " . substr($token, 0, 20) . "...\n";

$request = new WP_REST_Request('GET', '/assessor/v1/etracs/rpu/RPU-180b0697:19327f6fa4c:-6c83/detail');
$request->set_header('authorization', 'Bearer ' . $token);

$response = rest_do_request($request);
echo "Response status: " . $response->get_status() . "\n";
$data = $response->get_data();
echo json_encode($data, JSON_PRETTY_PRINT);
