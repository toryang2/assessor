<?php
/**
 * Run Migration 008 against assessor_local via Assessor_Migration_Runner
 */
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
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
        public function get_error_message() {
            return $this->message;
        }
    }
}

$db_host = '127.0.0.1';
$db_user = 'root';
$db_pass = '';
$db_name = 'assessor_local';

$pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

class WpdbAssessorLocalBridge {
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

    public function get_row($query, $output = OBJECT, $y = 0) {
        try {
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

    public function insert($table, $data, $format = null) {
        $fields = array_keys($data);
        $escaped_fields = array_map(function($f) { return "`$f`"; }, $fields);
        $placeholders = array_fill(0, count($fields), '?');
        $sql = "INSERT INTO `$table` (" . implode(', ', $escaped_fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute(array_values($data));
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
$wpdb = new WpdbAssessorLocalBridge($pdo);

// 1. Preflight database verification
$current_db = $wpdb->get_var("SELECT DATABASE()");
echo "=== Step 1: Preflight Verification ===\n";
echo "Active database: " . $current_db . "\n";
if ($current_db !== 'assessor_local') {
    die("FATAL: Target database is not assessor_local!\n");
}
echo "Database verification: PASS (exact match 'assessor_local')\n";

require_once dirname(__DIR__) . '/wp-content/plugins/assessor-api/includes/class-assessor-database.php';
require_once dirname(__DIR__) . '/wp-content/plugins/assessor-api/includes/class-assessor-migration-runner.php';

$runner = new Assessor_Migration_Runner();

echo "\n=== Step 2: Running Migration 008 ===\n";
$migration_result = $runner->execute_migration('008_property_assessed_descriptions_schema');
echo "Migration 008 Result:\n";
print_r($migration_result);

// Verify tables and columns
$table_machine = $wpdb->get_var("SHOW TABLES LIKE 'wp_assessor_machine'");
$table_planttree = $wpdb->get_var("SHOW TABLES LIKE 'wp_assessor_planttree'");
$table_planttreedetail = $wpdb->get_var("SHOW TABLES LIKE 'wp_assessor_planttreedetail'");

echo "\nSchema verification in assessor_local:\n";
echo "wp_assessor_machine: " . ($table_machine ? "EXISTS" : "MISSING") . "\n";
echo "wp_assessor_planttree: " . ($table_planttree ? "EXISTS" : "MISSING") . "\n";
echo "wp_assessor_planttreedetail: " . ($table_planttreedetail ? "EXISTS" : "MISSING") . "\n";

$col_origin = $wpdb->get_var("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'assessor_local' AND TABLE_NAME = 'wp_assessor_planttreedetail' AND COLUMN_NAME = 'origin_type'");
$idx_origin = $wpdb->get_var("SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = 'assessor_local' AND TABLE_NAME = 'wp_assessor_planttreedetail' AND INDEX_NAME = 'idx_origin_type' LIMIT 1");

echo "wp_assessor_planttreedetail.origin_type column: " . ($col_origin ? "EXISTS" : "MISSING") . "\n";
echo "wp_assessor_planttreedetail.idx_origin_type index: " . ($idx_origin ? "EXISTS" : "MISSING") . "\n";

$status = $runner->get_status();
echo "Registry Status List:\n";
print_r($status->data);
