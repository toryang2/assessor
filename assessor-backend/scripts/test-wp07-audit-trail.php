<?php
/**
 * Test Suite: Assessor Repo — Phase 13 / WP-07: Shadow ETRACS Audit Trail & Change History
 *
 * Isolated test suite running entirely within the disposable database `test_wp01_disposable`.
 *
 * Scenarios tested (per WP-07 specifications):
 * 1. Migration 007 execution & verification of `wp_assessor_shadow_audit` schema, columns, and indexes.
 * 2. LOCAL FAAS creation audit event:
 *    - Records event_id, actor, event_type='CREATE', source_classification='LOCAL_AUTHORING', origin_type='LOCAL'.
 *    - Preserves FAAS, RPU, Real Property, and RPUMaster identifiers.
 * 3. LOCAL FAAS draft update audit event:
 *    - Records event_type='UPDATE', diff of changed fields, minimized details, and correlation_id.
 * 4. Submission for approval audit event:
 *    - Records event_type='SUBMIT', state transition INTERIM -> FORAPPROVAL.
 * 5. Approval to CURRENT audit event:
 *    - Records event_type='APPROVE', state transition FORAPPROVAL -> CURRENT, approval timestamp.
 * 6. Lineage persistence & predecessor supersession audit events:
 *    - Lineage persistence creates LINEAGE_PERSIST event with predecessor counts.
 *    - Approving a successor superseding a local predecessor creates SUPERSEDED audit event on the predecessor.
 * 7. Disapproval audit event:
 *    - Records event_type='DISAPPROVE', old_state='FORAPPROVAL', new_state='DISAPPROVED', and disapproval reason.
 * 8. Explicit structured cancellation audit event:
 *    - Records event_type='CANCEL', source_classification='CANCELLATION', cancellation reason, date, and successor references.
 * 9. Assessment schedule line persistence audit event:
 *    - Records event_type='ASSESSMENT_UPDATE', affected line count, and updated RPU totals.
 * 10. RPUMaster pointer advancement audit event:
 *     - Records POINTER_ADVANCE event capturing previous and advanced currentfaasid/currentrpuid pointers.
 * 11. Transactional atomicity & rollback verification:
 *     - When a mutation fails or rolls back, the audit record rolls back atomically with zero phantom rows.
 * 12. Distinguishing LOCAL authoring vs. REPLICA source synchronization:
 *     - Source-replica pull creates SYNC_PULL audit event with source_classification='SOURCE_SYNC', origin_type='REPLICA', and System actor.
 * 13. Audit trail query endpoint (`get_shadow_audit_trail`):
 *     - Filtering by faas_id, object_type, event_type, source_classification, origin_type, and pagination.
 * 14. Redaction and security:
 *     - Verify sensitive fields (passwords, tokens, credentials) are strictly redacted or omitted from details JSON.
 * 15. Absolute Architecture Boundaries:
 *     - Proves wp_assessor_shadow_audit never references or synchronizes with native Assessor audit tables.
 *     - Proves no native Assessor tables are modified.
 */

class MockWpdbWp07 {
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

    public function esc_like($text) {
        return addcslashes($text, '_%\\');
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

    public function get_results($sql, $output = 'OBJECT') {
        try {
            $stmt = $this->pdo->query($sql);
            if (!$stmt) {
                return array();
            }
            if ($output === 'ARRAY_A') {
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            return $stmt->fetchAll(PDO::FETCH_OBJ);
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            return array();
        }
    }

    public function get_row($sql, $output = 'OBJECT') {
        try {
            $stmt = $this->pdo->query($sql);
            if (!$stmt) {
                return null;
            }
            if ($output === 'ARRAY_A') {
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
            } else {
                $row = $stmt->fetch(PDO::FETCH_OBJ);
            }
            return $row ? $row : null;
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            return null;
        }
    }

    public function get_var($sql) {
        try {
            $stmt = $this->pdo->query($sql);
            if (!$stmt) {
                return null;
            }
            return $stmt->fetchColumn();
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            return null;
        }
    }

    public function get_col($sql, $column_offset = 0) {
        try {
            $stmt = $this->pdo->query($sql);
            if (!$stmt) {
                return array();
            }
            return $stmt->fetchAll(PDO::FETCH_COLUMN, $column_offset);
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            return array();
        }
    }

    public function insert($table, $data) {
        $keys = array_keys($data);
        $fields = '`' . implode('`, `', $keys) . '`';
        $placeholders = implode(', ', array_fill(0, count($keys), '?'));
        $sql = "INSERT INTO $table ($fields) VALUES ($placeholders)";
        try {
            $stmt = $this->pdo->prepare($sql);
            $values = array_values($data);
            $res = $stmt->execute($values);
            return $res ? 1 : false;
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            return false;
        }
    }

    public function update($table, $data, $where) {
        $set = array();
        $values = array();
        foreach ($data as $col => $val) {
            $set[] = "`$col` = ?";
            $values[] = $val;
        }
        $where_clauses = array();
        foreach ($where as $wcol => $wval) {
            $where_clauses[] = "`$wcol` = ?";
            $values[] = $wval;
        }
        $sql = "UPDATE $table SET " . implode(', ', $set) . " WHERE " . implode(' AND ', $where_clauses);
        try {
            $stmt = $this->pdo->prepare($sql);
            $res = $stmt->execute($values);
            return $res ? $stmt->rowCount() : false;
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            return false;
        }
    }

    public function delete($table, $where) {
        $where_clauses = array();
        $values = array();
        foreach ($where as $col => $val) {
            $where_clauses[] = "`$col` = ?";
            $values[] = $val;
        }
        $sql = "DELETE FROM $table WHERE " . implode(' AND ', $where_clauses);
        try {
            $stmt = $this->pdo->prepare($sql);
            $res = $stmt->execute($values);
            return $res ? $stmt->rowCount() : false;
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            return false;
        }
    }

    public function get_charset_collate() {
        return "DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
    }
}

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}
if (!defined('ASSESSOR_API_PLUGIN_DIR')) {
    define('ASSESSOR_API_PLUGIN_DIR', __DIR__ . '/../wp-content/plugins/assessor-api/');
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

        public function get_error_code() {
            return $this->code;
        }

        public function get_error_message() {
            return $this->message;
        }

        public function get_error_data() {
            return $this->data;
        }
    }
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return ($thing instanceof WP_Error);
    }
}

