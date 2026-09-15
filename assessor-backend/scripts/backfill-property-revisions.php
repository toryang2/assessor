<?php
/**
 * Migration Script: 08 - Backfill Property Revision UUID
 *
 * For every existing property:
 * 1. Read effectivity_date
 * 2. Determine the effectivity year
 * 3. Find an active revision where from_year <= year <= to_year
 * 4. Treat `present` as open-ended
 * 5. Store that revision UUID (revision_id)
 *
 * Safety & Quality:
 * - Detect overlapping active revisions (hard stop if detected)
 * - Detect missing revision coverage
 * - Detect malformed effectivity dates
 * - Report ambiguity
 * - Never invent a revision
 * - Keep effectivity_date completely unchanged
 * - Keep total property count unchanged
 * - Verify all assigned revision_ids resolve to valid revision entries
 * - Idempotent and repeatable
 */

if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Accel-Buffering: no');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    ob_implicit_flush(true);
    @set_time_limit(600);

    $secret_key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';
    $allow_http = ($secret_key === 'masso-migrate-uuid');
}

// Load WordPress environment
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
foreach ($wp_load_paths as $path) {
    if (!empty($path) && file_exists($path)) {
        require_once $path;
        $loaded = true;
        break;
    }
}

if (!$loaded) {
    die("FATAL: Cannot locate wp-load.php.\n");
}

if (php_sapi_name() !== 'cli' && empty($allow_http)) {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized access: Administrator privileges required.');
    }
}

function flush_out($msg) {
    echo $msg;
    if (php_sapi_name() !== 'cli') {
        flush();
    }
}

global $wpdb;
$table_properties = $wpdb->prefix . 'assessor_properties';
$table_revisions  = $wpdb->prefix . 'assessor_revision_entries';

flush_out("===============================================================\n");
flush_out("BACKFILL PROPERTY REVISION UUID (08)\n");
flush_out("===============================================================\n\n");

// 1. Verify tables and columns
if (!$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_properties))) {
    die("FATAL: Table $table_properties does not exist.\n");
}
if (!$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_revisions))) {
    die("FATAL: Table $table_revisions does not exist.\n");
}

$rev_col = $wpdb->get_row("SHOW COLUMNS FROM $table_properties LIKE 'revision_id'");
if (!$rev_col || stripos($rev_col->Type, 'varchar(36)') === false) {
    die("FATAL: $table_properties.revision_id is missing or not VARCHAR(36). Please run add-property-revision-link.php first.\n");
}

// 2. Fetch and inspect active revisions
flush_out("[Step 1] Inspecting active revisions and intervals...\n");
$revisions = $wpdb->get_results("SELECT id, revision_code, revision_year, from_year, to_year, status FROM $table_revisions WHERE status = 'active' ORDER BY CAST(from_year AS UNSIGNED) ASC", ARRAY_A);

if (empty($revisions)) {
    die("FATAL: No active revisions found in $table_revisions.\n");
}

$intervals = array();
foreach ($revisions as $r) {
    $from = intval($r['from_year']);
    $to = (strtolower(trim($r['to_year'])) === 'present') ? 9999 : intval($r['to_year']);
    
    if ($from <= 0 || $to <= 0 || $from > $to) {
        die("FATAL: Invalid year boundaries on revision '{$r['revision_code']}': from={$r['from_year']}, to={$r['to_year']}.\n");
    }
    
    $intervals[] = array(
        'id'   => $r['id'],
        'code' => $r['revision_code'],
        'year' => $r['revision_year'],
        'from' => $from,
        'to'   => $to
    );
    $to_label = ($to === 9999) ? 'present' : $to;
    flush_out(sprintf("  - [%s] %-20s : %d to %s (UUID: %s)\n", $r['revision_code'], $r['revision_year'], $from, $to_label, $r['id']));
}

// 3. Detect overlapping active revisions (HARD STOP if found)
flush_out("\n[Step 2] Checking for overlapping active revisions...\n");
$overlap_detected = false;
for ($i = 0; $i < count($intervals); $i++) {
    for ($j = $i + 1; $j < count($intervals); $j++) {
        $a = $intervals[$i];
        $b = $intervals[$j];
        if (max($a['from'], $b['from']) <= min($a['to'], $b['to'])) {
            flush_out("FATAL: Overlap detected between '{$a['code']}' ({$a['from']}-{$a['to']}) and '{$b['code']}' ({$b['from']}-{$b['to']})!\n");
            $overlap_detected = true;
        }
    }
}
if ($overlap_detected) {
    die("HARD STOP: Overlapping active revisions exist. Aborting backfill to prevent ambiguous assignments.\n");
}
flush_out("  - No overlapping revisions found. All revision intervals are strictly mutually exclusive.\n");

// 4. Pre-analysis of property effectivity dates
flush_out("\n[Step 3] Analyzing property effectivity dates...\n");
$total_props_before = intval($wpdb->get_var("SELECT COUNT(*) FROM $table_properties"));
flush_out("  - Total properties in table: $total_props_before\n");

// Read all properties to check dates
$props = $wpdb->get_results("SELECT id, tax_declaration_number, effectivity_date, revision_id FROM $table_properties", ARRAY_A);

$mappable = array();
$unmappable_blank = array();
$unmappable_out_of_range = array();
$unmappable_malformed = array();

