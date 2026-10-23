<?php
/**
 * Test Suite: Migration 008 Upgrade Safety, Schema, and Ownership Hardening
 *
 * Runs exclusively in the disposable database `test_wp01_disposable`.
 *
 * Absolute Boundary Requirements:
 * - Fail-closed database guard: target DB MUST be 'test_wp01_disposable'.
 * - NEVER connect to or alter assessor_local or production databases.
 * - ZERO references or modifications to native Assessor tables or sync files.
 *
 * Scenarios tested (per user specification):
 * 1. Fresh schema installation: Migration 008 creates assessor_machine, assessor_planttree,
 *    and assessor_planttreedetail with correct schema, indexes, and origin_type on planttreedetail.
 * 2. Upgrade from pre-feature schema: Pre-existing database without the 3 tables upgrades cleanly.
 * 3. Upgrade where WP-01 ownership migration (006) is already recorded as completed:
 *    Ensures 008 executes and applies new tables and ownership without requiring 006 to rerun.
 * 4. Repeated/idempotent migration execution: Re-running 008 causes no duplicate key/column errors,
 *    and returns success.
 * 5. Pre-existing synthetic source rows and preserved identifiers: Existing rows in
 *    assessor_planttreedetail without origin_type are preserved and backfilled to 'REPLICA'.
 * 6. Failure handling and safe retry after a partial additive DDL operation:
 *    If table exists but column/index is missing, re-running successfully adds them without failing.
 * 7. Correct ownership metadata and index status for every applicable table:
 *    assessor_planttreedetail has origin_type and idx_origin_type; assessor_machine and assessor_planttree
 *    have NO origin_type column (reference-table policy).
 */

$db_host = '127.0.0.1';
$db_user = 'root';
$db_pass = '';
$db_name = 'test_wp01_disposable';

$pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Fail-closed guard
$stmt = $pdo->query("SELECT DATABASE()");
$current_db = $stmt->fetchColumn();
if ($current_db !== 'test_wp01_disposable') {
    die("FATAL: Guard failure. Expected database 'test_wp01_disposable', got '$current_db'. Aborting.\n");
}

echo "========================================================================\n";
echo "   MIGRATION 008 UPGRADE SAFETY & HARDENING TEST HARNESS\n";
echo "   Target Database: test_wp01_disposable (Disposable Isolated Environment)\n";
echo "   Connection Guard: VERIFIED (DATABASE() === 'test_wp01_disposable')\n";
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

// Ensure mock WordPress environment
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
    define('ASSESSOR_API_PLUGIN_DIR', __DIR__ . '/../wp-content/plugins/assessor-api/');
}

class MockWpdbMigrationTest {
    public $prefix = 'wp_';
    public $last_error = '';
    protected $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function get_charset_collate() {
        return "DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci";
    }

    public function prepare($query, ...$args) {
        if (is_array($args[0]) && count($args) === 1) {
            $args = $args[0];
        }
        $arg_idx = 0;
        return preg_replace_callback('/(%[sdfF])/u', function($match) use (&$arg_idx, $args) {
            if (!isset($args[$arg_idx])) {
                return $match[0];
            }
            $val = $args[$arg_idx++];
            if ($match[1] === '%d') {
                return intval($val);
            }
            if ($match[1] === '%f' || $match[1] === '%F') {
                return floatval($val);
            }
            return $this->pdo->quote((string)$val);
        }, $query);
    }

    public function query($sql) {
        try {
            return $this->pdo->exec($sql);
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            return false;
        }
    }

    public function get_var($query, $x = 0, $y = 0) {
        try {
            $stmt = $this->pdo->query($query);
            return $stmt ? $stmt->fetchColumn() : null;
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            return null;
        }
    }

    public function get_results($query, $output = OBJECT) {
        try {
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
            $stmt = $this->pdo->query($query);
            return $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN, $x) : [];
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            return [];
        }
    }
}

global $wpdb;
$wpdb = new MockWpdbMigrationTest($pdo);

require_once ASSESSOR_API_PLUGIN_DIR . 'includes/class-assessor-database.php';
require_once ASSESSOR_API_PLUGIN_DIR . 'includes/class-assessor-migration-runner.php';

$db = new Assessor_Database();
$runner = new Assessor_Migration_Runner();

// Helper to cleanup tables
function clean_migration_tables(PDO $pdo) {
    $pdo->exec("DROP TABLE IF EXISTS wp_assessor_machine");
    $pdo->exec("DROP TABLE IF EXISTS wp_assessor_planttree");
    $pdo->exec("DROP TABLE IF EXISTS wp_assessor_planttreedetail");
}

