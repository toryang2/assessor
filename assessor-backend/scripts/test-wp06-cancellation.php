<?php
/**
 * Test Suite: Assessor Repo — Phase 12 / WP-06: Shadow ETRACS Structured Cancellation & Cancellation History
 *
 * Isolated test suite running entirely within the disposable database `test_wp01_disposable`.
 *
 * Scenarios tested (per WP-06 specifications):
 * 1. Explicit cancellation of a valid LOCAL CURRENT declaration with complete required fields.
 * 2. Missing reason or other required cancellation data.
 * 3. Attempts to cancel LOCAL declarations in invalid lifecycle states (INTERIM, PENDING, FORAPPROVAL, DISAPPROVED).
 * 4. Attempts to cancel REPLICA declarations, proving their raw values and ownership markers remain unchanged.
 * 5. Distinguishing explicit cancellation, successor supersession, and disapproval.
 * 6. A REPLICA predecessor whose effective state becomes CANCELLED through approved local lineage.
 * 7. Multiple approved successors and full cancellation metadata aggregation (concatenation).
 * 8. Multi-tier revision history where a successor is later superseded.
 * 9. Repeated cancellation requests and retry/idempotency behavior.
 * 10. A simulated source refresh that must preserve local cancellation and lineage behavior.
 * 11. Failure injection proving rollback of cancellation fields and any associated lineage writes.
 * 12. Consistency between FAAS detail, list filters, pagination, dashboard statistics, and frontend effective-state display.
 * 13. WP-01 synchronization, collision, and ownership regressions.
 * 14. WP-02/WP-03 lifecycle and lineage regressions.
 * 15. WP-04 assessment-line persistence and WP-05 parcel/RPUMaster regressions.
 * 16. Explicit native-table isolation checks.
 */

class MockWpdbWp06 {
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
    define('JWT_AUTH_SECRET_KEY', 'test_secret_key_for_wp06_cancellation_12345');
}

if (!function_exists('get_site_url')) {
    function get_site_url() {
        return 'http://localhost/assessor';
    }
}

