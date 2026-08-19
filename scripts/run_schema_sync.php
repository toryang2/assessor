<?php
define('WP_USE_THEMES', false);
require_once('c:/xampp/htdocs/wp-load.php');

if (class_exists('Assessor_Etracs_Sync')) {
    echo "Running trigger_sync...\n";
    $result = Assessor_Etracs_Sync::trigger_sync();
    echo "Sync result:\n";
    print_r($result);
} else {
    echo "Class Assessor_Etracs_Sync not found.\n";
}
