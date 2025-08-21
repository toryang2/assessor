<?php

class Assessor_Properties {
    
    public function get_properties($request) {
        global $wpdb;
        
        $params = $request->get_params();
        $page = isset($params['page']) ? max(1, intval($params['page'])) : 1;
        $per_page = isset($params['per_page']) ? min(100, max(1, intval($params['per_page']))) : 20;
        $offset = ($page - 1) * $per_page;
        
        $table_properties = $wpdb->prefix . 'assessor_properties';
        $table_versions = $wpdb->prefix . 'assessor_property_versions';
        $table_users = $wpdb->prefix . 'assessor_users';
        
        // Build WHERE clause for filtering
        $where_conditions = array();
        $where_values = array();
        
        // Unified free-text search across common fields
        if (!empty($params['q'])) {
            $q = '%' . $wpdb->esc_like($params['q']) . '%';
            $or_sql = array(
                "p.tax_declaration_number LIKE %s",
                "p.declarant_last_name LIKE %s",
                "p.declarant_first_name LIKE %s",
                "p.lot_number LIKE %s",
                "p.title_number LIKE %s",
                "p.business LIKE %s"
            );
            $or_vals = array_fill(0, count($or_sql), $q);

            // Also match numeric-only searches against TDN without hyphens/spaces (e.g., '12312' matches '22-010-0001-12312')
            $q_digits_raw = preg_replace('/[^0-9]/', '', $params['q']);
            if ($q_digits_raw !== '') {
                $or_sql[] = "REPLACE(REPLACE(p.tax_declaration_number, '-', ''), ' ', '') LIKE %s";
                $or_vals[] = '%' . $wpdb->esc_like($q_digits_raw) . '%';
            }

            $where_conditions[] = '(' . implode(' OR ', $or_sql) . ')';
            $where_values = array_merge($where_values, $or_vals);
        }

        if (!empty($params['tax_declaration_number'])) {
            $where_conditions[] = "p.tax_declaration_number LIKE %s";
            $where_values[] = '%' . $wpdb->esc_like($params['tax_declaration_number']) . '%';
        }
        
        if (!empty($params['declarant_last_name'])) {
            $where_conditions[] = "p.declarant_last_name LIKE %s";
            $where_values[] = '%' . $wpdb->esc_like($params['declarant_last_name']) . '%';
        }
        
        if (!empty($params['declarant_first_name'])) {
            $where_conditions[] = "p.declarant_first_name LIKE %s";
            $where_values[] = '%' . $wpdb->esc_like($params['declarant_first_name']) . '%';
        }
        
        if (!empty($params['location'])) {
            $where_conditions[] = "p.location LIKE %s";
            $where_values[] = '%' . $wpdb->esc_like($params['location']) . '%';
        }
        
        // Added support for lot_number and title_number in search
        if (!empty($params['lot_number'])) {
            $where_conditions[] = "p.lot_number LIKE %s";
            $where_values[] = '%' . $wpdb->esc_like($params['lot_number']) . '%';
        }
        if (!empty($params['title_number'])) {
            $where_conditions[] = "p.title_number LIKE %s";
            $where_values[] = '%' . $wpdb->esc_like($params['title_number']) . '%';
        }
        if (!empty($params['business'])) {
            $where_conditions[] = "p.business LIKE %s";
            $where_values[] = '%' . $wpdb->esc_like($params['business']) . '%';
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
                   u.full_name as updated_by_name,
                   p.business as business_name
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
                   u.full_name as updated_by_name,
                   p.business as business_name
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
        $required_fields = array('tax_declaration_number', 'location', 'kind_of_property');
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
                'previous_tax_declaration_number' => sanitize_text_field($params['previous_tax_declaration_number']),
                'declarant_last_name' => sanitize_text_field($params['declarant_last_name']),
                'declarant_first_name' => sanitize_text_field($params['declarant_first_name']),
                'declarant_middle_initial' => sanitize_text_field($params['declarant_middle_initial']),
                'business' => sanitize_text_field($params['business_name']),
                'location' => sanitize_textarea_field($params['location']),
                'lot_number' => sanitize_text_field($params['lot_number']),
                'unique_lot_number_identified' => sanitize_text_field($params['unique_lot_number_identified']),
                'area_hectare' => floatval($params['area_hectare']),
                'title_number' => sanitize_text_field($params['title_number']),
                'assessed_value' => floatval($params['assessed_value']),
                'effectivity_date' => $params['effectivity_date'],
                'pin' => sanitize_text_field($params['pin']),
                'address' => sanitize_textarea_field($params['address']),
                'assessment_date' => $params['assessment_date'],
                'kind_of_property' => sanitize_text_field($params['kind_of_property']),
                'gen_class' => sanitize_text_field($params['gen_class']),
                'memoranda' => sanitize_textarea_field($params['memoranda']),
                'supporting_documents' => sanitize_textarea_field($params['supporting_documents']),
                'verifier_signatory_name' => sanitize_text_field($params['verifier_signatory_name']),
                'verifier_signatory_title' => sanitize_text_field($params['verifier_signatory_title']),
                'municipal_assessor_name' => sanitize_text_field($params['municipal_assessor_name']),
                'municipal_assessor_suffix' => sanitize_text_field($params['municipal_assessor_suffix']),
                'municipal_assessor_title' => sanitize_text_field($params['municipal_assessor_title']),
                'municipal_assessor_license' => sanitize_text_field($params['municipal_assessor_license']),
                'status' => 'active',
                'created_by' => $user_id,
                'updated_by' => $user_id
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d')
        );
        
        if ($result === false) {
            return new WP_Error('insert_failed', 'Failed to create property', array('status' => 500));
        }
        
        $property_id = $wpdb->insert_id;
        
        // Insert business name if provided
        // Business is stored on properties table; no separate insert needed

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
        
        // If tax_declaration_number is being changed, enforce uniqueness
        if (isset($params['tax_declaration_number'])) {
            $new_tax_number = sanitize_text_field($params['tax_declaration_number']);
            $duplicate_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table_properties WHERE tax_declaration_number = %s AND id != %d",
                $new_tax_number,
                $id
            ));
            if ($duplicate_id) {
                return new WP_Error('duplicate_tax_number', 'Tax declaration number already exists', array('status' => 400));
            }
        }
        $update_data = array(
            'updated_by' => $user_id,
            'updated_at' => current_time('mysql')
        );
        
        $allowed_fields = array(
            'tax_declaration_number', 'previous_tax_declaration_number', 'declarant_last_name', 'declarant_first_name', 
            'declarant_middle_initial', 'business', 'location', 'lot_number', 'unique_lot_number_identified',
            'area_hectare', 'title_number', 'assessed_value', 'effectivity_date', 'pin', 
            'address', 'assessment_date', 'kind_of_property', 'gen_class', 'memoranda', 
            'supporting_documents', 'verifier_signatory_name', 'verifier_signatory_title', 'municipal_assessor_name',
            'municipal_assessor_suffix', 'municipal_assessor_title', 'municipal_assessor_license', 'status'
        );
        
        foreach ($allowed_fields as $field) {
            if ($field === 'business') {
                if (isset($params['business_name'])) {
                    $update_data['business'] = sanitize_text_field($params['business_name']);
                }
                continue;
            }
            if (isset($params[$field])) {
                if (in_array($field, array('area_hectare', 'assessed_value'))) {
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
        
        // Business is stored on properties table directly
        
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
    
    public function get_tax_declaration_history($tax_declaration_number) {
        global $wpdb;
        
        $table_properties = $wpdb->prefix . 'assessor_properties';
        $history = array();

        // 1) Resolve to the latest (head) tax declaration in the chain starting from the given number
        $head_number = $tax_declaration_number;
        $visited_forward = array();
        while ($head_number && !in_array($head_number, $visited_forward, true)) {
            $visited_forward[] = $head_number;
            $next_number = $wpdb->get_var($wpdb->prepare(
                "SELECT tax_declaration_number 
                 FROM $table_properties 
                 WHERE previous_tax_declaration_number = %s AND status != 'deleted' 
                 ORDER BY created_at DESC 
                 LIMIT 1",
                $head_number
            ));
            if (!$next_number) {
                break;
            }
            $head_number = $next_number;
        }

        // 2) Build the chain backwards starting from the head (latest) down to the oldest
        $current_number = $head_number ?: $tax_declaration_number;
        $visited_backward = array();
        while ($current_number && !in_array($current_number, $visited_backward, true)) {
            $visited_backward[] = $current_number;

            $property = $wpdb->get_row($wpdb->prepare(
                "SELECT id, tax_declaration_number, previous_tax_declaration_number, 
                        declarant_last_name, declarant_first_name, declarant_middle_initial,
                        business,
                        location, lot_number, area_hectare, title_number, effectivity_date,
                        assessed_value, kind_of_property, memoranda, pin, address, assessment_date, gen_class, created_at,
                        verifier_signatory_name, verifier_signatory_title,
                        municipal_assessor_name, municipal_assessor_suffix, municipal_assessor_title, municipal_assessor_license
                 FROM $table_properties 
                 WHERE tax_declaration_number = %s AND status != 'deleted'
                 ORDER BY created_at DESC
                 LIMIT 1",
                $current_number
            ));
            
            if (!$property) {
                break;
            }
            
            // Fallback: if memoranda is empty on the live record, try latest version memoranda
            $memoranda_value = $property->memoranda;
            if (empty($memoranda_value)) {
                $memoranda_value = $wpdb->get_var($wpdb->prepare(
                    "SELECT memoranda FROM $table_versions WHERE property_id = %d ORDER BY version_number DESC LIMIT 1",
                    $property->id
                ));
            }

            // Business is now stored directly on properties table
            $business_name = $property->business;

            $history[] = array(
                'id' => $property->id,
                'tax_declaration_number' => $property->tax_declaration_number,
                'previous_tax_declaration_number' => $property->previous_tax_declaration_number,
                'declarant_name' => trim($property->declarant_last_name . ', ' . $property->declarant_first_name . 
                                       ($property->declarant_middle_initial ? ' ' . $property->declarant_middle_initial . '.' : '')),
                'business_name' => $business_name,
                'location' => $property->location,
                'lot_number' => $property->lot_number,
                'area_hectare' => $property->area_hectare,
                'title_number' => $property->title_number,
                'effectivity_date' => $property->effectivity_date,
                'assessed_value' => $property->assessed_value,
                'kind_of_property' => $property->kind_of_property,
                'memoranda' => $memoranda_value,
                'pin' => $property->pin,
                'address' => $property->address,
                'assessment_date' => $property->assessment_date,
                'gen_class' => $property->gen_class,
                'verifier_signatory_name' => $property->verifier_signatory_name,
                'verifier_signatory_title' => $property->verifier_signatory_title,
                'municipal_assessor_name' => $property->municipal_assessor_name,
                'municipal_assessor_suffix' => $property->municipal_assessor_suffix,
                'municipal_assessor_title' => $property->municipal_assessor_title,
                'municipal_assessor_license' => $property->municipal_assessor_license,
                'created_at' => $property->created_at
            );
            
            // Move to the previous declaration number
            $current_number = $property->previous_tax_declaration_number;
        }
        
        return $history;
    }
    
    public function get_property_by_tax_number($tax_declaration_number) {
        global $wpdb;
        
        $table_properties = $wpdb->prefix . 'assessor_properties';
        $table_users = $wpdb->prefix . 'assessor_users';
        
        $query = "
            SELECT p.*, 
                   c.full_name as created_by_name,
                   u.full_name as updated_by_name,
                   p.business as business_name
            FROM $table_properties p
            LEFT JOIN $table_users c ON p.created_by = c.id
            LEFT JOIN $table_users u ON p.updated_by = u.id
            WHERE p.tax_declaration_number = %s AND p.status != 'deleted'
            ORDER BY p.created_at DESC
            LIMIT 1
        ";
        
        return $wpdb->get_row($wpdb->prepare($query, $tax_declaration_number));
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
                'previous_tax_declaration_number' => $property_data->previous_tax_declaration_number,
                'declarant_last_name' => $property_data->declarant_last_name,
                'declarant_first_name' => $property_data->declarant_first_name,
                'declarant_middle_initial' => $property_data->declarant_middle_initial,
                'location' => $property_data->location,
                'lot_number' => $property_data->lot_number,
                'unique_lot_number_identified' => $property_data->unique_lot_number_identified,
                'area_hectare' => $property_data->area_hectare,
                'title_number' => $property_data->title_number,
                'assessed_value' => $property_data->assessed_value,
                'effectivity_date' => $property_data->effectivity_date,
                'pin' => $property_data->pin,
                'address' => $property_data->address,
                'assessment_date' => $property_data->assessment_date,
                'kind_of_property' => $property_data->kind_of_property,
                'gen_class' => $property_data->gen_class,
                'memoranda' => $property_data->memoranda,
                'supporting_documents' => $property_data->supporting_documents,
                'verifier_signatory_name' => isset($property_data->verifier_signatory_name) ? $property_data->verifier_signatory_name : '',
                'verifier_signatory_title' => isset($property_data->verifier_signatory_title) ? $property_data->verifier_signatory_title : '',
                'municipal_assessor_name' => isset($property_data->municipal_assessor_name) ? $property_data->municipal_assessor_name : '',
                'municipal_assessor_suffix' => isset($property_data->municipal_assessor_suffix) ? $property_data->municipal_assessor_suffix : '',
                'municipal_assessor_title' => isset($property_data->municipal_assessor_title) ? $property_data->municipal_assessor_title : '',
                'municipal_assessor_license' => isset($property_data->municipal_assessor_license) ? $property_data->municipal_assessor_license : '',
                'change_reason' => $change_reason,
                'created_by' => $property_data->updated_by
            ),
            array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d')
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

