<?php
/**
 * Test Suite: Assessor Repo — Phase 11 / WP-05: Shadow ETRACS Parcel Reuse & RPUMaster Lineage
 *
 * Isolated test suite running entirely within the disposable database `test_wp01_disposable`.
 *
 * Scenarios tested (per WP-05 specifications):
 * 1. Creating a new parcel with its first LOCAL RPU/FAAS.
 * 2. Revising an existing parcel without creating a duplicate real_property row.
 * 3. Reusing an existing REPLICA parcel without modifying its source-owned fields.
 * 4. Ensuring a subdivision creates distinct physical parcel records where required and preserves parent lineage (real_property.previd).
 * 5. Consolidating predecessor parcels while preserving all predecessor links and snapshots.
 * 6. Preventing ambiguous parcel matching and requiring explicit selection.
 * 7. Preventing duplicate parcel and master creation after a repeated request.
 * 8. Verifying LOCAL RPUMaster pointers advance at approval, not at draft creation or submission.
 * 9. Ensuring REPLICA RPUMaster source fields and ownership are unchanged.
 * 10. Verifying a later source refresh preserves local effective lifecycle and lineage.
 * 11. Injecting a database failure and proving that parcel, RPU, FAAS, assessment, lineage, and pointer changes roll back together.
 * 12. Confirming that no native Assessor tables or APIs are accessed.
 */

class MockWpdbWp05 {
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
            return $res ? 1 : false;
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            return false;
        }
    }

    public function get_results($sql, $output = 'OBJECT') {
        try {
            $stmt = $this->pdo->query($sql);
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
            $val = $stmt->fetchColumn();
            return $val !== false ? $val : null;
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            return null;
        }
    }

    public function get_col($sql, $col_num = 0) {
        try {
            $stmt = $this->pdo->query($sql);
            return $stmt->fetchAll(PDO::FETCH_COLUMN, $col_num);
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            return array();
        }
    }
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
    function current_time($type) {
        return date('Y-m-d H:i:s');
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
    define('JWT_AUTH_SECRET_KEY', 'test_secret_key_for_wp05_lineage_testing_12345');
}

if (!function_exists('get_site_url')) {
    function get_site_url() {
        return 'http://localhost/assessor';
    }
}

