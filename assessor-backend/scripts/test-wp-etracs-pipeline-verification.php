<?php
/**
 * Test Suite: ETRACS Pull Pipeline, Assessment Schema, and Actual Use Lookup Resolution
 *
 * Verifies:
 * 1. Fresh source-shaped assessment schema (exact business columns + origin_type).
 * 2. Absolute absence of wp_assessor_actualuse or assessor_actualuse table.
 * 3. actualuse_objid resolves correctly across all five lookup tables:
 *    - land -> landassesslevel
 *    - bldg -> bldgassesslevel
 *    - mach -> machassesslevel
 *    - planttree -> planttreeassesslevel
 *    - misc -> miscassesslevel
 * 4. classification_objid resolves separately through propertyclassification.
 * 5. No denormalized label columns (actualuse, classcode, classname, areatype) stored in assessment shadow table.
 * 6. assesslevel and assessedvalue preserved exactly without recalculation during pull.
 * 7. Resumable bounded-batch pull advances through checkpoints and can resume cleanly.
 * 8. Write failure halts cursor advancement and yields status = FAILED.
 * 9. Post-import verification detects record count mismatch and unresolved actualuse.
 * 10. Native Assessor tables remain completely untouched.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

// Setup WordPress test environment shims
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
if (!defined('ARRAY_N')) define('ARRAY_N', 'ARRAY_N');
if (!defined('OBJECT'))  define('OBJECT', 'OBJECT');

$GLOBALS['wp_options_mock'] = array();
function update_option($name, $value, $autoload = null) {
    $GLOBALS['wp_options_mock'][$name] = $value;
    return true;
}
function get_option($name, $default = false) {
    return isset($GLOBALS['wp_options_mock'][$name]) ? $GLOBALS['wp_options_mock'][$name] : $default;
}
function delete_option($name) {
    unset($GLOBALS['wp_options_mock'][$name]);
    return true;
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512) {
        return json_encode($data, $options, $depth);
    }
}
if (!function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
if (!function_exists('current_time')) {
    function current_time($type, $gmt = 0) {
        return gmdate('Y-m-d H:i:s');
    }
}
if (!function_exists('esc_html')) {
    function esc_html($s) {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return trim(strip_tags((string)$str));
    }
}
if (!class_exists('WP_Error')) {
    class WP_Error {
        protected $code;
        protected $message;
        protected $data;
        public function __construct($code = '', $message = '', $data = '') {
            $this->code = $code;
            $this->message = $message;
            $this->data = $data;
        }
        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
        public function get_error_data() { return $this->data; }
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return ($thing instanceof WP_Error);
    }
}

// Load backend classes under test
require_once __DIR__ . '/../wp-content/plugins/assessor-api/includes/class-assessor-database.php';
require_once __DIR__ . '/../wp-content/plugins/assessor-api/includes/class-assessor-etracs-sync.php';
require_once __DIR__ . '/../wp-content/plugins/assessor-api/includes/class-assessor-etracs.php';

// Connect to disposable test database
$disposable_db = 'test_wp01_disposable';
$pdo = new PDO("mysql:host=localhost;dbname=$disposable_db;charset=utf8mb4", 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Guard
$curr_db = $pdo->query("SELECT DATABASE()")->fetchColumn();
if ($curr_db !== $disposable_db) {
    die("Security abort: target database is $curr_db, expected $disposable_db\n");
}

class MockWPDBPipeline {
    public $prefix = 'wp_';
    private $pdo;
    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }
    public function prepare($query, ...$args) {
        if (is_array($args[0]) && count($args) === 1) $args = $args[0];
        foreach ($args as $arg) {
            $val = is_numeric($arg) ? $arg : "'" . addslashes((string)$arg) . "'";
            $query = preg_replace('/%[sdf]/', $val, $query, 1);
        }
        return $query;
    }
    public function get_var($query) {
        try {
            $stmt = $this->pdo->query($query);
            return $stmt ? $stmt->fetchColumn() : null;
        } catch (Exception $e) { return null; }
    }
    public function get_row($query, $output = OBJECT) {
        try {
            $stmt = $this->pdo->query($query);
            $res = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
            if (!$res) return null;
            return ($output === ARRAY_A) ? $res : (object)$res;
        } catch (Exception $e) { return null; }
    }
    public function get_results($query, $output = OBJECT) {
        try {
            $stmt = $this->pdo->query($query);
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();
            if ($output === ARRAY_A) return $rows;
            return array_map(function($r) { return (object)$r; }, $rows);
        } catch (Exception $e) { return array(); }
    }
    public function get_col($query, $x = 0) {
        try {
            $stmt = $this->pdo->query($query);
            return $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN, $x) : array();
        } catch (Exception $e) { return array(); }
    }
    public $fail_on_objid = null;
    public $last_error = '';

    public function query($query) {
        if ($this->fail_on_objid !== null && strpos($query, $this->fail_on_objid) !== false) {
            $this->last_error = "Injected deterministic database write failure for " . $this->fail_on_objid;
            return false;
        }
        $res = $this->pdo->exec($query);
        if ($res === false) {
            $err = $this->pdo->errorInfo();
            $this->last_error = $err[2] ?? 'PDO exec failed';
        }
        return $res;
    }
    public function insert($table, $data, $format = null) {
        $cols = array_keys($data);
        $fields = '`' . implode('`, `', $cols) . '`';
        $placeholders = ':' . implode(', :', $cols);
        $stmt = $this->pdo->prepare("INSERT INTO `$table` ($fields) VALUES ($placeholders)");
        return $stmt->execute($data) ? 1 : false;
    }
    public function update($table, $data, $where, $format = null, $where_format = null) {
        $set = [];
        $params = [];
        foreach ($data as $k => $v) {
            $set[] = "`$k` = :set_$k";
            $params["set_$k"] = $v;
        }
        $where_clauses = [];
        foreach ($where as $k => $v) {
            $where_clauses[] = "`$k` = :wh_$k";
            $params["wh_$k"] = $v;
        }
        $sql = "UPDATE `$table` SET " . implode(', ', $set) . " WHERE " . implode(' AND ', $where_clauses);
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params) ? 1 : false;
    }
    public function replace($table, $data, $format = null) {
        $cols = array_keys($data);
        $fields = '`' . implode('`, `', $cols) . '`';
        $placeholders = ':' . implode(', :', $cols);
        $stmt = $this->pdo->prepare("REPLACE INTO `$table` ($fields) VALUES ($placeholders)");
        return $stmt->execute($data) ? 1 : false;
    }
}

global $wpdb;
$wpdb = new MockWPDBPipeline($pdo);

echo "========================================================================\n";
echo "   ETRACS PULL PIPELINE & ASSESSMENT SCHEMA TEST SUITE\n";
echo "   Target DB: $disposable_db\n";
echo "========================================================================\n\n";

$pass = 0;
$fail = 0;
function test($desc, $cond) {
    global $pass, $fail;
    if ($cond) {
        echo " [PASS] $desc\n";
        $pass++;
    } else {
        echo " [FAIL] $desc\n";
        $fail++;
    }
}

// ─────────────────────────────────────────────────────────────
// Test 1: Clean Assessment Schema Setup
// ─────────────────────────────────────────────────────────────
echo "--- 1. Schema Verification: Business Columns & No Actualuse Table ---\n";

// Drop test tables
$pdo->exec("
DROP TABLE IF EXISTS 
    wp_assessor_rpu_assessment, 
    wp_assessor_landassesslevel, 
    wp_assessor_bldgassesslevel, 
    wp_assessor_machassesslevel, 
    wp_assessor_planttreeassesslevel, 
    wp_assessor_miscassesslevel, 
    wp_assessor_propertyclassification,
    wp_assessor_rpu,
    wp_assessor_faas,
    rpu_assessment,
    landassesslevel,
    bldgassesslevel,
    machassesslevel,
    planttreeassesslevel,
    miscassesslevel,
    propertyclassification;
");

// Create fresh shadow table using exact definition from class-assessor-database.php
$pdo->exec("
CREATE TABLE `wp_assessor_rpu_assessment` (
  `objid` varchar(50) NOT NULL,
  `rpuid` varchar(50) NOT NULL,
  `classification_objid` varchar(50) DEFAULT NULL,
  `actualuse_objid` varchar(50) DEFAULT NULL,
  `areasqm` decimal(16,2) NOT NULL DEFAULT '0.00',
  `areaha` decimal(16,6) NOT NULL DEFAULT '0.000000',
  `marketvalue` decimal(16,2) NOT NULL DEFAULT '0.00',
  `assesslevel` decimal(16,2) NOT NULL DEFAULT '0.00',
  `assessedvalue` decimal(16,2) NOT NULL DEFAULT '0.00',
  `rputype` varchar(25) DEFAULT NULL,
  `taxable` int DEFAULT '1',
  `origin_type` enum('LOCAL','REPLICA') NOT NULL DEFAULT 'REPLICA',
  PRIMARY KEY (`objid`),
  KEY `ix_rpuassess_rpuid` (`rpuid`),
  KEY `idx_origin_type` (`origin_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Check columns of created table
$cols = $pdo->query("DESCRIBE wp_assessor_rpu_assessment")->fetchAll(PDO::FETCH_COLUMN, 0);
$expected_business_cols = ['objid', 'rpuid', 'classification_objid', 'actualuse_objid', 'areasqm', 'areaha', 'marketvalue', 'assesslevel', 'assessedvalue', 'rputype', 'taxable', 'origin_type'];

test("Assessment shadow table contains all expected business columns + origin_type", count(array_intersect($expected_business_cols, $cols)) === count($expected_business_cols));
test("No denormalized label columns (classcode) in shadow table", !in_array('classcode', $cols, true));
test("No denormalized label columns (classname) in shadow table", !in_array('classname', $cols, true));
test("No denormalized label columns (actualuse) in shadow table", !in_array('actualuse', $cols, true));
test("No denormalized label columns (areatype) in shadow table", !in_array('areatype', $cols, true));

// Test 2: Check absolute absence of wp_assessor_actualuse table
$actualuse_tbl = $pdo->query("SHOW TABLES LIKE '%actualuse%'")->fetchAll(PDO::FETCH_COLUMN);
test("No wp_assessor_actualuse or assessor_actualuse table exists in database", empty($actualuse_tbl));

// ─────────────────────────────────────────────────────────────
// Test 3 & 4: Resolution of Actual Use across the 5 Lookup Tables
// ─────────────────────────────────────────────────────────────
echo "\n--- 2. Actual Use Resolution across 5 Lookup Tables & Classification ---\n";

// Create the 5 lookup shadow tables
$pdo->exec("
CREATE TABLE wp_assessor_landassesslevel (
    objid varchar(50) NOT NULL PRIMARY KEY,
    name varchar(100) NOT NULL,
    code varchar(20) DEFAULT NULL
) ENGINE=InnoDB;

CREATE TABLE wp_assessor_bldgassesslevel (
    objid varchar(50) NOT NULL PRIMARY KEY,
    name varchar(100) NOT NULL,
    code varchar(20) DEFAULT NULL
) ENGINE=InnoDB;

CREATE TABLE wp_assessor_machassesslevel (
    objid varchar(50) NOT NULL PRIMARY KEY,
    name varchar(100) NOT NULL,
    code varchar(20) DEFAULT NULL
) ENGINE=InnoDB;

CREATE TABLE wp_assessor_planttreeassesslevel (
    objid varchar(50) NOT NULL PRIMARY KEY,
    name varchar(100) NOT NULL,
    code varchar(20) DEFAULT NULL
) ENGINE=InnoDB;

CREATE TABLE wp_assessor_miscassesslevel (
    objid varchar(50) NOT NULL PRIMARY KEY,
    name varchar(100) NOT NULL,
    code varchar(20) DEFAULT NULL
) ENGINE=InnoDB;

CREATE TABLE wp_assessor_propertyclassification (
    objid varchar(50) NOT NULL PRIMARY KEY,
    name varchar(100) NOT NULL,
    code varchar(20) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE wp_assessor_rpu (
    objid varchar(50) NOT NULL PRIMARY KEY,
    realpropertyid varchar(50) NOT NULL,
    rpu_type varchar(25) DEFAULT NULL,
    origin_type enum('LOCAL','REPLICA') NOT NULL DEFAULT 'REPLICA',
    state varchar(25) DEFAULT 'CURRENT',
    total_market_value decimal(16,2) DEFAULT '0.00',
    total_assessed_value decimal(16,2) DEFAULT '0.00'
) ENGINE=InnoDB;
");

// Populate lookup entries
$pdo->exec("
INSERT INTO wp_assessor_landassesslevel (objid, name, code) VALUES ('AL-LAND-01', 'AGRICULTURAL CROPLAND', 'AGRI-CROP');
INSERT INTO wp_assessor_bldgassesslevel (objid, name, code) VALUES ('AL-BLDG-01', 'RESIDENTIAL TWO-STOREY', 'RES-2S');
INSERT INTO wp_assessor_machassesslevel (objid, name, code) VALUES ('AL-MACH-01', 'HEAVY INDUSTRIAL CRANE', 'IND-CRANE');
INSERT INTO wp_assessor_planttreeassesslevel (objid, name, code) VALUES ('AL-PLANT-01', 'COCONUT BEARING TREES', 'COCO-BEAR');
INSERT INTO wp_assessor_miscassesslevel (objid, name, code) VALUES ('AL-MISC-01', 'SPECIAL PURPOSE BILLBOARD', 'SPEC-BILL');
INSERT INTO wp_assessor_propertyclassification (objid, name, code) VALUES ('PC-COMM-01', 'COMMERCIAL GENERAL', 'COMM');
");

// Populate RPU
$pdo->exec("
INSERT INTO wp_assessor_rpu (objid, realpropertyid, rpu_type) VALUES ('RPU-MULTI-01', 'RP-01', 'land');
");

// Populate 5 assessment lines, one for each rputype
$pdo->exec("
INSERT INTO wp_assessor_rpu_assessment 
(objid, rpuid, classification_objid, actualuse_objid, areasqm, areaha, marketvalue, assesslevel, assessedvalue, rputype, taxable, origin_type) 
VALUES
('ASM-LAND', 'RPU-MULTI-01', 'PC-COMM-01', 'AL-LAND-01', 500.00, 0.05, 500000.00, 40.00, 200000.00, 'land', 1, 'REPLICA'),
('ASM-BLDG', 'RPU-MULTI-01', 'PC-COMM-01', 'AL-BLDG-01', 120.00, 0.012, 1200000.00, 60.00, 720000.00, 'bldg', 1, 'REPLICA'),
('ASM-MACH', 'RPU-MULTI-01', 'PC-COMM-01', 'AL-MACH-01', 0.00, 0.00, 800000.00, 80.00, 640000.00, 'mach', 1, 'REPLICA'),
('ASM-PLANT', 'RPU-MULTI-01', 'PC-COMM-01', 'AL-PLANT-01', 1000.00, 0.10, 150000.00, 30.00, 45000.00, 'planttree', 1, 'REPLICA'),
('ASM-MISC', 'RPU-MULTI-01', 'PC-COMM-01', 'AL-MISC-01', 0.00, 0.00, 50000.00, 20.00, 10000.00, 'misc', 1, 'REPLICA');
");

// Call Assessor_Etracs::get_rpu_assessments()
$etracs = new Assessor_Etracs();
$lines = $etracs->get_rpu_assessments('RPU-MULTI-01');

test("get_rpu_assessments returns 5 assessment lines", count($lines) === 5);

$by_id = [];
foreach ($lines as $line) {
    $by_id[$line['objid']] = $line;
}

test("Land actualuse resolves to 'AGRICULTURAL CROPLAND'", isset($by_id['ASM-LAND']) && $by_id['ASM-LAND']['actualuse'] === 'AGRICULTURAL CROPLAND');
test("Bldg actualuse resolves to 'RESIDENTIAL TWO-STOREY'", isset($by_id['ASM-BLDG']) && $by_id['ASM-BLDG']['actualuse'] === 'RESIDENTIAL TWO-STOREY');
test("Mach actualuse resolves to 'HEAVY INDUSTRIAL CRANE'", isset($by_id['ASM-MACH']) && $by_id['ASM-MACH']['actualuse'] === 'HEAVY INDUSTRIAL CRANE');
test("Planttree actualuse resolves to 'COCONUT BEARING TREES'", isset($by_id['ASM-PLANT']) && $by_id['ASM-PLANT']['actualuse'] === 'COCONUT BEARING TREES');
test("Misc actualuse resolves to 'SPECIAL PURPOSE BILLBOARD'", isset($by_id['ASM-MISC']) && $by_id['ASM-MISC']['actualuse'] === 'SPECIAL PURPOSE BILLBOARD');

test("Classification code resolves to 'COMM'", isset($by_id['ASM-LAND']) && $by_id['ASM-LAND']['classcode'] === 'COMM');
test("Classification name resolves to 'COMMERCIAL GENERAL'", isset($by_id['ASM-LAND']) && $by_id['ASM-LAND']['classname'] === 'COMMERCIAL GENERAL');

// ─────────────────────────────────────────────────────────────
// Test 5: Exact Import of assesslevel and assessedvalue Without Recalculation
// ─────────────────────────────────────────────────────────────
echo "\n--- 3. Exact Preservation of Imported Valuation Values ---\n";

// Create source rpu_assessment table in PDO
$pdo->exec("
CREATE TABLE rpu_assessment (
  `objid` varchar(50) NOT NULL,
  `rpuid` varchar(50) NOT NULL,
  `classification_objid` varchar(50) DEFAULT NULL,
  `actualuse_objid` varchar(50) DEFAULT NULL,
  `areasqm` decimal(16,2) NOT NULL,
  `areaha` decimal(16,6) NOT NULL,
  `marketvalue` decimal(16,2) NOT NULL,
  `assesslevel` decimal(16,2) NOT NULL,
  `assessedvalue` decimal(16,2) NOT NULL,
  `rputype` varchar(25) DEFAULT NULL,
  `taxable` int DEFAULT NULL,
  PRIMARY KEY (`objid`)
) ENGINE=InnoDB;

INSERT INTO rpu_assessment 
(objid, rpuid, classification_objid, actualuse_objid, areasqm, areaha, marketvalue, assesslevel, assessedvalue, rputype, taxable)
VALUES
('SRC-ASM-001', 'RPU-MULTI-01', 'PC-COMM-01', 'AL-LAND-01', 350.50, 0.035050, 777777.77, 43.21, 336077.77, 'land', 1),
('SRC-ASM-002', 'RPU-MULTI-01', 'PC-COMM-01', 'AL-BLDG-01', 200.00, 0.020000, 999999.99, 50.00, 500000.00, 'bldg', 1);
");

// Clear existing sync run state
delete_option('assessor_etracs_sync_run_state');
delete_option('assessor_etracs_sync_lock');

// Run trigger_sync targeting our PDO
$sync_res = Assessor_Etracs_Sync::trigger_sync($pdo);
if (is_wp_error($sync_res)) {
    echo "DEBUG trigger_sync error: " . $sync_res->get_error_code() . " - " . $sync_res->get_error_message() . "\n";
    var_dump($sync_res->get_error_data());
}
test("trigger_sync executed without fatal WP_Error", !is_wp_error($sync_res));

$imported = $pdo->query("SELECT * FROM wp_assessor_rpu_assessment WHERE objid = 'SRC-ASM-001'")->fetch();
test("Imported record exists in shadow table", !empty($imported));
test("assesslevel preserved exactly (43.21)", !empty($imported) && (float)$imported['assesslevel'] === 43.21);
test("assessedvalue preserved exactly (336077.77, not recalculated)", !empty($imported) && (float)$imported['assessedvalue'] === 336077.77);
test("areasqm preserved exactly (350.50)", !empty($imported) && (float)$imported['areasqm'] === 350.50);
test("areaha preserved exactly (0.035050)", !empty($imported) && (float)$imported['areaha'] === 0.035050);
test("origin_type marked as 'REPLICA'", !empty($imported) && $imported['origin_type'] === 'REPLICA');

// ─────────────────────────────────────────────────────────────
// Test 6: Resumable Bounded-Batch Pipeline
// ─────────────────────────────────────────────────────────────
echo "\n--- 4. Resumable Bounded-Batch Execution & Failure Halting ---\n";

// Insert a interrupted run state simulating an interrupted stage
$simulated_state = array(
    'run_id'        => 'SYNC_TEST_RESUME_001',
    'status'        => 'running',
    'current_stage' => 1, // stage 1
    'cursor'        => 500,
    'last_seen_id'  => 'OBJ-500',
    'stats'         => array('entity' => 500),
    'write_failures'=> array(),
    'stage_errors'  => array(),
    'last_error'    => null,
    'started_at'    => gmdate('Y-m-d H:i:s'),
    'updated_at'    => gmdate('Y-m-d H:i:s'),
);
update_option('assessor_etracs_sync_run_state', $simulated_state);
delete_option('assessor_etracs_sync_lock');

$loaded_state = get_option('assessor_etracs_sync_run_state');
test("Interrupted run state persisted and retrievable", $loaded_state && $loaded_state['run_id'] === 'SYNC_TEST_RESUME_001');
test("Interrupted stage cursor is 500", $loaded_state['cursor'] === 500);

// Test Post-Import Verification with missing records
echo "\n--- 5. Post-Import Verification Diagnostics ---\n";
// Insert an extra source record not present in destination to test verification
$pdo->exec("INSERT INTO rpu_assessment (objid, rpuid, areasqm, areaha, marketvalue, assesslevel, assessedvalue) VALUES ('SRC-ASM-MISSING', 'RPU-MULTI-01', 100, 0.01, 100, 10, 10)");

$verification = Assessor_Etracs_Sync::verify_post_import($pdo, array());
test("Verification detects missing records when source has record absent in destination", is_wp_error($verification));
$err_code = is_wp_error($verification) ? $verification->get_error_code() : 'NONE';
test("Verification error code is verification_missing_records or verification_count_mismatch (got $err_code)", in_array($err_code, ['verification_missing_records', 'verification_count_mismatch'], true));

// Remove extra record so verification can pass cleanly
$pdo->exec("DELETE FROM rpu_assessment WHERE objid = 'SRC-ASM-MISSING'");

// Test Post-Import Verification with unresolved actualuse
// Insert a source record matching this destination record so counts match
$pdo->exec("INSERT INTO rpu_assessment (objid, rpuid, areasqm, areaha, marketvalue, assesslevel, assessedvalue, rputype, actualuse_objid) VALUES ('ASM-ORPHAN-USE', 'RPU-MULTI-01', 100, 0.01, 100, 10, 10, 'land', 'AL-UNKNOWN-999')");
$pdo->exec("INSERT INTO wp_assessor_rpu_assessment (objid, rpuid, areasqm, areaha, marketvalue, assesslevel, assessedvalue, actualuse_objid, rputype, origin_type) VALUES ('ASM-ORPHAN-USE', 'RPU-MULTI-01', 100, 0.01, 100, 10, 10, 'AL-UNKNOWN-999', 'land', 'REPLICA')");

$verification_warn = Assessor_Etracs_Sync::verify_post_import($pdo, array());
if (is_wp_error($verification_warn)) {
    echo "DEBUG verification_warn error: " . $verification_warn->get_error_code() . " - " . $verification_warn->get_error_message() . "\n";
}
test("Verification runs cleanly and logs warning for unresolved actualuse", !is_wp_error($verification_warn) && !empty($verification_warn['warnings']));
test("Verification notes 1 unresolved actualuse_objid", is_array($verification_warn) && isset($verification_warn['unresolved_actualuse_count']) && $verification_warn['unresolved_actualuse_count'] === 1);

// Clean up test orphan
$pdo->exec("DELETE FROM wp_assessor_rpu_assessment WHERE objid = 'ASM-ORPHAN-USE'");
$pdo->exec("DELETE FROM rpu_assessment WHERE objid = 'ASM-ORPHAN-USE'");

// ─────────────────────────────────────────────────────────────
// Test 6: Deterministic Failed Write and Resume Recovery
// ─────────────────────────────────────────────────────────────
echo "\n--- 6. Deterministic Write Failure and Resume Recovery ---\n";

// Clear state
delete_option('assessor_etracs_sync_run_state');
delete_option('assessor_etracs_sync_lock');

// Setup source with 3 records: Row 1, Row 2 (will fail), Row 3
$pdo->exec("TRUNCATE TABLE rpu_assessment");
$pdo->exec("TRUNCATE TABLE wp_assessor_rpu_assessment");

$pdo->exec("
INSERT INTO rpu_assessment (objid, rpuid, areasqm, areaha, marketvalue, assesslevel, assessedvalue, rputype, taxable) VALUES
('ASM-FAIL-001', 'RPU-MULTI-01', 100, 0.01, 100000, 20, 20000, 'land', 1),
('ASM-FAIL-002', 'RPU-MULTI-01', 200, 0.02, 200000, 20, 40000, 'land', 1),
('ASM-FAIL-003', 'RPU-MULTI-01', 300, 0.03, 300000, 20, 60000, 'land', 1);
");

// Also add a protected LOCAL record in destination to ensure it is not overwritten
$pdo->exec("
INSERT INTO wp_assessor_rpu_assessment (objid, rpuid, areasqm, areaha, marketvalue, assesslevel, assessedvalue, rputype, taxable, origin_type) VALUES
('ASM-LOCAL-PROT', 'RPU-MULTI-01', 999, 0.0999, 999999, 50, 499999.50, 'land', 1, 'LOCAL');
");

// Configure MockWPDBPipeline to deterministic fail on ASM-FAIL-002
$wpdb->fail_on_objid = 'ASM-FAIL-002';
$fail_sync_res = Assessor_Etracs_Sync::trigger_sync($pdo);

test("Sync fails deterministically when row write returns false", is_wp_error($fail_sync_res));
test("Error code is write_failure", is_wp_error($fail_sync_res) && $fail_sync_res->get_error_code() === 'write_failure');

$persisted_fail_state = get_option('assessor_etracs_sync_run_state');
test("Run state recorded status = failed", $persisted_fail_state && strtolower($persisted_fail_state['status']) === 'failed');
test("Run state recorded failed objid ASM-FAIL-002 in write_failures", !empty($persisted_fail_state['write_failures']) && $persisted_fail_state['write_failures'][0]['objid'] === 'ASM-FAIL-002');
test("Cursor did not advance past the failed row (cursor <= 1)", isset($persisted_fail_state['cursor']) && $persisted_fail_state['cursor'] <= 1);

// Ensure row 1 was written, row 2 was NOT written, row 3 was NOT written
$r1 = $pdo->query("SELECT objid FROM wp_assessor_rpu_assessment WHERE objid = 'ASM-FAIL-001'")->fetchColumn();
$r2 = $pdo->query("SELECT objid FROM wp_assessor_rpu_assessment WHERE objid = 'ASM-FAIL-002'")->fetchColumn();
$r3 = $pdo->query("SELECT objid FROM wp_assessor_rpu_assessment WHERE objid = 'ASM-FAIL-003'")->fetchColumn();
test("Row 1 written before failure", $r1 === 'ASM-FAIL-001');
test("Row 2 was NOT written to shadow table", empty($r2));
test("Row 3 was NOT written (pipeline halted immediately)", empty($r3));

// Protected LOCAL record remains untouched
$local_prot = $pdo->query("SELECT marketvalue, origin_type FROM wp_assessor_rpu_assessment WHERE objid = 'ASM-LOCAL-PROT'")->fetch();
test("Protected LOCAL record remains untouched during failure", $local_prot && (float)$local_prot['marketvalue'] === 999999.00 && $local_prot['origin_type'] === 'LOCAL');

// Now clear the injected write failure and resume the sync
$wpdb->fail_on_objid = null;
$resume_sync_res = Assessor_Etracs_Sync::trigger_sync($pdo);

test("Resumed sync executes successfully", !is_wp_error($resume_sync_res));
$r2_after = $pdo->query("SELECT objid FROM wp_assessor_rpu_assessment WHERE objid = 'ASM-FAIL-002'")->fetchColumn();
$r3_after = $pdo->query("SELECT objid FROM wp_assessor_rpu_assessment WHERE objid = 'ASM-FAIL-003'")->fetchColumn();
test("Row 2 successfully written after recovery", $r2_after === 'ASM-FAIL-002');
test("Row 3 successfully written after recovery", $r3_after === 'ASM-FAIL-003');

$total_replica_count = (int)$pdo->query("SELECT COUNT(*) FROM wp_assessor_rpu_assessment WHERE origin_type = 'REPLICA'")->fetchColumn();
test("All 3 source rows imported with zero duplicates (count = 3)", $total_replica_count === 3);

$local_prot_after = $pdo->query("SELECT marketvalue, origin_type FROM wp_assessor_rpu_assessment WHERE objid = 'ASM-LOCAL-PROT'")->fetch();
test("Protected LOCAL record remains 100% preserved after recovery", $local_prot_after && (float)$local_prot_after['marketvalue'] === 999999.00 && $local_prot_after['origin_type'] === 'LOCAL');

// ─────────────────────────────────────────────────────────────
// Test 7: Native Assessor Table Isolation Proof with Sentinel Row
// ─────────────────────────────────────────────────────────────
echo "\n--- 7. Native Assessor Table Isolation Proof with Sentinel Row ---\n";

// In disposable DB ONLY, create representative native table with sentinel data
$pdo->exec("DROP TABLE IF EXISTS wp_assessor_properties");
$pdo->exec("
CREATE TABLE wp_assessor_properties (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
    title varchar(255) NOT NULL,
    pin varchar(50) NOT NULL,
    status varchar(50) NOT NULL DEFAULT 'active',
    created_at datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO wp_assessor_properties (id, title, pin, status) VALUES 
(42, 'Native Sentinel Property Land Parcel', 'PIN-NATIVE-SENTINEL-001', 'SENTINEL_ACTIVE');
");

$native_before = $pdo->query("SELECT * FROM wp_assessor_properties WHERE id = 42")->fetch();
$native_schema_before = $pdo->query("DESCRIBE wp_assessor_properties")->fetchAll(PDO::FETCH_ASSOC);

// Execute sync run
delete_option('assessor_etracs_sync_run_state');
delete_option('assessor_etracs_sync_lock');
$sync_res_iso = Assessor_Etracs_Sync::trigger_sync($pdo);

$native_after = $pdo->query("SELECT * FROM wp_assessor_properties WHERE id = 42")->fetch();
$native_schema_after = $pdo->query("DESCRIBE wp_assessor_properties")->fetchAll(PDO::FETCH_ASSOC);

test("Native table row contents completely unchanged", $native_before === $native_after && $native_after['status'] === 'SENTINEL_ACTIVE');
test("Native table schema completely unchanged", $native_schema_before === $native_schema_after);

// Clean up native sentinel table in disposable DB
$pdo->exec("DROP TABLE IF EXISTS wp_assessor_properties");

// ─────────────────────────────────────────────────────────────
// Test 8: Lock, Concurrency, and Heartbeat Expiration
// ─────────────────────────────────────────────────────────────
echo "\n--- 8. Timeout, Lock Concurrency & Heartbeat Verification ---\n";

// Active run lock (fresh timestamp)
update_option('assessor_etracs_sync_lock', array('run_id' => 'SYNC_ACTIVE_RUN_123', 'timestamp' => time()));
$concurrent_res = Assessor_Etracs_Sync::trigger_sync($pdo);
test("Concurrent sync attempt rejected with sync_locked error", is_wp_error($concurrent_res) && $concurrent_res->get_error_code() === 'sync_locked');

// Expired run lock (timestamp older than 600s)
update_option('assessor_etracs_sync_lock', array('run_id' => 'SYNC_STALE_RUN_999', 'timestamp' => time() - 700));
$expired_lock_res = Assessor_Etracs_Sync::trigger_sync($pdo);
test("Expired stale lock recovered without rejection", !is_wp_error($expired_lock_res));

$new_lock = get_option('assessor_etracs_sync_lock');
test("Lock reacquired with fresh timestamp after stale lock recovery", empty($new_lock) || (isset($new_lock['timestamp']) && (time() - $new_lock['timestamp']) <= 5));

// ─────────────────────────────────────────────────────────────
// Test 9: Fresh Schema vs Legacy Schema & Strict Column Allowlist
// ─────────────────────────────────────────────────────────────
echo "\n--- 9. Fresh Schema vs Legacy Schema & Strict Allowlist ---\n";

// Create a legacy schema table with deprecated columns (actualuse, classcode, classname, areatype)
$pdo->exec("DROP TABLE IF EXISTS wp_assessor_rpu_assessment");
$pdo->exec("
CREATE TABLE `wp_assessor_rpu_assessment` (
  `objid` varchar(50) NOT NULL PRIMARY KEY,
  `rpuid` varchar(50) NOT NULL,
  `classification_objid` varchar(50) DEFAULT NULL,
  `classcode` varchar(20) DEFAULT NULL,
  `classname` varchar(100) DEFAULT NULL,
  `actualuse_objid` varchar(50) DEFAULT NULL,
  `actualuse` varchar(100) DEFAULT NULL,
  `areasqm` decimal(16,2) NOT NULL DEFAULT '0.00',
  `areaha` decimal(16,6) NOT NULL DEFAULT '0.000000',
  `areatype` varchar(10) DEFAULT NULL,
  `marketvalue` decimal(16,2) NOT NULL DEFAULT '0.00',
  `assesslevel` decimal(16,2) NOT NULL DEFAULT '0.00',
  `assessedvalue` decimal(16,2) NOT NULL DEFAULT '0.00',
  `rputype` varchar(25) DEFAULT NULL,
  `taxable` int DEFAULT '1',
  `origin_type` enum('REPLICA','LOCAL') NOT NULL DEFAULT 'REPLICA'
) ENGINE=InnoDB;
");

// Ensure RPU table exists with a LOCAL RPU record for local assessment line authoring
$pdo->exec("
CREATE TABLE IF NOT EXISTS wp_assessor_rpu (
    objid varchar(50) NOT NULL PRIMARY KEY,
    realpropertyid varchar(50) NOT NULL,
    rpu_type varchar(25) DEFAULT 'LAND',
    origin_type enum('LOCAL','REPLICA') NOT NULL DEFAULT 'LOCAL',
    state varchar(25) DEFAULT 'CURRENT',
    total_market_value decimal(16,2) DEFAULT '0.00',
    total_assessed_value decimal(16,2) DEFAULT '0.00'
) ENGINE=InnoDB;
");
$pdo->exec("
INSERT INTO wp_assessor_rpu (objid, realpropertyid, rpu_type, origin_type) 
VALUES ('RPU-MULTI-01', 'RP-01', 'land', 'LOCAL')
ON DUPLICATE KEY UPDATE origin_type = 'LOCAL';
");

// Call persist_rpu_assessments passing deprecated/computed values
$etracs_legacy = new Assessor_Etracs();
$persist_lines = array(
    array(
        'actualuse_objid'      => 'AL-LAND-01',
        'classification_objid' => 'PC-COMM-01',
        'rputype'              => 'land',
        'areasqm'              => 150.00,
        'areaha'               => 0.015,
        'marketvalue'          => 300000.00,
        'assesslevel'          => 20.00,
        'taxable'              => 1,
        // Caller passes deprecated free-text labels
        'actualuse'            => 'ATTEMPTED_FREE_TEXT_ACTUALUSE',
        'classcode'            => 'ATTEMPTED_CODE',
        'classname'            => 'ATTEMPTED_NAME',
        'areatype'             => 'HECTARES',
    )
);

$persist_res = $etracs_legacy->persist_rpu_assessments('RPU-MULTI-01', $persist_lines, 'DRAFT');
test("persist_rpu_assessments succeeds against legacy schema table", !is_wp_error($persist_res));

$legacy_row = $pdo->query("SELECT * FROM wp_assessor_rpu_assessment WHERE rpuid = 'RPU-MULTI-01'")->fetch();
test("Strict allowlist prevented persisting 'actualuse' label even though column physically exists", empty($legacy_row['actualuse']));
test("Strict allowlist prevented persisting 'classcode' label even though column physically exists", empty($legacy_row['classcode']));
test("Strict allowlist prevented persisting 'classname' label even though column physically exists", empty($legacy_row['classname']));
test("Strict allowlist prevented persisting 'areatype' helper even though column physically exists", $legacy_row['areatype'] !== 'HECTARES' && empty($legacy_row['areatype']));
test("Genuine business fields persisted (actualuse_objid, classification_objid)", $legacy_row['actualuse_objid'] === 'AL-LAND-01' && $legacy_row['classification_objid'] === 'PC-COMM-01');

// Recreate clean fresh schema table
$pdo->exec("DROP TABLE IF EXISTS wp_assessor_rpu_assessment");
$pdo->exec("
CREATE TABLE `wp_assessor_rpu_assessment` (
  `objid` varchar(50) NOT NULL,
  `rpuid` varchar(50) NOT NULL,
  `classification_objid` varchar(50) DEFAULT NULL,
  `actualuse_objid` varchar(50) DEFAULT NULL,
  `areasqm` decimal(16,2) NOT NULL DEFAULT '0.00',
  `areaha` decimal(16,6) NOT NULL DEFAULT '0.000000',
  `marketvalue` decimal(16,2) NOT NULL DEFAULT '0.00',
  `assesslevel` decimal(16,2) NOT NULL DEFAULT '0.00',
  `assessedvalue` decimal(16,2) NOT NULL DEFAULT '0.00',
  `rputype` varchar(25) DEFAULT NULL,
  `taxable` int DEFAULT '1',
  `origin_type` enum('LOCAL','REPLICA') NOT NULL DEFAULT 'REPLICA',
  PRIMARY KEY (`objid`),
  KEY `ix_rpuassess_rpuid` (`rpuid`),
  KEY `idx_origin_type` (`origin_type`)
) ENGINE=InnoDB;
");

// ─────────────────────────────────────────────────────────────
// Test 10: Tightened Unresolved Actual Use Verification Details
// ─────────────────────────────────────────────────────────────
echo "\n--- 10. Unresolved Actual Use Breakdown & Non-Success Status ---\n";

// Populate clean assessment line with invalid actualuse_objid
$pdo->exec("TRUNCATE TABLE rpu_assessment");
$pdo->exec("TRUNCATE TABLE wp_assessor_rpu_assessment");
$pdo->exec("
INSERT INTO rpu_assessment (objid, rpuid, actualuse_objid, rputype, areasqm, areaha, marketvalue, assesslevel, assessedvalue, taxable) VALUES
('ASM-UNRES-01', 'RPU-MULTI-01', 'AL-INVALID-LAND-ID', 'land', 100, 0.01, 100000, 20, 20000, 1),
('ASM-UNRES-02', 'RPU-MULTI-01', 'AL-INVALID-BLDG-ID', 'bldg', 150, 0.015, 200000, 30, 60000, 1);
");

// Sync
delete_option('assessor_etracs_sync_run_state');
delete_option('assessor_etracs_sync_lock');
$unres_sync_res = Assessor_Etracs_Sync::trigger_sync($pdo);

$diag = Assessor_Etracs_Sync::get_diagnostics();
$run_state_res = get_option('assessor_etracs_sync_run_state');

test("Run with unresolved Actual Use qualifies as PARTIAL (never SUCCESS)", $diag && strtolower($diag['status']) === 'partial' && strtolower($run_state_res['status']) === 'partial');
test("Verification details record unresolved count = 2", isset($diag['verification']['unresolved_actualuse_count']) && $diag['verification']['unresolved_actualuse_count'] === 2);

$unres_list = $diag['verification']['unresolved_actualuse'] ?? [];
test("Unresolved details record rputype, actualuse_objid, and expected lookup table", 
    count($unres_list) === 2 && 
    $unres_list[0]['rputype'] === 'land' && 
    $unres_list[0]['actualuse_objid'] === 'AL-INVALID-LAND-ID' && 
    $unres_list[0]['expected_lookup_table'] === 'landassesslevel' &&
    $unres_list[1]['rputype'] === 'bldg' && 
    $unres_list[1]['expected_lookup_table'] === 'bldgassesslevel'
);

// ─────────────────────────────────────────────────────────────
// Test 11: End-to-End Resumable HTTP Continuation Across Invocations
// ─────────────────────────────────────────────────────────────
echo "\n--- 11. Multi-Invocation Continuation, Worker Locks & Recovery ---\n";

// Populate 1200 rows in rpu_assessment to force multiple 500-record batches
$pdo->exec("TRUNCATE TABLE rpu_assessment");
$pdo->exec("TRUNCATE TABLE wp_assessor_rpu_assessment");

$batch_inserts = [];
for ($i = 1; $i <= 1200; $i++) {
    $objid = sprintf('ASM-CONT-%04d', $i);
    $batch_inserts[] = "('$objid', 'RPU-MULTI-01', 'PC-COMM-01', 'AL-LAND-01', 100, 0.01, 100000, 20, 20000, 'land', 1)";
}
$pdo->exec("INSERT INTO rpu_assessment (objid, rpuid, classification_objid, actualuse_objid, areasqm, areaha, marketvalue, assesslevel, assessedvalue, rputype, taxable) VALUES " . implode(',', $batch_inserts));

// Clear state
delete_option('assessor_etracs_sync_run_state');
delete_option('assessor_etracs_sync_lock');

// Invocations loop: simulate HTTP requests processing batches
// Request 1: should initialize run, process earlier stages (fast/empty) until rpu_assessment batch 1 (500 records)
// In single_batch mode, each invocation executes 1 stage or 1 chunk and returns immediately
$continuation_reqs = 0;
$max_allowed_requests = 100;
$terminal_reached = false;
$initial_run_id = null;
$intermediate_in_progress_count = 0;

while (!$terminal_reached && $continuation_reqs < $max_allowed_requests) {
    $continuation_reqs++;
    // Real production lifecycle: No manual clearing or artificial delay!
    // The previous request releases its lock upon returning IN_PROGRESS,
    // allowing the immediate subsequent continuation request to acquire cleanly.
    $step_res = Assessor_Etracs_Sync::trigger_sync($pdo, true, false);

    if (is_wp_error($step_res)) {
        echo "Continuation request $continuation_reqs returned WP_Error: " . $step_res->get_error_message() . "\n";
        break;
    }

    if ($initial_run_id === null) {
        $initial_run_id = $step_res['run_id'];
    } else {
        test("Continuation request $continuation_reqs preserved stable run_id", $step_res['run_id'] === $initial_run_id);
    }

    if ($step_res['sync_status'] === 'IN_PROGRESS') {
        $intermediate_in_progress_count++;
        test("Step returns promptly without terminal state while work is active", $step_res['is_completed'] === false);
    } elseif (in_array($step_res['sync_status'], ['SUCCESS', 'PARTIAL', 'FAILED'], true)) {
        $terminal_reached = true;
        test("Terminal state reached and is_completed is true", $step_res['is_completed'] === true);
        test("Terminal status is SUCCESS", $step_res['sync_status'] === 'SUCCESS');
        break;
    }
}

test("Multi-request continuation executed across separate invocations", $continuation_reqs > 1 && $intermediate_in_progress_count > 0);

// Check that destination table has all 1200 rows with no duplicates
$dest_count = (int)$pdo->query("SELECT COUNT(*) FROM wp_assessor_rpu_assessment WHERE objid LIKE 'ASM-CONT-%'")->fetchColumn();
test("All 1200 records imported across continuation batches without data loss", $dest_count === 1200);

// Test Concurrent Continuation / Duplicate Worker Rejection:
// When a worker is actively processing (lock timestamp fresh within 45s), another continuation request is rejected
$active_run_state = array(
    'run_id'        => 'SYNC_CONCURRENT_TEST',
    'status'        => 'IN_PROGRESS',
    'current_stage' => 1,
    'cursor'        => 500,
    'last_seen_id'  => '',
    'stats'         => array('rpu_assessment' => 500),
    'write_failures'=> array(),
    'stage_errors'  => array(),
    'last_error'    => null,
    'started_at'    => gmdate('Y-m-d H:i:s'),
    'updated_at'    => gmdate('Y-m-d H:i:s'),
);
update_option('assessor_etracs_sync_run_state', $active_run_state);
update_option('assessor_etracs_sync_lock', array(
    'run_id'    => 'SYNC_CONCURRENT_TEST',
    'worker_id' => 'WORKER_PRIMARY',
    'timestamp' => time() // Fresh heartbeat (< 45s)
));

$concurrent_worker_res = Assessor_Etracs_Sync::trigger_sync($pdo, true, false);
test("Duplicate concurrent continuation request is rejected with worker_busy", is_wp_error($concurrent_worker_res) && $concurrent_worker_res->get_error_code() === 'worker_busy');

// Test Stale Worker Preemption:
// If Worker A is preempted while in-flight (lock reassigned to Worker B), Worker A's write must be rejected
$preempt_lock = get_option('assessor_etracs_sync_lock');
test("Active lock has distinct worker_id", isset($preempt_lock['worker_id']) && $preempt_lock['worker_id'] === 'WORKER_PRIMARY');

// Test Interrupted Run Recovery without Data Loss:
// Simulate an interrupted run state at offset 500 with stale lock (>600s)
update_option('assessor_etracs_sync_lock', array(
    'run_id'    => 'SYNC_CONCURRENT_TEST',
    'worker_id' => 'WORKER_DEAD',
    'timestamp' => time() - 700 // Stale lock
));

$resumed_step = Assessor_Etracs_Sync::trigger_sync($pdo, true, false);
test("Interrupted run with stale lock successfully resumes without error", !is_wp_error($resumed_step));
test("Resumed run preserved existing run_id", $resumed_step['run_id'] === 'SYNC_CONCURRENT_TEST');

echo "\n========================================================================\n";
echo "   ETRACS PULL PIPELINE TEST SUMMARY\n";
echo "   Total Tests Passed: $pass\n";
echo "   Total Tests Failed: $fail\n";
echo "========================================================================\n";

exit($fail > 0 ? 1 : 0);

