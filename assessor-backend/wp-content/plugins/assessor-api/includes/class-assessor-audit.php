<?php

class Assessor_Audit {
    
    public function get_audit_trail($request) {
        global $wpdb;
        
        $params = is_array($request) ? $request : $request->get_params();
        
        $page = max(1, intval($params['page'] ?? 1));
        $per_page = max(1, min(100, intval($params['per_page'] ?? 20)));
        $offset = ($page - 1) * $per_page;
        
        $search = trim($params['search'] ?? '');
        $action = trim($params['action'] ?? '');
        $table = trim($params['table'] ?? '');
        $user_id = isset($params['user_id']) && $params['user_id'] !== '' ? intval($params['user_id']) : null;
        $record_id = isset($params['record_id']) && $params['record_id'] !== '' ? intval($params['record_id']) : null;
        $date_from = $params['dateFrom'] ?? null;
        $date_to = $params['dateTo'] ?? null;
        
        $table_audit = $wpdb->prefix . 'assessor_audit_trail';
        $table_users = $wpdb->prefix . 'assessor_users';
        
        $where = array();
        $values = array();
        
        if ($search !== '') {
            $where[] = "(a.action LIKE %s OR a.table_name LIKE %s OR a.ip_address LIKE %s OR a.user_agent LIKE %s OR u.full_name LIKE %s)";
            $like = '%' . $wpdb->esc_like($search) . '%';
            $values[] = $like; $values[] = $like; $values[] = $like; $values[] = $like; $values[] = $like;
        }
        if ($action !== '') {
            $where[] = "a.action = %s";
            $values[] = $action;
        }
        if ($table !== '') {
            $where[] = "a.table_name = %s";
            $values[] = $table;
        }
        if ($user_id !== null) {
            $where[] = "a.user_id = %d";
            $values[] = $user_id;
        }
        if ($record_id !== null) {
            $where[] = "a.record_id = %d";
            $values[] = $record_id;
        }
        if (!empty($date_from)) {
            // Accept either Date object from frontend or string; treat as date start
            $where[] = "a.created_at >= %s";
            $values[] = is_string($date_from) ? $date_from : date('Y-m-d 00:00:00', strtotime($date_from));
        }
        if (!empty($date_to)) {
            // Treat as inclusive end of day
            $where[] = "a.created_at <= %s";
            $values[] = is_string($date_to) ? $date_to : date('Y-m-d 23:59:59', strtotime($date_to));
        }
        
        $where_clause = '';
        if (!empty($where)) {
            $where_clause = 'WHERE ' . implode(' AND ', $where);
        }
        
        // Total count
        $count_sql = "SELECT COUNT(*) FROM $table_audit a LEFT JOIN $table_users u ON a.user_id = u.id $where_clause";
        if (!empty($values)) {
            $count_sql = $wpdb->prepare($count_sql, $values);
        }
        $total = intval($wpdb->get_var($count_sql));
        
        // Data query
        $data_sql = "
            SELECT a.*, u.full_name AS user_name
            FROM $table_audit a
            LEFT JOIN $table_users u ON a.user_id = u.id
            $where_clause
            ORDER BY a.created_at DESC
            LIMIT %d OFFSET %d
        ";
        $data_values = array_merge($values, array($per_page, $offset));
        $data_query = $wpdb->prepare($data_sql, $data_values);
        $rows = $wpdb->get_results($data_query, ARRAY_A);
        
        return array(
            'data' => $rows,
            'total' => $total,
            'pagination' => array(
                'page' => $page,
                'per_page' => $per_page,
                'total' => $total,
                'total_pages' => $per_page > 0 ? ceil($total / $per_page) : 1
            )
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
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
        );
        
        $formats = array('%d','%s','%s','%d','%s','%s','%s','%s');
        
        return $wpdb->insert($table, $data, $formats);
    }
}



