<?php
/**
 * 02-migrate-lookup-tables-to-uuid-v7.php
 *
 * Migration Script: Migrate Assessor Lookup Tables Primary Keys to UUID v7
 *
 * Tables:
 * 1. assessor_property_types
 * 2. assessor_general_classes
 * 3. assessor_locations
 * 4. assessor_request_purposes
 *
 * Operations:
 * 1. Creates full table backups (assessor_{table}_backup_{timestamp})
 * 2. Creates persistent map table (assessor_lookup_id_uuid_map)
 * 3. Safely converts primary keys to VARCHAR(36) UUID v7 (RFC 9562)
 * 4. Preserves all existing records, fields, timestamps, and business keys
 * 5. Verifies row counts, uniqueness, and UUID v7 validity
 * 6. Idempotent and safe to retry without re-generating UUIDs
 */

// Enable full error display for debugging
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Accel-Buffering: no');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    ob_implicit_flush(true);
    @set_time_limit(600);
}

// Load WordPress environment
$wp_load_paths = array(
    'C:/xampp/htdocs/wp-load.php',
    '/home/u799325560/domains/archive.massokitaotao.net/public_html/wp-load.php',
    __DIR__ . '/../../../../../../wp-load.php',
    __DIR__ . '/../../../../../wp-load.php',
    __DIR__ . '/../../../../wp-load.php',
    __DIR__ . '/../../../wp-load.php',
    isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] . '/wp-load.php' : ''
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
    die("FATAL: Cannot locate wp-load.php. Please run within WordPress environment.\n");
}

// Authorization check (WordPress is now loaded, so sanitize_text_field and wp_die are defined)
if (php_sapi_name() !== 'cli') {
    $secret_key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';
    $allow_http = ($secret_key === 'masso-migrate-uuid');

    if (empty($allow_http) && !current_user_can('manage_options')) {
        if (function_exists('wp_die')) {
            wp_die('Unauthorized access: Administrator privileges required, or provide ?key=masso-migrate-uuid.');
        } else {
            die("Unauthorized access: Administrator privileges required, or provide ?key=masso-migrate-uuid.\n");
        }
    }
}

// Ensure Assessor_UUID is available
if (!class_exists('Assessor_UUID')) {
    $uuid_file = dirname(__DIR__, 2) . '/includes/class-assessor-uuid.php';
    if (file_exists($uuid_file)) {
        require_once $uuid_file;
    } else {
        die("FATAL: Cannot locate class-assessor-uuid.php\n");
    }
}

function flush_out($msg) {
    echo $msg;
    if (php_sapi_name() !== 'cli') {
        flush();
    }
}

function run_query($sql, $desc = '') {
    global $wpdb;
    $res = $wpdb->query($sql);
    if ($res === false) {
        flush_out("FATAL ERROR during '$desc': " . $wpdb->last_error . "\nSQL: $sql\n");
        exit(1);
    }
    return $res;
}

global $wpdb;
$wpdb->show_errors();

flush_out("===============================================================\n");
flush_out("STEP 02: ASSESSOR LOOKUP TABLES UUID v7 MIGRATION\n");
flush_out("===============================================================\n");
flush_out("Timestamp: " . date('Y-m-d H:i:s') . "\n\n");

$table_map = $wpdb->prefix . 'assessor_lookup_id_uuid_map';
$charset_collate = $wpdb->get_charset_collate();