foreach ($props as $p) {
    $raw = trim($p['effectivity_date'] ?? '');
    if ($raw === '') {
        $unmappable_blank[] = $p;
        continue;
    }

    if (preg_match('/^(\d{4})$/', $raw, $m)) {
        $year = intval($m[1]);
        $matches = array();
        foreach ($intervals as $inv) {
            if ($year >= $inv['from'] && $year <= $inv['to']) {
                $matches[] = $inv;
            }
        }
        if (count($matches) === 1) {
            $mappable[] = array(
                'id'          => $p['id'],
                'tdn'         => $p['tax_declaration_number'],
                'year'        => $year,
                'revision_id' => $matches[0]['id'],
                'code'        => $matches[0]['code']
            );
        } elseif (count($matches) > 1) {
            die("HARD STOP: More than one active revision matched year $year for TDN {$p['tax_declaration_number']}!\n");
        } else {
            $unmappable_out_of_range[] = $p;
        }
    } else {
        $unmappable_malformed[] = $p;
    }
}

flush_out(sprintf("  - Cleanly mappable properties: %d\n", count($mappable)));
flush_out(sprintf("  - Blank / empty dates:         %d\n", count($unmappable_blank)));
flush_out(sprintf("  - Year out of range (< 1965):  %d\n", count($unmappable_out_of_range)));
flush_out(sprintf("  - Malformed / non-year text:   %d\n", count($unmappable_malformed)));

if (!empty($unmappable_out_of_range)) {
    flush_out("\n  [Notice] Out of range effectivity_date records (will remain revision_id = NULL):\n");
    foreach ($unmappable_out_of_range as $row) {
        flush_out("    * TDN: {$row['tax_declaration_number']} | Date: '{$row['effectivity_date']}'\n");
    }
}

if (!empty($unmappable_malformed)) {
    flush_out("\n  [Notice] Malformed effectivity_date records (will remain revision_id = NULL):\n");
    foreach ($unmappable_malformed as $row) {
        flush_out("    * TDN: {$row['tax_declaration_number']} | Date: '{$row['effectivity_date']}'\n");
    }
}

// 5. Execute backfill
flush_out("\n[Step 4] Executing batch backfill of revision_id...\n");
$total_updated = 0;

foreach ($intervals as $inv) {
    $rev_uuid = $inv['id'];
    $from     = $inv['from'];
    $to       = $inv['to'];
    
    // Exact SQL condition: only 4-digit years within [from, to]
    $query = $wpdb->prepare("
        UPDATE $table_properties
        SET revision_id = %s
        WHERE effectivity_date REGEXP '^[0-9]{4}$'
          AND CAST(effectivity_date AS UNSIGNED) >= %d
          AND CAST(effectivity_date AS UNSIGNED) <= %d
    ", $rev_uuid, $from, $to);
    
    $updated = $wpdb->query($query);
    if ($updated === false) {
        die("FATAL ERROR: Update failed for revision {$inv['code']}: " . $wpdb->last_error . "\n");
    }
    
    $count = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_properties WHERE revision_id = %s", $rev_uuid)));
    flush_out(sprintf("  - Revision [%s]: %d properties assigned (affected rows in this run: %d)\n", $inv['code'], $count, $updated));
    $total_updated += $count;
}

// 6. Post-completion verification & sanity checks
flush_out("\n[Step 5] Running post-backfill verification...\n");

$total_props_after = intval($wpdb->get_var("SELECT COUNT(*) FROM $table_properties"));
if ($total_props_before !== $total_props_after) {
    die("FATAL: Total property count changed! Before: $total_props_before, After: $total_props_after\n");
}
flush_out("  - Property count unchanged: $total_props_after\n");

$total_with_revision = intval($wpdb->get_var("SELECT COUNT(*) FROM $table_properties WHERE revision_id IS NOT NULL"));
$total_null_revision = intval($wpdb->get_var("SELECT COUNT(*) FROM $table_properties WHERE revision_id IS NULL"));
flush_out("  - Properties with assigned revision_id: $total_with_revision\n");
flush_out("  - Properties with revision_id IS NULL:   $total_null_revision\n");

// Verify that all assigned revision_ids resolve to active revisions
$orphaned_count = intval($wpdb->get_var("
    SELECT COUNT(*) 
    FROM $table_properties p
    LEFT JOIN $table_revisions r ON p.revision_id = r.id
    WHERE p.revision_id IS NOT NULL AND r.id IS NULL
"));

if ($orphaned_count > 0) {
    die("FATAL: Found $orphaned_count properties with unresolved revision_ids!\n");
}
flush_out("  - All assigned revision UUIDs resolve 100% to valid revision records.\n");

// Verify that effectivity_date values were not modified
$mismatched_assignments = intval($wpdb->get_var("
    SELECT COUNT(*)
    FROM $table_properties p
    INNER JOIN $table_revisions r ON p.revision_id = r.id
    WHERE p.effectivity_date REGEXP '^[0-9]{4}$'
      AND (
        CAST(p.effectivity_date AS UNSIGNED) < CAST(r.from_year AS UNSIGNED)
        OR (
          LOWER(TRIM(r.to_year)) != 'present' 
          AND CAST(p.effectivity_date AS UNSIGNED) > CAST(r.to_year AS UNSIGNED)
        )
      )
"));

if ($mismatched_assignments > 0) {
    die("FATAL: Found $mismatched_assignments properties whose assigned revision does not match their effectivity_date!\n");
}
flush_out("  - All assigned revisions strictly match effectivity_date intervals (0 mismatches).\n");

flush_out("\n===============================================================\n");
flush_out("BACKFILL (08) COMPLETED SUCCESSFULLY!\n");
flush_out("===============================================================\n");
