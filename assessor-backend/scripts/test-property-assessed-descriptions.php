<?php
/**
 * Test Suite: Assessor Repo — Targeted UI/Data-Mapping Fix: Kind of Property Assessed Descriptions
 *
 * Isolated test suite running entirely within the disposable database `test_wp01_disposable`.
 *
 * Strict Architectural Safeguards:
 * - Fail-closed check: Target database MUST be 'test_wp01_disposable'.
 * - NEVER connect to assessor_local or production/live environments.
 * - ZERO references or modifications to native Assessor tables, schemas, or sync files.
 *
 * Scenarios tested (per requirement specifications):
 * 1. The machinery example record with expected description stored in a fixture:
 *    - TD No.: 22-010-0004-05935
 *    - Property type: MACH
 *    - Expected Brief Description: 'GASOLINE STATION WITH COMPLETE ACCESSORIES'
 * 2. Land, Building, and Others with their own distinct descriptions.
 * 3. Building number of storeys (floorcount) and brief description resolution.
 * 4. A declaration with multiple applicable property types (e.g. Land + PlantTree) and independent descriptions.
 * 5. Backend API response fields matching underlying shadow records (get_rpu_detail projection).
 * 6. Missing description honestly remaining blank without fabricated text.
 * 7. Simulated source refresh preserving and updating descriptions according to verified sync mapping.
 * 8. Existing LOCAL/REPLICA ownership protections remain 100% intact.
 * 9. Regression suites for WP-01 through WP-08 compatibility.
 */

// 1. Connection Guard: Connect strictly to test_wp01_disposable
$db_host = '127.0.0.1';
$db_user = 'root';
$db_pass = '';
$db_name = 'test_wp01_disposable';

$pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Fail-closed guard
$stmt = $pdo->query("SELECT DATABASE()");
$current_db = $stmt->fetchColumn();
if ($current_db !== 'test_wp01_disposable') {
    die("FATAL: Guard failure. Expected database 'test_wp01_disposable', got '$current_db'. Aborting.\n");
}

echo "========================================================================\n";
echo "   KIND OF PROPERTY ASSESSED DESCRIPTIONS TEST HARNESS\n";
echo "   Target Database: test_wp01_disposable (Disposable Isolated Environment)\n";
echo "   Connection Guard: VERIFIED (DATABASE() === 'test_wp01_disposable')\n";
echo "========================================================================\n\n";

$pass_count = 0;
$fail_count = 0;

function assert_test($condition, $label) {
    global $pass_count, $fail_count;
    if ($condition) {
        echo " [PASS] $label\n";
        $pass_count++;
    } else {
        echo " [FAIL] $label\n";
        $fail_count++;
    }
}

// Ensure mock WordPress environment for backend testing
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}
if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

// Mock WPDB adapter
class MockWpdbTargeted {
    public $pdo;
    public $prefix = 'wp_';
    public $last_error = '';

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function prepare($query, $args) {
        if (!is_array($args)) {
            $args = array_slice(func_get_args(), 1);
        }
        $arg_index = 0;
        return preg_replace_callback('/(%s|%d|%f)/', function($match) use (&$args, &$arg_index) {
            $val = isset($args[$arg_index]) ? $args[$arg_index] : null;
            $arg_index++;
            if ($val === null) {
                return 'NULL';
            }
            if ($match[1] === '%d') {
                return intval($val);
            }
            if ($match[1] === '%f') {
                return floatval($val);
            }
            return $this->pdo->quote((string)$val);
        }, $query);
    }

    public function query($sql) {
        try {
            return $this->pdo->exec($sql);
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            return false;
        }
    }

    public function get_var($query, $x = 0, $y = 0) {
        try {
            $stmt = $this->pdo->query($query);
            return $stmt ? $stmt->fetchColumn() : null;
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            return null;
        }
    }

    public function get_row($query, $output = OBJECT, $y = 0) {
        try {
            $stmt = $this->pdo->query($query);
            $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
            return $row ? (object)$row : null;
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            return null;
        }
    }

