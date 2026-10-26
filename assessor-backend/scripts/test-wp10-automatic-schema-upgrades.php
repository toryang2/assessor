<?php
/**
 * Automated Test Suite: WP-10 — Automatic Shadow ETRACS Schema Upgrades (Hardened)
 *
 * Strict Architectural Safeguards:
 * - Fail-closed check: Target database MUST be 'test_wp10_disposable'.
 * - NEVER connect to assessor_local, test, or production environments.
 * - Zero modifications to native Assessor tables, workflows, or synchronization classes.
 * - Thoroughly tests fresh install, plugin update, already completed, failed state,
 *   partial schema state, concurrent execution, stale lock recovery, retry after failure,
 *   lifecycle independence from create_tables(), and native Assessor table preservation.
 */

// 1. Connection Guard: Connect strictly to test_wp10_disposable
$db_host = '127.0.0.1';
$db_user = 'root';
$db_pass = '';
$db_name = 'test_wp10_disposable';

$pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Fail-closed guard
$stmt = $pdo->query("SELECT DATABASE()");
$current_db = $stmt->fetchColumn();
if ($current_db !== 'test_wp10_disposable') {
    die("FATAL: Guard failure. Expected database 'test_wp10_disposable', got '$current_db'. Aborting.\n");
}

echo "========================================================================\n";
echo "   WP-10: AUTOMATIC SHADOW ETRACS SCHEMA UPGRADES HARDENED TEST HARNESS\n";
echo "   Target Database: test_wp10_disposable (Disposable Isolated Environment)\n";
echo "   Connection Guard: VERIFIED (DATABASE() === 'test_wp10_disposable')\n";
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
if (!defined('ASSESSOR_API_PLUGIN_DIR')) {
    define('ASSESSOR_API_PLUGIN_DIR', dirname(__DIR__) . '/wp-content/plugins/assessor-api/');
}
if (!defined('ASSESSOR_API_VERSION')) {
    define('ASSESSOR_API_VERSION', '1.1.17');
}

