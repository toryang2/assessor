<?php
require_once 'C:\xampp\htdocs\wp-load.php';

$etracs = new Assessor_Etracs();
$response = $etracs->get_faas('Fff9a58:173c293df6d:-4a0a');
print_r($response);
