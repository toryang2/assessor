<?php
require_once 'C:/xampp/htdocs/wp-load.php';
global $wpdb;

$table_users = $wpdb->prefix . 'assessor_users';
$user = $wpdb->get_row("SELECT * FROM $table_users WHERE role IN ('admin', 'superadmin') AND status = 'active' LIMIT 1");

$auth = new Assessor_Auth();
$ref = new ReflectionClass($auth);
$m = $ref->getMethod('generate_token');
$m->setAccessible(true);
$token = $m->invoke($auth, $user);

$ch = curl_init('http://localhost/wp-json/assessor/v1/etracs/rpu/RPU-180b0697:19327f6fa4c:-6c83/detail');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);
$res = curl_exec($ch);
curl_close($ch);

echo "Raw Response:\n" . $res . "\n";
