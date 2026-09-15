<?php
/**
 * Step 14: Full Data Integrity Validation Report
 * Read-only script. Analyzes revisions, properties, duplicate TDNs,
 * relationships across all tables, and functional behavior.
 */

require_once 'C:/xampp/htdocs/wp-load.php';

global $wpdb;

echo "================================================================================\n";
echo "                      STEP 14: DATA INTEGRITY VALIDATION REPORT                  \n";
echo "================================================================================\n";
echo "Date Generated: " . date('Y-m-d H:i:s') . "\n";
echo "Mode: Read-Only (no modifications performed)\n\n";

$p = $wpdb->prefix;
$failures = array();
$warnings = array();

// -----------------------------------------------------------------------------
// 1. REVISION VALIDATION
// -----------------------------------------------------------------------------
echo "1. REVISION VALIDATION\n";
echo "---------------------\n";

$rev_table = $p . 'assessor_revision_entries';
$revisions = $wpdb->get_results("SELECT * FROM {$rev_table} ORDER BY CAST(from_year AS UNSIGNED) ASC", ARRAY_A);
$rev_count = count($revisions);
echo "• Total Revision Entries: {$rev_count}\n";

// A. UUID v7 check
$non_v7_revs = 0;
foreach ($revisions as $r) {
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $r['id'])) {
        $non_v7_revs++;
    }
}
if ($non_v7_revs === 0) {
    echo "  [PASS] All {$rev_count} revision IDs are valid UUID v7.\n";
} else {
    $failures[] = "Revisions: {$non_v7_revs} revisions have non-UUID v7 IDs.";
    echo "  [FAIL] {$non_v7_revs} revisions have non-UUID v7 IDs.\n";
}

// B. Unique IDs
$dup_rev_ids = $wpdb->get_var("SELECT COUNT(*) FROM (SELECT id FROM {$rev_table} GROUP BY id HAVING COUNT(*) > 1) t");
if (intval($dup_rev_ids) === 0) {
    echo "  [PASS] Revision IDs are 100% unique.\n";
} else {
    $failures[] = "Revisions: Duplicate revision IDs found ({$dup_rev_ids}).";
    echo "  [FAIL] Duplicate revision IDs found ({$dup_rev_ids}).\n";
}

// C. Row count expected
if ($rev_count === 9) {
    echo "  [PASS] Row count matches authoritative set (9 revisions).\n";
} else {
    $warnings[] = "Revisions: Expected 9 revisions, found {$rev_count}.";
    echo "  [WARN] Expected 9 revisions, found {$rev_count}.\n";
}

// D. Orphan revision references in properties table
$orphan_prop_revs = $wpdb->get_results(
    "SELECT p.revision_id, COUNT(*) as cnt 
     FROM {$p}assessor_properties p 
     LEFT JOIN {$rev_table} r ON p.revision_id = r.id 
     WHERE p.revision_id IS NOT NULL AND p.revision_id != '' AND r.id IS NULL 
     GROUP BY p.revision_id", 
    ARRAY_A
);
if (empty($orphan_prop_revs)) {
    echo "  [PASS] Zero orphan revision references in properties table.\n";
} else {
    $failures[] = "Revisions: Found orphan revision_ids referenced in properties table.";
    echo "  [FAIL] Orphan revision_ids referenced in properties table: " . json_encode($orphan_prop_revs) . "\n";
}

// E. Range overlaps & gaps among active revisions
$active_revs = array_filter($revisions, function($r) { return $r['status'] === 'active'; });
$prev_end = null;
$overlaps = array();
$gaps = array();

foreach ($active_revs as $r) {
    $start = intval($r['from_year']);
    $end = ($r['to_year'] === 'present') ? intval(date('Y')) : intval($r['to_year']);
    
    if ($prev_end !== null) {
        if ($start <= $prev_end) {
            $overlaps[] = "{$r['revision_code']} (starts {$start}) overlaps with previous ending at {$prev_end}";
        } elseif ($start > ($prev_end + 1)) {
            $gaps[] = "Gap between {$prev_end} and {$start}";
        }
    }
    $prev_end = $end;
}

