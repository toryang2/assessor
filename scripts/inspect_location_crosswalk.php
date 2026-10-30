<?php
$db = new mysqli('localhost', 'root', '', 'assessor_local');
if ($db->connect_error) die($db->connect_error);

// 1. Fetch all native locations
$native_locations = [];
$res = $db->query("SELECT id, code, name, pin, status FROM wp_assessor_locations WHERE deleted_at IS NULL");
while ($r = $res->fetch_assoc()) {
    $native_locations[] = $r;
}

// 2. Fetch all ETRACS barangays
$etracs_barangays = [];
$res = $db->query("SELECT objid, indexno, pin, name, fullname FROM wp_assessor_barangay ORDER BY pin");
while ($r = $res->fetch_assoc()) {
    $etracs_barangays[] = $r;
}

// Count candidates per barangay (among eligible candidates where p.id IS NULL)
$counts = [];
$c_res = $db->query("
    SELECT rp.barangayid, COUNT(*) as cnt
    FROM wp_assessor_faas f
    LEFT JOIN wp_assessor_properties p ON LOWER(TRIM(f.tdno)) = LOWER(TRIM(p.tax_declaration_number))
    LEFT JOIN wp_assessor_real_property rp ON f.realpropertyid = rp.objid
    WHERE f.origin_type = 'REPLICA' AND f.state IN ('CURRENT','CANCELLED') AND TRIM(COALESCE(f.tdno,'')) != ''
      AND p.id IS NULL
    GROUP BY rp.barangayid
");
while ($row = $c_res->fetch_assoc()) {
    $counts[$row['barangayid']] = (int)$row['cnt'];
}

function normalizeName($n) {
    $n = strtoupper(trim($n));
    $n = str_replace(['BARANGAY', 'BRGY.', 'BRGY', '/', '-', '.', ','], ' ', $n);
    $n = preg_replace('/\s+/', ' ', $n);
    return trim($n);
}

echo sprintf("%-15s %-20s %-15s %-10s | %-10s %-20s %-10s | %-30s %-6s\n", 
    "Source ObjID", "Source Name", "Source PIN", "Suffix", "Native ID", "Native Name", "Native PIN", "Audit Status", "Count");
echo str_repeat("-", 140) . "\n";

foreach ($etracs_barangays as $eb) {
    // extract suffix from PIN (e.g. 059-10-0001 -> 1, or 059-10-0024 -> 24)
    $pin_parts = explode('-', $eb['pin']);
    $last_part = end($pin_parts);
    $numeric_suffix = (int)$last_part; // 1 to 35
    
    // Find matching native location by PIN (numeric comparison or string comparison)
    $matches = [];
    foreach ($native_locations as $nl) {
        if ((int)$nl['pin'] === $numeric_suffix && (string)(int)$nl['pin'] === (string)$numeric_suffix && (int)$nl['pin'] > 0) {
            $matches[] = $nl;
        }
    }
    
    $cnt = $counts[$eb['objid']] ?? 0;
    
    $status = 'UNRESOLVED';
    $matched_native = null;
    $action = '';
    
    if (empty($matches)) {
        $status = 'MISSING_NATIVE_LOCATION';
    } elseif (count($matches) > 1) {
        $status = 'AMBIGUOUS_NATIVE_LOCATION';
    } else {
        $matched_native = $matches[0];
        if ($matched_native['status'] !== 'active') {
            $status = 'INACTIVE_NATIVE_LOCATION';
        } else {
            $src_norm = normalizeName($eb['name']);
            $nat_norm = normalizeName($matched_native['name']);
            
            if ($eb['name'] === $matched_native['name']) {
                $status = 'MATCH_CONFIRMED';
            } elseif ($src_norm === $nat_norm) {
                $status = 'MATCH_CONFIRMED_NAME_VARIATION';
            } else {
                // Check specific variations
                // E.g. KITUBO vs KITOBO? Or LOREGA vs LOREGA / NAPALICO?
                // Let's see!
                $status = 'REVIEW_NAME_MISMATCH';
            }
        }
    }
    
    echo sprintf("%-15s %-20s %-15s %-10s | %-10s %-20s %-10s | %-30s %-6s\n",
        $eb['objid'],
        $eb['name'],
        $eb['pin'],
        $numeric_suffix,
        $matched_native['id'] ?? 'NONE',
        $matched_native['name'] ?? 'NONE',
        $matched_native['pin'] ?? 'NONE',
        $status,
        $cnt
    );
}
