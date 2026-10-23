<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=assessor_local', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$tables = [
    'wp_assessor_bldgkind',
    'wp_assessor_bldgkindbucc',
    'wp_assessor_bldgrpu',
    'wp_assessor_bldgrpu_structuraltype',
    'wp_assessor_bldgtype',
    'wp_assessor_bldgtype_depreciation',
    'wp_assessor_bldgadditionalitem',
    'wp_assessor_bldgflooradditional',
    'wp_assessor_bldgstructure',
    'wp_assessor_bldguse',
    'wp_assessor_bldgrysetting',
    'wp_assessor_material',
    'wp_assessor_structure'
];

foreach ($tables as $t) {
    echo "Converting $t to utf8mb4_unicode_520_ci...\n";
    $pdo->exec("ALTER TABLE `$t` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci");
}
echo "All building tables normalized to utf8mb4_unicode_520_ci successfully.\n";
