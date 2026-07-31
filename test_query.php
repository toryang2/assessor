<?php
$regex = '#^/etracs/faas/(?P<id>[a-zA-Z0-9\-\:]+)$#';
$str = '/etracs/faas/F-1004fbd9:17a1c757f0b:-99f';
var_dump(preg_match($regex, $str));

$regex2 = '#^/etracs/faas/(?P<id>[a-zA-Z0-9\-:]+)$#';
var_dump(preg_match($regex2, $str));
?>
