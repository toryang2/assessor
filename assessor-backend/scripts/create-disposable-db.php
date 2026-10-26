<?php
$pdo = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', 'root', '');
$pdo->exec('CREATE DATABASE IF NOT EXISTS `test_wp10_disposable`');
echo "Created test_wp10_disposable successfully.\n";
