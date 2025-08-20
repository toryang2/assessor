<?php

class Assessor_Export {
    
    public function __construct() {
        // Constructor
    }
    
    public function export_data($request) {
        // Get export parameters
        $format = $request->get_param('format') ?: 'json';
        $type = $request->get_param('type') ?: 'properties';
        $filters = $request->get_param('filters') ?: array();
        
        try {
            switch ($type) {
                case 'properties':
                    return $this->export_properties($format, $filters);
                case 'versions':
                    return $this->export_versions($format, $filters);
                case 'audit':
                    return $this->export_audit($format, $filters);
                default:
                    return new WP_Error('invalid_type', 'Invalid export type', array('status' => 400));
            }
        } catch (Exception $e) {
            error_log('Export error: ' . $e->getMessage());
            return new WP_Error('export_error', 'Export failed: ' . $e->getMessage(), array('status' => 500));
        }
    }
    
    private function export_properties($format, $filters) {
        global $wpdb;
        
        $properties_table = $wpdb->prefix . 'assessor_properties';
        
        // Build query with filters
        $where_clause = "WHERE 1=1";
        $query_params = array();
        
        if (!empty($filters['search'])) {
            $where_clause .= " AND (property_number LIKE %s OR owner_name LIKE %s OR address LIKE %s)";
            $search_term = '%' . $wpdb->esc_like($filters['search']) . '%';
            $query_params[] = $search_term;
            $query_params[] = $search_term;
            $query_params[] = $search_term;
        }
        
        if (!empty($filters['status'])) {
            $where_clause .= " AND status = %s";
            $query_params[] = $filters['status'];
        }
        
        $query = "SELECT * FROM {$properties_table} {$where_clause} ORDER BY created_at DESC";
        
        if (!empty($query_params)) {
            $query = $wpdb->prepare($query, $query_params);
        }
        
        $properties = $wpdb->get_results($query, ARRAY_A);
        
        if ($format === 'csv') {
            return $this->generate_csv($properties, 'properties');
        } else {
            return array(
                'success' => true,
                'data' => $properties,
                'count' => count($properties),
                'format' => $format
            );
        }
    }
    
    private function export_versions($format, $filters) {
        global $wpdb;
        
        $versions_table = $wpdb->prefix . 'assessor_property_versions';
        
        $where_clause = "WHERE 1=1";
        $query_params = array();
        
        if (!empty($filters['property_id'])) {
            $where_clause .= " AND property_id = %d";
            $query_params[] = intval($filters['property_id']);
        }
        
        $query = "SELECT * FROM {$versions_table} {$where_clause} ORDER BY version_number DESC";
        
        if (!empty($query_params)) {
            $query = $wpdb->prepare($query, $query_params);
        }
        
        $versions = $wpdb->get_results($query, ARRAY_A);
        
        if ($format === 'csv') {
            return $this->generate_csv($versions, 'versions');
        } else {
            return array(
                'success' => true,
                'data' => $versions,
                'count' => count($versions),
                'format' => $format
            );
        }
    }
    
    private function export_audit($format, $filters) {
        global $wpdb;
        
        $audit_table = $wpdb->prefix . 'assessor_audit_trail';
        
        $where_clause = "WHERE 1=1";
        $query_params = array();
        
        if (!empty($filters['action'])) {
            $where_clause .= " AND action = %s";
            $query_params[] = $filters['action'];
        }
        
        if (!empty($filters['user_id'])) {
            $where_clause .= " AND user_id = %d";
            $query_params[] = intval($filters['user_id']);
        }
        
        if (!empty($filters['date_from'])) {
            $where_clause .= " AND created_at >= %s";
            $query_params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $where_clause .= " AND created_at <= %s";
            $query_params[] = $filters['date_to'];
        }
        
        $query = "SELECT * FROM {$audit_table} {$where_clause} ORDER BY created_at DESC";
        
        if (!empty($query_params)) {
            $query = $wpdb->prepare($query, $query_params);
        }
        
        $audit_entries = $wpdb->get_results($query, ARRAY_A);
        
        if ($format === 'csv') {
            return $this->generate_csv($audit_entries, 'audit');
        } else {
            return array(
                'success' => true,
                'data' => $audit_entries,
                'count' => count($audit_entries),
                'format' => $format
            );
        }
    }
    
    private function generate_csv($data, $type) {
        if (empty($data)) {
            return new WP_Error('no_data', 'No data to export', array('status' => 404));
        }
        
        $filename = "assessor_{$type}_" . date('Y-m-d_H-i-s') . ".csv";
        
        // Set headers for CSV download
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        $output = fopen('php://output', 'w');
        
        // Write headers
        if (!empty($data)) {
            fputcsv($output, array_keys($data[0]));
        }
        
        // Write data
        foreach ($data as $row) {
            fputcsv($output, $row);
        }
        
        fclose($output);
        exit;
    }
}