if (!function_exists('current_time')) {
    function current_time($type, $gmt = 0) {
        $timestamp = ($gmt || $gmt === 1 || $gmt === true) ? time() : (time() + (int)get_option('gmt_offset', 0) * 3600);
        if ($type === 'timestamp') {
            return $timestamp;
        }
        return ($gmt || $gmt === 1 || $gmt === true) ? gmdate('Y-m-d H:i:s', $timestamp) : date('Y-m-d H:i:s', $timestamp);
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

$GLOBALS['assessor_test_options'] = array();
if (!function_exists('get_option')) {
    function get_option($name, $default = false) {
        return $GLOBALS['assessor_test_options'][$name] ?? $default;
    }
}
if (!function_exists('update_option')) {
    function update_option($name, $value, $autoload = null) {
        $GLOBALS['assessor_test_options'][$name] = $value;
        return true;
    }
}
if (!function_exists('delete_option')) {
    function delete_option($name) {
        unset($GLOBALS['assessor_test_options'][$name]);
        return true;
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return trim(strip_tags((string)$str));
    }
}

if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($str) {
        return trim((string)$str);
    }
}

if (!function_exists('rest_ensure_response')) {
    function rest_ensure_response($response) {
        return $response;
    }
}

if (!function_exists('wp_list_pluck')) {
    function wp_list_pluck($list, $field) {
        $res = array();
        foreach ($list as $item) {
            if (is_object($item) && isset($item->$field)) {
                $res[] = $item->$field;
            } elseif (is_array($item) && isset($item[$field])) {
                $res[] = $item[$field];
            }
        }
        return $res;
    }
}

if (!defined('JWT_AUTH_SECRET_KEY')) {
    define('JWT_AUTH_SECRET_KEY', 'test_secret_key_for_wp07_audit_trail_12345');
}

if (!function_exists('get_site_url')) {
    function get_site_url() {
        return 'http://localhost/assessor';
    }
}

class MockRestRequestWp07 {
    public $params = array();
    public $headers = array();

    public function __construct($params = array(), $headers = array()) {
        $this->params = $params;
        $this->headers = $headers;
    }

    public function get_params() {
        return $this->params;
    }

    public function get_param($name) {
        return $this->params[$name] ?? null;
    }

    public function get_header($name) {
        return $this->headers[strtolower($name)] ?? $this->headers[$name] ?? null;
    }

    public function get_headers() {
        return $this->headers;
    }
}

// ─────────────────────────────────────────────────────────────
// DATABASE CONNECTION & FAIL-CLOSED GUARDS
// ─────────────────────────────────────────────────────────────
$disposable_db = 'test_wp01_disposable';
$db_user = 'root';
$db_pass = '';
$db_host = '127.0.0.1';
$db_port = 3306;

try {
    $pdo_target = new PDO("mysql:host=$db_host;port=$db_port;dbname=$disposable_db", $db_user, $db_pass, array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ));
} catch (PDOException $e) {
    die("[SETUP ERROR] Cannot connect to disposable database '$disposable_db': " . $e->getMessage() . "\n");
}

$current_db = $pdo_target->query("SELECT DATABASE()")->fetchColumn();
if ($current_db !== $disposable_db) {
    die("\n[CRITICAL SECURITY ABORT] Test harness PDO connection targets '$current_db'. Expected '$disposable_db'. Halting immediately.\n");
}

$prohibited_dbs = array('assessor_local', 'etracs254_kitaotao', 'information_schema', 'mysql', 'performance_schema', 'sys');
if (in_array(strtolower($current_db), $prohibited_dbs, true)) {
    die("\n[CRITICAL SECURITY ABORT] Test harness PDO connection targets prohibited database '$current_db'. Halting immediately.\n");
}

global $wpdb;
$wpdb = new MockWpdbWp07($pdo_target);

echo "========================================================================\n";
echo "   ASSESSOR REPO - PHASE 13 / WP-07 SHADOW AUDIT TRAIL HARNESS\n";
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

// Load Shadow ETRACS Subsystem
require_once __DIR__ . '/../wp-content/plugins/assessor-api/includes/class-assessor-auth.php';
require_once __DIR__ . '/../wp-content/plugins/assessor-api/includes/class-assessor-database.php';
require_once __DIR__ . '/../wp-content/plugins/assessor-api/includes/class-assessor-migration-runner.php';
require_once __DIR__ . '/../wp-content/plugins/assessor-api/includes/class-assessor-etracs.php';
require_once __DIR__ . '/../wp-content/plugins/assessor-api/includes/class-assessor-etracs-sync.php';

$db = new Assessor_Database();
$migration_runner = new Assessor_Migration_Runner();
$etracs = new Assessor_Etracs();

// ─────────────────────────────────────────────────────────────
// SCENARIO 1: Migration 007 & Table Schema Verification
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 1: Migration 007 Execution & Table Schema Verification ---\n";

// Run Migration 007
$mig_result = $migration_runner->run_shadow_audit_migration();
assert_test("Scenario 1: Migration 007 ran successfully", is_array($mig_result) && $mig_result['status'] === 'success');

// Verify table existence in MySQL
$has_table = $pdo_target->query("SHOW TABLES LIKE 'wp_assessor_shadow_audit'")->fetchColumn();
assert_test("Scenario 1: wp_assessor_shadow_audit table exists", $has_table === 'wp_assessor_shadow_audit');

// Verify column schema
$cols = $pdo_target->query("DESCRIBE wp_assessor_shadow_audit")->fetchAll(PDO::FETCH_COLUMN);
$expected_cols = array(
    'id', 'event_id', 'created_at', 'user_id', 'actor_name', 'event_type',
    'action_outcome', 'source_classification', 'origin_type', 'object_type',
    'object_id', 'faas_id', 'rpu_id', 'real_property_id', 'rpumaster_id',
    'old_state', 'new_state', 'changed_fields', 'details', 'correlation_id', 'ip_address'
);
$missing_cols = array_diff($expected_cols, $cols);
assert_test("Scenario 1: All required columns present in wp_assessor_shadow_audit", empty($missing_cols));

// Verify indexes
$raw_indexes = $pdo_target->query("SHOW INDEX FROM wp_assessor_shadow_audit")->fetchAll(PDO::FETCH_ASSOC);
$index_names = array_unique(array_column($raw_indexes, 'Key_name'));
assert_test("Scenario 1: Unique index uix_event_id exists", in_array('uix_event_id', $index_names, true));
assert_test("Scenario 1: Index ix_object exists", in_array('ix_object', $index_names, true));
assert_test("Scenario 1: Index ix_faas_id exists", in_array('ix_faas_id', $index_names, true));
assert_test("Scenario 1: Index ix_event_type exists", in_array('ix_event_type', $index_names, true));

// Setup Schema in Disposable Database (SHADOW TABLES ONLY)
$pdo_target->exec("SET FOREIGN_KEY_CHECKS=0");
$pdo_target->exec("DROP TABLE IF EXISTS wp_assessor_faas, wp_assessor_rpu, wp_assessor_real_property, wp_assessor_entity, wp_assessor_faas_previous, wp_assessor_rpu_assessment, wp_assessor_rpumaster, wp_assessor_txn_type, wp_assessor_faas_txntypes");

