<?php
require 'C:/xampp/htdocs/wp-load.php';
global $wpdb;

$table_props = $wpdb->prefix . 'assessor_properties';
$table_revs  = $wpdb->prefix . 'assessor_revision_entries';

// 1. Fetch active revisions
$revisions = $wpdb->get_results("SELECT id, revision_code, revision_year, from_year, to_year, status FROM $table_revs WHERE status = 'active' ORDER BY CAST(from_year AS UNSIGNED) ASC", ARRAY_A);

echo "=== 1. CHECK OVERLAPPING ACTIVE REVISIONS ===\n";
$intervals = [];
$overlap_found = false;
foreach ($revisions as $r) {
    $from = intval($r['from_year']);
    $to = (strtolower($r['to_year']) === 'present') ? 9999 : intval($r['to_year']);
    $intervals[] = [
        'id' => $r['id'],
        'code' => $r['revision_code'],
        'year' => $r['revision_year'],
        'from' => $from,
        'to' => $to
    ];
}

for ($i = 0; $i < count($intervals); $i++) {
    for ($j = $i + 1; $j < count($intervals); $j++) {
        $a = $intervals[$i];
        $b = $intervals[$j];
        if (max($a['from'], $b['from']) <= min($a['to'], $b['to'])) {
            echo "OVERLAP DETECTED: [{$a['code']}: {$a['from']}-{$a['to']}] and [{$b['code']}: {$b['from']}-{$b['to']}]\n";
            $overlap_found = true;
        }
    }
}
if (!$overlap_found) {
    echo "NO OVERLAPPING ACTIVE REVISIONS FOUND. All revision intervals are strictly mutually exclusive:\n";
    foreach ($intervals as $inv) {
        $to_str = ($inv['to'] === 9999) ? 'present (9999)' : $inv['to'];
        echo " - {$inv['code']} ({$inv['year']}): {$inv['from']} to {$to_str}\n";
    }
}

echo "\n=== 2. ANALYZE PROPERTY EFFECTIVITY DATES ===\n";
$props = $wpdb->get_results("SELECT id, tax_declaration_number, effectivity_date FROM $table_props", ARRAY_A);

$mappable = [];
$unmappable_no_match = [];
$unmappable_malformed = [];
$unmappable_blank = [];

foreach ($props as $p) {
    $raw = trim($p['effectivity_date'] ?? '');
    if ($raw === '') {
        $unmappable_blank[] = $p;
        continue;
    }

    // Determine effectivity year
    // Is it a clean 4-digit year?
    if (preg_match('/^(\d{4})$/', $raw, $m)) {
        $year = intval($m[1]);
        // Find matching revision
        $matches = [];
        foreach ($intervals as $inv) {
            if ($year >= $inv['from'] && $year <= $inv['to']) {
                $matches[] = $inv;
            }
        }
        if (count($matches) === 1) {
            $mappable[] = [
                'property_id' => $p['id'],
                'tdn' => $p['tax_declaration_number'],
                'effectivity_date' => $raw,
                'year' => $year,
                'revision_id' => $matches[0]['id'],
                'revision_code' => $matches[0]['code']
            ];
        } elseif (count($matches) > 1) {
            echo "AMBIGUITY: Multiple matches for year $year on TDN {$p['tax_declaration_number']}\n";
        } else {
            // No matching revision (e.g. 1963)
            $unmappable_no_match[] = [
                'property_id' => $p['id'],
                'tdn' => $p['tax_declaration_number'],
                'effectivity_date' => $raw,
                'year' => $year,
                'reason' => "Year $year falls outside all revisions [1965..present]"
            ];
        }
    } else {
        // Malformed / unusable date
        $unmappable_malformed[] = [
            'property_id' => $p['id'],
            'tdn' => $p['tax_declaration_number'],
            'effectivity_date' => $raw,
            'reason' => "Non-standard year format: '$raw'"
        ];
    }
}

echo "Total properties: " . count($props) . "\n";
echo "Mappable cleanly: " . count($mappable) . "\n";
echo "Blank/empty effectivity_date: " . count($unmappable_blank) . "\n";
echo "Unmappable (Year out of range, e.g. < 1965): " . count($unmappable_no_match) . "\n";
echo "Unmappable (Malformed / non-year text): " . count($unmappable_malformed) . "\n";

echo "\n--- OUT OF RANGE PROPERTIES ---\n";
foreach ($unmappable_no_match as $item) {
    echo "TDN: {$item['tdn']} | Date: '{$item['effectivity_date']}' | {$item['reason']}\n";
}

echo "\n--- MALFORMED PROPERTIES ---\n";
foreach ($unmappable_malformed as $item) {
    echo "TDN: {$item['tdn']} | Date: '{$item['effectivity_date']}' | {$item['reason']}\n";
}
