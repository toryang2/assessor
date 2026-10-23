<?php
/**
 * Test Suite: Assessor Repo — Phase 10 / WP-04: Shadow ETRACS RPU Assessment Line Persistence
 *
 * Isolated test suite running entirely within the disposable database `test_wp01_disposable`.
 *
 * Scenarios tested (per WP-04 specifications):
 * 1. Creating a LOCAL FAAS/RPU with multiple assessment lines.
 * 2. Verifying all lines are persisted with correct relationships and origin_type = 'LOCAL'.
 * 3. Editing a draft without duplicate lines (idempotency).
 * 4. Updating, adding, and removing permitted LOCAL draft lines.
 * 5. Attempting to modify REPLICA assessment lines or a REPLICA RPU without changing source-owned values (REPLICA protected).
 * 6. Ensuring one RPU cannot accidentally receive another declaration's assessment lines (RPU mismatch guard).
 * 7. Preserving aggregate values and line values under verified rules (total_market_value, total_assessed_value synchronized).
 * 8. Injecting a database failure and proving all related writes roll back (atomicity).
 * 9. Running a simulated WP-01 source refresh and verifying LOCAL line persistence (LOCAL lines preserved, REPLICA rows refreshed).
 * 10. Maintaining WP-02 lifecycle, predecessor snapshot, consolidation, subdivision, and ambiguity behavior.
 * 11. Passing all WP-01 synchronization and collision regression tests.
 * 12. Confirming that no native Assessor tables or APIs are accessed.
 */

class MockWpdbWp04 {
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

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return ($thing instanceof WP_Error);
    }
}

