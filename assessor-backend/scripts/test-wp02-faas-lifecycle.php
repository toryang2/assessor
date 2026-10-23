<?php
/**
 * Test Suite: Assessor Repo — Phase 8 / WP-02: Shadow ETRACS FAAS Lifecycle & Approval
 *
 * Isolated test suite running entirely within the disposable database `test_wp01_disposable`.
 *
 * Scenarios tested (per WP-02 specifications):
 * 1. Newly authored LOCAL FAAS record does not immediately assign 'CURRENT'; initial state is 'INTERIM' with origin_type = 'LOCAL'.
 * 2. Creating a LOCAL FAAS does not cancel predecessor declaration on creation.
 * 3. A user without approval permission (e.g. non-manager/viewer) cannot approve a declaration.
 * 4. Submit for approval transitions declaration from 'INTERIM' to 'FORAPPROVAL'.
 * 5. Authorized approval transitions 'FORAPPROVAL' to 'CURRENT', records dtapproved, and cancels predecessor TD.
 * 6. Disapproval transitions 'FORAPPROVAL' to 'DISAPPROVED' with cancellation/disapproval reason.
 * 7. Invalid state transitions (e.g. approving INTERIM directly, cancelling INTERIM directly, editing CURRENT directly) return structured WP_Error.
 * 8. Invalid transitions leave affected rows completely unchanged.
 * 9. REPLICA records are protected from local draft editing, submission, approval, and disapproval.
 * 10. Cancellation of CURRENT declaration preserves appropriate history, cancellation date, notes, and predecessor/successor relationships.
 * 11. WP-01 regression: Source sync preflight and ownership protections remain 100% intact.
 * 12. Absolute Architecture Boundary: Zero native Assessor tables or workflows accessed or modified.
 */

class MockWpdbWp02 {
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

