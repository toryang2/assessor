<?php
/**
 * Script to reconcile property states (CURRENT vs CANCELLED)
 * based on previous_tax_declaration_number references.
 * 
 * HOSTINGER / PRODUCTION READY VERSION (Batched)
 * Usage: Place this file in your WordPress root directory (where wp-config.php and wp-load.php live)
 * and visit it via your browser: https://yourdomain.com/fix_property_states.php
 */

// Extend timeout just in case
set_time_limit(300);

// Attempt to load WordPress
$wp_load = dirname(__FILE__) . '/wp-load.php';
    if (file_exists($wp_load)) {
        require_once $wp_load;
    } else {
        $wp_load = 'C:/xampp/htdocs/wp-load.php';
        if (file_exists($wp_load)) {
            require_once $wp_load;
        } else {
            die("Error: Could not find wp-load.php. Please place this script in the root directory of your WordPress installation.\n");
        }
    }

// Determine if running via CLI
$is_cli = (php_sapi_name() === 'cli');

if (!$is_cli && !current_user_can('manage_options')) {
    // Basic security to prevent unauthorized running via web browser. Make sure you are logged in as admin!
    die("You must be logged in as an administrator to run this script.");
}

global $wpdb;

if (!$is_cli) {
    echo "<pre>";
}
echo "Starting property state reconciliation...\n\n";

$table_properties = $wpdb->prefix . 'assessor_properties';
$table_property_states = $wpdb->prefix . 'assessor_property_states';

$batch_size = $is_cli ? 9999999 : 500; // Process all if CLI, batch if Web
$offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
$fixed_cancelled_count = isset($_GET['cancelled']) ? intval($_GET['cancelled']) : 0;
$fixed_current_count = isset($_GET['current']) ? intval($_GET['current']) : 0;

// Get a batch of active properties
$properties = $wpdb->get_results($wpdb->prepare(
    "SELECT id, tax_declaration_number FROM $table_properties WHERE status != 'deleted' ORDER BY id ASC LIMIT %d OFFSET %d",
    $batch_size, 
    $offset
));

if (empty($properties)) {
    echo "\nReconciliation FULLY COMPLETE!\n";
    echo "========================================\n";
    echo "- Total properties fixed to CANCELLED: $fixed_cancelled_count\n";
    echo "- Total properties reverted to CURRENT: $fixed_current_count\n";
    if (!$is_cli) echo "</pre>";
    die();
}

$processed_in_batch = 0;

foreach ($properties as $property) {
    $processed_in_batch++;
    
    // Check if this property is superseded by any other active property
    // that is either CURRENT or CANCELLED (ignoring INTERIM/PENDING)
    $is_superseded = $wpdb->get_var($wpdb->prepare(
        "SELECT p.id FROM $table_properties p
         LEFT JOIN $table_property_states ps ON p.id = ps.property_id
         WHERE FIND_IN_SET(%s, REPLACE(p.previous_tax_declaration_number, ';', ',')) > 0
         AND p.status != 'deleted' AND p.id != %d 
         AND COALESCE(ps.state, 'CURRENT') IN ('CURRENT', 'CANCELLED') LIMIT 1",
        $property->tax_declaration_number,
        $property->id
    ));

    // Get current explicit state (or default to CURRENT)
    $current_state_record = $wpdb->get_var($wpdb->prepare(
        "SELECT state FROM $table_property_states WHERE property_id = %d",
        $property->id
    ));
    $actual_state = $current_state_record ? strtoupper($current_state_record) : 'CURRENT';

    if ($is_superseded) {
        // It SHOULD be CANCELLED
        if ($actual_state === 'CURRENT') {
            $wpdb->replace(
                $table_property_states,
                [
                    'property_id' => $property->id,
                    'state' => 'CANCELLED',
                    'updated_by' => 1, // Admin user ID
                    'updated_at' => current_time('mysql')
                ],
                ['%d', '%s', '%d', '%s']
            );
            
            if (class_exists('Assessor_Sync')) {
                Assessor_Sync::enqueue_property($property->id, 'upsert');
            }

            echo "FIXED: Property ID {$property->id} (TDN: {$property->tax_declaration_number}) -> Changed to CANCELLED\n";
            $fixed_cancelled_count++;
        }
    } else {
        // It SHOULD be CURRENT (if it was incorrectly cancelled)
        if ($actual_state === 'CANCELLED') {
            $wpdb->replace(
                $table_property_states,
                [
                    'property_id' => $property->id,
                    'state' => 'CURRENT',
                    'updated_by' => 1, // Admin user ID
                    'updated_at' => current_time('mysql')
                ],
                ['%d', '%s', '%d', '%s']
            );
            
            if (class_exists('Assessor_Sync')) {
                Assessor_Sync::enqueue_property($property->id, 'upsert');
            }

            echo "FIXED: Property ID {$property->id} (TDN: {$property->tax_declaration_number}) -> Reverted to CURRENT\n";
            $fixed_current_count++;
        }
    }
}

$new_offset = $offset + $processed_in_batch;

if ($is_cli) {
    echo "\nReconciliation FULLY COMPLETE!\n";
    echo "========================================\n";
    echo "- Total properties fixed to CANCELLED: $fixed_cancelled_count\n";
    echo "- Total properties reverted to CURRENT: $fixed_current_count\n";
} else {
    $url = "?offset=" . $new_offset . "&cancelled=" . $fixed_cancelled_count . "&current=" . $fixed_current_count;
    echo "Processed records up to offset: $new_offset. Moving to next batch...\n";
    echo "</pre>";
    echo "<script>setTimeout(function() { window.location.href = '$url'; }, 1000);</script>";
}