<?php
require 'C:/xampp/htdocs/wp-load.php';

$res = Assessor_Sync::pull_revision_entries_from_live(true);
print_r($res);








