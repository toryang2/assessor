<?php
define('WP_USE_THEMES', false);
require_once('c:/xampp/htdocs/wp-load.php');
global $wpdb;

$use = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}assessor_propertyclassification WHERE objid = 'AL-5961e074:1843c511a0c:724f'");
print_r(['in_classification' => $use]);

$use2 = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}assessor_actualuse WHERE objid = 'AL-5961e074:1843c511a0c:724f'");
print_r(['in_actualuse' => $use2]);
