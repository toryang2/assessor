<?php
/**
 * Safe Backfill Script: Populate missing discrete display fields in assessor_sync_run_items
 * 
 * Rules:
 * - Only affects record_type = 'property'
 * - Looks up the local assessor_properties record by UUID/ID
 * - Populates declarant_last_name, declarant_first_name, declarant_middle_initial,
 *   business_name, assessed_value, assessed_value_old if missing or null in display_data
 * - Safe & idempotent: never overwrites existing non-empty values
 * - Supports CLI dry-run: --dry-run
 */

require_once 'C:/xampp/htdocs/wp-load.php';
global $wpdb;

$is_dry_run = in_array('--dry-run', $argv ?? []);

echo "========================================================\n";
echo "Safe Backfill: assessor_sync_run_items display fields\n";
echo "Mode: " . ($is_dry_run ? "DRY RUN (No updates committed)" : "LIVE EXECUTION") . "\n";
echo "========================================================\n\n";

$table_items = $wpdb->prefix . 'assessor_sync_run_items';
$table_props = $wpdb->prefix . 'assessor_properties';

// Check table exists
if ($wpdb->get_var("SHOW TABLES LIKE '{$table_items}'") !== $table_items) {
    echo "Error: Table {$table_items} does not exist.\n";
    exit(1);
}

// Find all property items
$items = $wpdb->get_results("
    SELECT id, run_id, record_id, display_data 
    FROM {$table_items} 
    WHERE record_type = 'property'
");

if (empty($items)) {
    echo "No property sync run items found.\n";
    exit(0);
}

echo "Found " . count($items) . " property sync run items to inspect.\n\n";

$updated_count = 0;
$skipped_count = 0;
$not_found_count = 0;

foreach ($items as $item) {
    $data = json_decode($item->display_data, true);
    if (!is_array($data)) {
        $data = [];
    }

    // Check if backfill is needed
    $needs_backfill = false;
    if (empty($data['declarant_last_name']) && empty($data['declarant_first_name']) && empty($data['business_name'])) {
        $needs_backfill = true;
    }
    if (!array_key_exists('assessed_value', $data) || $data['assessed_value'] === null) {
        $needs_backfill = true;
    }

    if (!$needs_backfill) {
        $skipped_count++;
        continue;
    }

    // Fetch corresponding property from assessor_properties
    $prop = $wpdb->get_row($wpdb->prepare("
        SELECT 
            declarant_last_name, 
            declarant_first_name, 
            declarant_middle_initial, 
            business_name, 
            assessed_value, 
            assessed_value_old,
            tax_declaration_number,
            location,
            pin,
            status
        FROM {$table_props}
        WHERE id = %s
    ", $item->record_id));

    if (!$prop) {
        $not_found_count++;
        continue;
    }

    $modified = false;

    // Declarant parts
    if (empty($data['declarant_last_name']) && !empty($prop->declarant_last_name)) {
        $data['declarant_last_name'] = $prop->declarant_last_name;
        $modified = true;
    }
    if (empty($data['declarant_first_name']) && !empty($prop->declarant_first_name)) {
        $data['declarant_first_name'] = $prop->declarant_first_name;
        $modified = true;
    }
    if (empty($data['declarant_middle_initial']) && !empty($prop->declarant_middle_initial)) {
        $data['declarant_middle_initial'] = $prop->declarant_middle_initial;
        $modified = true;
    }

    // Business name
    if (empty($data['business_name']) && !empty($prop->business_name)) {
        $data['business_name'] = $prop->business_name;
        $modified = true;
    }

    // Assessed value
    if ((!array_key_exists('assessed_value', $data) || $data['assessed_value'] === null) && $prop->assessed_value !== null) {
        $data['assessed_value'] = $prop->assessed_value;
        $modified = true;
    }
    if ((!array_key_exists('assessed_value_old', $data) || $data['assessed_value_old'] === null) && $prop->assessed_value_old !== null) {
        $data['assessed_value_old'] = $prop->assessed_value_old;
        $modified = true;
    }

    // Reconstruct owner_name cleanly if needed
    $last = $data['declarant_last_name'] ?? '';
    $first = $data['declarant_first_name'] ?? '';
    $mi = $data['declarant_middle_initial'] ?? '';
    if (($last || $first) && empty($data['owner_name'])) {
        $mi_clean = trim(str_replace('.', '', $mi));
        $mi_formatted = $mi_clean !== '' ? (strlen($mi_clean) === 1 ? " {$mi_clean}." : " {$mi_clean}") : '';
        $data['owner_name'] = trim(($last ? "{$last}" : "") . (($last && $first) ? ", {$first}" : ($first ? "{$first}" : "")) . $mi_formatted);
        $modified = true;
    }

    if ($modified) {
        if (!$is_dry_run) {
            $wpdb->update(
                $table_items,
                ['display_data' => json_encode($data)],
                ['id' => $item->id]
            );
        }
        $updated_count++;
    } else {
        $skipped_count++;
    }
}

echo "Summary:\n";
echo " - Total property items: " . count($items) . "\n";
echo " - Backfilled items:     {$updated_count}\n";
echo " - Skipped (complete):   {$skipped_count}\n";
echo " - Properties not found: {$not_found_count}\n";
echo "\nDone!\n";
