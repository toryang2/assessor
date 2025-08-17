<?php

class Assessor_Audit {
    
    public function get_audit_trail($request) {
        // This method is now handled by the main API class
        // Return empty array as fallback
        return array(
            'data' => [],
            'total' => 0
        );
    }
    
    public function log_activity($user_id, $action, $table_name, $record_id, $old_values = null, $new_values = null) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'assessor_audit_trail';
        
        $data = array(
            'user_id' => $user_id,
            'action' => $action,
            'table_name' => $table_name,
            'record_id' => $record_id,
            'old_values' => $old_values ? json_encode($old_values) : null,
            'new_values' => $new_values ? json_encode($new_values) : null,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'created_at' => current_time('mysql')
        );
        
        return $wpdb->insert($table, $data);
    }
}



