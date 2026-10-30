<?php
$db = new mysqli('localhost', 'root', '', 'assessor_local');
if ($db->connect_error) die($db->connect_error);

echo "=== PROPERTY SAMPLES ===\n";
$res = $db->query("SELECT id, tax_declaration_number, declarant_last_name, declarant_first_name, location, kind_of_property, gen_class FROM wp_assessor_properties LIMIT 5");
while ($r = $res->fetch_assoc()) {
    print_r($r);
}

echo "=== GENERAL CLASSES ===\n";
$res = $db->query("SELECT id, code, name, status, deleted_at FROM wp_assessor_general_classes");
while ($r = $res->fetch_assoc()) {
    print_r($r);
}

echo "=== PROPERTY TYPES ===\n";
$res = $db->query("SELECT id, code, name, status, deleted_at FROM wp_assessor_property_types");
while ($r = $res->fetch_assoc()) {
    print_r($r);
}

echo "=== NATIVE LOCATIONS ===\n";
$res = $db->query("SELECT id, code, name, pin, status, deleted_at FROM wp_assessor_locations ORDER BY sort_order, name");
while ($r = $res->fetch_assoc()) {
    print_r($r);
}
