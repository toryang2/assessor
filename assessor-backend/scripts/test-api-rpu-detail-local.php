<?php
/**
 * Test get_rpu_detail API against assessor_local
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

$pdo = new PDO("mysql:host=127.0.0.1;dbname=assessor_local;charset=utf8mb4", 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Mock wpdb
class WpdbVerifyBridge {
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
}

global $wpdb;
$wpdb = new WpdbVerifyBridge($pdo);

require_once dirname(__DIR__) . '/wp-content/plugins/assessor-api/includes/class-assessor-etracs.php';
$etracs = new Assessor_Etracs();

echo "=== Test 1: Machinery TD 22-010-0004-05935 ===\n";
$mach_faas = $pdo->query("SELECT objid, tdno, rpuid FROM wp_assessor_faas WHERE tdno = '22-010-0004-05935'")->fetch();
$mach_res = $etracs->get_rpu_detail(['id' => $mach_faas['rpuid']]);
$rpu_data = $mach_res['data'];
echo "RPU Type: " . $rpu_data->rpu_type . "\n";
echo "Machines count: " . count($rpu_data->machines ?? []) . "\n";
if (!empty($rpu_data->machines)) {
    foreach ($rpu_data->machines as $m) {
        echo " - Machine Name: " . ($m->machinename ?? 'blank') . "\n";
        echo " - Machine Code: " . ($m->machinecode ?? 'blank') . "\n";
        echo " - Machine ObjID: " . ($m->machine_objid ?? 'blank') . "\n";
    }
}

echo "\n=== Test 2: Building TD 22-010-0001-00007 ===\n";
$bldg_faas = $pdo->query("SELECT objid, tdno, rpuid FROM wp_assessor_faas WHERE tdno = '22-010-0001-00007'")->fetch();
$bldg_res = $etracs->get_rpu_detail(['id' => $bldg_faas['rpuid']]);
$b_rpu_data = $bldg_res['data'];
echo "RPU Type: " . $b_rpu_data->rpu_type . "\n";
echo "Subtype bldgkind_name: " . ($b_rpu_data->subtype->bldgkind_name ?? 'blank') . "\n";
echo "Subtype bldgtype_name: " . ($b_rpu_data->subtype->bldgtype_name ?? 'blank') . "\n";
echo "Subtype resolved_bldgtypename: " . ($b_rpu_data->subtype->resolved_bldgtypename ?? 'blank') . "\n";
echo "Floorcount: " . ($b_rpu_data->subtype->floorcount ?? 'blank') . "\n";

echo "\n=== Test 3: Plant/Tree Sample TD ===\n";
$pt_sample_rpu = $pdo->query("SELECT DISTINCT planttreerpuid FROM wp_assessor_planttreedetail LIMIT 1")->fetchColumn();
if ($pt_sample_rpu) {
    $pt_res = $etracs->get_rpu_detail(['id' => $pt_sample_rpu]);
    $pt_data = $pt_res['data'];
    echo "Plant/tree details count: " . count($pt_data->planttreedetails ?? []) . "\n";
    if (!empty($pt_data->planttreedetails)) {
        $first_pt = $pt_data->planttreedetails[0];
        echo " - First Plant/Tree Name: " . ($first_pt->planttreename ?? 'blank') . "\n";
        echo " - First Plant/Tree Code: " . ($first_pt->planttree_code ?? 'blank') . "\n";
        echo " - Origin Type: " . ($first_pt->origin_type ?? 'blank') . "\n";
    }
}

echo "\n=== Test 4: Miscellaneous Sample TD ===\n";
$misc_sample_rpu = $pdo->query("SELECT DISTINCT miscrpuid FROM wp_assessor_miscrpuitem LIMIT 1")->fetchColumn();
if ($misc_sample_rpu) {
    $misc_res = $etracs->get_rpu_detail(['id' => $misc_sample_rpu]);
    $misc_data = $misc_res['data'];
    echo "Misc items count: " . count($misc_data->miscitems ?? []) . "\n";
    if (!empty($misc_data->miscitems)) {
        $first_misc = $misc_data->miscitems[0];
        echo " - First Misc Name: " . ($first_misc->miscitemname ?? 'blank') . "\n";
        echo " - First Misc Code: " . ($first_misc->miscitem_code ?? 'blank') . "\n";
    }
}
