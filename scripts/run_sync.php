<?php 
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'c:/xampp/htdocs/wp-load.php'; 
require_once 'd:/CODE/assessor/assessor-backend/wp-content/plugins/assessor-api/includes/class-assessor-etracs-sync.php';

try {
    $stats = Assessor_Etracs_Sync::trigger_sync();
    print_r("SYNC COMPLETE: "); print_r($stats);
} catch (Exception $e) {
    echo "EXCEPTION: " . $e->getMessage();
} catch (Error $e) {
    echo "ERROR: " . $e->getMessage();
}
?>
