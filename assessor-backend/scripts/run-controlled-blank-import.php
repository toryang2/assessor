<?php
/**
 * Controlled Full Import Runner for Disposable Blank WordPress Database
 *
 * Target: assessor_etracs_blank_smoke_20261028
 * Source: etracs254_kitaotao
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
set_time_limit(0);

// WordPress shims & constants
if (!defined('ABSPATH')) define('ABSPATH', dirname(__DIR__) . '/');
if (!defined('ASSESSOR_API_PLUGIN_DIR')) define('ASSESSOR_API_PLUGIN_DIR', dirname(__DIR__) . '/wp-content/plugins/assessor-api/');
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
if (!defined('ARRAY_N')) define('ARRAY_N', 'ARRAY_N');
if (!defined('OBJECT'))  define('OBJECT', 'OBJECT');

if (!function_exists('wp_hash_password')) {
    function wp_hash_password($p) { return password_hash($p, PASSWORD_BCRYPT); }
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

if (!function_exists('current_time')) {
    function current_time($type, $gmt = 0) {
        return gmdate('Y-m-d H:i:s');
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

if (!function_exists('maybe_unserialize')) {
    function maybe_unserialize($original) {
        if (is_serialized($original)) {
            return @unserialize($original);
        }
        return $original;
    }
}

if (!function_exists('is_serialized')) {
    function is_serialized($data, $strict = true) {
        if (!is_string($data)) return false;
        $data = trim($data);
        if ('N;' === $data) return true;
        if (strlen($data) < 4) return false;
        if (':' !== $data[1]) return false;
        if ($strict) {
            $lastc = substr($data, -1);
            if (';' !== $lastc && '}' !== $lastc) return false;
        } else {
            $semicolon = strpos($data, ';');
            $brace     = strpos($data, '}');
            if (false === $semicolon && false === $brace) return false;
            if (false !== $semicolon && $semicolon < 3) return false;
            if (false !== $brace && $brace < 4) return false;
        }
        $token = $data[0];
        switch ($token) {
            case 's':
                if ($strict) {
                    if ('"' !== substr($data, -2, 1)) return false;
                } elseif (false === strpos($data, '"')) return false;
            case 'a':
            case 'O':
                return (bool) preg_match("/^{$token}:[0-9]+:/s", $data);
            case 'b':
            case 'i':
            case 'd':
                $end = $strict ? '$' : '';
                return (bool) preg_match("/^{$token}:[0-9.E+-]+;$end/", $data);
        }
        return false;
    }
}

// ─────────────────────────────────────────────────────────────
// Connection & Database Isolation Setup
// ─────────────────────────────────────────────────────────────
$target_db = 'assessor_etracs_blank_smoke_20261028';
$source_db = 'etracs254_kitaotao';

$targetPdo = new PDO("mysql:host=localhost;dbname=$target_db;charset=utf8mb4", 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$targetPdo->exec("SET innodb_lock_wait_timeout = 60");

// Guard target
$actual_target = $targetPdo->query("SELECT DATABASE()")->fetchColumn();
if ($actual_target !== $target_db) {
    die("FATAL SECURITY ABORT: Target DB is '$actual_target', expected '$target_db'\n");
}

$sourcePdo = new PDO("mysql:host=localhost;port=3306;dbname=$source_db;charset=utf8mb4", 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$actual_source = $sourcePdo->query("SELECT DATABASE()")->fetchColumn();
if ($actual_source !== $source_db) {
    die("FATAL SECURITY ABORT: Source DB is '$actual_source', expected '$source_db'\n");
}

echo "========================================================================\n";
echo "   CONTROLLED ETRACS FULL IMPORT INTO BLANK DISPOSABLE DATABASE\n";
echo "   Target DB: $actual_target (Guarded & Isolated)\n";
echo "   Source DB: $actual_source (Read-Only Verified)\n";
echo "========================================================================\n\n";

// MockWPDB implementation forwarding strictly to Target PDO
class ControlledImportWPDB {
    public $prefix = 'wp_';
    public $options = 'wp_options';
    public $last_error = '';
    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->options = 'wp_options';
    }

    public function prepare($query, ...$args) {
        if (isset($args[0]) && is_array($args[0]) && count($args) === 1) {
            $args = $args[0];
        }
        $parts = explode('%s', $query);
        $res = array_shift($parts);
        foreach ($parts as $i => $part) {
            $val = isset($args[$i]) ? $args[$i] : null;
            if ($val === null) {
                $res .= 'NULL';
            } else {
                $res .= $this->pdo->quote((string)$val);
            }
            $res .= $part;
        }
        return $res;
    }

    public function esc_like($text) {
        return addcslashes($text, '_%\\');
    }

    public function get_var($query) {
        try {
            $stmt = $this->pdo->query($query);
            if (!$stmt) return null;
            $res = $stmt->fetchColumn();
            return ($res === false) ? null : $res;
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            return null;
        }
    }

    public function get_row($query, $output = OBJECT) {
        try {
            $stmt = $this->pdo->query($query);
            $res = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
            if (!$res) return null;
            return ($output === ARRAY_A) ? $res : (object)$res;
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            return null;
        }
    }

    public function get_results($query, $output = OBJECT) {
        try {
            $stmt = $this->pdo->query($query);
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();
            if ($output === ARRAY_A) return $rows;
            return array_map(function($r) { return (object)$r; }, $rows);
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            return array();
        }
    }

    public function get_col($query, $x = 0) {
        try {
            $stmt = $this->pdo->query($query);
            return $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN, $x) : array();
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            return array();
        }
    }

    public function query($query) {
        $res = $this->pdo->exec($query);
        if ($res === false) {
            $err = $this->pdo->errorInfo();
            $this->last_error = $err[2] ?? 'PDO exec failed';
        }
        return $res;
    }
}

global $wpdb;
$wpdb = new ControlledImportWPDB($targetPdo);

// WordPress Object Cache Mock
$GLOBALS['wp_object_cache'] = array(
    'options' => array(),
);

if (!function_exists('wp_cache_get')) {
    function wp_cache_get($key, $group = '') {
        if (empty($group)) $group = 'default';
        return isset($GLOBALS['wp_object_cache'][$group][$key]) ? $GLOBALS['wp_object_cache'][$group][$key] : false;
    }
}

if (!function_exists('wp_cache_set')) {
    function wp_cache_set($key, $data, $group = '', $expire = 0) {
        if (empty($group)) $group = 'default';
        if (!isset($GLOBALS['wp_object_cache'][$group])) {
            $GLOBALS['wp_object_cache'][$group] = array();
        }
        $GLOBALS['wp_object_cache'][$group][$key] = $data;
        return true;
    }
}

if (!function_exists('wp_cache_delete')) {
    function wp_cache_delete($key, $group = '') {
        if (empty($group)) $group = 'default';
        if (isset($GLOBALS['wp_object_cache'][$group][$key])) {
            unset($GLOBALS['wp_object_cache'][$group][$key]);
            return true;
        }
        return false;
    }
}

$GLOBALS['wp_options_mock'] = array();

function update_option($name, $value, $autoload = null) {
    global $wpdb;
    $GLOBALS['wp_options_mock'][$name] = $value;

    wp_cache_set($name, $value, 'options');
    $notoptions = wp_cache_get('notoptions', 'options');
    if (is_array($notoptions) && isset($notoptions[$name])) {
        unset($notoptions[$name]);
        wp_cache_set('notoptions', $notoptions, 'options');
    }

    if (!empty($wpdb)) {
        $options_tbl = !empty($wpdb->options) ? $wpdb->options : ($wpdb->prefix . 'options');
        $val_ser = is_scalar($value) ? (string)$value : serialize($value);
        try {
            $exists = $wpdb->get_var($wpdb->prepare("SELECT option_id FROM `$options_tbl` WHERE option_name = %s", $name));
            if ($exists) {
                $wpdb->query($wpdb->prepare("UPDATE `$options_tbl` SET option_value = %s WHERE option_name = %s", $val_ser, $name));
            } else {
                $wpdb->query($wpdb->prepare("INSERT INTO `$options_tbl` (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $val_ser));
            }
        } catch (Exception $e) {}
    }
    return true;
}

function get_option($name, $default = false) {
    $notoptions = wp_cache_get('notoptions', 'options');
    if (is_array($notoptions) && isset($notoptions[$name])) {
        return $default;
    }

    $cached = wp_cache_get($name, 'options');
    if ($cached !== false) {
        return $cached;
    }

    global $wpdb;
    if (!empty($wpdb)) {
        $options_tbl = !empty($wpdb->options) ? $wpdb->options : ($wpdb->prefix . 'options');
        try {
            $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM `$options_tbl` WHERE option_name = %s", $name));
            if ($raw !== null) {
                $val = maybe_unserialize($raw);
                wp_cache_set($name, $val, 'options');
                return $val;
            }
        } catch (Exception $e) {}
    }

    if (isset($GLOBALS['wp_options_mock'][$name])) {
        $val = $GLOBALS['wp_options_mock'][$name];
        wp_cache_set($name, $val, 'options');
        return $val;
    }

    if (!is_array($notoptions)) {
        $notoptions = array();
    }
    $notoptions[$name] = true;
    wp_cache_set('notoptions', $notoptions, 'options');
    return $default;
}

function delete_option($name) {
    global $wpdb;
    if (isset($GLOBALS['wp_options_mock'][$name])) {
        unset($GLOBALS['wp_options_mock'][$name]);
    }
    wp_cache_delete($name, 'options');
    if (!empty($wpdb)) {
        $options_tbl = !empty($wpdb->options) ? $wpdb->options : ($wpdb->prefix . 'options');
        try {
            $wpdb->query($wpdb->prepare("DELETE FROM `$options_tbl` WHERE option_name = %s", $name));
        } catch (Exception $e) {}
    }
    return true;
}

require_once ABSPATH . 'wp-content/plugins/assessor-api/includes/class-assessor-etracs-sync.php';

// ─────────────────────────────────────────────────────────────
// Execution: Sequential Bounded Continuation Loop
// ─────────────────────────────────────────────────────────────
echo "Starting sequential HTTP-equivalent bounded-batch continuation loop...\n";

$invocation = 0;
$start_time = microtime(true);
$terminal_result = null;
$stable_run_id = null;
$last_stage_reported = '';

while (true) {
    $invocation++;

    // Execute single batch invocation (single_batch = true) exactly like frontend continuation call
    $res = Assessor_Etracs_Sync::trigger_sync($sourcePdo, true);

    if (is_wp_error($res)) {
        echo "\n[ERROR] Sync invocation $invocation returned WP_Error: " . $res->get_error_code() . " - " . $res->get_error_message() . "\n";
        $terminal_result = $res;
        break;
    }

    $run_id = $res['run_id'] ?? 'unknown';
    if ($stable_run_id === null) {
        $stable_run_id = $run_id;
        echo "[RUN INITIALIZED] Stable Run ID: $stable_run_id\n";
    }

    $is_completed = !empty($res['is_completed']);
    $status = $res['status'] ?? 'UNKNOWN';
    $stage = $res['stage'] ?? ($res['run_state']['current_stage'] ?? 'unknown');
    $cursor = $res['cursor'] ?? ($res['run_state']['cursor'] ?? 0);

    if ($stage !== $last_stage_reported) {
        echo sprintf(" -> Invocation %4d | Stage: %-32s | Cursor: %6d\n", $invocation, $stage, $cursor);
        $last_stage_reported = $stage;
    } elseif ($invocation % 20 === 0) {
        echo sprintf("    Invocation %4d | Stage: %-32s | Cursor: %6d\n", $invocation, $stage, $cursor);
    }

    if ($is_completed || in_array(strtoupper($status), array('SUCCESS', 'FAILED', 'PARTIAL'), true)) {
        echo sprintf("\n[TERMINAL STATE REACHED] Invocation %d reached status: %s (is_completed: %s)\n",
            $invocation, $status, $is_completed ? 'true' : 'false'
        );
        $terminal_result = $res;
        break;
    }

    // Safety guard against infinite loops
    if ($invocation > 5000) {
        echo "\n[ABORT] Invocation count exceeded safety threshold of 5000\n";
        break;
    }
}

$elapsed_total = round(microtime(true) - $start_time, 2);
echo "Total Execution Time: {$elapsed_total}s across $invocation invocations.\n\n";

// Save run result summary to file
file_put_contents(
    __DIR__ . '/controlled_import_result.json',
    json_encode($terminal_result, JSON_PRETTY_PRINT)
);

echo "Controlled import completed. Summary saved to controlled_import_result.json.\n";