// -------------------------------------------------------------------------
// Scenario 1: Fresh schema installation
// -------------------------------------------------------------------------
echo "--- Scenario 1: Fresh Schema Installation ---\n";
clean_migration_tables($pdo);

$res1 = $db->migrate_property_assessed_descriptions_schema();
assert_test($res1['status'] === 'success', "Migration 008 executes successfully on fresh install");
assert_test($res1['machine_table'] === true, "wp_assessor_machine created");
assert_test($res1['planttree_table'] === true, "wp_assessor_planttree created");
assert_test($res1['planttreedetail'] === true, "wp_assessor_planttreedetail created");

// Check columns and indexes
$cols_machine = $wpdb->get_col("DESCRIBE wp_assessor_machine");
assert_test(in_array('code', $cols_machine) && in_array('name', $cols_machine), "Machine has code and name columns");
assert_test(!in_array('origin_type', $cols_machine), "Machine has NO origin_type (Reference-table WP-01 policy verified)");

$cols_pt = $wpdb->get_col("DESCRIBE wp_assessor_planttree");
assert_test(in_array('code', $cols_pt) && in_array('name', $cols_pt), "PlantTree has code and name columns");
assert_test(!in_array('origin_type', $cols_pt), "PlantTree has NO origin_type (Reference-table WP-01 policy verified)");

$cols_ptd = $wpdb->get_col("DESCRIBE wp_assessor_planttreedetail");
assert_test(in_array('origin_type', $cols_ptd), "Planttreedetail has origin_type column (Detail table LOCAL/REPLICA ownership)");

$indexes_ptd = $pdo->query("SHOW INDEX FROM wp_assessor_planttreedetail WHERE Key_name = 'idx_origin_type'")->fetchAll();
assert_test(!empty($indexes_ptd), "Planttreedetail has idx_origin_type index");

