<?php
$pdo_local = new PDO('mysql:host=127.0.0.1;dbname=assessor_local', 'root', '');
$res_local = $pdo_local->query("SHOW TABLES LIKE 'wp_assessor%'")->fetchAll(PDO::FETCH_COLUMN);
echo "Tables in assessor_local: " . count($res_local) . "\n";

try {
    $pdo_test = new PDO('mysql:host=127.0.0.1;dbname=test', 'root', '');
    $res_test = $pdo_test->query("SHOW TABLES LIKE 'wp_assessor%'")->fetchAll(PDO::FETCH_COLUMN);
    echo "Tables in test: " . count($res_test) . "\n";
    $siteurl = $pdo_test->query("SELECT option_value FROM wp_options WHERE option_name = 'siteurl'")->fetchColumn();
    echo "test siteurl: $siteurl\n";
} catch (Exception $e) {
    echo "test db error: " . $e->getMessage() . "\n";
}

try {
    $pdo_prod2 = new PDO('mysql:host=127.0.0.1;dbname=production_database2', 'root', '');
    $res_prod2 = $pdo_prod2->query("SHOW TABLES LIKE 'wp_assessor%'")->fetchAll(PDO::FETCH_COLUMN);
    echo "Tables in production_database2: " . count($res_prod2) . "\n";
} catch (Exception $e) {
    echo "prod2 db error: " . $e->getMessage() . "\n";
}
