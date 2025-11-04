<?php

class Assessor_Properties {
    
    public function get_properties($request) {
        global $wpdb;
        
        // Add cache control headers to prevent caching
        header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        $params = $request->get_params();
        
        // Debug: Log all incoming parameters
        error_log('🔍 PROPERTIES API: get_properties called with params: ' . json_encode($params));
        error_log('🔍 PROPERTIES API: Request method: ' . $request->get_method());
        error_log('🔍 PROPERTIES API: Request route: ' . $request->get_route());
        
        $fetch_all = !empty($params['all']) && ($params['all'] === '1' || $params['all'] === 1 || $params['all'] === true);
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
            $q_raw = trim($params['q']);
            $q = '%' . $wpdb->esc_like($q_raw) . '%';
            $or_sql = array(
                "p.tax_declaration_number LIKE %s",
                "p.declarant_last_name LIKE %s",
                "p.declarant_first_name LIKE %s",
                "p.lot_number LIKE %s",
                "p.title_number LIKE %s",
                "p.business LIKE %s"
            );
            $or_vals = array_fill(0, count($or_sql), $q);

            // Support combined declarant name queries (e.g., "Last, First", "First Last", with optional middle initial)
            // Normalize query for name patterns
            $q_no_spaces = preg_replace('/\s+/', ' ', $q_raw);
            $q_no_dot = str_replace('.', '', $q_no_spaces);

            // Patterns to match (using CONCAT and TRIM to avoid double spaces):
            // 1) "Last, First" and "Last, First MI"
            $or_sql[] = "CONCAT(p.declarant_last_name, ', ', p.declarant_first_name) LIKE %s";
            $or_vals[] = '%' . $wpdb->esc_like($q_no_dot) . '%';
            $or_sql[] = "CONCAT(p.declarant_last_name, ', ', p.declarant_first_name, ' ', COALESCE(p.declarant_middle_initial, '')) LIKE %s";
            $or_vals[] = '%' . $wpdb->esc_like($q_no_dot) . '%';

            // 2) "First Last" and "First MI Last"
            $or_sql[] = "CONCAT(p.declarant_first_name, ' ', p.declarant_last_name) LIKE %s";
            $or_vals[] = '%' . $wpdb->esc_like($q_no_dot) . '%';
            $or_sql[] = "CONCAT(p.declarant_first_name, ' ', COALESCE(p.declarant_middle_initial, ''), ' ', p.declarant_last_name) LIKE %s";
            $or_vals[] = '%' . $wpdb->esc_like($q_no_dot) . '%';

            // 3) "Last First" (without comma)
            $or_sql[] = "CONCAT(p.declarant_last_name, ' ', p.declarant_first_name) LIKE %s";
            $or_vals[] = '%' . $wpdb->esc_like($q_no_dot) . '%';

            // Also match numeric-only searches against TDN without hyphens/spaces (e.g., '12312' matches '22-010-0001-12312')
            $q_digits_raw = preg_replace('/[^0-9]/', '', $q_raw);
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

        // Revision filter - filter by date range based on revision's from_year and to_year
        if (!empty($params['revision_id'])) {
            $revision_id = intval($params['revision_id']);
            error_log("🔍 REVISION FILTER START:");
            error_log("  - params['revision_id']: " . $params['revision_id']);
            error_log("  - intval revision_id: " . $revision_id);
            
            if ($revision_id > 0) {
                // Get revision details to determine date range
                $table_revisions = $wpdb->prefix . 'assessor_revision_entries';
                $revision = $wpdb->get_row($wpdb->prepare(
                    "SELECT from_year, to_year FROM $table_revisions WHERE id = %d AND status = 'active'",
                    $revision_id
                ));
                
                // Debug: Check if revision was found
                error_log("🔍 REVISION QUERY RESULT:");
                error_log("  - Query: SELECT from_year, to_year FROM $table_revisions WHERE id = $revision_id AND status = 'active'");
                error_log("  - Revision found: " . ($revision ? 'YES' : 'NO'));
                if ($revision) {
                    error_log("  - Revision data: " . print_r($revision, true));
                }

                if ($revision) {
                    $from_year = intval($revision->from_year);
                    // Treat 'present' as open-ended to include future years as well
                    $to_year = (strtolower($revision->to_year) === 'present') ? 9999 : intval($revision->to_year);
                    
                    // Debug logging
                    error_log("🔍 REVISION FILTER DEBUG:");
                    error_log("  - revision_id: " . $revision_id);
                    error_log("  - from_year: " . $from_year);
                    error_log("  - to_year: " . $to_year);
                    error_log("  - revision->from_year: " . $revision->from_year);
                    error_log("  - revision->to_year: " . $revision->to_year);
                    
                    // Filter by effectivity_date within the revision's date range
                    // effectivity_date is stored as varchar, so convert to integer for comparison
                    $where_conditions[] = "(
                        CAST(p.effectivity_date AS UNSIGNED) >= %d AND CAST(p.effectivity_date AS UNSIGNED) <= %d
                    )";
                    $where_values[] = $from_year;
                    $where_values[] = $to_year;
                    
                    error_log("  - Added WHERE condition with values: " . $from_year . " to " . $to_year);
                } else {
                    error_log("❌ REVISION FILTER: No revision found for ID: " . $revision_id);
                }
            }
        }