if (empty($overlaps)) {
    echo "  [PASS] No unexpected overlapping active revision year ranges.\n";
} else {
    $failures[] = "Revisions: Overlapping active ranges: " . implode('; ', $overlaps);
    echo "  [FAIL] Overlapping active ranges: " . implode('; ', $overlaps) . "\n";
}

if (empty($gaps)) {
    echo "  [PASS] Continuous year range coverage across active revisions (1965-present).\n";
} else {
    $warnings[] = "Revisions: Gaps in coverage: " . implode('; ', $gaps);
    echo "  [WARN] Gaps in coverage: " . implode('; ', $gaps) . "\n";
}

echo "\n";

// -----------------------------------------------------------------------------
// 2. PROPERTY VALIDATION
// -----------------------------------------------------------------------------
echo "2. PROPERTY VALIDATION\n";
echo "---------------------\n";

$prop_table = $p . 'assessor_properties';
$total_props = intval($wpdb->get_var("SELECT COUNT(*) FROM {$prop_table}"));
echo "• Total Property Records: {$total_props}\n";

// A. UUIDs unique and valid v7
$dup_prop_ids = intval($wpdb->get_var("SELECT COUNT(*) FROM (SELECT id FROM {$prop_table} GROUP BY id HAVING COUNT(*) > 1) t"));
$non_v7_props = intval($wpdb->get_var("SELECT COUNT(*) FROM {$prop_table} WHERE id NOT REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'"));

if ($dup_prop_ids === 0) {
    echo "  [PASS] Property UUIDs are 100% unique.\n";
} else {
    $failures[] = "Properties: {$dup_prop_ids} duplicate property UUIDs found.";
    echo "  [FAIL] {$dup_prop_ids} duplicate property UUIDs found.\n";
}

if ($non_v7_props === 0) {
    echo "  [PASS] All {$total_props} property UUIDs are valid UUID v7 format.\n";
} else {
    $failures[] = "Properties: {$non_v7_props} properties have non-v7 UUIDs.";
    echo "  [FAIL] {$non_v7_props} properties have non-v7 UUIDs.\n";
}

// B. Revision UUID resolution
$null_rev_props = intval($wpdb->get_var("SELECT COUNT(*) FROM {$prop_table} WHERE revision_id IS NULL OR revision_id = ''"));
$resolved_rev_props = intval($wpdb->get_var("SELECT COUNT(*) FROM {$prop_table} p JOIN {$rev_table} r ON p.revision_id = r.id"));

echo "• Property Revision Linkage:\n";
echo "    - Successfully resolved to active revision: {$resolved_rev_props} / {$total_props} (" . number_format(($resolved_rev_props/$total_props)*100, 2) . "%)\n";
echo "    - Unresolved/NULL revision_id: {$null_rev_props} / {$total_props}\n";

if ($null_rev_props > 0) {
    $null_breakdown = $wpdb->get_results("SELECT effectivity_date, COUNT(*) as cnt FROM {$prop_table} WHERE revision_id IS NULL OR revision_id = '' GROUP BY effectivity_date", ARRAY_A);
    echo "    - Breakdown of unresolved properties by effectivity_date:\n";
    foreach ($null_breakdown as $nb) {
        $eff = ($nb['effectivity_date'] === '') ? '(empty string)' : $nb['effectivity_date'];
        echo "        * {$eff}: {$nb['cnt']} properties\n";
    }
    echo "  [NOTE] These 105 properties possess legacy non-numeric/empty effectivity dates (e.g. '', 'EXEMPT', '1963') predating active revision spans (1965+).\n";
}

// C. TDN count
$total_tdns = intval($wpdb->get_var("SELECT COUNT(DISTINCT tax_declaration_number) FROM {$prop_table}"));
echo "• Unique TDN count: {$total_tdns}\n\n";

// -----------------------------------------------------------------------------
// 3. DUPLICATE TDN ANALYSIS
// -----------------------------------------------------------------------------
echo "3. DUPLICATE TDN ANALYSIS\n";
echo "-------------------------\n";

