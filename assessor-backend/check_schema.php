<?php
$etracs_db = new PDO('mysql:host=localhost;port=3306;dbname=etracs254_kitaotao;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$tables = ['bldgstructure', 'bldguse', 'bldgflooradditional', 'bldgrpu_structuraltype', 'bldgtype', 'bldgadditionalitem', 'bldgkindbucc', 'bldgtype_depreciation'];

foreach ($tables as $t) {
    $stmt = $etracs_db->query("SHOW CREATE TABLE $t");
    $row = $stmt->fetch(PDO::FETCH_NUM);
    echo "--- $t ---\n";
    echo $row[1] . "\n\n";
}
?>
