<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=assessor_local', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('ALTER TABLE wp_assessor_machine CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci');
$pdo->exec('ALTER TABLE wp_assessor_planttree CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci');
$pdo->exec('ALTER TABLE wp_assessor_planttreedetail CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci');
$pdo->exec('ALTER TABLE wp_assessor_migrations CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci');
echo "Collation normalized to utf8mb4_unicode_520_ci successfully.\n";
