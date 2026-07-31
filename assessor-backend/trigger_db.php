<?php
require_once dirname(__FILE__) . '/wp-load.php';

require_once dirname(__FILE__) . '/wp-admin/includes/upgrade.php';
require_once dirname(__FILE__) . '/wp-content/plugins/assessor-api/includes/class-assessor-database.php';

Assessor_Database::install();

global $wpdb;
$res = $wpdb->query("ALTER TABLE wp_assessor_locations ADD COLUMN pin varchar(50) DEFAULT ''");
echo "DB upgraded. Result of manual alter table: " . ($res !== false ? 'success' : 'failed or exists') . "\n";
