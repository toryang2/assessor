<?php
/**
 * Check Apache virtual hosts configuration
 */
$vhosts_path = 'C:/xampp/apache/conf/extra/httpd-vhosts.conf';
$httpd_conf = 'C:/xampp/apache/conf/httpd.conf';

if (file_exists($vhosts_path)) {
    echo "Found httpd-vhosts.conf:\n";
    $lines = file($vhosts_path);
    foreach ($lines as $line) {
        if (trim($line) && $line[0] !== '#') {
            echo "   " . trim($line) . "\n";
        }
    }
} else {
    echo "httpd-vhosts.conf not found\n";
}

if (file_exists($httpd_conf)) {
    echo "\nSearching DocumentRoot in httpd.conf:\n";
    $lines = file($httpd_conf);
    foreach ($lines as $line) {
        if (stripos($line, 'DocumentRoot') !== false || stripos($line, '<Directory "') !== false) {
            echo "   " . trim($line) . "\n";
        }
    }
}