// Step 1: Ensure persistent lookup ID -> UUID map exists
flush_out("[Step 1] Creating/verifying persistent mapping table: $table_map...\n");
run_query("
    CREATE TABLE IF NOT EXISTS $table_map (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        table_name VARCHAR(64) NOT NULL,
        old_id INT NOT NULL,
        new_uuid VARCHAR(36) NOT NULL,
        business_key VARCHAR(150) NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_table_old_id (table_name, old_id),
        UNIQUE KEY uq_table_new_uuid (table_name, new_uuid),
        KEY idx_table_business (table_name, business_key)
    ) $charset_collate;
", "Create lookup mapping table");
flush_out("  - Mapping table verified.\n\n");

$tables_to_migrate = array(
    'assessor_property_types'   => 'code',
    'assessor_general_classes'  => 'code',
    'assessor_locations'        => 'code',
    'assessor_request_purposes' => 'purpose',
);

$migration_summary = array();

foreach ($tables_to_migrate as $table_suffix => $business_key_col) {
    $full_table = $wpdb->prefix . $table_suffix;
    flush_out("---------------------------------------------------------------\n");
    flush_out("Processing Table: $full_table\n");
    flush_out("---------------------------------------------------------------\n");

    // Check table existence
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $full_table));
    if (!$exists) {
        flush_out("  - FATAL: Table $full_table does not exist. Aborting.\n");
        exit(1);
    }

    $id_col = $wpdb->get_row("SHOW COLUMNS FROM $full_table LIKE 'id'");
    if (!$id_col) {
        flush_out("  - FATAL: Table $full_table has no 'id' column. Aborting.\n");
        exit(1);
    }

    $is_already_varchar = (stripos($id_col->Type, 'varchar') !== false || stripos($id_col->Type, 'char') !== false);
    flush_out("  - Current id column type: {$id_col->Type}\n");

    $initial_rows = $wpdb->get_results("SELECT * FROM $full_table", ARRAY_A);
    $initial_count = count($initial_rows);
    flush_out("  - Row count: $initial_count\n");

    // Step A: Backup
    $backup_table = $full_table . '_backup_' . date('Ymd_His');
    flush_out("  - Creating backup table: $backup_table...\n");
    run_query("CREATE TABLE $backup_table LIKE $full_table", "Create backup table for $table_suffix");
    if ($initial_count > 0) {
        run_query("INSERT INTO $backup_table SELECT * FROM $full_table", "Populate backup table for $table_suffix");
    }
    $backup_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $backup_table");
    if ($backup_count !== $initial_count) {
        flush_out("  - FATAL: Backup verification failed! Expected $initial_count rows, found $backup_count.\n");
        exit(1);
    }
    flush_out("  - Backup created successfully ($backup_count rows).\n");

    // Step B: Populate mapping table
    flush_out("  - Reconciling persistent UUID mappings...\n");
    foreach ($initial_rows as $row) {
        $curr_id = $row['id'];
        $biz_key = isset($row[$business_key_col]) ? (string)$row[$business_key_col] : '';
        $is_uuid = Assessor_UUID::is_valid($curr_id);

        if ($is_uuid) {
            $map_entry = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_map WHERE table_name = %s AND new_uuid = %s",
                $table_suffix, $curr_id
            ), ARRAY_A);
        } else {
            $map_entry = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_map WHERE table_name = %s AND old_id = %d",
                $table_suffix, intval($curr_id)
            ), ARRAY_A);
        }

        if (!$map_entry) {
            $assigned_uuid = $is_uuid ? $curr_id : Assessor_UUID::v7();
            $int_old_id    = $is_uuid ? 0 : intval($curr_id);

            $wpdb->insert($table_map, array(
                'table_name'   => $table_suffix,
                'old_id'       => $int_old_id,
                'new_uuid'     => $assigned_uuid,
                'business_key' => $biz_key,
                'created_at'   => current_time('mysql')
            ), array('%s', '%d', '%s', '%s', '%s'));

            flush_out("    * Mapped old ID [{$curr_id}] -> UUID [{$assigned_uuid}] ($biz_key)\n");
        } else {
            flush_out("    * Existing mapping retained: old ID [{$curr_id}] -> UUID [{$map_entry['new_uuid']}]\n");
        }
    }

    // Step C: Convert schema to VARCHAR(36) UUID v7
    if (!$is_already_varchar) {
        flush_out("  - Converting $full_table schema to UUID v7 primary key...\n");

        // Remove AUTO_INCREMENT
        run_query("ALTER TABLE $full_table MODIFY id mediumint NOT NULL", "Remove auto increment on $full_table");

        // Add temporary column temp_uuid
        $has_temp = $wpdb->get_row("SHOW COLUMNS FROM $full_table LIKE 'temp_uuid'");
        if (!$has_temp) {
            run_query("ALTER TABLE $full_table ADD COLUMN temp_uuid VARCHAR(36) NULL AFTER id", "Add temp_uuid column to $full_table");
        }

        // Populate temp_uuid from map table
        run_query("
            UPDATE $full_table t
            JOIN $table_map m ON t.id = m.old_id AND m.table_name = '$table_suffix'
            SET t.temp_uuid = m.new_uuid
        ", "Populate temp_uuid for $table_suffix");

        // Drop old primary key and promote temp_uuid to id primary key
        run_query("ALTER TABLE $full_table DROP PRIMARY KEY", "Drop old PK on $full_table");
        run_query("ALTER TABLE $full_table DROP COLUMN id", "Drop old id on $full_table");
        run_query("ALTER TABLE $full_table CHANGE COLUMN temp_uuid id VARCHAR(36) NOT NULL", "Rename temp_uuid to id on $full_table");
        run_query("ALTER TABLE $full_table ADD PRIMARY KEY (id)", "Add new PK on id for $full_table");
        flush_out("  - Schema converted to VARCHAR(36) PRIMARY KEY.\n");
    } else {
        flush_out("  - Column 'id' is already VARCHAR(36). Verifying UUID alignment...\n");
        run_query("
            UPDATE $full_table t
            JOIN $table_map m ON t.$business_key_col = m.business_key AND m.table_name = '$table_suffix'
            SET t.id = m.new_uuid
            WHERE t.id != m.new_uuid
        ", "Ensure mapped UUID consistency on $full_table");
    }

    // Step D: Validation
    $final_rows = $wpdb->get_results("SELECT * FROM $full_table", ARRAY_A);
    $final_count = count($final_rows);

    if ($final_count !== $initial_count) {
        flush_out("  - FATAL INTEGRITY ERROR: Final count ($final_count) != initial count ($initial_count).\n");
        exit(1);
    }

    $uuid_seen = array();
    foreach ($final_rows as $fr) {
        $id_val = $fr['id'];
        if (!Assessor_UUID::is_valid($id_val)) {
            flush_out("  - FATAL: Invalid UUID format detected: '$id_val'\n");
            exit(1);
        }
        if (isset($uuid_seen[$id_val])) {
            flush_out("  - FATAL: Duplicate UUID detected: '$id_val'\n");
            exit(1);
        }
        $uuid_seen[$id_val] = true;
    }

    flush_out("  - [PASS] Table $full_table successfully migrated and verified ($final_count records, all valid UUID v7).\n\n");
    $migration_summary[$table_suffix] = array(
        'table'        => $full_table,
        'backup_table' => $backup_table,
        'rows'         => $final_count
    );
}

// Mark in assessor_migrations if table exists
$tbl_migrations = $wpdb->prefix . 'assessor_migrations';
if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $tbl_migrations)) === $tbl_migrations) {
    $now = current_time('mysql');
    $wpdb->replace($tbl_migrations, array(
        'migration_name' => '005_lookup_tables_uuid_migration',
        'status'         => 'completed',
        'started_at'     => $now,
        'completed_at'   => $now,
        'error'          => null,
        'batch'          => 1,
        'created_at'     => $now
    ));
    flush_out("Recorded migration '005_lookup_tables_uuid_migration' in $tbl_migrations.\n");
}

flush_out("\n===============================================================\n");
flush_out("🎉 ALL 4 LOOKUP TABLES SUCCESSFULLY MIGRATED TO UUID v7!\n");
flush_out("===============================================================\n");
print_r($migration_summary);
