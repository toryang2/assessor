<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=assessor_etracs_blank_smoke_20261028', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

function time_query($pdo, $sql, $params = []) {
    $start = microtime(true);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $duration = (microtime(true) - $start) * 1000;
    return ['ms' => $duration, 'count' => count($rows), 'first' => $rows[0] ?? null];
}

echo "=== BENCHMARK ON SMOKE DB (18,841 FAAS) ===" . PHP_EOL;

// 1. Unfiltered count
$t1 = time_query($pdo, 'SELECT COUNT(*) as c FROM wp_assessor_faas f');
printf("Unfiltered Count: %.2f ms (Total: %d)\n", $t1['ms'], $t1['first']['c']);

// 2. Filtered count (state = CURRENT)
$t2 = time_query($pdo, "SELECT COUNT(*) as c FROM wp_assessor_faas f LEFT JOIN (
    SELECT COALESCE(f_pred_id.objid, f_pred_td.objid) AS canonical_pred_id
    FROM wp_assessor_faas_previous fp_sub
    INNER JOIN wp_assessor_faas f_succ ON fp_sub.faasid = f_succ.objid
    LEFT JOIN wp_assessor_faas f_pred_id ON (fp_sub.prevfaasid IS NOT NULL AND fp_sub.prevfaasid != '' AND fp_sub.prevfaasid = f_pred_id.objid)
    LEFT JOIN (SELECT tdno, MIN(objid) AS objid FROM wp_assessor_faas WHERE tdno IS NOT NULL AND tdno != '' GROUP BY tdno HAVING COUNT(*) = 1) f_pred_td ON (f_pred_id.objid IS NULL AND fp_sub.prevtdno IS NOT NULL AND fp_sub.prevtdno != '' AND fp_sub.prevtdno = f_pred_td.tdno)
    WHERE f_succ.state IN ('CURRENT', 'CANCELLED')
    GROUP BY COALESCE(f_pred_id.objid, f_pred_td.objid)
) succ_filter ON f.objid = succ_filter.canonical_pred_id WHERE (f.state = 'CURRENT' AND succ_filter.canonical_pred_id IS NULL)");
printf("State=CURRENT Filtered Count: %.2f ms (Total: %d)\n", $t2['ms'], $t2['first']['c']);

// 3. Stats single pass
$t3 = time_query($pdo, "SELECT f.state, (succ_link.canonical_pred_id IS NOT NULL) AS is_superseded, COUNT(*) AS count
FROM wp_assessor_faas f
LEFT JOIN (
    SELECT COALESCE(f_pred_id.objid, f_pred_td.objid) AS canonical_pred_id
    FROM wp_assessor_faas_previous fp_sub
    INNER JOIN wp_assessor_faas f_succ ON fp_sub.faasid = f_succ.objid
    LEFT JOIN wp_assessor_faas f_pred_id ON (fp_sub.prevfaasid IS NOT NULL AND fp_sub.prevfaasid != '' AND fp_sub.prevfaasid = f_pred_id.objid)
    LEFT JOIN (SELECT tdno, MIN(objid) AS objid FROM wp_assessor_faas WHERE tdno IS NOT NULL AND tdno != '' GROUP BY tdno HAVING COUNT(*) = 1) f_pred_td ON (f_pred_id.objid IS NULL AND fp_sub.prevtdno IS NOT NULL AND fp_sub.prevtdno != '' AND fp_sub.prevtdno = f_pred_td.tdno)
    WHERE f_succ.state IN ('CURRENT', 'CANCELLED')
    GROUP BY COALESCE(f_pred_id.objid, f_pred_td.objid)
) succ_link ON f.objid = succ_link.canonical_pred_id
GROUP BY f.state, (succ_link.canonical_pred_id IS NOT NULL)");
printf("Stats Single Pass: %.2f ms (Buckets: %d)\n", $t3['ms'], $t3['count']);

// 4. Page 1 IDs (20 rows)
$t4 = time_query($pdo, 'SELECT f.objid FROM wp_assessor_faas f ORDER BY f.tdno DESC LIMIT 20 OFFSET 0');
printf("Page 1 IDs: %.2f ms (Count: %d)\n", $t4['ms'], $t4['count']);

// 5. Scoped Hydration on Page 1 IDs
$page_ids = array_map(function($r) { return $r['objid']; }, $pdo->query('SELECT f.objid FROM wp_assessor_faas f ORDER BY f.tdno DESC LIMIT 20 OFFSET 0')->fetchAll());
$in_csv = implode(',', array_map(function($id) use ($pdo) { return $pdo->quote($id); }, $page_ids));

$t5 = time_query($pdo, "SELECT f.*, r.rpu_type, r.classification, r.total_market_value, r.total_assessed_value, rp.pin AS rp_pin, rp.barangay, e.name AS taxpayer_name, tx.name AS txntype_name
FROM wp_assessor_faas f
LEFT JOIN wp_assessor_rpu r ON f.rpuid = r.objid
LEFT JOIN wp_assessor_real_property rp ON f.realpropertyid = rp.objid
LEFT JOIN wp_assessor_entity e ON f.taxpayer_objid = e.objid
LEFT JOIN wp_assessor_faas_txntypes tx ON f.txntype_objid = tx.code
WHERE f.objid IN ($in_csv)
ORDER BY FIELD(f.objid, $in_csv)");
printf("Scoped Base Rows Hydration: %.2f ms\n", $t5['ms']);

$t6 = time_query($pdo, "SELECT ra.rpuid, GROUP_CONCAT(DISTINCT COALESCE(la.name, ba.name, ma.name, pa.name, mia.name) SEPARATOR ', ') AS actualuse
FROM wp_assessor_rpu_assessment ra
INNER JOIN wp_assessor_faas f ON f.rpuid = ra.rpuid
LEFT JOIN wp_assessor_landassesslevel la ON (LOWER(ra.rputype) = 'land' AND la.objid = ra.actualuse_objid)
LEFT JOIN wp_assessor_bldgassesslevel ba ON (LOWER(ra.rputype) = 'bldg' AND ba.objid = ra.actualuse_objid)
LEFT JOIN wp_assessor_machassesslevel ma ON (LOWER(ra.rputype) = 'mach' AND ma.objid = ra.actualuse_objid)
LEFT JOIN wp_assessor_planttreeassesslevel pa ON (LOWER(ra.rputype) = 'planttree' AND pa.objid = ra.actualuse_objid)
LEFT JOIN wp_assessor_miscassesslevel mia ON (LOWER(ra.rputype) = 'misc' AND mia.objid = ra.actualuse_objid)
WHERE f.objid IN ($in_csv)
GROUP BY ra.rpuid");
printf("Scoped Actual Use Hydration: %.2f ms\n", $t6['ms']);

$t7 = time_query($pdo, "SELECT faasid, GROUP_CONCAT(prevtdno SEPARATOR ' | ') as fp_prevtdno
FROM wp_assessor_faas_previous
WHERE faasid IN ($in_csv)
GROUP BY faasid");
printf("Scoped Predecessor Hydration: %.2f ms\n", $t7['ms']);

$total_page_time = $t4['ms'] + $t5['ms'] + $t6['ms'] + $t7['ms'];
printf("TOTAL OPTIMIZED PAGE 1 RETRIEVAL TIME: %.2f ms (vs BEFORE ~2,524 ms) -> %.1fx FASTER!\n", $total_page_time, 2524 / $total_page_time);
