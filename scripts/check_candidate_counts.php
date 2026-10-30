<?php
$db = new mysqli('localhost', 'root', '', 'assessor_local');
if ($db->connect_error) die($db->connect_error);

$res = $db->query("
    SELECT 
        COUNT(*) as total_count,
        SUM(CASE WHEN f.rpuid IS NOT NULL AND f.rpuid != '' AND r.objid IS NOT NULL THEN 1 ELSE 0 END) as with_rpu,
        SUM(CASE WHEN f.rpuid IS NULL OR f.rpuid = '' OR r.objid IS NULL THEN 1 ELSE 0 END) as without_rpu,
        SUM(CASE WHEN f.realpropertyid IS NOT NULL AND rp.objid IS NOT NULL THEN 1 ELSE 0 END) as with_rp,
        SUM(CASE WHEN rp.barangayid IS NOT NULL AND b.objid IS NOT NULL THEN 1 ELSE 0 END) as with_barangay
    FROM wp_assessor_faas f
    LEFT JOIN wp_assessor_rpu r ON f.rpuid = r.objid
    LEFT JOIN wp_assessor_real_property rp ON f.realpropertyid = rp.objid
    LEFT JOIN wp_assessor_barangay b ON rp.barangayid = b.objid
    WHERE f.origin_type = 'REPLICA' 
      AND f.state IN ('CURRENT', 'CANCELLED')
      AND TRIM(COALESCE(f.tdno, '')) != ''
");

print_r($res->fetch_assoc());
