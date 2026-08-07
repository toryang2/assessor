<?php
require_once 'c:/xampp/htdocs/wp-load.php';
global $wpdb;
$row = $wpdb->get_row("SELECT rpu_type FROM " . $wpdb->prefix . "assessor_rpu r JOIN " . $wpdb->prefix . "assessor_faas f ON r.objid = f.rpuid WHERE f.tdno = '22-010-0024-00234'");
print_r($row);
