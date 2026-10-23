<?php
/**
 * Test get_faas endpoint output for TD 22-010-0004-05935
 */
$pdo = new PDO('mysql:host=127.0.0.1;dbname=assessor_local', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

class WpdbMockFaas {
    public $pdo;
    public $prefix = 'wp_';
    public $last_error = '';

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function prepare($query, $args) {
        if (!is_array($args)) {
            $args = array_slice(func_get_args(), 1);
        }
        $arg_index = 0;
        return preg_replace_callback('/(%s|%d|%f)/', function($match) use (&$args, &$arg_index) {
            $val = isset($args[$arg_index]) ? $args[$arg_index] : null;
            $arg_index++;
            if ($val === null) return 'NULL';
            if ($match[1] === '%d') return intval($val);
            if ($match[1] === '%f') return floatval($val);
            return $this->pdo->quote((string)$val);
        }, $query);
    }

    public function query($sql) {
        return $this->pdo->exec($sql);
    }

    public function get_var($query, $x = 0, $y = 0) {
        $stmt = $this->pdo->query($query);
        return $stmt ? $stmt->fetchColumn() : null;
    }

    public function get_row($query, $output = OBJECT, $y = 0) {
        $stmt = $this->pdo->query($query);
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        if (!$row) return null;
        return $output === ARRAY_A ? $row : (object)$row;
    }

    public function get_results($query, $output = OBJECT) {
        $stmt = $this->pdo->query($query);
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        if ($output === ARRAY_A) return $rows;
        return array_map(function($r) { return (object)$r; }, $rows);
    }
}

global $wpdb;
$wpdb = new WpdbMockFaas($pdo);

if (!defined('ABSPATH')) define('ABSPATH', dirname(__DIR__) . '/');
if (!defined('OBJECT')) define('OBJECT', 'OBJECT');
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');

require_once dirname(__DIR__) . '/wp-content/plugins/assessor-api/includes/class-assessor-etracs.php';
$etracs = new Assessor_Etracs();

$faas_id = 'F-180b0697:19327f6fa4c:-6c84';
$faas_res = $etracs->get_faas($faas_id);
echo "get_faas($faas_id) result:\n";
print_r($faas_res);
