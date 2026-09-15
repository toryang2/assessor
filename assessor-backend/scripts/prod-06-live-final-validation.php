<?php
/**
 * PROD-06 LIVE FINAL VALIDATION SCRIPT (READ-ONLY)
 */

define('WP_USE_THEMES', false);
require_once 'C:/xampp/htdocs/wp-load.php';

global $wpdb;

$report = [];
$report['timestamp'] = date('c');

// Helper to check UUID v7
function is_valid_uuid_v7($uuid) {
    if (!is_string($uuid) || strlen($uuid) !== 36) {
        return false;
    }
    // UUID regex: 8-4-4-4-12
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid)) {
        return false;
    }
    return true;
}

function is_valid_uuid($uuid) {
    if (!is_string($uuid) || strlen($uuid) !== 36) {
        return false;
    }
    return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid);
}

// -------------------------------------------------------------
// 1. REVISION VALIDATION
// -------------------------------------------------------------
$rev_table = $wpdb->prefix . 'assessor_revision_entries';
$revisions = $wpdb->get_results("SELECT * FROM {$rev_table} ORDER BY sort_order ASC, from_year ASC", ARRAY_A);

$rev_count = count($revisions);
$rev_uuids = array_column($revisions, 'id');
$rev_uuid_count = count(array_unique($rev_uuids));
$rev_v7_valid = true;
$rev_v7_invalid_ids = [];

foreach ($rev_uuids as $uid) {
    if (!is_valid_uuid_v7($uid)) {
        $rev_v7_valid = false;
        $rev_v7_invalid_ids[] = $uid;
    }
}

$rev_codes = array_column($revisions, 'revision_code');
$rev_codes_unique = (count($rev_codes) === count(array_unique(array_filter($rev_codes))));
$rev_codes_missing = array_filter($revisions, function($r) {
    return empty($r['revision_code']);
});

$report['REVISION'] = [
    'count' => $rev_count,
    'unique_ids' => ($rev_count === $rev_uuid_count),
    'all_valid_uuid_v7' => $rev_v7_valid,
    'invalid_v7_ids' => $rev_v7_invalid_ids,
    'all_codes_valid_and_unique' => ($rev_codes_unique && count($rev_codes_missing) === 0),
    'revisions_detail' => array_map(function($r) {
        return [
            'id' => $r['id'],
            'revision_year' => $r['revision_year'],
            'from_year' => $r['from_year'],
            'to_year' => $r['to_year'],
            'status' => $r['status'],
            'sort_order' => $r['sort_order'],
            'revision_code' => $r['revision_code']
        ];
    }, $revisions)
];

// -------------------------------------------------------------
// 2. PROPERTY VALIDATION
// -------------------------------------------------------------
$prop_table = $wpdb->prefix . 'assessor_properties';

$total_props = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$prop_table}");
$invalid_prop_uuids = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$prop_table} WHERE id NOT REGEXP '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$'");

// Orphan references: revision_id IS NOT NULL but does not exist in assessor_revision_entries
$orphan_rev_count = (int)$wpdb->get_var("
    SELECT COUNT(*) 
    FROM {$prop_table} p 
    LEFT JOIN {$rev_table} r ON p.revision_id = r.id 
    WHERE p.revision_id IS NOT NULL AND r.id IS NULL
");

$assigned_rev_count = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$prop_table} WHERE revision_id IS NOT NULL");
$unassigned_rev_count = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$prop_table} WHERE revision_id IS NULL");

