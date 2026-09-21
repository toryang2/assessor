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
        
        // Migration: Add purpose_details column to requests table if missing
        $column_purpose_details = $this->db->get_var($this->db->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'purpose_details'", $this->table_name));
        if (!$column_purpose_details) {
            $this->db->query("ALTER TABLE {$this->table_name} ADD COLUMN purpose_details text NULL AFTER purpose");
        }

        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id varchar(36) NOT NULL,
            property_id varchar(36) DEFAULT NULL,
            amount_paid decimal(10,2) NOT NULL,
            receipt_number varchar(100) NOT NULL,
            is_official_request tinyint(1) NOT NULL DEFAULT 0,
            date_issued datetime NOT NULL,
            place_issued varchar(255) NOT NULL,
            prepared_by varchar(255) NOT NULL,
            payment_type varchar(50) NOT NULL,
            purpose varchar(100) NOT NULL,
            purpose_details text NULL,
            client_name varchar(255) NOT NULL,
            client_address text,
            contact_number varchar(50),
            email varchar(255),
            remarks text,
            deleted_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_by varchar(50) DEFAULT NULL,
            updated_by varchar(50) DEFAULT NULL,
            verifier_signatory_name varchar(255) DEFAULT NULL,
            verifier_signatory_title varchar(255) DEFAULT NULL,
            municipal_assessor_name varchar(255) DEFAULT NULL,
            municipal_assessor_title varchar(255) DEFAULT NULL,
            municipal_assessor_license varchar(255) DEFAULT NULL,
            municipal_assessor_suffix varchar(255) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY property_id (property_id),
            KEY receipt_number (receipt_number),
            KEY date_issued (date_issued),
            KEY deleted_at (deleted_at),
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
            'purpose_details' => '',
            'client_name' => '',
            'client_address' => '',
            'contact_number' => '',
            'email' => '',
            'remarks' => '',
            'created_by' => $current_user_id,
            'updated_by' => $current_user_id,
            'created_at' => Assessor_Timezone::now_mysql(),
            'updated_at' => Assessor_Timezone::now_mysql()
        );
        
        $data = wp_parse_args($data, $defaults);

        // Official requests: bypass payment requirements
        $is_official_request = !empty($data['is_official_request']) && in_array($data['is_official_request'], array(1, '1', true, 'true', 'yes', 'on'), true);
        if ($is_official_request) {
            $data['amount_paid'] = 0.00;
            $data['receipt_number'] = 'Official Use';
        }
        
        // Validate required fields
        $required_fields = array('amount_paid', 'receipt_number', 'date_issued', 'place_issued', 'prepared_by', 'purpose', 'client_name');
        foreach ($required_fields as $field) {
            // Don't treat numeric 0 as missing; allow official requests to use 0.00
            if (!isset($data[$field]) || $data[$field] === '' || $data[$field] === null) {
                return new WP_Error('missing_field', "Field '$field' is required", array('status' => 400, 'code' => 'missing_field'));
            }
        }
        
        // Validate amount
        if (!is_numeric($data['amount_paid']) || ($is_official_request ? ($data['amount_paid'] < 0) : ($data['amount_paid'] <= 0))) {
            return new WP_Error('invalid_amount', 'Amount paid must be a positive number', array('status' => 400, 'code' => 'invalid_amount'));
        }
        
        // Validate date
        if (!strtotime($data['date_issued'])) {
            return new WP_Error('invalid_date', 'Invalid date format', array('status' => 400, 'code' => 'invalid_date'));
        }
        
        // Check if receipt number already exists
        if (!$is_official_request) {
            $existing = $this->db->get_var($this->db->prepare(
                "SELECT id FROM {$this->table_name} WHERE receipt_number = %s",
                $data['receipt_number']
            ));
            
            if ($existing) {
                return new WP_Error('duplicate_receipt', 'Receipt number already exists', array('status' => 400, 'code' => 'duplicate_receipt'));
            }
        }
        
        // Fetch current settings to use as fallback if frontend doesn't send signatories
        $settings = $this->db->get_row("SELECT * FROM {$this->db->prefix}assessor_settings ORDER BY id DESC LIMIT 1", ARRAY_A);
        
        $request_id = Assessor_UUID::v7();

        $purpose_details = isset($data['purpose_details']) && $data['purpose_details'] !== null && $data['purpose_details'] !== ''
            ? sanitize_textarea_field($data['purpose_details'])
            : null;

        $insert_data = array(
            'id' => $request_id,
            'property_id' => $data['property_id'],
            'amount_paid' => $data['amount_paid'],
            'receipt_number' => sanitize_text_field($data['receipt_number']),
            'date_issued' => sanitize_text_field($data['date_issued']),
            'place_issued' => sanitize_text_field($data['place_issued']),
            'prepared_by' => sanitize_text_field($data['prepared_by']),
            'payment_type' => !empty($data['payment_type']) ? sanitize_text_field($data['payment_type']) : 'cash',
            'purpose' => sanitize_text_field($data['purpose']),
            'purpose_details' => $purpose_details,
            'client_name' => sanitize_text_field($data['client_name']),
            'client_address' => sanitize_textarea_field($data['client_address']),
            'contact_number' => sanitize_text_field($data['contact_number']),
            'email' => sanitize_email($data['email']),
            'remarks' => sanitize_textarea_field($data['remarks']),
            'is_official_request' => $is_official_request ? 1 : 0,
            'created_by' => $data['created_by'],
            'updated_by' => $data['updated_by'],
            'created_at' => $data['created_at'],
            'updated_at' => $data['updated_at'],
            'verifier_signatory_name' => !empty($data['verifier_signatory_name']) ? sanitize_text_field($data['verifier_signatory_name']) : ($settings['verifier_signatory_name'] ?? null),
            'verifier_signatory_title' => !empty($data['verifier_signatory_title']) ? sanitize_text_field($data['verifier_signatory_title']) : ($settings['verifier_signatory_title'] ?? null),
            'municipal_assessor_name' => !empty($data['municipal_assessor_name']) ? sanitize_text_field($data['municipal_assessor_name']) : ($settings['municipal_assessor_name'] ?? null),
            'municipal_assessor_title' => !empty($data['municipal_assessor_title']) ? sanitize_text_field($data['municipal_assessor_title']) : ($settings['municipal_assessor_title'] ?? null),
            'municipal_assessor_license' => !empty($data['municipal_assessor_license']) ? sanitize_text_field($data['municipal_assessor_license']) : ($settings['municipal_assessor_license'] ?? null),
            'municipal_assessor_suffix' => !empty($data['municipal_assessor_suffix']) ? sanitize_text_field($data['municipal_assessor_suffix']) : ($settings['municipal_assessor_suffix'] ?? null)
        );
        
        $insert_format = array(
            '%s', // id
            '%s', // property_id
            '%f', // amount_paid
            '%s', // receipt_number
            '%s', // date_issued
            '%s', // place_issued
            '%s', // prepared_by
            '%s', // payment_type
            '%s', // purpose
            '%s', // purpose_details
            '%s', // client_name
            '%s', // client_address
            '%s', // contact_number
            '%s', // email
            '%s', // remarks
            '%d', // is_official_request
            '%s', // created_by
            '%s', // updated_by
            '%s', // created_at
            '%s', // updated_at
            '%s', // verifier_signatory_name
            '%s', // verifier_signatory_title
            '%s', // municipal_assessor_name
            '%s', // municipal_assessor_title
            '%s', // municipal_assessor_license
            '%s'  // municipal_assessor_suffix
        );
        
        $result = $this->db->insert($this->table_name, $insert_data, $insert_format);
        
        if ($result === false) {
            return new WP_Error('db_error', 'Failed to create request', array('status' => 500));
        }

        // Enqueue for sync to live site (only on local builds, and not when write came from sync)
        if (class_exists('Assessor_Sync')) {
            Assessor_Sync::enqueue_request($request_id, 'upsert');
        }
        
        return $this->get_request($request_id);
    }
    
    /**
     * Get a single request by ID
     */
    public function get_request($id, $include_deleted = false) {
        $id = sanitize_text_field($id);

        $table_users = $this->db->prefix . 'assessor_users';
        $has_assessor_users = $this->db->get_var("SHOW TABLES LIKE '$table_users'");

        $user_join = $has_assessor_users 
            ? "LEFT JOIN {$table_users} u1 ON r.created_by = u1.id
               LEFT JOIN {$table_users} u2 ON r.updated_by = u2.id"
            : "LEFT JOIN {$this->db->users} u1 ON r.created_by = u1.ID
               LEFT JOIN {$this->db->users} u2 ON r.updated_by = u2.ID";

        $user_select = $has_assessor_users
            ? "COALESCE(u1.full_name, u1.username) as created_by_name,
               COALESCE(u2.full_name, u2.username) as updated_by_name"
            : "u1.display_name as created_by_name,
               u2.display_name as updated_by_name";

        $deleted_clause = $include_deleted ? "" : "AND r.deleted_at IS NULL";

        $query = $this->db->prepare(
            "SELECT r.*, 
                    p.tax_declaration_number,
                    p.declarant_last_name,
                    p.declarant_first_name,
                    p.declarant_middle_initial,
                    p.business,
                    p.location,
                    p.assessed_value,
                    p.kind_of_property,
                    p.gen_class,
                    pt.name as kind_of_property_name,
                    gc.name as gen_class_name,
                    $user_select
             FROM {$this->table_name} r
             LEFT JOIN {$this->db->prefix}assessor_properties p ON r.property_id = p.id
             LEFT JOIN {$this->db->prefix}assessor_property_types pt ON p.kind_of_property = pt.code
             LEFT JOIN {$this->db->prefix}assessor_general_classes gc ON p.gen_class = gc.code
             $user_join
             WHERE r.id = %s $deleted_clause",
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
            'all' => false,
            'include_deleted' => false
        );
        
        $args = wp_parse_args($args, $defaults);
        
        // Handle 'all' parameter - if true, return all records without pagination
        $fetch_all = !empty($args['all']) && ($args['all'] === '1' || $args['all'] === 1 || $args['all'] === true);
        
        $where_conditions = array('1=1');
        $where_values = array();

        // Soft-delete exclusion by default
        if (empty($args['include_deleted'])) {
            $where_conditions[] = "r.deleted_at IS NULL";
        }
        
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
            $where_conditions[] = "r.property_id = %s";
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
        $count_query = "SELECT COUNT(*) FROM {$this->table_name} r LEFT JOIN {$this->db->prefix}assessor_properties p ON r.property_id = p.id WHERE {$where_clause}";
        if (!empty($where_values)) {
            $count_query = $this->db->prepare($count_query, $where_values);
        }
        $total = $this->db->get_var($count_query);
        
        // Build user joins
        $table_users = $this->db->prefix . 'assessor_users';
        $has_assessor_users = $this->db->get_var("SHOW TABLES LIKE '$table_users'");

        $user_join = $has_assessor_users 
            ? "LEFT JOIN {$table_users} u1 ON r.created_by = u1.id
               LEFT JOIN {$table_users} u2 ON r.updated_by = u2.id"
            : "LEFT JOIN {$this->db->users} u1 ON r.created_by = u1.ID
               LEFT JOIN {$this->db->users} u2 ON r.updated_by = u2.ID";

        $user_select = $has_assessor_users
            ? "COALESCE(u1.full_name, u1.username) as created_by_name,
               COALESCE(u2.full_name, u2.username) as updated_by_name"
            : "u1.display_name as created_by_name,
               u2.display_name as updated_by_name";

        // Build the main query
        $query = "SELECT r.*, 
                         p.tax_declaration_number,
                         p.declarant_last_name,
                         p.declarant_first_name,
                         p.declarant_middle_initial,
                         p.business,
                         p.location,
                         p.assessed_value,
                         p.kind_of_property,
                         p.gen_class,
                         pt.name as kind_of_property_name,
                         gc.name as gen_class_name,
                         $user_select
                  FROM {$this->table_name} r
                  LEFT JOIN {$this->db->prefix}assessor_properties p ON r.property_id = p.id
                  LEFT JOIN {$this->db->prefix}assessor_property_types pt ON p.kind_of_property = pt.code
                  LEFT JOIN {$this->db->prefix}assessor_general_classes gc ON p.gen_class = gc.code
                  $user_join
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
        
        $id = sanitize_text_field($id);

        // Check for duplicate receipt number if changed
        if (isset($data['receipt_number']) && $data['receipt_number'] !== $existing['receipt_number']) {
            $duplicate = $this->db->get_var($this->db->prepare(
                "SELECT id FROM {$this->table_name} WHERE receipt_number = %s AND id != %s",
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
            'property_id' => '%s',
            'amount_paid' => '%f',
            'receipt_number' => '%s',
            'date_issued' => '%s',
            'place_issued' => '%s',
            'prepared_by' => '%s',
            'payment_type' => '%s',
            'purpose' => '%s',
            'purpose_details' => '%s',
            'client_name' => '%s',
            'client_address' => '%s',
            'contact_number' => '%s',
            'email' => '%s',
            'remarks' => '%s',
            'verifier_signatory_name' => '%s',
            'verifier_signatory_title' => '%s',
            'municipal_assessor_name' => '%s',
            'municipal_assessor_title' => '%s',
            'municipal_assessor_license' => '%s',
            'municipal_assessor_suffix' => '%s'
        );
        
        foreach ($fields as $field => $format) {
            if (array_key_exists($field, $data)) {
                $val = $data[$field];
                if ($val === '' || $val === null) {
                    $update_data[$field] = null;
                } else if ($field === 'purpose_details') {
                    $update_data[$field] = sanitize_textarea_field($val);
                } else {
                    $update_data[$field] = $val;
                }
                $update_format[] = $format;
            }
        }
        
        // Add updated_by and updated_at
        $update_data['updated_by'] = $current_user_id;
        $update_data['updated_at'] = Assessor_Timezone::now_mysql();
        $update_format[] = '%s';
        $update_format[] = '%s';
        
        if (empty($update_data)) {
            return new WP_Error('no_data', 'No data provided for update', array('status' => 400));
        }
        
        $result = $this->db->update(
            $this->table_name,
            $update_data,
            array('id' => $id),
            $update_format,
            array('%s')
        );
        
        if ($result === false) {
            return new WP_Error('db_error', 'Failed to update request', array('status' => 500));
        }

        // Enqueue for sync to live site (only on local builds, and not when write came from sync)
        if (class_exists('Assessor_Sync')) {
            Assessor_Sync::enqueue_request($id, 'upsert');
        }
        
        return $this->get_request($id);
    }
    
    /**
     * Soft delete a request (sets deleted_at and updated_at, keeps row in DB)
     */
    public function delete_request($id) {
        $id = sanitize_text_field($id);

        // Check if request exists (only active ones can be deleted normally)
        $existing = $this->get_request($id);
        if (is_wp_error($existing)) {
            return $existing;
        }
        
        $now = Assessor_Timezone::now_mysql();
        $result = $this->db->update(
            $this->table_name,
            array(
                'deleted_at' => $now,
                'updated_at' => $now
            ),
            array('id' => $id),
            array('%s', '%s'),
            array('%s')
        );
        
        if ($result === false) {
            return new WP_Error('db_error', 'Failed to delete request', array('status' => 500));
        }

        // Enqueue for sync to live site (only on local builds, and not when write came from sync)
        if (class_exists('Assessor_Sync')) {
            Assessor_Sync::enqueue_request($id, 'delete');
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
            'summary_only' => false,
            'include_deleted' => false
        );
        
        $args = wp_parse_args($args, $defaults);
        $summary_only = !empty($args['summary_only']) && ($args['summary_only'] === true || $args['summary_only'] === '1' || $args['summary_only'] === 1);
        
        $where_conditions = array('1=1');
        $where_values = array();

        // Default: exclude soft-deleted records from statistics
        if (empty($args['include_deleted'])) {
            $where_conditions[] = "deleted_at IS NULL";
        }
        
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
        
        // This month / last month counts (based on created_at for consistency, excluding deleted)
        $now_dt = Assessor_Timezone::now();
        $curr_start = $now_dt->format('Y-m-01');
        $next_start = (clone $now_dt)->modify('+1 month')->format('Y-m-01');
        $prev_start = (clone $now_dt)->modify('-1 month')->format('Y-m-01');
        $this_month_count = (int)$this->db->get_var($this->db->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE deleted_at IS NULL AND created_at >= %s AND created_at < %s",
            $curr_start,
            $next_start
        ));
        $last_month_count = (int)$this->db->get_var($this->db->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE deleted_at IS NULL AND created_at >= %s AND created_at < %s",
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