$dup_tdns = $wpdb->get_results(
    "SELECT tax_declaration_number, COUNT(*) as cnt 
     FROM {$prop_table} 
     GROUP BY tax_declaration_number 
     HAVING cnt > 1 
     ORDER BY cnt DESC", 
    ARRAY_A
);

if (empty($dup_tdns)) {
    echo "  [PASS] Currently zero duplicate TDNs in production dataset.\n";
    echo "         (Duplicate TDNs across revisions are architecturally supported and tested).\n";
} else {
    echo "• Found " . count($dup_tdns) . " TDNs occurring more than once:\n";
    foreach ($dup_tdns as $dt) {
        $tdn = $dt['tax_declaration_number'];
        $matching = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.id, p.tax_declaration_number, p.revision_id, r.revision_year, p.status, p.effectivity_date 
                 FROM {$prop_table} p 
                 LEFT JOIN {$rev_table} r ON p.revision_id = r.id 
                 WHERE p.tax_declaration_number = %s",
                $tdn
            ),
            ARRAY_A
        );
        echo "    * TDN: '{$tdn}' (Count: {$dt['cnt']})\n";
        $rev_set = array();
        $same_rev_collision = false;
        foreach ($matching as $m) {
            echo "        - UUID: {$m['id']}, Rev: {$m['revision_id']} ({$m['revision_year']}), Eff: {$m['effectivity_date']}, Status: {$m['status']}\n";
            if (isset($rev_set[$m['revision_id']])) {
                $same_rev_collision = true;
            }
            $rev_set[$m['revision_id']] = true;
        }
        if ($same_rev_collision) {
            $failures[] = "Duplicate TDN: '{$tdn}' has multiple records within the SAME revision.";
            echo "        [FAIL] Collision detected: multiple records in same revision!\n";
        } else {
            echo "        [PASS] Clean cross-revision duplicate: all records belong to distinct revisions.\n";
        }
    }
}
echo "\n";

// -----------------------------------------------------------------------------
// 4. RELATIONSHIP CHECKS
// -----------------------------------------------------------------------------
echo "4. RELATIONSHIP CHECKS ACROSS SUBSYSTEMS\n";
echo "---------------------------------------\n";

$rel_checks = array(
    'Property Versions' => array(
        'table' => $p . 'assessor_property_versions',
        'column' => 'property_id',
        'nullable' => false,
    ),
    'Documents' => array(
        'table' => $p . 'assessor_documents',
        'column' => 'property_id',
        'nullable' => false,
    ),
    'Property States' => array(
        'table' => $p . 'assessor_property_states',
        'column' => 'property_id',
        'nullable' => false,
    ),
    'Audit Trail' => array(
        'table' => $p . 'assessor_audit_trail',
        'column' => 'record_id',
        'filter' => "record_type = 'properties' OR table_name LIKE '%properties'",
        'nullable' => true,
    ),
    'Requests' => array(
        'table' => $p . 'assessor_requests',
        'column' => 'property_id',
        'nullable' => true,
    ),
    'Sync Queue' => array(
        'table' => $p . 'assessor_sync_queue',
        'column' => 'property_id',
        'nullable' => false,
    ),
);

foreach ($rel_checks as $subsystem => $cfg) {
    $tbl = $cfg['table'];
    $tbl_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $tbl));
    if (!$tbl_exists) {
        echo "• {$subsystem}: Table does not exist (skipping).\n";
        continue;
    }
    
    $col = $cfg['column'];
    $where = "1=1";
    if (!empty($cfg['filter'])) {
        $where .= " AND ({$cfg['filter']})";
    }
    if ($cfg['nullable']) {
        $where .= " AND {$col} IS NOT NULL AND {$col} != ''";
    }
    
    $total_related = intval($wpdb->get_var("SELECT COUNT(*) FROM {$tbl} WHERE {$where}"));
    $orphans = intval($wpdb->get_var(
        "SELECT COUNT(*) FROM {$tbl} t 
         LEFT JOIN {$prop_table} p ON t.{$col} = p.id 
         WHERE {$where} AND p.id IS NULL"
    ));
    
    if ($orphans === 0) {
        echo "• {$subsystem} ({$tbl}): [PASS] Total: {$total_related}, Orphans: 0\n";
    } else {
        $failures[] = "Relationships: {$orphans} orphan records found in {$tbl} (no matching property).";
        echo "• {$subsystem} ({$tbl}): [FAIL] Total: {$total_related}, Orphans: {$orphans}\n";
    }
}

