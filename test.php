<?php 
require_once 'c:/xampp/htdocs/wp-load.php'; 
$host = get_option('assessor_etracs_db_host', 'localhost');
$port = get_option('assessor_etracs_db_port', '3306');
$user = get_option('assessor_etracs_db_user', 'root');
$pass = get_option('assessor_etracs_db_password', '');
$db   = get_option('assessor_etracs_db_name', 'etracs254_kitaotao');

try { 
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass);
    
    // Check if rpu_assessment exists and get sample data
    $stmt = $pdo->query("SELECT * FROM rpu_assessment LIMIT 1");
    print_r("rpu_assessment: "); print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

} catch(Exception $e) { 
    echo $e->getMessage(); 
} 
?>
