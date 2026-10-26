<?php
require_once 'C:/xampp/htdocs/wp-load.php';
global $wpdb;

$table_users = $wpdb->prefix . 'assessor_users';
$user = $wpdb->get_row("SELECT * FROM $table_users WHERE role IN ('admin', 'superadmin') AND status = 'active' LIMIT 1");

$auth = new Assessor_Auth();
$reflection = new ReflectionClass($auth);
$method = $reflection->getMethod('generate_token');
$method->setAccessible(true);
$token = $method->invoke($auth, $user);

echo "Token generated: " . substr($token, 0, 20) . "...\n";

// Now perform the real HTTP request via curl against the running Apache web server
$ch = curl_init('http://localhost/wp-json/assessor/v1/etracs/rpu/RPU-180b0697:19327f6fa4c:-6c83/detail');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $token,
    'Accept: application/json'
]);

$response_body = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $http_code\n";
$json = json_decode($response_body, true);

if ($http_code === 200 && is_array($json)) {
    echo "rpu_type: " . ($json['rpu_type'] ?? 'N/A') . "\n";
    echo "machdetails count: " . (isset($json['machdetails']) ? count($json['machdetails']) : 0) . "\n";
    if (!empty($json['machdetails'])) {
        echo "machdetails[0].machinename: " . ($json['machdetails'][0]['machinename'] ?? 'N/A') . "\n";
        echo "machdetails[0].machinecode: " . ($json['machdetails'][0]['machinecode'] ?? 'N/A') . "\n";
        echo "machdetails[0].brand: " . ($json['machdetails'][0]['brand'] ?? 'N/A') . "\n";
    }
    echo "machines count: " . (isset($json['machines']) ? count($json['machines']) : 0) . "\n";
    if (!empty($json['machines'])) {
        echo "machines[0].machinename: " . ($json['machines'][0]['machinename'] ?? 'N/A') . "\n";
    }
} else {
    echo "Response:\n" . substr($response_body, 0, 500) . "\n";
}
