<?php
/**
 * Automated Test Suite: WP-10 — Automatic Shadow ETRACS Schema Upgrades
 *
 * Strict Architectural Safeguards:
 * - Fail-closed check: Target database MUST be 'test_wp10_disposable'.
 * - NEVER connect to assessor_local, test, or production environments.
 * - Zero modifications to native Assessor tables, workflows, or synchronization classes.
 * - Thoroughly tests fresh install, plugin update, already completed, failed state,
 *   partial schema state, and concurrent execution.
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
echo "   WP-10: AUTOMATIC SHADOW ETRACS SCHEMA UPGRADES TEST HARNESS\n";
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
            return $this->pdo->exec($sql);
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

// Simulate fresh activation
$runner->run_automatic_schema_upgrades();

// Verify tables exist
$has_mach = $wpdb->get_var("SHOW TABLES LIKE 'wp_assessor_machine'") === 'wp_assessor_machine';
$has_pt = $wpdb->get_var("SHOW TABLES LIKE 'wp_assessor_planttree'") === 'wp_assessor_planttree';
$has_ptd = $wpdb->get_var("SHOW TABLES LIKE 'wp_assessor_planttreedetail'") === 'wp_assessor_planttreedetail';
$has_orig = $wpdb->get_var("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'test_wp10_disposable' AND TABLE_NAME = 'wp_assessor_planttreedetail' AND COLUMN_NAME = 'origin_type'") === 'origin_type';
$has_idx = $wpdb->get_var("SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = 'test_wp10_disposable' AND TABLE_NAME = 'wp_assessor_planttreedetail' AND INDEX_NAME = 'idx_origin_type' LIMIT 1") === 'idx_origin_type';

assert_test($has_mach, "Fresh activation creates wp_assessor_machine");
assert_test($has_pt, "Fresh activation creates wp_assessor_planttree");
assert_test($has_ptd, "Fresh activation creates wp_assessor_planttreedetail");
assert_test($has_orig, "Fresh activation ensures origin_type on planttreedetail");
assert_test($has_idx, "Fresh activation ensures idx_origin_type index on planttreedetail");

// Verify registry entry
$reg = $wpdb->get_row("SELECT * FROM wp_assessor_migrations WHERE migration_name = '008_property_assessed_descriptions_schema'", ARRAY_A);
assert_test($reg && $reg['status'] === 'completed', "Fresh activation records migration 008 as completed in registry");

// =========================================================================
// TEST SUITE 2: Already-Completed Migration (Idempotency)
// =========================================================================
echo "\n--- Test Suite 2: Already-Completed Migration (Idempotent Skip) ---\n";
$completed_before = $reg['completed_at'];

// Run upgrade again
$res2 = $runner->run_automatic_schema_upgrades();
$res2_m8 = $res2['008_property_assessed_descriptions_schema'];

assert_test($res2_m8['success'] === true, "Already-completed migration returns success: true");
assert_test($res2_m8['skipped'] === true, "Already-completed migration is safely skipped");
assert_test($res2_m8['status'] === 'already_completed', "Status is 'already_completed'");

$reg2 = $wpdb->get_row("SELECT * FROM wp_assessor_migrations WHERE migration_name = '008_property_assessed_descriptions_schema'", ARRAY_A);
assert_test($reg2['completed_at'] === $completed_before, "Completed timestamp is preserved without re-mutating DB");

// =========================================================================
// TEST SUITE 3: Already-Active Plugin Upgrade (Pending Migration)
// =========================================================================
echo "\n--- Test Suite 3: Already-Active Plugin Upgrade ---\n";
reset_disposable_db($pdo);

// Simulate an existing active database that had Migration 005 recorded but not 008
$wpdb->query("CREATE TABLE wp_assessor_migrations (
    id bigint(20) NOT NULL AUTO_INCREMENT,
    migration_name varchar(100) NOT NULL,
    status varchar(20) NOT NULL DEFAULT 'pending',
    started_at datetime DEFAULT NULL,
    completed_at datetime DEFAULT NULL,
    error text DEFAULT NULL,
    batch int NOT NULL DEFAULT 1,
    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY migration_name (migration_name),
    KEY status (status)
)");
$wpdb->query("INSERT INTO wp_assessor_migrations (migration_name, status, completed_at) VALUES ('005_lookup_tables_uuid_migration', 'completed', NOW())");

// Run automatic upgrade
$res3 = $runner->run_automatic_schema_upgrades();
$res3_m8 = $res3['008_property_assessed_descriptions_schema'];

assert_test($res3_m8['success'] === true && $res3_m8['status'] === 'completed', "Pending migration 008 executed successfully on upgraded installation");
$reg3 = $wpdb->get_row("SELECT * FROM wp_assessor_migrations WHERE migration_name = '008_property_assessed_descriptions_schema'", ARRAY_A);
assert_test($reg3 && $reg3['status'] === 'completed', "Registry updated with completed status for migration 008");
assert_test($wpdb->get_var("SHOW TABLES LIKE 'wp_assessor_machine'") === 'wp_assessor_machine', "wp_assessor_machine table created during upgrade");

// =========================================================================
// TEST SUITE 4: Partial Schema State (Self-Healing / Repair)
// =========================================================================
echo "\n--- Test Suite 4: Partial Schema State (Pre-existing table without origin_type) ---\n";
reset_disposable_db($pdo);

// Pre-create table without origin_type
$wpdb->query("CREATE TABLE wp_assessor_planttreedetail (
    objid varchar(50) NOT NULL,
    planttree_objid varchar(50) NOT NULL,
    unitvalue decimal(16,2) NOT NULL DEFAULT '0.00',
    PRIMARY KEY (objid)
)");

// Mark completed in migrations table (simulating inconsistency)
$runner->ensure_migrations_table();
$wpdb->query("INSERT INTO wp_assessor_migrations (migration_name, status, completed_at) VALUES ('008_property_assessed_descriptions_schema', 'completed', NOW())");

// Run upgrade — should detect partial schema inconsistency and repair
$res4 = $runner->run_automatic_schema_upgrades();
$val4 = $runner->validate_migration_008_schema();

assert_test($val4['valid'] === true, "Partial schema state detected and repaired successfully");
$has_orig_repaired = $wpdb->get_var("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'test_wp10_disposable' AND TABLE_NAME = 'wp_assessor_planttreedetail' AND COLUMN_NAME = 'origin_type'") === 'origin_type';
assert_test($has_orig_repaired, "origin_type column successfully added to existing partial table");

// =========================================================================
// TEST SUITE 5: Concurrency Guarding
// =========================================================================
echo "\n--- Test Suite 5: Concurrency Guarding ---\n";
// Set status to running recently
$wpdb->query("UPDATE wp_assessor_migrations SET status = 'running', started_at = NOW() WHERE migration_name = '008_property_assessed_descriptions_schema'");

$res5 = $runner->run_automatic_schema_upgrades();
$res5_m8 = $res5['008_property_assessed_descriptions_schema'];

assert_test($res5_m8['status'] === 'in_progress', "Concurrent run detected and blocked by lock");
assert_test($res5_m8['skipped'] === true, "Concurrent run gracefully skipped without crashing");

// =========================================================================
// TEST SUITE 6: Failed Migration Handling & Logging
// =========================================================================
echo "\n--- Test Suite 6: Failed Migration Handling & Diagnostics ---\n";
reset_disposable_db($pdo);
$runner->ensure_migrations_table();

// Create a conflicting view or table structure that forces failure if attempted
// or mock error by corrupting the runner handler temporarily
class FailingRunner extends Assessor_Migration_Runner {
    public function run_property_assessed_descriptions_migration() {
        throw new Exception("Simulated DB Disk Full or Syntax Error");
    }
}

$fail_runner = new FailingRunner();
$res6 = $fail_runner->run_automatic_schema_upgrades();
$res6_m8 = $res6['008_property_assessed_descriptions_schema'];

assert_test($res6_m8['success'] === false, "Failed migration returns success: false");
assert_test($res6_m8['status'] === 'failed', "Failed migration returns status: 'failed'");
assert_test(strpos($res6_m8['error'], 'Simulated DB Disk Full') !== false, "Diagnostic error message captured in response");

$reg6 = $wpdb->get_row("SELECT * FROM wp_assessor_migrations WHERE migration_name = '008_property_assessed_descriptions_schema'", ARRAY_A);
assert_test($reg6['status'] === 'failed', "Database registry accurately records 'failed' status");
assert_test(!empty($reg6['error']), "Database registry records specific error message for post-mortem diagnosis");

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
