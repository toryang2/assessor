<?php
$db = new mysqli('localhost', 'root', '', 'assessor_local');
if ($db->connect_error) die($db->connect_error);

$res = $db->query("
    SELECT f.objid as faas_id, f.rpuid, f.realpropertyid, f.tdno, f.state,
           r.objid as rpu_objid, r.rpu_type, r.classification, r.total_market_value, r.total_assessed_value, r.total_area_hectare, r.total_area_sqm, r.fullpin as rpu_fullpin, r.barangayid as rpu_barangayid, r.realpropertyid as rpu_realpropertyid,
           rp.objid as rp_objid, rp.pin as rp_pin, rp.cadastrallotno, rp.surveyno, rp.barangay as rp_barangay, rp.barangayid as rp_barangayid
    FROM wp_assessor_faas f
    LEFT JOIN wp_assessor_rpu r ON f.rpuid = r.objid
    LEFT JOIN wp_assessor_real_property rp ON (r.realpropertyid = rp.objid OR f.realpropertyid = rp.objid)
    WHERE f.origin_type = 'REPLICA' AND f.state IN ('CURRENT', 'CANCELLED')
    LIMIT 3
");

while ($row = $res->fetch_assoc()) {
    print_r($row);
}
