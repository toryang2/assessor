<?php

class Assessor_Properties {
    
    public function get_properties($request) {
        global $wpdb;
        
        $params = $request->get_params();
        $page = isset($params['page']) ? max(1, intval($params['page'])) : 1;
        $per_page = isset($params['per_page']) ? min(100, max(1, intval($params['per_page']))) : 20;
        $offset = ($page - 1) * $per_page;
        
        $table_properties = $wpdb->prefix . 'assessor_properties';
        $table_users = $wpdb->prefix . 'assessor_users';
        
        // Build WHERE clause for filtering
        $where_conditions = array();
        $where_values = array();
        
        if (!empty($params['tax_declaration_number'])) {
            $where_conditions[] = "p.tax_declaration_number LIKE %s";
            $where_values[] = '%' . $wpdb->esc_like($params['tax_declaration_number']) . '%';
        }
        
        if (!empty($params['owner_name'])) {
            $where_conditions[] = "p.owner_name LIKE %s";
            $where_values[] = '%' . $wpdb->esc_like($params['owner_name']) . '%';
        }
        
        if (!empty($params['property_location'])) {
            $where_conditions[] = "p.property_location LIKE %s";
            $where_values[] = '%' . $wpdb->esc_like($params['property_location']) . '%';
        }
        
        if (!empty($params['status'])) {
            $where_conditions[] = "p.status = %s";
            $where_values[] = $params['status'];
        }
        
        if (!empty($params['date_from'])) {
            $where_conditions[] = "p.created_at >= %s";
            $where_values[] = $params['date_from'];
        }
        
        if (!empty($params['date_to'])) {
            $where_conditions[] = "p.created_at <= %s";
            $where_values[] = $params['date_to'];
        }
        
        $where_clause = '';
        if (!empty($where_conditions)) {
            $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        }
        
        // Build ORDER BY clause
        $order_by = 'p.created_at DESC';
        if (!empty($params['order_by'])) {
            $allowed_fields = array('tax_declaration_number', 'owner_name', 'property_location', 'assessed_value', 'created_at');
            if (in_array($params['order_by'], $allowed_fields)) {
                $order_direction = (!empty($params['order_direction']) && strtoupper($params['order_direction']) === 'ASC') ? 'ASC' : 'DESC';
                $order_by = 'p.' . $params['order_by'] . ' ' . $order_direction;
            }
        }
        
        // Get total count
        $count_query = "SELECT COUNT(*) FROM $table_properties p $where_clause";
        if (!empty($where_values)) {
            $count_query = $wpdb->prepare($count_query, $where_values);
        }
        $total = $wpdb->get_var($count_query);
        
        // Get properties with user information
        $query = "
            SELECT p.*, 
                   c.full_name as created_by_name,
                   u.full_name as updated_by_name
            FROM $table_properties p
            LEFT JOIN $table_users c ON p.created_by = c.id
            LEFT JOIN $table_users u ON p.updated_by = u.id
            $where_clause
            ORDER BY $order_by
            LIMIT %d OFFSET %d
        ";
        
        $query_values = array_merge($where_values, array($per_page, $offset));
        $properties = $wpdb->get_results($wpdb->prepare($query, $query_values));
        
        return array(
            'properties' => $properties,
            'pagination' => array(
                'page' => $page,
                'per_page' => $per_page,
                'total' => intval($total),
                'total_pages' => ceil($total / $per_page)
            )
        );
    }
    
    public function get_property($id) {
        global $wpdb;
        
        $table_properties = $wpdb->prefix . 'assessor_properties';
        $table_users = $wpdb->prefix . 'assessor_users';
        
        $query = "
            SELECT p.*, 
                   c.full_name as created_by_name,
                   u.full_name as updated_by_name
            FROM $table_properties p
            LEFT JOIN $table_users c ON p.created_by = c.id
            LEFT JOIN $table_users u ON p.updated_by = u.id
            WHERE p.id = %d
        ";
        
        $property = $wpdb->get_row($wpdb->prepare($query, $id));
        
        if (!$property) {
            return new WP_Error('property_not_found', 'Property not found', array('status' => 404));
        }
        
        return $property;
    }
    
