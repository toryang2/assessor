<?php
$pdo = new PDO('mysql:host=localhost;port=3306;dbname=etracs254_kitaotao', 'root', '');
$stmt = $pdo->query("
    SELECT f.objid, f.prevtdno, f.prevowner,
           GROUP_CONCAT(fp.prevtdno SEPARATOR ' | ') as grouped_prevtdno,
           GROUP_CONCAT(fp.prevowner SEPARATOR ' | ') as grouped_prevowner
    FROM faas f
    LEFT JOIN faas_previous fp ON f.objid = fp.faasid
    GROUP BY f.objid
    HAVING grouped_prevowner IS NOT NULL
    LIMIT 5
");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    print_r($row);
}
