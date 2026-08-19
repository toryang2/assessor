<?php
// Script to copy lookup data from etracs254_kitaotao to assessor_local

$etracs_db = new PDO('mysql:host=localhost;port=3306;dbname=etracs254_kitaotao;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$wp_db = new PDO('mysql:host=localhost;port=3306;dbname=assessor_local;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$tables_to_sync_data = [
    'bldgkind' => 'wp_assessor_bldgkind',
    'bldgtype' => 'wp_assessor_bldgtype',
    'structure' => 'wp_assessor_structure',
    'material' => 'wp_assessor_material',
    'bldgadditionalitem' => 'wp_assessor_bldgadditionalitem',
    'bldgkindbucc' => 'wp_assessor_bldgkindbucc',
    'bldgtype_depreciation' => 'wp_assessor_bldgtype_depreciation'
];

echo "Syncing data for lookup tables...\n";
foreach ($tables_to_sync_data as $etracs_tbl => $wp_tbl) {
    try {
        // Copy data
        $rows = $etracs_db->query("SELECT * FROM $etracs_tbl")->fetchAll();
        if (count($rows) > 0) {
            $wp_db->exec("TRUNCATE TABLE `$wp_tbl`");
            $columns = array_keys($rows[0]);
            $col_list = "`" . implode("`, `", $columns) . "`";
            $placeholders = trim(str_repeat('?,', count($columns)), ',');
            
            $insert_stmt = $wp_db->prepare("INSERT INTO `$wp_tbl` ($col_list) VALUES ($placeholders)");
            $count = 0;
            foreach ($rows as $r) {
                $insert_stmt->execute(array_values($r));
                $count++;
            }
            echo "Copied $count rows to $wp_tbl\n";
        } else {
            echo "No rows found in $etracs_tbl to copy.\n";
        }
    } catch (Exception $e) {
        echo "Error on $wp_tbl: " . $e->getMessage() . "\n";
    }
}

echo "\nDONE.\n";
?>
