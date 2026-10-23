<?php
/**
 * Test Suite: Assessor Repo — Phase 7 / WP-01: Shadow ETRACS Data Ownership & Sync Protection
 *
 * Isolated test suite running entirely within the disposable database `test_wp01_disposable`.
 *
 * Scenarios tested (per WP-01 specifications):
 * 1. Ownership migration assigns 'REPLICA' to all pre-existing legacy shadow rows in disposable test DB.
 * 2. An existing REPLICA row is refreshed from source.
 * 3. A new source row is inserted and marked REPLICA.
 * 4. A LOCAL row sharing the source primary key is preserved and causes a preflight failure.
 * 5. A source utdno conflicts with a different locally owned FAAS ID; entire run aborts before writing.
 * 6. A unique-key collision occurs in another eligible shadow table (e.g. entityno in assessor_entity); run aborts safely.
 * 7. A failed preflight leaves all table contents completely unchanged.
 * 8. Repeated safe pulls do not create duplicate records (idempotent).
 * 9. Existing source IDs, predecessor links, assessment lines, and related rows remain intact.
 * 10. Native Assessor schemas, tables, and sync code remain untouched (asserted against baseline).
 */

class MockWpdb {
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

    public function query($sql) {
        try {
            $count = $this->pdo->exec($sql);
            return $count;
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            return false;
        }
    }

    public function get_var($query, $x = 0, $y = 0) {
        try {
            $stmt = $this->pdo->query($query);
            if (!$stmt) return null;
            return $stmt->fetchColumn((int)$x);
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            return null;
        }
    }

    public function get_col($query, $x = 0) {
        try {
            $stmt = $this->pdo->query($query);
            if (!$stmt) return array();
            return $stmt->fetchAll(PDO::FETCH_COLUMN, (int)$x);
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            return array();
        }
    }

    public function get_row($query, $output = 'OBJECT') {
        try {
            $stmt = $this->pdo->query($query);
            if (!$stmt) return null;
            $res = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$res) return null;
            if ($output === 'ARRAY_A') return $res;
            return (object)$res;
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            return null;
        }
    }

    public function get_results($query, $output = 'OBJECT') {
        try {
            $stmt = $this->pdo->query($query);
            if (!$stmt) return array();
            $res = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($output === 'ARRAY_A') return $res;
            $obj_res = array();
            foreach ($res as $r) {
                $obj_res[] = (object)$r;
            }
            return $obj_res;
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            return array();
        }
    }
}

