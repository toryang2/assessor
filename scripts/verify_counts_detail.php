<?php
$db = new mysqli('localhost', 'root', '', 'assessor_local');
if ($db->connect_error) die($db->connect_error);

// 1. Total CURRENT / CANCELLED with nonblank TD
$r = $db->query("SELECT COUNT(*) FROM wp_assessor_faas WHERE origin_type = 'REPLICA' AND state IN ('CURRENT','CANCELLED') AND TRIM(COALESCE(tdno,'')) != ''")->fetch_row();
echo "Total eligible source before native check: " . $r[0] . "\n";

// 2. Already present in native properties
// Check how many match native properties by trimmed TD
$sql_native = "
    SELECT COUNT(DISTINCT f.objid) 
    FROM wp_assessor_faas f
    JOIN wp_assessor_properties p ON LOWER(TRIM(f.tdno)) = LOWER(TRIM(p.tax_declaration_number))
    WHERE f.origin_type = 'REPLICA' AND f.state IN ('CURRENT','CANCELLED') AND TRIM(COALESCE(f.tdno,'')) != ''
";
$r = $db->query($sql_native)->fetch_row();
echo "Already present in native properties: " . $r[0] . "\n";

// 3. Candidates not in native properties
$sql_not_native = "
    SELECT f.objid, f.tdno, f.state, f.rpuid, r.objid as rpu_objid, r.rpu_type, r.classification, r.total_area_hectare, r.total_area_sqm, r.total_assessed_value,
           rp.barangayid, b.objid as b_objid, b.pin as b_pin, b.name as b_name
    FROM wp_assessor_faas f
    LEFT JOIN wp_assessor_properties p ON LOWER(TRIM(f.tdno)) = LOWER(TRIM(p.tax_declaration_number))
    LEFT JOIN wp_assessor_rpu r ON f.rpuid = r.objid
    LEFT JOIN wp_assessor_real_property rp ON f.realpropertyid = rp.objid
    LEFT JOIN wp_assessor_barangay b ON rp.barangayid = b.objid
    WHERE f.origin_type = 'REPLICA' AND f.state IN ('CURRENT','CANCELLED') AND TRIM(COALESCE(f.tdno,'')) != ''
      AND p.id IS NULL
";
$res = $db->query("SELECT COUNT(*) FROM ($sql_not_native) as c");
echo "Remaining candidates after native exclusion: " . $res->fetch_row()[0] . "\n";