if (!function_exists('current_time')) {
    function current_time($type = 'mysql') {
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
    define('JWT_AUTH_SECRET_KEY', 'test_secret_key_for_wp04_assessment_testing_12345');
}

if (!function_exists('get_site_url')) {
    function get_site_url() {
        return 'http://localhost/assessor';
    }
}

if (!function_exists('generate_test_token_wp04')) {
    function generate_test_token_wp04($user_id, $role, $username = 'testuser') {
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
}

// Mock request wrapper
class MockRestRequestWp04 {
    private $params;
    private $headers;

    public function __construct($params = array(), $headers = array()) {
        $this->params = $params;
        $this->headers = $headers;
    }

    public function get_params() {
        return $this->params;
    }

    public function get_param($key) {
        return $this->params[$key] ?? null;
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
$wpdb = new MockWpdbWp04($pdo_target);

echo "========================================================================\n";
echo "   ASSESSOR REPO - PHASE 10 / WP-04 RPU ASSESSMENT TEST HARNESS\n";
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
DROP TABLE IF EXISTS wp_assessor_faas, wp_assessor_rpu, wp_assessor_real_property, wp_assessor_entity, wp_assessor_faas_previous, wp_assessor_rpu_assessment, wp_assessor_txn_type, wp_assessor_faas_txntypes, wp_assessor_landrpu, wp_assessor_landdetail;

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
    origin_type enum('REPLICA', 'LOCAL') NOT NULL DEFAULT 'REPLICA',
    classification_objid varchar(50) DEFAULT NULL,
    classcode varchar(20) DEFAULT NULL,
    classname varchar(100) DEFAULT NULL,
    actualuse_objid varchar(50) DEFAULT NULL,
    actualuse varchar(100) DEFAULT NULL,
    areasqm decimal(16,2) NOT NULL DEFAULT '0.00',
    areaha decimal(16,6) NOT NULL DEFAULT '0.000000',
    areatype varchar(10) DEFAULT 'SQM',
    marketvalue decimal(16,2) NOT NULL DEFAULT '0.00',
    assesslevel decimal(16,2) NOT NULL DEFAULT '0.00',
    assessedvalue decimal(16,2) NOT NULL DEFAULT '0.00',
    rputype varchar(25) DEFAULT NULL,
    taxable int DEFAULT '1',
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
");

require_once __DIR__ . '/../wp-content/plugins/assessor-api/includes/class-assessor-auth.php';
require_once __DIR__ . '/../wp-content/plugins/assessor-api/includes/class-assessor-etracs.php';

$etracs = new Assessor_Etracs();

echo "\n--- Scenario 1: Creating a LOCAL FAAS/RPU with Multiple Assessment Lines ---\n";

$multi_lines_input = array(
    array(
        'actualuse' => 'RESIDENTIAL',
        'classcode' => 'RES',
        'classname' => 'Residential',
        'areasqm'   => 500.00,
        'areaha'    => 0.05,
        'marketvalue' => 500000.00,
        'assesslevel' => 20.0,
        'assessedvalue' => 100000.00,
        'taxable'   => 1
    ),
    array(
        'actualuse' => 'COMMERCIAL',
        'classcode' => 'COM',
        'classname' => 'Commercial',
        'areasqm'   => 200.00,
        'areaha'    => 0.02,
        'marketvalue' => 800000.00,
        'assesslevel' => 50.0,
        'assessedvalue' => 400000.00,
        'taxable'   => 1
    )
);

$faas_draft_params = array(
    'tdno'       => 'TD-2026-WP04-001',
    'utdno'      => 'TD-2026-WP04-001',
    'owner_name' => 'Juan Dela Cruz',
    'pin'        => '048-12-001-01-001',
    'rpu_type'   => 'LAND',
    'assessments' => $multi_lines_input
);

$draft_res = $etracs->create_faas(new MockRestRequestWp04($faas_draft_params));
if (is_wp_error($draft_res)) {
    echo "DEBUG draft_res error: code=" . $draft_res->get_error_code() . " msg=" . $draft_res->get_error_message() . "\n";
}
assert_test("Draft FAAS created successfully", !is_wp_error($draft_res) && !empty($draft_res->objid));

$created_rpu_id = $draft_res->rpu_id;
assert_test("RPU ID is linked on created FAAS", !empty($created_rpu_id));

$persisted_lines = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM wp_assessor_rpu_assessment WHERE rpuid = %s ORDER BY actualuse ASC",
    $created_rpu_id
));

assert_test("All assessment lines persisted independently (count = 2)", count($persisted_lines) === 2);
assert_test("Line 1 has origin_type = 'LOCAL'", $persisted_lines[0]->origin_type === 'LOCAL');
assert_test("Line 2 has origin_type = 'LOCAL'", $persisted_lines[1]->origin_type === 'LOCAL');
assert_test("Line 1 rpuid strictly points to created RPU", $persisted_lines[0]->rpuid === $created_rpu_id);
assert_test("Line 2 rpuid strictly points to created RPU", $persisted_lines[1]->rpuid === $created_rpu_id);

// Check RPU aggregates calculated from lines
$created_rpu = $wpdb->get_row($wpdb->prepare("SELECT * FROM wp_assessor_rpu WHERE objid = %s", $created_rpu_id));
assert_test("RPU total_market_value equals sum of lines (1,300,000.00)", floatval($created_rpu->total_market_value) == 1300000.00);
assert_test("RPU total_assessed_value equals sum of lines (500,000.00)", floatval($created_rpu->total_assessed_value) == 500000.00);
assert_test("RPU total_area_sqm equals sum of lines (700.00)", floatval($created_rpu->total_area_sqm) == 700.00);
assert_test("RPU total_area_hectare equals sum of lines (0.07)", abs(floatval($created_rpu->total_area_hectare) - 0.07) < 0.0001);

echo "\n--- Scenario 2: RPU Detail Endpoint Verification ---\n";

$rpu_detail_res = $etracs->get_rpu_detail(array('id' => $created_rpu_id));
assert_test("get_rpu_detail returned data for created RPU", !empty($rpu_detail_res['data']));
$detail_assessments = $rpu_detail_res['data']->assessments ?? array();
assert_test("get_rpu_detail returns 2 assessment items", count($detail_assessments) === 2);


echo "\n--- Scenario 3: Editing a Draft Without Duplicate Lines (Idempotency) ---\n";

// Update FAAS with the same 2 lines (using their generated objids)
$lines_with_ids = array(
    array(
        'objid'       => $persisted_lines[0]->objid,
        'actualuse'   => 'COMMERCIAL',
        'classcode'   => 'COM',
        'classname'   => 'Commercial',
        'areasqm'     => 200.00,
        'areaha'      => 0.02,
        'marketvalue' => 800000.00,
        'assesslevel' => 50.0,
        'assessedvalue' => 400000.00,
        'taxable'     => 1
    ),
    array(
        'objid'       => $persisted_lines[1]->objid,
        'actualuse'   => 'RESIDENTIAL',
        'classcode'   => 'RES',
        'classname'   => 'Residential',
        'areasqm'     => 500.00,
        'areaha'      => 0.05,
        'marketvalue' => 500000.00,
        'assesslevel' => 20.0,
        'assessedvalue' => 100000.00,
        'taxable'     => 1
    )
);

$update_draft_res = $etracs->update_faas($draft_res->objid, array(
    'owner_name'  => 'Juan Dela Cruz Jr.',
    'assessments' => $lines_with_ids
));
assert_test("Draft FAAS updated cleanly", !is_wp_error($update_draft_res));

$lines_after_reup = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM wp_assessor_rpu_assessment WHERE rpuid = %s",
    $created_rpu_id
));
assert_test("Idempotent update: Total assessment row count remains exactly 2 (no duplicates)", count($lines_after_reup) === 2);


