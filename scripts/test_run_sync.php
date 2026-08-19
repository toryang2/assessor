<?php require 'C:/xampp/htdocs/wp-load.php'; register_shutdown_function(function() { print_r(error_get_last()); }); Assessor_Sync::manual_sync(); echo 'SYNC DONE';