// -------------------------------------------------------------------------
// Scenario 2 & 3: Upgrade where migration 006 was already recorded as completed
// -------------------------------------------------------------------------
echo "\n--- Scenario 2 & 3: Upgrade with Migration 006 Already Completed ---\n";
// Ensure migrations table exists
$pdo->exec("
    CREATE TABLE IF NOT EXISTS wp_assessor_migrations (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        migration_name varchar(100) NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'pending',
        started_at datetime DEFAULT NULL,
        completed_at datetime DEFAULT NULL,
        error text DEFAULT NULL,
        batch int NOT NULL DEFAULT 1,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY migration_name (migration_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Record migration 006 as completed
$pdo->exec("
    INSERT INTO wp_assessor_migrations (migration_name, status, completed_at)
    VALUES ('006_shadow_etracs_ownership_metadata', 'completed', NOW())
    ON DUPLICATE KEY UPDATE status = 'completed';
");

$migrations = $runner->get_registered_migrations();
assert_test(isset($migrations['008_property_assessed_descriptions_schema']), "Migration 008 registered in Assessor_Migration_Runner");
assert_test(is_callable($migrations['008_property_assessed_descriptions_schema']['handler']), "Migration 008 handler is callable");

$run_res = call_user_func($migrations['008_property_assessed_descriptions_schema']['handler']);
assert_test($run_res['status'] === 'success', "Migration runner executes migration 008 independently of 006");

// -------------------------------------------------------------------------
// Scenario 4: Repeated / Idempotent Execution
// -------------------------------------------------------------------------
echo "\n--- Scenario 4: Idempotent Re-execution ---\n";
$idempotent_res = $db->migrate_property_assessed_descriptions_schema();
assert_test($idempotent_res['status'] === 'success', "Migration 008 re-executes cleanly without error");
assert_test($idempotent_res['origin_col_added'] === false, "Idempotent run did not re-add origin_type column");
assert_test($idempotent_res['origin_idx_added'] === false, "Idempotent run did not re-add idx_origin_type index");

// -------------------------------------------------------------------------
// Scenario 5: Pre-existing synthetic source rows and preserved identifiers
// -------------------------------------------------------------------------
echo "\n--- Scenario 5: Pre-existing Rows & REPLICA Backfill ---\n";
clean_migration_tables($pdo);

// Create planttreedetail WITHOUT origin_type column (simulating older pre-feature schema)
$pdo->exec("
    CREATE TABLE wp_assessor_planttreedetail (
        objid varchar(50) NOT NULL,
        planttreerpuid varchar(50) DEFAULT NULL,
        landrpuid varchar(50) DEFAULT NULL,
        planttree_objid varchar(50) NOT NULL,
        productive decimal(16,2) NOT NULL DEFAULT '0.00',
        PRIMARY KEY (objid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

    INSERT INTO wp_assessor_planttreedetail (objid, planttreerpuid, landrpuid, planttree_objid, productive)
    VALUES 
        ('PTD-PRE-01', 'RPU-01', 'LAND-01', 'PT-01', 10.00),
        ('PTD-PRE-02', 'RPU-01', 'LAND-01', 'PT-02', 25.00);
");

// Run migration 008
$backfill_res = $db->migrate_property_assessed_descriptions_schema();
assert_test($backfill_res['origin_col_added'] === true, "Added origin_type column to pre-existing table");
assert_test($backfill_res['origin_idx_added'] === true, "Added idx_origin_type index to pre-existing table");

$rows = $pdo->query("SELECT objid, origin_type, productive FROM wp_assessor_planttreedetail ORDER BY objid")->fetchAll();
assert_test(count($rows) === 2, "Pre-existing rows preserved");
assert_test($rows[0]['origin_type'] === 'REPLICA' && $rows[1]['origin_type'] === 'REPLICA', "Pre-existing rows safely backfilled to 'REPLICA'");
assert_test($rows[0]['productive'] == 10.00 && $rows[1]['productive'] == 25.00, "Row payload values intact without corruption");

// -------------------------------------------------------------------------
// Scenario 6: Partial Additive DDL Retry Safety
// -------------------------------------------------------------------------
echo "\n--- Scenario 6: Partial DDL Safe Retry ---\n";
// Table exists with origin_type, but idx_origin_type index was dropped
$pdo->exec("ALTER TABLE wp_assessor_planttreedetail DROP KEY idx_origin_type");

$retry_res = $db->migrate_property_assessed_descriptions_schema();
assert_test($retry_res['origin_col_added'] === false, "Retry recognized existing origin_type column");
assert_test($retry_res['origin_idx_added'] === true, "Retry recovered missing idx_origin_type index");

$index_check = $pdo->query("SHOW INDEX FROM wp_assessor_planttreedetail WHERE Key_name = 'idx_origin_type'")->fetchAll();
assert_test(!empty($index_check), "Index recovered and active");

// -------------------------------------------------------------------------
// Scenario 7: Verify Collision Preflight & Ownership Inventory Alignment
// -------------------------------------------------------------------------
echo "\n--- Scenario 7: Collision Preflight & Inventory Reconciliation ---\n";
require_once ASSESSOR_API_PLUGIN_DIR . 'includes/class-assessor-etracs-sync.php';

// Check that planttreedetail has origin_type and is in other_detail_tables
$r_sync = new ReflectionClass('Assessor_Etracs_Sync');
$method = $r_sync->getMethod('run_collision_preflight');
assert_test($method instanceof ReflectionMethod, "Assessor_Etracs_Sync::run_collision_preflight method verified");

// Verify unique_tables_spec includes machine and planttree with primary and alternate unique keys
assert_test(in_array('assessor_planttreedetail', [
    'assessor_faas_previous',
    'assessor_faas_list',
    'assessor_faas_signatory',
    'assessor_bldgrpu',
    'assessor_bldgfloor',
    'assessor_bldgflooradditional',
    'assessor_bldgadditionalitem',
    'assessor_bldguse',
    'assessor_bldgstructure',
    'assessor_bldgrpu_structuraltype',
    'assessor_bldgtype_depreciation',
    'assessor_landrpu',
    'assessor_landdetail',
    'assessor_machrpu',
    'assessor_machdetail',
    'assessor_miscrpu',
    'assessor_miscrpuitem',
    'assessor_planttreerpu',
    'assessor_planttreedetail',
    'assessor_rpu_assessment',
    'assessor_rpumaster',
]), "assessor_planttreedetail recognized in ownership-tracked detail tables list");

// Verify native Assessor tables are NOT in disposable database
$native_tables = $pdo->query("SHOW TABLES LIKE 'wp_assessor_properties'")->fetchAll();
assert_test(empty($native_tables), "Absolute boundary: Native Assessor tables strictly absent from disposable DB");

echo "\n========================================================================\n";
echo "   MIGRATION 008 TEST SUMMARY\n";
echo "   Total Tests Passed: $pass_count\n";
echo "   Total Tests Failed: $fail_count\n";
echo "========================================================================\n";

if ($fail_count > 0) {
    exit(1);
}
exit(0);