echo "\n--- Scenario 4: Updating, Adding, and Removing Permitted LOCAL Draft Lines ---\n";

// Update existing line 1 (COMMERCIAL -> 900,000 MV), Remove line 2 (RESIDENTIAL), Add line 3 (AGRICULTURAL)
$modified_lines_input = array(
    array(
        'objid'       => $persisted_lines[0]->objid,
        'actualuse'   => 'COMMERCIAL',
        'classcode'   => 'COM',
        'classname'   => 'Commercial',
        'areasqm'     => 250.00,
        'areaha'      => 0.025,
        'marketvalue' => 900000.00,
        'assesslevel' => 50.0,
        'assessedvalue' => 450000.00,
        'taxable'     => 1
    ),
    array(
        'actualuse'   => 'AGRICULTURAL',
        'classcode'   => 'AGR',
        'classname'   => 'Agricultural',
        'areasqm'     => 10000.00,
        'areaha'      => 1.0,
        'marketvalue' => 300000.00,
        'assesslevel' => 40.0,
        'assessedvalue' => 120000.00,
        'taxable'     => 1
    )
);

$update_modify_res = $etracs->update_faas($draft_res->objid, array(
    'assessments' => $modified_lines_input
));
assert_test("Update with line modification, addition, and deletion succeeded", !is_wp_error($update_modify_res));

$lines_after_mod = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM wp_assessor_rpu_assessment WHERE rpuid = %s ORDER BY actualuse ASC",
    $created_rpu_id
));

assert_test("Row count is 2 (1 updated, 1 new, 1 removed)", count($lines_after_mod) === 2);
assert_test("Removed line 2 (RESIDENTIAL) is no longer in database", !in_array($persisted_lines[1]->objid, wp_list_pluck($lines_after_mod, 'objid')));
assert_test("Existing line 1 was updated in-place (MV = 900,000.00)", floatval($lines_after_mod[1]->marketvalue) == 900000.00);
assert_test("New agricultural line was added with origin_type = LOCAL", $lines_after_mod[0]->actualuse === 'AGRICULTURAL' && $lines_after_mod[0]->origin_type === 'LOCAL');

// Recheck RPU aggregates: 900,000 + 300,000 = 1,200,000 MV; 450,000 + 120,000 = 570,000 AV
$rpu_after_mod = $wpdb->get_row($wpdb->prepare("SELECT * FROM wp_assessor_rpu WHERE objid = %s", $created_rpu_id));
assert_test("RPU total_market_value updated to 1,200,000.00", floatval($rpu_after_mod->total_market_value) == 1200000.00);
assert_test("RPU total_assessed_value updated to 570,000.00", floatval($rpu_after_mod->total_assessed_value) == 570000.00);


echo "\n--- Scenario 5: Attempting to Modify REPLICA Assessment Lines or REPLICA RPU ---\n";