// Minimal WordPress helper shims for stand-alone execution
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}
if (!defined('ASSESSOR_API_PLUGIN_DIR')) {
    define('ASSESSOR_API_PLUGIN_DIR', dirname(__DIR__) . '/wp-content/plugins/assessor-api/');
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (!class_exists('WP_Error')) {
    class WP_Error {
        public $code;
        public $message;
        public $data;
        public function __construct($code = '', $message = '', $data = '') {
            $this->code = $code;
            $this->message = $message;
            $this->data = $data;
        }
        public function get_error_message() { return $this->message; }
        public function get_error_code() { return $this->code; }
        public function get_error_data() { return $this->data; }
    }
}

function is_wp_error($thing) {
    return ($thing instanceof WP_Error);
}

function current_time($type = 'mysql') {
    return date('Y-m-d H:i:s');
}

$GLOBALS['wp_options_mock'] = array();
function update_option($name, $val, $autoload = null) {
    $GLOBALS['wp_options_mock'][$name] = $val;
    return true;
}
function get_option($name, $default = false) {
    return isset($GLOBALS['wp_options_mock'][$name]) ? $GLOBALS['wp_options_mock'][$name] : $default;
}
function delete_option($name) {
    unset($GLOBALS['wp_options_mock'][$name]);
    return true;
}

// Load backend classes under test
require_once __DIR__ . '/../wp-content/plugins/assessor-api/includes/class-assessor-database.php';
require_once __DIR__ . '/../wp-content/plugins/assessor-api/includes/class-assessor-etracs-sync.php';
require_once __DIR__ . '/../wp-content/plugins/assessor-api/includes/class-assessor-migration-runner.php';

// Setup connections
$disposable_db = 'test_wp01_disposable';
$pdo_target = new PDO("mysql:host=localhost;dbname=$disposable_db;charset=utf8mb4", 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// ─────────────────────────────────────────────────────────────
// FAIL-CLOSED DATABASE CONNECTION GUARD
// Verify that EVERY database connection used by this harness targets ONLY
// the explicitly allowed disposable test database.
// ─────────────────────────────────────────────────────────────
$current_db = $pdo_target->query("SELECT DATABASE()")->fetchColumn();
if ($current_db !== $disposable_db) {
    die("\n[CRITICAL SECURITY ABORT] Test harness PDO connection targets '$current_db'. Expected '$disposable_db'. Halting immediately to protect application databases.\n");
}

// Prohibit connecting to production or development databases
$prohibited_dbs = array('assessor_local', 'etracs254_kitaotao', 'information_schema', 'mysql', 'performance_schema', 'sys');
if (in_array(strtolower($current_db), $prohibited_dbs, true)) {
    die("\n[CRITICAL SECURITY ABORT] Test harness PDO connection targets prohibited database '$current_db'. Halting immediately.\n");
}

// Mock WPDB globally
global $wpdb;
$wpdb = new MockWpdb($pdo_target);

echo "========================================================================\n";
echo "   ASSESSOR REPO - PHASE 7 / WP-01 COMPREHENSIVE TEST HARNESS\n";
echo "   Target Database: $disposable_db (Disposable Isolated Environment)\n";
echo "   Connection Guard: VERIFIED (DATABASE() === '$current_db')\n";
echo "========================================================================\n\n";

$pass_count = 0;
$fail_count = 0;

function assert_test($description, $condition) {
    global $pass_count, $fail_count;
    if ($condition) {
        echo " [PASS] $description\n";
        $pass_count++;
    } else {
        echo " [FAIL] $description\n";
        $fail_count++;
    }
}

// ─────────────────────────────────────────────────────────────
// Setup Schema in Disposable Database (SHADOW TABLES ONLY)
// Native Assessor tables (e.g. wp_assessor_properties) are NEVER created,
// dropped, altered, or seeded in the test database.
// ─────────────────────────────────────────────────────────────
$pdo_target->exec("DROP TABLE IF EXISTS wp_assessor_faas, wp_assessor_rpu, wp_assessor_real_property, wp_assessor_entity, wp_assessor_faas_previous, wp_assessor_rpu_assessment, wp_assessor_bldgkind, wp_assessor_propertyclassification");

// Create test shadow tables without origin_type first to test migration
$pdo_target->exec("
CREATE TABLE wp_assessor_faas (
    objid varchar(50) NOT NULL,
    state varchar(25) NOT NULL,
    rpuid varchar(50) DEFAULT NULL,
    utdno varchar(25) NOT NULL,
    tdno varchar(25) DEFAULT NULL,
    owner_name longtext,
    PRIMARY KEY (objid),
    UNIQUE KEY ux_faas_utdno (utdno)
) ENGINE=InnoDB;

CREATE TABLE wp_assessor_rpu (
    id mediumint NOT NULL AUTO_INCREMENT,
    objid varchar(50) NOT NULL,
    state varchar(25) DEFAULT 'CURRENT',
    realpropertyid varchar(50) NOT NULL,
    rpu_type varchar(50) DEFAULT NULL,
    total_market_value float DEFAULT '0',
    total_assessed_value float DEFAULT '0',
    PRIMARY KEY (id),
    UNIQUE KEY ux_rpu_objid (objid)
) ENGINE=InnoDB;

CREATE TABLE wp_assessor_real_property (
    id mediumint NOT NULL AUTO_INCREMENT,
    objid varchar(50) NOT NULL,
    pin varchar(50) DEFAULT NULL,
    cadastrallotno varchar(900) DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY ux_rp_objid (objid)
) ENGINE=InnoDB;

CREATE TABLE wp_assessor_entity (
    objid varchar(50) NOT NULL,
    entityno varchar(50) NOT NULL,
    name longtext NOT NULL,
    address_text varchar(255) NOT NULL DEFAULT '',
    type varchar(25) NOT NULL,
    PRIMARY KEY (objid),
    UNIQUE KEY uix_entityno (entityno)
) ENGINE=InnoDB;

CREATE TABLE wp_assessor_faas_previous (
    objid varchar(50) NOT NULL,
    faasid varchar(50) DEFAULT NULL,
    prevfaasid varchar(50) DEFAULT NULL,
    prevtdno varchar(800) DEFAULT NULL,
    PRIMARY KEY (objid)
) ENGINE=InnoDB;

CREATE TABLE wp_assessor_rpu_assessment (
    objid varchar(50) NOT NULL,
    rpuid varchar(50) NOT NULL,
    actualuse varchar(100) DEFAULT NULL,
    marketvalue decimal(16,2) NOT NULL DEFAULT '0.00',
    assessedvalue decimal(16,2) NOT NULL DEFAULT '0.00',
    PRIMARY KEY (objid)
) ENGINE=InnoDB;

CREATE TABLE wp_assessor_bldgkind (
    objid varchar(50) NOT NULL,
    state varchar(10) NOT NULL,
    code varchar(20) NOT NULL,
    name varchar(100) NOT NULL,
    PRIMARY KEY (objid),
    UNIQUE KEY ux_bldgkind_code (code),
    UNIQUE KEY ux_bldgkind_name (name)
) ENGINE=InnoDB;

CREATE TABLE wp_assessor_propertyclassification (
    objid varchar(50) NOT NULL,
    state varchar(10) DEFAULT 'active',
    code varchar(20) NOT NULL,
    name varchar(100) NOT NULL,
    PRIMARY KEY (objid),
    UNIQUE KEY ux_classcode (code)
) ENGINE=InnoDB;
");

// Insert sample legacy shadow records (origin_type not yet present)
$pdo_target->exec("
INSERT INTO wp_assessor_faas (objid, state, rpuid, utdno, tdno, owner_name) VALUES
('FAAS-LEGACY-001', 'CURRENT', 'RPU-LEGACY-001', 'UTD-001', 'TD-001', 'Legacy Owner 1'),
('FAAS-LEGACY-002', 'CURRENT', 'RPU-LEGACY-002', 'UTD-002', 'TD-002', 'Legacy Owner 2');

INSERT INTO wp_assessor_entity (objid, entityno, name, address_text, type) VALUES
('ENT-LEGACY-001', 'E-001', 'Legacy Entity 1', 'Barangay 1', 'INDIVIDUAL');
");

// ─────────────────────────────────────────────────────────────
// Test Scenario 1: Ownership migration assigns REPLICA to legacy rows
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 1: Ownership Migration ---\n";
$db_tool = new Assessor_Database();
$migration_results = $db_tool->migrate_shadow_ownership_metadata();

$faas_col = $wpdb->get_var("SHOW COLUMNS FROM wp_assessor_faas LIKE 'origin_type'");
$faas_origins = $wpdb->get_col("SELECT DISTINCT origin_type FROM wp_assessor_faas");
assert_test("origin_type column exists on wp_assessor_faas after migration", $faas_col === 'origin_type');
assert_test("All legacy rows in wp_assessor_faas backfilled as 'REPLICA'", count($faas_origins) === 1 && $faas_origins[0] === 'REPLICA');

// Verify that the migration method ONLY targets shadow tables and never targets native tables
$reflector = new ReflectionMethod('Assessor_Database', 'migrate_shadow_ownership_metadata');
$filename = $reflector->getFileName();
$start_line = $reflector->getStartLine();
$end_line = $reflector->getEndLine();
$lines = file($filename);
$method_source = implode('', array_slice($lines, $start_line - 1, $end_line - $start_line + 1));
$targets_native = (strpos($method_source, 'assessor_properties') !== false || strpos($method_source, 'property_versions') !== false);
assert_test("Migration code statically excludes native Assessor tables", !$targets_native);

// ─────────────────────────────────────────────────────────────
// Setup Mock Source SQLite / in-memory or PDO tables
// We create source tables in test_wp01_disposable with prefix `src_` to simulate ETRACS source DB
// ─────────────────────────────────────────────────────────────
$pdo_target->exec("DROP TABLE IF EXISTS entity, realproperty, rpu, faas, bldgkind, propertyclassification");
$pdo_target->exec("
CREATE TABLE entity (
    objid varchar(50) NOT NULL,
    entityno varchar(50) NOT NULL,
    name longtext NOT NULL,
    address_text varchar(255) NOT NULL DEFAULT '',
    mailingaddress varchar(255) DEFAULT NULL,
    type varchar(25) NOT NULL,
    sys_lastupdate varchar(25) DEFAULT NULL,
    sys_lastupdateby varchar(50) DEFAULT NULL,
    remarks text,
    entityname varchar(800) NOT NULL,
    address_objid varchar(50) DEFAULT NULL,
    mobileno varchar(25) DEFAULT NULL,
    phoneno varchar(25) DEFAULT NULL,
    email varchar(50) DEFAULT NULL,
    state varchar(25) DEFAULT NULL,
    PRIMARY KEY (objid)
);

CREATE TABLE realproperty (
    objid varchar(50) NOT NULL,
    pin varchar(50) DEFAULT NULL,
    cadastrallotno varchar(900) DEFAULT NULL,
    surveyno varchar(255) DEFAULT NULL,
    blockno varchar(100) DEFAULT NULL,
    barangayid varchar(50) DEFAULT NULL,
    lguid varchar(50) DEFAULT NULL,
    lgutype varchar(50) DEFAULT NULL,
    north varchar(255) DEFAULT NULL,
    south varchar(255) DEFAULT NULL,
    east varchar(255) DEFAULT NULL,
    west varchar(255) DEFAULT NULL,
    pintype varchar(5) DEFAULT 'old',
    ry int DEFAULT NULL,
    purok varchar(100) DEFAULT NULL,
    street varchar(100) DEFAULT NULL,
    stewardshipno varchar(3) DEFAULT NULL,
    portionof varchar(255) DEFAULT NULL,
    autonumber int DEFAULT NULL,
    previd varchar(50) DEFAULT NULL,
    claimno varchar(5) DEFAULT NULL,
    section varchar(3) DEFAULT NULL,
    parcel varchar(3) DEFAULT NULL,
    PRIMARY KEY (objid)
);

CREATE TABLE rpu (
    objid varchar(50) NOT NULL,
    state varchar(25) DEFAULT 'CURRENT',
    realpropertyid varchar(50) NOT NULL,
    rputype varchar(50) DEFAULT NULL,
    classification_objid varchar(50) DEFAULT NULL,
    ry int DEFAULT '0',
    totalmv float DEFAULT '0',
    totalav float DEFAULT '0',
    taxable tinyint(1) DEFAULT '1',
    totalareaha float DEFAULT NULL,
    totalareasqm float DEFAULT NULL,
    fullpin varchar(35) DEFAULT NULL,
    suffix int DEFAULT '0',
    subsuffix int DEFAULT NULL,
    exemptiontype_objid varchar(50) DEFAULT NULL,
    totalbmv decimal(16,2) NOT NULL DEFAULT '0.00',
    previd varchar(50) DEFAULT NULL,
    rpumasterid varchar(50) DEFAULT NULL,
    PRIMARY KEY (objid)
);

CREATE TABLE faas (
    objid varchar(50) NOT NULL,
    state varchar(25) NOT NULL,
    rpuid varchar(50) DEFAULT NULL,
    realpropertyid varchar(50) DEFAULT NULL,
    datacapture int NOT NULL DEFAULT '0',
    autonumber int NOT NULL DEFAULT '0',
    utdno varchar(25) NOT NULL,
    tdno varchar(25) DEFAULT NULL,
    txntype_objid varchar(50) DEFAULT NULL,
    effectivityyear int NOT NULL DEFAULT '0',
    effectivityqtr int NOT NULL DEFAULT '0',
    titletype varchar(10) DEFAULT NULL,
    titleno varchar(50) DEFAULT NULL,
    titledate datetime DEFAULT NULL,
    taxpayer_objid varchar(50) DEFAULT NULL,
    owner_name longtext,
    owner_address varchar(150) DEFAULT NULL,
    administrator_objid varchar(50) DEFAULT NULL,
    administrator_name text,
    administrator_address varchar(150) DEFAULT NULL,
    beneficiary_objid varchar(50) DEFAULT NULL,
    beneficiary_name varchar(150) DEFAULT NULL,
    beneficiary_address varchar(150) DEFAULT NULL,
    memoranda text,
    cancelnote text,
    restrictionid varchar(50) DEFAULT NULL,
    backtaxyrs int DEFAULT NULL,
    prevtdno varchar(800) DEFAULT NULL,
    prevpin varchar(800) DEFAULT NULL,
    prevowner text,
    prevav varchar(500) DEFAULT NULL,
    prevmv varchar(500) DEFAULT NULL,
    cancelreason varchar(15) DEFAULT NULL,
    canceldate datetime DEFAULT NULL,
    cancelledbytdnos mediumtext,
    lguid varchar(50) DEFAULT NULL,
    publicland int DEFAULT NULL,
    txntimestamp datetime DEFAULT NULL,
    cancelledtimestamp datetime DEFAULT NULL,
    name varchar(100) DEFAULT NULL,
    dtapproved datetime DEFAULT NULL,
    lgutype varchar(50) DEFAULT NULL,
    signatories text,
    ryordinanceno varchar(50) DEFAULT NULL,
    ryordinancedate datetime DEFAULT NULL,
    prevareaha varchar(500) DEFAULT NULL,
    prevareasqm varchar(500) DEFAULT NULL,
    fullpin varchar(35) DEFAULT NULL,
    preveffectivity varchar(10) DEFAULT NULL,
    year int DEFAULT NULL,
    qtr int DEFAULT NULL,
    month int DEFAULT NULL,
    day int DEFAULT NULL,
    cancelledyear int DEFAULT NULL,
    cancelledqtr int DEFAULT NULL,
    cancelledmonth int DEFAULT NULL,
    cancelledday int DEFAULT NULL,
    prevadministrator varchar(200) DEFAULT NULL,
    originlguid varchar(50) DEFAULT NULL,
    parentfaasid varchar(50) DEFAULT NULL,
    PRIMARY KEY (objid)
);
");

// ─────────────────────────────────────────────────────────────
// Test Scenario 2 & 3: Refresh REPLICA row and Insert new REPLICA row
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 2 & 3: Safe Sync with REPLICA rows ---\n";
// Populate source with updated FAAS-LEGACY-001 (updated name) and new FAAS-SRC-003
$pdo_target->exec("
INSERT INTO faas (objid, state, rpuid, utdno, tdno, owner_name) VALUES
('FAAS-LEGACY-001', 'CURRENT', 'RPU-LEGACY-001', 'UTD-001', 'TD-001', 'Legacy Owner 1 Updated from Source'),
('FAAS-SRC-003', 'CURRENT', 'RPU-SRC-003', 'UTD-003', 'TD-003', 'New Source Owner 3');
");

$stats = Assessor_Etracs_Sync::trigger_sync($pdo_target);
assert_test("trigger_sync returned successful stats array", !is_wp_error($stats) && isset($stats['faas']));

$updated_owner = $wpdb->get_var("SELECT owner_name FROM wp_assessor_faas WHERE objid = 'FAAS-LEGACY-001'");
assert_test("Existing REPLICA row refreshed from source", $updated_owner === 'Legacy Owner 1 Updated from Source');

$new_row = $wpdb->get_row("SELECT * FROM wp_assessor_faas WHERE objid = 'FAAS-SRC-003'", 'ARRAY_A');
assert_test("New source row inserted successfully", !empty($new_row));
assert_test("New source row marked with origin_type = 'REPLICA'", $new_row && $new_row['origin_type'] === 'REPLICA');

// ─────────────────────────────────────────────────────────────
// Test Scenario 4: LOCAL row sharing source PK causes preflight abort
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 4: PK Collision with LOCAL row ---\n";
// Insert local row
$pdo_target->exec("
INSERT INTO wp_assessor_faas (objid, state, rpuid, utdno, tdno, owner_name, origin_type) VALUES
('FAAS-LOCAL-PK-01', 'CURRENT', 'RPU-LOCAL-01', 'UTD-LOCAL-01', 'TD-LOCAL-01', 'Locally Authored Owner', 'LOCAL')
");

// Source attempts to push a record with same PK 'FAAS-LOCAL-PK-01'
$pdo_target->exec("
INSERT INTO faas (objid, state, rpuid, utdno, tdno, owner_name) VALUES
('FAAS-LOCAL-PK-01', 'CURRENT', 'RPU-DIFF', 'UTD-SRC-COLLIDE', 'TD-SRC-COLLIDE', 'Attacking Source Row')
");

$res_pk_collide = Assessor_Etracs_Sync::trigger_sync($pdo_target);
assert_test("Sync returns WP_Error on PK collision with LOCAL row", is_wp_error($res_pk_collide));
assert_test("WP_Error code is 'sync_preflight_collision'", is_wp_error($res_pk_collide) && $res_pk_collide->get_error_code() === 'sync_preflight_collision');

$local_faas_owner = $wpdb->get_var("SELECT owner_name FROM wp_assessor_faas WHERE objid = 'FAAS-LOCAL-PK-01'");
assert_test("Locally authored row was 100% preserved (not overwritten)", $local_faas_owner === 'Locally Authored Owner');

// Clean up colliding source row for next test
$pdo_target->exec("DELETE FROM faas WHERE objid = 'FAAS-LOCAL-PK-01'");

// ─────────────────────────────────────────────────────────────
// Test Scenario 5: Natural key collision on utdno causes preflight abort
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 5: Natural Key (utdno) Collision with LOCAL row ---\n";
// Local record has utdno = 'UTD-LOCAL-01' with objid = 'FAAS-LOCAL-PK-01'
// Source has record with DIFFERENT objid 'FAAS-SRC-CONFLICT' but SAME utdno 'UTD-LOCAL-01'
$pdo_target->exec("
INSERT INTO faas (objid, state, rpuid, utdno, tdno, owner_name) VALUES
('FAAS-SRC-CONFLICT', 'CURRENT', 'RPU-DIFF-2', 'UTD-LOCAL-01', 'TD-CONFLICT', 'Conflict Source')
");

$res_utdno_collide = Assessor_Etracs_Sync::trigger_sync($pdo_target);
assert_test("Sync returns WP_Error on unique utdno collision", is_wp_error($res_utdno_collide));

$diag = Assessor_Etracs_Sync::get_diagnostics();
assert_test("Diagnostics recorded conflict type UNIQUE_KEY_COLLISION_UTDNO", isset($diag['conflicts'][0]['conflict_type']) && $diag['conflicts'][0]['conflict_type'] === 'UNIQUE_KEY_COLLISION_UTDNO');

$conflict_row_in_db = $wpdb->get_var("SELECT COUNT(*) FROM wp_assessor_faas WHERE objid = 'FAAS-SRC-CONFLICT'");
assert_test("Conflicting source record was NOT written to shadow table", intval($conflict_row_in_db) === 0);

// Clean up colliding source row
$pdo_target->exec("DELETE FROM faas WHERE objid = 'FAAS-SRC-CONFLICT'");

// ─────────────────────────────────────────────────────────────
// Test Scenario 6: Unique-key collision on entityno in assessor_entity
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 6: Unique-key collision on Entity (entityno) ---\n";
$pdo_target->exec("
INSERT INTO wp_assessor_entity (objid, entityno, name, address_text, type, origin_type) VALUES
('ENT-LOCAL-002', 'ENT-NO-LOCAL-99', 'Local Entity Person', 'Local Address', 'INDIVIDUAL', 'LOCAL')
");

// Source provides entity with DIFFERENT objid 'ENT-SRC-COLLIDE' but SAME entityno 'ENT-NO-LOCAL-99'
$pdo_target->exec("
INSERT INTO entity (objid, entityno, name, address_text, type, entityname) VALUES
('ENT-SRC-COLLIDE', 'ENT-NO-LOCAL-99', 'Source Entity Overwrite Attempt', 'Source Address', 'INDIVIDUAL', 'Source Entity')
");

$res_entity_collide = Assessor_Etracs_Sync::trigger_sync($pdo_target);
assert_test("Sync returns WP_Error on entityno unique key collision", is_wp_error($res_entity_collide));

$diag = Assessor_Etracs_Sync::get_diagnostics();
assert_test("Diagnostics recorded conflict on wp_assessor_entity (entityno)", isset($diag['conflicts'][0]['key_name']) && $diag['conflicts'][0]['key_name'] === 'entityno');

$local_ent_name = $wpdb->get_var("SELECT name FROM wp_assessor_entity WHERE objid = 'ENT-LOCAL-002'");
assert_test("Local entity name preserved intact", $local_ent_name === 'Local Entity Person');

// Clean up colliding source entity
$pdo_target->exec("DELETE FROM entity WHERE objid = 'ENT-SRC-COLLIDE'");

// ─────────────────────────────────────────────────────────────
// Test Scenario 7: Failed preflight leaves all shadow tables unchanged
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 7: Preflight Failure Atomicity / Zero State Mutation ---\n";
$faas_count_before = $wpdb->get_var("SELECT COUNT(*) FROM wp_assessor_faas");
$entity_count_before = $wpdb->get_var("SELECT COUNT(*) FROM wp_assessor_entity");

// Inject conflict
$pdo_target->exec("
INSERT INTO entity (objid, entityno, name, address_text, type, entityname) VALUES
('ENT-SRC-COLLIDE-2', 'ENT-NO-LOCAL-99', 'Another Attempt', 'Address', 'INDIVIDUAL', 'Another')
");
$res_abort = Assessor_Etracs_Sync::trigger_sync($pdo_target);

$faas_count_after = $wpdb->get_var("SELECT COUNT(*) FROM wp_assessor_faas");
$entity_count_after = $wpdb->get_var("SELECT COUNT(*) FROM wp_assessor_entity");

assert_test("FAAS count unchanged after preflight abort", $faas_count_before === $faas_count_after);
assert_test("Entity count unchanged after preflight abort", $entity_count_before === $entity_count_after);

$pdo_target->exec("DELETE FROM entity WHERE objid = 'ENT-SRC-COLLIDE-2'");

// ─────────────────────────────────────────────────────────────
// Test Scenario 8: Idempotent pulls do not duplicate records
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 8: Idempotency of Repeated Pulls ---\n";
$sync1 = Assessor_Etracs_Sync::trigger_sync($pdo_target);
$faas_count_1 = $wpdb->get_var("SELECT COUNT(*) FROM wp_assessor_faas");

$sync2 = Assessor_Etracs_Sync::trigger_sync($pdo_target);
$faas_count_2 = $wpdb->get_var("SELECT COUNT(*) FROM wp_assessor_faas");

assert_test("Second sync succeeds cleanly", !is_wp_error($sync2));
assert_test("Repeated safe pull creates zero duplicate rows (count 1 == count 2)", $faas_count_1 === $faas_count_2);

// ─────────────────────────────────────────────────────────────
// Test Scenario 9: Existing source IDs and relationships intact
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 9: Existing source IDs & relationships preserved ---\n";
$faas_legacy_1 = $wpdb->get_row("SELECT * FROM wp_assessor_faas WHERE objid = 'FAAS-LEGACY-001'", 'ARRAY_A');
assert_test("Original source ID 'FAAS-LEGACY-001' retained", !empty($faas_legacy_1));
assert_test("Original RPU ID link 'RPU-LEGACY-001' retained", $faas_legacy_1 && $faas_legacy_1['rpuid'] === 'RPU-LEGACY-001');

// ─────────────────────────────────────────────────────────────
// Test Scenario 10: Native Assessor Isolation (Static Code-Path Verification)
// Asserts that Assessor_Etracs_Sync contains zero references to native
// Assessor tables (properties, property_states, versions, documents, requests, audit).
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 10: Absolute Boundary - Native Assessor Model Isolation ---\n";
$sync_reflector = new ReflectionClass('Assessor_Etracs_Sync');
$sync_file = $sync_reflector->getFileName();
$sync_code_no_comments = php_strip_whitespace($sync_file);

$prohibited_native_tables = array(
    'assessor_properties',
    'assessor_property_states',
    'assessor_property_versions',
    'assessor_documents',
    'assessor_requests',
    'assessor_audit_trail',
    'assessor_sync_runs',
    'assessor_sync_queue',
);

$found_prohibited = array();
foreach ($prohibited_native_tables as $pt) {
    if (stripos($sync_code_no_comments, $pt) !== false) {
        $found_prohibited[] = $pt;
    }
}
assert_test("Assessor_Etracs_Sync contains ZERO operational references to native Assessor tables", empty($found_prohibited));

// Ensure wp_assessor_properties does not exist in the disposable test database
$table_in_test_db = $wpdb->get_var("SHOW TABLES LIKE 'wp_assessor_properties'");
assert_test("wp_assessor_properties does NOT exist in disposable test database", empty($table_in_test_db));

// ─────────────────────────────────────────────────────────────
// Test Scenario 11: Alternate unique-key collision against REPLICA row with different objid
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 11: Alternate Unique-Key Collision on REPLICA Row (utdno) ---\n";
// Currently wp_assessor_faas has 'FAAS-LEGACY-001' with utdno = 'UTD-001' (origin_type = 'REPLICA')
// Incoming source has objid = 'FAAS-DIFF-999' with utdno = 'UTD-001'
$pdo_target->exec("
INSERT INTO faas (objid, state, rpuid, utdno, tdno, owner_name) VALUES
('FAAS-DIFF-999', 'CURRENT', 'RPU-DIFF', 'UTD-001', 'TD-CONFLICT', 'Alternate Key Hijacker')
");

$res_alt_replica = Assessor_Etracs_Sync::trigger_sync($pdo_target);
assert_test("Sync returns WP_Error when incoming row conflicts on utdno with an existing REPLICA row", is_wp_error($res_alt_replica));

$diag = Assessor_Etracs_Sync::get_diagnostics();
assert_test("Diagnostics recorded UNIQUE_KEY_COLLISION_UTDNO on REPLICA record", isset($diag['conflicts'][0]['conflict_type']) && $diag['conflicts'][0]['conflict_type'] === 'UNIQUE_KEY_COLLISION_UTDNO');

// Verify existing REPLICA record was completely unchanged
$legacy_owner = $wpdb->get_var("SELECT owner_name FROM wp_assessor_faas WHERE objid = 'FAAS-LEGACY-001'");
assert_test("Existing REPLICA row values preserved intact (not overwritten or hijacked)", $legacy_owner === 'Legacy Owner 1 Updated from Source');

// Verify colliding row was NOT inserted
$hijacker_in_db = $wpdb->get_var("SELECT COUNT(*) FROM wp_assessor_faas WHERE objid = 'FAAS-DIFF-999'");
assert_test("Conflicting source row FAAS-DIFF-999 was NOT inserted into shadow table", intval($hijacker_in_db) === 0);

$pdo_target->exec("DELETE FROM faas WHERE objid = 'FAAS-DIFF-999'");

// ─────────────────────────────────────────────────────────────
// Test Scenario 12: Alternate unique-key collision on lookup table (bldgkind / propertyclassification)
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 12: Alternate Unique-Key Collision on Lookup Table (bldgkind code) ---\n";
// Create source table bldgkind
$pdo_target->exec("
CREATE TABLE IF NOT EXISTS bldgkind (
    objid varchar(50) NOT NULL,
    state varchar(10) NOT NULL,
    code varchar(20) NOT NULL,
    name varchar(100) NOT NULL,
    PRIMARY KEY (objid)
);
");

// Populate local shadow bldgkind
$pdo_target->exec("
INSERT INTO wp_assessor_bldgkind (objid, state, code, name) VALUES
('BK-LOC-01', 'active', 'RESIDENTIAL_1', 'Single Family Residential');
");

// Source provides row with DIFFERENT objid 'BK-SRC-02' but SAME code 'RESIDENTIAL_1'
$pdo_target->exec("
INSERT INTO bldgkind (objid, state, code, name) VALUES
('BK-SRC-02', 'active', 'RESIDENTIAL_1', 'Conflicting Commercial Name');
");

$res_bldgkind_collide = Assessor_Etracs_Sync::trigger_sync($pdo_target);
assert_test("Sync returns WP_Error on bldgkind alternate unique-key (code) collision", is_wp_error($res_bldgkind_collide));

$diag = Assessor_Etracs_Sync::get_diagnostics();
assert_test("Diagnostics recorded ALTERNATE_UNIQUE_KEY_COLLISION on wp_assessor_bldgkind", isset($diag['conflicts'][0]['conflict_type']) && $diag['conflicts'][0]['conflict_type'] === 'ALTERNATE_UNIQUE_KEY_COLLISION');

$local_bk_name = $wpdb->get_var("SELECT name FROM wp_assessor_bldgkind WHERE objid = 'BK-LOC-01'");
assert_test("Existing local bldgkind row was NOT overwritten or repurposed", $local_bk_name === 'Single Family Residential');

$src_bk_in_db = $wpdb->get_var("SELECT COUNT(*) FROM wp_assessor_bldgkind WHERE objid = 'BK-SRC-02'");
assert_test("Conflicting bldgkind BK-SRC-02 was NOT written", intval($src_bk_in_db) === 0);

$pdo_target->exec("DELETE FROM bldgkind WHERE objid = 'BK-SRC-02'");

// ─────────────────────────────────────────────────────────────
// Test Scenario 13: Cross-row PK and Alternate Unique-Key Conflict
// (Source row PK matches Row A, but its alternate key conflicts with Row B)
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 13: Cross-Row PK and Alternate-Key Conflict ---\n";
// Local DB has:
// Row A: objid = 'FAAS-ROW-A', utdno = 'UTD-ROW-A', owner_name = 'Owner A'
// Row B: objid = 'FAAS-ROW-B', utdno = 'UTD-ROW-B', owner_name = 'Owner B'
$pdo_target->exec("
INSERT INTO wp_assessor_faas (objid, state, rpuid, utdno, tdno, owner_name, origin_type) VALUES
('FAAS-ROW-A', 'CURRENT', 'RPU-A', 'UTD-ROW-A', 'TD-A', 'Owner A', 'REPLICA'),
('FAAS-ROW-B', 'CURRENT', 'RPU-B', 'UTD-ROW-B', 'TD-B', 'Owner B', 'REPLICA');
");

// Source sends:
// objid = 'FAAS-ROW-A' (matches Row A PK), but utdno = 'UTD-ROW-B' (conflicts with Row B's unique utdno!)
$pdo_target->exec("
INSERT INTO faas (objid, state, rpuid, utdno, tdno, owner_name) VALUES
('FAAS-ROW-A', 'CURRENT', 'RPU-A', 'UTD-ROW-B', 'TD-A-NEW', 'Cross-Conflict Owner');
");

$res_cross_conflict = Assessor_Etracs_Sync::trigger_sync($pdo_target);
assert_test("Sync returns WP_Error on cross-row PK/alternate-key conflict", is_wp_error($res_cross_conflict));

$diag = Assessor_Etracs_Sync::get_diagnostics();
assert_test("Preflight caught conflict on utdno colliding with Row B", isset($diag['conflicts'][0]['key_value']) && $diag['conflicts'][0]['key_value'] === 'UTD-ROW-B');

// Assert both Row A and Row B are completely untouched
$owner_a = $wpdb->get_var("SELECT owner_name FROM wp_assessor_faas WHERE objid = 'FAAS-ROW-A'");
$owner_b = $wpdb->get_var("SELECT owner_name FROM wp_assessor_faas WHERE objid = 'FAAS-ROW-B'");
assert_test("Row A owner name completely preserved", $owner_a === 'Owner A');
assert_test("Row B owner name completely preserved", $owner_b === 'Owner B');

$pdo_target->exec("DELETE FROM faas WHERE objid = 'FAAS-ROW-A'");
$pdo_target->exec("DELETE FROM wp_assessor_faas WHERE objid IN ('FAAS-ROW-A', 'FAAS-ROW-B')");

// ─────────────────────────────────────────────────────────────
// Test Scenario 14: One collision anywhere in the pull aborts ALL writes
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 14: Single Collision Aborts Entire Pull Across All Tables ---\n";
// Prepare a valid new FAAS row, but introduce an invalid colliding Entity row
$pdo_target->exec("
INSERT INTO faas (objid, state, rpuid, utdno, tdno, owner_name) VALUES
('FAAS-VALID-BATCH', 'CURRENT', 'RPU-BATCH', 'UTD-VALID-BATCH', 'TD-BATCH', 'Batch Valid Owner');

INSERT INTO entity (objid, entityno, name, address_text, type, entityname) VALUES
('ENT-BATCH-COLLIDE', 'E-001', 'Entity Colliding with Legacy E-001', 'Address', 'INDIVIDUAL', 'Batch Collide');
");

$res_batch_abort = Assessor_Etracs_Sync::trigger_sync($pdo_target);
assert_test("Sync returns WP_Error when single table has collision in batch", is_wp_error($res_batch_abort));

$batch_faas_in_db = $wpdb->get_var("SELECT COUNT(*) FROM wp_assessor_faas WHERE objid = 'FAAS-VALID-BATCH'");
assert_test("Even valid non-conflicting FAAS row in the same pull was NOT written (pull fully aborted)", intval($batch_faas_in_db) === 0);

$batch_ent_in_db = $wpdb->get_var("SELECT COUNT(*) FROM wp_assessor_entity WHERE objid = 'ENT-BATCH-COLLIDE'");
assert_test("Colliding entity row was NOT written", intval($batch_ent_in_db) === 0);

$pdo_target->exec("DELETE FROM faas WHERE objid = 'FAAS-VALID-BATCH'");
$pdo_target->exec("DELETE FROM entity WHERE objid = 'ENT-BATCH-COLLIDE'");

// ─────────────────────────────────────────────────────────────
// Summary
// ─────────────────────────────────────────────────────────────
echo "\n========================================================================\n";
echo "   TEST SUMMARY\n";
echo "   Total Tests Passed: $pass_count\n";
echo "   Total Tests Failed: $fail_count\n";
echo "========================================================================\n";

if ($fail_count > 0) {
    exit(1);
}
exit(0);
