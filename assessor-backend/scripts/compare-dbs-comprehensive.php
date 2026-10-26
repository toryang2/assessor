<?php
$pdo = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', 'root', '');

function inspect_db($pdo, $db) {
    echo "=== Database: $db ===\n";
    $stmt = $pdo->query("SHOW TABLES FROM `$db`");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Check siteurl and home
    if (in_array('wp_options', $tables)) {
        $q = $pdo->query("SELECT option_name, option_value FROM `$db`.wp_options WHERE option_name IN ('siteurl', 'home', 'template', 'stylesheet')");
        while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
            echo "  " . $row['option_name'] . ": " . $row['option_value'] . "\n";
        }
    } else {
        echo "  wp_options: NOT FOUND\n";
    }
    
    // Check users count
    if (in_array('wp_users', $tables)) {
        $c = $pdo->query("SELECT count(*) FROM `$db`.wp_users")->fetchColumn();
        echo "  wp_users count: " . $c . "\n";
        $users = $pdo->query("SELECT ID, user_login, user_email FROM `$db`.wp_users LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($users as $u) {
            echo "    User: ID={$u['ID']}, login={$u['user_login']}, email={$u['user_email']}\n";
        }
    } else {
        echo "  wp_users: NOT FOUND\n";
    }
    
    // Native Assessor tables
    $native = [
        'wp_assessor_properties', 
        'wp_assessor_subdivisions', 
        'wp_assessor_reclassifications', 
        'wp_assessor_consolidations', 
        'wp_assessor_lineage_edges',
        'wp_assessor_reconciliation',
        'wp_assessor_audit_log'
    ];
    echo "  Native Assessor tables:\n";
    foreach ($native as $t) {
        if (in_array($t, $tables)) {
            $c = $pdo->query("SELECT count(*) FROM `$db`.`$t`")->fetchColumn();
            echo "    " . $t . ": " . $c . " rows\n";
        } else {
            echo "    " . $t . ": NOT FOUND\n";
        }
    }
    
    // Shadow ETRACS tables
    $shadow = [
        'wp_assessor_faas', 
        'wp_assessor_rpu', 
        'wp_assessor_bldgdetail',
        'wp_assessor_bldgrpu',
        'wp_assessor_machrpu', 
        'wp_assessor_machdetail', 
        'wp_assessor_machine', 
        'wp_assessor_machine_smv', 
        'wp_assessor_planttree', 
        'wp_assessor_planttreedetail', 
        'wp_assessor_sync_runs', 
        'wp_assessor_sync_checkpoints', 
        'wp_assessor_migrations'
    ];
    echo "  Shadow ETRACS tables:\n";
    foreach ($shadow as $t) {
        if (in_array($t, $tables)) {
            $c = $pdo->query("SELECT count(*) FROM `$db`.`$t`")->fetchColumn();
            echo "    " . $t . ": " . $c . " rows\n";
        } else {
            echo "    " . $t . ": NOT FOUND\n";
        }
    }
    echo "\n";
}

inspect_db($pdo, 'test');
inspect_db($pdo, 'assessor_local');
