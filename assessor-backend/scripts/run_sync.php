<?php
require 'C:/xampp/htdocs/assessor/wp-load.php';
$stats = Assessor_Etracs_Sync::trigger_sync();
print_r($stats);
