<?php
$ch = curl_init('http://localhost/wp-json/assessor/v1/etracs/rpu/RPUff9a58:173c1174121:-4956/detail');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$resp = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
echo "HTTP Code: " . $httpcode . "\n";
echo "Response: " . $resp . "\n";