    public function create_property($request) {
        global $wpdb;
        
        $params = $request->get_params();
        $user_id = $this->get_user_id_from_request($request);
        
        // Validate required fields
        $required_fields = array('tax_declaration_number', 'owner_name', 'property_location', 'property_type');
        foreach ($required_fields as $field) {
            if (empty($params[$field])) {
                return new WP_Error('missing_field', "Field '$field' is required", array('status' => 400));
            }
        }
        
        // Check if tax declaration number already exists
        $table_properties = $wpdb->prefix . 'assessor_properties';
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_properties WHERE tax_declaration_number = %s",
            $params['tax_declaration_number']
        ));
        
        if ($existing) {
            return new WP_Error('duplicate_tax_number', 'Tax declaration number already exists', array('status' => 400));
        }
        
        // Insert property
        $result = $wpdb->insert(
            $table_properties,
            array(
                'tax_declaration_number' => sanitize_text_field($params['tax_declaration_number']),
                'owner_name' => sanitize_text_field($params['owner_name']),
                'owner_address' => sanitize_textarea_field($params['owner_address']),
                'property_location' => sanitize_textarea_field($params['property_location']),
                'property_type' => sanitize_text_field($params['property_type']),
                'land_area' => floatval($params['land_area']),
                'building_area' => floatval($params['building_area']),
                'assessed_value' => floatval($params['assessed_value']),
                'market_value' => floatval($params['market_value']),
                'status' => 'active',
                'created_by' => $user_id,
                'updated_by' => $user_id
            ),
            array('%s', '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%f', '%s', '%d', '%d')
        );
        
        if ($result === false) {
            return new WP_Error('insert_failed', 'Failed to create property', array('status' => 500));
        }
        
        $property_id = $wpdb->insert_id;
        
        // Log audit trail
        $this->log_audit($user_id, 'create', 'assessor_properties', $property_id);
        
        return $this->get_property($property_id);
    }
    
    public function update_property($id, $request) {
        global $wpdb;
        
        $params = $request->get_params();
        $user_id = $this->get_user_id_from_request($request);
        
        // Get current property data for versioning
        $current_property = $this->get_property($id);
        if (is_wp_error($current_property)) {
            return $current_property;
        }
        
        // Create version before updating
        $this->create_property_version($id, $current_property, $params['change_reason'] ?? 'Property updated');
        
        // Update property
        $table_properties = $wpdb->prefix . 'assessor_properties';
        $update_data = array(
            'updated_by' => $user_id,
            'updated_at' => current_time('mysql')
        );
        
        $allowed_fields = array('owner_name', 'owner_address', 'property_location', 'property_type', 
                               'land_area', 'building_area', 'assessed_value', 'market_value', 'status');
        
        foreach ($allowed_fields as $field) {
            if (isset($params[$field])) {
                if (in_array($field, array('land_area', 'building_area', 'assessed_value', 'market_value'))) {
                    $update_data[$field] = floatval($params[$field]);
                } else {
                    $update_data[$field] = sanitize_text_field($params[$field]);
                }
            }
        }
        
        $result = $wpdb->update(
            $table_properties,
            $update_data,
            array('id' => $id),
            null,
            array('%d')
        );
        
        if ($result === false) {
            return new WP_Error('update_failed', 'Failed to update property', array('status' => 500));
        }
        
        // Log audit trail
        $this->log_audit($user_id, 'update', 'assessor_properties', $id);
        
        return $this->get_property($id);
    }
    
    public function delete_property($id) {
        global $wpdb;
        
        $user_id = $this->get_user_id_from_request($request);
        
        // Check if property exists
        $property = $this->get_property($id);
        if (is_wp_error($property)) {
            return $property;
        }
        
        // Soft delete by updating status
        $table_properties = $wpdb->prefix . 'assessor_properties';
        $result = $wpdb->update(
            $table_properties,
            array('status' => 'deleted', 'updated_by' => $user_id, 'updated_at' => current_time('mysql')),
            array('id' => $id),
            array('%s', '%d', '%s'),
            array('%d')
        );
        
        if ($result === false) {
            return new WP_Error('delete_failed', 'Failed to delete property', array('status' => 500));
        }
        
        // Log audit trail
        $this->log_audit($user_id, 'delete', 'assessor_properties', $id);
        
        return array('success' => true, 'message' => 'Property deleted successfully');
    }
    
    public function get_total_count() {
        global $wpdb;
        $table_properties = $wpdb->prefix . 'assessor_properties';
        return $wpdb->get_var("SELECT COUNT(*) FROM $table_properties WHERE status != 'deleted'");
    }
    
    public function get_version_counts() {
        global $wpdb;
        $table_versions = $wpdb->prefix . 'assessor_property_versions';
        return $wpdb->get_var("SELECT COUNT(*) FROM $table_versions");
    }
    
    private function create_property_version($property_id, $property_data, $change_reason) {
        global $wpdb;
        
        $table_versions = $wpdb->prefix . 'assessor_property_versions';
        
        // Get next version number
        $current_version = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(version_number) FROM $table_versions WHERE property_id = %d",
            $property_id
        ));
        $next_version = ($current_version ? $current_version + 1 : 1);
        
        $wpdb->insert(
            $table_versions,
            array(
                'property_id' => $property_id,
                'version_number' => $next_version,
                'tax_declaration_number' => $property_data->tax_declaration_number,
                'owner_name' => $property_data->owner_name,
                'owner_address' => $property_data->owner_address,
                'property_location' => $property_data->property_location,
                'property_type' => $property_data->property_type,
                'land_area' => $property_data->land_area,
                'building_area' => $property_data->building_area,
                'assessed_value' => $property_data->assessed_value,
                'market_value' => $property_data->market_value,
                'change_reason' => $change_reason,
                'created_by' => $property_data->updated_by
            ),
            array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%f', '%s', '%d')
        );
    }
    
    private function get_user_id_from_request($request) {
        $auth = new Assessor_Auth();
        return $auth->get_user_id_from_token($request);
    }
    
    private function log_audit($user_id, $action, $table_name, $record_id) {
        $audit = new Assessor_Audit();
        $audit->log_activity($user_id, $action, $table_name, $record_id);
    }
}

