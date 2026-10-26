<?php
$pdo = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', 'root', '');

echo "=== wp_assessor_migrations in test ===\n";
$q1 = $pdo->query("SELECT * FROM `test`.wp_assessor_migrations");
print_r($q1->fetchAll(PDO::FETCH_ASSOC));

echo "=== wp_assessor_migrations in assessor_local ===\n";
$q2 = $pdo->query("SELECT * FROM `assessor_local`.wp_assessor_migrations");
print_r($q2->fetchAll(PDO::FETCH_ASSOC));

// Compare wp_assessor_properties:
$c_test = $pdo->query("SELECT count(*) FROM `test`.wp_assessor_properties")->fetchColumn();
$c_local = $pdo->query("SELECT count(*) FROM `assessor_local`.wp_assessor_properties")->fetchColumn();
echo "wp_assessor_properties: test=$c_test, assessor_local=$c_local\n";

// Check the date of recent updates in wp_assessor_properties
$d_test = $pdo->query("SELECT MAX(updated_at) as max_up, MAX(created_at) as max_cr FROM `test`.wp_assessor_properties")->fetch(PDO::FETCH_ASSOC);
echo "test properties dates: " . json_encode($d_test) . "\n";
$d_local = $pdo->query("SELECT MAX(updated_at) as max_up, MAX(created_at) as max_cr FROM `assessor_local`.wp_assessor_properties")->fetch(PDO::FETCH_ASSOC);
echo "assessor_local properties dates: " . json_encode($d_local) . "\n";

// Check active plugins in test vs assessor_local
$p_test = $pdo->query("SELECT option_value FROM `test`.wp_options WHERE option_name = 'active_plugins'")->fetchColumn();
echo "test active_plugins: $p_test\n";
$p_local = $pdo->query("SELECT option_value FROM `assessor_local`.wp_options WHERE option_name = 'active_plugins'")->fetchColumn();
echo "assessor_local active_plugins: $p_local\n";