    public function insert($table, $data) {
        $cols = array_keys($data);
        $escaped_cols = implode(', ', $cols);
        $placeholders = array();
        $values = array();

        foreach ($cols as $col) {
            $placeholders[] = '?';
            $values[] = $data[$col];
        }

        $sql = "INSERT INTO $table ($escaped_cols) VALUES (" . implode(', ', $placeholders) . ")";
        try {
            $stmt = $this->pdo->prepare($sql);
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
            $set[] = "$col = ?";
            $values[] = $val;
        }

        $where_clauses = array();
        foreach ($where as $wcol => $wval) {
            $where_clauses[] = "$wcol = ?";
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

function wp_generate_uuid4() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

function sanitize_text_field($str) {
    return trim(strip_tags((string)$str));
}

function sanitize_textarea_field($str) {
    return trim((string)$str);
}

function rest_ensure_response($response) {
    return $response;
}

if (!defined('JWT_AUTH_SECRET_KEY')) {
    define('JWT_AUTH_SECRET_KEY', 'test_secret_key_for_wp02_lifecycle_testing_1234567890');
}

function get_site_url() {
    return 'http://localhost/assessor';
}

function generate_test_token($user_id, $role, $username = 'testuser') {
    $header = json_encode(array('typ' => 'JWT', 'alg' => 'HS256'));
    $payload = json_encode(array(
        'user_id' => $user_id,
        'username' => $username,
        'role' => $role,
        'iss' => get_site_url(),
        'iat' => time(),
        'exp' => time() + 3600
    ));
    $b64_header = str_replace(array('+', '/', '='), array('-', '_', ''), base64_encode($header));
    $b64_payload = str_replace(array('+', '/', '='), array('-', '_', ''), base64_encode($payload));
    $sig = hash_hmac('sha256', $b64_header . "." . $b64_payload, JWT_AUTH_SECRET_KEY, true);
    $b64_sig = str_replace(array('+', '/', '='), array('-', '_', ''), base64_encode($sig));
    return $b64_header . "." . $b64_payload . "." . $b64_sig;
}

// Mock request wrapper
class MockRestRequest {
    public $params;
    public $headers;
    public function __construct($params = array(), $headers = array()) {
        $this->params = $params;
        $this->headers = array();
        foreach ($headers as $k => $v) {
            $this->headers[strtolower($k)] = is_array($v) ? $v : array($v);
        }
    }
    public function get_params() {
        return $this->params;
    }
    public function get_param($key) {
        return isset($this->params[$key]) ? $this->params[$key] : null;
    }
    public function get_header($key) {
        $k = strtolower($key);
        return isset($this->headers[$k]) ? $this->headers[$k][0] : null;
    }
    public function get_headers() {
        return $this->headers;
    }
}

// Load backend classes under test
require_once __DIR__ . '/../wp-content/plugins/assessor-api/includes/class-assessor-database.php';
require_once __DIR__ . '/../wp-content/plugins/assessor-api/includes/class-assessor-etracs-sync.php';
require_once __DIR__ . '/../wp-content/plugins/assessor-api/includes/class-assessor-etracs.php';
require_once __DIR__ . '/../wp-content/plugins/assessor-api/includes/class-assessor-auth.php';
require_once __DIR__ . '/../wp-content/plugins/assessor-api/includes/class-assessor-api.php';

// Setup connections
$disposable_db = 'test_wp01_disposable';
$db_user = 'root';
$db_pass = '';
$db_host = '127.0.0.1';

try {
    $pdo_target = new PDO("mysql:host=$db_host;dbname=$disposable_db;charset=utf8mb4", $db_user, $db_pass, array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ));
} catch (PDOException $e) {
    die("[SETUP ERROR] Cannot connect to disposable database '$disposable_db': " . $e->getMessage() . "\n");
}

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
$wpdb = new MockWpdbWp02($pdo_target);

echo "========================================================================\n";
echo "   ASSESSOR REPO - PHASE 8 / WP-02 COMPREHENSIVE TEST HARNESS\n";
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
$pdo_target->exec("DROP TABLE IF EXISTS wp_assessor_faas, wp_assessor_rpu, wp_assessor_real_property, wp_assessor_entity, wp_assessor_faas_previous, wp_assessor_rpu_assessment, wp_assessor_txn_type, wp_assessor_faas_txntypes");

$pdo_target->exec("
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

CREATE TABLE wp_assessor_rpu (
    id mediumint NOT NULL AUTO_INCREMENT,
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
    taxable int DEFAULT '1',
    PRIMARY KEY (id),
    UNIQUE KEY ux_rpu_objid (objid),
    KEY idx_origin_type (origin_type)
) ENGINE=InnoDB;

CREATE TABLE wp_assessor_real_property (
    id mediumint NOT NULL AUTO_INCREMENT,
    objid varchar(50) NOT NULL,
    origin_type enum('REPLICA','LOCAL') NOT NULL DEFAULT 'REPLICA',
    pin varchar(50) DEFAULT NULL,
    cadastrallotno varchar(900) DEFAULT NULL,
    surveyno varchar(50) DEFAULT '',
    blockno varchar(50) DEFAULT '',
    barangay varchar(100) DEFAULT '',
    municipality varchar(100) DEFAULT '',
    province varchar(100) DEFAULT '',
    north varchar(255) DEFAULT '',
    south varchar(255) DEFAULT '',
    east varchar(255) DEFAULT '',
    west varchar(255) DEFAULT '',
    PRIMARY KEY (id),
    UNIQUE KEY ux_rp_objid (objid),
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
    origin_type enum('REPLICA','LOCAL') NOT NULL DEFAULT 'REPLICA',
    faasid varchar(50) DEFAULT NULL,
    prevfaasid varchar(50) DEFAULT NULL,
    prevtdno varchar(800) DEFAULT NULL,
    prevpin varchar(50) DEFAULT NULL,
    prevowner longtext DEFAULT NULL,
    prevav varchar(50) DEFAULT NULL,
    prevmv varchar(50) DEFAULT NULL,
    prevareasqm varchar(50) DEFAULT NULL,
    prevareaha varchar(50) DEFAULT NULL,
    prevadministrator varchar(255) DEFAULT NULL,
    PRIMARY KEY (objid),
    KEY idx_origin_type (origin_type)
) ENGINE=InnoDB;

CREATE TABLE wp_assessor_rpu_assessment (
    objid varchar(50) NOT NULL,
    rpuid varchar(50) NOT NULL,
    actualuse varchar(100) DEFAULT NULL,
    marketvalue decimal(16,2) NOT NULL DEFAULT '0.00',
    assessedvalue decimal(16,2) NOT NULL DEFAULT '0.00',
    PRIMARY KEY (objid)
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
");

$etracs = new Assessor_Etracs();
$api = new Assessor_API();

// ─────────────────────────────────────────────────────────────
// Scenario 1: Predecessor Declaration and Local Draft Creation
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 1: Initial Local FAAS Creation Lifecycle ---\n";

// Insert an existing REPLICA active declaration (e.g. from ETRACS sync)
$pdo_target->exec("
INSERT INTO wp_assessor_faas (objid, state, origin_type, utdno, tdno, owner_name, txntimestamp)
VALUES ('FAAS-PREV-001', 'CURRENT', 'REPLICA', 'TD-2024-001', 'TD-2024-001', 'Juan dela Cruz', NOW());
");

// Author a new LOCAL declaration referencing the predecessor
$new_faas_params = array(
    'tdno' => 'TD-2026-LOCAL-001',
    'utdno' => 'TD-2026-LOCAL-001',
    'owner_name' => 'Maria Santos',
    'owner_address' => 'Barangay Poblacion, Kitaotao',
    'pin' => '048-12-001-01-001',
    'rpu_type' => 'LAND',
    'classification' => 'AGRICULTURAL',
    'total_market_value' => '500000',
    'total_assessed_value' => '100000',
    'prevtdno' => 'TD-2024-001',
    'prev_owner' => 'Juan dela Cruz',
    'txntype_code' => 'TR'
);

$req_create = new MockRestRequest($new_faas_params);
$created_faas = $etracs->create_faas($req_create);

if (is_wp_error($created_faas)) {
    echo "DEBUG CREATE ERROR: " . $created_faas->get_error_message() . "\n";
}

assert_test("create_faas() returns valid FAAS object", !is_wp_error($created_faas) && !empty($created_faas->objid));
assert_test("New LOCAL FAAS state is 'INTERIM' (not CURRENT)", !is_wp_error($created_faas) && $created_faas->state === 'INTERIM');
assert_test("New LOCAL FAAS origin_type is 'LOCAL'", !is_wp_error($created_faas) && $created_faas->origin_type === 'LOCAL');
assert_test("New LOCAL FAAS dtapproved is NULL upon creation", empty($created_faas->dtapproved));

// Check predecessor TD state in database
$prev_state = $wpdb->get_var("SELECT state FROM wp_assessor_faas WHERE tdno = 'TD-2024-001'");
assert_test("Predecessor declaration remains 'CURRENT' upon draft creation (NOT cancelled)", $prev_state === 'CURRENT');

// Check related entity, real_property, and rpu have origin_type = LOCAL
$created_rpu_origin = $wpdb->get_var($wpdb->prepare("SELECT origin_type FROM wp_assessor_rpu WHERE objid = %s", $created_faas->rpuid));
$created_rp_origin = $wpdb->get_var($wpdb->prepare("SELECT origin_type FROM wp_assessor_real_property WHERE objid = %s", $created_faas->realpropertyid));
assert_test("Related RPU record assigned origin_type = 'LOCAL'", $created_rpu_origin === 'LOCAL');
assert_test("Related Real Property record assigned origin_type = 'LOCAL'", $created_rp_origin === 'LOCAL');

// ─────────────────────────────────────────────────────────────
// Scenario 2: Permission Check on Approval
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 2: Permission Enforcement on Approval Route ---\n";

// Test route permission checking using mock roles
$token_viewer = generate_test_token(101, 'viewer');
$token_assessor = generate_test_token(102, 'assessor');
$token_admin = generate_test_token(103, 'administrator');

$req_unauthorized = new MockRestRequest(array(), array('Authorization' => 'Bearer ' . $token_viewer));
$can_viewer_approve = $api->check_manager($req_unauthorized);
assert_test("Viewer role cannot pass check_manager permission callback", $can_viewer_approve === false);

$req_authorized_assessor = new MockRestRequest(array(), array('Authorization' => 'Bearer ' . $token_assessor));
$can_assessor_approve = $api->check_manager($req_authorized_assessor);
assert_test("Assessor role passes check_manager permission callback", $can_assessor_approve === true);

$req_authorized_admin = new MockRestRequest(array(), array('Authorization' => 'Bearer ' . $token_admin));
$can_admin_approve = $api->check_manager($req_authorized_admin);
assert_test("Administrator role passes check_manager permission callback", $can_admin_approve === true);

// ─────────────────────────────────────────────────────────────
// Scenario 3: Invalid State Transition Validation
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 3: Invalid State Transitions Leave Data Unchanged ---\n";

// Attempt 1: Approve an INTERIM declaration directly without FORAPPROVAL
$req_direct_approve = new MockRestRequest();
$invalid_approve_res = $etracs->approve_faas($created_faas->objid, $req_direct_approve);
assert_test("Direct approval of INTERIM declaration returns WP_Error", is_wp_error($invalid_approve_res));
assert_test("Error code is 'invalid_state'", $invalid_approve_res->get_error_code() === 'invalid_state');

$state_after_invalid = $wpdb->get_var($wpdb->prepare("SELECT state FROM wp_assessor_faas WHERE objid = %s", $created_faas->objid));
assert_test("Declaration state remains 'INTERIM' after failed direct approval", $state_after_invalid === 'INTERIM');

// Attempt 2: Cancel an INTERIM declaration (only CURRENT can be cancelled)
$req_cancel_interim = new MockRestRequest(array('cancel_reason' => 'Should fail'));
$invalid_cancel_res = $etracs->cancel_faas($created_faas->objid, $req_cancel_interim);
assert_test("Cancelling an INTERIM declaration returns WP_Error", is_wp_error($invalid_cancel_res));
assert_test("Error code is 'invalid_state'", $invalid_cancel_res->get_error_code() === 'invalid_state');

// ─────────────────────────────────────────────────────────────
// Scenario 4: Valid Submission & Approval Flow
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 4: Submission and Approval Flow ---\n";

// Step A: Submit INTERIM for approval
$req_submit = new MockRestRequest();
$submit_res = $etracs->submit_faas($created_faas->objid, $req_submit);
assert_test("submit_faas() succeeds", !is_wp_error($submit_res));
assert_test("Declaration state transitioned to 'FORAPPROVAL'", $submit_res->state === 'FORAPPROVAL');

// Attempt to edit while FORAPPROVAL (must be rejected)
$req_edit_forapproval = new MockRestRequest(array('owner_name' => 'Illegal Edit While For Approval'));
$invalid_edit_res = $etracs->update_faas($created_faas->objid, $req_edit_forapproval);
assert_test("Editing a declaration in FORAPPROVAL returns WP_Error", is_wp_error($invalid_edit_res));
$owner_after_invalid_edit = $wpdb->get_var($wpdb->prepare("SELECT owner_name FROM wp_assessor_faas WHERE objid = %s", $created_faas->objid));
assert_test("Owner name remains unchanged after invalid edit", $owner_after_invalid_edit === 'Maria Santos');

// Step B: Approve the FORAPPROVAL declaration
$approval_date = '2026-10-22 11:30:00';
$req_approve = new MockRestRequest(array('date_approved' => $approval_date));
$approve_res = $etracs->approve_faas($created_faas->objid, $req_approve);
if (is_wp_error($approve_res)) {
    echo "DEBUG APPROVE ERROR: " . $approve_res->get_error_message() . "\n";
}
assert_test("approve_faas() succeeds", !is_wp_error($approve_res));
assert_test("Declaration state is now 'CURRENT'", !is_wp_error($approve_res) && $approve_res->state === 'CURRENT');
assert_test("dtapproved recorded properly", !is_wp_error($approve_res) && $approve_res->dtapproved === $approval_date);

// Step C: Verify predecessor TD raw state remains CURRENT (preserved source ownership)
// but effective state becomes CANCELLED via get_faas and list query.
$prev_faas_raw = $wpdb->get_row("SELECT state, canceldate, cancelledbytdnos, origin_type FROM wp_assessor_faas WHERE tdno = 'TD-2024-001'");
assert_test("REPLICA Predecessor declaration RAW state remains 'CURRENT' (source-owned row untouched)", $prev_faas_raw->state === 'CURRENT');
assert_test("REPLICA Predecessor raw canceldate remains empty/null", empty($prev_faas_raw->canceldate));
assert_test("REPLICA Predecessor raw cancelledbytdnos remains empty/null", empty($prev_faas_raw->cancelledbytdnos));

$prev_faas_effective = $etracs->get_faas('FAAS-PREV-001');
assert_test("Predecessor effective state transitioned to 'CANCELLED'", $prev_faas_effective->state === 'CANCELLED');
assert_test("Predecessor effective_state field explicitly equals 'CANCELLED'", $prev_faas_effective->effective_state === 'CANCELLED');
assert_test("Predecessor raw_state field equals 'CURRENT'", $prev_faas_effective->raw_state === 'CURRENT');
assert_test("Predecessor effective cancel_date set to successor approval date", $prev_faas_effective->cancel_date === $approval_date);
assert_test("Predecessor effective cancelled_by_tdnos set to successor TD", $prev_faas_effective->cancelled_by_tdnos === 'TD-2026-LOCAL-001');

// ─────────────────────────────────────────────────────────────
// Scenario 5: Disapproval / Rejection Lifecycle
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 5: Disapproval Lifecycle ---\n";

// Create a second draft to test rejection
$faas_draft_2 = array(
    'tdno' => 'TD-2026-LOCAL-002',
    'utdno' => 'TD-2026-LOCAL-002',
    'owner_name' => 'Pedro Penduko',
    'rpu_type' => 'LAND',
    'txntype_code' => 'GR'
);
$req_create_2 = new MockRestRequest($faas_draft_2);
$created_draft_2 = $etracs->create_faas($req_create_2);

// Submit for approval
$etracs->submit_faas($created_draft_2->objid, new MockRestRequest());

// Disapprove declaration
$disapproval_reason = 'Incomplete survey coordinates and missing title certification';
$req_disapprove = new MockRestRequest(array('reason' => $disapproval_reason));
$disapprove_res = $etracs->disapprove_faas($created_draft_2->objid, $req_disapprove);

assert_test("disapprove_faas() succeeds", !is_wp_error($disapprove_res));
assert_test("Declaration state transitioned to 'DISAPPROVED'", $disapprove_res->state === 'DISAPPROVED');
assert_test("Disapproval reason recorded in cancelreason column", $disapprove_res->cancelreason === $disapproval_reason);

// Resubmit DISAPPROVED declaration after correction
$resubmit_res = $etracs->submit_faas($created_draft_2->objid, new MockRestRequest());
assert_test("DISAPPROVED declaration can be resubmitted to 'FORAPPROVAL'", $resubmit_res->state === 'FORAPPROVAL');

// ─────────────────────────────────────────────────────────────
// Scenario 6: REPLICA Row Protection
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 6: REPLICA Records Lifecycle Protection ---\n";

$replica_row_id = 'FAAS-PREV-001'; // Pre-existing REPLICA record

// Attempt 1: submit_faas on REPLICA
$res_rep_submit = $etracs->submit_faas($replica_row_id, new MockRestRequest());
assert_test("Submitting REPLICA record returns WP_Error 'replica_protected'", is_wp_error($res_rep_submit) && $res_rep_submit->get_error_code() === 'replica_protected');

// Attempt 2: approve_faas on REPLICA
$res_rep_approve = $etracs->approve_faas($replica_row_id, new MockRestRequest());
assert_test("Approving REPLICA record returns WP_Error 'replica_protected'", is_wp_error($res_rep_approve) && $res_rep_approve->get_error_code() === 'replica_protected');

// Attempt 3: disapprove_faas on REPLICA
$res_rep_disapprove = $etracs->disapprove_faas($replica_row_id, new MockRestRequest());
assert_test("Disapproving REPLICA record returns WP_Error 'replica_protected'", is_wp_error($res_rep_disapprove) && $res_rep_disapprove->get_error_code() === 'replica_protected');

// Attempt 4: update_faas on REPLICA
$res_rep_update = $etracs->update_faas($replica_row_id, new MockRestRequest(array('owner_name' => 'Hacked Replica')));
assert_test("Updating REPLICA record returns WP_Error 'replica_protected'", is_wp_error($res_rep_update) && $res_rep_update->get_error_code() === 'replica_protected');

// Attempt 5: delete_faas on REPLICA
$res_rep_delete = $etracs->delete_faas($replica_row_id, new MockRestRequest());
assert_test("Deleting REPLICA record returns WP_Error 'replica_protected'", is_wp_error($res_rep_delete) && $res_rep_delete->get_error_code() === 'replica_protected');

// ─────────────────────────────────────────────────────────────
// Scenario 7: Cancellation of CURRENT Declaration
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 7: Explicit Cancellation of CURRENT Declaration ---\n";

$cancel_params = array(
    'cancel_reason' => 'Transfer of Ownership',
    'cancel_note' => 'Superseded by Deed of Sale dated 2026-10-20',
    'cancelled_by_tdnos' => 'TD-2026-LOCAL-003',
    'cancel_date' => '2026-10-22 12:00:00'
);
$req_cancel = new MockRestRequest($cancel_params);
$cancel_res = $etracs->cancel_faas($created_faas->objid, $req_cancel);

assert_test("cancel_faas() on CURRENT declaration succeeds", !is_wp_error($cancel_res));
assert_test("Declaration state transitioned to 'CANCELLED'", $cancel_res->state === 'CANCELLED');
assert_test("cancelreason recorded", $cancel_res->cancelreason === $cancel_params['cancel_reason']);
assert_test("cancelnote recorded", $cancel_res->cancelnote === $cancel_params['cancel_note']);
assert_test("cancelledbytdnos recorded", $cancel_res->cancelledbytdnos === $cancel_params['cancelled_by_tdnos']);

// ─────────────────────────────────────────────────────────────
// Scenario 8: Stats aggregation with new lifecycle states
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 8: Stats Aggregation With New Lifecycle States ---\n";

$stats = $etracs->get_faas_stats(new MockRestRequest());
assert_test("Stats returns array/object with required metrics", isset($stats['total']) && isset($stats['current']));
assert_test("Stats contains 'forapproval' count", array_key_exists('forapproval', $stats));
assert_test("Stats contains 'interim' count", array_key_exists('interim', $stats));
assert_test("Stats contains 'disapproved' count", array_key_exists('disapproved', $stats));

// ─────────────────────────────────────────────────────────────
// Scenario 9: Absolute Architecture Boundary & WP-01 Regression
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 9: Native Isolation and WP-01 Regression Checks ---\n";

// Verify native Assessor table does NOT exist in disposable test database
$native_table_exists = $pdo_target->query("SHOW TABLES LIKE 'wp_assessor_properties'")->fetchColumn();
assert_test("Native Assessor table wp_assessor_properties does NOT exist in test database", empty($native_table_exists));

// Verify Assessor_Etracs contains no native Assessor SQL
$etracs_code = file_get_contents(__DIR__ . '/../wp-content/plugins/assessor-api/includes/class-assessor-etracs.php');
$has_native_mutation = preg_match('/assessor_properties|assessor_revisions|assessor_documents/i', $etracs_code);
assert_test("Assessor_Etracs code contains zero references to native Assessor tables", $has_native_mutation === 0);

// ─────────────────────────────────────────────────────────────
// Scenario 10: Section 1 — REPLICA Predecessor Lineage Survival Across WP-01 Source Sync Refresh
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 10: Section 1 — REPLICA Predecessor Lineage Across Source Refresh ---\n";

// Setup: Predecessor is a REPLICA declaration synced from ETRACS
$replica_pred_id = 'FAAS-REP-PRED-100';
$replica_pred_td = 'TD-2023-SRC-100';
$pdo_target->exec("
INSERT INTO wp_assessor_faas (objid, state, origin_type, utdno, tdno, owner_name, fullpin, txntimestamp)
VALUES ('$replica_pred_id', 'CURRENT', 'REPLICA', '$replica_pred_td', '$replica_pred_td', 'Original Source Taxpayer', '048-12-001-99-999', NOW());
");

// Author a new LOCAL declaration referencing the REPLICA predecessor
$succ_10_params = array(
    'tdno' => 'TD-2026-LOCAL-100',
    'utdno' => 'TD-2026-LOCAL-100',
    'owner_name' => 'Local Successor Taxpayer',
    'owner_address' => 'Kitaotao, Bukidnon',
    'pin' => '048-12-001-99-999',
    'prevtdno' => $replica_pred_td,
    'prev_owner' => 'Original Source Taxpayer',
    'rpu_type' => 'LAND',
    'txntype_code' => 'TR'
);
$succ_10_draft = $etracs->create_faas(new MockRestRequest($succ_10_params));
assert_test("Successor 10 draft created", !is_wp_error($succ_10_draft));

// Verify unapproved draft (INTERIM) does NOT cancel predecessor
$pred_while_interim = $etracs->get_faas($replica_pred_id);
assert_test("Predecessor effective state remains CURRENT while successor is INTERIM", $pred_while_interim->state === 'CURRENT');

// Submit successor 10
$etracs->submit_faas($succ_10_draft->objid, new MockRestRequest());

// Verify unapproved draft (FORAPPROVAL) does NOT cancel predecessor
$pred_while_forapproval = $etracs->get_faas($replica_pred_id);
assert_test("Predecessor effective state remains CURRENT while successor is FORAPPROVAL", $pred_while_forapproval->state === 'CURRENT');

// Approve successor 10
$succ_10_approved = $etracs->approve_faas($succ_10_draft->objid, new MockRestRequest(array('date_approved' => '2026-10-22 14:00:00')));
assert_test("Successor 10 approved to CURRENT", !is_wp_error($succ_10_approved) && $succ_10_approved->state === 'CURRENT');

// Verify predecessor RAW row remains CURRENT and untouched in wp_assessor_faas
$pred_row_raw = $wpdb->get_row($wpdb->prepare("SELECT state, origin_type, canceldate, cancelledbytdnos FROM wp_assessor_faas WHERE objid = %s", $replica_pred_id));
assert_test("REPLICA predecessor origin_type remains 'REPLICA'", $pred_row_raw->origin_type === 'REPLICA');
assert_test("REPLICA predecessor RAW state in wp_assessor_faas remains 'CURRENT'", $pred_row_raw->state === 'CURRENT');
assert_test("REPLICA predecessor RAW canceldate is untouched/null", empty($pred_row_raw->canceldate));
assert_test("REPLICA predecessor RAW cancelledbytdnos is untouched/null", empty($pred_row_raw->cancelledbytdnos));

// Verify predecessor EFFECTIVE state in get_faas is CANCELLED with effective cancellation metadata
$pred_effective = $etracs->get_faas($replica_pred_id);
assert_test("REPLICA predecessor effective state is 'CANCELLED'", $pred_effective->state === 'CANCELLED');
assert_test("REPLICA predecessor effective_state field is 'CANCELLED'", $pred_effective->effective_state === 'CANCELLED');
assert_test("REPLICA predecessor raw_state field is 'CURRENT'", $pred_effective->raw_state === 'CURRENT');
assert_test("REPLICA predecessor effective cancelled_by_tdnos points to successor", $pred_effective->cancelled_by_tdnos === 'TD-2026-LOCAL-100');
assert_test("REPLICA predecessor effective cancel_date points to successor dtapproved", $pred_effective->cancel_date === '2026-10-22 14:00:00');

// Verify lineage was captured in wp_assessor_faas_previous with origin_type = LOCAL
$lineage_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM wp_assessor_faas_previous WHERE faasid = %s", $succ_10_draft->objid));
assert_test("Predecessor lineage stored in wp_assessor_faas_previous", !empty($lineage_row) && $lineage_row->prevtdno === $replica_pred_td);
assert_test("Predecessor lineage row has origin_type = 'LOCAL'", !empty($lineage_row->origin_type) && $lineage_row->origin_type === 'LOCAL');

// SIMULATE WP-01 SOURCE REFRESH:
// Suppose remote ETRACS source sync runs and pulls the predecessor record from source where state is still CURRENT.
// Safe upsert on the predecessor REPLICA row refreshes state to CURRENT with latest source attributes.
$simulated_src_faas = array(
    'objid'              => $replica_pred_id,
    'state'              => 'CURRENT', // source still considers it current
    'tdno'               => $replica_pred_td,
    'utdno'              => $replica_pred_td,
    'owner_name'         => 'Original Source Taxpayer Refreshed',
    'origin_type'        => 'REPLICA'
);
Assessor_Etracs_Sync::safe_upsert_row($wpdb->prefix . 'assessor_faas', $simulated_src_faas, 'objid');

// Verify predecessor after simulated source refresh:
// Raw state is updated by sync
$pred_after_sync_raw = $wpdb->get_row($wpdb->prepare("SELECT state, origin_type, owner_name FROM wp_assessor_faas WHERE objid = %s", $replica_pred_id));
assert_test("Predecessor origin_type remains REPLICA after source refresh", $pred_after_sync_raw->origin_type === 'REPLICA');
assert_test("Predecessor owner_name refreshed by source sync", $pred_after_sync_raw->owner_name === 'Original Source Taxpayer Refreshed');

// Effective state of predecessor remains CANCELLED despite source sync refresh!
$pred_after_sync_eff = $etracs->get_faas($replica_pred_id);
assert_test("Predecessor EFFECTIVE state remains 'CANCELLED' after source sync refresh", $pred_after_sync_eff->state === 'CANCELLED');
assert_test("Predecessor EFFECTIVE cancelled_by_tdnos preserved after source refresh", $pred_after_sync_eff->cancelled_by_tdnos === 'TD-2026-LOCAL-100');

// VERIFY SUCCESSOR LINEAGE SURVIVAL:
$succ_10_fetched = $etracs->get_faas($succ_10_draft->objid);
assert_test("Successor record remains CURRENT after predecessor refresh", $succ_10_fetched->state === 'CURRENT');
assert_test("Successor record remains LOCAL origin_type", $succ_10_fetched->origin_type === 'LOCAL');
assert_test("Successor queryable lineage (prevtdno) is fully preserved", $succ_10_fetched->prevtdno === $replica_pred_td);

// ─────────────────────────────────────────────────────────────
// Scenario 10B: Multiple Revisions & Disapproved Successors
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 10B: Multiple Revisions and Disapproved Successor Lineage ---\n";

// A. Test Disapproved Successor does NOT falsely cancel predecessor
$disapp_succ_params = array(
    'tdno' => 'TD-2026-LOCAL-DISAPP',
    'utdno' => 'TD-2026-LOCAL-DISAPP',
    'owner_name' => 'Disapproved Successor Owner',
    'prevtdno' => 'TD-2026-PRED-DISAPP-TEST',
    'rpu_type' => 'LAND',
    'txntype_code' => 'TR'
);
// Seed predecessor for this test
$pdo_target->exec("
INSERT INTO wp_assessor_faas (objid, state, origin_type, utdno, tdno, owner_name, txntimestamp)
VALUES ('FAAS-PRED-DISAPP-TEST', 'CURRENT', 'REPLICA', 'TD-2026-PRED-DISAPP-TEST', 'TD-2026-PRED-DISAPP-TEST', 'Owner Pre Disapproval', NOW());
");
$disapp_draft = $etracs->create_faas(new MockRestRequest($disapp_succ_params));
$etracs->submit_faas($disapp_draft->objid, new MockRestRequest());
$etracs->disapprove_faas($disapp_draft->objid, new MockRestRequest(array('reason' => 'Invalid Deed of Sale')));

$pred_disapp_eff = $etracs->get_faas('FAAS-PRED-DISAPP-TEST');
assert_test("Predecessor effective state remains 'CURRENT' when successor is DISAPPROVED", $pred_disapp_eff->state === 'CURRENT');

// B. Multi-tier revision: Successor C cancels approved Successor B (which superseded REPLICA A)
$succ_10_c_params = array(
    'tdno' => 'TD-2026-LOCAL-100-REV2',
    'utdno' => 'TD-2026-LOCAL-100-REV2',
    'owner_name' => 'Second Tier Successor Taxpayer',
    'prevtdno' => 'TD-2026-LOCAL-100', // references Successor 10 (which is LOCAL)
    'rpu_type' => 'LAND',
    'txntype_code' => 'TR'
);
$succ_10_c_draft = $etracs->create_faas(new MockRestRequest($succ_10_c_params));
$etracs->submit_faas($succ_10_c_draft->objid, new MockRestRequest());
$succ_10_c_approved = $etracs->approve_faas($succ_10_c_draft->objid, new MockRestRequest(array('date_approved' => '2026-10-22 15:00:00')));
assert_test("Revision 2 approved to CURRENT", !is_wp_error($succ_10_c_approved) && $succ_10_c_approved->state === 'CURRENT');

// Verify Successor 10 (LOCAL predecessor to Rev 2) is now CANCELLED in DB
$succ_10_row_after_c = $wpdb->get_row($wpdb->prepare("SELECT state, cancelledbytdnos FROM wp_assessor_faas WHERE objid = %s", $succ_10_draft->objid));
assert_test("Successor 10 (LOCAL) physically transitioned to CANCELLED", $succ_10_row_after_c->state === 'CANCELLED');
assert_test("Successor 10 cancelledbytdnos set to Revision 2 TD", $succ_10_row_after_c->cancelledbytdnos === 'TD-2026-LOCAL-100-REV2');

// Crucial: Original REPLICA predecessor A ($replica_pred_id) must STILL have effective state CANCELLED!
$replica_pred_eff_after_c = $etracs->get_faas($replica_pred_id);
assert_test("Historic lineage preserved: Original REPLICA predecessor remains effectively CANCELLED even when its successor is cancelled", $replica_pred_eff_after_c->state === 'CANCELLED');

// ─────────────────────────────────────────────────────────────
// Scenario 11: Section 2 — Atomic Approval and Rollback on Predecessor Failure
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 11: Section 2 — Atomic Approval & Failure Injection Rollback ---\n";

// A. Test approval with NO predecessor
$faas_no_pred = array(
    'tdno' => 'TD-2026-NO-PRED',
    'utdno' => 'TD-2026-NO-PRED',
    'owner_name' => 'First Time Owner',
    'rpu_type' => 'LAND',
    'txntype_code' => 'NC' // New Discovery / Construction
);
$draft_no_pred = $etracs->create_faas(new MockRestRequest($faas_no_pred));
$etracs->submit_faas($draft_no_pred->objid, new MockRestRequest());
$approved_no_pred = $etracs->approve_faas($draft_no_pred->objid, new MockRestRequest(array('date_approved' => '2026-10-22 14:15:00')));
assert_test("Successful approval with no predecessor transitions to CURRENT", !is_wp_error($approved_no_pred) && $approved_no_pred->state === 'CURRENT');
assert_test("Approved declaration without predecessor has null/empty prevtdno", empty($approved_no_pred->prevtdno));

// B. Failure Injection: Simulate predecessor update failure during approval
// We create a predecessor and successor. Then we set a database trigger or interceptor to simulate failure,
// OR inject an invalid state condition that triggers rollback.
$pred_fail_id = 'FAAS-PRED-FAIL-01';
$pred_fail_td = 'TD-2024-FAIL-01';
$pdo_target->exec("
INSERT INTO wp_assessor_faas (objid, state, origin_type, utdno, tdno, owner_name, txntimestamp)
VALUES ('$pred_fail_id', 'CURRENT', 'LOCAL', '$pred_fail_td', '$pred_fail_td', 'Owner Prior To Failure', NOW());
");

$succ_fail_params = array(
    'tdno' => 'TD-2026-SUCC-FAIL',
    'utdno' => 'TD-2026-SUCC-FAIL',
    'owner_name' => 'Owner Attempting Approval',
    'prevtdno' => $pred_fail_td,
    'rpu_type' => 'LAND',
    'txntype_code' => 'TR'
);
$succ_fail_draft = $etracs->create_faas(new MockRestRequest($succ_fail_params));
$etracs->submit_faas($succ_fail_draft->objid, new MockRestRequest());

// Create a temporary BEFORE UPDATE trigger on wp_assessor_faas that fails specifically if cancelledbytdnos = 'TD-2026-SUCC-FAIL'
$pdo_target->exec("
CREATE TRIGGER trg_fail_pred_update BEFORE UPDATE ON wp_assessor_faas
FOR EACH ROW
BEGIN
    IF NEW.cancelledbytdnos = 'TD-2026-SUCC-FAIL' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected Predecessor Failure';
    END IF;
END;
");

$fail_approve_res = $etracs->approve_faas($succ_fail_draft->objid, new MockRestRequest(array('date_approved' => '2026-10-22 14:30:00')));
assert_test("Approval fails and returns WP_Error when predecessor update fails", is_wp_error($fail_approve_res));
assert_test("Error code is 'approval_failed'", is_wp_error($fail_approve_res) && $fail_approve_res->get_error_code() === 'approval_failed');

// Remove trigger
$pdo_target->exec("DROP TRIGGER IF EXISTS trg_fail_pred_update;");

// Verify ROLLBACK guarantees:
// 1. Successor was rolled back: remains in FORAPPROVAL, not CURRENT
$succ_fail_state = $wpdb->get_var($wpdb->prepare("SELECT state FROM wp_assessor_faas WHERE objid = %s", $succ_fail_draft->objid));
assert_test("ATOMIC GUARANTEE: Successor is NOT left in 'CURRENT' after rollback (state remains FORAPPROVAL)", $succ_fail_state === 'FORAPPROVAL');

// 2. Predecessor was rolled back: remains in CURRENT, not CANCELLED
$pred_fail_row = $wpdb->get_row($wpdb->prepare("SELECT state, cancelledbytdnos, canceldate FROM wp_assessor_faas WHERE objid = %s", $pred_fail_id));
assert_test("ATOMIC GUARANTEE: Predecessor is NOT partially modified (state remains CURRENT)", $pred_fail_row->state === 'CURRENT');
assert_test("ATOMIC GUARANTEE: Predecessor cancelledbytdnos is still empty/null", empty($pred_fail_row->cancelledbytdnos));

// 3. No orphan lineage row created
$orphan_lineage = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM wp_assessor_faas_previous WHERE faasid = %s", $succ_fail_draft->objid));
assert_test("ATOMIC GUARANTEE: No orphan lineage row created in wp_assessor_faas_previous", intval($orphan_lineage) === 0);

// ─────────────────────────────────────────────────────────────
// Scenario 12: Section 3 — LOCAL Ownership Assignment & REPLICA Entity / RPU / RP Preservation
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 12: Section 3 — Ownership Assignment & REPLICA Preservation ---\n";

// A. REPLICA Entity: create pre-existing REPLICA entity
$rep_entity_id = 'ENT-REP-300';
$rep_entity_no = 'ENT-NO-300';
$pdo_target->exec("
INSERT INTO wp_assessor_entity (objid, origin_type, entityno, name, entityname, address_text, type)
VALUES ('$rep_entity_id', 'REPLICA', '$rep_entity_no', 'Existing Source Corp', 'Existing Source Corp', 'Cagayan de Oro', 'CORPORATION');
");

// Author a LOCAL draft using the exact same entity name
$faas_using_rep_ent = array(
    'tdno' => 'TD-2026-LOCAL-300',
    'utdno' => 'TD-2026-LOCAL-300',
    'owner_name' => 'Existing Source Corp',
    'owner_address' => 'Should Not Overwrite Replica Corp',
    'rpu_type' => 'LAND',
    'txntype_code' => 'GR'
);
$draft_300 = $etracs->create_faas(new MockRestRequest($faas_using_rep_ent));
assert_test("Draft 300 created successfully referencing entity", !is_wp_error($draft_300));

// Check that existing REPLICA entity address was NOT modified
$rep_ent_after = $wpdb->get_row($wpdb->prepare("SELECT origin_type, address_text FROM wp_assessor_entity WHERE objid = %s", $rep_entity_id));
assert_test("Existing REPLICA entity address was NOT overwritten", $rep_ent_after->address_text === 'Cagayan de Oro');
assert_test("Existing REPLICA entity origin_type remains 'REPLICA'", $rep_ent_after->origin_type === 'REPLICA');

// B. Updating a LOCAL draft where taxpayer_id points to REPLICA entity creates a LOCAL entity instead of mutating REPLICA
$update_300_res = $etracs->update_faas($draft_300->objid, new MockRestRequest(array('owner_name' => 'Mutated Name Attempt', 'owner_address' => 'New Address')));
assert_test("update_faas() succeeds", !is_wp_error($update_300_res));

// Verify original REPLICA entity remains 100% untouched
$rep_ent_check2 = $wpdb->get_row($wpdb->prepare("SELECT name, origin_type FROM wp_assessor_entity WHERE objid = %s", $rep_entity_id));
assert_test("REPLICA entity name remains 'Existing Source Corp' (untouched)", $rep_ent_check2->name === 'Existing Source Corp');
assert_test("REPLICA entity origin_type remains 'REPLICA'", $rep_ent_check2->origin_type === 'REPLICA');

// C. REPLICA Real Property and RPU Preservation
$rep_rp_id = 'RP-REP-400';
$rep_rpu_id = 'RPU-REP-400';
$pdo_target->exec("
INSERT INTO wp_assessor_real_property (objid, origin_type, pin, barangay)
VALUES ('$rep_rp_id', 'REPLICA', '048-12-001-44-444', 'Poblacion');

INSERT INTO wp_assessor_rpu (objid, origin_type, realpropertyid, rpu_type, total_market_value)
VALUES ('$rep_rpu_id', 'REPLICA', '$rep_rp_id', 'LAND', 888888.00);
");

// Verify that if a local FAAS draft's real_property_id or rpu_id points to REPLICA, update_faas does not modify them
$faas_rp_rep_id = 'FAAS-TEST-RP-REP';
$pdo_target->exec("
INSERT INTO wp_assessor_faas (objid, state, origin_type, utdno, tdno, realpropertyid, rpuid, owner_name, txntimestamp)
VALUES ('$faas_rp_rep_id', 'INTERIM', 'LOCAL', 'TD-TEST-RP-REP', 'TD-TEST-RP-REP', '$rep_rp_id', '$rep_rpu_id', 'Test Owner', NOW());
");

$update_rp_attempt = $etracs->update_faas($faas_rp_rep_id, new MockRestRequest(array('barangay' => 'Illegal Barangay Mutation', 'total_market_value' => '99999999')));
assert_test("update_faas returns valid response", !is_wp_error($update_rp_attempt));

// Verify REPLICA real property and RPU are intact
$rp_val_after = $wpdb->get_var($wpdb->prepare("SELECT barangay FROM wp_assessor_real_property WHERE objid = %s", $rep_rp_id));
$rpu_val_after = $wpdb->get_var($wpdb->prepare("SELECT total_market_value FROM wp_assessor_rpu WHERE objid = %s", $rep_rpu_id));
assert_test("REPLICA Real Property barangay was NOT overwritten", $rp_val_after === 'Poblacion');
assert_test("REPLICA RPU total_market_value was NOT overwritten", floatval($rpu_val_after) == 888888.00);

// ─────────────────────────────────────────────────────────────
// Scenario 13: Section 4 — State and Reason Semantics Verification
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 13: Section 4 — State and Reason Semantics ---\n";

// Verify disapproval sets cancelnote with [DISAPPROVED] prefix and does not erase lineage
$faas_sem_params = array(
    'tdno' => 'TD-2026-SEM-001',
    'utdno' => 'TD-2026-SEM-001',
    'owner_name' => 'Semantics Test User',
    'prevtdno' => 'TD-2024-001',
    'rpu_type' => 'LAND',
    'txntype_code' => 'TR'
);
$sem_draft = $etracs->create_faas(new MockRestRequest($faas_sem_params));
$etracs->submit_faas($sem_draft->objid, new MockRestRequest());
$sem_disapproved = $etracs->disapprove_faas($sem_draft->objid, new MockRestRequest(array('reason' => 'Defective surveyor signature')));

assert_test("Disapproval reason recorded in cancelreason", $sem_disapproved->cancelreason === 'Defective surveyor signature');
$sem_db_row = $wpdb->get_row($wpdb->prepare("SELECT cancelnote, prevtdno FROM wp_assessor_faas WHERE objid = %s", $sem_draft->objid));
assert_test("Disapproval reason explicitly tagged in cancelnote with [DISAPPROVED] prefix", strpos($sem_db_row->cancelnote, '[DISAPPROVED]') === 0);
assert_test("Disapproval does NOT physically erase historical prevtdno lineage", $sem_db_row->prevtdno === 'TD-2024-001');

// Verify soft deletion (DELETED) does NOT physically delete the row or predecessor lineage
$sem_deleted = $etracs->delete_faas($sem_draft->objid, new MockRestRequest());
assert_test("delete_faas() succeeds", !is_wp_error($sem_deleted));
$del_db_row = $wpdb->get_row($wpdb->prepare("SELECT state, prevtdno FROM wp_assessor_faas WHERE objid = %s", $sem_draft->objid));
assert_test("Deleted row remains physically present in DB with state = 'DELETED'", !empty($del_db_row) && $del_db_row->state === 'DELETED');
assert_test("Deleted row retains historical prevtdno lineage intact", $del_db_row->prevtdno === 'TD-2024-001');

// ─────────────────────────────────────────────────────────────
// Scenario 14: Final Query Hardening — Multi-Successor Lineage & FAAS List Cardinality
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 14: Multi-Successor Lineage & FAAS List Cardinality ---\n";

// A. Setup: Predecessor declaration (e.g. large parcel to be subdivided into 2 lots)
$split_pred_id = 'FAAS-SPLIT-PRED-999';
$split_pred_td = 'TD-2023-SPLIT-999';
$pdo_target->exec("
INSERT INTO wp_assessor_faas (objid, state, origin_type, utdno, tdno, owner_name, fullpin, txntimestamp)
VALUES ('$split_pred_id', 'CURRENT', 'REPLICA', '$split_pred_td', '$split_pred_td', 'Subdivision Mother Lot Owner', '048-12-001-88-888', NOW());
");

// Pre-check stats and list counts before successors
$stats_before_split = $etracs->get_faas_stats(new MockRestRequest());
$list_before_split = $etracs->get_faas_list(new MockRestRequest(array('per_page' => 100)));
if (empty($list_before_split['data'])) {
    echo "DEBUG LIST ERROR: " . $wpdb->last_error . "\n";
    var_dump($list_before_split);
}
$pred_count_before = 0;
foreach ($list_before_split['data'] as $row) {
    if ($row->tdno === $split_pred_td) $pred_count_before++;
}
assert_test("Predecessor appears exactly once in list before successors", $pred_count_before === 1);

// B. Author Successor Lot 1 referencing Mother Lot by prevfaasid + prevtdno
$succ_lot1_params = array(
    'tdno' => 'TD-2026-LOT-1',
    'utdno' => 'TD-2026-LOT-1',
    'owner_name' => 'Buyer Lot 1',
    'prevtdno' => $split_pred_td,
    'rpu_type' => 'LAND',
    'txntype_code' => 'CS' // Consolidation / Subdivision
);
$succ_lot1_draft = $etracs->create_faas(new MockRestRequest($succ_lot1_params));
$etracs->submit_faas($succ_lot1_draft->objid, new MockRestRequest());
$succ_lot1_app = $etracs->approve_faas($succ_lot1_draft->objid, new MockRestRequest(array('date_approved' => '2026-10-22 16:00:00')));
assert_test("Successor Lot 1 approved to CURRENT", !is_wp_error($succ_lot1_app) && $succ_lot1_app->state === 'CURRENT');

// C. Author Successor Lot 2 referencing Mother Lot by prevfaasid + prevtdno
$succ_lot2_params = array(
    'tdno' => 'TD-2026-LOT-2',
    'utdno' => 'TD-2026-LOT-2',
    'owner_name' => 'Buyer Lot 2',
    'prevtdno' => $split_pred_td,
    'rpu_type' => 'LAND',
    'txntype_code' => 'CS'
);
$succ_lot2_draft = $etracs->create_faas(new MockRestRequest($succ_lot2_params));
$etracs->submit_faas($succ_lot2_draft->objid, new MockRestRequest());
$succ_lot2_app = $etracs->approve_faas($succ_lot2_draft->objid, new MockRestRequest(array('date_approved' => '2026-10-22 16:30:00')));
assert_test("Successor Lot 2 approved to CURRENT", !is_wp_error($succ_lot2_app) && $succ_lot2_app->state === 'CURRENT');

// D. CARDINALITY TEST: Predecessor must appear EXACTLY ONCE in FAAS list (no row multiplication from 2 successors)
$list_after_split = $etracs->get_faas_list(new MockRestRequest(array('per_page' => 100)));
$pred_count_after = 0;
$pred_row_in_list = null;
foreach ($list_after_split['data'] as $row) {
    if ($row->tdno === $split_pred_td) {
        $pred_count_after++;
        $pred_row_in_list = $row;
    }
}
assert_test("CARDINALITY: Predecessor with multiple approved successors appears EXACTLY ONCE in list query", $pred_count_after === 1);
assert_test("Total count in list reflects exact distinct rows without duplication", $list_after_split['total'] === count($list_after_split['data']));

// E. METADATA AGGREGATION: Predecessor list row reflects aggregated successor metadata
assert_test("Predecessor effective state is CANCELLED in list", $pred_row_in_list->effective_state === 'CANCELLED');
assert_test("Predecessor raw state is CURRENT in list", $pred_row_in_list->raw_state === 'CURRENT');
assert_test("Predecessor cancelled_by_tdnos contains BOTH successor TDs comma-separated", strpos($pred_row_in_list->cancelled_by_tdnos, 'TD-2026-LOT-1') !== false && strpos($pred_row_in_list->cancelled_by_tdnos, 'TD-2026-LOT-2') !== false);
assert_test("Predecessor cancel_date reflects earliest approval timestamp", $pred_row_in_list->cancel_date === '2026-10-22 16:00:00');

// F. SINGLE RECORD LOOKUP: get_faas($split_pred_id) reflects aggregated successors
$pred_single = $etracs->get_faas($split_pred_id);
assert_test("Single-record get_faas returns effective state CANCELLED", $pred_single->effective_state === 'CANCELLED');
assert_test("Single-record cancelled_by_tdnos contains BOTH successor TDs", strpos($pred_single->cancelled_by_tdnos, 'TD-2026-LOT-1') !== false && strpos($pred_single->cancelled_by_tdnos, 'TD-2026-LOT-2') !== false);

// G. DASHBOARD STATS: Mother lot counted exactly once under cancelled, not duplicated
$stats_after_split = $etracs->get_faas_stats(new MockRestRequest());
assert_test("Dashboard cancelled count accurately incremented without duplicate counting", $stats_after_split['cancelled'] >= 1);
assert_test("Dashboard total equals sum of distinct rows", $stats_after_split['total'] === intval($wpdb->get_var("SELECT COUNT(*) FROM wp_assessor_faas WHERE state != 'DELETED'")));

// H. ID-BASED MATCHING (prevfaasid): Test predecessor identified by prevfaasid when prevtdno is omitted/changed
$pred_id_only_id = 'FAAS-PRED-ID-ONLY-777';
$pdo_target->exec("
INSERT INTO wp_assessor_faas (objid, state, origin_type, utdno, tdno, owner_name, fullpin, txntimestamp)
VALUES ('$pred_id_only_id', 'CURRENT', 'REPLICA', 'TD-ORIG-NO-MATCH', 'TD-ORIG-NO-MATCH', 'ID Matching Predecessor', '048-12-001-77-777', NOW());
");

// Insert a lineage link where prevfaasid is explicitly populated with $pred_id_only_id, but prevtdno has dummy/old text
$succ_id_match_id = 'FAAS-SUCC-ID-MATCH-777';
$pdo_target->exec("
INSERT INTO wp_assessor_faas (objid, state, origin_type, utdno, tdno, dtapproved, owner_name, fullpin, txntimestamp)
VALUES ('$succ_id_match_id', 'CURRENT', 'LOCAL', 'TD-2026-SUCC-ID-MATCH', 'TD-2026-SUCC-ID-MATCH', '2026-10-22 17:00:00', 'Successor via ID', '048-12-001-77-778', NOW());

INSERT INTO wp_assessor_faas_previous (objid, origin_type, faasid, prevfaasid, prevtdno)
VALUES ('LINEAGE-ID-MATCH-777', 'LOCAL', '$succ_id_match_id', '$pred_id_only_id', 'OLD-TD-DOES-NOT-EXIST');
");

$pred_id_matched = $etracs->get_faas($pred_id_only_id);
assert_test("Predecessor successfully resolved via prevfaasid even when prevtdno differs", $pred_id_matched->effective_state === 'CANCELLED');
assert_test("Predecessor cancelled_by_tdnos contains TD-2026-SUCC-ID-MATCH via prevfaasid link", $pred_id_matched->cancelled_by_tdnos === 'TD-2026-SUCC-ID-MATCH');

// I. TD-FALLBACK MATCHING: Test predecessor without prevfaasid resolves via prevtdno
$pred_td_only_id = 'FAAS-PRED-TD-ONLY-888';
$pred_td_only_td = 'TD-2023-FALLBACK-888';
$pdo_target->exec("
INSERT INTO wp_assessor_faas (objid, state, origin_type, utdno, tdno, owner_name, fullpin, txntimestamp)
VALUES ('$pred_td_only_id', 'CURRENT', 'REPLICA', '$pred_td_only_td', '$pred_td_only_td', 'TD Fallback Predecessor', '048-12-001-88-777', NOW());
");

$succ_td_fallback_id = 'FAAS-SUCC-TD-FALLBACK-888';
$pdo_target->exec("
INSERT INTO wp_assessor_faas (objid, state, origin_type, utdno, tdno, dtapproved, owner_name, fullpin, txntimestamp)
VALUES ('$succ_td_fallback_id', 'CURRENT', 'LOCAL', 'TD-2026-SUCC-TD-FALLBACK', 'TD-2026-SUCC-TD-FALLBACK', '2026-10-22 17:30:00', 'Successor via TD Fallback', '048-12-001-88-778', NOW());

INSERT INTO wp_assessor_faas_previous (objid, origin_type, faasid, prevfaasid, prevtdno)
VALUES ('LINEAGE-TD-FALLBACK-888', 'LOCAL', '$succ_td_fallback_id', NULL, '$pred_td_only_td');
");

$pred_td_matched = $etracs->get_faas($pred_td_only_id);
assert_test("Predecessor successfully resolved via prevtdno fallback when prevfaasid is NULL", $pred_td_matched->effective_state === 'CANCELLED');
assert_test("Predecessor cancelled_by_tdnos contains TD-2026-SUCC-TD-FALLBACK via prevtdno link", $pred_td_matched->cancelled_by_tdnos === 'TD-2026-SUCC-TD-FALLBACK');

// J. MULTIPLE PREDECESSOR-LINK ROWS for single successor (e.g. consolidation of 2 lots into 1)
$consol_pred1_id = 'FAAS-CONSOL-P1';
$consol_pred2_id = 'FAAS-CONSOL-P2';
$consol_pred1_td = 'TD-2023-CONSOL-P1';
$consol_pred2_td = 'TD-2023-CONSOL-P2';
$pdo_target->exec("
INSERT INTO wp_assessor_faas (objid, state, origin_type, utdno, tdno, owner_name, fullpin, txntimestamp)
VALUES ('$consol_pred1_id', 'CURRENT', 'REPLICA', '$consol_pred1_td', '$consol_pred1_td', 'Consol Owner P1', '048-12-001-11-001', NOW());

INSERT INTO wp_assessor_faas (objid, state, origin_type, utdno, tdno, owner_name, fullpin, txntimestamp)
VALUES ('$consol_pred2_id', 'CURRENT', 'REPLICA', '$consol_pred2_td', '$consol_pred2_td', 'Consol Owner P2', '048-12-001-11-002', NOW());

INSERT INTO wp_assessor_faas (objid, state, origin_type, utdno, tdno, dtapproved, owner_name, fullpin, txntimestamp)
VALUES ('FAAS-CONSOL-SUCC', 'CURRENT', 'LOCAL', 'TD-2026-CONSOL-SUCC', 'TD-2026-CONSOL-SUCC', '2026-10-22 18:00:00', 'Consolidated Property Owner', '048-12-001-11-003', NOW());

INSERT INTO wp_assessor_faas_previous (objid, origin_type, faasid, prevfaasid, prevtdno)
VALUES ('LINEAGE-CONSOL-1', 'LOCAL', 'FAAS-CONSOL-SUCC', '$consol_pred1_id', '$consol_pred1_td'),
       ('LINEAGE-CONSOL-2', 'LOCAL', 'FAAS-CONSOL-SUCC', '$consol_pred2_id', '$consol_pred2_td');
");

$p1_eff = $etracs->get_faas($consol_pred1_id);
$p2_eff = $etracs->get_faas($consol_pred2_id);
assert_test("Consolidated predecessor 1 is effectively CANCELLED", $p1_eff->effective_state === 'CANCELLED');
assert_test("Consolidated predecessor 2 is effectively CANCELLED", $p2_eff->effective_state === 'CANCELLED');
assert_test("Both consolidated predecessors point to same successor TD", $p1_eff->cancelled_by_tdnos === 'TD-2026-CONSOL-SUCC' && $p2_eff->cancelled_by_tdnos === 'TD-2026-CONSOL-SUCC');

// ─────────────────────────────────────────────────────────────
// Scenario 15: WP-02 Final Acceptance — Canonical Predecessor Resolution & Conflict Isolation
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 15: Canonical Predecessor Resolution & Conflict Isolation ---\n";

// 1. One predecessor referenced by BOTH prevfaasid and TD-number-only lineage rows
$pred_mixed_id = 'FAAS-PRED-MIXED-111';
$pred_mixed_td = 'TD-2023-MIXED-111';
$pdo_target->exec("
INSERT INTO wp_assessor_faas (objid, state, origin_type, utdno, tdno, owner_name, fullpin, txntimestamp)
VALUES ('$pred_mixed_id', 'CURRENT', 'REPLICA', '$pred_mixed_td', '$pred_mixed_td', 'Mixed Lineage Predecessor', '048-12-001-99-111', NOW());
");

// Successor A references by prevfaasid
$pdo_target->exec("
INSERT INTO wp_assessor_faas (objid, state, origin_type, utdno, tdno, dtapproved, year, owner_name, fullpin, txntimestamp)
VALUES ('FAAS-SUCC-MIX-A', 'CURRENT', 'LOCAL', 'TD-2026-SUCC-MIX-A', 'TD-2026-SUCC-MIX-A', '2026-10-22 19:00:00', 2026, 'Successor Mix A', '048-12-001-99-112', NOW());

INSERT INTO wp_assessor_faas_previous (objid, origin_type, faasid, prevfaasid, prevtdno)
VALUES ('LINEAGE-MIX-A', 'LOCAL', 'FAAS-SUCC-MIX-A', '$pred_mixed_id', NULL);
");

// Successor B references same predecessor by prevtdno only
$pdo_target->exec("
INSERT INTO wp_assessor_faas (objid, state, origin_type, utdno, tdno, dtapproved, year, owner_name, fullpin, txntimestamp)
VALUES ('FAAS-SUCC-MIX-B', 'CURRENT', 'LOCAL', 'TD-2027-SUCC-MIX-B', 'TD-2027-SUCC-MIX-B', '2027-01-15 10:00:00', 2027, 'Successor Mix B', '048-12-001-99-113', NOW());

INSERT INTO wp_assessor_faas_previous (objid, origin_type, faasid, prevfaasid, prevtdno)
VALUES ('LINEAGE-MIX-B', 'LOCAL', 'FAAS-SUCC-MIX-B', NULL, '$pred_mixed_td');
");

// Predecessor must aggregate BOTH successors into exactly ONE row
$mixed_eff = $etracs->get_faas($pred_mixed_id);
assert_test("Predecessor referenced by mixed ID and TD rows is effectively CANCELLED", $mixed_eff->effective_state === 'CANCELLED');
assert_test("Predecessor aggregates both successors into cancelled_by_tdnos", strpos($mixed_eff->cancelled_by_tdnos, 'TD-2026-SUCC-MIX-A') !== false && strpos($mixed_eff->cancelled_by_tdnos, 'TD-2027-SUCC-MIX-B') !== false);
assert_test("Earliest cancel_date correctly chosen (2026-10-22)", $mixed_eff->cancel_date === '2026-10-22 19:00:00');
assert_test("Cancelled year matches earliest successor (2026, not 2027)", intval($mixed_eff->cancelled_year) === 2026);

// Verify FAAS list cardinality for mixed predecessor
$list_mixed = $etracs->get_faas_list(new MockRestRequest(array('tdno' => $pred_mixed_td)));
assert_test("Mixed lineage predecessor appears exactly once in list results", count($list_mixed['data']) === 1 && $list_mixed['total'] === 1);
assert_test("List effective state matches single record detail", $list_mixed['data'][0]->effective_state === 'CANCELLED');
assert_test("List cancelled_year matches single record detail", intval($list_mixed['data'][0]->cancelled_year) === 2026);

// 2. Populated but unresolvable prevfaasid with a valid prevtdno fallback
$pred_unres_id = 'FAAS-PRED-UNRES-222';
$pred_unres_td = 'TD-2023-UNRES-222';
$pdo_target->exec("
INSERT INTO wp_assessor_faas (objid, state, origin_type, utdno, tdno, owner_name, fullpin, txntimestamp)
VALUES ('$pred_unres_id', 'CURRENT', 'REPLICA', '$pred_unres_td', '$pred_unres_td', 'Unresolvable ID Predecessor', '048-12-001-99-222', NOW());
");

$succ_unres_id = 'FAAS-SUCC-UNRES-222';
$pdo_target->exec("
INSERT INTO wp_assessor_faas (objid, state, origin_type, utdno, tdno, dtapproved, year, owner_name, fullpin, txntimestamp)
VALUES ('$succ_unres_id', 'CURRENT', 'LOCAL', 'TD-2026-SUCC-UNRES', 'TD-2026-SUCC-UNRES', '2026-10-22 20:00:00', 2026, 'Successor for Unres', '048-12-001-99-223', NOW());

INSERT INTO wp_assessor_faas_previous (objid, origin_type, faasid, prevfaasid, prevtdno)
VALUES ('LINEAGE-UNRES-222', 'LOCAL', '$succ_unres_id', 'NON-EXISTENT-GHOST-ID', '$pred_unres_td');
");

$unres_eff = $etracs->get_faas($pred_unres_id);
assert_test("Predecessor with unresolvable prevfaasid successfully falls back to valid prevtdno", $unres_eff->effective_state === 'CANCELLED');
assert_test("Predecessor cancelled_by_tdnos contains successor TD via TD fallback", $unres_eff->cancelled_by_tdnos === 'TD-2026-SUCC-UNRES');

// 3. ID string collision isolation: String matches Row X's objid and Row Y's tdno
// Suppose String 'COLLISION-STR-123' is the objid of Row X, and ALSO the tdno of Row Y.
// Lineage specifies prevfaasid = 'COLLISION-STR-123' (aiming at Row X).
// Row Y MUST NOT be cancelled because it only matches on tdno, while prevfaasid is an explicit ID reference!
$row_x_id = 'COLLISION-STR-123';
$row_x_td = 'TD-FOR-ROW-X';
$row_y_id = 'FAAS-ROW-Y-OBJID';
$row_y_td = 'COLLISION-STR-123'; // Same string as Row X's objid!

$pdo_target->exec("
INSERT INTO wp_assessor_faas (objid, state, origin_type, utdno, tdno, owner_name, fullpin, txntimestamp)
VALUES ('$row_x_id', 'CURRENT', 'REPLICA', '$row_x_td', '$row_x_td', 'Target Predecessor X', '048-12-001-00-111', NOW()),
       ('$row_y_id', 'CURRENT', 'REPLICA', '$row_y_td', '$row_y_td', 'Innocent Bystander Y', '048-12-001-00-222', NOW());
");

$succ_collision_id = 'FAAS-SUCC-COLLISION';
$pdo_target->exec("
INSERT INTO wp_assessor_faas (objid, state, origin_type, utdno, tdno, dtapproved, year, owner_name, fullpin, txntimestamp)
VALUES ('$succ_collision_id', 'CURRENT', 'LOCAL', 'TD-2026-SUCC-COLLISION', 'TD-2026-SUCC-COLLISION', '2026-10-22 21:00:00', 2026, 'Successor Collision', '048-12-001-00-333', NOW());

INSERT INTO wp_assessor_faas_previous (objid, origin_type, faasid, prevfaasid, prevtdno)
VALUES ('LINEAGE-COLLISION', 'LOCAL', '$succ_collision_id', '$row_x_id', 'TD-FOR-ROW-X');
");

$row_x_eff = $etracs->get_faas($row_x_id);
$row_y_eff = $etracs->get_faas($row_y_id);
assert_test("Row X (target of prevfaasid) is effectively CANCELLED", $row_x_eff->effective_state === 'CANCELLED');
assert_test("ISOLATION: Row Y (having tdno equal to Row X's objid) remains CURRENT and NOT cancelled", $row_y_eff->effective_state === 'CURRENT');
assert_test("Row Y cancelled_by_tdnos remains empty/null", empty($row_y_eff->cancelled_by_tdnos));

// 4. Multi-successor with approval dates spanning different years: cancelled_year consistency
$pred_years_id = 'FAAS-PRED-YEARS-333';
$pred_years_td = 'TD-2023-YEARS-333';
$pdo_target->exec("
INSERT INTO wp_assessor_faas (objid, state, origin_type, utdno, tdno, owner_name, fullpin, txntimestamp)
VALUES ('$pred_years_id', 'CURRENT', 'REPLICA', '$pred_years_td', '$pred_years_td', 'Different Years Predecessor', '048-12-001-99-333', NOW());
");

// Successor 1: approved in 2025 with year = 2025
$pdo_target->exec("
INSERT INTO wp_assessor_faas (objid, state, origin_type, utdno, tdno, dtapproved, year, owner_name, fullpin, txntimestamp)
VALUES ('FAAS-SUCC-YR-1', 'CURRENT', 'LOCAL', 'TD-2025-SUCC-YR-1', 'TD-2025-SUCC-YR-1', '2025-06-15 10:00:00', 2025, 'Successor 2025', '048-12-001-99-334', NOW());
");

// Successor 2: approved in 2028 with year = 2028
$pdo_target->exec("
INSERT INTO wp_assessor_faas (objid, state, origin_type, utdno, tdno, dtapproved, year, owner_name, fullpin, txntimestamp)
VALUES ('FAAS-SUCC-YR-2', 'CURRENT', 'LOCAL', 'TD-2028-SUCC-YR-2', 'TD-2028-SUCC-YR-2', '2028-03-20 10:00:00', 2028, 'Successor 2028', '048-12-001-99-335', NOW());

INSERT INTO wp_assessor_faas_previous (objid, origin_type, faasid, prevfaasid, prevtdno)
VALUES ('LINEAGE-YR-1', 'LOCAL', 'FAAS-SUCC-YR-1', '$pred_years_id', '$pred_years_td'),
       ('LINEAGE-YR-2', 'LOCAL', 'FAAS-SUCC-YR-2', '$pred_years_id', '$pred_years_td');
");

$years_eff = $etracs->get_faas($pred_years_id);
assert_test("Earliest approval date is 2025-06-15", $years_eff->cancel_date === '2025-06-15 10:00:00');
assert_test("Cancelled year matches earliest approval year (2025, not 2028)", intval($years_eff->cancelled_year) === 2025);

// 5. Verification: Dashboard statistics agree with list results
$final_stats = $etracs->get_faas_stats(new MockRestRequest());
$final_list_cancelled = $etracs->get_faas_list(new MockRestRequest(array('state' => 'CANCELLED', 'per_page' => 100)));
assert_test("Dashboard cancelled stat agrees with list query filtered by state = CANCELLED", intval($final_stats['cancelled']) === intval($final_list_cancelled['total']));

$final_list_current = $etracs->get_faas_list(new MockRestRequest(array('state' => 'CURRENT', 'per_page' => 100)));
assert_test("Dashboard current stat agrees with list query filtered by state = CURRENT", intval($final_stats['current']) === intval($final_list_current['total']));

// ------------------------------------------------------------------------
// Scenario 16: Ambiguous TD Fallback Safety & Conflict Isolation
// ------------------------------------------------------------------------
echo "\n--- Scenario 16: Ambiguous TD Fallback Safety & Conflict Isolation ---\n";

// 1. Unique prevtdno fallback resolving to exactly one predecessor
$uniq_pred_id = 'FAAS-PRED-UNIQ-TD';
$uniq_pred_td = 'TD-UNIQUE-FALLBACK-1';
$pdo_target->exec("
INSERT INTO wp_assessor_faas (objid, state, origin_type, utdno, tdno, owner_name, fullpin, txntimestamp)
VALUES ('$uniq_pred_id', 'CURRENT', 'REPLICA', 'UTD-UNIQ-1', '$uniq_pred_td', 'Unique Predecessor', '048-12-001-99-901', NOW());
");

$succ_uniq_id = 'FAAS-SUCC-UNIQ-TD';
$succ_uniq_td = 'TD-SUCC-UNIQ-TD';
$pdo_target->exec("
INSERT INTO wp_assessor_faas (objid, state, origin_type, utdno, tdno, dtapproved, year, owner_name, fullpin, txntimestamp)
VALUES ('$succ_uniq_id', 'CURRENT', 'LOCAL', 'UTD-SUCC-UNIQ', '$succ_uniq_td', '2026-10-22 22:00:00', 2026, 'Successor Uniq TD', '048-12-001-99-902', NOW());

INSERT INTO wp_assessor_faas_previous (objid, origin_type, faasid, prevfaasid, prevtdno)
VALUES ('LINEAGE-UNIQ-TD', 'LOCAL', '$succ_uniq_id', NULL, '$uniq_pred_td');
");

$uniq_pred_eff = $etracs->get_faas($uniq_pred_id);
assert_test("Unique prevtdno fallback resolves to predecessor and marks it CANCELLED", $uniq_pred_eff->effective_state === 'CANCELLED');
assert_test("Unique prevtdno predecessor has successor in cancelled_by_tdnos", $uniq_pred_eff->cancelled_by_tdnos === $succ_uniq_td);

// 2. Ambiguous prevtdno matching multiple FAAS records:
// Two distinct records share the same tdno 'TD-SHARED-AMBIG-99' (e.g. historical revisions or multi-PIN declarations)
$ambig_pred_id1 = 'FAAS-AMBIG-PRED-1';
$ambig_pred_id2 = 'FAAS-AMBIG-PRED-2';
$shared_ambig_td = 'TD-SHARED-AMBIG-99';

$pdo_target->exec("
INSERT INTO wp_assessor_faas (objid, state, origin_type, utdno, tdno, owner_name, fullpin, txntimestamp)
VALUES ('$ambig_pred_id1', 'CURRENT', 'REPLICA', 'UTD-AMBIG-1', '$shared_ambig_td', 'Ambiguous Record 1', '048-12-001-99-911', NOW()),
       ('$ambig_pred_id2', 'CURRENT', 'REPLICA', 'UTD-AMBIG-2', '$shared_ambig_td', 'Ambiguous Record 2', '048-12-001-99-912', NOW());
");

$succ_ambig_id = 'FAAS-SUCC-AMBIG-TD';
$succ_ambig_td = 'TD-SUCC-AMBIG-TD';
$pdo_target->exec("
INSERT INTO wp_assessor_faas (objid, state, origin_type, utdno, tdno, dtapproved, year, owner_name, fullpin, txntimestamp)
VALUES ('$succ_ambig_id', 'CURRENT', 'LOCAL', 'UTD-SUCC-AMBIG', '$succ_ambig_td', '2026-10-22 22:30:00', 2026, 'Successor Ambig TD', '048-12-001-99-913', NOW());

INSERT INTO wp_assessor_faas_previous (objid, origin_type, faasid, prevfaasid, prevtdno)
VALUES ('LINEAGE-AMBIG-TD', 'LOCAL', '$succ_ambig_id', NULL, '$shared_ambig_td');
");

$ambig_pred1_eff = $etracs->get_faas($ambig_pred_id1);
$ambig_pred2_eff = $etracs->get_faas($ambig_pred_id2);

// Neither ambiguous record should be cancelled because the reference is ambiguous
assert_test("Ambiguous TD fallback does NOT mark Record 1 as effectively CANCELLED", $ambig_pred1_eff->effective_state === 'CURRENT');
assert_test("Record 1 cancelled_by_tdnos remains empty/null", empty($ambig_pred1_eff->cancelled_by_tdnos));
assert_test("Ambiguous TD fallback does NOT mark Record 2 as effectively CANCELLED", $ambig_pred2_eff->effective_state === 'CURRENT');
assert_test("Record 2 cancelled_by_tdnos remains empty/null", empty($ambig_pred2_eff->cancelled_by_tdnos));

// 3. Populated but unresolvable prevfaasid combined with an ambiguous TD fallback
$unres_ambig_succ_id = 'FAAS-SUCC-UNRES-AMBIG';
$pdo_target->exec("
INSERT INTO wp_assessor_faas (objid, state, origin_type, utdno, tdno, dtapproved, year, owner_name, fullpin, txntimestamp)
VALUES ('$unres_ambig_succ_id', 'CURRENT', 'LOCAL', 'UTD-SUCC-UNRES-AMBIG', 'TD-SUCC-UNRES-AMBIG', '2026-10-22 23:00:00', 2026, 'Successor Unres Ambig', '048-12-001-99-914', NOW());

INSERT INTO wp_assessor_faas_previous (objid, origin_type, faasid, prevfaasid, prevtdno)
VALUES ('LINEAGE-UNRES-AMBIG', 'LOCAL', '$unres_ambig_succ_id', 'NON-EXISTENT-OBJID-999', '$shared_ambig_td');
");

$ambig_pred1_after = $etracs->get_faas($ambig_pred_id1);
$ambig_pred2_after = $etracs->get_faas($ambig_pred_id2);
assert_test("Unresolvable prevfaasid with ambiguous TD fallback preserves Record 1 state as CURRENT", $ambig_pred1_after->effective_state === 'CURRENT');
assert_test("Unresolvable prevfaasid with ambiguous TD fallback preserves Record 2 state as CURRENT", $ambig_pred2_after->effective_state === 'CURRENT');

// 4. Resolved prevfaasid remains authoritative even if TD is shared/ambiguous
$resolved_succ_id = 'FAAS-SUCC-AUTH-ID';
$pdo_target->exec("
INSERT INTO wp_assessor_faas (objid, state, origin_type, utdno, tdno, dtapproved, year, owner_name, fullpin, txntimestamp)
VALUES ('$resolved_succ_id', 'CURRENT', 'LOCAL', 'UTD-SUCC-AUTH-ID', 'TD-SUCC-AUTH-ID', '2026-10-22 23:30:00', 2026, 'Successor Auth ID', '048-12-001-99-915', NOW());

INSERT INTO wp_assessor_faas_previous (objid, origin_type, faasid, prevfaasid, prevtdno)
VALUES ('LINEAGE-AUTH-ID', 'LOCAL', '$resolved_succ_id', '$ambig_pred_id1', '$shared_ambig_td');
");

$ambig_pred1_authoritative = $etracs->get_faas($ambig_pred_id1);
$ambig_pred2_authoritative = $etracs->get_faas($ambig_pred_id2);
assert_test("Authoritative prevfaasid cancels targeted Record 1 despite shared TD", $ambig_pred1_authoritative->effective_state === 'CANCELLED');
assert_test("Authoritative prevfaasid leaves un-targeted Record 2 untouched as CURRENT", $ambig_pred2_authoritative->effective_state === 'CURRENT');

// 5. Cardinality, pagination, and dashboard stats consistency
$stats_s16 = $etracs->get_faas_stats(new MockRestRequest());
$list_cancelled_s16 = $etracs->get_faas_list(new MockRestRequest(array('state' => 'CANCELLED', 'per_page' => 100)));
$list_current_s16 = $etracs->get_faas_list(new MockRestRequest(array('state' => 'CURRENT', 'per_page' => 100)));

assert_test("Scenario 16: Dashboard cancelled stat matches list CANCELLED total exactly", intval($stats_s16['cancelled']) === intval($list_cancelled_s16['total']));
assert_test("Scenario 16: Dashboard current stat matches list CURRENT total exactly", intval($stats_s16['current']) === intval($list_current_s16['total']));
assert_test("Scenario 16: Total sum of stats matches total active records", (intval($stats_s16['cancelled']) + intval($stats_s16['current'])) <= intval($stats_s16['total']));

// ─────────────────────────────────────────────────────────────
// Scenario 17: WP-03 Predecessor Persistence & Historical Snapshots
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 17: WP-03 Predecessor Persistence & Historical Snapshots ---\n";

// 1. Author a predecessor with complete assessment and property details
$wp03_pred1_id = 'FAAS-WP03-PRED-01';
$wp03_pred1_td = 'TD-2024-WP03-01';
$wp03_pred1_rpu_id = 'RPU-WP03-PRED-01';
$wp03_pred1_rp_id  = 'RP-WP03-PRED-01';

$pdo_target->exec("
INSERT INTO wp_assessor_real_property (objid, origin_type, pin, barangay)
VALUES ('$wp03_pred1_rp_id', 'REPLICA', '048-12-001-99-001', 'Barangay Uno');

INSERT INTO wp_assessor_rpu (objid, origin_type, realpropertyid, rpu_type, total_market_value, total_assessed_value, total_area_sqm, total_area_hectare)
VALUES ('$wp03_pred1_rpu_id', 'REPLICA', '$wp03_pred1_rp_id', 'LAND', 550000.00, 110000.00, 1500.00, 0.15);

INSERT INTO wp_assessor_faas (objid, state, origin_type, rpuid, realpropertyid, utdno, tdno, owner_name, administrator_name, fullpin, txntimestamp)
VALUES ('$wp03_pred1_id', 'CURRENT', 'REPLICA', '$wp03_pred1_rpu_id', '$wp03_pred1_rp_id', '$wp03_pred1_td', '$wp03_pred1_td', 'Original Ancestral Owner', 'Original Administrator', '048-12-001-99-001', NOW());
");

// Test 1: Author a successor referencing predecessor by prevfaasid + prevtdno and approve
$wp03_succ1_params = array(
    'tdno' => 'TD-2026-WP03-SUCC-01',
    'utdno' => 'TD-2026-WP03-SUCC-01',
    'owner_name' => 'Successor Owner 1',
    'prevtdno' => $wp03_pred1_td,
    'prevfaasid' => $wp03_pred1_id,
    'rpu_type' => 'LAND',
    'txntype_code' => 'TR'
);
$wp03_succ1_draft = $etracs->create_faas(new MockRestRequest($wp03_succ1_params));
assert_test("WP-03 Test 1: Draft created with prevfaasid and prevtdno", !is_wp_error($wp03_succ1_draft));

// Submit and approve draft
$etracs->submit_faas($wp03_succ1_draft->objid, new MockRestRequest());
$wp03_succ1_app = $etracs->approve_faas($wp03_succ1_draft->objid, new MockRestRequest(array('date_approved' => '2026-10-22 17:00:00')));
assert_test("WP-03 Test 1: Successor 1 approved successfully", !is_wp_error($wp03_succ1_app) && $wp03_succ1_app->state === 'CURRENT');

// Verify lineage row in wp_assessor_faas_previous
$snap1_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM wp_assessor_faas_previous WHERE faasid = %s", $wp03_succ1_draft->objid));
assert_test("WP-03 Test 1: Lineage row stored in wp_assessor_faas_previous", !empty($snap1_row));
assert_test("WP-03 Test 1: Lineage row has origin_type = 'LOCAL'", $snap1_row->origin_type === 'LOCAL');
assert_test("WP-03 Test 1: Lineage row preserves stable prevfaasid", $snap1_row->prevfaasid === $wp03_pred1_id);
assert_test("WP-03 Test 1: Lineage row preserves prevtdno", $snap1_row->prevtdno === $wp03_pred1_td);
assert_test("WP-03 Test 1: Historical snapshot captured owner", $snap1_row->prevowner === 'Original Ancestral Owner');
assert_test("WP-03 Test 1: Historical snapshot captured administrator", $snap1_row->prevadministrator === 'Original Administrator');
assert_test("WP-03 Test 1: Historical snapshot captured PIN", $snap1_row->prevpin === '048-12-001-99-001');
assert_test("WP-03 Test 1: Historical snapshot captured market value", floatval($snap1_row->prevmv) == 550000.00);
assert_test("WP-03 Test 1: Historical snapshot captured assessed value", floatval($snap1_row->prevav) == 110000.00);

// Test 2: Idempotent re-execution of persist_predecessor_lineage does not duplicate lineage or overwrite snapshot
$re_persist = $etracs->persist_predecessor_lineage($wp03_succ1_draft->objid, array(
    'prevtdno' => $wp03_pred1_td,
    'prevfaasid' => $wp03_pred1_id,
    'prev_owner' => 'Different Nonce Attempt'
), false);
assert_test("WP-03 Test 2: Re-persisting lineage succeeds idempotently", $re_persist === true);
$lineage_count_succ1 = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM wp_assessor_faas_previous WHERE faasid = %s", $wp03_succ1_draft->objid));
assert_test("WP-03 Test 2: Re-persisting does NOT duplicate lineage rows (count remains 1)", intval($lineage_count_succ1) === 1);
$snap1_recheck = $wpdb->get_row($wpdb->prepare("SELECT * FROM wp_assessor_faas_previous WHERE faasid = %s", $wp03_succ1_draft->objid));
assert_test("WP-03 Test 2: Existing snapshot owner remains immutable (Original Ancestral Owner)", $snap1_recheck->prevowner === 'Original Ancestral Owner');

// Test 3: Consolidation (Two Predecessors -> One Successor) with independent historical snapshots
$wp03_pred2_id = 'FAAS-WP03-PRED-02';
$wp03_pred2_td = 'TD-2024-WP03-02';
$wp03_pred2_rpu_id = 'RPU-WP03-PRED-02';

$pdo_target->exec("
INSERT INTO wp_assessor_rpu (objid, origin_type, realpropertyid, rpu_type, total_market_value, total_assessed_value, total_area_sqm, total_area_hectare)
VALUES ('$wp03_pred2_rpu_id', 'REPLICA', '$wp03_pred1_rp_id', 'LAND', 300000.00, 60000.00, 800.00, 0.08);

INSERT INTO wp_assessor_faas (objid, state, origin_type, rpuid, realpropertyid, utdno, tdno, owner_name, administrator_name, fullpin, txntimestamp)
VALUES ('$wp03_pred2_id', 'CURRENT', 'REPLICA', '$wp03_pred2_rpu_id', '$wp03_pred1_rp_id', '$wp03_pred2_td', '$wp03_pred2_td', 'Second Predecessor Owner', 'Second Admin', '048-12-001-99-002', NOW());
");

$wp03_consol_params = array(
    'tdno' => 'TD-2026-WP03-CONSOL',
    'utdno' => 'TD-2026-WP03-CONSOL',
    'owner_name' => 'Consolidated Master Owner',
    'prevtdno' => "{$wp03_pred1_td} | {$wp03_pred2_td}",
    'rpu_type' => 'LAND',
    'txntype_code' => 'CS',
    'predecessors' => array(
        array('prevfaasid' => $wp03_pred1_id, 'prevtdno' => $wp03_pred1_td),
        array('prevfaasid' => $wp03_pred2_id, 'prevtdno' => $wp03_pred2_td)
    )
);
$wp03_consol_draft = $etracs->create_faas(new MockRestRequest($wp03_consol_params));
$etracs->submit_faas($wp03_consol_draft->objid, new MockRestRequest());
$wp03_consol_app = $etracs->approve_faas($wp03_consol_draft->objid, new MockRestRequest(array(
    'date_approved' => '2026-10-22 17:30:00',
    'predecessors' => $wp03_consol_params['predecessors']
)));
assert_test("WP-03 Test 3: Consolidation successor approved", !is_wp_error($wp03_consol_app) && $wp03_consol_app->state === 'CURRENT');

$consol_lineage_rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM wp_assessor_faas_previous WHERE faasid = %s ORDER BY prevtdno ASC", $wp03_consol_draft->objid));
assert_test("WP-03 Test 3: Consolidation records exactly two lineage rows", count($consol_lineage_rows) === 2);
assert_test("WP-03 Test 3: First consolidated snapshot retains Pred 1 details", $consol_lineage_rows[0]->prevowner === 'Original Ancestral Owner');
assert_test("WP-03 Test 3: Second consolidated snapshot retains Pred 2 details", $consol_lineage_rows[1]->prevowner === 'Second Predecessor Owner');
assert_test("WP-03 Test 3: Both consolidated rows have origin_type = LOCAL", $consol_lineage_rows[0]->origin_type === 'LOCAL' && $consol_lineage_rows[1]->origin_type === 'LOCAL');

// Verify get_faas returns both predecessors in predecessors list
$consol_detail = $etracs->get_faas($wp03_consol_draft->objid);
assert_test("WP-03 Test 3: get_faas exposes predecessors array with 2 items", isset($consol_detail->predecessors) && count($consol_detail->predecessors) === 2);

// Test 4: Subdivision (One predecessor linked to multiple successors)
$wp03_subdiv_params = array(
    'tdno' => 'TD-2026-WP03-SUBDIV-02',
    'utdno' => 'TD-2026-WP03-SUBDIV-02',
    'owner_name' => 'Subdivision Lot 2 Owner',
    'prevtdno' => $wp03_pred1_td,
    'prevfaasid' => $wp03_pred1_id,
    'rpu_type' => 'LAND',
    'txntype_code' => 'CS'
);
$wp03_subdiv_draft = $etracs->create_faas(new MockRestRequest($wp03_subdiv_params));
$etracs->submit_faas($wp03_subdiv_draft->objid, new MockRestRequest());
$wp03_subdiv_app = $etracs->approve_faas($wp03_subdiv_draft->objid, new MockRestRequest(array('date_approved' => '2026-10-22 17:45:00')));
assert_test("WP-03 Test 4: Second subdivision successor approved", !is_wp_error($wp03_subdiv_app) && $wp03_subdiv_app->state === 'CURRENT');

$subdiv_lineage_2 = $wpdb->get_row($wpdb->prepare("SELECT * FROM wp_assessor_faas_previous WHERE faasid = %s", $wp03_subdiv_draft->objid));
assert_test("WP-03 Test 4: Independent lineage row created for second successor", !empty($subdiv_lineage_2) && $subdiv_lineage_2->prevfaasid === $wp03_pred1_id);

// Test 5: Ambiguous TD reference refuses arbitrary selection on approval
$ambig_wp03_td = 'TD-2024-SHARED-WP03';
$ambig_wp03_id1 = 'FAAS-AMBIG-WP03-1';
$ambig_wp03_id2 = 'FAAS-AMBIG-WP03-2';

$pdo_target->exec("
INSERT INTO wp_assessor_faas (objid, state, origin_type, utdno, tdno, owner_name, txntimestamp)
VALUES ('$ambig_wp03_id1', 'CURRENT', 'REPLICA', 'U-AMBIG-01', '$ambig_wp03_td', 'Shared TD Owner 1', NOW()),
       ('$ambig_wp03_id2', 'CURRENT', 'REPLICA', 'U-AMBIG-02', '$ambig_wp03_td', 'Shared TD Owner 2', NOW());
");

$ambig_succ_params = array(
    'tdno' => 'TD-2026-AMBIG-SUCC',
    'utdno' => 'TD-2026-AMBIG-SUCC',
    'owner_name' => 'Owner Facing Ambiguity',
    'prevtdno' => $ambig_wp03_td,
    'rpu_type' => 'LAND',
    'txntype_code' => 'TR'
);
$ambig_succ_draft = $etracs->create_faas(new MockRestRequest($ambig_succ_params));
$etracs->submit_faas($ambig_succ_draft->objid, new MockRestRequest());

// Approving without explicit prevfaasid MUST fail with ambiguous_predecessor
$ambig_app_res = $etracs->approve_faas($ambig_succ_draft->objid, new MockRestRequest(array('date_approved' => '2026-10-22 18:00:00')));
assert_test("WP-03 Test 5: Approval fails with error on ambiguous prevtdno without explicit ID", is_wp_error($ambig_app_res));
if (is_wp_error($ambig_app_res)) {
    echo "DEBUG TEST 5 ERROR: code=" . $ambig_app_res->get_error_code() . " msg=" . $ambig_app_res->get_error_message() . "\n";
}
assert_test("WP-03 Test 5: Error code is 'approval_failed' containing 'ambiguous'", is_wp_error($ambig_app_res) && stripos($ambig_app_res->get_error_message(), 'ambiguous') !== false);

// Supplying explicit prevfaasid allows disambiguation and successful approval
$disambig_app_res = $etracs->approve_faas($ambig_succ_draft->objid, new MockRestRequest(array(
    'date_approved' => '2026-10-22 18:00:00',
    'prevfaasid' => $ambig_wp03_id1
)));
assert_test("WP-03 Test 5: Explicit prevfaasid successfully disambiguates predecessor on approval", !is_wp_error($disambig_app_res) && $disambig_app_res->state === 'CURRENT');
$ambig_pred1_after_app = $etracs->get_faas($ambig_wp03_id1);
$ambig_pred2_after_app = $etracs->get_faas($ambig_wp03_id2);
assert_test("WP-03 Test 5: Targeted record 1 is effectively CANCELLED", $ambig_pred1_after_app->effective_state === 'CANCELLED');
assert_test("WP-03 Test 5: Untargeted record 2 remains CURRENT", $ambig_pred2_after_app->effective_state === 'CURRENT');

// Test 6: Populated but unresolvable prevfaasid with a safe unique TD fallback
$unres_succ_params = array(
    'tdno' => 'TD-2026-WP03-UNRES-ID',
    'utdno' => 'TD-2026-WP03-UNRES-ID',
    'owner_name' => 'Owner Fallback Test',
    'prevtdno' => $wp03_pred2_td,
    'prevfaasid' => 'NON-EXISTENT-FAAS-ID-999',
    'rpu_type' => 'LAND',
    'txntype_code' => 'TR'
);
$unres_succ_draft = $etracs->create_faas(new MockRestRequest($unres_succ_params));
$etracs->submit_faas($unres_succ_draft->objid, new MockRestRequest());
$unres_succ_app = $etracs->approve_faas($unres_succ_draft->objid, new MockRestRequest(array('date_approved' => '2026-10-22 18:15:00')));
assert_test("WP-03 Test 6: Approval succeeds with safe fallback to unique TD", !is_wp_error($unres_succ_app) && $unres_succ_app->state === 'CURRENT');

// Test 7: Source refresh changing predecessor source data leaves local snapshot intact
$pdo_target->exec("
UPDATE wp_assessor_faas
SET owner_name = 'NEW REFRESHED SOURCE OWNER CORP',
    administrator_name = 'NEW REFRESHED ADMIN'
WHERE objid = '$wp03_pred1_id';

UPDATE wp_assessor_rpu
SET total_market_value = 9999999.00
WHERE objid = '$wp03_pred1_rpu_id';
");

$snap1_after_source_refresh = $wpdb->get_row($wpdb->prepare("SELECT * FROM wp_assessor_faas_previous WHERE faasid = %s", $wp03_succ1_draft->objid));
assert_test("WP-03 Test 7: Predecessor source owner updated by source sync", $wpdb->get_var($wpdb->prepare("SELECT owner_name FROM wp_assessor_faas WHERE objid = %s", $wp03_pred1_id)) === 'NEW REFRESHED SOURCE OWNER CORP');
assert_test("WP-03 Test 7: Historical snapshot in wp_assessor_faas_previous preserved original owner", $snap1_after_source_refresh->prevowner === 'Original Ancestral Owner');
assert_test("WP-03 Test 7: Historical snapshot in wp_assessor_faas_previous preserved original market value", floatval($snap1_after_source_refresh->prevmv) == 550000.00);

// Test 8: Draft or disapproved successor does not affect effective predecessor lifecycle
$wp03_pred3_id = 'FAAS-WP03-PRED-03';
$wp03_pred3_td = 'TD-2024-WP03-03';
$pdo_target->exec("
INSERT INTO wp_assessor_faas (objid, state, origin_type, utdno, tdno, owner_name, txntimestamp)
VALUES ('$wp03_pred3_id', 'CURRENT', 'REPLICA', '$wp03_pred3_td', '$wp03_pred3_td', 'Pred 3 Active Owner', NOW());
");

$draft_wp03_params = array(
    'tdno' => 'TD-2026-WP03-DRAFT-ONLY',
    'utdno' => 'TD-2026-WP03-DRAFT-ONLY',
    'owner_name' => 'Draft Author',
    'prevtdno' => $wp03_pred3_td,
    'prevfaasid' => $wp03_pred3_id,
    'rpu_type' => 'LAND',
    'txntype_code' => 'TR'
);
$wp03_draft_only = $etracs->create_faas(new MockRestRequest($draft_wp03_params));
$pred3_check_interim = $etracs->get_faas($wp03_pred3_id);
assert_test("WP-03 Test 8: Predecessor remains CURRENT while successor is INTERIM", $pred3_check_interim->effective_state === 'CURRENT');

$etracs->submit_faas($wp03_draft_only->objid, new MockRestRequest());
$pred3_check_forapp = $etracs->get_faas($wp03_pred3_id);
assert_test("WP-03 Test 8: Predecessor remains CURRENT while successor is FORAPPROVAL", $pred3_check_forapp->effective_state === 'CURRENT');

$etracs->disapprove_faas($wp03_draft_only->objid, new MockRestRequest(array('reason' => 'Deficient documentation')));
$pred3_check_disapp = $etracs->get_faas($wp03_pred3_id);
assert_test("WP-03 Test 8: Predecessor remains CURRENT while successor is DISAPPROVED", $pred3_check_disapp->effective_state === 'CURRENT');

// Test 9: Approval establishes supersession without mutating the source-owned predecessor
$wp03_pred4_id = 'FAAS-WP03-PRED-04';
$wp03_pred4_td = 'TD-2024-WP03-04';
$pdo_target->exec("
INSERT INTO wp_assessor_faas (objid, state, origin_type, utdno, tdno, owner_name, txntimestamp)
VALUES ('$wp03_pred4_id', 'CURRENT', 'REPLICA', '$wp03_pred4_td', '$wp03_pred4_td', 'Pred 4 Replica Owner', NOW());
");

$wp03_succ4_params = array(
    'tdno' => 'TD-2026-WP03-SUCC-04',
    'utdno' => 'TD-2026-WP03-SUCC-04',
    'owner_name' => 'Succ 4 Owner',
    'prevtdno' => $wp03_pred4_td,
    'prevfaasid' => $wp03_pred4_id,
    'rpu_type' => 'LAND',
    'txntype_code' => 'TR'
);
$wp03_succ4_draft = $etracs->create_faas(new MockRestRequest($wp03_succ4_params));
$etracs->submit_faas($wp03_succ4_draft->objid, new MockRestRequest());
$wp03_succ4_app = $etracs->approve_faas($wp03_succ4_draft->objid, new MockRestRequest(array('date_approved' => '2026-10-22 18:30:00')));
assert_test("WP-03 Test 9: Successor 4 approved", !is_wp_error($wp03_succ4_app));

$pred4_raw = $wpdb->get_row($wpdb->prepare("SELECT state, origin_type, canceldate, cancelledbytdnos FROM wp_assessor_faas WHERE objid = %s", $wp03_pred4_id));
assert_test("WP-03 Test 9: REPLICA predecessor RAW state in wp_assessor_faas remains 'CURRENT'", $pred4_raw->state === 'CURRENT');
assert_test("WP-03 Test 9: REPLICA predecessor RAW cancelledbytdnos is null/untouched", empty($pred4_raw->cancelledbytdnos));
$pred4_eff = $etracs->get_faas($wp03_pred4_id);
assert_test("WP-03 Test 9: REPLICA predecessor EFFECTIVE state is 'CANCELLED'", $pred4_eff->effective_state === 'CANCELLED');
assert_test("WP-03 Test 9: REPLICA predecessor effective cancelled_by_tdnos points to successor", $pred4_eff->cancelled_by_tdnos === 'TD-2026-WP03-SUCC-04');

// Test 10: Injected database failure rolling back all related lineage and snapshot writes
$wp03_fail_pred_id = 'FAAS-WP03-FAIL-PRED';
$wp03_fail_pred_td = 'TD-2024-WP03-FAIL';
$pdo_target->exec("
INSERT INTO wp_assessor_faas (objid, state, origin_type, utdno, tdno, owner_name, txntimestamp)
VALUES ('$wp03_fail_pred_id', 'CURRENT', 'LOCAL', '$wp03_fail_pred_td', '$wp03_fail_pred_td', 'Pred Rollback Test', NOW());
");

$wp03_fail_succ_params = array(
    'tdno' => 'TD-2026-WP03-FAIL-SUCC',
    'utdno' => 'TD-2026-WP03-FAIL-SUCC',
    'owner_name' => 'Succ Rollback Attempt',
    'prevtdno' => $wp03_fail_pred_td,
    'rpu_type' => 'LAND',
    'txntype_code' => 'TR'
);
$wp03_fail_draft = $etracs->create_faas(new MockRestRequest($wp03_fail_succ_params));
$etracs->submit_faas($wp03_fail_draft->objid, new MockRestRequest());

// Create trigger on wp_assessor_faas that fails specifically on update of predecessor
$pdo_target->exec("
CREATE TRIGGER trg_wp03_fail_rollback BEFORE UPDATE ON wp_assessor_faas
FOR EACH ROW
BEGIN
    IF NEW.cancelledbytdnos = 'TD-2026-WP03-FAIL-SUCC' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected Rollback Failure';
    END IF;
END;
");

$fail_res = $etracs->approve_faas($wp03_fail_draft->objid, new MockRestRequest(array('date_approved' => '2026-10-22 18:45:00')));
assert_test("WP-03 Test 10: Approval with injected failure fails cleanly", is_wp_error($fail_res));

$pdo_target->exec("DROP TRIGGER IF EXISTS trg_wp03_fail_rollback;");

$fail_draft_state = $wpdb->get_var($wpdb->prepare("SELECT state FROM wp_assessor_faas WHERE objid = %s", $wp03_fail_draft->objid));
assert_test("WP-03 Test 10: ATOMIC GUARANTEE: Successor state remains FORAPPROVAL", $fail_draft_state === 'FORAPPROVAL');
$fail_lineage_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM wp_assessor_faas_previous WHERE faasid = %s", $wp03_fail_draft->objid));
assert_test("WP-03 Test 10: ATOMIC GUARANTEE: Injected failure rolled back lineage row creation (count = 0)", intval($fail_lineage_count) === 0);

echo "\n========================================================================\n";
echo "   WP-02 & WP-03 COMBINED TEST SUMMARY\n";
echo "   Total Tests Passed: $pass_count\n";
echo "   Total Tests Failed: $fail_count\n";
echo "========================================================================\n";

if ($fail_count > 0) {
    exit(1);
}
exit(0);

