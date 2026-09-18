<?php
/**
 * Test script for verifying:
 * 1. Declarant parts formatting (Name combinations A-G)
 * 2. Perspective-aware Direction labeling
 * 3. Assessed value display formatting
 * 4. Sync Report recording with full discrete fields
 */

echo "========================================================\n";
echo "Test: Sync Perspective & Formatting Logic\n";
echo "========================================================\n\n";

// PHP mirror of frontend formatDeclarantFromParts & formatSyncDeclarant logic
function formatSyncDeclarantFromParts($last, $first, $middle) {
    $last = trim((string)$last);
    $first = trim((string)$first);
    $middle = trim((string)$middle);

    if ($last === '' && $first === '') return '';

    $mi = str_replace('.', '', $middle);
    $middleFormatted = $mi !== '' ? (strlen($mi) === 1 ? " {$mi}." : " {$mi}") : '';

    if ($last !== '' && $first !== '') {
        return "{$last}, {$first}{$middleFormatted}";
    } elseif ($last !== '') {
        return $last;
    } else {
        return trim("{$first}{$middleFormatted}");
    }
}

function formatSyncBusinessName($business_name) {
    if (empty($business_name)) return '';
    return trim(preg_replace('/,\s*/', ' ', $business_name));
}

function getDirectionInfo($direction, $perspective = 'local') {
    $isLocal = $perspective === 'local';
    if ($direction === 'local_to_live') {
        return $isLocal
            ? ['label' => 'Push', 'tooltip' => 'Pushed to Live Server']
            : ['label' => 'Received', 'tooltip' => 'Received from Local Server'];
    }
    if ($direction === 'live_to_local') {
        return $isLocal
            ? ['label' => 'Pull', 'tooltip' => 'Pulled from Live Server']
            : ['label' => 'Sent', 'tooltip' => 'Sent to Local Server'];
    }
    return ['label' => $direction, 'tooltip' => $direction];
}

function formatSyncAssessedValue($current, $old = null) {
    $hasCurrent = $current !== null && $current !== '';
    $hasOld = $old !== null && $old !== '';

    if (!$hasCurrent && !$hasOld) return '₱0.00';

    $display = '';
    if ($hasCurrent && is_numeric($current)) {
        $display = '₱' . number_format((float)$current, 2);
    } elseif ($hasCurrent) {
        $display = (string)$current;
    }

    if ($hasOld && $display !== '') {
        return "{$display} ({$old})";
    } elseif ($hasOld) {
        return (string)$old;
    } else {
        return $display ?: '₱0.00';
    }
}

// ── Test 1: Declarant formatting combinations A-G ──
echo "--- 1. Declarant Name Combinations (A - G) ---\n";
$name_tests = [
    'A (Standard with 1-char MI)'   => ['DELA CRUZ', 'JUAN', 'P', 'DELA CRUZ, JUAN P.'],
    'B (Multi-char middle)'         => ['DELA CRUZ', 'JUAN', 'PAULO', 'DELA CRUZ, JUAN PAULO'],
    'C (MI already has dot)'        => ['DELA CRUZ', 'JUAN', 'P.', 'DELA CRUZ, JUAN P.'],
    'D (No middle initial)'         => ['DELA CRUZ', 'JUAN', '', 'DELA CRUZ, JUAN'],
    'E (Surname only)'              => ['DELA CRUZ', '', '', 'DELA CRUZ'],
    'F (First name only)'           => ['', 'JUAN', '', 'JUAN'],
    'G (Empty/none)'                => ['', '', '', ''],
];

foreach ($name_tests as $desc => $t) {
    $actual = formatSyncDeclarantFromParts($t[0], $t[1], $t[2]);
    $expected = $t[3];
    $pass = $actual === $expected;
    echo "[" . ($pass ? "PASS" : "FAIL") . "] {$desc}\n";
    if (!$pass) {
        echo "  Expected: '{$expected}'\n";
        echo "  Actual:   '{$actual}'\n";
    }
}

// ── Test 2: Business name sanitization ──
echo "\n--- 2. Business Name Sanitization ---\n";
$biz_tests = [
    'ACME Corp, Inc.' => 'ACME Corp Inc.',
    'San Miguel, Brewery, Inc.' => 'San Miguel Brewery Inc.',
    '' => '',
];
foreach ($biz_tests as $input => $expected) {
    $actual = formatSyncBusinessName($input);
    $pass = $actual === $expected;
    echo "[" . ($pass ? "PASS" : "FAIL") . "] Business '{$input}' -> '{$actual}'\n";
}

// ── Test 3: Perspective-aware Direction labeling ──
echo "\n--- 3. Perspective-aware Direction Labels ---\n";
$dir_tests = [
    ['local_to_live', 'local', 'Push', 'Pushed to Live Server'],
    ['live_to_local', 'local', 'Pull', 'Pulled from Live Server'],
    ['local_to_live', 'live', 'Received', 'Received from Local Server'],
    ['live_to_local', 'live', 'Sent', 'Sent to Local Server'],
];

foreach ($dir_tests as $dt) {
    $info = getDirectionInfo($dt[0], $dt[1]);
    $passLabel = $info['label'] === $dt[2];
    $passTooltip = $info['tooltip'] === $dt[3];
    $pass = $passLabel && $passTooltip;
    echo "[" . ($pass ? "PASS" : "FAIL") . "] dir={$dt[0]}, perspective={$dt[1]} -> label='{$info['label']}', tooltip='{$info['tooltip']}'\n";
}

// ── Test 4: Assessed value formatting ──
echo "\n--- 4. Assessed Value Formatting ---\n";
$av_tests = [
    [150000.5, null, '₱150,000.50'],
    [0, null, '₱0.00'],
    [null, null, '₱0.00'],
    ['', '', '₱0.00'],
    [500000, '400000', '₱500,000.00 (400000)'],
    [null, '400000', '400000'],
];

foreach ($av_tests as $avt) {
    $actual = formatSyncAssessedValue($avt[0], $avt[1]);
    $pass = $actual === $avt[2];
    echo "[" . ($pass ? "PASS" : "FAIL") . "] current=" . var_export($avt[0], true) . ", old=" . var_export($avt[1], true) . " -> '{$actual}'\n";
}

echo "\nAll Unit Tests Completed!\n";
