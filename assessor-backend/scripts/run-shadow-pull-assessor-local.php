<?php
/**
 * Run Shadow ETRACS Pull against assessor_local
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

// Ensure WordPress helper stubs
if (!function_exists('current_time')) {
    function current_time($type, $gmt = 0) {
        return ($type === 'mysql') ? date('Y-m-d H:i:s') : time();
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
        return trim((string)$str);
    }
}
if (!function_exists('esc_html')) {
    function esc_html($str) {
        return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
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

// WordPress options table backing for assessor_local
$pdo_local = new PDO("mysql:host=127.0.0.1;dbname=assessor_local;charset=utf8mb4", 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Strict target database guard
$check_db = $pdo_local->query("SELECT DATABASE()")->fetchColumn();
if ($check_db !== 'assessor_local') {
    die("FATAL: Target database is NOT assessor_local! Got: $check_db\n");
}

function get_option($key, $default = false) {
    global $pdo_local;
    $stmt = $pdo_local->prepare("SELECT option_value FROM wp_options WHERE option_name = ? LIMIT 1");
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    if ($val === false) return $default;
    $un = @unserialize($val);
    return ($un !== false || $val === 'b:0;') ? $un : $val;
}

function update_option($key, $value, $autoload = null) {
    global $pdo_local;
    $serialized = is_scalar($value) ? (string)$value : serialize($value);
    $exists = $pdo_local->prepare("SELECT COUNT(*) FROM wp_options WHERE option_name = ?");
    $exists->execute([$key]);
    if ($exists->fetchColumn() > 0) {
        $stmt = $pdo_local->prepare("UPDATE wp_options SET option_value = ? WHERE option_name = ?");
        return $stmt->execute([$serialized, $key]);
    } else {
        $stmt = $pdo_local->prepare("INSERT INTO wp_options (option_name, option_value, autoload) VALUES (?, ?, 'yes')");
        return $stmt->execute([$key, $serialized]);
    }
}

function delete_option($key) {
    global $pdo_local;
    $stmt = $pdo_local->prepare("DELETE FROM wp_options WHERE option_name = ?");
    return $stmt->execute([$key]);
}

// Verify configured upstream source
$host = get_option('assessor_etracs_db_host', 'localhost');
$port = get_option('assessor_etracs_db_port', '3306');
$db   = get_option('assessor_etracs_db_name', 'etracs254_kitaotao');
echo "Upstream source host: " . $host . "\n";
echo "Upstream source port: " . $port . "\n";
echo "Upstream source database: " . $db . "\n";

if ($host === '192.168.0.156' || strpos($host, '192.168.0.156') !== false) {
    die("FATAL: Prohibited remote host 192.168.0.156 detected in options! Aborting.\n");
}

class WpdbAssessorLocalSyncBridge {
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
$wpdb = new WpdbAssessorLocalSyncBridge($pdo_local);

require_once dirname(__DIR__) . '/wp-content/plugins/assessor-api/includes/class-assessor-database.php';
require_once dirname(__DIR__) . '/wp-content/plugins/assessor-api/includes/class-assessor-etracs.php';
require_once dirname(__DIR__) . '/wp-content/plugins/assessor-api/includes/class-assessor-etracs-sync.php';

echo "\n=== Step 3: Triggering Controlled Shadow ETRACS Pull ===\n";
$start_time = microtime(true);
$result = Assessor_Etracs_Sync::trigger_sync();
$duration = round(microtime(true) - $start_time, 2);

echo "Sync completed in {$duration} seconds.\n";
echo "Result:\n";
print_r($result);

echo "\nDiagnostics Report:\n";
$diag = Assessor_Etracs_Sync::get_diagnostics();
print_r($diag);
