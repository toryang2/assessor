<?php
$c = new mysqli('localhost', 'root', '');
$r = $c->query('SHOW DATABASES');
while($row = $r->fetch_assoc()) {
    $db = $row['Database'];
    $c->select_db($db);
    $res = $c->query("SHOW TABLES LIKE 'wp_assessor_faas_list'");
    if($res && $res->num_rows > 0) {
        echo "Found in DB: $db\n";
    }
}
