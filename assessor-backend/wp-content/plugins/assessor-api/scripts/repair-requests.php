<?php
/**
 * Diagnostic & Repair Tool for Assessor Requests
 * 
 * Inspects `wp_assessor_requests`, checks its `property_id` values,
 * compares them against `wp_assessor_properties`, `wp_assessor_property_versions`,
 * `wp_assessor_id_uuid_map`, and repairs the linkage.
 */

// Streaming headers
header('Content-Type: text/plain; charset=utf-8');
header('X-Accel-Buffering: no');
header('Cache-Control: no-cache, no-store, must-revalidate');

while (ob_get_level() > 0) {
    ob_end_flush();
}
ob_implicit_flush(true);
@set_time_limit(300);

function out($text) {
    echo $text;
    @ob_flush();
    flush();
}

$wp_load_paths = array(
    'C:/xampp/htdocs/wp-load.php',
    '/home/u799325560/domains/archive.massokitaotao.net/public_html/wp-load.php',
    __DIR__ . '/../../../../wp-load.php',
    __DIR__ . '/../../../wp-load.php',
    __DIR__ . '/../../wp-load.php',
    dirname(__FILE__) . '/../wp-load.php',
    $_SERVER['DOCUMENT_ROOT'] . '/wp-load.php'
);

$loaded = false;
foreach ($wp_load_paths as $p) {
    if (!empty($p) && file_exists($p)) {
        require_once $p;
        $loaded = true;
        break;
    }
}
if (!$loaded) {
    die("FATAL: Cannot locate wp-load.php\n");
}

// Security: check query key or admin
$key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';
if ($key !== 'masso-migrate-uuid' && !current_user_can('manage_options')) {
    die("ACCESS DENIED: Pass ?key=masso-migrate-uuid or log in as Administrator.\n");
}

global $wpdb;
$wpdb->show_errors(false);

$table_requests   = $wpdb->prefix . 'assessor_requests';
$table_properties = $wpdb->prefix . 'assessor_properties';
$table_versions   = $wpdb->prefix . 'assessor_property_versions';
$table_map        = $wpdb->prefix . 'assessor_id_uuid_map';
$table_audit      = $wpdb->prefix . 'assessor_audit_trail';

out("=======================================================\n");
out("ASSESSOR REQUESTS DIAGNOSTIC & REPAIR UTILITY\n");
out("=======================================================\n\n");

// 1. Check table existence
out("[1] Checking tables and schemas...\n");
$requests_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_requests'");
if (!$requests_exists) {
    die("ERROR: Table $table_requests does not exist!\n");
}

// 2. Count total requests and analyze property_id types
out("\n[2] Analyzing requests records...\n");
$total_requests = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_requests");
out("  Total requests in database: $total_requests\n");

if ($total_requests === 0) {
    out("  Table $table_requests is currently empty.\n");
    exit(0);
}

$all_requests = $wpdb->get_results("SELECT * FROM $table_requests ORDER BY id ASC", ARRAY_A);

