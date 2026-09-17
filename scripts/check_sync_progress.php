<?php
require 'C:/xampp/htdocs/wp-load.php';
global $wpdb;
$props = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}assessor_properties");
$revs  = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}assessor_revision_entries");
$offset = Assessor_Sync::get_meta('pull_offset');
echo "PROPERTIES: $props | REVISIONS: $revs | PULL_OFFSET: $offset\n";
