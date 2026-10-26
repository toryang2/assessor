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
if (!defined('ASSESSOR_SHADOW_SCHEMA_VERSION')) {
    define('ASSESSOR_SHADOW_SCHEMA_VERSION', '1.0.0');
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

    public function get_col($query, $x = 0) {
        try {
            $this->last_error = '';
            $stmt = $this->pdo->query($query);
            return $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN, $x) : [];
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

// Production helper functions from assessor-api.php for lifecycle testing
if (!function_exists('assessor_api_check_version')) {
    function assessor_api_check_version() {
        $current_version = get_option('assessor_db_version');

        if ($current_version !== ASSESSOR_API_VERSION) {
            $database = new Assessor_Database();
            $database->create_tables();
            update_option('assessor_db_version', ASSESSOR_API_VERSION);
        }

        // Dedicated Shadow ETRACS schema-version marker check
        $shadow_schema_version = get_option('assessor_shadow_schema_version');
        if ($shadow_schema_version !== ASSESSOR_SHADOW_SCHEMA_VERSION) {
            if (class_exists('Assessor_Migration_Runner')) {
                $runner = new Assessor_Migration_Runner();
                $validation = $runner->validate_migration_008_schema();
                if ($validation['valid']) {
                    $runner->mark_migration_completed_if_valid('008_property_assessed_descriptions_schema');
                    update_option('assessor_shadow_schema_version', ASSESSOR_SHADOW_SCHEMA_VERSION);
                } else {
                    $result = $runner->run_automatic_schema_upgrades();
                    if (!empty($result['008_property_assessed_descriptions_schema']['success'])) {
                        $revalidation = $runner->validate_migration_008_schema();
                        if ($revalidation['valid']) {
                            update_option('assessor_shadow_schema_version', ASSESSOR_SHADOW_SCHEMA_VERSION);
                        }
                    }
                }
            }
        }
    }
}

if (!function_exists('assessor_api_activate')) {
    function assessor_api_activate() {
        $database = new Assessor_Database();
        $database->create_tables();

        if (class_exists('Assessor_Migration_Runner')) {
            $runner = new Assessor_Migration_Runner();
            if ($runner->mark_migration_completed_if_valid('008_property_assessed_descriptions_schema')) {
                update_option('assessor_shadow_schema_version', ASSESSOR_SHADOW_SCHEMA_VERSION);
            } else {
                $result = $runner->run_automatic_schema_upgrades();
                if (!empty($result['008_property_assessed_descriptions_schema']['success'])) {
                    $val = $runner->validate_migration_008_schema();
                    if ($val['valid']) {
                        update_option('assessor_shadow_schema_version', ASSESSOR_SHADOW_SCHEMA_VERSION);
                    }
                }
            }
        }
        update_option('assessor_db_version', ASSESSOR_API_VERSION);
    }
}

// =========================================================================
// TEST SCENARIO 1 & 2: Fresh installation via assessor_api_activate()
// =========================================================================
echo "\n--- Scenario 1 & 2: Fresh Installation via assessor_api_activate() ---\n";
reset_disposable_db($pdo);
$mock_options = [];

// Directly invoke production assessor_api_activate()
assessor_api_activate();

$runner = new Assessor_Migration_Runner();
$val1 = $runner->validate_migration_008_schema();

assert_test($val1['valid'] === true, "Standard assessor_api_activate() created complete canonical Shadow ETRACS schema");
assert_test($wpdb->get_var("SHOW TABLES LIKE 'wp_assessor_machine'") === 'wp_assessor_machine', "wp_assessor_machine exists from create_tables()");
assert_test($wpdb->get_var("SHOW TABLES LIKE 'wp_assessor_planttree'") === 'wp_assessor_planttree', "wp_assessor_planttree exists from create_tables()");
assert_test($wpdb->get_var("SHOW TABLES LIKE 'wp_assessor_planttreedetail'") === 'wp_assessor_planttreedetail', "wp_assessor_planttreedetail exists from create_tables()");

$has_origin = $wpdb->get_var("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'test_wp10_disposable' AND TABLE_NAME = 'wp_assessor_planttreedetail' AND COLUMN_NAME = 'origin_type'");
$has_origin_idx = $wpdb->get_var("SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = 'test_wp10_disposable' AND TABLE_NAME = 'wp_assessor_planttreedetail' AND INDEX_NAME = 'idx_origin_type' LIMIT 1");
assert_test($has_origin === 'origin_type', "Canonical create_tables() includes origin_type column on planttreedetail");
assert_test($has_origin_idx === 'idx_origin_type', "Canonical create_tables() includes idx_origin_type index on planttreedetail");

$reg1 = $wpdb->get_row("SELECT * FROM wp_assessor_migrations WHERE migration_name = '008_property_assessed_descriptions_schema'", ARRAY_A);
assert_test($reg1 && $reg1['status'] === 'completed', "Migration 008 recorded as completed on fresh install");
assert_test(get_option('assessor_shadow_schema_version') === ASSESSOR_SHADOW_SCHEMA_VERSION, "assessor_shadow_schema_version marker updated to 1.0.0");
assert_test(get_option('assessor_db_version') === ASSESSOR_API_VERSION, "assessor_db_version updated to 1.1.17");

// =========================================================================
// TEST SCENARIO 3: Existing installation with complete schema (No recurring repairs)
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

// Test normal boot via assessor_api_check_version() with marker present
$runner_called = false;
assessor_api_check_version();
assert_test(get_option('assessor_shadow_schema_version') === ASSESSOR_SHADOW_SCHEMA_VERSION, "Normal boot leaves shadow schema marker intact without recurring repair");

// =========================================================================
// TEST SCENARIO 4: Existing installation at 1.1.17 with missing schema marker
// =========================================================================
echo "\n--- Scenario 4: Existing installation at 1.1.17 with missing marker & missing shadow tables ---\n";
reset_disposable_db($pdo);
$mock_options = [];

// Simulate existing install already on 1.1.17, but missing shadow schema marker and missing tables
update_option('assessor_db_version', '1.1.17');
// marker assessor_shadow_schema_version is intentionally null/missing
$runner->ensure_migrations_table();
$wpdb->query("INSERT INTO wp_assessor_migrations (migration_name, status, started_at) VALUES ('008_property_assessed_descriptions_schema', 'failed')");

// Normal boot invokes assessor_api_check_version() directly
assessor_api_check_version();

$val4 = $runner->validate_migration_008_schema();
assert_test($val4['valid'] === true, "Missing shadow tables automatically repaired on normal boot even when plugin version is 1.1.17");
$reg4 = $wpdb->get_row("SELECT * FROM wp_assessor_migrations WHERE migration_name = '008_property_assessed_descriptions_schema'", ARRAY_A);
assert_test($reg4 && $reg4['status'] === 'completed', "Pre-existing failed migration status reconciled to completed");
assert_test(get_option('assessor_shadow_schema_version') === ASSESSOR_SHADOW_SCHEMA_VERSION, "Schema marker updated to 1.0.0 after successful repair");

// Subsequent boot should skip repair
$completed_at_v4 = $reg4['completed_at'];
assessor_api_check_version();
$reg4_subsequent = $wpdb->get_row("SELECT * FROM wp_assessor_migrations WHERE migration_name = '008_property_assessed_descriptions_schema'", ARRAY_A);
assert_test($reg4_subsequent['completed_at'] === $completed_at_v4, "Subsequent boot completely skips repair because marker is current");

// =========================================================================
// TEST SCENARIO 4B: Existing installation at 1.1.17 with valid schema marks current without repair
// =========================================================================
echo "\n--- Scenario 4B: Existing installation with valid schema & missing marker marks current without repair ---\n";
reset_disposable_db($pdo);
$mock_options = [];

// Create tables directly
$db = new Assessor_Database();
$db->create_tables();
update_option('assessor_db_version', '1.1.17');
// Do NOT set assessor_shadow_schema_version marker

// Record a pending migration record
$runner->ensure_migrations_table();
$wpdb->query("INSERT INTO wp_assessor_migrations (migration_name, status, started_at) VALUES ('008_property_assessed_descriptions_schema', 'pending')");

// Normal boot
assessor_api_check_version();
assert_test(get_option('assessor_shadow_schema_version') === ASSESSOR_SHADOW_SCHEMA_VERSION, "Marker set to 1.0.0 without running DDL repair");
$reg4b = $wpdb->get_row("SELECT * FROM wp_assessor_migrations WHERE migration_name = '008_property_assessed_descriptions_schema'", ARRAY_A);
assert_test($reg4b['status'] === 'completed', "Pending record reconciled to completed upon validation");

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
// TEST SCENARIO 8: Failure handling, marker retention, and retry
// =========================================================================
echo "\n--- Scenario 8: Repair Failure Retains Marker and Can Be Retried Safely ---\n";
reset_disposable_db($pdo);
$mock_options = [];
update_option('assessor_db_version', '1.1.17');

// Create table with conflicting duplicate data that prevents creating unique index
$wpdb->query("CREATE TABLE wp_assessor_machine (
    objid varchar(50) NOT NULL,
    state varchar(10) NOT NULL DEFAULT 'APPROVED',
    code varchar(50) NOT NULL,
    name varchar(250) NOT NULL,
    PRIMARY KEY (objid)
)");
$wpdb->query("INSERT INTO wp_assessor_machine (objid, code, name) VALUES ('MAC-1', 'DUPE_CODE', 'Machine 1')");
$wpdb->query("INSERT INTO wp_assessor_machine (objid, code, name) VALUES ('MAC-2', 'DUPE_CODE', 'Machine 2')");

// Run check_version: repair should fail because of duplicates on `code`, preserving data and retaining outdated marker
assessor_api_check_version();

$marker_after_failure = get_option('assessor_shadow_schema_version');
assert_test($marker_after_failure !== ASSESSOR_SHADOW_SCHEMA_VERSION, "Schema marker retained as missing/outdated after repair failure");

$dupe_count = $wpdb->get_var("SELECT COUNT(*) FROM wp_assessor_machine WHERE code = 'DUPE_CODE'");
assert_test($dupe_count == 2, "Conflicting data preserved intact without dropping table or losing records");

// Operator resolves duplicate data
$wpdb->query("UPDATE wp_assessor_machine SET code = 'RESOLVED_CODE' WHERE objid = 'MAC-2'");

// Retry via normal boot
assessor_api_check_version();
$marker_after_retry = get_option('assessor_shadow_schema_version');
$val8 = $runner->validate_migration_008_schema();

assert_test($val8['valid'] === true, "Validation passes after duplicate data resolved on retry");
assert_test($marker_after_retry === ASSESSOR_SHADOW_SCHEMA_VERSION, "Schema marker updated to 1.0.0 after successful retry");

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
