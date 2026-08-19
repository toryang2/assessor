<?php
// Script to create missing building tables

$wp_db = new PDO('mysql:host=localhost;port=3306;dbname=assessor_local;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$queries = [
    // --- Data Tables ---
    "CREATE TABLE IF NOT EXISTS `wp_assessor_bldgstructure` (
        `objid` varchar(50) NOT NULL,
        `bldgrpuid` varchar(50) NOT NULL,
        `structure_objid` varchar(50) NOT NULL,
        `material_objid` varchar(50) DEFAULT NULL,
        `floor` int NOT NULL,
        PRIMARY KEY (`objid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS `wp_assessor_bldguse` (
        `objid` varchar(50) NOT NULL,
        `bldgrpuid` varchar(50) NOT NULL,
        `structuraltype_objid` varchar(50) DEFAULT NULL,
        `actualuse_objid` varchar(50) NOT NULL,
        `basevalue` decimal(16,2) NOT NULL,
        `area` decimal(16,2) NOT NULL,
        `basemarketvalue` decimal(16,2) NOT NULL,
        `depreciationvalue` decimal(16,2) NOT NULL,
        `adjustment` decimal(16,2) NOT NULL,
        `marketvalue` decimal(16,2) NOT NULL,
        `assesslevel` decimal(16,2) DEFAULT NULL,
        `assessedvalue` decimal(16,2) DEFAULT NULL,
        `addlinfo` varchar(255) DEFAULT NULL,
        `adjfordepreciation` decimal(16,2) DEFAULT NULL,
        `taxable` int DEFAULT NULL,
        PRIMARY KEY (`objid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS `wp_assessor_bldgflooradditional` (
        `objid` varchar(50) NOT NULL,
        `bldgfloorid` varchar(50) NOT NULL,
        `bldgrpuid` varchar(50) NOT NULL,
        `additionalitem_objid` varchar(50) NOT NULL,
        `amount` decimal(16,2) NOT NULL,
        `expr` text NOT NULL,
        `depreciate` int DEFAULT NULL,
        `issystem` int DEFAULT NULL,
        PRIMARY KEY (`objid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS `wp_assessor_bldgrpu_structuraltype` (
        `objid` varchar(50) NOT NULL,
        `bldgrpuid` varchar(50) NOT NULL,
        `bldgtype_objid` varchar(50) NOT NULL,
        `bldgkindbucc_objid` varchar(50) DEFAULT NULL,
        `floorcount` int NOT NULL,
        `basefloorarea` decimal(16,2) NOT NULL,
        `totalfloorarea` decimal(16,2) NOT NULL,
        `basevalue` decimal(16,2) NOT NULL,
        `unitvalue` decimal(16,2) NOT NULL,
        `classification_objid` varchar(50) DEFAULT NULL,
        PRIMARY KEY (`objid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // --- Lookup Tables ---
    "CREATE TABLE IF NOT EXISTS `wp_assessor_bldgkind` (
        `objid` varchar(50) NOT NULL,
        `bldgrysettingid` varchar(50) NOT NULL,
        `code` varchar(10) NOT NULL,
        `name` varchar(50) NOT NULL,
        `previd` varchar(50) DEFAULT NULL,
        PRIMARY KEY (`objid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS `wp_assessor_bldgtype` (
        `objid` varchar(50) NOT NULL,
        `bldgrysettingid` varchar(50) NOT NULL,
        `code` varchar(10) NOT NULL,
        `name` varchar(50) NOT NULL,
        `basevaluetype` varchar(10) NOT NULL,
        `residualrate` decimal(10,2) NOT NULL,
        `previd` varchar(50) DEFAULT NULL,
        `usecdu` int DEFAULT NULL,
        `storeyadjtype` varchar(10) DEFAULT NULL,
        PRIMARY KEY (`objid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS `wp_assessor_structure` (
        `objid` varchar(50) NOT NULL,
        `code` varchar(10) NOT NULL,
        `name` varchar(50) NOT NULL,
        `indexno` int NOT NULL,
        PRIMARY KEY (`objid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS `wp_assessor_material` (
        `objid` varchar(50) NOT NULL,
        `code` varchar(10) NOT NULL,
        `name` varchar(50) NOT NULL,
        PRIMARY KEY (`objid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS `wp_assessor_bldgadditionalitem` (
        `objid` varchar(50) NOT NULL,
        `bldgrysettingid` varchar(50) NOT NULL,
        `code` varchar(10) NOT NULL,
        `name` varchar(100) NOT NULL,
        `unit` varchar(25) NOT NULL,
        `expr` varchar(100) NOT NULL,
        `previd` varchar(50) DEFAULT NULL,
        `type` varchar(50) DEFAULT NULL,
        `addareatobldgtotalarea` int DEFAULT NULL,
        `idx` int DEFAULT NULL,
        PRIMARY KEY (`objid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS `wp_assessor_bldgkindbucc` (
        `objid` varchar(50) NOT NULL,
        `bldgrysettingid` varchar(50) NOT NULL,
        `bldgtypeid` varchar(50) NOT NULL,
        `bldgkind_objid` varchar(50) NOT NULL,
        `basevaluetype` varchar(25) NOT NULL,
        `basevalue` decimal(10,2) NOT NULL,
        `minbasevalue` decimal(10,2) NOT NULL,
        `maxbasevalue` decimal(10,2) NOT NULL,
        `gapvalue` int NOT NULL,
        `minarea` decimal(10,2) NOT NULL,
        `maxarea` decimal(10,2) NOT NULL,
        `bldgclass` varchar(50) DEFAULT NULL,
        `previd` varchar(50) DEFAULT NULL,
        PRIMARY KEY (`objid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS `wp_assessor_bldgtype_depreciation` (
        `objid` varchar(50) NOT NULL,
        `bldgtypeid` varchar(50) NOT NULL,
        `bldgrysettingid` varchar(50) NOT NULL,
        `agefrom` int NOT NULL,
        `ageto` int NOT NULL,
        `rate` decimal(16,2) NOT NULL,
        `excellent` decimal(16,2) DEFAULT NULL,
        `verygood` decimal(16,2) DEFAULT NULL,
        `good` decimal(16,2) DEFAULT NULL,
        `average` decimal(16,2) DEFAULT NULL,
        `fair` decimal(16,2) DEFAULT NULL,
        `poor` decimal(16,2) DEFAULT NULL,
        `verypoor` decimal(16,2) DEFAULT NULL,
        `unsound` decimal(16,2) DEFAULT NULL,
        PRIMARY KEY (`objid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
];

foreach ($queries as $sql) {
    try {
        $wp_db->exec($sql);
        // get table name from query for logging
        preg_match('/CREATE TABLE IF NOT EXISTS `([^`]+)`/', $sql, $matches);
        echo "Created table " . $matches[1] . "\n";
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\nSQL: $sql\n";
    }
}

echo "\nDONE.\n";
?>
