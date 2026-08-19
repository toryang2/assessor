<?php
require 'c:/xampp/htdocs/wp-load.php';

$etracs_db_host = get_option('assessor_etracs_db_host', 'localhost');
$etracs_db_port = get_option('assessor_etracs_db_port', '5432');
$etracs_db_name = get_option('assessor_etracs_db_name', 'etracs');
$etracs_db_user = get_option('assessor_etracs_db_user', 'postgres');
$etracs_db_pass = get_option('assessor_etracs_db_pass', 'postgres');

try {
    $pdo = new PDO("pgsql:host=$etracs_db_host;port=$etracs_db_port;dbname=$etracs_db_name", $etracs_db_user, $etracs_db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Check actualuse mapping in PostgreSQL
    $stmt = $pdo->query("SELECT ra.objid, ra.actualuse_objid, pc2.name as pc_name, au.name as au_name 
                         FROM rpu_assessment ra 
                         LEFT JOIN propertyclassification pc2 ON pc2.objid = ra.actualuse_objid 
                         LEFT JOIN actualuse au ON au.objid = ra.actualuse_objid
                         WHERE ra.actualuse_objid IS NOT NULL LIMIT 5");
    echo "Actual Use Test:\n";
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
die();
