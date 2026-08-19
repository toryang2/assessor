<?php
/**
 * Fix script to revert migrated GBL users back to KIT
 */

if (file_exists(__DIR__ . '/wp-load.php')) {
    require_once __DIR__ . '/wp-load.php';
} elseif (file_exists(dirname(__DIR__) . '/wp-load.php')) {
    require_once dirname(__DIR__) . '/wp-load.php';
} else {
    // Look for htdocs
    if (file_exists('C:/xampp/htdocs/wp-load.php')) {
        require_once 'C:/xampp/htdocs/wp-load.php';
    } else {
        die("Cannot find wp-load.php\n");
    }
}

global $wpdb;

echo "Starting prefix fix...\n";

$table_users = $wpdb->prefix . 'assessor_users';
$users = $wpdb->get_results("SELECT id, username FROM $table_users WHERE id LIKE 'USR-GBL-%'", ARRAY_A);

echo "Found " . count($users) . " users with GBL prefix.\n";

foreach ($users as $user) {
    $old_id = $user['id'];
    $username = $user['username'];
    
    if (in_array($username, array('admin', 'super'))) {
        echo "Skipping $username ($old_id) - admin/super should be GBL\n";
        continue;
    }

    $new_id = str_replace('USR-GBL-', 'USR-KIT-', $old_id);
    
    echo "Fixing user $username: $old_id -> $new_id\n";
    
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

echo "Fix Complete!\n";