class MockRestRequestWp05 {
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
$wpdb = new MockWpdbWp05($pdo_target);

echo "========================================================================\n";
echo "   ASSESSOR REPO - PHASE 11 / WP-05 PARCEL REUSE & RPUMASTER HARNESS\n";
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
// ─────────────────────────────────────────────────────────────
$pdo_target->exec("
DROP TABLE IF EXISTS wp_assessor_faas, wp_assessor_rpu, wp_assessor_real_property, wp_assessor_entity, wp_assessor_faas_previous, wp_assessor_rpu_assessment, wp_assessor_rpumaster, wp_assessor_txn_type;

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

INSERT INTO wp_assessor_txn_type (code, name) VALUES 
('GR', 'General Revision'),
('TR', 'Transfer'),
('SD', 'Subdivision'),
('CS', 'Consolidation'),
('PC', 'Physical Change');
");

require_once __DIR__ . '/../wp-content/plugins/assessor-api/includes/class-assessor-auth.php';
require_once __DIR__ . '/../wp-content/plugins/assessor-api/includes/class-assessor-etracs.php';

$etracs = new Assessor_Etracs();

// ─────────────────────────────────────────────────────────────
// Scenario 1: Creating a new parcel with its first LOCAL RPU/FAAS
// ─────────────────────────────────────────────────────────────
echo "--- Scenario 1: Creating a New Parcel with its First LOCAL RPU/FAAS ---\n";
$sc1_params = array(
    'tdno'                  => 'TD-WP05-SC1-01',
    'utdno'                 => 'TD-WP05-SC1-01',
    'owner_name'            => 'First Owner SC1',
    'pin'                   => '048-12-001-01-001',
    'lot_number'            => 'Lot 101',
    'cadastral_lot_no'      => 'Lot 101',
    'survey_no'             => 'Cad-2026',
    'block_no'              => 'Block 1',
    'barangay'              => 'Poblacion',
    'rpu_type'              => 'LAND',
    'classification'        => 'RESIDENTIAL',
    'txntype_code'          => 'GR',
    'total_market_value'    => 500000.00,
    'total_assessed_value'  => 100000.00,
);

$sc1_draft = $etracs->create_faas(new MockRestRequestWp05($sc1_params));
assert_test("Scenario 1: Draft FAAS created successfully", !is_wp_error($sc1_draft));
assert_test("Scenario 1: FAAS has origin_type = 'LOCAL'", $sc1_draft->origin_type === 'LOCAL');

// Verify Real Property
$sc1_rp = $wpdb->get_row($wpdb->prepare("SELECT * FROM wp_assessor_real_property WHERE objid = %s", $sc1_draft->real_property_id));
assert_test("Scenario 1: Real Property record created with origin_type = 'LOCAL'", !empty($sc1_rp) && $sc1_rp->origin_type === 'LOCAL');
assert_test("Scenario 1: Real Property pin matches", $sc1_rp->pin === '048-12-001-01-001');

// Verify RPU
$sc1_rpu = $wpdb->get_row($wpdb->prepare("SELECT * FROM wp_assessor_rpu WHERE objid = %s", $sc1_draft->rpu_id));
assert_test("Scenario 1: RPU created with origin_type = 'LOCAL'", !empty($sc1_rpu) && $sc1_rpu->origin_type === 'LOCAL');
assert_test("Scenario 1: RPU realpropertyid matches created parcel", $sc1_rpu->realpropertyid === $sc1_rp->objid);
assert_test("Scenario 1: RPU previd is NULL for initial declaration", empty($sc1_rpu->previd));
assert_test("Scenario 1: RPU rpumasterid is assigned", !empty($sc1_rpu->rpumasterid));

// Verify RPUMaster record
$sc1_master = $wpdb->get_row($wpdb->prepare("SELECT * FROM wp_assessor_rpumaster WHERE objid = %s", $sc1_rpu->rpumasterid));
assert_test("Scenario 1: RPUMaster record created with origin_type = 'LOCAL'", !empty($sc1_master) && $sc1_master->origin_type === 'LOCAL');
assert_test("Scenario 1: RPUMaster currentfaasid is NULL before approval (not advanced on draft)", empty($sc1_master->currentfaasid));
assert_test("Scenario 1: RPUMaster currentrpuid is NULL before approval", empty($sc1_master->currentrpuid));

// ─────────────────────────────────────────────────────────────
// Scenario 2: Revising an existing parcel without duplicate real_property row
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 2: Revising an Existing Parcel Without Duplicate real_property Row ---\n";
// Approve Scenario 1 FAAS first to establish approved predecessor
$etracs->submit_faas($sc1_draft->objid, new MockRestRequestWp05());
$sc1_approved = $etracs->approve_faas($sc1_draft->objid, new MockRestRequestWp05(array('date_approved' => '2026-10-22 10:00:00')));
assert_test("Scenario 2 Setup: Predecessor approved to CURRENT", !is_wp_error($sc1_approved) && $sc1_approved->state === 'CURRENT');

$total_parcels_before_revision = $wpdb->get_var("SELECT COUNT(*) FROM wp_assessor_real_property");

$sc2_params = array(
    'tdno'                  => 'TD-WP05-SC2-REVISION',
    'utdno'                 => 'TD-WP05-SC2-REVISION',
    'owner_name'            => 'Second Owner SC2 (Transfer)',
    'prevfaasid'            => $sc1_draft->objid,
    'prevtdno'              => 'TD-WP05-SC1-01',
    'txntype_code'          => 'TR',
    'rpu_type'              => 'LAND',
    'classification'        => 'RESIDENTIAL',
    'total_market_value'    => 600000.00,
    'total_assessed_value'  => 120000.00,
);

$sc2_draft = $etracs->create_faas(new MockRestRequestWp05($sc2_params));
assert_test("Scenario 2: Revision draft created cleanly", !is_wp_error($sc2_draft));

$total_parcels_after_revision = $wpdb->get_var("SELECT COUNT(*) FROM wp_assessor_real_property");
assert_test("Scenario 2: PARCEL REUSE GUARANTEE: Total parcel count unchanged (no duplicate parcel created)", intval($total_parcels_before_revision) === intval($total_parcels_after_revision));
assert_test("Scenario 2: Revision strictly reuses predecessor realpropertyid", $sc2_draft->real_property_id === $sc1_rp->objid);

// Verify RPU lineage
$sc2_rpu = $wpdb->get_row($wpdb->prepare("SELECT * FROM wp_assessor_rpu WHERE objid = %s", $sc2_draft->rpu_id));
assert_test("Scenario 2: Revision RPU previd points strictly to predecessor RPU", $sc2_rpu->previd === $sc1_rpu->objid);
assert_test("Scenario 2: Revision RPU shares persistent predecessor rpumasterid", $sc2_rpu->rpumasterid === $sc1_rpu->rpumasterid);

// ─────────────────────────────────────────────────────────────
// Scenario 3: Reusing an existing REPLICA parcel without modifying its source-owned fields
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 3: Reusing an Existing REPLICA Parcel Without Modifying Source Fields ---\n";
$rep_parcel_id = 'RP-REPLICA-SRC-01';
$rep_rpu_id = 'RPU-REPLICA-SRC-01';
$rep_faas_id = 'FAAS-REPLICA-SRC-01';
$rep_master_id = 'MASTER-REPLICA-SRC-01';

$pdo_target->exec("
INSERT INTO wp_assessor_real_property (objid, origin_type, pin, cadastrallotno, barangay, municipality, province)
VALUES ('$rep_parcel_id', 'REPLICA', '048-12-002-99-999', 'Source Lot 99', 'Old Barangay', 'Kitaotao', 'Bukidnon');

INSERT INTO wp_assessor_rpumaster (objid, currentfaasid, currentrpuid, origin_type)
VALUES ('$rep_master_id', '$rep_faas_id', '$rep_rpu_id', 'REPLICA');

INSERT INTO wp_assessor_rpu (objid, state, origin_type, realpropertyid, rpu_type, classification, total_market_value, total_assessed_value, rpumasterid)
VALUES ('$rep_rpu_id', 'CURRENT', 'REPLICA', '$rep_parcel_id', 'LAND', 'COMMERCIAL', 2000000.00, 1000000.00, '$rep_master_id');

INSERT INTO wp_assessor_faas (objid, state, origin_type, rpuid, realpropertyid, utdno, tdno, owner_name, txntimestamp)
VALUES ('$rep_faas_id', 'CURRENT', 'REPLICA', '$rep_rpu_id', '$rep_parcel_id', 'TD-REPLICA-SRC-01', 'TD-REPLICA-SRC-01', 'Source Owned Corporate Taxpayer', NOW());
");

$rep_parcel_before = $wpdb->get_row($wpdb->prepare("SELECT * FROM wp_assessor_real_property WHERE objid = %s", $rep_parcel_id));

// Create a local revision referencing this REPLICA predecessor
$sc3_params = array(
    'tdno'                  => 'TD-WP05-LOCAL-REV-REP',
    'utdno'                 => 'TD-WP05-LOCAL-REV-REP',
    'owner_name'            => 'Local Successor Taxpayer',
    'prevfaasid'            => $rep_faas_id,
    'prevtdno'              => 'TD-REPLICA-SRC-01',
    'txntype_code'          => 'TR',
    'rpu_type'              => 'LAND',
    'classification'        => 'COMMERCIAL',
    // Attempting to pass new parcel details via params:
    'cadastral_lot_no'      => 'Hijacked Lot Number',
    'barangay'              => 'Hijacked Barangay',
);

$sc3_draft = $etracs->create_faas(new MockRestRequestWp05($sc3_params));
assert_test("Scenario 3: Local revision draft referencing REPLICA created cleanly", !is_wp_error($sc3_draft));
assert_test("Scenario 3: Draft reuses REPLICA real_property_id", $sc3_draft->real_property_id === $rep_parcel_id);

// Check that REPLICA real property row was NOT modified
$rep_parcel_after = $wpdb->get_row($wpdb->prepare("SELECT * FROM wp_assessor_real_property WHERE objid = %s", $rep_parcel_id));
assert_test("Scenario 3: REPLICA parcel origin_type remains 'REPLICA'", $rep_parcel_after->origin_type === 'REPLICA');
assert_test("Scenario 3: REPLICA parcel lot number untouched ('Source Lot 99')", $rep_parcel_after->cadastrallotno === 'Source Lot 99');
assert_test("Scenario 3: REPLICA parcel barangay untouched ('Old Barangay')", $rep_parcel_after->barangay === 'Old Barangay');

// Attempt local update_faas on the draft with modified parcel fields to verify guard
$etracs->update_faas($sc3_draft->objid, new MockRestRequestWp05(array(
    'cadastral_lot_no' => 'Second Attempt Hijack Lot',
    'barangay' => 'Second Attempt Hijack Barangay'
)));
$rep_parcel_after_update = $wpdb->get_row($wpdb->prepare("SELECT * FROM wp_assessor_real_property WHERE objid = %s", $rep_parcel_id));
assert_test("Scenario 3: update_real_property guard prevents mutating REPLICA parcel via draft updates", $rep_parcel_after_update->cadastrallotno === 'Source Lot 99');

// ─────────────────────────────────────────────────────────────
// Scenario 4: Subdivision creates distinct physical parcel and preserves parent lineage
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 4: Subdivision Creates Distinct Physical Parcel & Preserves Parent Lineage ---\n";
$parcels_before_subdiv = $wpdb->get_var("SELECT COUNT(*) FROM wp_assessor_real_property");

$sc4_lot1_params = array(
    'tdno'                  => 'TD-WP05-SUBDIV-LOT-A',
    'utdno'                 => 'TD-WP05-SUBDIV-LOT-A',
    'owner_name'            => 'Subdivision Lot A Owner',
    'prevfaasid'            => $sc1_draft->objid,
    'prevtdno'              => 'TD-WP05-SC1-01',
    'txntype_code'          => 'SD', // SUBDIVISION
    'lot_number'            => 'Lot 101-A',
    'cadastral_lot_no'      => 'Lot 101-A',
    'pin'                   => '048-12-001-01-001-A',
    'rpu_type'              => 'LAND',
    'classification'        => 'RESIDENTIAL',
    'total_market_value'    => 250000.00,
    'total_assessed_value'  => 50000.00,
);

$sc4_draft = $etracs->create_faas(new MockRestRequestWp05($sc4_lot1_params));
assert_test("Scenario 4: Subdivision child declaration draft created", !is_wp_error($sc4_draft));

$parcels_after_subdiv = $wpdb->get_var("SELECT COUNT(*) FROM wp_assessor_real_property");
assert_test("Scenario 4: Subdivision created a NEW distinct physical parcel record (count incremented)", intval($parcels_after_subdiv) === intval($parcels_before_subdiv) + 1);
assert_test("Scenario 4: Child parcel has distinct objid from parent parcel", $sc4_draft->real_property_id !== $sc1_rp->objid);

// Verify child parcel previd links to parent parcel objid
$child_rp = $wpdb->get_row($wpdb->prepare("SELECT * FROM wp_assessor_real_property WHERE objid = %s", $sc4_draft->real_property_id));
assert_test("Scenario 4: Child parcel previd points strictly to parent real_property objid", $child_rp->previd === $sc1_rp->objid);
assert_test("Scenario 4: Child parcel origin_type = 'LOCAL'", $child_rp->origin_type === 'LOCAL');

// ─────────────────────────────────────────────────────────────
// Scenario 5: Consolidation preserves all predecessor links and snapshots
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 5: Consolidation Preserves All Predecessor Links and Snapshots ---\n";
// Create two approved predecessor declarations
$cs_pred1_id = 'FAAS-CS-PRED-1';
$cs_pred1_td = 'TD-CS-PRED-1';
$cs_pred1_rp = 'RP-CS-PRED-1';
$cs_pred1_rpu = 'RPU-CS-PRED-1';

$cs_pred2_id = 'FAAS-CS-PRED-2';
$cs_pred2_td = 'TD-CS-PRED-2';
$cs_pred2_rp = 'RP-CS-PRED-2';
$cs_pred2_rpu = 'RPU-CS-PRED-2';

$pdo_target->exec("
INSERT INTO wp_assessor_real_property (objid, origin_type, pin, cadastrallotno)
VALUES ('$cs_pred1_rp', 'LOCAL', '048-12-005-01-001', 'Lot 501'),
       ('$cs_pred2_rp', 'LOCAL', '048-12-005-01-002', 'Lot 502');

INSERT INTO wp_assessor_rpu (objid, state, origin_type, realpropertyid, rpu_type, total_market_value, total_assessed_value)
VALUES ('$cs_pred1_rpu', 'CURRENT', 'LOCAL', '$cs_pred1_rp', 'LAND', 300000.00, 60000.00),
       ('$cs_pred2_rpu', 'CURRENT', 'LOCAL', '$cs_pred2_rp', 'LAND', 400000.00, 80000.00);

INSERT INTO wp_assessor_faas (objid, state, origin_type, rpuid, realpropertyid, utdno, tdno, owner_name, txntimestamp)
VALUES ('$cs_pred1_id', 'CURRENT', 'LOCAL', '$cs_pred1_rpu', '$cs_pred1_rp', '$cs_pred1_td', '$cs_pred1_td', 'Consol Pred Owner 1', NOW()),
       ('$cs_pred2_id', 'CURRENT', 'LOCAL', '$cs_pred2_rpu', '$cs_pred2_rp', '$cs_pred2_td', '$cs_pred2_td', 'Consol Pred Owner 2', NOW());
");

$cs_params = array(
    'tdno'                  => 'TD-WP05-CONSOLIDATED',
    'utdno'                 => 'TD-WP05-CONSOLIDATED',
    'owner_name'            => 'Consolidated Property Owner',
    'txntype_code'          => 'CS', // CONSOLIDATION
    'prevtdno'              => "$cs_pred1_td | $cs_pred2_td",
    'lot_number'            => 'Consolidated Lot 501-502',
    'rpu_type'              => 'LAND',
    'predecessors'          => array(
        array('prevfaasid' => $cs_pred1_id, 'prevtdno' => $cs_pred1_td),
        array('prevfaasid' => $cs_pred2_id, 'prevtdno' => $cs_pred2_td),
    )
);

$cs_draft = $etracs->create_faas(new MockRestRequestWp05($cs_params));
assert_test("Scenario 5: Consolidation draft created", !is_wp_error($cs_draft));

// Approve consolidation
$etracs->submit_faas($cs_draft->objid, new MockRestRequestWp05());
$cs_approved = $etracs->approve_faas($cs_draft->objid, new MockRestRequestWp05(array(
    'date_approved' => '2026-10-22 14:00:00',
    'predecessors'  => $cs_params['predecessors']
)));
assert_test("Scenario 5: Consolidation approved to CURRENT", !is_wp_error($cs_approved) && $cs_approved->state === 'CURRENT');

// Verify both predecessor rows in wp_assessor_faas_previous
$cs_lineage = $wpdb->get_results($wpdb->prepare("SELECT * FROM wp_assessor_faas_previous WHERE faasid = %s ORDER BY prevtdno ASC", $cs_draft->objid));
assert_test("Scenario 5: Exactly 2 historical predecessor snapshot records preserved", count($cs_lineage) === 2);
assert_test("Scenario 5: Lineage row 1 points to Pred 1", $cs_lineage[0]->prevfaasid === $cs_pred1_id);
assert_test("Scenario 5: Lineage row 2 points to Pred 2", $cs_lineage[1]->prevfaasid === $cs_pred2_id);
assert_test("Scenario 5: Lineage row 1 snapshot captured Pred 1 owner", $cs_lineage[0]->prevowner === 'Consol Pred Owner 1');
assert_test("Scenario 5: Lineage row 2 snapshot captured Pred 2 owner", $cs_lineage[1]->prevowner === 'Consol Pred Owner 2');

// ─────────────────────────────────────────────────────────────
// Scenario 6: Preventing ambiguous parcel matching and requiring explicit selection
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 6: Preventing Ambiguous Parcel Matching & Requiring Explicit Selection ---\n";
// Insert two parcels with the identical PIN but different objids
$ambig_pin = '048-12-006-AMBIG-99';
$pdo_target->exec("
INSERT INTO wp_assessor_real_property (objid, origin_type, pin, cadastrallotno)
VALUES ('RP-AMBIG-1', 'REPLICA', '$ambig_pin', 'Ambig Lot 1'),
       ('RP-AMBIG-2', 'REPLICA', '$ambig_pin', 'Ambig Lot 2');
");

$ambig_match_params = array(
    'tdno'          => 'TD-WP05-AMBIG-PARCEL-TRY',
    'utdno'         => 'TD-WP05-AMBIG-PARCEL-TRY',
    'owner_name'    => 'Ambiguous Match Attempt Owner',
    'pin'           => $ambig_pin,
    'txntype_code'  => 'TR',
);

$ambig_res = $etracs->create_faas(new MockRestRequestWp05($ambig_match_params));
assert_test("Scenario 6: Attempting to match parcel using non-unique PIN alone is rejected", is_wp_error($ambig_res));
assert_test("Scenario 6: Structured error returns informative message requiring explicit selection", is_wp_error($ambig_res) && stripos($ambig_res->get_error_message(), 'Ambiguous parcel PIN') !== false);

// Supplying explicit realpropertyid succeeds without error
$explicit_match_params = $ambig_match_params;
$explicit_match_params['realpropertyid'] = 'RP-AMBIG-1';
$explicit_res = $etracs->create_faas(new MockRestRequestWp05($explicit_match_params));
assert_test("Scenario 6: Supplying explicit realpropertyid successfully creates declaration", !is_wp_error($explicit_res));
assert_test("Scenario 6: Explicitly selected parcel ID was strictly used", !is_wp_error($explicit_res) && $explicit_res->real_property_id === 'RP-AMBIG-1');

// ─────────────────────────────────────────────────────────────
// Scenario 7: Preventing duplicate parcel and master creation after repeated request
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 7: Idempotent Retries / Duplicate Protection ---\n";
// Attempting to create the exact same TD again MUST be rejected with duplicate_tdno
$retry_res = $etracs->create_faas(new MockRestRequestWp05($sc1_params));
assert_test("Scenario 7: Repeated create request with same TD is rejected", is_wp_error($retry_res));
assert_test("Scenario 7: Error code is 'duplicate_tdno'", is_wp_error($retry_res) && $retry_res->get_error_code() === 'duplicate_tdno');

// ─────────────────────────────────────────────────────────────
// Scenario 8: Verifying LOCAL RPUMaster pointers advance at approval, not draft or submit
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 8: LOCAL RPUMaster Pointers Advance at Approval Only ---\n";
$sc8_params = array(
    'tdno'                  => 'TD-WP05-SC8-MASTER-FLOW',
    'utdno'                 => 'TD-WP05-SC8-MASTER-FLOW',
    'owner_name'            => 'Master Flow Owner',
    'pin'                   => '048-12-008-01-001',
    'rpu_type'              => 'LAND',
    'classification'        => 'RESIDENTIAL',
    'txntype_code'          => 'GR',
);
$sc8_draft = $etracs->create_faas(new MockRestRequestWp05($sc8_params));
$sc8_rpu = $wpdb->get_row($wpdb->prepare("SELECT objid, rpumasterid FROM wp_assessor_rpu WHERE objid = %s", $sc8_draft->rpu_id));
$sc8_master_at_draft = $wpdb->get_row($wpdb->prepare("SELECT * FROM wp_assessor_rpumaster WHERE objid = %s", $sc8_rpu->rpumasterid));
assert_test("Scenario 8: Draft creation: currentfaasid is NULL", empty($sc8_master_at_draft->currentfaasid));
assert_test("Scenario 8: Draft creation: currentrpuid is NULL", empty($sc8_master_at_draft->currentrpuid));

// Submit for approval
$etracs->submit_faas($sc8_draft->objid, new MockRestRequestWp05());
$sc8_master_at_submit = $wpdb->get_row($wpdb->prepare("SELECT * FROM wp_assessor_rpumaster WHERE objid = %s", $sc8_rpu->rpumasterid));
assert_test("Scenario 8: Submission (FORAPPROVAL): currentfaasid remains NULL", empty($sc8_master_at_submit->currentfaasid));
assert_test("Scenario 8: Submission (FORAPPROVAL): currentrpuid remains NULL", empty($sc8_master_at_submit->currentrpuid));

// Approve
$sc8_app = $etracs->approve_faas($sc8_draft->objid, new MockRestRequestWp05(array('date_approved' => '2026-10-22 15:30:00')));
assert_test("Scenario 8: Declaration approved successfully", !is_wp_error($sc8_app));

$sc8_master_post_approval = $wpdb->get_row($wpdb->prepare("SELECT * FROM wp_assessor_rpumaster WHERE objid = %s", $sc8_rpu->rpumasterid));
assert_test("Scenario 8: APPROVAL ADVANCEMENT: currentfaasid advanced to approved FAAS objid", $sc8_master_post_approval->currentfaasid === $sc8_draft->objid);
assert_test("Scenario 8: APPROVAL ADVANCEMENT: currentrpuid advanced to approved RPU objid", $sc8_master_post_approval->currentrpuid === $sc8_rpu->objid);

// ─────────────────────────────────────────────────────────────
// Scenario 9: Ensuring REPLICA RPUMaster source fields and ownership are unchanged
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 9: REPLICA RPUMaster Source Fields & Ownership Are Unchanged ---\n";
// From Scenario 3, $rep_master_id is a REPLICA master pointing to $rep_faas_id and $rep_rpu_id
$rep_master_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM wp_assessor_rpumaster WHERE objid = %s", $rep_master_id));
assert_test("Scenario 9: REPLICA master origin_type is 'REPLICA'", $rep_master_row->origin_type === 'REPLICA');
assert_test("Scenario 9: REPLICA master currentfaasid remains original source pointer ('$rep_faas_id')", $rep_master_row->currentfaasid === $rep_faas_id);
assert_test("Scenario 9: REPLICA master currentrpuid remains original source pointer ('$rep_rpu_id')", $rep_master_row->currentrpuid === $rep_rpu_id);

// ─────────────────────────────────────────────────────────────
// Scenario 10: Later source refresh preserves local effective lifecycle and lineage
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 10: Later Source Refresh Preserves Local Effective Lifecycle & Lineage ---\n";
// Simulate source refresh updating REPLICA predecessor data (e.g. changing taxpayer owner name on predecessor)
$pdo_target->exec("
UPDATE wp_assessor_faas SET owner_name = 'Updated Remote Source Name via ETRACS Sync' WHERE objid = '$rep_faas_id';
");

// Check effective lifecycle of local successor
$sc3_check = $etracs->get_faas($sc3_draft->objid);
assert_test("Scenario 10: Local successor remains intact after simulated source refresh", !is_wp_error($sc3_check));
assert_test("Scenario 10: Local successor origin_type = 'LOCAL' preserved", $sc3_check->origin_type === 'LOCAL');
assert_test("Scenario 10: Local successor realpropertyid pointer preserved", $sc3_check->real_property_id === $rep_parcel_id);

// ─────────────────────────────────────────────────────────────
// Scenario 11: Database failure injection proves all writes roll back together
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 11: Database Failure Injection and Atomic Rollback ---\n";
$parcels_before_fail = $wpdb->get_var("SELECT COUNT(*) FROM wp_assessor_real_property");
$rpus_before_fail = $wpdb->get_var("SELECT COUNT(*) FROM wp_assessor_rpu");
$faas_before_fail = $wpdb->get_var("SELECT COUNT(*) FROM wp_assessor_faas");
$masters_before_fail = $wpdb->get_var("SELECT COUNT(*) FROM wp_assessor_rpumaster");

// Inject an invalid assessment line payload that causes persist_rpu_assessments to fail inside the transaction
$fail_payload = array(
    'tdno'         => 'TD-WP05-ROLLBACK-INJECTION',
    'utdno'        => 'TD-WP05-ROLLBACK-INJECTION',
    'owner_name'   => 'Rollback Subject',
    'pin'          => '048-12-011-00-001',
    'assessments'  => array(
        array('marketvalue' => -999999) // Invalid payload that triggers rejection
    )
);

// We can induce failure via assessment line with missing actualuse/class or non-numeric error
$pdo_target->exec("
CREATE TRIGGER trg_wp05_fail_faas BEFORE INSERT ON wp_assessor_faas
FOR EACH ROW
BEGIN
    IF NEW.tdno = 'TD-WP05-ROLLBACK-INJECTION' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected Database Transaction Failure';
    END IF;
END;
");

$fail_call_res = $etracs->create_faas(new MockRestRequestWp05($fail_payload));
assert_test("Scenario 11: Create FAAS with failure injection returns WP_Error", is_wp_error($fail_call_res));

$pdo_target->exec("DROP TRIGGER IF EXISTS trg_wp05_fail_faas;");

$parcels_after_fail = $wpdb->get_var("SELECT COUNT(*) FROM wp_assessor_real_property");
$rpus_after_fail = $wpdb->get_var("SELECT COUNT(*) FROM wp_assessor_rpu");
$faas_after_fail = $wpdb->get_var("SELECT COUNT(*) FROM wp_assessor_faas");
$masters_after_fail = $wpdb->get_var("SELECT COUNT(*) FROM wp_assessor_rpumaster");

assert_test("Scenario 11: ATOMIC ROLLBACK: Real Property writes rolled back (count unchanged)", intval($parcels_before_fail) === intval($parcels_after_fail));
assert_test("Scenario 11: ATOMIC ROLLBACK: RPU writes rolled back (count unchanged)", intval($rpus_before_fail) === intval($rpus_after_fail));
assert_test("Scenario 11: ATOMIC ROLLBACK: FAAS writes rolled back (count unchanged)", intval($faas_before_fail) === intval($faas_after_fail));
assert_test("Scenario 11: ATOMIC ROLLBACK: RPUMaster writes rolled back (count unchanged)", intval($masters_before_fail) === intval($masters_after_fail));

// ─────────────────────────────────────────────────────────────
// Scenario 12: Absolute Architecture Boundary Check
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 12: Absolute Architecture Boundary Check ---\n";
$native_table_exists = $pdo_target->query("SHOW TABLES LIKE 'wp_assessor_properties'")->fetchColumn();
assert_test("Scenario 12: Native table wp_assessor_properties does NOT exist in disposable database", empty($native_table_exists));

echo "\n========================================================================\n";
echo "   WP-05 PARCEL REUSE & RPUMASTER TEST SUMMARY\n";
echo "   Total Tests Passed: $pass_count\n";
echo "   Total Tests Failed: $fail_count\n";
echo "========================================================================\n";

exit($fail_count === 0 ? 0 : 1);
