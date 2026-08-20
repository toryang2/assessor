<?php
$host = '127.0.0.1';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dbs = ['assessor_local', 'assessor_kitaotao_new', 'assessor-archiving-test'];

foreach ($dbs as $db) {
    echo "Checking database: $db\n";
    $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, $user, $pass, $options);
        
        $table_name = 'wp_assessor_requests';

        $new_columns = [
            'verifier_signatory_name',
            'verifier_signatory_title',
            'municipal_assessor_name',
            'municipal_assessor_title',
            'municipal_assessor_license',
            'municipal_assessor_suffix'
        ];

        foreach ($new_columns as $col) {
            try {
                $pdo->exec("ALTER TABLE {$table_name} ADD COLUMN {$col} varchar(255) DEFAULT NULL");
                echo "  Added column: {$col}\n";
            } catch (PDOException $e) {
                if ($e->getCode() == '42S21') {
                    echo "  Column {$col} already exists.\n";
                } elseif ($e->getCode() == '42S02') {
                    echo "  Table {$table_name} doesn't exist in $db.\n";
                    break;
                } else {
                    throw $e;
                }
            }
        }
    } catch (\PDOException $e) {
        echo "  Could not connect: " . $e->getMessage() . "\n";
    }
}
echo "Done.\n";
