<?php
require_once 'd:\CODE\assessor\wp-load.php';
global $wpdb;
$res = $wpdb->get_results('DESCRIBE ' . $wpdb->prefix . 'assessor_property_states');
print_r($res);
