<?php
$host = 'localhost';
$db   = 'assessor_local';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset;port=3306";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    
    // Add missing columns to wp_assessor_faas_list
    $queries = [
        "ALTER TABLE wp_assessor_faas_list ADD COLUMN prevpin text COLLATE utf8mb4_unicode_520_ci AFTER prevtdno",
        "ALTER TABLE wp_assessor_faas_list ADD COLUMN prevowner text COLLATE utf8mb4_unicode_520_ci AFTER prevpin",
        "ALTER TABLE wp_assessor_faas_list ADD COLUMN prevav text COLLATE utf8mb4_unicode_520_ci AFTER prevowner",
        "ALTER TABLE wp_assessor_faas_list ADD COLUMN prevmv text COLLATE utf8mb4_unicode_520_ci AFTER prevav",
        "ALTER TABLE wp_assessor_faas_list ADD COLUMN prevareaha text COLLATE utf8mb4_unicode_520_ci AFTER prevmv",
        "ALTER TABLE wp_assessor_faas_list ADD COLUMN prevareasqm text COLLATE utf8mb4_unicode_520_ci AFTER prevareaha",
        "ALTER TABLE wp_assessor_faas_list ADD COLUMN prevadministrator varchar(255) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL AFTER prevareasqm",
        "ALTER TABLE wp_assessor_faas_list ADD COLUMN actualuse varchar(100) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL AFTER classcode",
        "ALTER TABLE wp_assessor_faas_list ADD COLUMN actualuse_objid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL AFTER classcode"
    ];

    foreach ($queries as $sql) {
        try {
            $pdo->exec($sql);
            echo "Success: $sql\n";
        } catch (\PDOException $e) {
            echo "Failed or already exists: $sql (Error: " . $e->getMessage() . ")\n";
        }
    }

    echo "\nColumns in wp_assessor_faas_list:\n";
    $stmt = $pdo->query("SHOW COLUMNS FROM wp_assessor_faas_list");
    while ($row = $stmt->fetch()) {
        if (strpos($row['Field'], 'prev') === 0 || strpos($row['Field'], 'actualuse') === 0) {
            echo $row['Field'] . " - " . $row['Type'] . "\n";
        }
    }
} catch (\PDOException $e) {
    throw new \PDOException($e->getMessage(), (int)$e->getCode());
}