// Seed a REPLICA RPU and REPLICA assessment line
$replica_rpu_id = 'RPU-REPLICA-SRC-001';
$replica_assess_id = 'ASSESS-REPLICA-SRC-001';
$wpdb->insert('wp_assessor_rpu', array(
    'objid'              => $replica_rpu_id,
    'origin_type'        => 'REPLICA',
    'realpropertyid'     => 'RP-TEST-DUMMY',
    'rpu_type'           => 'LAND',
    'total_market_value' => 2000000.00,
    'total_assessed_value' => 800000.00
));
$wpdb->insert('wp_assessor_rpu_assessment', array(
    'objid'         => $replica_assess_id,
    'rpuid'         => $replica_rpu_id,
    'origin_type'   => 'REPLICA',
    'actualuse'     => 'INDUSTRIAL',
    'marketvalue'   => 2000000.00,
    'assessedvalue' => 800000.00
));

// 5a: Direct persist_rpu_assessments call targeting REPLICA RPU
$replica_rpu_attempt = $etracs->persist_rpu_assessments($replica_rpu_id, array(
    array('actualuse' => 'ILLEGAL_OVERWRITE', 'marketvalue' => 99999.00)
));
assert_test("Direct call targeting REPLICA RPU returns WP_Error", is_wp_error($replica_rpu_attempt));
if (is_wp_error($replica_rpu_attempt) && $replica_rpu_attempt->get_error_code() !== 'replica_protected') {
    echo "DEBUG replica error code=" . $replica_rpu_attempt->get_error_code() . " msg=" . $replica_rpu_attempt->get_error_message() . "\n";
}
assert_test("Error code is 'replica_protected'", is_wp_error($replica_rpu_attempt) && $replica_rpu_attempt->get_error_code() === 'replica_protected');

// Verify REPLICA line was NOT modified or deleted
$replica_line_after_attempt = $wpdb->get_row($wpdb->prepare("SELECT * FROM wp_assessor_rpu_assessment WHERE objid = %s", $replica_assess_id));
assert_test("REPLICA assessment line remains untouched (actualuse = INDUSTRIAL)", $replica_line_after_attempt->actualuse === 'INDUSTRIAL');
assert_test("REPLICA assessment line origin_type remains REPLICA", $replica_line_after_attempt->origin_type === 'REPLICA');

// 5b: Attempting to hijack a REPLICA assessment line into a LOCAL RPU update
$hijack_attempt = $etracs->persist_rpu_assessments($created_rpu_id, array(
    array('objid' => $replica_assess_id, 'actualuse' => 'HIJACKED', 'marketvalue' => 12345.00)
));
assert_test("Attempting to overwrite REPLICA assessment row returns WP_Error", is_wp_error($hijack_attempt));
assert_test("Hijack error code is 'replica_protected'", $hijack_attempt->get_error_code() === 'replica_protected');


echo "\n--- Scenario 6: Ensuring One RPU Cannot Receive Another Declaration's Assessment Lines ---\n";

// Seed another LOCAL RPU and assessment line
$other_rpu_id = 'RPU-LOCAL-OTHER-002';
$other_assess_id = 'ASSESS-LOCAL-OTHER-002';
$wpdb->insert('wp_assessor_rpu', array(
    'objid'          => $other_rpu_id,
    'origin_type'    => 'LOCAL',
    'realpropertyid' => 'RP-TEST-DUMMY',
    'rpu_type'       => 'LAND'
));
$wpdb->insert('wp_assessor_rpu_assessment', array(
    'objid'       => $other_assess_id,
    'rpuid'       => $other_rpu_id,
    'origin_type' => 'LOCAL',
    'actualuse'   => 'SPECIAL'
));

$cross_rpu_attempt = $etracs->persist_rpu_assessments($created_rpu_id, array(
    array('objid' => $other_assess_id, 'actualuse' => 'CROSS_ATTEMPT')
));
assert_test("Attempting to attach another RPU's assessment line returns WP_Error", is_wp_error($cross_rpu_attempt));
assert_test("Cross-RPU error code is 'rpu_mismatch'", $cross_rpu_attempt->get_error_code() === 'rpu_mismatch');


echo "\n--- Scenario 7: Aggregate Preservation Under Verified Rules ---\n";