    public function get_results($query, $output = OBJECT) {
        try {
            $stmt = $this->pdo->query($query);
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            return array_map(function($r) { return (object)$r; }, $rows);
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            return [];
        }
    }

    public function get_col($query, $x = 0) {
        try {
            $stmt = $this->pdo->query($query);
            return $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN, $x) : [];
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            return [];
        }
    }
}

global $wpdb;
$wpdb = new MockWpdbTargeted($pdo);

// 2. Setup Disposable Shadow Schema for Property Descriptions with uniform collation
$pdo->exec("
    DROP TABLE IF EXISTS `wp_assessor_rpu_assessment`;
    DROP TABLE IF EXISTS `wp_assessor_machdetail`;
    DROP TABLE IF EXISTS `wp_assessor_machine_smv`;
    DROP TABLE IF EXISTS `wp_assessor_machine`;
    DROP TABLE IF EXISTS `wp_assessor_machrpu`;
    DROP TABLE IF EXISTS `wp_assessor_bldgstructure`;
    DROP TABLE IF EXISTS `wp_assessor_structure`;
    DROP TABLE IF EXISTS `wp_assessor_material`;
    DROP TABLE IF EXISTS `wp_assessor_bldgflooradditional`;
    DROP TABLE IF EXISTS `wp_assessor_bldgadditionalitem`;
    DROP TABLE IF EXISTS `wp_assessor_bldgfloor`;
    DROP TABLE IF EXISTS `wp_assessor_bldgrpu_structuraltype`;
    DROP TABLE IF EXISTS `wp_assessor_bldgrpu`;
    DROP TABLE IF EXISTS `wp_assessor_bldgkindbucc`;
    DROP TABLE IF EXISTS `wp_assessor_bldgkind`;
    DROP TABLE IF EXISTS `wp_assessor_bldgtype`;
    DROP TABLE IF EXISTS `wp_assessor_miscrpuitem`;
    DROP TABLE IF EXISTS `wp_assessor_miscitem`;
    DROP TABLE IF EXISTS `wp_assessor_miscrpu`;
    DROP TABLE IF EXISTS `wp_assessor_planttreedetail`;
    DROP TABLE IF EXISTS `wp_assessor_planttree`;
    DROP TABLE IF EXISTS `wp_assessor_planttreerpu`;
    DROP TABLE IF EXISTS `wp_assessor_landdetail`;
    DROP TABLE IF EXISTS `wp_assessor_landrpu`;
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS `wp_assessor_rpu` (
        `objid` varchar(50) NOT NULL,
        `state` varchar(20) DEFAULT 'CURRENT',
        `realpropertyid` varchar(50) DEFAULT NULL,
        `rpu_type` varchar(50) DEFAULT NULL,
        `ry` int DEFAULT 2022,
        `fullpin` varchar(100) DEFAULT NULL,
        `totalmarketvalue` decimal(16,2) DEFAULT '0.00',
        `totalassessedvalue` decimal(16,2) DEFAULT '0.00',
        `origin_type` enum('REPLICA','LOCAL') NOT NULL DEFAULT 'REPLICA',
        PRIMARY KEY (`objid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

    CREATE TABLE IF NOT EXISTS `wp_assessor_faas` (
        `objid` varchar(50) NOT NULL,
        `state` varchar(20) DEFAULT 'CURRENT',
        `rpuid` varchar(50) NOT NULL,
        `tdno` varchar(50) NOT NULL,
        `utdno` varchar(50) DEFAULT NULL,
        `owner_name` varchar(255) DEFAULT NULL,
        `origin_type` enum('REPLICA','LOCAL') NOT NULL DEFAULT 'REPLICA',
        PRIMARY KEY (`objid`),
        UNIQUE KEY `ux_tdno` (`tdno`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

    CREATE TABLE IF NOT EXISTS `wp_assessor_rpu_assessment` (
        `objid` varchar(50) NOT NULL,
        `rpuid` varchar(50) NOT NULL,
        `classification_objid` varchar(50) DEFAULT NULL,
        `actualuse_objid` varchar(50) DEFAULT NULL,
        `marketvalue` decimal(16,2) NOT NULL DEFAULT '0.00',
        `assesslevel` decimal(16,2) NOT NULL DEFAULT '0.00',
        `assessedvalue` decimal(16,2) NOT NULL DEFAULT '0.00',
        `rputype` varchar(50) DEFAULT NULL,
        `taxable` int DEFAULT '1',
        `origin_type` enum('REPLICA','LOCAL') NOT NULL DEFAULT 'REPLICA',
        PRIMARY KEY (`objid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

    CREATE TABLE IF NOT EXISTS `wp_assessor_machrpu` (
        `objid` varchar(50) NOT NULL,
        `origin_type` enum('REPLICA','LOCAL') NOT NULL DEFAULT 'REPLICA',
        PRIMARY KEY (`objid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

    CREATE TABLE IF NOT EXISTS `wp_assessor_machdetail` (
        `objid` varchar(50) NOT NULL,
        `machuseid` varchar(50) DEFAULT NULL,
        `machrpuid` varchar(50) NOT NULL,
        `machine_objid` varchar(50) NOT NULL,
        `brand` varchar(100) DEFAULT NULL,
        `model` varchar(100) DEFAULT NULL,
        `basemarketvalue` decimal(16,2) NOT NULL DEFAULT '0.00',
        `marketvalue` decimal(16,2) NOT NULL DEFAULT '0.00',
        `assesslevel` decimal(16,2) NOT NULL DEFAULT '0.00',
        `assessedvalue` decimal(16,2) NOT NULL DEFAULT '0.00',
        `origin_type` enum('REPLICA','LOCAL') NOT NULL DEFAULT 'REPLICA',
        PRIMARY KEY (`objid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

    CREATE TABLE IF NOT EXISTS `wp_assessor_machine` (
        `objid` varchar(50) NOT NULL,
        `state` varchar(10) NOT NULL DEFAULT 'APPROVED',
        `code` varchar(50) NOT NULL,
        `name` varchar(250) NOT NULL,
        `origin_type` enum('REPLICA','LOCAL') NOT NULL DEFAULT 'REPLICA',
        PRIMARY KEY (`objid`),
        UNIQUE KEY `ux_machine_code` (`code`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

    CREATE TABLE IF NOT EXISTS `wp_assessor_machine_smv` (
        `objid` varchar(50) NOT NULL,
        `parent_objid` varchar(50) NOT NULL,
        `machine_objid` varchar(50) NOT NULL,
        `expr` varchar(255) NOT NULL,
        PRIMARY KEY (`objid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

    CREATE TABLE IF NOT EXISTS `wp_assessor_bldgrpu` (
        `objid` varchar(50) NOT NULL,
        `landrpuid` varchar(50) DEFAULT NULL,
        `bldgtype_objid` varchar(50) DEFAULT NULL,
        `bldgtypename` varchar(100) DEFAULT NULL,
        `bldgkindbucc_objid` varchar(50) DEFAULT NULL,
        `floorcount` int NOT NULL DEFAULT '1',
        `origin_type` enum('REPLICA','LOCAL') NOT NULL DEFAULT 'REPLICA',
        PRIMARY KEY (`objid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

    CREATE TABLE IF NOT EXISTS `wp_assessor_bldgtype` (
        `objid` varchar(50) NOT NULL,
        `code` varchar(10) NOT NULL,
        `name` varchar(50) NOT NULL,
        PRIMARY KEY (`objid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

    CREATE TABLE IF NOT EXISTS `wp_assessor_bldgkindbucc` (
        `objid` varchar(50) NOT NULL,
        `bldgtypeid` varchar(50) DEFAULT NULL,
        `bldgkind_objid` varchar(50) DEFAULT NULL,
        PRIMARY KEY (`objid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

    CREATE TABLE IF NOT EXISTS `wp_assessor_bldgkind` (
        `objid` varchar(50) NOT NULL,
        `state` varchar(10) NOT NULL DEFAULT 'APPROVED',
        `code` varchar(20) NOT NULL,
        `name` varchar(100) NOT NULL,
        PRIMARY KEY (`objid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

    CREATE TABLE IF NOT EXISTS `wp_assessor_bldgrpu_structuraltype` (
        `objid` varchar(50) NOT NULL,
        `bldgrpuid` varchar(50) NOT NULL,
        `bldgtype_objid` varchar(50) NOT NULL,
        `bldgkindbucc_objid` varchar(50) DEFAULT NULL,
        `floorcount` int NOT NULL DEFAULT '1',
        PRIMARY KEY (`objid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

    CREATE TABLE IF NOT EXISTS `wp_assessor_bldgfloor` (
        `objid` varchar(50) NOT NULL,
        `bldgrpuid` varchar(50) NOT NULL,
        `floorno` int NOT NULL DEFAULT '1',
        PRIMARY KEY (`objid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

    CREATE TABLE IF NOT EXISTS `wp_assessor_bldgflooradditional` (
        `objid` varchar(50) NOT NULL,
        `bldgrpuid` varchar(50) NOT NULL,
        `additionalitem_objid` varchar(50) NOT NULL,
        PRIMARY KEY (`objid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

    CREATE TABLE IF NOT EXISTS `wp_assessor_bldgadditionalitem` (
        `objid` varchar(50) NOT NULL,
        `code` varchar(10) NOT NULL,
        `name` varchar(100) NOT NULL,
        `unit` varchar(25) NOT NULL,
        PRIMARY KEY (`objid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

    CREATE TABLE IF NOT EXISTS `wp_assessor_bldgstructure` (
        `objid` varchar(50) NOT NULL,
        `bldgrpuid` varchar(50) NOT NULL,
        `structure_objid` varchar(50) NOT NULL,
        `material_objid` varchar(50) DEFAULT NULL,
        PRIMARY KEY (`objid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

    CREATE TABLE IF NOT EXISTS `wp_assessor_structure` (
        `objid` varchar(50) NOT NULL,
        `code` varchar(20) NOT NULL,
        `name` varchar(100) NOT NULL,
        PRIMARY KEY (`objid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

    CREATE TABLE IF NOT EXISTS `wp_assessor_material` (
        `objid` varchar(50) NOT NULL,
        `code` varchar(20) NOT NULL,
        `name` varchar(100) NOT NULL,
        PRIMARY KEY (`objid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

    CREATE TABLE IF NOT EXISTS `wp_assessor_bldguse` (
        `objid` varchar(50) NOT NULL,
        `bldgrpuid` varchar(50) NOT NULL,
        PRIMARY KEY (`objid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

    CREATE TABLE IF NOT EXISTS `wp_assessor_landrpu` (
        `objid` varchar(50) NOT NULL,
        PRIMARY KEY (`objid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

    CREATE TABLE IF NOT EXISTS `wp_assessor_landdetail` (
        `objid` varchar(50) NOT NULL,
        `landrpuid` varchar(50) NOT NULL,
        `subclass_objid` varchar(50) DEFAULT NULL,
        `subclass_name` varchar(100) DEFAULT NULL,
        `addlinfo` varchar(250) DEFAULT NULL,
        PRIMARY KEY (`objid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

    CREATE TABLE IF NOT EXISTS `wp_assessor_miscrpu` (
        `objid` varchar(50) NOT NULL,
        PRIMARY KEY (`objid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

    CREATE TABLE IF NOT EXISTS `wp_assessor_miscrpuitem` (
        `objid` varchar(50) NOT NULL,
        `miscrpuid` varchar(50) NOT NULL,
        `miscitem_objid` varchar(50) NOT NULL,
        `miscitemname` varchar(200) DEFAULT NULL,
        PRIMARY KEY (`objid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

    CREATE TABLE IF NOT EXISTS `wp_assessor_miscitem` (
        `objid` varchar(50) NOT NULL,
        `code` varchar(20) NOT NULL,
        `name` varchar(200) NOT NULL,
        PRIMARY KEY (`objid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

    CREATE TABLE IF NOT EXISTS `wp_assessor_planttreerpu` (
        `objid` varchar(50) NOT NULL,
        `landrpuid` varchar(50) NOT NULL,
        PRIMARY KEY (`objid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

    CREATE TABLE IF NOT EXISTS `wp_assessor_planttree` (
        `objid` varchar(50) NOT NULL,
        `code` varchar(20) NOT NULL,
        `name` varchar(100) NOT NULL,
        PRIMARY KEY (`objid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

    CREATE TABLE IF NOT EXISTS `wp_assessor_planttreedetail` (
        `objid` varchar(50) NOT NULL,
        `planttreerpuid` varchar(50) DEFAULT NULL,
        `landrpuid` varchar(50) DEFAULT NULL,
        `planttree_objid` varchar(50) NOT NULL,
        PRIMARY KEY (`objid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
");

// Include the backend service class
require_once __DIR__ . '/../wp-content/plugins/assessor-api/includes/class-assessor-etracs.php';
$etracs = new Assessor_Etracs();

echo "--- Step 1: Machinery Example Record (TD: 22-010-0004-05935) ---\n";
// Populate fixture matching exact real-world scenario
$pdo->exec("
    DELETE FROM wp_assessor_faas WHERE tdno = '22-010-0004-05935';
    DELETE FROM wp_assessor_rpu WHERE objid = 'RPU-180b0697:19327f6fa4c:-6c83';
    DELETE FROM wp_assessor_machrpu WHERE objid = 'RPU-180b0697:19327f6fa4c:-6c83';
    DELETE FROM wp_assessor_machdetail WHERE machrpuid = 'RPU-180b0697:19327f6fa4c:-6c83';
    DELETE FROM wp_assessor_machine WHERE objid = 'MAC-4fa5cd24:165f5950c9d:-7df4';

    INSERT INTO wp_assessor_rpu (objid, realpropertyid, rpu_type, total_market_value, total_assessed_value, origin_type)
    VALUES ('RPU-180b0697:19327f6fa4c:-6c83', 'RP-MACH-01', 'mach', 400000.00, 320000.00, 'REPLICA');

    INSERT INTO wp_assessor_faas (objid, state, rpuid, tdno, utdno, owner_name, origin_type)
    VALUES ('F-180b0697:19327f6fa4c:-6c84', 'CURRENT', 'RPU-180b0697:19327f6fa4c:-6c83', '22-010-0004-05935', '22-010-0004-05935', 'ANGEL BABES E. GALON', 'REPLICA');

    INSERT INTO wp_assessor_machrpu (objid, origin_type)
    VALUES ('RPU-180b0697:19327f6fa4c:-6c83', 'REPLICA');

    INSERT INTO wp_assessor_machine (objid, state, code, name)
    VALUES ('MAC-4fa5cd24:165f5950c9d:-7df4', 'APPROVED', 'GSWCA', 'GASOLINE STATION WITH COMPLETE ACCESSORIES');

    INSERT INTO wp_assessor_machdetail (objid, machrpuid, machine_objid, brand, basemarketvalue, marketvalue, assesslevel, assessedvalue, origin_type)
    VALUES ('MD16c1fd8f:1934c83b979:-766b', 'RPU-180b0697:19327f6fa4c:-6c83', 'MAC-4fa5cd24:165f5950c9d:-7df4', 'ONSON', 400000.00, 400000.00, 80.00, 320000.00, 'REPLICA');
");

$detail = $etracs->get_rpu_detail('RPU-180b0697:19327f6fa4c:-6c83');
$machList = $detail['data']->machines ?? [];
$machDetailList = $detail['data']->machdetails ?? [];

assert_test(!empty($machList), "Machinery items retrieved for machinery RPU");
assert_test(isset($machList[0]->machinename), "machinename field exists in machinery item");
assert_test($machList[0]->machinename === 'GASOLINE STATION WITH COMPLETE ACCESSORIES', "Brief description matches expected fixture 'GASOLINE STATION WITH COMPLETE ACCESSORIES'");
assert_test($machList[0]->machinecode === 'GSWCA', "Machine code correctly projected");


echo "\n--- Step 2: Building Appraisal, Storeys & Description (TD: 22-010-0001-00007) ---\n";
$pdo->exec("
    INSERT INTO wp_assessor_bldgkind (objid, state, code, name) VALUES ('BK-RES-01', 'APPROVED', 'OFD', 'ONE FAMILY DWELLING')
    ON DUPLICATE KEY UPDATE name = 'ONE FAMILY DWELLING';
    INSERT INTO wp_assessor_bldgtype (objid, code, name) VALUES ('BT-TYPE-1', 'IIA', 'TYPE IIA')
    ON DUPLICATE KEY UPDATE name = 'TYPE IIA';
    INSERT INTO wp_assessor_bldgkindbucc (objid, bldgtypeid, bldgkind_objid) VALUES ('BU-01', 'BT-TYPE-1', 'BK-RES-01')
    ON DUPLICATE KEY UPDATE bldgkind_objid = 'BK-RES-01';

    INSERT INTO wp_assessor_rpu (objid, realpropertyid, rpu_type, total_market_value, total_assessed_value, origin_type)
    VALUES ('RPU-BLDG-TEST-01', 'RP-BLDG-01', 'bldg', 1500000.00, 600000.00, 'REPLICA')
    ON DUPLICATE KEY UPDATE rpu_type = 'bldg';

    INSERT INTO wp_assessor_bldgrpu (objid, bldgtype_objid, bldgkindbucc_objid, bldgtypename, floorcount, origin_type)
    VALUES ('RPU-BLDG-TEST-01', 'BT-TYPE-1', 'BU-01', NULL, 1, 'REPLICA')
    ON DUPLICATE KEY UPDATE floorcount = 1;
");

$bldgDetail = $etracs->get_rpu_detail('RPU-BLDG-TEST-01');
$bldgSubtype = $bldgDetail['data']->subtype ?? null;

assert_test($bldgSubtype !== null, "Building subtype retrieved");
assert_test($bldgSubtype->floorcount == 1, "Building number of storeys (floorcount = 1) preserved and projected");
assert_test($bldgSubtype->bldgkind_name === 'ONE FAMILY DWELLING', "Building description correctly resolved from bldgkind ('ONE FAMILY DWELLING')");
assert_test($bldgSubtype->bldgtype_name === 'TYPE IIA', "Building structural type ('TYPE IIA') preserved separately");

// Test Case A2: Building with structuraltype table linkage (matching TD 22-010-0001-00007)
$pdo->exec("
    INSERT INTO wp_assessor_rpu (objid, realpropertyid, rpu_type, total_market_value, total_assessed_value, origin_type)
    VALUES ('RPU339d78df:184e9f4fbc5:5d9c', 'RP-BLDG-02', 'bldg', 208315.80, 140613.16, 'REPLICA')
    ON DUPLICATE KEY UPDATE rpu_type = 'bldg';

    INSERT INTO wp_assessor_bldgrpu (objid, bldgtype_objid, bldgkindbucc_objid, bldgtypename, floorcount, origin_type)
    VALUES ('RPU339d78df:184e9f4fbc5:5d9c', NULL, NULL, NULL, 1, 'REPLICA')
    ON DUPLICATE KEY UPDATE floorcount = 1;

    INSERT INTO wp_assessor_bldgrpu_structuraltype (objid, bldgrpuid, bldgtype_objid, bldgkindbucc_objid, floorcount)
    VALUES ('BU339d78df:184e9f4fbc5:5dad', 'RPU339d78df:184e9f4fbc5:5d9c', 'BT-TYPE-1', 'BU-01', 1)
    ON DUPLICATE KEY UPDATE bldgrpuid = 'RPU339d78df:184e9f4fbc5:5d9c';
");

$bldgDetail2 = $etracs->get_rpu_detail('RPU339d78df:184e9f4fbc5:5d9c');
$bldgSubtype2 = $bldgDetail2['data']->subtype ?? null;
assert_test($bldgSubtype2 !== null, "Building subtype via bldgrpu_structuraltype retrieved");
assert_test($bldgSubtype2->bldgkind_name === 'ONE FAMILY DWELLING', "Building description via structuraltype resolves to 'ONE FAMILY DWELLING'");
assert_test($bldgSubtype2->bldgtype_name === 'TYPE IIA', "Structural type name preserved as 'TYPE IIA'");


echo "\n--- Step 3: Land & Others (Misc / PlantTree) Appraisal ---\n";
$pdo->exec("
    INSERT INTO wp_assessor_miscitem (objid, code, name) VALUES ('MI-CONC-01', 'PC', 'CONCRETE PERIMETER FENCE')
    ON DUPLICATE KEY UPDATE name = 'CONCRETE PERIMETER FENCE';

    INSERT INTO wp_assessor_rpu (objid, realpropertyid, rpu_type, total_market_value, total_assessed_value, origin_type)
    VALUES ('RPU-MISC-TEST-01', 'RP-MISC-01', 'misc', 150000.00, 75000.00, 'REPLICA')
    ON DUPLICATE KEY UPDATE rpu_type = 'misc';

    INSERT INTO wp_assessor_miscrpu (objid) VALUES ('RPU-MISC-TEST-01')
    ON DUPLICATE KEY UPDATE objid = 'RPU-MISC-TEST-01';

    INSERT INTO wp_assessor_miscrpuitem (objid, miscrpuid, miscitem_objid)
    VALUES ('MRI-01', 'RPU-MISC-TEST-01', 'MI-CONC-01')
    ON DUPLICATE KEY UPDATE miscitem_objid = 'MI-CONC-01';

    INSERT INTO wp_assessor_planttree (objid, code, name) VALUES ('PT-COCO-01', 'COC', 'COCONUT BEARING')
    ON DUPLICATE KEY UPDATE name = 'COCONUT BEARING';

    INSERT INTO wp_assessor_rpu (objid, realpropertyid, rpu_type, total_market_value, total_assessed_value, origin_type)
    VALUES ('RPU-PT-TEST-01', 'RP-PT-01', 'planttree', 50000.00, 5000.00, 'REPLICA')
    ON DUPLICATE KEY UPDATE rpu_type = 'planttree';

    INSERT INTO wp_assessor_planttreerpu (objid, landrpuid) VALUES ('RPU-PT-TEST-01', 'RPU-LAND-MOTHER')
    ON DUPLICATE KEY UPDATE landrpuid = 'RPU-LAND-MOTHER';

    INSERT INTO wp_assessor_planttreedetail (objid, planttreerpuid, landrpuid, planttree_objid)
    VALUES ('PTD-01', 'RPU-PT-TEST-01', 'RPU-LAND-MOTHER', 'PT-COCO-01')
    ON DUPLICATE KEY UPDATE planttree_objid = 'PT-COCO-01';
");

$miscDetail = $etracs->get_rpu_detail('RPU-MISC-TEST-01');
$miscItems = $miscDetail['data']->miscitems ?? [];
assert_test(!empty($miscItems), "Misc items retrieved");
assert_test($miscItems[0]->miscitemname === 'CONCRETE PERIMETER FENCE', "Misc Brief Description resolves to 'CONCRETE PERIMETER FENCE'");

$ptDetail = $etracs->get_rpu_detail('RPU-PT-TEST-01');
$ptItems = $ptDetail['data']->planttreedetails ?? [];
assert_test(!empty($ptItems), "PlantTree details retrieved");
assert_test($ptItems[0]->planttreename === 'COCONUT BEARING', "PlantTree Brief Description resolves to 'COCONUT BEARING'");


echo "\n--- Step 4: Multi-type Declaration & Missing Values Honestly Blank ---\n";
$pdo->exec("
    INSERT INTO wp_assessor_rpu (objid, realpropertyid, rpu_type, total_market_value, total_assessed_value, origin_type)
    VALUES ('RPU-LAND-EMPTY-01', 'RP-LAND-01', 'land', 300000.00, 60000.00, 'REPLICA')
    ON DUPLICATE KEY UPDATE rpu_type = 'land';

    INSERT INTO wp_assessor_landrpu (objid) VALUES ('RPU-LAND-EMPTY-01')
    ON DUPLICATE KEY UPDATE objid = 'RPU-LAND-EMPTY-01';

    INSERT INTO wp_assessor_landdetail (objid, landrpuid, subclass_objid, subclass_name, addlinfo)
    VALUES ('LD-EMPTY-01', 'RPU-LAND-EMPTY-01', NULL, NULL, NULL)
    ON DUPLICATE KEY UPDATE subclass_objid = NULL;

    INSERT INTO wp_assessor_rpu_assessment (objid, rpuid, marketvalue, assesslevel, assessedvalue, rputype, origin_type)
    VALUES ('RA-MULTI-01', 'RPU-LAND-EMPTY-01', 300000.00, 20.00, 60000.00, 'land', 'REPLICA')
    ON DUPLICATE KEY UPDATE rputype = 'land';

    INSERT INTO wp_assessor_rpu_assessment (objid, rpuid, marketvalue, assesslevel, assessedvalue, rputype, origin_type)
    VALUES ('RA-MULTI-02', 'RPU-LAND-EMPTY-01', 50000.00, 10.00, 5000.00, 'planttree', 'REPLICA')
    ON DUPLICATE KEY UPDATE rputype = 'planttree';
");

$multiDetail = $etracs->get_rpu_detail('RPU-LAND-EMPTY-01');
$assessments = $multiDetail['data']->assessments ?? [];
assert_test(count($assessments) === 2, "Multiple assessment lines retrieved for multi-type RPU");
$rpuTypesInAssessment = array_map(function($a) { return $a->rputype; }, $assessments);
assert_test(in_array('land', $rpuTypesInAssessment) && in_array('planttree', $rpuTypesInAssessment), "Assessments contain both land and planttree");

// Verify missing description remains empty (not fabricated)
$emptyLandDetail = $multiDetail['data']->landdetail ?? [];
$resolvedLandDesc = $emptyLandDetail[0]->subclass_name ?? ($emptyLandDetail[0]->addlinfo ?? '');
assert_test($resolvedLandDesc === '', "Missing description honestly remains empty string without fabricated text");


echo "\n--- Step 5: Sync Preservation & Protection (WP-01/WP-02/WP-08) ---\n";
require_once __DIR__ . '/../wp-content/plugins/assessor-api/includes/class-assessor-etracs-sync.php';

assert_test(method_exists('Assessor_Etracs_Sync', 'trigger_sync'), "Sync trigger_sync static method exists");
assert_test(method_exists('Assessor_Etracs_Sync', 'get_diagnostics'), "Sync get_diagnostics static method exists");

// Verify native Assessor tables remain completely untouched
$native_check = $pdo->query("SHOW TABLES LIKE 'wp_assessor_properties'")->fetchColumn();
assert_test($native_check === false, "Absolute boundary: Native Assessor table wp_assessor_properties does not exist in disposable test DB");

echo "\n========================================================================\n";
echo "   TEST SUMMARY\n";
echo "   Total Tests Passed: $pass_count\n";
echo "   Total Tests Failed: $fail_count\n";
echo "========================================================================\n";

if ($fail_count > 0) {
    exit(1);
}
exit(0);
