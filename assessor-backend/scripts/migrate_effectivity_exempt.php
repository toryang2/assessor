<?php
/**
 * Migration Script: Add effectivity_exempt column and migrate legacy values
 *
 * Adds:
 * effectivity_exempt TINYINT(1) NOT NULL DEFAULT 0
 * to both:
 * {$wpdb->prefix}assessor_properties
 * {$wpdb->prefix}assessor_property_versions
 *
 * Requirements:
 * 1. Safe WordPress bootstrap
 * 2. Dynamic table prefix ($wpdb->prefix)
 * 3. Verify tables exist
 * 4. Add column if missing (AFTER effectivity_date)
 * 5. Report if already exists
 * 6. SHOW COLUMNS verification: table, type, null, default, result
 * 7. Migrate legacy values:
 *    - effectivity_date = 'EXEMPT' and effectivity_exempt = 0 -> effectivity_date = NULL, effectivity_exempt = 1
 *    - effectivity_date = '' and effectivity_exempt = 0 -> effectivity_date = NULL, effectivity_exempt = 0
 *    - Report any unexpected non-4-digit year values
 * 8. Safe for repeated runs
 */

if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Accel-Buffering: no');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    ob_implicit_flush(true);
    @set_time_limit(300);

    $secret_key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';
    $allow_http = ($secret_key === 'masso-migrate-uuid');
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
foreach ($wp_load_paths as $path) {
    if (!empty($path) && file_exists($path)) {
        require_once $path;
        $loaded = true;
        break;
    }
}

if (!$loaded) {
    die("FATAL: Cannot locate wp-load.php\n");
}

global $wpdb;

echo "==================================================\n";
echo "Starting migration: effectivity_exempt column\n";
echo "Database prefix: " . $wpdb->prefix . "\n";
echo "==================================================\n\n";

$tables = array(
    $wpdb->prefix . 'assessor_properties',
    $wpdb->prefix . 'assessor_property_versions'
);

foreach ($tables as $table) {
    echo "--- Checking table: $table ---\n";

    // 1. Verify table exists
    $table_check = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
    if (!$table_check) {
        echo "WARNING: Table $table does not exist. Skipping.\n\n";
        continue;
    }

    // 2. Check if effectivity_exempt already exists
    $column_exists = $wpdb->get_results(
        $wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'effectivity_exempt')
    );

    if (empty($column_exists)) {
        echo "Column effectivity_exempt does NOT exist in $table. Adding column...\n";
        $alter_sql = "ALTER TABLE `{$table}` ADD COLUMN `effectivity_exempt` TINYINT(1) NOT NULL DEFAULT 0 AFTER `effectivity_date`";
        $result = $wpdb->query($alter_sql);
        if ($result === false) {
            echo "ERROR adding column to $table: " . $wpdb->last_error . "\n";
        } else {
            echo "SUCCESS: Added effectivity_exempt to $table.\n";
        }
    } else {
        echo "Column effectivity_exempt ALREADY EXISTS in $table. No schema modification needed.\n";
    }

    // 3. SHOW COLUMNS verification
    $columns = $wpdb->get_results("SHOW COLUMNS FROM `{$table}`", ARRAY_A);
    echo "Columns in $table around effectivity:\n";
    foreach ($columns as $col) {
        if (strpos($col['Field'], 'effectivity') !== false) {
            echo sprintf(
                "  Table: %s | Field: %s | Type: %s | Null: %s | Default: %s | Extra: %s\n",
                $table,
                $col['Field'],
                $col['Type'],
                $col['Null'],
                var_export($col['Default'], true),
                $col['Extra']
            );
        }
    }
    echo "\n";
}

// Migrate legacy records in wp_assessor_properties
$props_table = $wpdb->prefix . 'assessor_properties';
$table_check = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $props_table));
if ($table_check) {
    echo "--- Checking legacy effectivity data in $props_table ---\n";

    // A. Migrate 'EXEMPT' string
    $exempt_count = $wpdb->get_var("SELECT COUNT(*) FROM `{$props_table}` WHERE LOWER(TRIM(effectivity_date)) = 'exempt'");
    echo "Found $exempt_count records with effectivity_date = 'EXEMPT'.\n";
    if ($exempt_count > 0) {
        $updated = $wpdb->query(
            "UPDATE `{$props_table}` SET effectivity_date = NULL, effectivity_exempt = 1 WHERE LOWER(TRIM(effectivity_date)) = 'exempt'"
        );
        echo "Updated $updated records to effectivity_date = NULL, effectivity_exempt = 1.\n";
    }

    // B. Migrate empty string '' to NULL (effectivity_exempt = 0)
    $empty_count = $wpdb->get_var("SELECT COUNT(*) FROM `{$props_table}` WHERE effectivity_date = '' AND effectivity_exempt = 0");
    echo "Found $empty_count records with effectivity_date = '' (empty string).\n";
    if ($empty_count > 0) {
        $updated = $wpdb->query(
            "UPDATE `{$props_table}` SET effectivity_date = NULL WHERE effectivity_date = '' AND effectivity_exempt = 0"
        );
        echo "Updated $updated records to effectivity_date = NULL.\n";
    }

    // C. Inspect any unexpected values (not NULL, not 4 digits)
    $unexpected = $wpdb->get_results(
        "SELECT id, tax_declaration_number, effectivity_date, effectivity_exempt FROM `{$props_table}` 
         WHERE effectivity_date IS NOT NULL AND effectivity_date NOT REGEXP '^[0-9]{4}$' LIMIT 50",
        ARRAY_A
    );
    if (!empty($unexpected)) {
        echo "WARNING: Found " . count($unexpected) . " records with unexpected non-4-digit effectivity_date values:\n";
        foreach ($unexpected as $un) {
            echo "  ID: {$un['id']} | TDN: {$un['tax_declaration_number']} | Date: '{$un['effectivity_date']}' | Exempt: {$un['effectivity_exempt']}\n";
        }
    } else {
        echo "No unexpected non-4-digit effectivity_date strings found.\n";
    }
}

// Migrate legacy records in wp_assessor_property_versions
$vers_table = $wpdb->prefix . 'assessor_property_versions';
$vers_check = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $vers_table));
if ($vers_check) {
    echo "\n--- Checking legacy effectivity data in $vers_table ---\n";
    $v_exempt_count = $wpdb->get_var("SELECT COUNT(*) FROM `{$vers_table}` WHERE LOWER(TRIM(effectivity_date)) = 'exempt'");
    echo "Found $v_exempt_count version records with effectivity_date = 'EXEMPT'.\n";
    if ($v_exempt_count > 0) {
        $v_updated = $wpdb->query(
            "UPDATE `{$vers_table}` SET effectivity_date = NULL, effectivity_exempt = 1 WHERE LOWER(TRIM(effectivity_date)) = 'exempt'"
        );
        echo "Updated $v_updated version records to effectivity_date = NULL, effectivity_exempt = 1.\n";
    }

    $v_empty_count = $wpdb->get_var("SELECT COUNT(*) FROM `{$vers_table}` WHERE effectivity_date = '' AND effectivity_exempt = 0");
    echo "Found $v_empty_count version records with effectivity_date = '' (empty string).\n";
    if ($v_empty_count > 0) {
        $v_updated = $wpdb->query(
            "UPDATE `{$vers_table}` SET effectivity_date = NULL WHERE effectivity_date = '' AND effectivity_exempt = 0"
        );
        echo "Updated $v_updated version records to effectivity_date = NULL.\n";
    }
}

echo "\nMigration script completed successfully.\n";
