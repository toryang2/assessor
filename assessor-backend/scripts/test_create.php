<?php
require_once dirname(__DIR__) . '/wp-load.php';

$api = new Assessor_Requests();
$data = array(
    'property_id' => 9128,
    'amount_paid' => 600,
    'receipt_number' => 'TEST-123',
    'date_issued' => '2026-08-20',
    'place_issued' => 'MTO',
    'prepared_by' => 'System',
    'purpose' => 'test',
    'client_name' => 'Tester',
    'client_address' => '',
    'contact_number' => '',
    'email' => '',
    'remarks' => '',
    'created_by' => 1,
    'updated_by' => 1,
    'created_at' => current_time('mysql'),
    'updated_at' => current_time('mysql')
);

// We simulate NOT providing signatories
$result = $api->create_request($data);
print_r($result);
