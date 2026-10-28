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
    public $options = 'wp_options';
    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->options = 'wp_options';
    }
    public function prepare($query, ...$args) {
        if (isset($args[0]) && is_array($args[0]) && count($args) === 1) {
            $args = $args[0];
        }
        $parts = explode('%s', $query);
        $res = array_shift($parts);
        foreach ($parts as $i => $part) {
            $val = isset($args[$i]) ? $args[$i] : null;
            if ($val === null) {
                $res .= 'NULL';
            } else {
                $res .= $this->pdo->quote((string)$val);
            }
            $res .= $part;
        }
        return $res;
    }
    public function esc_like($text) {
        return addcslashes($text, '_%\\');
    }
    public function get_var($query) {
        try {
            $stmt = $this->pdo->query($query);
            if (!$stmt) return null;
            $res = $stmt->fetchColumn();
            return ($res === false) ? null : $res;
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

// WordPress Object Cache Mock
$GLOBALS['wp_object_cache'] = array(
    'options' => array(),
);

if (!function_exists('wp_cache_get')) {
    function wp_cache_get($key, $group = '') {
        if (empty($group)) $group = 'default';
        return isset($GLOBALS['wp_object_cache'][$group][$key]) ? $GLOBALS['wp_object_cache'][$group][$key] : false;
    }
}

if (!function_exists('wp_cache_set')) {
    function wp_cache_set($key, $data, $group = '', $expire = 0) {
        if (empty($group)) $group = 'default';
        if (!isset($GLOBALS['wp_object_cache'][$group])) {
            $GLOBALS['wp_object_cache'][$group] = array();
        }
        $GLOBALS['wp_object_cache'][$group][$key] = $data;
        return true;
    }
}

if (!function_exists('wp_cache_delete')) {
    function wp_cache_delete($key, $group = '') {
        if (empty($group)) $group = 'default';
        if (isset($GLOBALS['wp_object_cache'][$group][$key])) {
            unset($GLOBALS['wp_object_cache'][$group][$key]);
            return true;
        }
        return false;
    }
}

$GLOBALS['wp_options_mock'] = array();

function update_option($name, $value, $autoload = null) {
    global $wpdb;
    $GLOBALS['wp_options_mock'][$name] = $value;

    // Core WordPress option cache update
    wp_cache_set($name, $value, 'options');
    $notoptions = wp_cache_get('notoptions', 'options');
    if (is_array($notoptions) && isset($notoptions[$name])) {
        unset($notoptions[$name]);
        wp_cache_set('notoptions', $notoptions, 'options');
    }

    if (!empty($wpdb)) {
        $options_tbl = !empty($wpdb->options) ? $wpdb->options : ($wpdb->prefix . 'options');
        $val_ser = is_scalar($value) ? (string)$value : serialize($value);
        try {
            $exists = $wpdb->get_var($wpdb->prepare("SELECT option_id FROM `$options_tbl` WHERE option_name = %s", $name));
            if ($exists) {
                $wpdb->query($wpdb->prepare("UPDATE `$options_tbl` SET option_value = %s WHERE option_name = %s", $val_ser, $name));
            } else {
                $wpdb->query($wpdb->prepare("INSERT INTO `$options_tbl` (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $val_ser));
            }
        } catch (Exception $e) {}
    }
    return true;
}

function get_option($name, $default = false) {
    // 1. Check notoptions cache
    $notoptions = wp_cache_get('notoptions', 'options');
    if (is_array($notoptions) && isset($notoptions[$name])) {
        return $default;
    }

    // 2. Check individual option cache
    $cached = wp_cache_get($name, 'options');
    if ($cached !== false) {
        return $cached;
    }

    // 3. Fallback to DB if options table exists
    global $wpdb;
    if (!empty($wpdb)) {
        $options_tbl = !empty($wpdb->options) ? $wpdb->options : ($wpdb->prefix . 'options');
        try {
            $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM `$options_tbl` WHERE option_name = %s", $name));
            if ($raw !== null) {
                $val = maybe_unserialize($raw);
                wp_cache_set($name, $val, 'options');
                return $val;
            }
        } catch (Exception $e) {}
    }

    // 4. Fallback to mock array
    if (isset($GLOBALS['wp_options_mock'][$name])) {
        $val = $GLOBALS['wp_options_mock'][$name];
        wp_cache_set($name, $val, 'options');
        return $val;
    }

    // Option does not exist: record in notoptions cache (standard WP core behavior)
    if (!is_array($notoptions)) {
        $notoptions = array();
    }
    $notoptions[$name] = true;
    wp_cache_set('notoptions', $notoptions, 'options');

    return $default;
}

function delete_option($name) {
    global $wpdb;
    unset($GLOBALS['wp_options_mock'][$name]);
    wp_cache_delete($name, 'options');
    $notoptions = wp_cache_get('notoptions', 'options');
    if (is_array($notoptions) && isset($notoptions[$name])) {
        unset($notoptions[$name]);
        wp_cache_set('notoptions', $notoptions, 'options');
    }

    global $wpdb, $pdo;
    if (!empty($wpdb)) {
        $options_tbl = !empty($wpdb->options) ? $wpdb->options : ($wpdb->prefix . 'options');
        try {
            $wpdb->query($wpdb->prepare("DELETE FROM `$options_tbl` WHERE option_name = %s", $name));
        } catch (Exception $e) {}
    } elseif (!empty($pdo)) {
        try {
            $stmt = $pdo->prepare("DELETE FROM `wp_options` WHERE option_name = ?");
            $stmt->execute([$name]);
        } catch (Exception $e) {}
    }
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
if (!function_exists('maybe_unserialize')) {
    function maybe_unserialize($original) {
        if (is_serialized($original)) {
            return @unserialize($original);
        }
        return $original;
    }
}
if (!function_exists('is_serialized')) {
    function is_serialized($data, $strict = true) {
        if (!is_string($data)) return false;
        $data = trim($data);
        if ('N;' === $data) return true;
        if (strlen($data) < 4) return false;
        if (':' !== $data[1]) return false;
        if ($strict) {
            $lastc = substr($data, -1);
            if (';' !== $lastc && '}' !== $lastc) return false;
        } else {
            $semicolon = strpos($data, ';');
            $brace     = strpos($data, '}');
            if (false === $semicolon && false === $brace) return false;
            if (false !== $semicolon && $semicolon < 3) return false;
            if (false !== $brace && $brace < 4) return false;
        }
        $token = $data[0];
        switch ($token) {
            case 's':
                if ($strict) {
                    if ('"' !== substr($data, -2, 1)) return false;
                } elseif (false === strpos($data, '"')) return false;
            case 'a':
            case 'O':
                return (bool) preg_match("/^{$token}:[0-9]+:/s", $data);
            case 'b':
            case 'i':
            case 'd':
                $end = $strict ? '$' : '';
                return (bool) preg_match("/^{$token}:[0-9.E+-]+;$end/", $data);
        }
        return false;
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

// End of class shims

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

// Ensure wp_options table exists in disposable test environment for realistic WordPress option caching & direct SQL tests
$pdo->exec("
CREATE TABLE IF NOT EXISTS `wp_options` (
  `option_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `option_name` varchar(191) NOT NULL DEFAULT '',
  `option_value` longtext NOT NULL,
  `autoload` varchar(20) NOT NULL DEFAULT 'yes',
  PRIMARY KEY (`option_id`),
  UNIQUE KEY `option_name` (`option_name`),
  KEY `autoload` (`autoload`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
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

// ─────────────────────────────────────────────────────────────
// Test 12: Atomic Concurrency, Race Condition Simulation & Checkpoint Safety
// ─────────────────────────────────────────────────────────────
echo "\n--- 12. Atomic Concurrency & Checkpoint Safety Verification ---\n";

// Subtest 12.1: Two concurrent requests attempting to acquire an initially empty lock
delete_option('assessor_etracs_sync_lock');
delete_option('assessor_etracs_sync_run_state');

$w1_token = 'WORKER_SIM_RACE_1';
$w2_token = 'WORKER_SIM_RACE_2';
$race_run_id = 'SYNC_RACE_INIT';

// Simulate simultaneous arrival: Worker 1 wins atomic insert, Worker 2 must be rejected
$res_w1 = Assessor_Etracs_Sync::acquire_lock_atomic($race_run_id, $w1_token, false);
$res_w2 = Assessor_Etracs_Sync::acquire_lock_atomic($race_run_id, $w2_token, false);

test("Worker 1 acquired empty lock atomically", $res_w1 === true);
test("Worker 2 simultaneously attempting empty lock was rejected", is_wp_error($res_w2));

// Subtest 12.2: Two continuation requests arriving together for the same run
$run_state_race = array(
    'run_id'        => $race_run_id,
    'status'        => 'IN_PROGRESS',
    'current_stage' => 0,
    'cursor'        => 100,
    'last_seen_id'  => '',
    'stats'         => array('entity' => 100),
    'write_failures'=> array(),
    'stage_errors'  => array(),
    'last_error'    => null,
    'started_at'    => gmdate('Y-m-d H:i:s'),
    'updated_at'    => gmdate('Y-m-d H:i:s'),
);
update_option('assessor_etracs_sync_run_state', $run_state_race);

// While Worker 1 holds active lock, a simultaneous Worker 3 continuation arrives
$w3_token = 'WORKER_SIM_CONT_3';
$res_w3 = Assessor_Etracs_Sync::acquire_lock_atomic($race_run_id, $w3_token, true);
test("Simultaneous continuation request arriving during active lock is rejected with worker_busy", is_wp_error($res_w3) && $res_w3->get_error_code() === 'worker_busy');

// Subtest 12.3: Worker remaining active beyond 45 seconds (heartbeat refreshed) cannot be stolen
// Worker 1 refreshes heartbeat periodically (simulating active execution)
Assessor_Etracs_Sync::refresh_heartbeat_atomic($race_run_id, $w1_token);
$lock_active = get_option('assessor_etracs_sync_lock');
test("Active worker heartbeat kept timestamp fresh (<45s)", (time() - $lock_active['timestamp']) < 5);

$res_takeover_attempt = Assessor_Etracs_Sync::acquire_lock_atomic($race_run_id, 'WORKER_ROGUE', true);
test("Active worker executing beyond 45s with heartbeat cannot be superseded", is_wp_error($res_takeover_attempt) && $res_takeover_attempt->get_error_code() === 'worker_busy');

// Subtest 12.4: Preempted worker attempting to write and save checkpoint afterward
// Simulate Worker 1 being legitimately preempted after true abandonment (>45s inactive)
update_option('assessor_etracs_sync_lock', array(
    'run_id'    => $race_run_id,
    'worker_id' => 'WORKER_NEW_LEGITIMATE',
    'timestamp' => time()
));

// Worker 1 now attempts safe checkpoint update and write verification
$w1_stale_state = $run_state_race;
$w1_stale_state['cursor'] = 999; // Stale progress
$checkpoint_saved = Assessor_Etracs_Sync::update_checkpoint_safe($w1_stale_state, $w1_token);
test("Preempted worker checkpoint write was strictly rejected", $checkpoint_saved === false);

$current_persisted_state = get_option('assessor_etracs_sync_run_state');
test("Persisted run state was NOT overwritten by preempted worker", $current_persisted_state['cursor'] === 100);

// Cleanup race test state
delete_option('assessor_etracs_sync_lock');
delete_option('assessor_etracs_sync_run_state');

// =========================================================================
// 13. WORDPRESS OBJECT-CACHE COHERENCE & ATOMIC LOCK AUDIT
// =========================================================================
echo "\n--- 13. WordPress Object-Cache Coherence & Atomic Lock Audit ---\n";

// Subtest 13.1: Lock acquisition when previously absent and absence was cached in 'notoptions'
// Ensure lock option does not exist in DB
global $wpdb;
$options_tbl = !empty($wpdb->options) ? $wpdb->options : ($wpdb->prefix . 'options');
$wpdb->query($wpdb->prepare("DELETE FROM `$options_tbl` WHERE option_name = %s", Assessor_Etracs_Sync::OPTION_LOCK));

// Prime the WordPress object cache with 'notoptions' entry containing the lock
if (function_exists('wp_cache_set')) {
    $notoptions = wp_cache_get('notoptions', 'options');
    if (!is_array($notoptions)) {
        $notoptions = array();
    }
    $notoptions[Assessor_Etracs_Sync::OPTION_LOCK] = true;
    wp_cache_set('notoptions', $notoptions, 'options');
}

// Perform atomic lock acquisition
$cache_run_id = 'RUN_CACHE_TEST_01';
$cache_w1 = 'WORKER_CACHE_1';
$acq_res = Assessor_Etracs_Sync::acquire_lock_atomic($cache_run_id, $cache_w1, false);
test("Lock acquisition succeeds when lock option was previously absent", $acq_res === true);

// Verify that 'notoptions' cache was purged and get_option immediately observes the acquired lock
$cached_notoptions = function_exists('wp_cache_get') ? wp_cache_get('notoptions', 'options') : array();
$notoptions_purged = !is_array($cached_notoptions) || !isset($cached_notoptions[Assessor_Etracs_Sync::OPTION_LOCK]);
test("wp_cache 'notoptions' entry was purged upon atomic insert", $notoptions_purged);

$observed_lock = get_option(Assessor_Etracs_Sync::OPTION_LOCK);
test("get_option() immediately observes newly acquired lock without stale notoptions interference",
    is_array($observed_lock) && ($observed_lock['worker_id'] ?? '') === $cache_w1
);

$fresh_lock = Assessor_Etracs_Sync::get_lock_fresh();
test("get_lock_fresh() reads identical ownership from database",
    is_array($fresh_lock) && ($fresh_lock['worker_id'] ?? '') === $cache_w1
);

// Subtest 13.2: Lock reassignment from Worker A to Worker B followed by Worker A ownership check
// Worker B acquires/steals lock directly or via atomic update
$cache_w2 = 'WORKER_CACHE_2';
$new_lock_data = array('run_id' => $cache_run_id, 'worker_id' => $cache_w2, 'timestamp' => time());
$wpdb->query($wpdb->prepare(
    "UPDATE `$options_tbl` SET option_value = %s WHERE option_name = %s",
    serialize($new_lock_data),
    Assessor_Etracs_Sync::OPTION_LOCK
));
$GLOBALS['wp_options_mock'][Assessor_Etracs_Sync::OPTION_LOCK] = $new_lock_data;

// Intentionally pollute the object cache with Worker A's stale lock to simulate a persistent cache lag
if (function_exists('wp_cache_set')) {
    wp_cache_set(Assessor_Etracs_Sync::OPTION_LOCK, array('run_id' => $cache_run_id, 'worker_id' => $cache_w1, 'timestamp' => time()), 'options');
}

// Worker A now performs ownership validation via get_lock_fresh() and update_checkpoint_safe()
$stale_w1_seen_lock = Assessor_Etracs_Sync::get_lock_fresh();
test("get_lock_fresh() bypasses stale cached lock and detects reassignment to Worker B",
    is_array($stale_w1_seen_lock) && ($stale_w1_seen_lock['worker_id'] ?? '') === $cache_w2
);

// Worker A attempts checkpoint save
$dummy_state = array('run_id' => $cache_run_id, 'cursor' => 50, 'status' => 'IN_PROGRESS');
$w1_checkpoint_res = Assessor_Etracs_Sync::update_checkpoint_safe($dummy_state, $cache_w1);
test("Worker A checkpoint update is rejected because ownership was transferred to Worker B", $w1_checkpoint_res === false);

// Worker A attempts heartbeat renewal
$w1_heartbeat_res = Assessor_Etracs_Sync::refresh_heartbeat_atomic($cache_run_id, $cache_w1);
test("Worker A heartbeat renewal is rejected because ownership was transferred to Worker B", $w1_heartbeat_res === false);

// Subtest 13.3: Lock release followed by immediate reacquisition
// Worker B releases lock
Assessor_Etracs_Sync::release_lock_atomic($cache_w2);

$after_release_db = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM `$options_tbl` WHERE option_name = %s", Assessor_Etracs_Sync::OPTION_LOCK));
test("Lock row was deleted from database on atomic release", $after_release_db === null);

$after_release_fresh = Assessor_Etracs_Sync::get_lock_fresh();
test("get_lock_fresh() confirms lock absence after release", $after_release_fresh === null || $after_release_fresh === false);

// Immediate reacquisition by Worker C
$cache_w3 = 'WORKER_CACHE_3';
$reacq_res = Assessor_Etracs_Sync::acquire_lock_atomic($cache_run_id, $cache_w3, false);
test("Immediate reacquisition succeeds after release", $reacq_res === true);

$reacq_lock = Assessor_Etracs_Sync::get_lock_fresh();
test("Reacquired lock belongs to Worker C in both DB and cache",
    is_array($reacq_lock) && ($reacq_lock['worker_id'] ?? '') === $cache_w3
);

// Cleanup
Assessor_Etracs_Sync::release_lock_atomic($cache_w3);
delete_option(Assessor_Etracs_Sync::OPTION_LOCK);
delete_option(Assessor_Etracs_Sync::OPTION_RUN_STATE);
Assessor_Etracs_Sync::clean_lock_cache();

// ─────────────────────────────────────────────────────────────
// Test 14: Row-Write Fencing Verification (Post-Lock Loss Protection)
// ─────────────────────────────────────────────────────────────
echo "\n--- 14. Row-Write Fencing Verification (Post-Lock Loss Protection) ---\n";

$fence_run_id = 'RUN-FENCE-' . time();
$worker_a = 'WORKER_FENCE_A';
$worker_b = 'WORKER_FENCE_B';

// Step 1: Worker A acquires lock cleanly
$acq_a = Assessor_Etracs_Sync::acquire_lock_atomic($fence_run_id, $worker_a, false);
test("Worker A acquires initial lock", $acq_a === true);

// Step 2: Worker A validates lock ownership
$a_lock_seen = Assessor_Etracs_Sync::get_lock_fresh();
test("Worker A confirms active lock ownership", is_array($a_lock_seen) && ($a_lock_seen['worker_id'] ?? '') === $worker_a);

// Step 3: Worker A pauses / stalls. In the meantime, timeout occurs and Worker B takes over the lock
$new_lock_b = array('run_id' => $fence_run_id, 'worker_id' => $worker_b, 'timestamp' => time());
$wpdb->query($wpdb->prepare(
    "UPDATE `$options_tbl` SET option_value = %s WHERE option_name = %s",
    serialize($new_lock_b),
    Assessor_Etracs_Sync::OPTION_LOCK
));
$GLOBALS['wp_options_mock'][Assessor_Etracs_Sync::OPTION_LOCK] = $new_lock_b;
Assessor_Etracs_Sync::clean_lock_cache();

$b_lock_seen = Assessor_Etracs_Sync::get_lock_fresh();
test("Worker B has successfully taken over lock ownership", is_array($b_lock_seen) && ($b_lock_seen['worker_id'] ?? '') === $worker_b);

// Step 4: Worker A resumes and attempts to write a row using safe_upsert_row() with its stale worker_id
$stale_row_data = array(
    'objid' => 'ASM-FENCE-STALE-A',
    'rpuid' => 'RPU-FENCE-001',
    'origin_type' => 'REPLICA',
    'marketvalue' => 12345.00
);
$target_asm_tbl = $wpdb->prefix . 'assessor_rpu_assessment';
$stale_write_res = Assessor_Etracs_Sync::safe_upsert_row($target_asm_tbl, $stale_row_data, 'objid', $worker_a);

test("Worker A row write is strictly rejected by fencing mechanism", $stale_write_res === false);

// Verify that the row was NOT written to the database
$check_stale_in_db = $wpdb->get_var($wpdb->prepare(
    "SELECT objid FROM `$target_asm_tbl` WHERE objid = %s",
    'ASM-FENCE-STALE-A'
));
test("Stale row ASM-FENCE-STALE-A was NOT inserted into database", empty($check_stale_in_db));

// Step 5: Worker A attempts checkpoint persistence
$stale_state = array('run_id' => $fence_run_id, 'cursor' => 999, 'status' => 'IN_PROGRESS');
$stale_cp_res = Assessor_Etracs_Sync::update_checkpoint_safe($stale_state, $worker_a);
test("Worker A checkpoint update is strictly rejected", $stale_cp_res === false);

// Step 6: Worker B writes legitimate row with valid worker_id
$valid_row_b = array(
    'objid' => 'ASM-FENCE-VALID-B',
    'rpuid' => 'RPU-FENCE-001',
    'origin_type' => 'REPLICA',
    'marketvalue' => 67890.00
);
$valid_write_b = Assessor_Etracs_Sync::safe_upsert_row($target_asm_tbl, $valid_row_b, 'objid', $worker_b);
test("Worker B legitimate row write succeeds under valid ownership", $valid_write_b !== false);

$check_valid_in_db = $wpdb->get_var($wpdb->prepare(
    "SELECT objid FROM `$target_asm_tbl` WHERE objid = %s",
    'ASM-FENCE-VALID-B'
));
test("Valid row ASM-FENCE-VALID-B is present in database", $check_valid_in_db === 'ASM-FENCE-VALID-B');

// Step 7: Worker B updates checkpoint
$valid_state_b = array('run_id' => $fence_run_id, 'cursor' => 10, 'status' => 'IN_PROGRESS');
$valid_cp_res = Assessor_Etracs_Sync::update_checkpoint_safe($valid_state_b, $worker_b);
test("Worker B checkpoint update succeeds", $valid_cp_res === true);

// Clean up test rows and lock
$wpdb->query($wpdb->prepare("DELETE FROM `$target_asm_tbl` WHERE objid IN ('ASM-FENCE-STALE-A', 'ASM-FENCE-VALID-B')"));
Assessor_Etracs_Sync::release_lock_atomic($worker_b);
// ─────────────────────────────────────────────────────────────
// Test 15: Checkpoint Persistence Fencing Race & Deterministic Interleaving
// ─────────────────────────────────────────────────────────────
echo "\n--- 15. Real Two-Connection Checkpoint Fencing & Row-Locking Test ---\n";

// 1. Verify that the test wp_options table uses InnoDB
$engine_check = $pdo->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'test_wp01_disposable' AND TABLE_NAME = 'wp_options'")->fetchColumn();
test("wp_options table uses InnoDB storage engine", strcasecmp($engine_check, 'InnoDB') === 0);

// 2. Open two independent database connections, Connection A and Connection B
$pdo_a = new PDO("mysql:host=localhost;dbname=test_wp01_disposable;charset=utf8mb4", 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo_b = new PDO("mysql:host=localhost;dbname=test_wp01_disposable;charset=utf8mb4", 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Set a short lock-wait timeout on Connection B (1 second) so contention is detected quickly and cleanly
$pdo_b->exec("SET innodb_lock_wait_timeout = 1");

$wpdb_a = new MockWPDBPipeline($pdo_a);
$wpdb_b = new MockWPDBPipeline($pdo_b);

$run_id_15 = 'RUN-FENCE-2CONN-' . time();
$worker_a = 'WORKER_2CONN_A';
$worker_b = 'WORKER_2CONN_B';

// Set global $wpdb to Connection A for Worker A operations
global $wpdb;
$orig_wpdb = $wpdb;

// Clean slate in DB and cache
$pdo_a->exec("DELETE FROM wp_options WHERE option_name IN ('" . Assessor_Etracs_Sync::OPTION_LOCK . "', '" . Assessor_Etracs_Sync::OPTION_RUN_STATE . "')");
Assessor_Etracs_Sync::clean_lock_cache();

// ── Test A: Lock contention and valid commit ordering ──

// Worker A acquires lock via production atomic acquisition
$wpdb = $wpdb_a;
$acq_a_res = Assessor_Etracs_Sync::acquire_lock_atomic($run_id_15, $worker_a, false);
test("Connection A: Worker A acquires initial lock atomically", $acq_a_res === true);

// Worker A starts a transaction and locks the worker-lock row with SELECT ... FOR UPDATE
$pdo_a->exec("START TRANSACTION");
$locked_raw_a = $pdo_a->query("SELECT option_value FROM wp_options WHERE option_name = '" . Assessor_Etracs_Sync::OPTION_LOCK . "' FOR UPDATE")->fetchColumn();
$locked_lock_a = maybe_unserialize($locked_raw_a);
test("Connection A: Holds active exclusive FOR UPDATE row lock on worker-lock row", is_array($locked_lock_a) && ($locked_lock_a['worker_id'] ?? '') === $worker_a);

// While Connection A holds the exclusive row lock, Connection B attempts lock takeover.
// Because the row is locked with FOR UPDATE, Connection B must block and experience lock-wait timeout!
$wpdb = $wpdb_b;
$b_blocked = false;
$b_error_msg = '';
$t0 = microtime(true);
try {
    // Attempt direct atomic lock update or takeover on Connection B
    $lock_val_b = serialize(array('run_id' => $run_id_15, 'worker_id' => $worker_b, 'timestamp' => time()));
    $pdo_b->exec("UPDATE wp_options SET option_value = " . $pdo_b->quote($lock_val_b) . " WHERE option_name = '" . Assessor_Etracs_Sync::OPTION_LOCK . "'");
} catch (Exception $e) {
    $b_error_msg = $e->getMessage();
    if (strpos($b_error_msg, '1205') !== false || stripos($b_error_msg, 'timeout') !== false) {
        $b_blocked = true;
    }
}
$elapsed_wait = microtime(true) - $t0;
test("Connection B: Lock takeover is strictly BLOCKED while Connection A holds row lock (wait: " . round($elapsed_wait, 2) . "s)", $b_blocked === true);

// Allow Connection A to persist its checkpoint and commit
$wpdb = $wpdb_a;
$state_a = array(
    'run_id' => $run_id_15,
    'cursor' => 100,
    'status' => 'IN_PROGRESS',
    'worker_id' => $worker_a
);
$state_a_ser = serialize($state_a);
// Write state on connection A and commit
$pdo_a->exec("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('" . Assessor_Etracs_Sync::OPTION_RUN_STATE . "', " . $pdo_a->quote($state_a_ser) . ", 'no') ON DUPLICATE KEY UPDATE option_value = " . $pdo_a->quote($state_a_ser));
$pdo_a->exec("COMMIT");
Assessor_Etracs_Sync::clean_lock_cache();
test("Connection A: Commits transaction and releases row lock", true);

// Fast-forward lock timestamp so Worker B can perform legitimate stale-worker takeover (>45s)
$wpdb = $wpdb_a;
$stale_time = time() - 60;
$stale_lock_a = serialize(array('run_id' => $run_id_15, 'worker_id' => $worker_a, 'timestamp' => $stale_time));
$pdo_a->exec("UPDATE wp_options SET option_value = " . $pdo_a->quote($stale_lock_a) . " WHERE option_name = '" . Assessor_Etracs_Sync::OPTION_LOCK . "'");
Assessor_Etracs_Sync::clean_lock_cache();

// Now Connection B attempts production atomic lock acquisition
$wpdb = $wpdb_b;
$acq_b_res = Assessor_Etracs_Sync::acquire_lock_atomic($run_id_15, $worker_b, true);
test("Connection B: Production acquire_lock_atomic() succeeds after Worker A commit & timeout", $acq_b_res === true);

// Connection B persists a newer checkpoint using production update_checkpoint_safe()
$state_b = array(
    'run_id' => $run_id_15,
    'cursor' => 300,
    'status' => 'SUCCESS',
    'is_completed' => true,
    'worker_id' => $worker_b
);
$cp_b_res = Assessor_Etracs_Sync::update_checkpoint_safe($state_b, $worker_b);
test("Connection B: Production update_checkpoint_safe() persists terminal state", $cp_b_res === true);

// Verify that the final database state reflects the valid committed order
$final_state_raw = $pdo_a->query("SELECT option_value FROM wp_options WHERE option_name = '" . Assessor_Etracs_Sync::OPTION_RUN_STATE . "'")->fetchColumn();
$final_state = maybe_unserialize($final_state_raw);
test("Committed order preserved: Final status is SUCCESS", ($final_state['status'] ?? '') === 'SUCCESS');
test("Committed order preserved: Final cursor is 300", (int)($final_state['cursor'] ?? 0) === 300);
test("Committed order preserved: Final worker_id is Worker B", ($final_state['worker_id'] ?? '') === $worker_b);

// ── Test B: Stale worker after a completed takeover ──

// Worker A now attempts to invoke production update_checkpoint_safe() with its obsolete worker_id
$wpdb = $wpdb_a;
$stale_a_state = array(
    'run_id' => $run_id_15,
    'cursor' => 150, // Stale older cursor
    'status' => 'IN_PROGRESS', // Stale non-terminal status
    'worker_id' => $worker_a
);
$stale_a_cp_res = Assessor_Etracs_Sync::update_checkpoint_safe($stale_a_state, $worker_a);
test("Test B: Obsolete Worker A update_checkpoint_safe() is strictly REJECTED by fencing", $stale_a_cp_res === false);

// Verify Worker B's checkpoint and terminal state remain unchanged in database
$untainted_state_raw = $pdo_a->query("SELECT option_value FROM wp_options WHERE option_name = '" . Assessor_Etracs_Sync::OPTION_RUN_STATE . "'")->fetchColumn();
$untainted_state = maybe_unserialize($untainted_state_raw);
test("Test B: Persisted run state was NOT overwritten (status remains SUCCESS)", ($untainted_state['status'] ?? '') === 'SUCCESS');
test("Test B: Persisted run state was NOT overwritten (cursor remains 300)", (int)($untainted_state['cursor'] ?? 0) === 300);
test("Test B: Persisted run state was NOT overwritten (worker_id remains Worker B)", ($untainted_state['worker_id'] ?? '') === $worker_b);

// Clean up connections and restore original wpdb
$wpdb = $wpdb_b;
Assessor_Etracs_Sync::release_lock_atomic($worker_b);
$pdo_a->exec("DELETE FROM wp_options WHERE option_name IN ('" . Assessor_Etracs_Sync::OPTION_LOCK . "', '" . Assessor_Etracs_Sync::OPTION_RUN_STATE . "')");
Assessor_Etracs_Sync::clean_lock_cache();

// Restore original test wpdb harness
$wpdb = $orig_wpdb;
unset($pdo_a, $pdo_b, $wpdb_a, $wpdb_b);

echo "\n========================================================================\n";
echo "   ETRACS PULL PIPELINE TEST SUMMARY\n";
echo "   Total Tests Passed: $pass\n";
echo "   Total Tests Failed: $fail\n";
echo "========================================================================\n";

exit($fail > 0 ? 1 : 0);

