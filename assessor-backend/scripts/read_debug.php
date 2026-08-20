<?php
require_once('wp-load.php');
echo ABSPATH . "\n";
if (file_exists(ABSPATH . 'debug_payload.log')) {
    echo file_get_contents(ABSPATH . 'debug_payload.log');
} else {
    echo "Log file not found.\n";
}
