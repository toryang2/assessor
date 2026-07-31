<?php
$ch = curl_init('http://localhost/assessor/wp-json/assessor/v1/faas?per_page=10');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$res = curl_exec($ch);
curl_close($ch);
$data = json_decode($res, true);
foreach($data['data'] as $f) {
    if ($f['prevtdno'] == '10-021-07268') {
        print_r($f);
    }
}