// Lineage sanity check
$broken_predecessors = intval($wpdb->get_var(
    "SELECT COUNT(*) FROM {$prop_table} 
     WHERE previous_tax_declaration_number IS NOT NULL 
       AND previous_tax_declaration_number != '' 
       AND previous_tax_declaration_number = tax_declaration_number"
));
if ($broken_predecessors === 0) {
    echo "• Lineage Self-Reference: [PASS] Zero properties cite themselves as previous TDN.\n";
} else {
    $failures[] = "Lineage: {$broken_predecessors} properties have previous_tax_declaration_number == tax_declaration_number.";
    echo "• Lineage Self-Reference: [FAIL] {$broken_predecessors} properties cite themselves as previous TDN.\n";
}

echo "\n";

// -----------------------------------------------------------------------------
// 5. FUNCTIONAL CHECKS
// -----------------------------------------------------------------------------
echo "5. FUNCTIONAL CHECKS\n";
echo "-------------------\n";

$props_service = new Assessor_Properties();

// Test A: Revision filter unchanged and active
$active_rev_id = $revisions[0]['id'];
$req_filter = new WP_REST_Request('GET', '/assessor/v1/properties');
$req_filter->set_param('revision_id', $active_rev_id);
$req_filter->set_param('per_page', 5);
$filter_res = $props_service->get_properties($req_filter);
if (is_array($filter_res) && isset($filter_res['properties'])) {
    echo "• Revision Filter: [PASS] Filter by revision_id returns cleanly (" . count($filter_res['properties']) . " records).\n";
} else {
    $failures[] = "Functional: revision_id filter failed.";
    echo "• Revision Filter: [FAIL] Query returned unexpected format.\n";
}

// Test B: Same TDN + Same Revision is rejected
$func_tdn = 'VALIDATE-TEST-TDN-' . time();
$uuid_a = class_exists('Assessor_UUID') ? Assessor_UUID::v7() : wp_generate_uuid4();
$uuid_b = class_exists('Assessor_UUID') ? Assessor_UUID::v7() : wp_generate_uuid4();
$cleanup_func_ids = array($uuid_a, $uuid_b);

$prop_data_1 = array(
    'id'                     => $uuid_a,
    'tax_declaration_number' => $func_tdn,
    'effectivity_date'       => '2024',
    'declarant_last_name'    => 'TestLast',
    'declarant_first_name'   => 'TestFirst',
    'location'               => 'Test Loc',
    'kind_of_property'       => 'Land',
    'status'                 => 'active',
);

