<?php
$dbs = ['assessor_local', 'assessor_kitaotao_new'];
foreach($dbs as $db) {
    echo "Checking $db...\n";
    try {
        $c = new mysqli('localhost', 'root', '', $db);
        if ($c->connect_error) continue;
        
        $r = $c->query("SHOW TABLES LIKE 'wp_assessor_locations'");
        if ($r && $r->num_rows > 0) {
            echo "Table exists in $db.\n";
            $res = $c->query("ALTER TABLE wp_assessor_locations ADD COLUMN pin varchar(50) DEFAULT ''");
            if ($res) echo "Column added to $db.\n";
            else echo "Could not add column (might exist) in $db: " . $c->error . "\n";
        } else {
            echo "Table not found in $db.\n";
        }
    } catch(Exception $e) {
        echo "Error in $db: " . $e->getMessage() . "\n";
    }
}
