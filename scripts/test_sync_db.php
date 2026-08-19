<?php require 'C:/xampp/htdocs/wp-load.php'; global $wpdb; print_r($wpdb->get_results('SELECT * FROM ' . $wpdb->prefix . 'assessor_sync_queue ORDER BY id DESC LIMIT 5', ARRAY_A));