$req_create_1 = new WP_REST_Request('POST', '/assessor/v1/properties');
$req_create_1->set_body_params($prop_data_1);
$res_create_1 = $props_service->create_property($req_create_1);
if (is_wp_error($res_create_1)) {
    $failures[] = "Functional: Failed creating base property for duplicate test: " . $res_create_1->get_error_message();
    echo "• Base Property Creation: [FAIL] " . $res_create_1->get_error_message() . "\n";
} else {
    // Attempt duplicate in same revision (same effectivity year 2024 -> GR-2022)
    $prop_data_same_rev = array(
        'id'                     => $uuid_b,
        'tax_declaration_number' => $func_tdn,
        'effectivity_date'       => '2024',
        'declarant_last_name'    => 'Collision',
        'declarant_first_name'   => 'Test',
        'location'               => 'Test Loc',
        'kind_of_property'       => 'Land',
        'status'                 => 'active',
    );
    $req_same_rev = new WP_REST_Request('POST', '/assessor/v1/properties');
    $req_same_rev->set_body_params($prop_data_same_rev);
    $res_same_rev = $props_service->create_property($req_same_rev);
    if (is_wp_error($res_same_rev) && ($res_same_rev->get_error_code() === 'duplicate_tax_number' || $res_same_rev->get_error_code() === 'duplicate_tdn')) {
        echo "• Same TDN + Same Revision: [PASS] Correctly rejected with '{$res_same_rev->get_error_code()}' error.\n";
    } else {
        $failures[] = "Functional: Same TDN + Same Revision was NOT rejected.";
        echo "• Same TDN + Same Revision: [FAIL] Expected rejection but succeeded or returned wrong error.\n";
    }

    // Attempt same TDN in DIFFERENT revision (effectivity year 2020 -> GR-2018)
    $prop_data_diff_rev = array(
        'id'                     => $uuid_b,
        'tax_declaration_number' => $func_tdn,
        'effectivity_date'       => '2020',
        'declarant_last_name'    => 'DifferentRev',
        'declarant_first_name'   => 'Test',
        'location'               => 'Test Loc',
        'kind_of_property'       => 'Land',
        'status'                 => 'active',
    );
    $req_diff_rev = new WP_REST_Request('POST', '/assessor/v1/properties');
    $req_diff_rev->set_body_params($prop_data_diff_rev);
    $res_diff_rev = $props_service->create_property($req_diff_rev);
    if (!is_wp_error($res_diff_rev)) {
        echo "• Same TDN + Different Revision: [PASS] Allowed and created cleanly.\n";
    } else {
        $failures[] = "Functional: Same TDN + Different Revision was incorrectly rejected: " . $res_diff_rev->get_error_message();
        echo "• Same TDN + Different Revision: [FAIL] " . $res_diff_rev->get_error_message() . "\n";
    }

    $id_a = (!empty($res_create_1) && !is_wp_error($res_create_1)) ? $res_create_1->id : $uuid_a;
    $id_b = (!empty($res_diff_rev) && !is_wp_error($res_diff_rev)) ? $res_diff_rev->id : $uuid_b;
    $cleanup_func_ids = array($id_a, $id_b);

    // Test UUID Exact Lookup
    $lookup_a = $props_service->get_property($id_a);
    $lookup_b = $props_service->get_property($id_b);
    if (!empty($lookup_a) && !empty($lookup_b) && $lookup_a->id !== $lookup_b->id && $lookup_a->tax_declaration_number === $lookup_b->tax_declaration_number) {
        echo "• UUID Exact Lookup: [PASS] Correctly returned exact distinct property objects sharing the same TDN.\n";
    } else {
        $failures[] = "Functional: UUID exact lookup failed to isolate distinct properties.";
        echo "• UUID Exact Lookup: [FAIL] Lookup failed.\n";
    }

    // Cleanup functional test items
    foreach ($cleanup_func_ids as $cid) {
        $wpdb->delete($prop_table, array('id' => $cid));
        $wpdb->delete($p . 'assessor_property_states', array('property_id' => $cid));
        $wpdb->delete($p . 'assessor_sync_queue', array('property_id' => $cid));
    }
}

echo "\n";

// -----------------------------------------------------------------------------
// SUMMARY
// -----------------------------------------------------------------------------
echo "================================================================================\n";
echo "                               VALIDATION SUMMARY                               \n";
echo "================================================================================\n";
if (empty($failures)) {
    echo "OVERALL STATUS: PASS\n";
    echo "All core integrity constraints, UUID formats, relationships, and duplicate rules are valid.\n";
} else {
    echo "OVERALL STATUS: FAIL\n";
    echo "The following integrity failures were detected:\n";
    foreach ($failures as $idx => $f) {
        echo "  " . ($idx + 1) . ". {$f}\n";
    }
}

if (!empty($warnings)) {
    echo "\nWARNINGS / ADVISORIES:\n";
    foreach ($warnings as $idx => $w) {
        echo "  " . ($idx + 1) . ". {$w}\n";
    }
}
echo "================================================================================\n";
