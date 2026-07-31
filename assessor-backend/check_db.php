<?php
$c = new mysqli('localhost', 'root', '');
$r = $c->query('SHOW DATABASES');
while($row = $r->fetch_assoc()) echo $row['Database'] . "\n";