// Check linked vs unlinked
$linked_count = (int) $wpdb->get_var("
    SELECT COUNT(*) FROM $table_requests r
    JOIN $table_properties p ON r.property_id = p.id
");
$null_property_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_requests WHERE property_id IS NULL OR property_id = ''");
$numeric_property_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_requests WHERE property_id REGEXP '^[0-9]+$'");
$uuid_property_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_requests WHERE property_id REGEXP '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$'");

out("  - Successfully linked to active properties: $linked_count / $total_requests\n");
out("  - Rows with NULL / empty property_id: $null_property_count\n");
out("  - Rows with numeric (old integer) property_id: $numeric_property_count\n");
out("  - Rows with UUID format property_id: $uuid_property_count\n");

// 3. Inspect sample request rows
out("\n[3] Inspecting sample request rows:\n");
$samples = $wpdb->get_results("
    SELECT r.id, r.receipt_number, r.client_name, r.property_id, r.date_issued,
           p.tax_declaration_number, p.declarant_last_name
    FROM $table_requests r
    LEFT JOIN $table_properties p ON r.property_id = p.id
    ORDER BY r.id DESC
    LIMIT 5
", ARRAY_A);

foreach ($samples as $s) {
    $match = !empty($s['tax_declaration_number']) ? "MATCHED (TDN: {$s['tax_declaration_number']})" : "NOT LINKED";
    out("  ID: {$s['id']} | Receipt: {$s['receipt_number']} | PropID: '{$s['property_id']}' -> $match\n");
}

// 4. Scanning available recovery sources
out("\n[4] Scanning available recovery sources...\n");

// Source A: Check mapping table
$has_map = $wpdb->get_var("SHOW TABLES LIKE '$table_map'");
if ($has_map) {
    $map_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_map");
    out("  [SOURCE A] Found map table $table_map with $map_count mapped pairs.\n");
    $match_old = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_requests r JOIN $table_map m ON r.property_id = m.old_id");
    $match_new = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_requests r JOIN $table_map m ON r.property_id = m.new_uuid");
    out("    - Requests matching map.old_id: $match_old / $total_requests\n");
    out("    - Requests matching map.new_uuid: $match_new / $total_requests\n");
    
    // Sample map entries
    $sample_map = $wpdb->get_results("SELECT old_id, new_uuid FROM $table_map LIMIT 3", ARRAY_A);
    foreach ($sample_map as $sm) {
        out("      Sample map: old_id '{$sm['old_id']}' -> new_uuid '{$sm['new_uuid']}'\n");
    }
} else {
    out("  [SOURCE A] Map table $table_map does not exist.\n");
}

// Source B: Check property versions table
$has_versions = $wpdb->get_var("SHOW TABLES LIKE '$table_versions'");
if ($has_versions) {
    $ver_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_versions");
    out("  [SOURCE B] Found property versions table $table_versions with $ver_count rows.\n");
    
    // Check if any request.property_id matches versions.property_id
    $ver_matches = (int) $wpdb->get_var("
        SELECT COUNT(DISTINCT r.id) FROM $table_requests r
        JOIN $table_versions v ON r.property_id = v.property_id
    ");
    out("    - Requests matching versions.property_id: $ver_matches / $total_requests\n");
    
    // Check sample version IDs
    $sample_vers = $wpdb->get_results("SELECT property_id, tax_declaration_number FROM $table_versions LIMIT 3", ARRAY_A);
    foreach ($sample_vers as $sv) {
        out("      Sample version: property_id '{$sv['property_id']}' -> TDN '{$sv['tax_declaration_number']}'\n");
    }
} else {
    out("  [SOURCE B] Versions table does not exist.\n");
}

// Source C: Check properties table directly
out("\n[4b] Checking wp_assessor_properties status:\n");
$prop_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_properties");
$prop_id_col = $wpdb->get_row("SHOW COLUMNS FROM $table_properties LIKE 'id'");
out("  - Total rows in $table_properties: $prop_count\n");
out("  - Column $table_properties.id type: " . ($prop_id_col ? $prop_id_col->Type : 'unknown') . "\n");

$sample_prop_ids = $wpdb->get_results("SELECT id, tax_declaration_number FROM $table_properties LIMIT 3", ARRAY_A);
foreach ($sample_prop_ids as $sp) {
    out("      Sample property: ID '{$sp['id']}' | TDN '{$sp['tax_declaration_number']}'\n");
}

// Check all property-like tables in DB
$all_prop_tables = $wpdb->get_col("SHOW TABLES LIKE '%assessor%'");
out("  - All assessor tables in DB: " . implode(', ', $all_prop_tables) . "\n");

// 5. Detailed breakdown of all 39 requests
out("\n[5] Detailed breakdown of all $total_requests requests in database:\n");
foreach ($all_requests as $idx => $r) {
    $num = $idx + 1;
    out("  #{$num} [ID: {$r['id']}] Receipt: '{$r['receipt_number']}' | Client: '{$r['client_name']}' | PropID: '{$r['property_id']}' | Remarks: '{$r['remarks']}' | Date: '{$r['date_issued']}'\n");
}

// 6. Execute Repair / Recovery
$action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';

if ($action === 'repair') {
    out("\n[6] Executing repair...\n");

    $repaired = 0;

    // Strategy 1: Remap via versions table (if versions retained the old UUID and we can find the property by TDN)
    if ($has_versions) {
        $recovered_via_ver = $wpdb->query("
            UPDATE $table_requests r
            JOIN $table_versions v ON r.property_id = v.property_id
            JOIN $table_properties p ON v.tax_declaration_number = p.tax_declaration_number
            SET r.property_id = p.id
        ");
        if ($recovered_via_ver > 0) {
            out("  - [Strategy 1] Successfully re-linked $recovered_via_ver requests via versions & TDN matching!\n");
            $repaired += $recovered_via_ver;
        }
    }

    // Strategy 2: Remap via map table if old_id matches request property_id
    if ($has_map) {
        $rep_old = $wpdb->query("
            UPDATE $table_requests r
            JOIN $table_map m ON r.property_id = m.old_id
            SET r.property_id = m.new_uuid
        ");
        if ($rep_old > 0) {
            out("  - [Strategy 2] Successfully updated $rep_old requests by joining with map.old_id!\n");
            $repaired += $rep_old;
        }
    }

    // Strategy 3: Remarks TDN regex or Client Name matching
    out("  - [Strategy 3] Inspecting unlinked requests for client name / TDN matching...\n");
    $unlinked_requests = $wpdb->get_results("
        SELECT r.* FROM $table_requests r
        LEFT JOIN $table_properties p ON r.property_id = p.id
        WHERE p.id IS NULL
    ", ARRAY_A);

    foreach ($unlinked_requests as $r) {
        $found_prop_id = null;

        // Check if remarks contains TDN pattern (e.g. 04-0001-...)
        if (!empty($r['remarks']) && preg_match('/([0-9]{2,4}-[0-9\-]+)/', $r['remarks'], $matches)) {
            $tdn = trim($matches[1]);
            $found_prop_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table_properties WHERE tax_declaration_number = %s AND status != 'deleted' LIMIT 1",
                $tdn
            ));
            if ($found_prop_id) {
                out("    [TDN in Remarks] Request #{$r['id']} matched TDN '$tdn' -> Prop UUID $found_prop_id\n");
            }
        }

        // Check client_name against declarant name (exclude generic government agency names like DPWH or Assessor OIC)
        $agency_names = array('DPWH', 'OIC', 'ASSISTANT REGIONAL DIRECTOR', 'ROXAS');
        $is_agency = false;
        foreach ($agency_names as $ag) {
            if (stripos($r['client_name'], $ag) !== false) {
                $is_agency = true;
                break;
            }
        }

        if (!$found_prop_id && !$is_agency && !empty($r['client_name'])) {
            $client = trim($r['client_name']);
            // Try matching client name against declarant
            $found_prop_id = $wpdb->get_var($wpdb->prepare("
                SELECT id FROM $table_properties
                WHERE status != 'deleted'
                  AND (
                      CONCAT_WS(' ', declarant_first_name, declarant_last_name) LIKE %s
                      OR CONCAT_WS(' ', declarant_first_name, declarant_middle_initial, declarant_last_name) LIKE %s
                      OR CONCAT_WS(', ', declarant_last_name, declarant_first_name) LIKE %s
                      OR declarant_last_name = %s
                  )
                ORDER BY created_at DESC
                LIMIT 1
            "), '%' . $client . '%', '%' . $client . '%', '%' . $client . '%', $client);

            if ($found_prop_id) {
                out("    [Client Name Match] Request #{$r['id']} ('{$client}') -> Prop UUID $found_prop_id\n");
            }
        }

        if ($found_prop_id) {
            $wpdb->update($table_requests, array('property_id' => $found_prop_id), array('id' => $r['id']), array('%s'), array('%d'));
            $repaired++;
        }
    }

    // Final validation
    $final_linked = (int) $wpdb->get_var("
        SELECT COUNT(*) FROM $table_requests r
        JOIN $table_properties p ON r.property_id = p.id
    ");
    out("\n>>> Result after repair: $final_linked / $total_requests requests successfully linked to active properties! <<<\n");

} else {
    out("\n=======================================================\n");
    out("To run the automated repair / re-linkage, add &action=repair to the URL:\n");
    out("  ?key=masso-migrate-uuid&action=repair\n");
    out("=======================================================\n");
}
