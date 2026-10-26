<?php
require_once 'C:/xampp/htdocs/wp-load.php';
global $wpdb;

echo "=== Verification of Target Machinery Record in 'test' ===\n";

$rpu_id = 'RPU-180b0697:19327f6fa4c:-6c83';
$detail_id = 'MD16c1fd8f:1934c83b979:-766b';
$machine_id = 'MAC-4fa5cd24:165f5950c9d:-7df4';

// Check wp_assessor_machdetail
$detail = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}assessor_machdetail WHERE objid = %s", $detail_id));
echo "wp_assessor_machdetail row:\n";
print_r($detail);

// Check wp_assessor_machine
$machine = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}assessor_machine WHERE objid = %s", $machine_id));
echo "\nwp_assessor_machine row:\n";
print_r($machine);

// Test the actual backend query
$query = "
    SELECT md.*,
           COALESCE(m.name, md.brand, md.model) as machinename,
           m.code as machinecode,
           m.name as machine_name
    FROM {$wpdb->prefix}assessor_machdetail md
    LEFT JOIN {$wpdb->prefix}assessor_machine m ON md.machine_objid = m.objid
    WHERE md.machrpuid = %s
";

$results = $wpdb->get_results($wpdb->prepare($query, $rpu_id));
echo "\nBackend detail query results for RPU ($rpu_id):\n";
print_r($results);
echo "SQL Error: " . ($wpdb->last_error ?: 'NONE') . "\n";
