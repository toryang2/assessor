<?php
require_once('C:\xampp\htdocs\wp-load.php');
$stats = Assessor_Etracs_Sync::trigger_sync();
print_r($stats);
?>
