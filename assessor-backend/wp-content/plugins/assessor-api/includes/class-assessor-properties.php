<?php

class Assessor_Properties {
    
    public function get_properties($request) {
        global $wpdb;
        
        // Add cache control headers to prevent caching
        header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        
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
        
        // Always exclude deleted properties
        $where_conditions[] = "p.status != 'deleted'";
        
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
            WHERE p.id = %d AND p.status != 'deleted'
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
            "SELECT id FROM $table_properties WHERE tax_declaration_number = %s AND status != 'deleted'",
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
                'area_hectare' => isset($params['area_hectare']) && $params['area_hectare'] !== '' ? floatval($params['area_hectare']) : null,
                'area_hectare_old' => sanitize_text_field($params['area_hectare_old'] ?? ''),
                'area_sqm' => isset($params['area_sqm']) && $params['area_sqm'] !== '' ? floatval($params['area_sqm']) : null,
                'title_number' => sanitize_text_field($params['title_number']),
                'assessed_value' => floatval($params['assessed_value']),
                'assessed_value_old' => sanitize_text_field($params['assessed_value_old']),
                'effectivity_date' => $params['effectivity_date'],
                'pin' => sanitize_text_field($params['pin']),
                'address' => sanitize_textarea_field($params['address']),
                'assessment_date' => $params['assessment_date'],
                'kind_of_property' => sanitize_text_field($params['kind_of_property']),
                'gen_class' => sanitize_text_field($params['gen_class']),
                'memoranda' => sanitize_textarea_field($params['memoranda']),
                'supporting_documents' => sanitize_textarea_field($params['supporting_documents']),
                'supporting_documents_old' => sanitize_textarea_field($params['supporting_documents_old']),
                'supporting_documents_old' => sanitize_textarea_field($params['supporting_documents_old']),
                'verifier_signatory_name' => sanitize_text_field($params['verifier_signatory_name']),
                'verifier_signatory_title' => sanitize_text_field($params['verifier_signatory_title']),
                'municipal_assessor_name' => sanitize_text_field($params['municipal_assessor_name']),
                'municipal_assessor_suffix' => sanitize_text_field($params['municipal_assessor_suffix']),
                'municipal_assessor_title' => sanitize_text_field($params['municipal_assessor_title']),
                'municipal_assessor_license' => sanitize_text_field($params['municipal_assessor_license']),
                'status' => 'active',
                'created_by' => $user_id,
                'updated_by' => $user_id,
                'created_at' => isset($params['created_at']) ? $params['created_at'] : date('Y-m-d H:i:s'),
                'updated_at' => isset($params['updated_at']) ? $params['updated_at'] : date('Y-m-d H:i:s')
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%f', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s')
        );
        
        if ($result === false) {
            return new WP_Error('insert_failed', 'Failed to create property', array('status' => 500));
        }
        
        $property_id = $wpdb->insert_id;
        
        // Insert business name if provided
        // Business is stored on properties table; no separate insert needed

        // Log audit trail with full snapshot of newly created property
        $created = $this->get_property($property_id);
        $new_values = array();
        if ($created && !is_wp_error($created)) {
            $new_values = array(
                'id' => $created->id,
                'tax_declaration_number' => $created->tax_declaration_number,
                'previous_tax_declaration_number' => $created->previous_tax_declaration_number,
                'declarant_last_name' => $created->declarant_last_name,
                'declarant_first_name' => $created->declarant_first_name,
                'declarant_middle_initial' => $created->declarant_middle_initial,
                'business' => isset($created->business) ? $created->business : (isset($created->business_name) ? $created->business_name : ''),
                'location' => $created->location,
                'lot_number' => $created->lot_number,
                'unique_lot_number_identified' => $created->unique_lot_number_identified,
                'area_hectare' => $created->area_hectare,
                'area_hectare_old' => $created->area_hectare_old,
                'area_sqm' => isset($created->area_sqm) ? $created->area_sqm : null,
                'title_number' => $created->title_number,
                'assessed_value' => $created->assessed_value,
                'assessed_value_old' => isset($created->assessed_value_old) ? $created->assessed_value_old : '',
                'effectivity_date' => $created->effectivity_date,
                'pin' => $created->pin,
                'address' => $created->address,
                'assessment_date' => $created->assessment_date,
                'kind_of_property' => $created->kind_of_property,
                'gen_class' => $created->gen_class,
                'memoranda' => $created->memoranda,
                'supporting_documents' => $created->supporting_documents,
                'supporting_documents_old' => isset($created->supporting_documents_old) ? $created->supporting_documents_old : '',
                'verifier_signatory_name' => isset($created->verifier_signatory_name) ? $created->verifier_signatory_name : '',
                'verifier_signatory_title' => isset($created->verifier_signatory_title) ? $created->verifier_signatory_title : '',
                'municipal_assessor_name' => isset($created->municipal_assessor_name) ? $created->municipal_assessor_name : '',
                'municipal_assessor_suffix' => isset($created->municipal_assessor_suffix) ? $created->municipal_assessor_suffix : '',
                'municipal_assessor_title' => isset($created->municipal_assessor_title) ? $created->municipal_assessor_title : '',
                'municipal_assessor_license' => isset($created->municipal_assessor_license) ? $created->municipal_assessor_license : '',
                'created_at' => $created->created_at,
                'updated_at' => isset($created->updated_at) ? $created->updated_at : null,
                'created_by' => isset($created->created_by) ? $created->created_by : null,
                'updated_by' => isset($created->updated_by) ? $created->updated_by : null
            );
        }
        $audit = new Assessor_Audit();
        $audit->log_activity($user_id, 'create', 'assessor_properties', $property_id, null, $new_values);
        
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
        
        // Compute changes for audit before update
        $fields_to_track = array(
            'tax_declaration_number',
            'previous_tax_declaration_number',
            'declarant_last_name',
            'declarant_first_name',
            'declarant_middle_initial',
            'business',
            'location',
            'lot_number',
            'unique_lot_number_identified',
            'area_hectare',
            'area_hectare_old',
            'area_sqm',
            'title_number',
            'assessed_value',
            'effectivity_date',
            'pin',
            'address',
            'assessment_date',
            'kind_of_property',
            'gen_class',
            'memoranda',
            'supporting_documents',
            'supporting_documents_old'
        );
        $old_values = array();
        $new_values = array();
        foreach ($fields_to_track as $field) {
            if (!array_key_exists($field, $params)) {
                continue;
            }
            $new_raw = $params[$field];
            $old_raw = isset($current_property->$field) ? $current_property->$field : null;

            // Normalize values by field type to avoid logging formatting-only changes
            $is_numeric_4 = ($field === 'area_hectare' || $field === 'area_sqm');
            $is_numeric_2 = ($field === 'assessed_value');

            if ($is_numeric_4 || $is_numeric_2) {
                $precision = $is_numeric_4 ? 4 : 2;
                $old_num = is_null($old_raw) || $old_raw === '' ? null : floatval($old_raw);
                $new_num = $new_raw === '' || is_null($new_raw) ? null : floatval($new_raw);

                // If both null/empty, no change
                if ($old_num === null && $new_num === null) {
                    continue;
                }
                // Compare rounded numeric values
                $old_round = is_null($old_num) ? null : round($old_num, $precision);
                $new_round = is_null($new_num) ? null : round($new_num, $precision);
                if ($old_round === $new_round) {
                    continue; // no effective change
                }
                // Store formatted values for readability
                $old_values[$field] = is_null($old_round) ? null : number_format($old_round, $precision, '.', '');
                $new_values[$field] = is_null($new_round) ? null : number_format($new_round, $precision, '.', '');
                continue;
            }

            // String-like fields: normalize whitespace and case similar to UI
            $normalize_string = function($v) {
                if ($v === null) return null;
                $s = trim((string)$v);
                return $s;
            };
            $old_norm = $normalize_string($old_raw);
            $new_norm = $normalize_string($new_raw);
            if ($old_norm !== $new_norm) {
                $old_values[$field] = $old_norm;
                $new_values[$field] = $new_norm;
            }
        }

        // Update property
        $table_properties = $wpdb->prefix . 'assessor_properties';
        
        // If tax_declaration_number is being changed, enforce uniqueness
        if (isset($params['tax_declaration_number'])) {
            $new_tax_number = sanitize_text_field($params['tax_declaration_number']);
            $duplicate_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table_properties WHERE tax_declaration_number = %s AND id != %d AND status != 'deleted'",
                $new_tax_number,
                $id
            ));
            if ($duplicate_id) {
                return new WP_Error('duplicate_tax_number', 'Tax declaration number already exists', array('status' => 400));
            }
        }
        $update_data = array(
            'updated_by' => $user_id,
            'updated_at' => isset($params['updated_at']) ? $params['updated_at'] : date('Y-m-d H:i:s')
        );
        
        $allowed_fields = array(
            'tax_declaration_number', 'previous_tax_declaration_number', 'declarant_last_name', 'declarant_first_name', 
            'declarant_middle_initial', 'business', 'location', 'lot_number', 'unique_lot_number_identified',
            'area_hectare', 'area_hectare_old', 'area_sqm', 'title_number', 'assessed_value', 'assessed_value_old', 'effectivity_date', 'pin', 
            'address', 'assessment_date', 'kind_of_property', 'gen_class', 'memoranda', 
            'supporting_documents', 'supporting_documents_old', 'verifier_signatory_name', 'verifier_signatory_title', 'municipal_assessor_name',
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
                if ($field === 'area_hectare_old') {
                    // Allow explicit nulling when cleared on edit
                    if ($params[$field] === '' || is_null($params[$field])) {
                        $update_data[$field] = null;
                    } else {
                        $update_data[$field] = sanitize_text_field($params[$field]);
                    }
                } else if (in_array($field, array('area_hectare', 'area_sqm', 'assessed_value'))) {
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
        
        // Log audit trail with captured changes (if any)
        $audit = new Assessor_Audit();
        $audit->log_activity($user_id, 'update', 'assessor_properties', $id, !empty($old_values) ? $old_values : null, !empty($new_values) ? $new_values : null);
        
        return $this->get_property($id);
    }
    
    public function delete_property($id, $request) {
        global $wpdb;
        
        $user_id = $this->get_user_id_from_request($request);
        
        // Check if property exists
        $property = $this->get_property($id);
        if (is_wp_error($property)) {
            return $property;
        }
        
        // Hard delete the property record. Related records (versions/documents)
        // are configured with ON DELETE CASCADE via foreign keys.
        $table_properties = $wpdb->prefix . 'assessor_properties';
        $result = $wpdb->delete(
            $table_properties,
            array('id' => $id),
            array('%d')
        );
        
        if ($result === false) {
            return new WP_Error('delete_failed', 'Failed to delete property', array('status' => 500));
        }
        
        // Log audit trail with full snapshot of property values for complete history
        $old_values = array(
            'id' => $property->id,
            'tax_declaration_number' => $property->tax_declaration_number,
            'previous_tax_declaration_number' => $property->previous_tax_declaration_number,
            'declarant_last_name' => $property->declarant_last_name,
            'declarant_first_name' => $property->declarant_first_name,
            'declarant_middle_initial' => $property->declarant_middle_initial,
            'business' => isset($property->business) ? $property->business : (isset($property->business_name) ? $property->business_name : ''),
            'location' => $property->location,
            'lot_number' => $property->lot_number,
            'unique_lot_number_identified' => $property->unique_lot_number_identified,
            'area_hectare' => $property->area_hectare,
            'area_hectare_old' => $property->area_hectare_old,
            'title_number' => $property->title_number,
            'assessed_value' => $property->assessed_value,
            'assessed_value_old' => isset($property->assessed_value_old) ? $property->assessed_value_old : '',
            'effectivity_date' => $property->effectivity_date,
            'pin' => $property->pin,
            'address' => $property->address,
            'assessment_date' => $property->assessment_date,
            'kind_of_property' => $property->kind_of_property,
            'gen_class' => $property->gen_class,
            'memoranda' => $property->memoranda,
            'supporting_documents' => $property->supporting_documents,
            'supporting_documents_old' => isset($property->supporting_documents_old) ? $property->supporting_documents_old : '',
            'verifier_signatory_name' => isset($property->verifier_signatory_name) ? $property->verifier_signatory_name : '',
            'verifier_signatory_title' => isset($property->verifier_signatory_title) ? $property->verifier_signatory_title : '',
            'municipal_assessor_name' => isset($property->municipal_assessor_name) ? $property->municipal_assessor_name : '',
            'municipal_assessor_suffix' => isset($property->municipal_assessor_suffix) ? $property->municipal_assessor_suffix : '',
            'municipal_assessor_title' => isset($property->municipal_assessor_title) ? $property->municipal_assessor_title : '',
            'municipal_assessor_license' => isset($property->municipal_assessor_license) ? $property->municipal_assessor_license : '',
            'created_at' => $property->created_at,
            'updated_at' => isset($property->updated_at) ? $property->updated_at : null,
            'created_by' => isset($property->created_by) ? $property->created_by : null,
            'updated_by' => isset($property->updated_by) ? $property->updated_by : null
        );
        $audit = new Assessor_Audit();
        $audit->log_activity($user_id, 'delete', 'assessor_properties', $id, $old_values, null);
        
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
        $table_users = $wpdb->prefix . 'assessor_users';
        $table_versions = $wpdb->prefix . 'assessor_property_versions';
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
                "SELECT 
                        p.id, p.tax_declaration_number, p.previous_tax_declaration_number, 
                        p.declarant_last_name, p.declarant_first_name, p.declarant_middle_initial,
                        p.business,
                        p.location, p.lot_number, p.area_hectare, p.area_hectare_old, p.area_sqm, p.title_number, p.effectivity_date,
                        p.assessed_value, p.assessed_value_old, p.kind_of_property, p.memoranda, p.supporting_documents, p.supporting_documents_old, p.pin, p.address, p.assessment_date, p.gen_class, p.created_at,
                        p.verifier_signatory_name, p.verifier_signatory_title,
                        p.municipal_assessor_name, p.municipal_assessor_suffix, p.municipal_assessor_title, p.municipal_assessor_license,
                        c.full_name AS created_by_name
                 FROM $table_properties p
                 LEFT JOIN $table_users c ON p.created_by = c.id
                 WHERE p.tax_declaration_number = %s AND p.status != 'deleted' 
                 ORDER BY p.created_at DESC 
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
                'area_hectare_old' => $property->area_hectare_old,
                'area_sqm' => isset($property->area_sqm) ? $property->area_sqm : null,
                'title_number' => $property->title_number,
                'effectivity_date' => $property->effectivity_date,
                'assessed_value' => $property->assessed_value,
                'assessed_value_old' => isset($property->assessed_value_old) ? $property->assessed_value_old : '',
                'kind_of_property' => $property->kind_of_property,
                'memoranda' => $memoranda_value,
                'supporting_documents' => isset($property->supporting_documents) ? $property->supporting_documents : '',
                'supporting_documents_old' => isset($property->supporting_documents_old) ? $property->supporting_documents_old : '',
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
                'created_at' => $property->created_at,
                'created_by_name' => $property->created_by_name
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
                'area_hectare_old' => $property_data->area_hectare_old,
                'area_sqm' => isset($property_data->area_sqm) ? $property_data->area_sqm : null,
                'title_number' => $property_data->title_number,
                'assessed_value' => $property_data->assessed_value,
                'assessed_value_old' => isset($property_data->assessed_value_old) ? $property_data->assessed_value_old : '',
                'effectivity_date' => $property_data->effectivity_date,
                'pin' => $property_data->pin,
                'address' => $property_data->address,
                'assessment_date' => $property_data->assessment_date,
                'kind_of_property' => $property_data->kind_of_property,
                'gen_class' => $property_data->gen_class,
                'memoranda' => $property_data->memoranda,
                'supporting_documents' => $property_data->supporting_documents,
                'supporting_documents_old' => isset($property_data->supporting_documents_old) ? $property_data->supporting_documents_old : '',
                'verifier_signatory_name' => isset($property_data->verifier_signatory_name) ? $property_data->verifier_signatory_name : '',
                'verifier_signatory_title' => isset($property_data->verifier_signatory_title) ? $property_data->verifier_signatory_title : '',
                'municipal_assessor_name' => isset($property_data->municipal_assessor_name) ? $property_data->municipal_assessor_name : '',
                'municipal_assessor_suffix' => isset($property_data->municipal_assessor_suffix) ? $property_data->municipal_assessor_suffix : '',
                'municipal_assessor_title' => isset($property_data->municipal_assessor_title) ? $property_data->municipal_assessor_title : '',
                'municipal_assessor_license' => isset($property_data->municipal_assessor_license) ? $property_data->municipal_assessor_license : '',
                'change_reason' => $change_reason,
                'created_by' => $property_data->updated_by
            ),
            array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%f', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d')
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