        // Image status filter: with | without | broken
        if (!empty($params['image_status'])) {
            $status = strtolower(trim($params['image_status']));
            error_log('Image status filter: ' . $status);
            // Define helpers for readability
            $has_http = "(p.supporting_documents REGEXP 'https?://' OR p.supporting_documents_old REGEXP 'https?://')";
            $has_any = "(COALESCE(p.supporting_documents,'') <> '' OR COALESCE(p.supporting_documents_old,'') <> '')";
            // For all image status filters, we need to get properties with potential URLs
            // The real filtering will be done server-side with actual URL verification
            if ($status === 'without') {
                // Without: no URLs at all
                $where_conditions[] = "NOT (" . $has_http . ")";
            } else {
                // For 'with' and 'broken', get properties that might have URLs
                // We'll verify them server-side
                $where_conditions[] = $has_http;
            }
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
        
        // Get properties with user information, property type name, and general class name
        $table_property_types = $wpdb->prefix . 'assessor_property_types';
        $table_general_classes = $wpdb->prefix . 'assessor_general_classes';
        $select_sql = "
            SELECT p.*, 
                   c.full_name as created_by_name,
                   u.full_name as updated_by_name,
                   p.business as business_name,
                   pt.name as kind_of_property_name,
                   gc.name as gen_class_name
            FROM $table_properties p
            LEFT JOIN $table_users c ON p.created_by = c.id
            LEFT JOIN $table_users u ON p.updated_by = u.id
            LEFT JOIN $table_property_types pt ON p.kind_of_property = pt.code
            LEFT JOIN $table_general_classes gc ON p.gen_class = gc.code
            $where_clause
            ORDER BY $order_by";

        // Debug logging for final query
        error_log("🔍 FINAL SQL QUERY:");
        error_log("  - SQL: " . $select_sql);
        error_log("  - WHERE clause: " . $where_clause);
        error_log("  - WHERE values: " . print_r($where_values, true));
        
        if ($fetch_all) {
            // Fetch all matching rows in one response
            $query = $select_sql; // no LIMIT/OFFSET
            if (!empty($where_values)) {
                error_log("  - Executing with WHERE values: " . print_r($where_values, true));
                $properties = $wpdb->get_results($wpdb->prepare($query, $where_values));
            } else {
                error_log("  - Executing without WHERE values");
                $properties = $wpdb->get_results($query);
            }
        } else {
            // Paged fetch
            $query = $select_sql . "\n            LIMIT %d OFFSET %d";
            $query_values = array_merge($where_values, array($per_page, $offset));
            $properties = $wpdb->get_results($wpdb->prepare($query, $query_values));
        }

        // If requesting image status filters, use hybrid approach for instant results
        if (!empty($params['image_status'])) {
            $status = strtolower(trim($params['image_status']));
            
            if ($status === 'without') {
                // Without: no URLs at all - instant database filtering
                $where_conditions[] = "(
                    (supporting_documents IS NULL OR supporting_documents = '' OR supporting_documents = '[]') 
                    AND 
                    (supporting_documents_old IS NULL OR supporting_documents_old = '' OR supporting_documents_old = '[]')
                )";
                
                // Rebuild the query with the new WHERE conditions
                $where_clause = '';
                if (!empty($where_conditions)) {
                    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
                }
                
                $select_sql = "SELECT DISTINCT p.* FROM {$wpdb->prefix}assessor_properties p";
                if (!empty($join_clause)) {
                    $select_sql .= " " . $join_clause;
                }
                $select_sql .= " " . $where_clause;
                
                if (!empty($order_clause)) {
                    $select_sql .= " " . $order_clause;
                }
                
                // Get total count for pagination
                $count_sql = "SELECT COUNT(DISTINCT p.id) FROM {$wpdb->prefix}assessor_properties p";
                if (!empty($join_clause)) {
                    $count_sql .= " " . $join_clause;
                }
                $count_sql .= " " . $where_clause;
                
                $total_count = $wpdb->get_var($wpdb->prepare($count_sql, $where_values));
                
                // Get paginated results
                $query = $select_sql . "\n            LIMIT %d OFFSET %d";
                $query_values = array_merge($where_values, array($per_page, $offset));
                $properties = $wpdb->get_results($wpdb->prepare($query, $query_values));
                
            } else {
                // For 'with' and 'broken', get all properties with URLs first (instant)
                $where_conditions[] = "(
                    (supporting_documents IS NOT NULL AND supporting_documents != '' AND supporting_documents != '[]' AND supporting_documents LIKE '%http%')
                    OR 
                    (supporting_documents_old IS NOT NULL AND supporting_documents_old != '' AND supporting_documents_old != '[]' AND supporting_documents_old LIKE '%http%')
                )";
                
                // Rebuild the query with the new WHERE conditions
                $where_clause = '';
                if (!empty($where_conditions)) {
                    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
                }
                
                $select_sql = "SELECT DISTINCT p.* FROM {$wpdb->prefix}assessor_properties p";
                if (!empty($join_clause)) {
                    $select_sql .= " " . $join_clause;
                }
                $select_sql .= " " . $where_clause;
                
                if (!empty($order_clause)) {
                    $select_sql .= " " . $order_clause;
                }
                
                // Get total count for pagination
                $count_sql = "SELECT COUNT(DISTINCT p.id) FROM {$wpdb->prefix}assessor_properties p";
                if (!empty($join_clause)) {
                    $count_sql .= " " . $join_clause;
                }
                $count_sql .= " " . $where_clause;
                
                $total_count = $wpdb->get_var($wpdb->prepare($count_sql, $where_values));
                
                // For image status filtering, get ALL properties with URLs (no pagination limit)
                $properties = $wpdb->get_results($wpdb->prepare($select_sql, $where_values));
                
                // Only perform per-URL verification for 'broken'.
                // 'with' relies on SQL has-http match for speed and consistency.
                if ($status === 'broken') {
                    $filtered = array();
                    
                    // Extract URLs from each property
                    $split_urls = function($raw) {
                        if (empty($raw)) return array();
                        $sources = is_array($raw) ? $raw : array($raw);
                        $joined = implode(' | ', array_map('strval', array_filter($sources)));
                        if (empty($joined)) return array();
                        $parts = preg_split('/[|,]/', $joined);
                        $urls = array();
                        foreach ($parts as $p) {
                            $u = trim($p);
                            if ($u !== '' && preg_match('/^https?:\/\//i', $u)) {
                                $urls[] = $u;
                            }
                        }
                        return $urls;
                    };
                    
                    // For debugging - let's see what we're working with
                    error_log('Processing ' . count($properties) . ' properties for status: ' . $status);
                    
                    // URL checker using fast HTTP HEAD with strict timeouts
                    $check_url = function($url) {
                        $ch = curl_init($url);
                        curl_setopt($ch, CURLOPT_NOBODY, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_USERAGENT, 'AssessorImageChecker/1.0');
                        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 1);
                        // Some hosts may have SSL issues; ignore to avoid false negatives
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                        curl_exec($ch);
                        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        $ctype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
                        $errno = curl_errno($ch);
                        curl_close($ch);
                        $ok = ($errno === 0) && ($http >= 200 && $http < 300) && is_string($ctype) && stripos($ctype, 'image/') === 0;
                        return array($ok, $http, $ctype, $errno);
                    };

                    // Process properties in batches to avoid timeout
                    $batch_size = 5; // Smaller batch size for better debugging
                    $batches = array_chunk($properties, $batch_size);
                    $processed_count = 0;
                    $broken_count = 0;
                    $working_count = 0;
                    
                    foreach ($batches as $batch) {
                        foreach ($batch as $prop) {
                            $processed_count++;
                            $urls = array_merge(
                                $split_urls(isset($prop->supporting_documents) ? $prop->supporting_documents : ''),
                                $split_urls(isset($prop->supporting_documents_old) ? $prop->supporting_documents_old : '')
                            );
                            
                            if (empty($urls)) continue;
                            
        // Debug: log first few properties and progress every 50 properties
        if ($processed_count <= 3) {
            error_log('Property ' . $processed_count . ' has ' . count($urls) . ' URLs: ' . implode(', ', $urls));
        } else if ($processed_count % 50 === 0) {
            error_log('Progress: Processed ' . $processed_count . ' properties so far...');
        }
                            
                            // Real URL checks via HTTP HEAD
                            $hasBrokenUrls = false;
                            $hasWorkingUrls = false;
                            foreach ($urls as $url) {
                                list($ok, $code, $ctype, $errno) = $check_url($url);
                                // Log selectively to avoid noise
                                if ($processed_count <= 2 || $processed_count % 200 === 0) {
                                    error_log('URL check => ok=' . ($ok ? '1' : '0') . ', http=' . $code . ', type=' . ($ctype ?: 'n/a') . ', err=' . $errno . ' | ' . $url);
                                }
                                if ($ok) {
                                    $hasWorkingUrls = true;
                                } else {
                                    $hasBrokenUrls = true;
                                }
                                // Early exit if we already know it is mixed
                                if ($hasBrokenUrls && $hasWorkingUrls) {
                                    // For 'broken' filter we only need to know there's any broken
                                    if ($status === 'broken') {
                                        break;
                                    }
                                }
                            }
                            
                            if ($status === 'with' && $hasWorkingUrls && !$hasBrokenUrls) {
                                // With: has working URLs and no broken URLs
                                if ($processed_count <= 5) { error_log('ADD with => TDN ' . $prop->tax_declaration_number); }
                                $filtered[] = $prop;
                                $working_count++;
                            } else if ($status === 'broken' && $hasBrokenUrls) {
                                // Broken: has at least one broken URL (even if others work)
                                if ($processed_count <= 5) { error_log('ADD broken => TDN ' . $prop->tax_declaration_number); }
                                $filtered[] = $prop;
                                $broken_count++;
                            }
                        }
                        
                        // Small delay between batches
                        usleep(50000); // 0.05 second delay
                    }
                    
                    error_log('Processed ' . $processed_count . ' properties. Found ' . $working_count . ' working, ' . $broken_count . ' broken');
error_log('Final filtered count: ' . count($filtered) . ' properties');
                    
                    $properties = $filtered;
                    
                    // Apply pagination after filtering
                    $total_count = count($properties);
                    // Update overall total to reflect filtered count
                    $total = $total_count;
                    if (!$fetch_all) {
                        $properties = array_slice($properties, $offset, $per_page);
                    }
                }
            }
        }
        
        return array(
            'properties' => $properties,
            'pagination' => array(
                'page' => $fetch_all ? 1 : $page,
                'per_page' => $fetch_all ? intval($total) : $per_page,
                'total' => intval($total),
                'total_pages' => $fetch_all ? 1 : ceil($total / $per_page)
            )
        );
    }
    
    public function get_property($id) {
        global $wpdb;
        
        $table_properties = $wpdb->prefix . 'assessor_properties';
        $table_users = $wpdb->prefix . 'assessor_users';
        
        $table_property_types = $wpdb->prefix . 'assessor_property_types';
        $table_general_classes = $wpdb->prefix . 'assessor_general_classes';
        $query = "
            SELECT p.*, 
                   c.full_name as created_by_name,
                   u.full_name as updated_by_name,
                   p.business as business_name,
                   pt.name as kind_of_property_name,
                   gc.name as gen_class_name
            FROM $table_properties p
            LEFT JOIN $table_users c ON p.created_by = c.id
            LEFT JOIN $table_users u ON p.updated_by = u.id
            LEFT JOIN $table_property_types pt ON p.kind_of_property = pt.code
            LEFT JOIN $table_general_classes gc ON p.gen_class = gc.code
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
        
        // Handle municipal_assessor_license with prefix to preserve leading zeros
        $license_value = sanitize_text_field($params['municipal_assessor_license']);
        $original_license = $license_value;
        if (!empty($license_value)) {
            $license_value = 'LICENSE_' . $license_value;
            error_log("🔍 CREATE: Added prefix = '$license_value'");
        }
        
        // Normalize previous tax declaration numbers (support multiple via ';')
        $normalized_previous_tdn = $this->normalize_previous_tax_declaration_numbers(isset($params['previous_tax_declaration_number']) ? $params['previous_tax_declaration_number'] : '');

        // Insert property
        $result = $wpdb->insert(
            $table_properties,
            array(
                'tax_declaration_number' => sanitize_text_field($params['tax_declaration_number']),
                'previous_tax_declaration_number' => $normalized_previous_tdn,
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
                'municipal_assessor_license' => $license_value,
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
        
        // Remove prefix from license after successful insert to restore original value
        if (!empty($original_license)) {
            $result = $wpdb->update(
                $table_properties,
                array('municipal_assessor_license' => $original_license),
                array('id' => $property_id),
                array('%s'),
                array('%d')
            );
            error_log("🔍 CREATE: Removed prefix, final license = '$original_license'");
            
            // Verify the value was stored correctly
            $stored_license = $wpdb->get_var($wpdb->prepare(
                "SELECT municipal_assessor_license FROM $table_properties WHERE id = %d",
                $property_id
            ));
            error_log("🔍 CREATE: License after DB insert = '$stored_license' (with leading zeros preserved)");
        }
        
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
                'business' => $created->business,
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
            'assessed_value_old',
            'effectivity_date',
            'pin',
            'address',
            'assessment_date',
            'kind_of_property',
            'gen_class',
            'memoranda',
            'supporting_documents',
            'supporting_documents_old',
            'verifier_signatory_name',
            'verifier_signatory_title',
            'municipal_assessor_name',
            'municipal_assessor_suffix',
            'municipal_assessor_title',
            'municipal_assessor_license',
            'status'
        );
        $old_values = array();
        $new_values = array();
        foreach ($fields_to_track as $field) {
            // Handle business field name mapping (frontend sends business_name, DB stores as business)
            $param_key = $field;
            if ($field === 'business') {
                $param_key = 'business_name';
            }
            
            if (!array_key_exists($param_key, $params)) {
                continue;
            }
            $new_raw = $params[$param_key];
            $old_raw = isset($current_property->$field) ? $current_property->$field : null;

            // Normalize values by field type to avoid logging formatting-only changes
            $is_numeric_4 = ($field === 'area_hectare' || $field === 'area_sqm');
            $is_numeric_2 = ($field === 'assessed_value');

            if ($is_numeric_4 || $is_numeric_2) {
                $precision = $is_numeric_4 ? 4 : 2;
                
                // Better handling of numeric values
                $old_num = is_null($old_raw) || $old_raw === '' ? null : floatval($old_raw);
                $new_num = $new_raw === '' || is_null($new_raw) ? null : floatval($new_raw);
                
                // For area_hectare, treat 0.0000 as equivalent to null/empty when comparing
                // This handles cases where the database has 0.0000 but the form sends empty
                if ($field === 'area_hectare') {
                    if ($old_num !== null && $old_num == 0) {
                        $old_num = null;
                    }
                    if ($new_num !== null && $new_num == 0) {
                        $new_num = null;
                    }
                }

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
                // Treat empty strings as null for comparison purposes
                return $s === '' ? null : $s;
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
                if ($field === 'previous_tax_declaration_number') {
                    $update_data[$field] = $this->normalize_previous_tax_declaration_numbers($params[$field]);
                } else
                if ($field === 'area_hectare_old') {
                    // Allow explicit nulling when cleared on edit
                    if ($params[$field] === '' || is_null($params[$field])) {
                        $update_data[$field] = null;
                    } else {
                        $update_data[$field] = sanitize_text_field($params[$field]);
                    }
                } else if ($field === 'municipal_assessor_license') {
                    // Handle municipal_assessor_license with prefix to preserve leading zeros
                    $license_value = sanitize_text_field($params[$field]);
                    if (!empty($license_value)) {
                        $update_data[$field] = 'LICENSE_' . $license_value;
                        error_log("🔍 UPDATE: Added prefix = 'LICENSE_$license_value'");
                    } else {
                        $update_data[$field] = $license_value;
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
        
        // Remove prefix from license after successful update to restore original value
        if (isset($params['municipal_assessor_license'])) {
            $original_license = sanitize_text_field($params['municipal_assessor_license']);
            if (!empty($original_license)) {
                $result = $wpdb->update(
                    $table_properties,
                    array('municipal_assessor_license' => $original_license),
                    array('id' => $id),
                    array('%s'),
                    array('%d')
                );
                error_log("🔍 UPDATE: Removed prefix, final license = '$original_license'");
                
                // Verify the value was stored correctly
                $stored_license = $wpdb->get_var($wpdb->prepare(
                    "SELECT municipal_assessor_license FROM $table_properties WHERE id = %d",
                    $id
                ));
                error_log("🔍 UPDATE: License after DB update = '$stored_license' (with leading zeros preserved)");
            }
        }
        
        // Business is stored on properties table directly
        
        // Log audit trail with captured changes (only if there are actual changes)
        if (!empty($old_values) && !empty($new_values)) {
            $audit = new Assessor_Audit();
            $audit->log_activity($user_id, 'update', 'assessor_properties', $id, $old_values, $new_values);
        }
        
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
        $table_properties = $wpdb->prefix . 'assessor_properties';
        
        // Count linked tax declarations = 1 count per linked chain
        // Count unique tax declaration chains by finding root declarations
        // Root declarations are those that don't have a previous_tax_declaration_number
        // or their previous_tax_declaration_number doesn't exist in the properties table
        $query = "
            SELECT COUNT(DISTINCT tax_declaration_number) as linked_chains
            FROM {$table_properties} p1
            WHERE p1.status != 'deleted'
              AND (p1.previous_tax_declaration_number IS NULL 
                   OR p1.previous_tax_declaration_number = ''
                   OR NOT EXISTS (
                       SELECT 1 FROM {$table_properties} p2 
                       WHERE p2.tax_declaration_number = p1.previous_tax_declaration_number
                         AND p2.status != 'deleted'
                   ))
        ";
        
        $result = $wpdb->get_var($query);
        
        // Debug logging
        error_log("🔍 Linked Tax Declaration Count Query: " . $query);
        error_log("🔍 Linked Tax Declaration Count Result: " . ($result ? $result : 0));
        
        return $result ? $result : 0;
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
            // Support multiple previous TDs stored as semicolon-separated list by using FIND_IN_SET on a comma-normalized string
            $next_number = $wpdb->get_var($wpdb->prepare(
                "SELECT tax_declaration_number 
                 FROM $table_properties 
                 WHERE FIND_IN_SET(%s, REPLACE(previous_tax_declaration_number, ';', ',')) > 0 AND status != 'deleted' 
                 ORDER BY created_at DESC 
                 LIMIT 1",
                $head_number
            ));
            if (!$next_number) {
                break;
            }
            $head_number = $next_number;
        }

        // 2) Build the tree backwards (branching): traverse all previous TDs and collect unique rows
        $start_number = $head_number ?: $tax_declaration_number;
        $visited = array();
        $queue = array();
        if ($start_number) {
            $queue[] = $start_number;
        }
        $table_property_types = $wpdb->prefix . 'assessor_property_types';
        $table_general_classes = $wpdb->prefix . 'assessor_general_classes';
        while (!empty($queue)) {
            $current_number = array_shift($queue);
            if (!$current_number || isset($visited[$current_number])) {
                continue;
            }
            $visited[$current_number] = true;

            $property = $wpdb->get_row($wpdb->prepare(
                "SELECT 
                        p.id, p.tax_declaration_number, p.previous_tax_declaration_number, 
                        p.declarant_last_name, p.declarant_first_name, p.declarant_middle_initial,
                        p.business,
                        p.location, p.lot_number, p.area_hectare, p.area_hectare_old, p.area_sqm, p.title_number, p.effectivity_date,
                        p.assessed_value, p.assessed_value_old, p.kind_of_property, p.memoranda, p.supporting_documents, p.supporting_documents_old, p.pin, p.address, p.assessment_date, p.gen_class, p.created_at, p.updated_at,
                        p.verifier_signatory_name, p.verifier_signatory_title,
                        p.municipal_assessor_name, p.municipal_assessor_suffix, p.municipal_assessor_title, p.municipal_assessor_license,
                        c.full_name AS created_by_name,
                        u.full_name AS updated_by_name,
                        pt.name as kind_of_property_name,
                        gc.name as gen_class_name
                 FROM $table_properties p
                 LEFT JOIN $table_users c ON p.created_by = c.id
                 LEFT JOIN $table_users u ON p.updated_by = u.id
                 LEFT JOIN $table_property_types pt ON p.kind_of_property = pt.code
                 LEFT JOIN $table_general_classes gc ON p.gen_class = gc.code
                 WHERE p.tax_declaration_number = %s AND p.status != 'deleted' 
                 ORDER BY p.created_at DESC 
                 LIMIT 1",
                $current_number
            ));

            if (!$property) {
                continue;
            }

            $memoranda_value = $property->memoranda;
            if (empty($memoranda_value)) {
                $memoranda_value = $wpdb->get_var($wpdb->prepare(
                    "SELECT memoranda FROM $table_versions WHERE property_id = %d ORDER BY version_number DESC LIMIT 1",
                    $property->id
                ));
            }

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
                'kind_of_property_name' => isset($property->kind_of_property_name) ? $property->kind_of_property_name : $property->kind_of_property,
                'gen_class' => $property->gen_class,
                'gen_class_name' => isset($property->gen_class_name) ? $property->gen_class_name : $property->gen_class,
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
                'created_by_name' => $property->created_by_name,
                'updated_at' => isset($property->updated_at) ? $property->updated_at : null,
                'updated_by_name' => isset($property->updated_by_name) ? $property->updated_by_name : ''
            );

            // Enqueue all previous declaration numbers (branching if multiple)
            $prev_raw = (string)$property->previous_tax_declaration_number;
            if ($prev_raw !== '') {
                if (strpos($prev_raw, ';') !== false) {
                    $tokens = array_filter(array_map('trim', explode(';', $prev_raw)), function($t){ return $t !== ''; });
                    foreach ($tokens as $t) {
                        if ($t && !isset($visited[$t])) {
                            $queue[] = $t;
                        }
                    }
                } else {
                    if (!isset($visited[$prev_raw])) {
                        $queue[] = $prev_raw;
                    }
                }
            }
        }
        
        return $history;
    }
    
    public function get_property_by_tax_number($tax_declaration_number) {
        global $wpdb;
        
        $table_properties = $wpdb->prefix . 'assessor_properties';
        $table_users = $wpdb->prefix . 'assessor_users';
        
        $table_property_types = $wpdb->prefix . 'assessor_property_types';
        $table_general_classes = $wpdb->prefix . 'assessor_general_classes';
        $query = "
            SELECT p.*, 
                   c.full_name as created_by_name,
                   u.full_name as updated_by_name,
                   p.business as business_name,
                   pt.name as kind_of_property_name,
                   gc.name as gen_class_name
            FROM $table_properties p
            LEFT JOIN $table_users c ON p.created_by = c.id
            LEFT JOIN $table_users u ON p.updated_by = u.id
            LEFT JOIN $table_property_types pt ON p.kind_of_property = pt.code
            LEFT JOIN $table_general_classes gc ON p.gen_class = gc.code
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

    private function normalize_previous_tax_declaration_numbers($raw) {
        // Accept commas or semicolons, normalize to semicolons and uppercase tokens
        $value = is_string($raw) ? $raw : '';
        if ($value === '') {
            return '';
        }
        $upper = strtoupper($value);
        // Replace commas with semicolons and collapse repeated separators
        $replaced = preg_replace('/[;，、]+/u', ';', str_replace(',', ';', $upper));
        $parts = array_filter(array_map('trim', explode(';', $replaced)), function($t) { return $t !== ''; });
        if (empty($parts)) {
            return '';
        }
        $seen = array();
        $unique = array();
        foreach ($parts as $p) {
            if (!isset($seen[$p])) {
                $seen[$p] = true;
                $unique[] = sanitize_text_field($p);
            }
        }
        return implode(';', $unique);
    }
}

