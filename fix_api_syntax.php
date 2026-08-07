<?php
$api_file = 'd:/CODE/assessor/assessor-backend/wp-content/plugins/assessor-api/includes/class-assessor-api.php';
$api_code = file_get_contents($api_file);

// Find the incorrectly placed block
$bad_start = '            // Lookups';
$bad_end = '    public function check_auth($request) {';

$pos_start = strpos($api_code, $bad_start);
$pos_end = strpos($api_code, $bad_end);

if ($pos_start !== false && $pos_end !== false) {
    // Extract the block
    $block = substr($api_code, $pos_start, $pos_end - $pos_start);
    
    // Remove the block from its current bad location
    $api_code = substr_replace($api_code, '', $pos_start, $pos_end - $pos_start);
    
    // Now we need to insert the block INSIDE register_routes().
    // We can insert it right before the closing brace of register_routes().
    // Look for:
    //         register_rest_route('assessor/v1', '/etracs/entities/(?P<id>[a-zA-Z0-9\-\:]+)', array(
    //             'methods'  => 'DELETE',
    //             'callback' => array($this, 'etracs_delete_entity'),
    //             'permission_callback' => array($this, 'check_admin'),
    //         ));
    //     }
    
    $insert_target = "        register_rest_route('assessor/v1', '/etracs/entities/(?P<id>[a-zA-Z0-9\-\:]+)', array(
            'methods'  => 'DELETE',
            'callback' => array(\$this, 'etracs_delete_entity'),
            'permission_callback' => array(\$this, 'check_admin'),
        ));";
        
    if (strpos($api_code, $insert_target) !== false) {
        $api_code = str_replace($insert_target, $insert_target . "\n\n" . $block, $api_code);
        file_put_contents($api_file, $api_code);
        echo "Fixed class-assessor-api.php\n";
    } else {
        echo "Could not find insert target.\n";
    }
} else {
    echo "Could not find bad block.\n";
}