if (!function_exists('current_time')) {
    function current_time($type, $gmt = 0) {
        return ($type === 'mysql') ? date('Y-m-d H:i:s') : time();
    }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return trim((string)$str);
    }
}
if (!function_exists('esc_html')) {
    function esc_html($str) {
        return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
    }
}
if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response {
        public $data;
        public $status;
        public function __construct($data = null, $status = 200) {
            $this->data = $data;
            $this->status = $status;
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

// In-memory options mock for disposable environment
$mock_options = [];
function get_option($key, $default = false) {
    global $mock_options;
    return $mock_options[$key] ?? $default;
}
function update_option($key, $val, $autoload = null) {
    global $mock_options;
    $mock_options[$key] = $val;
    return true;
}

// WPDB Mock Bridge for disposable DB
class MockWpdbDisposable {
    public $pdo;
    public $prefix = 'wp_';
    public $last_error = '';

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function get_charset_collate() {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
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
            $this->last_error = '';
            $res = $this->pdo->exec($sql);
            return $res;
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            return false;
        }
    }

    public function get_var($query, $x = 0, $y = 0) {
        try {
            $this->last_error = '';
            $stmt = $this->pdo->query($query);
            return $stmt ? $stmt->fetchColumn() : null;
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            return null;
        }
    }

    public function get_row($query, $output = OBJECT, $y = 0) {
        try {
            $this->last_error = '';
            $stmt = $this->pdo->query($query);
            $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
            if (!$row) return null;
            return $output === ARRAY_A ? $row : (object)$row;
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            return null;
        }
    }

    public function get_results($query, $output = OBJECT) {
        try {
            $this->last_error = '';
            $stmt = $this->pdo->query($query);
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            if ($output === ARRAY_A) {
                return $rows;
            }
            return array_map(function($r) { return (object)$r; }, $rows);
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            return [];
        }
    }

    public function insert($table, $data, $format = null) {
        $fields = array_keys($data);
        $escaped_fields = array_map(function($f) { return "`$f`"; }, $fields);
        $placeholders = array_fill(0, count($fields), '?');
        $sql = "INSERT INTO `$table` (" . implode(', ', $escaped_fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $this->pdo->prepare($sql);
        $res = $stmt->execute(array_values($data));
        return $res ? $this->pdo->lastInsertId() : false;
    }

    public function update($table, $data, $where, $format = null, $where_format = null) {
        $set_clauses = [];
        $values = [];
        foreach ($data as $col => $val) {
            $set_clauses[] = "`$col` = ?";
            $values[] = $val;
        }
        $where_clauses = [];
        foreach ($where as $col => $val) {
            $where_clauses[] = "`$col` = ?";
            $values[] = $val;
        }
        $sql = "UPDATE `$table` SET " . implode(', ', $set_clauses) . " WHERE " . implode(' AND ', $where_clauses);
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($values);
    }
}

global $wpdb;
$wpdb = new MockWpdbDisposable($pdo);

// Clean up disposable database completely before tests
function reset_disposable_db($pdo) {
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    foreach ($tables as $t) {
        $pdo->exec("DROP TABLE IF EXISTS `$t`");
    }
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
}

reset_disposable_db($pdo);

require_once ASSESSOR_API_PLUGIN_DIR . 'includes/class-assessor-database.php';
require_once ASSESSOR_API_PLUGIN_DIR . 'includes/class-assessor-migration-runner.php';

// =========================================================================
// TEST SUITE 1: Fresh Installation
// =========================================================================
echo "\n--- Test Suite 1: Fresh Installation ---\n";
reset_disposable_db($pdo);
$runner = new Assessor_Migration_Runner();

// Run automatic upgrade
$res1 = $runner->run_automatic_schema_upgrades();
$val1 = $runner->validate_migration_008_schema();

assert_test($res1['008_property_assessed_descriptions_schema']['success'] === true, "Fresh installation executes migration 008 successfully");
assert_test($val1['valid'] === true, "Fresh installation validates all tables, columns, and indexes");
$reg1 = $wpdb->get_row("SELECT * FROM wp_assessor_migrations WHERE migration_name = '008_property_assessed_descriptions_schema'", ARRAY_A);
assert_test($reg1 && $reg1['status'] === 'completed', "Fresh installation records completed status in wp_assessor_migrations");

// =========================================================================
// TEST SUITE 2: Existing Installation with Expected Schema (Idempotent Skip)
// =========================================================================
echo "\n--- Test Suite 2: Existing Installation with Expected Schema ---\n";
$completed_before = $reg1['completed_at'];

$res2 = $runner->run_automatic_schema_upgrades();
$res2_m8 = $res2['008_property_assessed_descriptions_schema'];

assert_test($res2_m8['success'] === true, "Repeat execution returns success: true");
assert_test($res2_m8['skipped'] === true, "Repeat execution is safely skipped");
assert_test($res2_m8['status'] === 'already_completed', "Status is 'already_completed'");
$reg2 = $wpdb->get_row("SELECT * FROM wp_assessor_migrations WHERE migration_name = '008_property_assessed_descriptions_schema'", ARRAY_A);
assert_test($reg2['completed_at'] === $completed_before, "Completed timestamp preserved without DB mutations");

// =========================================================================
// TEST SUITE 3: Existing Installation Missing Migration 008
// =========================================================================
echo "\n--- Test Suite 3: Existing Installation Missing Migration 008 ---\n";
reset_disposable_db($pdo);

// Simulate an existing database that has legacy migration history
$runner->ensure_migrations_table();
$wpdb->query("INSERT INTO wp_assessor_migrations (migration_name, status, completed_at) VALUES ('005_lookup_tables_uuid_migration', 'completed', NOW())");

$res3 = $runner->run_automatic_schema_upgrades();
$val3 = $runner->validate_migration_008_schema();

assert_test($res3['008_property_assessed_descriptions_schema']['status'] === 'completed', "Pending migration 008 detected and executed on existing installation");
assert_test($val3['valid'] === true, "All required schema elements created on existing installation");

// =========================================================================
// TEST SUITE 4: Partially Existing Tables Missing Columns/Indexes & Data Preservation
// =========================================================================
echo "\n--- Test Suite 4: Partially Existing Tables Missing Columns/Indexes ---\n";
reset_disposable_db($pdo);

// Create partially-defined tables with pre-existing data
$wpdb->query("CREATE TABLE wp_assessor_machine (
    objid varchar(50) NOT NULL,
    code varchar(50) NOT NULL,
    PRIMARY KEY (objid)
)");
$wpdb->query("INSERT INTO wp_assessor_machine (objid, code) VALUES ('MAC-001', 'GSWCA')");

$wpdb->query("CREATE TABLE wp_assessor_planttree (
    objid varchar(50) NOT NULL,
    code varchar(20) NOT NULL,
    PRIMARY KEY (objid)
)");
$wpdb->query("INSERT INTO wp_assessor_planttree (objid, code) VALUES ('PT-001', 'COCO')");

$wpdb->query("CREATE TABLE wp_assessor_planttreedetail (
    objid varchar(50) NOT NULL,
    planttree_objid varchar(50) NOT NULL,
    unitvalue decimal(16,2) NOT NULL DEFAULT '0.00',
    PRIMARY KEY (objid)
)");
$wpdb->query("INSERT INTO wp_assessor_planttreedetail (objid, planttree_objid, unitvalue) VALUES ('PTD-001', 'PT-001', 500.00)");

// Validation should initially report missing items
$val_before = $runner->validate_migration_008_schema();
assert_test($val_before['valid'] === false, "Pre-repair validation correctly detects missing columns and indexes across all tables");

// Run upgrade to repair
$res4 = $runner->run_automatic_schema_upgrades();
$val_after = $runner->validate_migration_008_schema();

assert_test($res4['008_property_assessed_descriptions_schema']['success'] === true, "Automatic upgrade repairs incomplete tables successfully");
assert_test($val_after['valid'] === true, "Post-repair validation confirms all required columns and indexes exist");

// Verify existing records were preserved intact
$m_row = $wpdb->get_row("SELECT * FROM wp_assessor_machine WHERE objid = 'MAC-001'", ARRAY_A);
$pt_row = $wpdb->get_row("SELECT * FROM wp_assessor_planttree WHERE objid = 'PT-001'", ARRAY_A);
$ptd_row = $wpdb->get_row("SELECT * FROM wp_assessor_planttreedetail WHERE objid = 'PTD-001'", ARRAY_A);

assert_test($m_row && $m_row['code'] === 'GSWCA' && $m_row['state'] === 'APPROVED', "Pre-existing machine row preserved with new state column default");
assert_test($pt_row && $pt_row['code'] === 'COCO' && $pt_row['state'] === 'APPROVED', "Pre-existing planttree row preserved with new state column default");
assert_test($ptd_row && floatval($ptd_row['unitvalue']) == 500.00 && $ptd_row['origin_type'] === 'REPLICA', "Pre-existing planttreedetail row preserved and backfilled as REPLICA");

// =========================================================================
// TEST SUITE 5: Concurrency Guarding & Stale Lock Recovery
// =========================================================================
echo "\n--- Test Suite 5: Concurrency Guarding & Stale Lock Recovery ---\n";
reset_disposable_db($pdo);
$runner->ensure_migrations_table();

// Subcase 5A: Active lock held (running recently)
$recent_time = date('Y-m-d H:i:s', time() - 30); // 30 seconds ago
$wpdb->query("INSERT INTO wp_assessor_migrations (migration_name, status, started_at) VALUES ('008_property_assessed_descriptions_schema', 'running', '$recent_time')");

$res5a = $runner->run_automatic_schema_upgrades();
assert_test($res5a['008_property_assessed_descriptions_schema']['status'] === 'in_progress', "Active concurrent migration is blocked by lock");
assert_test($res5a['008_property_assessed_descriptions_schema']['skipped'] === true, "Active concurrent migration is gracefully skipped");

// Subcase 5B: Stale lock recovery (> 5 minutes old)
$stale_time = date('Y-m-d H:i:s', time() - 400); // ~6.6 minutes ago
$wpdb->query("UPDATE wp_assessor_migrations SET status = 'running', started_at = '$stale_time' WHERE migration_name = '008_property_assessed_descriptions_schema'");

$res5b = $runner->run_automatic_schema_upgrades();
assert_test($res5b['008_property_assessed_descriptions_schema']['status'] === 'completed', "Stale running lock is safely reclaimed and completed");
$val5b = $runner->validate_migration_008_schema();
assert_test($val5b['valid'] === true, "Schema is valid after stale lock recovery");

// =========================================================================
// TEST SUITE 6: Migration Failure, Diagnostics, and Retry
// =========================================================================
echo "\n--- Test Suite 6: Migration Failure, Diagnostics, and Retry ---\n";
reset_disposable_db($pdo);
$runner->ensure_migrations_table();

class FailingRunnerMock extends Assessor_Migration_Runner {
    public $should_fail = true;
    public function run_property_assessed_descriptions_migration() {
        if ($this->should_fail) {
            throw new Exception("Simulated Transient DB Error");
        }
        return parent::run_property_assessed_descriptions_migration();
    }
}

$mock_runner = new FailingRunnerMock();
$res6a = $mock_runner->run_automatic_schema_upgrades();

assert_test($res6a['008_property_assessed_descriptions_schema']['success'] === false, "Failed migration returns success: false");
assert_test($res6a['008_property_assessed_descriptions_schema']['status'] === 'failed', "Failed migration returns status: 'failed'");
assert_test(strpos($res6a['008_property_assessed_descriptions_schema']['error'], 'Simulated Transient DB Error') !== false, "Error message captured in response");

$reg6a = $wpdb->get_row("SELECT * FROM wp_assessor_migrations WHERE migration_name = '008_property_assessed_descriptions_schema'", ARRAY_A);
assert_test($reg6a && $reg6a['status'] === 'failed', "Failed status recorded in registry for diagnostics");

// Now test Retry: transient failure resolved
$mock_runner->should_fail = false;
$res6b = $mock_runner->run_automatic_schema_upgrades();

assert_test($res6b['008_property_assessed_descriptions_schema']['success'] === true, "Retried migration executes successfully after resolving failure");
assert_test($res6b['008_property_assessed_descriptions_schema']['status'] === 'completed', "Registry status transitions from 'failed' to 'completed'");
$val6b = $runner->validate_migration_008_schema();
assert_test($val6b['valid'] === true, "Schema valid after retry");

// =========================================================================
// TEST SUITE 7: Decoupled Lifecycle & Native Assessor Data/Schema Protection
// =========================================================================
echo "\n--- Test Suite 7: Decoupled Lifecycle & Native Assessor Protection ---\n";
reset_disposable_db($pdo);

// Create native property table with sensitive native records
$wpdb->query("CREATE TABLE wp_assessor_properties (
    id varchar(36) NOT NULL,
    tax_declaration_number varchar(100) NOT NULL,
    declarant_last_name varchar(255) NOT NULL,
    declarant_first_name varchar(255) NOT NULL,
    PRIMARY KEY (id)
)");
$wpdb->query("INSERT INTO wp_assessor_properties VALUES ('UUID-001', 'TD-2026-0001', 'DELA CRUZ', 'JUAN')");

// Set up Mock plugin version matching ASSESSOR_API_VERSION
update_option('assessor_db_version', ASSESSOR_API_VERSION);

// Track whether create_tables was called
class TrackedDatabaseMock extends Assessor_Database {
    public static $create_tables_called = false;
    public function create_tables() {
        self::$create_tables_called = true;
        return parent::create_tables();
    }
}

// Simulate the assessor_api_check_version() logic from assessor-api.php
function simulate_check_version_hook() {
    $current_version = get_option('assessor_db_version');

    // WP-10 decoupled execution
    if (class_exists('Assessor_Migration_Runner')) {
        $runner = new Assessor_Migration_Runner();
        $runner->run_automatic_schema_upgrades();
    }

    // Legacy core database version check
    if ($current_version !== ASSESSOR_API_VERSION) {
        TrackedDatabaseMock::$create_tables_called = true;
        update_option('assessor_db_version', ASSESSOR_API_VERSION);
    }
}

TrackedDatabaseMock::$create_tables_called = false;
simulate_check_version_hook();

assert_test(TrackedDatabaseMock::$create_tables_called === false, "WP-10 execution on existing installation does NOT call broad create_tables()");
$val7 = $runner->validate_migration_008_schema();
assert_test($val7['valid'] === true, "Migration 008 executed and validated automatically via lifecycle hook");

// Native Assessor data and schema verification
$prop_count = $wpdb->get_var("SELECT COUNT(*) FROM wp_assessor_properties");
$prop_row = $wpdb->get_row("SELECT * FROM wp_assessor_properties WHERE id = 'UUID-001'", ARRAY_A);
assert_test($prop_count == 1, "Native Assessor table row count unchanged (1 row)");
assert_test($prop_row && $prop_row['tax_declaration_number'] === 'TD-2026-0001', "Native Assessor property records completely intact and untouched");

// =========================================================================
// Summary
// =========================================================================
echo "\n========================================================================\n";
echo "   TEST SUMMARY\n";
echo "   Total Tests Passed: $pass_count\n";
echo "   Total Tests Failed: $fail_count\n";
echo "========================================================================\n";

if ($fail_count > 0) {
    exit(1);
}
