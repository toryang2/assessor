<?php

class Assessor_Requests {
    
    private $db;
    private $table_name;
    
    public function __construct() {
        global $wpdb;
        $this->db = $wpdb;
        $this->table_name = $wpdb->prefix . 'assessor_requests';
    }
    
    /**
     * Create the requests table if it doesn't exist
     */
    public function create_table() {
        $charset_collate = $this->db->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            property_id bigint(20) DEFAULT NULL,
            amount_paid decimal(10,2) NOT NULL,
            receipt_number varchar(100) NOT NULL,
            date_issued date NOT NULL,
            place_issued varchar(255) NOT NULL,
            prepared_by varchar(255) NOT NULL,
            payment_type varchar(50) NOT NULL,
            purpose varchar(100) NOT NULL,
            client_name varchar(255) NOT NULL,
            client_address text,
            contact_number varchar(50),
            email varchar(255),
            remarks text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_by bigint(20) DEFAULT NULL,
            updated_by bigint(20) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY property_id (property_id),
            KEY receipt_number (receipt_number),
            KEY date_issued (date_issued),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Create a new request
     */
    public function create_request($data, $request = null) {
        // Get current user ID from JWT token if available
        $current_user_id = null;
        if ($request) {
            $auth = new Assessor_Auth();
            $current_user_id = $auth->get_user_id_from_token($request);
        }
        
        // Fallback to WordPress current user if JWT method fails
        if (!$current_user_id) {
            $current_user_id = get_current_user_id();
        }
        
        // Default to null if still no user ID
        if (!$current_user_id) {
            $current_user_id = null;
        }
        
        $defaults = array(
            'property_id' => null,
            'amount_paid' => 0.00,
            'receipt_number' => '',
            'date_issued' => date('Y-m-d'),
            'place_issued' => '',
            'prepared_by' => '',
            'purpose' => '',
            'client_name' => '',
            'client_address' => '',
            'contact_number' => '',
            'email' => '',
            'remarks' => '',
            'created_by' => $current_user_id,
            'updated_by' => $current_user_id,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        );
        
        $data = wp_parse_args($data, $defaults);
        
        // Validate required fields
        $required_fields = array('amount_paid', 'receipt_number', 'date_issued', 'place_issued', 'prepared_by', 'purpose', 'client_name');
        foreach ($required_fields as $field) {
            if (empty($data[$field])) {
                return new WP_Error('missing_field', "Field '$field' is required", array('status' => 400, 'code' => 'missing_field'));
            }
        }
        
        // Validate amount
        if (!is_numeric($data['amount_paid']) || $data['amount_paid'] <= 0) {
            return new WP_Error('invalid_amount', 'Amount paid must be a positive number', array('status' => 400, 'code' => 'invalid_amount'));
        }
        
        // Validate date
        if (!strtotime($data['date_issued'])) {
            return new WP_Error('invalid_date', 'Invalid date format', array('status' => 400, 'code' => 'invalid_date'));
        }
        
        // Check if receipt number already exists
        $existing = $this->db->get_var($this->db->prepare(
            "SELECT id FROM {$this->table_name} WHERE receipt_number = %s",
            $data['receipt_number']
        ));
        
        if ($existing) {
            return new WP_Error('duplicate_receipt', 'Receipt number already exists', array('status' => 400, 'code' => 'duplicate_receipt'));
        }
        
        $insert_data = array(
            'property_id' => $data['property_id'],
            'amount_paid' => $data['amount_paid'],
            'receipt_number' => sanitize_text_field($data['receipt_number']),
            'date_issued' => $data['date_issued'],
            'place_issued' => sanitize_text_field($data['place_issued']),
            'prepared_by' => sanitize_text_field($data['prepared_by']),
            'purpose' => sanitize_text_field($data['purpose']),
            'client_name' => sanitize_text_field($data['client_name']),
            'client_address' => sanitize_textarea_field($data['client_address']),
            'contact_number' => sanitize_text_field($data['contact_number']),
            'email' => sanitize_email($data['email']),
            'remarks' => sanitize_textarea_field($data['remarks']),
            'created_by' => $data['created_by'],
            'updated_by' => $data['updated_by'],
            'created_at' => $data['created_at'],
            'updated_at' => $data['updated_at']
        );
        
        $insert_format = array(
            '%d', // property_id
            '%f', // amount_paid
            '%s', // receipt_number
            '%s', // date_issued
            '%s', // place_issued
            '%s', // prepared_by
            '%s', // purpose
            '%s', // client_name
            '%s', // client_address
            '%s', // contact_number
            '%s', // email
            '%s', // remarks
            '%d', // created_by
            '%d', // updated_by
            '%s', // created_at
            '%s'  // updated_at
        );
        
        $result = $this->db->insert($this->table_name, $insert_data, $insert_format);
        
        if ($result === false) {
            return new WP_Error('db_error', 'Failed to create request', array('status' => 500));
        }
        
        $request_id = $this->db->insert_id;
        return $this->get_request($request_id);
    }
    
    /**
     * Get a single request by ID
     */
    public function get_request($id) {
        $query = $this->db->prepare(
            "SELECT r.*, 
                    p.tax_declaration_number,
                    p.declarant_last_name,
                    p.declarant_first_name,
                    p.declarant_middle_initial,
                    p.business,
                    p.location,
                    p.assessed_value,
                    u1.display_name as created_by_name,
                    u2.display_name as updated_by_name
             FROM {$this->table_name} r
             LEFT JOIN {$this->db->prefix}assessor_properties p ON r.property_id = p.id
             LEFT JOIN {$this->db->users} u1 ON r.created_by = u1.ID
             LEFT JOIN {$this->db->users} u2 ON r.updated_by = u2.ID
             WHERE r.id = %d",
            $id
        );
        
        $request = $this->db->get_row($query, ARRAY_A);
        
        if (!$request) {
            return new WP_Error('not_found', 'Request not found', array('status' => 404));
        }
        
        return $request;
    }
    
    /**
     * Get requests with pagination and filters
     */
    public function get_requests($args = array()) {
        $defaults = array(
            'page' => 1,
            'per_page' => 20,
            'search' => '',
            'property_id' => null,
            'date_issued' => null,
            'payment_type' => null,
            'purpose' => null,
            'prepared_by' => null,
            'all' => false
        );
        
        $args = wp_parse_args($args, $defaults);
        
        // Handle 'all' parameter - if true, return all records without pagination
        $fetch_all = !empty($args['all']) && ($args['all'] === '1' || $args['all'] === 1 || $args['all'] === true);
        
        $where_conditions = array('1=1');
        $where_values = array();
        
        // Search filter
        if (!empty($args['search'])) {
            $search_term = '%' . $this->db->esc_like($args['search']) . '%';
            $where_conditions[] = "(r.receipt_number LIKE %s OR r.client_name LIKE %s OR r.client_address LIKE %s OR r.remarks LIKE %s OR r.purpose LIKE %s OR r.prepared_by LIKE %s OR p.tax_declaration_number LIKE %s OR p.declarant_last_name LIKE %s OR p.declarant_first_name LIKE %s OR p.declarant_middle_initial LIKE %s OR p.business LIKE %s OR p.location LIKE %s)";
            $where_values[] = $search_term;
            $where_values[] = $search_term;
            $where_values[] = $search_term;
            $where_values[] = $search_term;
            $where_values[] = $search_term;
            $where_values[] = $search_term;
            $where_values[] = $search_term;
            $where_values[] = $search_term;
            $where_values[] = $search_term;
            $where_values[] = $search_term;
            $where_values[] = $search_term;
            $where_values[] = $search_term;
        }
        
        // Property filter
        if (!empty($args['property_id'])) {
            $where_conditions[] = "r.property_id = %d";
            $where_values[] = $args['property_id'];
        }
        
        // Date filter
        if (!empty($args['date_issued'])) {
            $where_conditions[] = "r.date_issued = %s";
            $where_values[] = $args['date_issued'];
        }
        
        // Payment type filter
        if (!empty($args['payment_type'])) {
            $where_conditions[] = "r.payment_type = %s";
            $where_values[] = $args['payment_type'];
        }
        
        // Purpose filter
        if (!empty($args['purpose'])) {
            $where_conditions[] = "r.purpose = %s";
            $where_values[] = $args['purpose'];
        }
        
        // Prepared by filter
        if (!empty($args['prepared_by'])) {
            $where_conditions[] = "r.prepared_by LIKE %s";
            $where_values[] = '%' . $this->db->esc_like($args['prepared_by']) . '%';
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        
        // Count total records
        $count_query = "SELECT COUNT(*) FROM {$this->table_name} r WHERE {$where_clause}";
        if (!empty($where_values)) {
            $count_query = $this->db->prepare($count_query, $where_values);
        }
        $total = $this->db->get_var($count_query);
        
        // Build the main query
        $query = "SELECT r.*, 
                         p.tax_declaration_number,
                         p.declarant_last_name,
                         p.declarant_first_name,
                         p.declarant_middle_initial,
                         p.business,
                         p.location,
                         p.assessed_value,
                         u1.display_name as created_by_name,
                         u2.display_name as updated_by_name
                  FROM {$this->table_name} r
                  LEFT JOIN {$this->db->prefix}assessor_properties p ON r.property_id = p.id
                  LEFT JOIN {$this->db->users} u1 ON r.created_by = u1.ID
                  LEFT JOIN {$this->db->users} u2 ON r.updated_by = u2.ID
                  WHERE {$where_clause}
                  ORDER BY r.created_at DESC";
        
        if (!$fetch_all) {
            // Add pagination for normal requests
            $offset = ($args['page'] - 1) * $args['per_page'];
            $query .= " LIMIT %d OFFSET %d";
            $query_values = array_merge($where_values, array($args['per_page'], $offset));
        } else {
            // No pagination for 'all' requests
            $query_values = $where_values;
        }
        
        $query = $this->db->prepare($query, $query_values);
        $requests = $this->db->get_results($query, ARRAY_A);
        
        if ($fetch_all) {
            // Return all records without pagination info
            return array(
                'requests' => $requests,
                'total' => (int) $total
            );
        } else {
            // Return paginated results with pagination info
            return array(
                'requests' => $requests,
                'pagination' => array(
                    'total' => (int) $total,
                    'per_page' => (int) $args['per_page'],
                    'current_page' => (int) $args['page'],
                    'total_pages' => ceil($total / $args['per_page'])
                )
            );
        }
    }
    
    /**
     * Update a request
     */
    public function update_request($id, $data, $request = null) {
        // Get current user ID from JWT token if available
        $current_user_id = null;
        if ($request) {
            $auth = new Assessor_Auth();
            $current_user_id = $auth->get_user_id_from_token($request);
        }
        
        // Fallback to WordPress current user if JWT method fails
        if (!$current_user_id) {
            $current_user_id = get_current_user_id();
        }
        
        // Default to null if still no user ID
        if (!$current_user_id) {
            $current_user_id = null;
        }
        
        // Check if request exists
        $existing = $this->get_request($id);
        if (is_wp_error($existing)) {
            return $existing;
        }
        
        // Validate required fields if provided
        $required_fields = array('amount_paid', 'receipt_number', 'date_issued', 'place_issued', 'prepared_by', 'purpose', 'client_name');
        foreach ($required_fields as $field) {
            if (isset($data[$field]) && empty($data[$field])) {
                return new WP_Error('missing_field', "Field '$field' cannot be empty", array('status' => 400, 'code' => 'missing_field'));
            }
        }
        
        // Validate amount if provided
        if (isset($data['amount_paid']) && (!is_numeric($data['amount_paid']) || $data['amount_paid'] <= 0)) {
            return new WP_Error('invalid_amount', 'Amount paid must be a positive number', array('status' => 400, 'code' => 'invalid_amount'));
        }
        
        // Validate date if provided
        if (isset($data['date_issued']) && !strtotime($data['date_issued'])) {
            return new WP_Error('invalid_date', 'Invalid date format', array('status' => 400, 'code' => 'invalid_date'));
        }
        
        // Check for duplicate receipt number if changed
        if (isset($data['receipt_number']) && $data['receipt_number'] !== $existing['receipt_number']) {
            $duplicate = $this->db->get_var($this->db->prepare(
                "SELECT id FROM {$this->table_name} WHERE receipt_number = %s AND id != %d",
                $data['receipt_number'],
                $id
            ));
            
            if ($duplicate) {
                return new WP_Error('duplicate_receipt', 'Receipt number already exists', array('status' => 400, 'code' => 'duplicate_receipt'));
            }
        }
        
        // Prepare update data
        $update_data = array();
        $update_format = array();
        
        $fields = array(
            'property_id' => '%d',
            'amount_paid' => '%f',
            'receipt_number' => '%s',
            'date_issued' => '%s',
            'place_issued' => '%s',
            'prepared_by' => '%s',
            'purpose' => '%s',
            'client_name' => '%s',
            'client_address' => '%s',
            'contact_number' => '%s',
            'email' => '%s',
            'remarks' => '%s'
        );
        
        foreach ($fields as $field => $format) {
            if (isset($data[$field])) {
                $update_data[$field] = $data[$field];
                $update_format[] = $format;
            }
        }
        
        // Add updated_by and updated_at
        $update_data['updated_by'] = $current_user_id;
        $update_data['updated_at'] = date('Y-m-d H:i:s');
        $update_format[] = '%d';
        $update_format[] = '%s';
        
        if (empty($update_data)) {
            return new WP_Error('no_data', 'No data provided for update', array('status' => 400));
        }
        
        $result = $this->db->update(
            $this->table_name,
            $update_data,
            array('id' => $id),
            $update_format,
            array('%d')
        );
        
        if ($result === false) {
            return new WP_Error('db_error', 'Failed to update request', array('status' => 500));
        }
        
        return $this->get_request($id);
    }
    
    /**
     * Delete a request
     */
    public function delete_request($id) {
        // Check if request exists
        $existing = $this->get_request($id);
        if (is_wp_error($existing)) {
            return $existing;
        }
        
        $result = $this->db->delete(
            $this->table_name,
            array('id' => $id),
            array('%d')
        );
        
        if ($result === false) {
            return new WP_Error('db_error', 'Failed to delete request', array('status' => 500));
        }
        
        return array('message' => 'Request deleted successfully');
    }
    
    /**
     * Get request statistics
     */
    public function get_statistics($args = array()) {
        $defaults = array(
            'date_from' => null,
            'date_to' => null,
            'summary_only' => false
        );
        
        $args = wp_parse_args($args, $defaults);
        $summary_only = !empty($args['summary_only']) && ($args['summary_only'] === true || $args['summary_only'] === '1' || $args['summary_only'] === 1);
        
        $where_conditions = array('1=1');
        $where_values = array();
        
        // Date range filter
        if (!empty($args['date_from'])) {
            $where_conditions[] = "date_issued >= %s";
            $where_values[] = $args['date_from'];
        }
        
        if (!empty($args['date_to'])) {
            $where_conditions[] = "date_issued <= %s";
            $where_values[] = $args['date_to'];
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        
        // Total amount
        $total_amount_query = "SELECT SUM(amount_paid) FROM {$this->table_name} WHERE {$where_clause}";
        if (!empty($where_values)) {
            $total_amount_query = $this->db->prepare($total_amount_query, $where_values);
        }
        $total_amount = $this->db->get_var($total_amount_query) ?: 0;
        
        // Total requests
        $total_requests_query = "SELECT COUNT(*) FROM {$this->table_name} WHERE {$where_clause}";
        if (!empty($where_values)) {
            $total_requests_query = $this->db->prepare($total_requests_query, $where_values);
        }
        $total_requests = $this->db->get_var($total_requests_query) ?: 0;
        
        // This month / last month counts (based on created_at for consistency)
        $now_ts = current_time('timestamp');
        $curr_start = date('Y-m-01', $now_ts);
        $next_start = date('Y-m-01', strtotime('+1 month', $now_ts));
        $prev_start = date('Y-m-01', strtotime('-1 month', $now_ts));
        $this_month_count = (int)$this->db->get_var($this->db->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE created_at >= %s AND created_at < %s",
            $curr_start,
            $next_start
        ));
        $last_month_count = (int)$this->db->get_var($this->db->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE created_at >= %s AND created_at < %s",
            $prev_start,
            $curr_start
        ));

        if ($summary_only) {
            return array(
                'total_amount' => (float) $total_amount,
                'total_requests' => (int) $total_requests,
                'total_requests_this_month' => (int) $this_month_count,
                'total_requests_last_month' => (int) $last_month_count
            );
        }

        // Payment type breakdown
        $payment_types_query = "SELECT payment_type, COUNT(*) as count, SUM(amount_paid) as total
                                FROM {$this->table_name} 
                                WHERE {$where_clause}
                                GROUP BY payment_type
                                ORDER BY total DESC";
        if (!empty($where_values)) {
            $payment_types_query = $this->db->prepare($payment_types_query, $where_values);
        }
        $payment_types = $this->db->get_results($payment_types_query, ARRAY_A);
        
        // Purpose breakdown
        $purposes_query = "SELECT purpose, COUNT(*) as count, SUM(amount_paid) as total
                           FROM {$this->table_name} 
                           WHERE {$where_clause}
                           GROUP BY purpose
                           ORDER BY total DESC";
        if (!empty($where_values)) {
            $purposes_query = $this->db->prepare($purposes_query, $where_values);
        }
        $purposes = $this->db->get_results($purposes_query, ARRAY_A);
        
        return array(
            'total_amount' => (float) $total_amount,
            'total_requests' => (int) $total_requests,
            'total_requests_this_month' => (int) $this_month_count,
            'total_requests_last_month' => (int) $last_month_count,
            'payment_types' => $payment_types,
            'purposes' => $purposes
        );
    }
}