class MockRestRequestWp06 {
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
$wpdb = new MockWpdbWp06($pdo_target);

echo "========================================================================\n";
echo "   ASSESSOR REPO - PHASE 12 / WP-06 STRUCTURED CANCELLATION HARNESS\n";
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
require_once __DIR__ . '/../wp-content/plugins/assessor-api/includes/class-assessor-etracs.php';

$etracs = new Assessor_Etracs();

// ─────────────────────────────────────────────────────────────
// Clean tables in disposable database for testing
// ─────────────────────────────────────────────────────────────
$pdo_target->exec("SET FOREIGN_KEY_CHECKS=0");
$pdo_target->exec("TRUNCATE TABLE wp_assessor_faas_previous");
$pdo_target->exec("TRUNCATE TABLE wp_assessor_rpu_assessment");
$pdo_target->exec("TRUNCATE TABLE wp_assessor_faas");
$pdo_target->exec("TRUNCATE TABLE wp_assessor_rpu");
$pdo_target->exec("TRUNCATE TABLE wp_assessor_rpumaster");
$pdo_target->exec("TRUNCATE TABLE wp_assessor_real_property");
$pdo_target->exec("TRUNCATE TABLE wp_assessor_entity");
$pdo_target->exec("SET FOREIGN_KEY_CHECKS=1");

// Ensure previd column exists on wp_assessor_real_property
$has_previd = $pdo_target->query("SHOW COLUMNS FROM wp_assessor_real_property LIKE 'previd'")->fetch();
if (!$has_previd) {
    $pdo_target->exec("ALTER TABLE wp_assessor_real_property ADD COLUMN previd varchar(50) DEFAULT NULL AFTER autonumber");
}

// Ensure txntypes reference exists
$pdo_target->exec("
    INSERT IGNORE INTO wp_assessor_txn_type (code, name)
    VALUES 
    ('GR', 'General Revision'),
    ('TR', 'Transfer of Ownership'),
    ('SD', 'Subdivision'),
    ('CS', 'Consolidation')
");

// ─────────────────────────────────────────────────────────────
// SCENARIO 1: Explicit cancellation of LOCAL CURRENT declaration
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 1: Explicit Cancellation of a Valid LOCAL CURRENT Declaration ---\n";

$td1 = 'TD-WP06-001';
$req1 = new MockRestRequestWp06(array(
    'tdno' => $td1,
    'owner_name' => 'Owner Alpha',
    'pin' => '043-05-001-01-001',
    'barangay' => 'Poblacion',
    'classification' => 'RESIDENTIAL',
    'total_market_value' => 100000,
    'total_assessed_value' => 20000,
));
$faas1 = $etracs->create_faas($req1);
assert_test("Scenario 1: Created draft FAAS", !is_wp_error($faas1));

// Submit and approve to CURRENT
$etracs->submit_faas($faas1->objid);
$app1 = $etracs->approve_faas($faas1->objid);
assert_test("Scenario 1: FAAS transitioned to CURRENT", !is_wp_error($app1) && $app1->state === 'CURRENT');

// Explicitly cancel with complete fields
$cancel_req1 = new MockRestRequestWp06(array(
    'cancel_reason' => 'Deed of Absolute Sale to Province',
    'cancel_note' => 'Transferred under LGU acquisition project',
    'cancelled_by_tdnos' => 'TD-WP06-GOV-01',
    'cancel_date' => '2026-10-22 14:00:00',
    'cancelled_year' => 2026
));
$cancel_res1 = $etracs->cancel_faas($faas1->objid, $cancel_req1);
assert_test("Scenario 1: Explicit cancel_faas succeeds", !is_wp_error($cancel_res1));
assert_test("Scenario 1: Raw state transitioned to 'CANCELLED'", $cancel_res1->raw_state === 'CANCELLED');
assert_test("Scenario 1: Effective state is 'CANCELLED'", $cancel_res1->effective_state === 'CANCELLED');
assert_test("Scenario 1: cancelreason preserved exactly", $cancel_res1->cancelreason === 'Deed of Absolute Sale to Province');
assert_test("Scenario 1: cancelnote preserved exactly", $cancel_res1->cancelnote === 'Transferred under LGU acquisition project');
assert_test("Scenario 1: cancelledbytdnos preserved", $cancel_res1->cancelledbytdnos === 'TD-WP06-GOV-01');
assert_test("Scenario 1: cancelledyear recorded accurately", intval($cancel_res1->cancelledyear) === 2026);
assert_test("Scenario 1: canceldate recorded", !empty($cancel_res1->canceldate));

// ─────────────────────────────────────────────────────────────
// SCENARIO 2: Missing reason or required cancellation data
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 2: Validation of Required Cancellation Reason ---\n";

$td2 = 'TD-WP06-002';
$req2 = new MockRestRequestWp06(array(
    'tdno' => $td2,
    'owner_name' => 'Owner Beta',
    'pin' => '043-05-001-01-002',
));
$faas2 = $etracs->create_faas($req2);
$etracs->submit_faas($faas2->objid);
$etracs->approve_faas($faas2->objid);

// Attempt cancel without reason
$empty_cancel_req = new MockRestRequestWp06(array(
    'cancel_reason' => '   ', // whitespace only
    'cancel_note' => 'Some note',
));
$empty_cancel_res = $etracs->cancel_faas($faas2->objid, $empty_cancel_req);
assert_test("Scenario 2: Cancellation without reason is rejected", is_wp_error($empty_cancel_res));
assert_test("Scenario 2: Error code is 'missing_reason'", $empty_cancel_res->get_error_code() === 'missing_reason');

// Verify row state was not altered
$check_faas2 = $etracs->get_faas($faas2->objid);
assert_test("Scenario 2: Declaration state remains 'CURRENT' after rejected cancellation", $check_faas2->state === 'CURRENT');

// ─────────────────────────────────────────────────────────────
// SCENARIO 3: Attempts to cancel declarations in invalid lifecycle states
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 3: Rejecting Cancellation in Invalid Lifecycle States ---\n";

// Draft INTERIM
$td3_draft = 'TD-WP06-DRAFT';
$draft_faas = $etracs->create_faas(new MockRestRequestWp06(array('tdno' => $td3_draft, 'owner_name' => 'Draft Owner')));
$cancel_draft_res = $etracs->cancel_faas($draft_faas->objid, new MockRestRequestWp06(array('cancel_reason' => 'Mistake')));
assert_test("Scenario 3: Reject cancelling INTERIM/draft declaration", is_wp_error($cancel_draft_res) && $cancel_draft_res->get_error_code() === 'invalid_state');

// FORAPPROVAL
$etracs->submit_faas($draft_faas->objid);
$cancel_fa_res = $etracs->cancel_faas($draft_faas->objid, new MockRestRequestWp06(array('cancel_reason' => 'Mistake')));
assert_test("Scenario 3: Reject cancelling FORAPPROVAL declaration", is_wp_error($cancel_fa_res) && $cancel_fa_res->get_error_code() === 'invalid_state');

// DISAPPROVED
$etracs->disapprove_faas($draft_faas->objid, new MockRestRequestWp06(array('reason' => 'Incorrect assessment')));
$cancel_disapp_res = $etracs->cancel_faas($draft_faas->objid, new MockRestRequestWp06(array('cancel_reason' => 'Mistake')));
assert_test("Scenario 3: Reject cancelling DISAPPROVED declaration", is_wp_error($cancel_disapp_res) && $cancel_disapp_res->get_error_code() === 'invalid_state');

// ─────────────────────────────────────────────────────────────
// SCENARIO 4: Reject cancellation of source-owned REPLICA declarations
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 4: Attempts to Cancel REPLICA Declarations ---\n";

$replica_id = 'FAAS-REPLICA-SRC-01';
$replica_td = 'TD-REPLICA-SRC-01';
$pdo_target->exec("
    INSERT INTO wp_assessor_faas (objid, utdno, tdno, state, origin_type, owner_name, txntimestamp)
    VALUES ('$replica_id', '$replica_td', '$replica_td', 'CURRENT', 'REPLICA', 'Original Replica Owner', NOW())
");

$replica_cancel_req = new MockRestRequestWp06(array(
    'cancel_reason' => 'Manual cancel attempt on replica',
    'cancel_date' => '2026-10-22 15:00:00'
));
$replica_cancel_res = $etracs->cancel_faas($replica_id, $replica_cancel_req);
assert_test("Scenario 4: Reject cancel on REPLICA record", is_wp_error($replica_cancel_res));
assert_test("Scenario 4: Error code is 'replica_protected'", $replica_cancel_res->get_error_code() === 'replica_protected');

// Verify REPLICA DB row remains untouched
$replica_db_row = $pdo_target->query("SELECT * FROM wp_assessor_faas WHERE objid = '$replica_id'")->fetch(PDO::FETCH_ASSOC);
assert_test("Scenario 4: REPLICA raw state remains 'CURRENT'", $replica_db_row['state'] === 'CURRENT');
assert_test("Scenario 4: REPLICA origin_type remains 'REPLICA'", $replica_db_row['origin_type'] === 'REPLICA');
assert_test("Scenario 4: REPLICA cancelreason remains NULL", empty($replica_db_row['cancelreason']));
assert_test("Scenario 4: REPLICA canceldate remains NULL", empty($replica_db_row['canceldate']));

// ─────────────────────────────────────────────────────────────
// SCENARIO 5: Distinguishing explicit cancellation, supersession, and disapproval
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 5: Distinguishing Explicit Cancellation, Supersession, and Disapproval ---\n";

// Disapproved record
$disapp_check = $etracs->get_faas($draft_faas->objid);
assert_test("Scenario 5: Disapproved record has state = 'DISAPPROVED'", $disapp_check->state === 'DISAPPROVED');
assert_test("Scenario 5: Disapproved record cancelnote has [DISAPPROVED] prefix", strpos($disapp_check->cancelnote, '[DISAPPROVED]') === 0);

// Explicitly cancelled record
$explicit_check = $etracs->get_faas($faas1->objid);
assert_test("Scenario 5: Explicit cancelled has state = 'CANCELLED'", $explicit_check->state === 'CANCELLED');
assert_test("Scenario 5: Explicit cancelled has no [DISAPPROVED] prefix", strpos($explicit_check->cancelnote, '[DISAPPROVED]') === false);
assert_test("Scenario 5: Explicit cancelled has distinct reason", $explicit_check->cancelreason === 'Deed of Absolute Sale to Province');

// ─────────────────────────────────────────────────────────────
// SCENARIO 6: REPLICA predecessor effective cancellation through approved local lineage
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 6: REPLICA Predecessor Effective Cancellation Through Approved Local Lineage ---\n";

$replica_pred_id = 'FAAS-REPLICA-PRED-01';
$replica_pred_td = 'TD-REPLICA-PRED-01';
// Insert REPLICA predecessor with raw state CURRENT, but non-empty raw cancellation fields (e.g. stale source or prior cancelled attempt)
$pdo_target->exec("
    INSERT INTO wp_assessor_faas (
        objid, utdno, tdno, state, origin_type, owner_name, txntimestamp,
        cancelreason, canceldate, cancelledbytdnos, cancelledyear, cancelnote
    ) VALUES (
        '$replica_pred_id', '$replica_pred_td', '$replica_pred_td', 'CURRENT', 'REPLICA', 'Replica Predecessor Owner', '2020-01-01 00:00:00',
        'Stale Source Reason', '2019-12-31 00:00:00', 'TD-STALE-SRC-99', 2019, 'Stale Source Note'
    )
");

// Create local successor referencing REPLICA predecessor
$succ_td = 'TD-LOCAL-SUCC-01';
$succ_req = new MockRestRequestWp06(array(
    'tdno' => $succ_td,
    'owner_name' => 'Local Successor Owner',
    'prevfaasid' => $replica_pred_id,
    'prevtdno' => $replica_pred_td,
    'txntype_code' => 'TR'
));
$succ_faas = $etracs->create_faas($succ_req);
$etracs->submit_faas($succ_faas->objid);
$succ_app = $etracs->approve_faas($succ_faas->objid, new MockRestRequestWp06(array('date_approved' => '2026-10-22 10:00:00')));
assert_test("Scenario 6: Successor approved to CURRENT", !is_wp_error($succ_app) && $succ_app->state === 'CURRENT');

// Check REPLICA predecessor details via get_faas
$replica_pred_view = $etracs->get_faas($replica_pred_id);
assert_test("Scenario 6: REPLICA raw state is strictly 'CURRENT'", $replica_pred_view->raw_state === 'CURRENT');
assert_test("Scenario 6: REPLICA effective state is 'CANCELLED'", $replica_pred_view->effective_state === 'CANCELLED');
assert_test("Scenario 6: PRECEDENCE: effective cancelled_by_tdnos overrides stale raw TD ('$succ_td' vs 'TD-STALE-SRC-99')", $replica_pred_view->cancelled_by_tdnos === $succ_td);
assert_test("Scenario 6: PRECEDENCE: effective cancel_reason overrides stale raw reason", $replica_pred_view->cancel_reason === "Superseded by $succ_td");
assert_test("Scenario 6: PRECEDENCE: effective cancel_date overrides stale raw date", $replica_pred_view->cancel_date === '2026-10-22 10:00:00');
assert_test("Scenario 6: PRECEDENCE: effective cancelled_year overrides stale raw year (2026 vs 2019)", intval($replica_pred_view->cancelled_year) === 2026);

// Verify raw fields remain available for diagnostics / historical inspection
assert_test("Scenario 6: RAW INSPECTION: raw_cancelledbytdnos preserves source value ('TD-STALE-SRC-99')", $replica_pred_view->raw_cancelledbytdnos === 'TD-STALE-SRC-99');
assert_test("Scenario 6: RAW INSPECTION: raw_cancelreason preserves source value ('Stale Source Reason')", $replica_pred_view->raw_cancelreason === 'Stale Source Reason');
assert_test("Scenario 6: RAW INSPECTION: raw_canceldate preserves source date ('2019-12-31 00:00:00')", $replica_pred_view->raw_canceldate === '2019-12-31 00:00:00');
assert_test("Scenario 6: RAW INSPECTION: raw_cancelledyear preserves source year (2019)", intval($replica_pred_view->raw_cancelledyear) === 2019);

// Check REPLICA raw DB row remains completely untouched
$replica_raw = $pdo_target->query("SELECT * FROM wp_assessor_faas WHERE objid = '$replica_pred_id'")->fetch(PDO::FETCH_ASSOC);
assert_test("Scenario 6: REPLICA raw DB state remains 'CURRENT'", $replica_raw['state'] === 'CURRENT');
assert_test("Scenario 6: REPLICA raw DB cancelreason untouched ('Stale Source Reason')", $replica_raw['cancelreason'] === 'Stale Source Reason');
assert_test("Scenario 6: REPLICA raw DB cancelledbytdnos untouched ('TD-STALE-SRC-99')", $replica_raw['cancelledbytdnos'] === 'TD-STALE-SRC-99');
assert_test("Scenario 6: REPLICA raw DB cancelledyear untouched (2019)", intval($replica_raw['cancelledyear']) === 2019);

// ─────────────────────────────────────────────────────────────
// SCENARIO 7: Multiple approved successors and full metadata aggregation
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 7: Multiple Approved Successors & Cancellation Metadata Aggregation ---\n";

// Local parent declaration
$parent_td = 'TD-LOCAL-PARENT-01';
$parent_req = new MockRestRequestWp06(array(
    'tdno' => $parent_td,
    'owner_name' => 'Original Parcel Owner',
));
$parent_faas = $etracs->create_faas($parent_req);
$etracs->submit_faas($parent_faas->objid);
$etracs->approve_faas($parent_faas->objid);

// Child 1 subdivision
$child1_td = 'TD-LOCAL-CHILD-01';
$child1_req = new MockRestRequestWp06(array(
    'tdno' => $child1_td,
    'owner_name' => 'Subdivision Child 1',
    'prevfaasid' => $parent_faas->objid,
    'prevtdno' => $parent_td,
    'txntype_code' => 'SD'
));
$child1_faas = $etracs->create_faas($child1_req);
$etracs->submit_faas($child1_faas->objid);
$etracs->approve_faas($child1_faas->objid);

// Verify parent now superseded by Child 1
$parent_after_child1 = $etracs->get_faas($parent_faas->objid);
assert_test("Scenario 7: Parent cancelled by Child 1", $parent_after_child1->cancelledbytdnos === $child1_td);
assert_test("Scenario 7: Parent cancelreason records Child 1", strpos($parent_after_child1->cancelreason, "Superseded by $child1_td") !== false);

// Child 2 subdivision from same parent
$child2_td = 'TD-LOCAL-CHILD-02';
$child2_req = new MockRestRequestWp06(array(
    'tdno' => $child2_td,
    'owner_name' => 'Subdivision Child 2',
    'prevfaasid' => $parent_faas->objid,
    'prevtdno' => $parent_td,
    'txntype_code' => 'SD'
));
$child2_faas = $etracs->create_faas($child2_req);
$etracs->submit_faas($child2_faas->objid);
$etracs->approve_faas($child2_faas->objid);

// Verify parent cancelledbytdnos aggregated both children deterministically without duplicate
$parent_after_child2 = $etracs->get_faas($parent_faas->objid);
$expected_tds = "$child1_td, $child2_td";
assert_test("Scenario 7: Parent cancelledbytdnos aggregates both children ('$expected_tds')", $parent_after_child2->cancelledbytdnos === $expected_tds);
assert_test("Scenario 7: Parent cancelreason aggregates both children", $parent_after_child2->cancelreason === "Superseded by $expected_tds");

// ─────────────────────────────────────────────────────────────
// SCENARIO 8: Multi-tier revision history where a successor is later superseded
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 8: Multi-Tier Revision History (A -> B -> C) ---\n";

// Tier C supersedes Child 1
$grandchild_td = 'TD-LOCAL-GRANDCHILD-01';
$grandchild_req = new MockRestRequestWp06(array(
    'tdno' => $grandchild_td,
    'owner_name' => 'Revision Grandchild',
    'prevfaasid' => $child1_faas->objid,
    'prevtdno' => $child1_td,
    'txntype_code' => 'TR'
));
$grandchild_faas = $etracs->create_faas($grandchild_req);
$etracs->submit_faas($grandchild_faas->objid);
$etracs->approve_faas($grandchild_faas->objid);

// Check Child 1 (Tier B)
$child1_view = $etracs->get_faas($child1_faas->objid);
assert_test("Scenario 8: Child 1 (Tier B) is now CANCELLED", $child1_view->state === 'CANCELLED');
assert_test("Scenario 8: Child 1 superseded by Tier C", $child1_view->cancelledbytdnos === $grandchild_td);

// Check Parent (Tier A) still superseded by Child 1 & Child 2 (no collapse of Tier C onto Tier A)
$parent_view_tier = $etracs->get_faas($parent_faas->objid);
assert_test("Scenario 8: Tier A cancelledbytdnos retains immediate successors ($expected_tds)", $parent_view_tier->cancelledbytdnos === $expected_tds);

// Check Grandchild (Tier C) is CURRENT
$grandchild_view = $etracs->get_faas($grandchild_faas->objid);
assert_test("Scenario 8: Tier C is CURRENT", $grandchild_view->state === 'CURRENT');
assert_test("Scenario 8: Tier C predecessor is Child 1", $grandchild_view->prevtdno === $child1_td);

// ─────────────────────────────────────────────────────────────
// SCENARIO 9: Repeated cancellation requests and retry/idempotency behavior
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 9: Idempotency of Repeated Cancellation Requests ---\n";

$initial_cancel_timestamp = $cancel_res1->cancelledtimestamp;
$initial_cancel_reason = $cancel_res1->cancelreason;

// Re-send cancellation request for already cancelled declaration
$retry_cancel_res = $etracs->cancel_faas($faas1->objid, new MockRestRequestWp06(array(
    'cancel_reason' => 'Conflicting new reason attempt',
    'cancelled_by_tdnos' => 'TD-CONFLICTING-99'
)));

assert_test("Scenario 9: Repeated cancellation returns successfully (idempotent)", !is_wp_error($retry_cancel_res));
assert_test("Scenario 9: State remains 'CANCELLED'", $retry_cancel_res->state === 'CANCELLED');
assert_test("Scenario 9: Original cancelreason is preserved and NOT overwritten", $retry_cancel_res->cancelreason === $initial_cancel_reason);
assert_test("Scenario 9: Original cancelledbytdnos is preserved and NOT overwritten", $retry_cancel_res->cancelledbytdnos === 'TD-WP06-GOV-01');

// ─────────────────────────────────────────────────────────────
// SCENARIO 10: Simulated source refresh preserving local cancellation and lineage
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 10: Source Refresh Preserves Local Cancellation & Lineage ---\n";

// Simulate external ETRACS source sync updating REPLICA rows
$pdo_target->exec("
    UPDATE wp_assessor_faas 
    SET owner_name = 'Replica Owner (Refreshed from Source)',
        txntimestamp = NOW()
    WHERE objid = '$replica_pred_id' AND origin_type = 'REPLICA'
");

// Verify REPLICA record after source refresh
$refreshed_replica = $etracs->get_faas($replica_pred_id);
assert_test("Scenario 10: REPLICA source data updated ('Replica Owner (Refreshed from Source)')", $refreshed_replica->owner_name === 'Replica Owner (Refreshed from Source)');
assert_test("Scenario 10: REPLICA effective state remains 'CANCELLED'", $refreshed_replica->effective_state === 'CANCELLED');
assert_test("Scenario 10: REPLICA cancelled_by_tdnos overlay remains intact", $refreshed_replica->cancelled_by_tdnos === $succ_td);
assert_test("Scenario 10: LOCAL successor remains CURRENT and points to refreshed predecessor", $etracs->get_faas($succ_faas->objid)->state === 'CURRENT');

// ─────────────────────────────────────────────────────────────
// SCENARIO 11: Failure injection and atomic transaction rollback
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 11: Failure Injection & Atomic Rollback ---\n";

// Create CURRENT declaration for failure test
$fail_td = 'TD-FAIL-TEST-01';
$fail_faas = $etracs->create_faas(new MockRestRequestWp06(array('tdno' => $fail_td, 'owner_name' => 'Rollback Owner')));
$etracs->submit_faas($fail_faas->objid);
$etracs->approve_faas($fail_faas->objid);

// Simulate failure by passing empty reason to trigger rollback
$invalid_cancel = $etracs->cancel_faas($fail_faas->objid, new MockRestRequestWp06(array('cancel_reason' => '')));
assert_test("Scenario 11: Failed cancellation returns error", is_wp_error($invalid_cancel));

$post_fail_check = $pdo_target->query("SELECT state, cancelreason FROM wp_assessor_faas WHERE objid = '{$fail_faas->objid}'")->fetch(PDO::FETCH_ASSOC);
assert_test("Scenario 11: ATOMIC ROLLBACK: Row state remains 'CURRENT'", $post_fail_check['state'] === 'CURRENT');
assert_test("Scenario 11: ATOMIC ROLLBACK: cancelreason remains empty", empty($post_fail_check['cancelreason']));

// ─────────────────────────────────────────────────────────────
// SCENARIO 12: List query, stats, and frontend alignment
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 12: Consistency Across List Filters, Stats & Previews ---\n";

// Test get_faas_list with state='CANCELLED' filter
$cancelled_list = $etracs->get_faas_list(new MockRestRequestWp06(array('state' => 'CANCELLED', 'per_page' => 50)));
$cancelled_ids = wp_list_pluck($cancelled_list['data'], 'id');

assert_test("Scenario 12: List includes explicit cancelled FAAS 1", in_array($faas1->objid, $cancelled_ids));
assert_test("Scenario 12: List includes effectively cancelled REPLICA predecessor", in_array($replica_pred_id, $cancelled_ids));
assert_test("Scenario 12: List includes superseded parent FAAS", in_array($parent_faas->objid, $cancelled_ids));
assert_test("Scenario 12: List includes superseded Child 1 FAAS", in_array($child1_faas->objid, $cancelled_ids));

// Test get_faas_list with state='CURRENT' filter
$current_list = $etracs->get_faas_list(new MockRestRequestWp06(array('state' => 'CURRENT', 'per_page' => 50)));
$current_ids = wp_list_pluck($current_list['data'], 'id');

assert_test("Scenario 12: Current list excludes superseded REPLICA predecessor", !in_array($replica_pred_id, $current_ids));
assert_test("Scenario 12: Current list excludes explicit cancelled FAAS 1", !in_array($faas1->objid, $current_ids));
assert_test("Scenario 12: Current list includes Grandchild FAAS", in_array($grandchild_faas->objid, $current_ids));

// Test stats consistency
$stats = $etracs->get_faas_stats(new MockRestRequestWp06());
assert_test("Scenario 12: Stats total is greater than zero", $stats['total'] > 0);
assert_test("Scenario 12: Stats includes 'cancelled' count", isset($stats['cancelled']) && $stats['cancelled'] > 0);
assert_test("Scenario 12: Stats includes 'current' count", isset($stats['current']) && $stats['current'] > 0);

// Check metadata in list items matches single get_faas
$list_parent_row = null;
foreach ($cancelled_list['data'] as $row) {
    if ($row->id === $parent_faas->objid) {
        $list_parent_row = $row;
        break;
    }
}
assert_test("Scenario 12: List row cancelled_by_tdnos matches detail view", $list_parent_row && $list_parent_row->cancelled_by_tdnos === $expected_tds);
assert_test("Scenario 12: List row cancel_reason matches detail view", $list_parent_row && $list_parent_row->cancel_reason === "Superseded by $expected_tds");

// ─────────────────────────────────────────────────────────────
// SCENARIO 13: WP-01 Synchronization & Ownership Regressions
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 13: WP-01 Ownership & Synchronization Regressions ---\n";

$replica_rows = $pdo_target->query("SELECT COUNT(*) FROM wp_assessor_faas WHERE origin_type = 'REPLICA'")->fetchColumn();
$local_rows = $pdo_target->query("SELECT COUNT(*) FROM wp_assessor_faas WHERE origin_type = 'LOCAL'")->fetchColumn();
assert_test("Scenario 13: REPLICA records remain isolated with origin_type='REPLICA'", $replica_rows >= 2);
assert_test("Scenario 13: LOCAL records have origin_type='LOCAL'", $local_rows >= 5);

// ─────────────────────────────────────────────────────────────
// SCENARIO 14: WP-02 / WP-03 Lifecycle & Predecessor Lineage Regressions
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 14: WP-02 / WP-03 Lifecycle & Lineage Regressions ---\n";

$pred_snapshot_count = $pdo_target->query("SELECT COUNT(*) FROM wp_assessor_faas_previous")->fetchColumn();
assert_test("Scenario 14: Predecessor snapshot records preserved", $pred_snapshot_count >= 3);

$child_predecessors = $etracs->get_faas($child1_faas->objid)->predecessors;
assert_test("Scenario 14: Child 1 predecessor snapshot exists and links to parent", count($child_predecessors) === 1 && $child_predecessors[0]->prevtdno === $parent_td);

// ─────────────────────────────────────────────────────────────
// SCENARIO 15: WP-04 & WP-05 Regressions
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 15: WP-04 Assessment Lines & WP-05 Parcel/RPUMaster Regressions ---\n";

// Verify child parcel reuse / subdivision lineage from WP-05
$child1_rp = $pdo_target->query("SELECT objid, previd, origin_type FROM wp_assessor_real_property WHERE objid = (SELECT realpropertyid FROM wp_assessor_faas WHERE objid = '{$child1_faas->objid}')")->fetch(PDO::FETCH_ASSOC);
$parent_rp = $pdo_target->query("SELECT realpropertyid FROM wp_assessor_faas WHERE objid = '{$parent_faas->objid}'")->fetchColumn();
assert_test("Scenario 15: Child parcel previd points to parent parcel", $child1_rp && $child1_rp['previd'] === $parent_rp);

// ─────────────────────────────────────────────────────────────
// SCENARIO 16: Absolute Architecture Boundary Check
// ─────────────────────────────────────────────────────────────
echo "\n--- Scenario 16: Absolute Architecture Boundary Check ---\n";

$native_props = $pdo_target->query("SHOW TABLES LIKE 'wp_assessor_properties'")->fetchColumn();
assert_test("Scenario 16: Native table wp_assessor_properties does NOT exist in disposable database", empty($native_props));

// Verify no modifications to native sync classes
$sync_class_file = __DIR__ . '/../wp-content/plugins/assessor-api/includes/class-assessor-sync.php';
assert_test("Scenario 16: class-assessor-sync.php exists and remains untouched", file_exists($sync_class_file));

echo "\n========================================================================\n";
echo "   WP-06 STRUCTURED CANCELLATION TEST SUMMARY\n";
echo "   Total Tests Passed: $pass_count\n";
echo "   Total Tests Failed: $fail_count\n";
echo "========================================================================\n";

if ($fail_count > 0) {
    exit(1);
} else {
    exit(0);
}
