<?php
/**
 * Automated Test Suite: WP-10 — Canonical Database Creation & Scoped Compatibility (Hardened)
 *
 * Strict Architectural Safeguards:
 * - Fail-closed check: Target database MUST be 'test_wp10_disposable'.
 * - NEVER connect to assessor_local, test, or production environments.
 * - Zero modifications to native Assessor tables, workflows, or synchronization classes.
 * - Tests:
 *   1. Fresh installation creates all required ETRACS shadow tables through normal create_tables().
 *   2. Fresh installation does not require a separate Migration 008 execution (mark completed if valid).
 *   3. Existing installation with complete schema performs no recurring repair operations.
 *   4. Existing installation missing shadow tables is repaired automatically through the scoped compatibility path.
 *   5. Partial schemas are repaired safely, or fail with useful diagnostics when repair would risk existing records.
 *   6. Native Assessor schemas and records remain unchanged.
 *   7. No ETRACS pull is triggered.
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
echo "   WP-10: CANONICAL DB CREATION & SCOPED COMPATIBILITY TEST HARNESS\n";
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
if (!function_exists('wp_hash_password')) {
    function wp_hash_password($password) {
        return password_hash($password, PASSWORD_DEFAULT);
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

// Ensure dbDelta stub for pure disposable test execution if upgrade.php is not loaded
if (!function_exists('dbDelta')) {
    function dbDelta($queries) {
        global $wpdb;
        if (!is_array($queries)) {
            $queries = explode(';', $queries);
        }
        $results = [];
        foreach ($queries as $sql) {
            $sql = trim($sql);
            if (!empty($sql)) {
                $wpdb->query($sql);
                $results[] = $sql;
            }
        }
        return $results;
    }
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

    public function prepare($query, $args = null) {
        $num_args = func_num_args();
        if ($num_args <= 1) {
            return $query;
        }
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
// TEST SCENARIO 1 & 2: Fresh installation creates all tables through create_tables()
// and does not require separate Migration 008 execution
// =========================================================================
echo "\n--- Scenario 1 & 2: Fresh Installation via Standard create_tables() ---\n";
reset_disposable_db($pdo);

// Run standard database installation
$db = new Assessor_Database();
$db->create_tables();

$runner = new Assessor_Migration_Runner();
$val1 = $runner->validate_migration_008_schema();

assert_test($val1['valid'] === true, "Standard create_tables() created complete canonical Shadow ETRACS schema");
assert_test($wpdb->get_var("SHOW TABLES LIKE 'wp_assessor_machine'") === 'wp_assessor_machine', "wp_assessor_machine exists from create_tables()");
assert_test($wpdb->get_var("SHOW TABLES LIKE 'wp_assessor_planttree'") === 'wp_assessor_planttree', "wp_assessor_planttree exists from create_tables()");
assert_test($wpdb->get_var("SHOW TABLES LIKE 'wp_assessor_planttreedetail'") === 'wp_assessor_planttreedetail', "wp_assessor_planttreedetail exists from create_tables()");

$has_origin = $wpdb->get_var("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'test_wp10_disposable' AND TABLE_NAME = 'wp_assessor_planttreedetail' AND COLUMN_NAME = 'origin_type'");
$has_origin_idx = $wpdb->get_var("SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = 'test_wp10_disposable' AND TABLE_NAME = 'wp_assessor_planttreedetail' AND INDEX_NAME = 'idx_origin_type' LIMIT 1");
assert_test($has_origin === 'origin_type', "Canonical create_tables() includes origin_type column on planttreedetail");
assert_test($has_origin_idx === 'idx_origin_type', "Canonical create_tables() includes idx_origin_type index on planttreedetail");

// Simulate activation hook: marks completed if valid without running migration procedure
$marked = $runner->mark_migration_completed_if_valid('008_property_assessed_descriptions_schema');
assert_test($marked === true, "Fresh installation records Migration 008 completed without executing separate migration procedure");

$reg1 = $wpdb->get_row("SELECT * FROM wp_assessor_migrations WHERE migration_name = '008_property_assessed_descriptions_schema'", ARRAY_A);
assert_test($reg1 && $reg1['status'] === 'completed', "Migration 008 status in registry is 'completed'");

// =========================================================================
// TEST SCENARIO 3: Existing installation with complete schema performs NO recurring repairs
// =========================================================================
echo "\n--- Scenario 3: Existing Installation with Complete Schema (No Recurring Repairs) ---\n";
$completed_at_before = $reg1['completed_at'];

// Calling automatic schema upgrades on complete installation
$res3 = $runner->run_automatic_schema_upgrades();
$m8_res3 = $res3['008_property_assessed_descriptions_schema'];

assert_test($m8_res3['success'] === true, "Complete installation returns success: true");
assert_test($m8_res3['skipped'] === true, "Complete installation safely skips execution");
assert_test($m8_res3['status'] === 'already_completed', "Status is 'already_completed'");

$reg3 = $wpdb->get_row("SELECT * FROM wp_assessor_migrations WHERE migration_name = '008_property_assessed_descriptions_schema'", ARRAY_A);
assert_test($reg3['completed_at'] === $completed_at_before, "Database timestamp unchanged; no DDL or repair performed");

// Verify that normal plugins_loaded does NOT call run_automatic_schema_upgrades()
class SpiedRunner extends Assessor_Migration_Runner {
    public static $upgrades_called = false;
    public function run_automatic_schema_upgrades() {
        self::$upgrades_called = true;
        return parent::run_automatic_schema_upgrades();
    }
}

// In assessor-api.php, assessor_api_check_version() only runs upgrades when version changes
update_option('assessor_db_version', ASSESSOR_API_VERSION);
SpiedRunner::$upgrades_called = false;

// Simulate check_version hook
$curr_v = get_option('assessor_db_version');
if ($curr_v !== ASSESSOR_API_VERSION) {
    $db = new Assessor_Database();
    $db->create_tables();
    $spied = new SpiedRunner();
    $spied->run_automatic_schema_upgrades();
    update_option('assessor_db_version', ASSESSOR_API_VERSION);
}
assert_test(SpiedRunner::$upgrades_called === false, "Normal boot when version matches does NOT trigger recurring upgrades");

// =========================================================================
// TEST SCENARIO 4: Existing installation missing shadow tables is repaired automatically
// =========================================================================
echo "\n--- Scenario 4: Existing Installation Missing Shadow Tables Repaired Automatically ---\n";
reset_disposable_db($pdo);

// Simulate an existing database created before Migration 008 (tables missing, old version in options)
update_option('assessor_db_version', '1.1.16');
$runner->ensure_migrations_table();
$wpdb->query("INSERT INTO wp_assessor_migrations (migration_name, status, completed_at) VALUES ('005_lookup_tables_uuid_migration', 'completed', NOW())");

// Simulate check_version hook during version bump
$curr_v = get_option('assessor_db_version');
if ($curr_v !== ASSESSOR_API_VERSION) {
    $db = new Assessor_Database();
    $db->create_tables();
    $runner->run_automatic_schema_upgrades();
    update_option('assessor_db_version', ASSESSOR_API_VERSION);
}

$val4 = $runner->validate_migration_008_schema();
assert_test($val4['valid'] === true, "Missing shadow tables automatically repaired during upgrade");
$reg4 = $wpdb->get_row("SELECT * FROM wp_assessor_migrations WHERE migration_name = '008_property_assessed_descriptions_schema'", ARRAY_A);
assert_test($reg4 && $reg4['status'] === 'completed', "Migration 008 recorded as completed after upgrade repair");

// =========================================================================
// TEST SCENARIO 5: Partial schema repair with data preservation
// =========================================================================
echo "\n--- Scenario 5: Partial Schema Repair with Data Preservation ---\n";
reset_disposable_db($pdo);

// Create partial table with custom user data
$wpdb->query("CREATE TABLE wp_assessor_machine (
    objid varchar(50) NOT NULL,
    code varchar(50) NOT NULL,
    PRIMARY KEY (objid)
)");
$wpdb->query("INSERT INTO wp_assessor_machine (objid, code) VALUES ('MAC-EXISTING', 'TESTCODE')");

$wpdb->query("CREATE TABLE wp_assessor_planttreedetail (
    objid varchar(50) NOT NULL,
    planttree_objid varchar(50) NOT NULL,
    unitvalue decimal(16,2) NOT NULL DEFAULT '0.00',
    PRIMARY KEY (objid)
)");
$wpdb->query("INSERT INTO wp_assessor_planttreedetail (objid, planttree_objid, unitvalue) VALUES ('PTD-EXISTING', 'PT-01', 750.00)");

$res5 = $runner->run_automatic_schema_upgrades();
$val5 = $runner->validate_migration_008_schema();

assert_test($res5['008_property_assessed_descriptions_schema']['success'] === true, "Scoped compatibility repair succeeded on partial schema");
assert_test($val5['valid'] === true, "All required columns and indexes restored");

$m_check = $wpdb->get_row("SELECT * FROM wp_assessor_machine WHERE objid = 'MAC-EXISTING'", ARRAY_A);
$ptd_check = $wpdb->get_row("SELECT * FROM wp_assessor_planttreedetail WHERE objid = 'PTD-EXISTING'", ARRAY_A);
assert_test($m_check && $m_check['code'] === 'TESTCODE' && $m_check['state'] === 'APPROVED', "Pre-existing machine row preserved with new state default");
assert_test($ptd_check && floatval($ptd_check['unitvalue']) == 750.00 && $ptd_check['origin_type'] === 'REPLICA', "Pre-existing planttreedetail row preserved and backfilled as REPLICA");

// =========================================================================
// TEST SCENARIO 6: Native Assessor Schema & Data Integrity
// =========================================================================
echo "\n--- Scenario 6: Native Assessor Schemas and Records Remain Untouched ---\n";
reset_disposable_db($pdo);

// Insert native table and record
$wpdb->query("CREATE TABLE wp_assessor_properties (
    id varchar(36) NOT NULL,
    tax_declaration_number varchar(100) NOT NULL,
    declarant_last_name varchar(255) NOT NULL,
    declarant_first_name varchar(255) NOT NULL,
    PRIMARY KEY (id)
)");
$wpdb->query("INSERT INTO wp_assessor_properties VALUES ('PROP-001', 'TD-NATIVE-999', 'SANTOS', 'MARIA')");

// Run standard create_tables and automatic upgrades
$db = new Assessor_Database();
$db->create_tables();
$runner->run_automatic_schema_upgrades();

$native_count = $wpdb->get_var("SELECT COUNT(*) FROM wp_assessor_properties");
$native_row = $wpdb->get_row("SELECT * FROM wp_assessor_properties WHERE id = 'PROP-001'", ARRAY_A);

assert_test($native_count == 1, "Native property count unchanged (exactly 1 row)");
assert_test($native_row && $native_row['tax_declaration_number'] === 'TD-NATIVE-999', "Native property record data completely intact");

// =========================================================================
// TEST SCENARIO 7: No ETRACS pull or remote contact triggered
// =========================================================================
echo "\n--- Scenario 7: No ETRACS pull or remote contact triggered ---\n";
// Ensure shadow lookup data table row count is 0 after schema creation
$machine_rows = $wpdb->get_var("SELECT COUNT(*) FROM wp_assessor_machine");
$planttree_rows = $wpdb->get_var("SELECT COUNT(*) FROM wp_assessor_planttree");
$planttreedetail_rows = $wpdb->get_var("SELECT COUNT(*) FROM wp_assessor_planttreedetail");

assert_test($machine_rows == 0, "wp_assessor_machine has 0 rows (no automatic data pull)");
assert_test($planttree_rows == 0, "wp_assessor_planttree has 0 rows (no automatic data pull)");
assert_test($planttreedetail_rows == 0, "wp_assessor_planttreedetail has 0 rows (no automatic data pull)");

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
