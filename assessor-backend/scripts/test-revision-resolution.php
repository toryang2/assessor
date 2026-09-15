<?php
/**
 * Test Property Create & Update Revision Assignment Logic (Step 09)
 */
require 'C:/xampp/htdocs/wp-load.php';
global $wpdb;

echo "=== TESTING REVISION RESOLUTION LOGIC ===\n";
$props = new Assessor_Properties();

// Test 1: Valid date 2024 -> GR-2022 (UUID)
$res1 = $props->resolve_revision_by_effectivity_date('2024');
echo "Test 1 (2024): ";
if (!is_wp_error($res1) && $res1 === '01a09e1f-7f77-7873-ba7c-050531fa8a65') {
    echo "PASS ($res1)\n";
} else {
    echo "FAIL: " . print_r($res1, true) . "\n";
}

// Test 2: Valid date 2020 -> GR-2018
$res2 = $props->resolve_revision_by_effectivity_date('2020');
echo "Test 2 (2020): ";
if (!is_wp_error($res2) && $res2 === '01a09e1f-7f74-7ac8-ab9b-b13637255b41') {
    echo "PASS ($res2)\n";
} else {
    echo "FAIL: " . print_r($res2, true) . "\n";
}

// Test 3: Valid date 1968 -> CA-470
$res3 = $props->resolve_revision_by_effectivity_date('1968');
echo "Test 3 (1968): ";
if (!is_wp_error($res3) && $res3 === '01a09e1f-7f5c-7d7a-a13c-d08cedf46ba9') {
    echo "PASS ($res3)\n";
} else {
    echo "FAIL: " . print_r($res3, true) . "\n";
}

// Test 4: Malformed date 'EXEMPT' -> Reject
$res4 = $props->resolve_revision_by_effectivity_date('EXEMPT');
echo "Test 4 (EXEMPT): ";
if (is_wp_error($res4) && $res4->get_error_code() === 'malformed_effectivity_date') {
    echo "PASS (Rejected as expected: {$res4->get_error_message()})\n";
} else {
    echo "FAIL: " . print_r($res4, true) . "\n";
}

// Test 5: Out of range year '1960' -> Reject
$res5 = $props->resolve_revision_by_effectivity_date('1960');
echo "Test 5 (1960): ";
if (is_wp_error($res5) && $res5->get_error_code() === 'no_matching_revision') {
    echo "PASS (Rejected as expected: {$res5->get_error_message()})\n";
} else {
    echo "FAIL: " . print_r($res5, true) . "\n";
}

// Test 6: Client supplies matching revision UUID
$res6 = $props->resolve_revision_by_effectivity_date('2024', '01a09e1f-7f77-7873-ba7c-050531fa8a65');
echo "Test 6 (Matching Client UUID): ";
if (!is_wp_error($res6)) {
    echo "PASS\n";
} else {
    echo "FAIL: " . print_r($res6, true) . "\n";
}

// Test 7: Client supplies matching revision CODE
$res7 = $props->resolve_revision_by_effectivity_date('2024', 'GR-2022');
echo "Test 7 (Matching Client Code): ";
if (!is_wp_error($res7)) {
    echo "PASS\n";
} else {
    echo "FAIL: " . print_r($res7, true) . "\n";
}

// Test 8: Client supplies MISMATCHED revision UUID -> Reject
$res8 = $props->resolve_revision_by_effectivity_date('2024', '01a09e1f-7f74-7ac8-ab9b-b13637255b41');
echo "Test 8 (Mismatched Client UUID): ";
if (is_wp_error($res8) && $res8->get_error_code() === 'revision_mismatch') {
    echo "PASS (Rejected as expected: {$res8->get_error_message()})\n";
} else {
    echo "FAIL: " . print_r($res8, true) . "\n";
}

echo "\nALL TESTS COMPLETED!\n";
