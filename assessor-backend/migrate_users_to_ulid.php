<?php
/**
 * Migration Script to convert integer User IDs to ULIDs
 * Place this in assessor-backend/ and run via CLI: php migrate_users_to_ulid.php
 */

if (file_exists(__DIR__ . '/wp-load.php')) {
    require_once __DIR__ . '/wp-load.php';
} elseif (file_exists(dirname(__DIR__) . '/wp-load.php')) {
    require_once dirname(__DIR__) . '/wp-load.php';
} else {
    die("Cannot find wp-load.php. Please place this script in your WordPress root directory or run it from assessor-backend.\n");
}

if (!class_exists('Assessor_ULID')) {
    require_once WP_PLUGIN_DIR . '/assessor-api/includes/class-assessor-ulid.php';
}

$is_cli = (php_sapi_name() === 'cli');
if (!$is_cli) {
    echo "<pre>";
}
echo "Starting ULID Migration...\n";

$tables_to_alter = array(
    $wpdb->prefix . 'assessor_users' => array('id'),
    $wpdb->prefix . 'assessor_properties' => array('created_by', 'updated_by'),
    $wpdb->prefix . 'assessor_property_versions' => array('created_by', 'updated_by'),
    $wpdb->prefix . 'assessor_documents' => array('uploaded_by'),
    $wpdb->prefix . 'assessor_audit_trail' => array('user_id'),
    $wpdb->prefix . 'assessor_api_keys' => array('created_by'),
    $wpdb->prefix . 'assessor_requests' => array('created_by', 'updated_by')
);

// Step 1: Alter tables to VARCHAR(50)
foreach ($tables_to_alter as $table => $columns) {
    // Check if table exists
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
    if (!$table_exists) {
        continue;
    }
    
    foreach ($columns as $column) {
        echo "Altering $table.$column to VARCHAR(50)...\n";
        // To be safe, we just alter it. MySQL will convert ints to strings natively.
        if ($column === 'id' && $table === $wpdb->prefix . 'assessor_users') {
            // First we need to remove auto_increment
            $wpdb->query("ALTER TABLE $table MODIFY COLUMN $column mediumint(9) NOT NULL");
            $wpdb->query("ALTER TABLE $table MODIFY COLUMN $column VARCHAR(50) NOT NULL");
        } else {
            $wpdb->query("ALTER TABLE $table MODIFY COLUMN $column VARCHAR(50) DEFAULT NULL");
        }
    }
}

// Step 2: Fetch all users to map old ID to new ULID
$table_users = $wpdb->prefix . 'assessor_users';
$users = $wpdb->get_results("SELECT id, username FROM $table_users", ARRAY_A);

$table_settings = $wpdb->prefix . 'assessor_settings';
$settings_row = $wpdb->get_row("SELECT municipality_prefix FROM $table_settings LIMIT 1", ARRAY_A);

$muni = 'GBL';
if ($settings_row && !empty($settings_row['municipality_prefix'])) {
    $muni = strtoupper(substr(trim($settings_row['municipality_prefix']), 0, 3));
}
if ($muni === 'GBL') {
    // Allow passing via CLI argument or GET param if db setting is empty
    $muni_arg = $is_cli ? (isset($argv[1]) ? $argv[1] : '') : (isset($_GET['muni']) ? $_GET['muni'] : '');
    $muni = !empty($muni_arg) ? strtoupper(substr($muni_arg, 0, 3)) : 'GBL';
}

echo "Using municipality prefix: $muni\n";
echo "Generating ULIDs for " . count($users) . " users...\n";

foreach ($users as $user) {
    $old_id = $user['id'];
    $username = $user['username'];
    
    // Skip if it's already a ULID
    if (strpos($old_id, 'USR-') === 0) {
        continue;
    }

    if (in_array($username, array('admin', 'super'))) {
        $new_id = Assessor_ULID::generate_with_prefix('GBL');
    } else {
        $new_id = Assessor_ULID::generate_with_prefix($muni);
    }
    
    echo "Mapping user $old_id -> $new_id\n";
    
    // Update users table
    $wpdb->update($table_users, array('id' => $new_id), array('id' => $old_id), array('%s'), array('%s'));
    
    // Update foreign keys
    $wpdb->update($wpdb->prefix . 'assessor_properties', array('created_by' => $new_id), array('created_by' => $old_id), array('%s'), array('%s'));
    $wpdb->update($wpdb->prefix . 'assessor_properties', array('updated_by' => $new_id), array('updated_by' => $old_id), array('%s'), array('%s'));
    
    $table_versions = $wpdb->prefix . 'assessor_property_versions';
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_versions'")) {
        $wpdb->update($table_versions, array('created_by' => $new_id), array('created_by' => $old_id), array('%s'), array('%s'));
        $wpdb->update($table_versions, array('updated_by' => $new_id), array('updated_by' => $old_id), array('%s'), array('%s'));
    }

    $wpdb->update($wpdb->prefix . 'assessor_documents', array('uploaded_by' => $new_id), array('uploaded_by' => $old_id), array('%s'), array('%s'));
    $wpdb->update($wpdb->prefix . 'assessor_audit_trail', array('user_id' => $new_id), array('user_id' => $old_id), array('%s'), array('%s'));
    $wpdb->update($wpdb->prefix . 'assessor_api_keys', array('created_by' => $new_id), array('created_by' => $old_id), array('%s'), array('%s'));
    $wpdb->update($wpdb->prefix . 'assessor_requests', array('created_by' => $new_id), array('created_by' => $old_id), array('%s'), array('%s'));
    $wpdb->update($wpdb->prefix . 'assessor_requests', array('updated_by' => $new_id), array('updated_by' => $old_id), array('%s'), array('%s'));
}

echo "Migration Complete!\n";
if (!$is_cli) {
    echo "</pre>";
}
