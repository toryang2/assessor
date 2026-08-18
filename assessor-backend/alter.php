<?php
require 'C:/xampp/htdocs/wp-load.php';
global $wpdb;
$wpdb->query("ALTER TABLE {$wpdb->prefix}assessor_audit_trail MODIFY record_id VARCHAR(50);");
echo "Altered table!\n";