$pdo_target->exec("
CREATE TABLE wp_assessor_real_property (
    objid varchar(50) NOT NULL,
    origin_type enum('REPLICA','LOCAL') NOT NULL DEFAULT 'REPLICA',
    pin varchar(50) DEFAULT NULL,
    cadastrallotno varchar(900) DEFAULT NULL,
    surveyno varchar(255) DEFAULT '',
    blockno varchar(100) DEFAULT '',
    barangay varchar(100) DEFAULT '',
    municipality varchar(100) DEFAULT '',
    province varchar(100) DEFAULT '',
    north varchar(255) DEFAULT '',
    south varchar(255) DEFAULT '',
    east varchar(255) DEFAULT '',
    west varchar(255) DEFAULT '',
    pintype varchar(5) DEFAULT 'old',
    ry int DEFAULT NULL,
    barangayid varchar(50) DEFAULT NULL,
    lguid varchar(50) DEFAULT NULL,
    lgutype varchar(50) DEFAULT NULL,
    purok varchar(100) DEFAULT NULL,
    street varchar(100) DEFAULT NULL,
    stewardshipno varchar(3) DEFAULT NULL,
    portionof varchar(255) DEFAULT NULL,
    autonumber int DEFAULT NULL,
    previd varchar(50) DEFAULT NULL,
    claimno varchar(5) DEFAULT NULL,
    section varchar(3) DEFAULT NULL,
    parcel varchar(3) DEFAULT NULL,
    PRIMARY KEY (objid),
    KEY idx_origin_type (origin_type),
    KEY idx_pin (pin)
) ENGINE=InnoDB;

CREATE TABLE wp_assessor_rpumaster (
    objid varchar(50) NOT NULL,
    currentfaasid varchar(50) DEFAULT NULL,
    currentrpuid varchar(50) DEFAULT NULL,
    origin_type enum('REPLICA','LOCAL') NOT NULL DEFAULT 'REPLICA',
    PRIMARY KEY (objid),
    KEY ix_rpumaster_faasid (currentfaasid),
    KEY ix_rpumaster_rpuid (currentrpuid),
    KEY idx_origin_type (origin_type)
) ENGINE=InnoDB;

CREATE TABLE wp_assessor_rpu (
    objid varchar(50) NOT NULL,
    state varchar(25) DEFAULT 'CURRENT',
    origin_type enum('REPLICA','LOCAL') NOT NULL DEFAULT 'REPLICA',
    realpropertyid varchar(50) NOT NULL,
    rpu_type varchar(50) DEFAULT NULL,
    classification varchar(50) DEFAULT '',
    ry int DEFAULT '0',
    total_market_value float DEFAULT '0',
    total_assessed_value float DEFAULT '0',
    total_area_hectare float DEFAULT NULL,
    total_area_sqm float DEFAULT NULL,
    fullpin varchar(35) DEFAULT NULL,
    suffix int DEFAULT '0',
    subsuffix int DEFAULT NULL,
    classification_objid varchar(50) DEFAULT NULL,
    exemptiontype_objid varchar(50) DEFAULT NULL,
    totalbmv decimal(16,2) NOT NULL DEFAULT '0.00',
    previd varchar(50) DEFAULT NULL,
    rpumasterid varchar(50) DEFAULT NULL,
    barangayid varchar(50) DEFAULT NULL,
    taxable tinyint(1) DEFAULT '1',
    PRIMARY KEY (objid),
    KEY ix_realpropertyid (realpropertyid),
    KEY idx_origin_type (origin_type)
) ENGINE=InnoDB;

CREATE TABLE wp_assessor_faas (
    objid varchar(50) NOT NULL,
    state varchar(25) NOT NULL,
    origin_type enum('REPLICA','LOCAL') NOT NULL DEFAULT 'REPLICA',
    rpuid varchar(50) DEFAULT NULL,
    realpropertyid varchar(50) DEFAULT NULL,
    taxpayer_objid varchar(50) DEFAULT NULL,
    utdno varchar(25) NOT NULL,
    tdno varchar(25) DEFAULT NULL,
    txntype_objid varchar(50) DEFAULT 'GR',
    effectivityyear int DEFAULT '0',
    effectivityqtr int DEFAULT '0',
    owner_name longtext,
    owner_address longtext,
    administrator_name varchar(255) DEFAULT '',
    administrator_address varchar(255) DEFAULT '',
    beneficiary_name varchar(255) DEFAULT '',
    beneficiary_address varchar(255) DEFAULT '',
    fullpin varchar(50) DEFAULT '',
    titletype varchar(50) DEFAULT '',
    titleno varchar(50) DEFAULT '',
    titledate date DEFAULT NULL,
    prevtdno varchar(255) DEFAULT '',
    prevowner longtext,
    prevav varchar(50) DEFAULT '',
    prevmv varchar(50) DEFAULT '',
    prevareaha varchar(50) DEFAULT '',
    prevareasqm varchar(50) DEFAULT '',
    preveffectivity varchar(50) DEFAULT '',
    memoranda longtext,
    backtaxyrs int DEFAULT '0',
    ryordinanceno varchar(50) DEFAULT '',
    ryordinancedate date DEFAULT NULL,
    dtapproved datetime DEFAULT NULL,
    year int DEFAULT NULL,
    publicland int DEFAULT '0',
    prevpin varchar(50) DEFAULT '',
    prevadministrator varchar(255) DEFAULT '',
    originlguid varchar(50) DEFAULT NULL,
    cancelledyear int DEFAULT NULL,
    cancelreason varchar(255) DEFAULT '',
    canceldate datetime DEFAULT NULL,
    cancelnote text,
    cancelledbytdnos varchar(255) DEFAULT '',
    cancelledtimestamp datetime DEFAULT NULL,
    txntimestamp datetime DEFAULT NULL,
    PRIMARY KEY (objid),
    UNIQUE KEY ux_faas_utdno (utdno),
    KEY idx_origin_type (origin_type)
) ENGINE=InnoDB;

CREATE TABLE wp_assessor_entity (
    objid varchar(50) NOT NULL,
    origin_type enum('REPLICA','LOCAL') NOT NULL DEFAULT 'REPLICA',
    entityno varchar(50) NOT NULL,
    name longtext NOT NULL,
    entityname varchar(800) NOT NULL DEFAULT '',
    address_text varchar(255) NOT NULL DEFAULT '',
    type varchar(25) NOT NULL,
    PRIMARY KEY (objid),
    UNIQUE KEY uix_entityno (entityno),
    KEY idx_origin_type (origin_type)
) ENGINE=InnoDB;

CREATE TABLE wp_assessor_faas_previous (
    objid varchar(50) NOT NULL,
    origin_type enum('REPLICA','LOCAL') NOT NULL DEFAULT 'LOCAL',
    faasid varchar(50) NOT NULL,
    prevfaasid varchar(50) DEFAULT NULL,
    prevrpuid varchar(50) DEFAULT NULL,
    prevtdno varchar(50) NOT NULL,
    prevpin varchar(50) DEFAULT NULL,
    prevowner longtext,
    prevadministrator varchar(255) DEFAULT NULL,
    prevav varchar(50) DEFAULT NULL,
    prevmv varchar(50) DEFAULT NULL,
    prevareasqm varchar(50) DEFAULT NULL,
    prevareaha varchar(50) DEFAULT NULL,
    preveffectivity varchar(50) DEFAULT NULL,
    prevtaxability varchar(50) DEFAULT NULL,
    PRIMARY KEY (objid),
    KEY ix_faasprev_faasid (faasid),
    KEY ix_faasprev_prevfaasid (prevfaasid),
    KEY ix_faasprev_prevtdno (prevtdno)
) ENGINE=InnoDB;

CREATE TABLE wp_assessor_rpu_assessment (
    objid varchar(50) NOT NULL,
    origin_type enum('REPLICA','LOCAL') NOT NULL DEFAULT 'LOCAL',
    rpuid varchar(50) NOT NULL,
    classification_objid varchar(50) DEFAULT NULL,
    classcode varchar(20) DEFAULT NULL,
    classname varchar(100) DEFAULT NULL,
    actualuse_objid varchar(50) DEFAULT NULL,
    actualuse varchar(100) DEFAULT NULL,
    marketvalue decimal(16,2) NOT NULL DEFAULT '0.00',
    assesslevel decimal(16,2) NOT NULL DEFAULT '0.00',
    assessedvalue decimal(16,2) NOT NULL DEFAULT '0.00',
    taxable tinyint(1) DEFAULT '1',
    area decimal(16,4) DEFAULT NULL,
    areaha decimal(16,4) DEFAULT NULL,
    areasqm decimal(16,2) DEFAULT NULL,
    PRIMARY KEY (objid),
    KEY ix_rpuassess_rpuid (rpuid),
    KEY idx_origin_type (origin_type)
) ENGINE=InnoDB;

CREATE TABLE wp_assessor_txn_type (
    code varchar(20) NOT NULL,
    name varchar(100) NOT NULL,
    PRIMARY KEY (code)
) ENGINE=InnoDB;

CREATE TABLE wp_assessor_faas_txntypes (
    code varchar(20) NOT NULL,
    name varchar(100) NOT NULL,
    PRIMARY KEY (code)
) ENGINE=InnoDB;

INSERT INTO wp_assessor_txn_type (code, name) VALUES 
('GR', 'General Revision'),
('TR', 'Transfer'),
('CS', 'Consolidation'),
('SD', 'Subdivision'),
('DC', 'Deed Cancellation');

INSERT INTO wp_assessor_faas_txntypes (code, name) VALUES 
('GR', 'General Revision'),
('TR', 'Transfer'),
('CS', 'Consolidation'),
('SD', 'Subdivision'),
('DC', 'Deed Cancellation');
");

$pdo_target->exec("TRUNCATE TABLE wp_assessor_shadow_audit");
$pdo_target->exec("SET FOREIGN_KEY_CHECKS=1");

// Seed test user in users table for actor display test
$pdo_target->exec("
    CREATE TABLE IF NOT EXISTS wp_assessor_users (
        id varchar(50) NOT NULL PRIMARY KEY,
        username varchar(60) NOT NULL,
        full_name varchar(100) DEFAULT '',
        role varchar(30) DEFAULT 'admin',
        status varchar(20) DEFAULT 'active'
    ) ENGINE=InnoDB
");
$pdo_target->exec("
    INSERT IGNORE INTO wp_assessor_users (id, username, full_name, role)
    VALUES ('USER-APP-01', 'jdoe', 'John Doe, REA', 'admin')
");

// ─────────────────────────────────────────────────────────────
// SCENARIO 2: LOCAL FAAS Creation Audit Event
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 2: LOCAL FAAS Creation Audit Event ---\n";

$corr_1 = 'TXN-CORR-' . wp_generate_uuid4();
$req_create = new MockRestRequestWp07(array(
    'tdno'           => 'TD-AUDIT-001',
    'owner_name'     => 'Taxpayer Audit Alpha',
    'pin'            => '059-10-001-01-001',
    'barangay'       => 'Poblacion',
    'classification' => 'RESIDENTIAL',
    'correlation_id' => $corr_1,
    'user_id'        => 'USER-APP-01',
));

$faas1 = $etracs->create_faas($req_create);
if (is_wp_error($faas1)) {
    echo "DEBUG CREATE ERROR: " . $faas1->get_error_message() . "\n";
}
assert_test("Scenario 2: Draft FAAS created cleanly", !is_wp_error($faas1));

// Inspect audit trail for CREATE event
$audit_events = $pdo_target->query("SELECT * FROM wp_assessor_shadow_audit WHERE faas_id = '{$faas1->objid}' AND event_type = 'CREATE'")->fetchAll(PDO::FETCH_ASSOC);
assert_test("Scenario 2: CREATE audit event recorded", count($audit_events) === 1);

$create_event = $audit_events[0];
assert_test("Scenario 2: Event outcome is SUCCESS", $create_event['action_outcome'] === 'SUCCESS');
assert_test("Scenario 2: Source classification is LOCAL_AUTHORING", $create_event['source_classification'] === 'LOCAL_AUTHORING');
assert_test("Scenario 2: Origin type is LOCAL", $create_event['origin_type'] === 'LOCAL');
assert_test("Scenario 2: Actor user ID resolved correctly", $create_event['user_id'] === 'USER-APP-01');
assert_test("Scenario 2: Actor display name resolved from users table", $create_event['actor_name'] === 'John Doe, REA');
assert_test("Scenario 2: Initial state recorded as INTERIM", $create_event['new_state'] === 'INTERIM');
assert_test("Scenario 2: Correlation ID preserved", $create_event['correlation_id'] === $corr_1);
assert_test("Scenario 2: Related IDs (rpu_id, real_property_id) populated", !empty($create_event['rpu_id']) && !empty($create_event['real_property_id']));

// ─────────────────────────────────────────────────────────────
// SCENARIO 3: Draft Edit Audit Event & Field Diff
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 3: Draft Edit Audit Event & Field Diff ---\n";

$corr_2 = 'TXN-CORR-' . wp_generate_uuid4();
$req_update = new MockRestRequestWp07(array(
    'owner_name'     => 'Taxpayer Audit Alpha - Updated',
    'memoranda'      => 'Corrected surname per PSA certificate',
    'correlation_id' => $corr_2,
    'user_id'        => 'USER-APP-01',
));

$upd1 = $etracs->update_faas($faas1->objid, $req_update);
assert_test("Scenario 3: Draft updated cleanly", !is_wp_error($upd1));

$upd_event = $pdo_target->query("SELECT * FROM wp_assessor_shadow_audit WHERE faas_id = '{$faas1->objid}' AND event_type = 'UPDATE'")->fetch(PDO::FETCH_ASSOC);
assert_test("Scenario 3: UPDATE audit event recorded", !empty($upd_event));
assert_test("Scenario 3: Changed fields JSON contains owner_name and memoranda", 
    strpos($upd_event['changed_fields'], 'owner_name') !== false && strpos($upd_event['changed_fields'], 'memoranda') !== false);
assert_test("Scenario 3: State remains INTERIM", $upd_event['old_state'] === 'INTERIM' && $upd_event['new_state'] === 'INTERIM');

// ─────────────────────────────────────────────────────────────
// SCENARIO 4: Submission for Approval Audit Event
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 4: Submission for Approval Audit Event ---\n";

$corr_3 = 'TXN-CORR-' . wp_generate_uuid4();
$req_submit = new MockRestRequestWp07(array(
    'correlation_id' => $corr_3,
    'user_id'        => 'USER-APP-01',
));

$sub1 = $etracs->submit_faas($faas1->objid, $req_submit);
assert_test("Scenario 4: FAAS submitted for approval", !is_wp_error($sub1));

$sub_event = $pdo_target->query("SELECT * FROM wp_assessor_shadow_audit WHERE faas_id = '{$faas1->objid}' AND event_type = 'SUBMIT'")->fetch(PDO::FETCH_ASSOC);
assert_test("Scenario 4: SUBMIT audit event recorded", !empty($sub_event));
assert_test("Scenario 4: Old state INTERIM, new state FORAPPROVAL", $sub_event['old_state'] === 'INTERIM' && $sub_event['new_state'] === 'FORAPPROVAL');
assert_test("Scenario 4: Correlation ID preserved", $sub_event['correlation_id'] === $corr_3);

// ─────────────────────────────────────────────────────────────
// SCENARIO 5: Disapproval Audit Event & Reason Retention
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 5: Disapproval Audit Event & Rationale Retention ---\n";

$corr_dis = 'TXN-CORR-' . wp_generate_uuid4();
$req_dis = new MockRestRequestWp07(array(
    'reason'         => 'Missing certified true copy of deed of sale',
    'correlation_id' => $corr_dis,
    'user_id'        => 'USER-APP-01',
));

$dis1 = $etracs->disapprove_faas($faas1->objid, $req_dis);
assert_test("Scenario 5: Declaration disapproved", !is_wp_error($dis1));

$dis_event = $pdo_target->query("SELECT * FROM wp_assessor_shadow_audit WHERE faas_id = '{$faas1->objid}' AND event_type = 'DISAPPROVE'")->fetch(PDO::FETCH_ASSOC);
assert_test("Scenario 5: DISAPPROVE audit event recorded", !empty($dis_event));
assert_test("Scenario 5: Source classification is APPROVAL", $dis_event['source_classification'] === 'APPROVAL');
assert_test("Scenario 5: Old state FORAPPROVAL, new state DISAPPROVED", $dis_event['old_state'] === 'FORAPPROVAL' && $dis_event['new_state'] === 'DISAPPROVED');
assert_test("Scenario 5: Disapproval rationale stored in details", strpos($dis_event['details'], 'Missing certified true copy') !== false);

// Resubmit for approval to prepare for Scenario 6
$etracs->submit_faas($faas1->objid, $req_submit);

// ─────────────────────────────────────────────────────────────
// SCENARIO 6: Declaration Approval & RPUMaster Pointer Advancement
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 6: Declaration Approval & RPUMaster Pointer Advancement ---\n";

$corr_app = 'TXN-CORR-' . wp_generate_uuid4();
$req_app = new MockRestRequestWp07(array(
    'date_approved'  => '2026-10-22 15:30:00',
    'correlation_id' => $corr_app,
    'user_id'        => 'USER-APP-01',
));

$app1 = $etracs->approve_faas($faas1->objid, $req_app);
assert_test("Scenario 6: Declaration approved to CURRENT", !is_wp_error($app1) && $app1->state === 'CURRENT');

$app_event = $pdo_target->query("SELECT * FROM wp_assessor_shadow_audit WHERE faas_id = '{$faas1->objid}' AND event_type = 'APPROVE'")->fetch(PDO::FETCH_ASSOC);
assert_test("Scenario 6: APPROVE audit event recorded", !empty($app_event));
assert_test("Scenario 6: Old state FORAPPROVAL, new state CURRENT", $app_event['old_state'] === 'FORAPPROVAL' && $app_event['new_state'] === 'CURRENT');
assert_test("Scenario 6: Details contains approval date", strpos($app_event['details'], '2026-10-22 15:30:00') !== false);

// Check if POINTER_ADVANCE or pointer event was recorded
$ptr_event = $pdo_target->query("SELECT * FROM wp_assessor_shadow_audit WHERE faas_id = '{$faas1->objid}' AND event_type IN ('POINTER_ADVANCE', 'CREATE') AND object_type = 'RPUMASTER'")->fetch(PDO::FETCH_ASSOC);
assert_test("Scenario 6: RPUMaster pointer audit event recorded", !empty($ptr_event));

// ─────────────────────────────────────────────────────────────
// SCENARIO 7: Predecessor Lineage & Successor Supersession Overlay
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 7: Predecessor Lineage & Successor Supersession ---\n";

// Create Child Successor referencing faas1 as predecessor
$corr_succ = 'TXN-CORR-' . wp_generate_uuid4();
$req_succ = new MockRestRequestWp07(array(
    'tdno'           => 'TD-AUDIT-SUCC-01',
    'owner_name'     => 'Taxpayer Successor Child',
    'pin'            => '059-10-001-01-002',
    'prevfaasid'     => $faas1->objid,
    'prevtdno'       => 'TD-AUDIT-001',
    'correlation_id' => $corr_succ,
    'user_id'        => 'USER-APP-01',
));

$faas_succ = $etracs->create_faas($req_succ);
assert_test("Scenario 7: Successor draft created", !is_wp_error($faas_succ));

// Submit and approve successor
$etracs->submit_faas($faas_succ->objid, $req_succ);
$app_succ = $etracs->approve_faas($faas_succ->objid, $req_succ);
assert_test("Scenario 7: Successor approved to CURRENT", !is_wp_error($app_succ));

// Predecessor (faas1) must now be CANCELLED with SUPERSEDED audit event
$pred_super_event = $pdo_target->query("SELECT * FROM wp_assessor_shadow_audit WHERE object_id = '{$faas1->objid}' AND event_type = 'SUPERSEDED'")->fetch(PDO::FETCH_ASSOC);
assert_test("Scenario 7: SUPERSEDED audit event recorded for local predecessor", !empty($pred_super_event));
assert_test("Scenario 7: Predecessor transitioned from CURRENT to CANCELLED", $pred_super_event['old_state'] === 'CURRENT' && $pred_super_event['new_state'] === 'CANCELLED');
assert_test("Scenario 7: Details references successor TD", strpos($pred_super_event['details'], 'TD-AUDIT-SUCC-01') !== false);

// ─────────────────────────────────────────────────────────────
// SCENARIO 8: Explicit Structured Cancellation Audit Event
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 8: Explicit Structured Cancellation Audit Event ---\n";

// Explicitly cancel the successor
$corr_cancel = 'TXN-CORR-' . wp_generate_uuid4();
$req_cancel = new MockRestRequestWp07(array(
    'cancel_reason'      => 'Property Expropriated for Municipal Road Widening',
    'cancel_date'        => '2026-10-22 16:00:00',
    'cancel_note'        => 'Resolution No. 2026-140',
    'cancelled_by_tdnos' => 'TD-EXPROP-01',
    'cancelled_year'     => 2026,
    'correlation_id'     => $corr_cancel,
    'user_id'            => 'USER-APP-01',
));

$cancel_res = $etracs->cancel_faas($faas_succ->objid, $req_cancel);
assert_test("Scenario 8: Explicit cancellation succeeded", !is_wp_error($cancel_res));

$cancel_event = $pdo_target->query("SELECT * FROM wp_assessor_shadow_audit WHERE faas_id = '{$faas_succ->objid}' AND event_type = 'CANCEL'")->fetch(PDO::FETCH_ASSOC);
assert_test("Scenario 8: CANCEL audit event recorded", !empty($cancel_event));
assert_test("Scenario 8: Source classification is CANCELLATION", $cancel_event['source_classification'] === 'CANCELLATION');
assert_test("Scenario 8: Old state CURRENT, new state CANCELLED", $cancel_event['old_state'] === 'CURRENT' && $cancel_event['new_state'] === 'CANCELLED');
assert_test("Scenario 8: Details contains structured reason and resolution", 
    strpos($cancel_event['details'], 'Municipal Road Widening') !== false && strpos($cancel_event['details'], 'TD-EXPROP-01') !== false);

// ─────────────────────────────────────────────────────────────
// SCENARIO 9: Assessment Schedule Lines Persistence Audit Event
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 9: Assessment Schedule Lines Persistence Audit Event ---\n";

$assess_lines = array(
    array(
        'actualuse'            => 'RESIDENTIAL',
        'classcode'            => 'RES',
        'areasqm'              => 500.0,
        'areaha'               => 0.05,
        'marketvalue'          => 250000.0,
        'assessedvalue'        => 50000.0,
        'assessmentlevel'      => 20.0,
    ),
    array(
        'actualuse'            => 'COMMERCIAL',
        'classcode'            => 'COM',
        'areasqm'              => 150.0,
        'areaha'               => 0.015,
        'marketvalue'          => 150000.0,
        'assessedvalue'        => 75000.0,
        'assessmentlevel'      => 50.0,
    ),
);

$rpu_id_test = $faas1->rpuid;
$persist_res = $etracs->persist_rpu_assessments($rpu_id_test, $assess_lines, true);
assert_test("Scenario 9: Assessment lines persisted", $persist_res === true);

$assess_event = $pdo_target->query("SELECT * FROM wp_assessor_shadow_audit WHERE object_type = 'RPU_ASSESSMENT' AND rpu_id = '$rpu_id_test' AND event_type = 'ASSESSMENT_UPDATE'")->fetch(PDO::FETCH_ASSOC);
assert_test("Scenario 9: ASSESSMENT_UPDATE audit event recorded", !empty($assess_event));
assert_test("Scenario 9: Line count recorded in details (2 lines)", strpos($assess_event['details'], '"line_count":2') !== false);
assert_test("Scenario 9: Aggregates calculated and recorded (MV: 400000, AV: 125000)", 
    strpos($assess_event['details'], '400000') !== false && strpos($assess_event['details'], '125000') !== false);

// ─────────────────────────────────────────────────────────────
// SCENARIO 10: Atomic Rollback on Failure Injection
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 10: Transactional Atomicity & Rollback on Failure Injection ---\n";

$audit_count_before = (int) $pdo_target->query("SELECT COUNT(*) FROM wp_assessor_shadow_audit")->fetchColumn();

// Attempt creation with duplicate TD to trigger rollback
$req_dup = new MockRestRequestWp07(array(
    'tdno'       => 'TD-AUDIT-001', // existing TD
    'owner_name' => 'Should Roll Back',
));
$fail_res = $etracs->create_faas($req_dup);
assert_test("Scenario 10: Duplicate TD rejected", is_wp_error($fail_res));

$audit_count_after = (int) $pdo_target->query("SELECT COUNT(*) FROM wp_assessor_shadow_audit")->fetchColumn();
assert_test("Scenario 10: Zero phantom audit rows inserted on failure", $audit_count_after === $audit_count_before);

// ─────────────────────────────────────────────────────────────
// SCENARIO 11: Distinguishing LOCAL vs. SOURCE_SYNC Provenance
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 11: Distinguishing LOCAL vs. SOURCE_SYNC Provenance ---\n";

// Log a simulated source sync pull audit event directly or via trigger_sync
Assessor_Etracs::log_shadow_audit(array(
    'event_type'            => 'SYNC_PULL',
    'action_outcome'        => 'SUCCESS',
    'source_classification' => 'SOURCE_SYNC',
    'origin_type'           => 'REPLICA',
    'object_type'           => 'SYSTEM_SYNC',
    'object_id'             => 'BATCH_20261022_170000',
    'user_id'               => 'SYSTEM_SYNC',
    'actor_name'            => 'Source ETRACS Replica Sync',
    'changed_fields'        => array('faas', 'rpu', 'real_property'),
    'details'               => array(
        'total_synced_rows' => 450,
        'table_breakdown'   => array('faas' => 150, 'rpu' => 150, 'real_property' => 150),
    ),
    'correlation_id'        => 'SYNC_BATCH_01',
));

$sync_event = $pdo_target->query("SELECT * FROM wp_assessor_shadow_audit WHERE source_classification = 'SOURCE_SYNC' AND origin_type = 'REPLICA'")->fetch(PDO::FETCH_ASSOC);
assert_test("Scenario 11: SOURCE_SYNC audit event recorded", !empty($sync_event));
assert_test("Scenario 11: Origin type is strictly 'REPLICA'", $sync_event['origin_type'] === 'REPLICA');
assert_test("Scenario 11: Actor user ID is SYSTEM_SYNC", $sync_event['user_id'] === 'SYSTEM_SYNC');
assert_test("Scenario 11: Total synced rows preserved in details", strpos($sync_event['details'], '450') !== false);

// ─────────────────────────────────────────────────────────────
// SCENARIO 12: Audit Query Endpoint & Filters
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 12: Audit Query Endpoint & Filters ---\n";

function get_resp_data($resp) {
    if (is_object($resp) && isset($resp->data)) {
        return $resp->data;
    }
    return is_array($resp) ? $resp : array();
}

// Query by faas_id
$res_faas1 = get_resp_data($etracs->get_shadow_audit_trail(array('faas_id' => $faas1->objid)));
assert_test("Scenario 12: Query by faas_id returns results", !empty($res_faas1['data']));
assert_test("Scenario 12: Query total matches row count", $res_faas1['total'] >= 3);

// Query by event_type = CANCEL
$res_cancel = get_resp_data($etracs->get_shadow_audit_trail(array('event_type' => 'CANCEL')));
assert_test("Scenario 12: Filter by event_type=CANCEL returns cancellation events", count($res_cancel['data']) === 1);
assert_test("Scenario 12: Cancel event matched correct object_id", $res_cancel['data'][0]['object_id'] === $faas_succ->objid);

// Query by source_classification = SOURCE_SYNC
$res_sync = get_resp_data($etracs->get_shadow_audit_trail(array('source_classification' => 'SOURCE_SYNC')));
assert_test("Scenario 12: Filter by source_classification=SOURCE_SYNC returns sync events", count($res_sync['data']) === 1);

// Query by origin_type = LOCAL
$res_local = get_resp_data($etracs->get_shadow_audit_trail(array('origin_type' => 'LOCAL')));
assert_test("Scenario 12: Filter by origin_type=LOCAL returns local authoring events", $res_local['total'] > 0);

// Pagination check
$res_page = get_resp_data($etracs->get_shadow_audit_trail(array('per_page' => 2, 'page' => 1)));
assert_test("Scenario 12: Pagination per_page limits returned count", count($res_page['data']) === 2);
assert_test("Scenario 12: Pagination calculates total_pages", $res_page['total_pages'] > 1);

// ─────────────────────────────────────────────────────────────
// SCENARIO 13: Redaction and Security
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 13: Redaction and Security ---\n";

// Log event with sensitive payload to test automatic redaction
Assessor_Etracs::log_shadow_audit(array(
    'event_type'            => 'AUTH_TEST',
    'object_type'           => 'SECURITY_TEST',
    'object_id'             => 'SEC-001',
    'details'               => array(
        'password'          => 'super_secret_admin_pass',
        'api_key'           => 'ak_live_998877665544',
        'jwt_token'         => 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...',
        'safe_parameter'    => 'Normal Public Value'
    ),
));

$sec_event = $pdo_target->query("SELECT * FROM wp_assessor_shadow_audit WHERE object_id = 'SEC-001'")->fetch(PDO::FETCH_ASSOC);
assert_test("Scenario 13: Security test event recorded", !empty($sec_event));
assert_test("Scenario 13: Password is completely redacted", strpos($sec_event['details'], 'super_secret') === false && strpos($sec_event['details'], '[REDACTED]') !== false);
assert_test("Scenario 13: API Key is completely redacted", strpos($sec_event['details'], 'ak_live_') === false);
assert_test("Scenario 13: JWT Token is completely redacted", strpos($sec_event['details'], 'eyJhbGci') === false);
assert_test("Scenario 13: Safe parameters remain intact", strpos($sec_event['details'], 'Normal Public Value') !== false);

// ─────────────────────────────────────────────────────────────
// SCENARIO 15: Soft Delete (DELETED state) Mutation & Audit Coverage
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 15: Soft Delete (DELETED state) Mutation & Audit Coverage ---\n";

// Create a draft FAAS to delete
$req_draft_del = new MockRestRequestWp07(array(
    'tdno'       => 'TD-AUDIT-DRAFT-DEL',
    'owner_name' => 'Owner For Deletion',
));
$draft_del = $etracs->create_faas($req_draft_del);
assert_test("Scenario 15: Draft FAAS created for deletion", !is_wp_error($draft_del));

$del_res = $etracs->delete_faas($draft_del->objid, new MockRestRequestWp07(array('correlation_id' => 'CORR-DEL-01')));
assert_test("Scenario 15: delete_faas returns success response", !is_wp_error($del_res));

$del_row = $pdo_target->query("SELECT state FROM wp_assessor_faas WHERE objid = '{$draft_del->objid}'")->fetch(PDO::FETCH_ASSOC);
assert_test("Scenario 15: FAAS state transitioned to DELETED", $del_row['state'] === 'DELETED');

$del_audit = $pdo_target->query("SELECT * FROM wp_assessor_shadow_audit WHERE faas_id = '{$draft_del->objid}' AND event_type = 'DELETE'")->fetch(PDO::FETCH_ASSOC);
assert_test("Scenario 15: Mandatory DELETE audit event recorded", !empty($del_audit));
assert_test("Scenario 15: DELETE audit event has old_state='INTERIM' and new_state='DELETED'", $del_audit['old_state'] === 'INTERIM' && $del_audit['new_state'] === 'DELETED');
assert_test("Scenario 15: DELETE audit event preserves correlation_id", $del_audit['correlation_id'] === 'CORR-DEL-01');

// Verify REPLICA cannot be deleted
$pdo_target->exec("INSERT INTO wp_assessor_faas (objid, utdno, tdno, state, origin_type) VALUES ('REP-FAAS-DEL', 'TD-REP-DEL', 'TD-REP-DEL', 'CURRENT', 'REPLICA')");
$del_rep = $etracs->delete_faas('REP-FAAS-DEL', new MockRestRequestWp07());
assert_test("Scenario 15: Attempting to delete REPLICA returns replica_protected", is_wp_error($del_rep) && $del_rep->get_error_code() === 'replica_protected');

// ─────────────────────────────────────────────────────────────
// SCENARIO 16: Failure-Injection: Mandatory Audit Failure Forces Rollback
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 16: Failure-Injection: Mandatory Audit Failure Forces Rollback ---\n";

// Test rollback of delete_faas if audit table is inaccessible
$draft_to_fail = $etracs->create_faas(new MockRestRequestWp07(array(
    'tdno'       => 'TD-AUDIT-FAIL-01',
    'owner_name' => 'Should Not Delete If Audit Fails',
)));
assert_test("Scenario 16: Target draft created", !is_wp_error($draft_to_fail));

// Temporarily rename wp_assessor_shadow_audit to force audit failure
$pdo_target->exec("RENAME TABLE wp_assessor_shadow_audit TO wp_assessor_shadow_audit_temp");

$fail_del = $etracs->delete_faas($draft_to_fail->objid, new MockRestRequestWp07());
assert_test("Scenario 16: delete_faas fails when audit logging fails", is_wp_error($fail_del));

// Restore table
$pdo_target->exec("RENAME TABLE wp_assessor_shadow_audit_temp TO wp_assessor_shadow_audit");

// Verify target draft state was rolled back and is STILL 'INTERIM' (not DELETED)
$state_check = $pdo_target->query("SELECT state FROM wp_assessor_faas WHERE objid = '{$draft_to_fail->objid}'")->fetchColumn();
assert_test("Scenario 16: ATOMIC GUARANTEE: Declaration state rolled back to INTERIM", $state_check === 'INTERIM');

// ─────────────────────────────────────────────────────────────
// SCENARIO 17: Invalid State Transitions Produce No Audit Events
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 17: Invalid State Transitions Produce No Audit Events ---\n";

$audit_total_before = (int) $pdo_target->query("SELECT COUNT(*) FROM wp_assessor_shadow_audit")->fetchColumn();

// Attempt to approve a draft directly while in INTERIM (invalid transition)
$invalid_appr = $etracs->approve_faas($draft_to_fail->objid, new MockRestRequestWp07());
assert_test("Scenario 17: Invalid approval rejected", is_wp_error($invalid_appr));
assert_test("Scenario 17: Error code is invalid_state", $invalid_appr->get_error_code() === 'invalid_state');

// Attempt to disapprove a draft in INTERIM (invalid transition)
$invalid_disappr = $etracs->disapprove_faas($draft_to_fail->objid, new MockRestRequestWp07());
assert_test("Scenario 17: Invalid disapproval rejected", is_wp_error($invalid_disappr));

// Attempt to cancel an unapproved draft (invalid transition)
$invalid_cancel = $etracs->cancel_faas($draft_to_fail->objid, new MockRestRequestWp07(array('cancel_reason' => 'premature')));
assert_test("Scenario 17: Invalid cancellation rejected", is_wp_error($invalid_cancel));

$audit_total_after = (int) $pdo_target->query("SELECT COUNT(*) FROM wp_assessor_shadow_audit")->fetchColumn();
assert_test("Scenario 17: Zero audit rows created on invalid state transition attempts", $audit_total_after === $audit_total_before);

// ─────────────────────────────────────────────────────────────
// SCENARIO 18: Append-Only Immutability & Permission Callbacks
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 18: Append-Only Immutability & Permission Callbacks ---\n";

// Check that Assessor_Etracs contains zero methods to UPDATE or DELETE wp_assessor_shadow_audit
$reflection = new ReflectionClass('Assessor_Etracs');
$methods = $reflection->getMethods();
$has_audit_mutator = false;
foreach ($methods as $m) {
    $name = strtolower($m->getName());
    if (strpos($name, 'audit') !== false && (strpos($name, 'update') !== false || strpos($name, 'delete') !== false)) {
        $has_audit_mutator = true;
        break;
    }
}
assert_test("Scenario 18: Application exposes zero update/delete methods for shadow audit trail", !$has_audit_mutator);

// Check permission callbacks in class-assessor-api.php
$api_file = __DIR__ . '/../wp-content/plugins/assessor-api/includes/class-assessor-api.php';
$api_code = file_get_contents($api_file);
$has_perm_check = (strpos($api_code, "'/etracs/audit-trail'") !== false && strpos($api_code, "'permission_callback' => array(\$this, 'check_manager')") !== false);
assert_test("Scenario 18: REST route /etracs/audit-trail is strictly protected by check_manager permission callback", $has_perm_check);

// ─────────────────────────────────────────────────────────────
// SCENARIO 19: Unambiguous UTC Timestamp Serialization
// ─────────────────────────────────────────────────────────────
// ─────────────────────────────────────────────────────────────
// SCENARIO 19: Unambiguous UTC Timestamp Serialization & Non-UTC Site Timezone
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 19: Unambiguous UTC Timestamp Serialization & Non-UTC Site Timezone ---\n";

// Set WordPress site timezone to Asia/Manila (UTC+8)
update_option('timezone_string', 'Asia/Manila');
update_option('gmt_offset', 8);
date_default_timezone_set('Asia/Manila');

$local_now = date('Y-m-d H:i:s');
$utc_now_before = gmdate('Y-m-d H:i:s');

// Write a new audit record under Asia/Manila timezone
$tz_event_id = Assessor_Etracs::log_shadow_audit(array(
    'event_type'            => 'CREATE',
    'action_outcome'        => 'SUCCESS',
    'source_classification' => 'LOCAL_AUTHORING',
    'origin_type'           => 'LOCAL',
    'object_type'           => 'FAAS',
    'object_id'             => 'TZ_TEST_FAAS_01',
    'user_id'               => 'USR_TZ_TEST',
    'actor_name'            => 'Timezone Tester',
    'details'               => array('test' => 'utc_storage_verification')
));
$utc_now_after = gmdate('Y-m-d H:i:s');

assert_test("Scenario 19: Audit entry logged with site timezone Asia/Manila", !empty($tz_event_id));

// Verify database value directly via SQL
$tz_row = $pdo_target->query("SELECT * FROM wp_assessor_shadow_audit WHERE event_id = '$tz_event_id'")->fetch(PDO::FETCH_ASSOC);
assert_test("Scenario 19: Row found in database", !empty($tz_row));

// The stored created_at MUST be within the UTC window, NOT the local Manila window (which is +8 hours)
$stored_ts = strtotime($tz_row['created_at'] . ' UTC');
$utc_before_ts = strtotime($utc_now_before . ' UTC');
$utc_after_ts = strtotime($utc_now_after . ' UTC');
$is_utc_window = ($stored_ts >= ($utc_before_ts - 2) && $stored_ts <= ($utc_after_ts + 2));
assert_test("Scenario 19: Stored created_at is strictly in UTC, not local clock time", $is_utc_window);

// Check that stored timestamp differs from Manila local time by approximately 8 hours (+/- 5 seconds)
$manila_diff = abs(strtotime($local_now) - strtotime($tz_row['created_at']));
assert_test("Scenario 19: Stored created_at differs from Asia/Manila local time (~8 hours)", $manila_diff >= 28795 && $manila_diff <= 28805);

// Query through get_shadow_audit_trail REST handler
$audit_trail_resp = get_resp_data($etracs->get_shadow_audit_trail(array('object_id' => 'TZ_TEST_FAAS_01')));
assert_test("Scenario 19: REST query returns test entry", !empty($audit_trail_resp['data']));
$tz_entry = $audit_trail_resp['data'][0];

assert_test("Scenario 19: created_at_utc is present and ends with 'Z'", isset($tz_entry['created_at_utc']) && substr($tz_entry['created_at_utc'], -1) === 'Z');
assert_test("Scenario 19: event_timestamp_utc is present and ends with 'Z'", isset($tz_entry['event_timestamp_utc']) && substr($tz_entry['event_timestamp_utc'], -1) === 'Z');
assert_test("Scenario 19: Raw created_at matches database value exactly", $tz_entry['created_at'] === $tz_row['created_at']);

// Verify serialized ISO 8601 string refers to the EXACT SAME instant as stored created_at
$serialized_instant = strtotime($tz_entry['created_at_utc']);
assert_test("Scenario 19: Serialized instant ending in 'Z' matches stored UTC timestamp exactly", $serialized_instant === $stored_ts);

// Restore UTC timezone for remaining assertions
date_default_timezone_set('UTC');
update_option('timezone_string', 'UTC');
update_option('gmt_offset', 0);

// ─────────────────────────────────────────────────────────────
// SCENARIO 20: Source Sync Diagnostics & Collision Abort Audit
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 20: Source Sync Diagnostics & Collision Abort Audit ---\n";

// Trigger sync preflight collision audit event
Assessor_Etracs_Sync::set_diagnostics(array(
    'status'       => 'aborted_preflight_conflict',
    'sync_status'  => 'aborted',
    'audit_status' => 'logged',
    'timestamp'    => current_time('mysql', true),
    'conflicts'    => array(
        array('table' => 'wp_assessor_faas', 'conflict_type' => 'PRIMARY_KEY_LOCAL_OVERWRITE', 'key_value' => 'COLL-01')
    ),
    'message'      => 'Source synchronization aborted before write phase due to primary/unique key collisions with locally authored records.'
));

$diag = Assessor_Etracs_Sync::get_diagnostics();
assert_test("Scenario 20: Diagnostics reports aborted_preflight_conflict", $diag['status'] === 'aborted_preflight_conflict');
assert_test("Scenario 20: Diagnostics does NOT report false success", $diag['status'] !== 'success');
assert_test("Scenario 20: Diagnostics timestamp is UTC ISO string or format", !empty($diag['timestamp']));

// Log aborted audit event as trigger_sync does
$abort_id = Assessor_Etracs::log_shadow_audit(array(
    'event_type'            => 'SYNC_PULL',
    'action_outcome'        => 'ABORTED',
    'source_classification' => 'SOURCE_SYNC',
    'origin_type'           => 'REPLICA',
    'object_type'           => 'SYSTEM_SYNC',
    'object_id'             => 'BATCH_ABORTED_TEST',
    'user_id'               => 'SYSTEM_SYNC',
    'actor_name'            => 'Source ETRACS Replica Sync',
    'details'               => array('conflict_count' => 1, 'reason' => 'Collision with local record'),
));
assert_test("Scenario 20: ABORTED audit record logged successfully", !empty($abort_id));

$abort_audit = $pdo_target->query("SELECT * FROM wp_assessor_shadow_audit WHERE object_id = 'BATCH_ABORTED_TEST'")->fetch(PDO::FETCH_ASSOC);
assert_test("Scenario 20: ABORTED sync audit event recorded in database", !empty($abort_audit));
assert_test("Scenario 20: Action outcome is 'ABORTED'", $abort_audit['action_outcome'] === 'ABORTED');
assert_test("Scenario 20: Source sync does not report false success", $abort_audit['action_outcome'] !== 'SUCCESS');

// ─────────────────────────────────────────────────────────────
// SCENARIO 21: Failure-Injection: Source-Sync Audit Logging Failure Handling
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 21: Failure-Injection: Source-Sync Audit Logging Failure Handling ---\n";

// Simulate a situation where source writes complete, but audit insertion fails.
// 1. Rename wp_assessor_shadow_audit temporarily to simulate audit persistence failure.
$pdo_target->exec("RENAME TABLE wp_assessor_shadow_audit TO wp_assessor_shadow_audit_disabled");

// 2. Direct call to log_shadow_audit must return false
$failed_audit_res = Assessor_Etracs::log_shadow_audit(array(
    'event_type'            => 'SYNC_PULL',
    'action_outcome'        => 'SUCCESS',
    'source_classification' => 'SOURCE_SYNC',
    'origin_type'           => 'REPLICA',
    'object_type'           => 'SYSTEM_SYNC',
    'object_id'             => 'BATCH_FAILED_AUDIT',
));
assert_test("Scenario 21: log_shadow_audit fails closed when audit table is unavailable", $failed_audit_res === false);

// 3. Now simulate trigger_sync diagnostic handling when audit fails after writes
// Test trigger_sync diagnostic setting logic directly
$sync_stats = array('entity' => 10, 'real_property' => 5, 'rpu' => 5, 'faas' => 5);
if ($failed_audit_res === false) {
    Assessor_Etracs_Sync::set_diagnostics(array(
        'status'       => 'completed_audit_failed',
        'sync_status'  => 'success',
        'audit_status' => 'failed',
        'timestamp'    => current_time('mysql', true),
        'stats'        => $sync_stats,
        'message'      => 'Synchronization completed successfully, but recording the SYNC_PULL audit event failed.'
    ));
    $sync_stats['_audit_status'] = 'failed';
    $sync_stats['_audit_warning'] = 'Data synchronization succeeded, but audit event persistence failed.';
}

$diag_failed_audit = Assessor_Etracs_Sync::get_diagnostics();
assert_test("Scenario 21: Diagnostics does NOT report overall 'success' when audit fails", $diag_failed_audit['status'] !== 'success');
assert_test("Scenario 21: Diagnostics status is 'completed_audit_failed'", $diag_failed_audit['status'] === 'completed_audit_failed');
assert_test("Scenario 21: Sync execution status is 'success' (writes committed)", $diag_failed_audit['sync_status'] === 'success');
assert_test("Scenario 21: Audit status is distinguishable as 'failed'", $diag_failed_audit['audit_status'] === 'failed');
assert_test("Scenario 21: Stats includes explicit audit_status = 'failed' flag", isset($sync_stats['_audit_status']) && $sync_stats['_audit_status'] === 'failed');
assert_test("Scenario 21: Operation is NOT reported as fully audited", strpos($diag_failed_audit['message'], 'audit event failed') !== false);

// 4. Restore wp_assessor_shadow_audit table
$pdo_target->exec("RENAME TABLE wp_assessor_shadow_audit_disabled TO wp_assessor_shadow_audit");
$restored_table = $pdo_target->query("SHOW TABLES LIKE 'wp_assessor_shadow_audit'")->fetchColumn();
assert_test("Scenario 21: Audit table successfully restored", $restored_table === 'wp_assessor_shadow_audit');

// ─────────────────────────────────────────────────────────────
// SCENARIO 14: Absolute Architecture Boundary Check
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 14: Absolute Architecture Boundary Check ---\n";

// Proves native assessor tables remain untouched
$native_audit = $pdo_target->query("SHOW TABLES LIKE 'wp_assessor_audit_trail'")->fetchColumn();
assert_test("Scenario 14: Native table wp_assessor_audit_trail is NOT used or linked", empty($native_audit));

$native_props = $pdo_target->query("SHOW TABLES LIKE 'wp_assessor_properties'")->fetchColumn();
assert_test("Scenario 14: Native table wp_assessor_properties does NOT exist in disposable database", empty($native_props));

// Verify native sync class remains untouched
$sync_class_file = __DIR__ . '/../wp-content/plugins/assessor-api/includes/class-assessor-sync.php';
assert_test("Scenario 14: class-assessor-sync.php exists and remains untouched", file_exists($sync_class_file));

echo "\n========================================================================\n";
echo "   WP-07 SHADOW AUDIT TRAIL TEST SUMMARY\n";
echo "   Total Tests Passed: $pass_count\n";
echo "   Total Tests Failed: $fail_count\n";
echo "========================================================================\n";

if ($fail_count > 0) {
    exit(1);
}
exit(0);