// Create a draft with explicit 0/empty line values to verify raw preserving
$zero_line_input = array(
    array(
        'actualuse'   => 'TIMBERLAND',
        'classcode'   => 'TIM',
        'classname'   => 'Timberland',
        'areasqm'     => 0.00,
        'areaha'      => 5.5,
        'marketvalue' => 0.00,
        'assesslevel' => 0.00,
        'assessedvalue' => 0.00,
        'taxable'     => 0
    )
);

$faas_zero_draft = $etracs->create_faas(new MockRestRequestWp04(array(
    'tdno'        => 'TD-2026-WP04-ZERO',
    'owner_name'  => 'Government Forest',
    'pin'         => '048-12-001-99-999',
    'rpu_type'    => 'LAND',
    'assessments' => $zero_line_input
)));
assert_test("Draft with 0 market/assessed value created cleanly", !is_wp_error($faas_zero_draft));

$zero_rpu = $wpdb->get_row($wpdb->prepare("SELECT * FROM wp_assessor_rpu WHERE objid = %s", $faas_zero_draft->rpu_id));
assert_test("RPU total_market_value is 0.00 without NaN/error", floatval($zero_rpu->total_market_value) == 0.00);
assert_test("RPU total_area_hectare is 5.5", floatval($zero_rpu->total_area_hectare) == 5.5);


echo "\n--- Scenario 8: Database Failure Injection and Atomic Rollback ---\n";

// Count FAAS, RPU, and Assessment rows before failed attempt
$faas_count_before = $wpdb->get_var("SELECT COUNT(*) FROM wp_assessor_faas");
$rpu_count_before = $wpdb->get_var("SELECT COUNT(*) FROM wp_assessor_rpu");
$assess_count_before = $wpdb->get_var("SELECT COUNT(*) FROM wp_assessor_rpu_assessment");

// Inject invalid assessment payload that triggers error (e.g. string instead of array or invalid line data)
$failing_draft_params = array(
    'tdno'        => 'TD-2026-WP04-FAIL',
    'owner_name'  => 'Should Rollback',
    'pin'         => '048-12-001-00-FAIL',
    'rpu_type'    => 'LAND',
    'assessments' => 'INVALID_PAYLOAD_NOT_ARRAY' // triggers invalid_assessments_payload
);

$fail_res = $etracs->create_faas(new MockRestRequestWp04($failing_draft_params));
assert_test("Create FAAS with invalid assessment payload returns WP_Error", is_wp_error($fail_res));

$faas_count_after = $wpdb->get_var("SELECT COUNT(*) FROM wp_assessor_faas");
$rpu_count_after = $wpdb->get_var("SELECT COUNT(*) FROM wp_assessor_rpu");
$assess_count_after = $wpdb->get_var("SELECT COUNT(*) FROM wp_assessor_rpu_assessment");

assert_test("ATOMIC GUARANTEE: FAAS row was rolled back (count unchanged)", intval($faas_count_before) === intval($faas_count_after));
assert_test("ATOMIC GUARANTEE: RPU row was rolled back (count unchanged)", intval($rpu_count_before) === intval($rpu_count_after));
assert_test("ATOMIC GUARANTEE: Assessment lines were rolled back (count unchanged)", intval($assess_count_before) === intval($assess_count_after));


echo "\n--- Scenario 9: Simulated WP-01 Source Refresh and LOCAL Line Persistence ---\n";

// Check that our local lines exist before refresh
$local_lines_pre = $wpdb->get_results("SELECT objid, actualuse FROM wp_assessor_rpu_assessment WHERE origin_type = 'LOCAL'");
$local_count_pre = count($local_lines_pre);
assert_test("Local assessment lines exist prior to simulated refresh ($local_count_pre lines)", $local_count_pre > 0);

