<?php
$pdo = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', 'root', '');
echo 'Version: ' . $pdo->query('SELECT VERSION()')->fetchColumn() . PHP_EOL;
echo 'User: ' . $pdo->query('SELECT user(), current_user()')->fetch(PDO::FETCH_NUM)[0] . PHP_EOL;