// Breakdown of unassigned properties
$unassigned_samples = $wpdb->get_results("
    SELECT id, tax_declaration_number, effectivity_date 
    FROM {$prop_table} 
    WHERE revision_id IS NULL 
    LIMIT 10
", ARRAY_A);

$report['PROPERTY'] = [
    'total_count' => $total_props,
    'invalid_uuid_count' => $invalid_prop_uuids,
    'assigned_revision_count' => $assigned_rev_count,
    'unassigned_revision_count' => $unassigned_rev_count,
    'orphan_revision_references' => $orphan_rev_count,
    'unassigned_samples' => $unassigned_samples
];

// -------------------------------------------------------------
// 3. FILTER TEST (Revision Resolution & Effectivity Filtering)
// -------------------------------------------------------------
// Test years: first year (1965), middle year (2000), last year (2025), present (2026), outside range (1950)
$test_years = [
    'first_year' => ['year' => 1965, 'expected_code' => 'CA-470'],
    'middle_year' => ['year' => 2000, 'expected_code' => 'RA-7160-ART-310'],
    'last_year' => ['year' => 2025, 'expected_code' => 'GR-2022'],
    'present' => ['year' => 2026, 'expected_code' => 'GR-2022'],
    'outside_range' => ['year' => 1950, 'expected_code' => null]
];

$filter_results = [];
foreach ($test_years as $label => $spec) {
    $yr = $spec['year'];
    $matching = $wpdb->get_results($wpdb->prepare("
        SELECT id, revision_code, from_year, to_year, status 
        FROM {$rev_table} 
        WHERE status = 'active' 
          AND from_year <= %d 
          AND (to_year >= %d OR to_year IS NULL)
    ", $yr, $yr), ARRAY_A);

    $resolved_code = count($matching) === 1 ? $matching[0]['revision_code'] : (count($matching) > 1 ? 'AMBIGUOUS' : null);
    $matches_prop_count = 0;
    if (count($matching) === 1) {
        $matches_prop_count = (int)$wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$prop_table} WHERE revision_id = %s
        ", $matching[0]['id']));
    }

    $filter_results[$label] = [
        'test_year' => $yr,
        'expected_code' => $spec['expected_code'],
        'resolved_code' => $resolved_code,
        'matching_revision_count' => count($matching),
        'matching_properties_for_revision' => $matches_prop_count,
        'pass' => ($resolved_code === $spec['expected_code'])
    ];
}

$report['FILTER'] = $filter_results;

// -------------------------------------------------------------
// 4. TDN (Tax Declaration Number) REPORT
// -------------------------------------------------------------
// Global UNIQUE status of TDN column
$indexes = $wpdb->get_results("SHOW INDEX FROM {$prop_table}", ARRAY_A);
$tdn_indexes = [];
foreach ($indexes as $idx) {
    if ($idx['Column_name'] === 'tax_declaration_number') {
        $tdn_indexes[] = [
            'Key_name' => $idx['Key_name'],
            'Non_unique' => (int)$idx['Non_unique'],
            'Seq_in_index' => (int)$idx['Seq_in_index']
        ];
    }
}

// Duplicate TDNs count
$dup_tdns = $wpdb->get_results("
    SELECT tax_declaration_number, COUNT(*) as cnt, COUNT(DISTINCT revision_id) as rev_cnt
    FROM {$prop_table}
    WHERE tax_declaration_number IS NOT NULL AND tax_declaration_number != ''
    GROUP BY tax_declaration_number
    HAVING cnt > 1
", ARRAY_A);

$duplicate_tdn_total = count($dup_tdns);
$same_tdn_diff_rev = 0;
$same_tdn_same_rev = 0;

foreach ($dup_tdns as $d) {
    if ((int)$d['cnt'] === (int)$d['rev_cnt']) {
        $same_tdn_diff_rev++;
    } else {
        $same_tdn_same_rev++;
    }
}

$report['TDN'] = [
    'indexes' => $tdn_indexes,
    'has_global_unique_index' => count(array_filter($tdn_indexes, function($i) { return $i['Non_unique'] === 0 && $i['Seq_in_index'] === 1 && $i['Key_name'] !== 'idx_tdn_revision'; })) > 0,
    'duplicate_tdn_count' => $duplicate_tdn_total,
    'same_tdn_different_revision_count' => $same_tdn_diff_rev,
    'same_tdn_same_revision_count' => $same_tdn_same_rev,
    'sample_duplicates' => array_slice($dup_tdns, 0, 5)
];

// -------------------------------------------------------------
// 5. RELATIONSHIPS CHECK
// -------------------------------------------------------------
$tables_to_check = [
    'property_versions' => $wpdb->prefix . 'assessor_property_versions',
    'documents' => $wpdb->prefix . 'assessor_documents',
    'property_states' => $wpdb->prefix . 'assessor_property_states',
    'audit_trail' => $wpdb->prefix . 'assessor_audit_trail',
    'requests' => $wpdb->prefix . 'assessor_requests',
    'etracs_faas' => $wpdb->prefix . 'assessor_etracs_faas',
    'etracs_sync_log' => $wpdb->prefix . 'assessor_etracs_sync_log',
    'lineage' => $wpdb->prefix . 'assessor_property_lineage',
    'superseded' => $wpdb->prefix . 'assessor_superseded_properties',
    'taxpayers' => $wpdb->prefix . 'assessor_taxpayers'
];

$relationship_report = [];
foreach ($tables_to_check as $label => $tbl) {
    $exists = $wpdb->get_var("SHOW TABLES LIKE '{$tbl}'");
    if (!$exists) {
        $relationship_report[$label] = ['status' => 'TABLE_NOT_FOUND'];
        continue;
    }

    $row_count = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$tbl}");
    
    // Check if property_id / property_uuid column exists
    $cols = $wpdb->get_col("DESCRIBE {$tbl}", 0);
    $prop_col = null;
    foreach (['property_id', 'property_uuid', 'id'] as $c) {
        if (in_array($c, $cols, true) && $label !== 'taxpayers') {
            $prop_col = $c;
            break;
        }
    }

    $orphan_count = 0;
    if ($prop_col && $label !== 'taxpayers') {
        $orphan_count = (int)$wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$tbl} t 
            LEFT JOIN {$prop_table} p ON t.{$prop_col} = p.id 
            WHERE t.{$prop_col} IS NOT NULL AND p.id IS NULL
        ");
    }

    $relationship_report[$label] = [
        'table' => $tbl,
        'row_count' => $row_count,
        'linking_column' => $prop_col,
        'orphan_to_properties' => $orphan_count
    ];
}

$report['RELATIONSHIPS'] = $relationship_report;

// Write report as JSON
echo json_encode($report, JSON_PRETTY_PRINT);