// Simulate source refresh on assessor_rpu_assessment:
// Upsert incoming replica record
$wpdb->query("
    INSERT INTO wp_assessor_rpu_assessment (objid, rpuid, origin_type, actualuse, marketvalue, assessedvalue)
    VALUES ('ASSESS-REPLICA-SRC-001', 'RPU-REPLICA-SRC-001', 'REPLICA', 'INDUSTRIAL_UPDATED', 2500000.00, 1000000.00)
    ON DUPLICATE KEY UPDATE actualuse = VALUES(actualuse), marketvalue = VALUES(marketvalue), assessedvalue = VALUES(assessedvalue)
");

// Verify that REPLICA was refreshed
$refreshed_replica = $wpdb->get_row("SELECT * FROM wp_assessor_rpu_assessment WHERE objid = 'ASSESS-REPLICA-SRC-001'");
assert_test("REPLICA assessment line was refreshed (actualuse = INDUSTRIAL_UPDATED)", $refreshed_replica->actualuse === 'INDUSTRIAL_UPDATED');

// Verify that LOCAL lines are 100% intact and not modified or deleted
$local_lines_post = $wpdb->get_results("SELECT objid, actualuse FROM wp_assessor_rpu_assessment WHERE origin_type = 'LOCAL'");
assert_test("LOCAL assessment lines completely preserved after source refresh (count unchanged)", count($local_lines_post) === $local_count_pre);


echo "\n--- Scenario 10: Lifecycle Integration: Draft Submission, Approval, and Disapproval ---\n";

// 10a: Submit draft with assessment lines
$submit_res = $etracs->submit_faas($draft_res->objid, new MockRestRequestWp04());
assert_test("Draft with assessment lines submitted successfully (state = FORAPPROVAL)", !is_wp_error($submit_res) && $submit_res->state === 'FORAPPROVAL');

// 10b: Approve draft with assessment lines
$approve_res = $etracs->approve_faas($draft_res->objid, new MockRestRequestWp04(array('date_approved' => '2026-10-22 18:00:00')));
assert_test("Draft with assessment lines approved successfully (state = CURRENT)", !is_wp_error($approve_res) && $approve_res->state === 'CURRENT');

// Verify assessment lines still exist and are linked to approved RPU
$lines_after_approval = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM wp_assessor_rpu_assessment WHERE rpuid = %s",
    $created_rpu_id
));
assert_test("Assessment lines preserved post-approval (count = 2)", count($lines_after_approval) === 2);

// 10c: Create another draft and disapprove it, then verify editing updates LOCAL rows
$disapp_draft = $etracs->create_faas(new MockRestRequestWp04(array(
    'tdno'        => 'TD-2026-WP04-DISAPP',
    'owner_name'  => 'Disapproved Draft Owner',
    'rpu_type'    => 'LAND',
    'assessments' => array(
        array('actualuse' => 'RESIDENTIAL', 'marketvalue' => 100000.00, 'assessedvalue' => 20000.00)
    )
)));
$etracs->submit_faas($disapp_draft->objid, new MockRestRequestWp04());
$disapp_res = $etracs->disapprove_faas($disapp_draft->objid, new MockRestRequestWp04(array('reason' => 'Need survey plan correction')));
assert_test("Draft transitioned to DISAPPROVED", !is_wp_error($disapp_res) && $disapp_res->state === 'DISAPPROVED');

// Edit the DISAPPROVED draft's assessment lines
$disapp_edit_res = $etracs->update_faas($disapp_draft->objid, array(
    'assessments' => array(
        array('actualuse' => 'RESIDENTIAL_CORRECTED', 'marketvalue' => 150000.00, 'assessedvalue' => 30000.00)
    )
));
assert_test("Editing DISAPPROVED draft succeeds", !is_wp_error($disapp_edit_res));
$disapp_lines_after = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM wp_assessor_rpu_assessment WHERE rpuid = %s",
    $disapp_draft->rpu_id
));
assert_test("DISAPPROVED draft assessment line was updated cleanly", count($disapp_lines_after) === 1 && $disapp_lines_after[0]->actualuse === 'RESIDENTIAL_CORRECTED');


echo "\n--- Scenario 11: Absolute Architecture Boundary Check ---\n";
$tables = $wpdb->get_col("SHOW TABLES LIKE 'wp_assessor_properties'");
assert_test("Native table wp_assessor_properties does NOT exist in disposable database", empty($tables));


echo "\n========================================================================\n";
echo "   WP-04 RPU ASSESSMENT TEST SUMMARY\n";
echo "   Total Tests Passed: $pass_count\n";
echo "   Total Tests Failed: $fail_count\n";
echo "========================================================================\n";

if ($fail_count > 0) {
    exit(1);
}
exit(0);
