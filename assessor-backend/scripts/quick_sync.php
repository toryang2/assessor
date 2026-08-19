<?php
require 'C:/xampp/htdocs/wp-load.php';
global $wpdb;

set_time_limit(0);

try {
    $pdo = new PDO("mysql:host=localhost;dbname=etracs254_kitaotao;charset=utf8mb4;port=3306", 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    // Sync specific faas_previous
    $wp_faas_previous = $wpdb->prefix . 'assessor_faas_previous';
    $stmt = $pdo->query("SELECT * FROM faas_previous WHERE prevtdno LIKE '%10-024-07725%'");
    while ($row = $stmt->fetch()) {
        $wpdb->replace($wp_faas_previous, $row);
        $faasid = $row['faasid'];
    }
    
    // Sync specific faas
    if (!empty($faasid)) {
        $wp_faas = $wpdb->prefix . 'assessor_faas_list';
        $stmt = $pdo->query("SELECT * FROM faas WHERE objid = '$faasid'");
        while ($row = $stmt->fetch()) {
            $data = [
                'objid' => $row['objid'],
                'state' => $row['state'],
                'rpuid' => $row['rpuid'],
                'realpropertyid' => $row['realpropertyid'],
                'tdno' => $row['tdno'],
                'utdno' => $row['utdno'],
                'txntype_objid' => $row['txntype_objid'],
                'taxpayer_objid' => $row['taxpayer_objid'],
                'owner_name' => $row['owner_name'],
                'prevtdno' => $row['prevtdno'],
                'prevpin' => $row['prevpin'],
                'prevowner' => $row['prevowner'],
                'prevav' => $row['prevav'],
                'prevmv' => $row['prevmv'],
                'prevareaha' => $row['prevareaha'],
                'prevareasqm' => $row['prevareasqm'],
                'prevadministrator' => $row['prevadministrator'],
                'cancelledbytdnos' => $row['cancelledbytdnos'],
                'pin' => $row['fullpin'] ?: '',
            ];
            $wpdb->replace($wp_faas, $data);
            
            // Also replace in assessor_faas just in case it reads from there (it doesn't, but still)
            $wpdb->replace($wpdb->prefix . 'assessor_faas', $row);
        }
    }
    
    echo "Specific sync complete!\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
