<?php
require 'C:/xampp/htdocs/wp-load.php';
require_once 'd:/CODE/assessor/assessor-backend/wp-content/plugins/assessor-api/includes/class-assessor-etracs-sync.php';
echo "Running ETRACS Sync...\n";
$stats = Assessor_Etracs_Sync::trigger_sync();
print_r($stats);
