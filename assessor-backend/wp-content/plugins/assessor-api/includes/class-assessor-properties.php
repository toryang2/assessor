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
        
        // Unified free-text search (staff) vs public search blocks (explicit q and/or tax_declaration_number; no "name TD" in one string).
        $public_where_reserved = array('handled' => false);
        if (!empty($params['current_tdn_only'])) {
            $this->apply_public_property_search_where_block($params, $where_conditions, $where_values, $table_properties, $public_where_reserved);
        } elseif (!empty($params['q'])) {
            $q_raw = trim($params['q']);
            $this->apply_whole_string_q_search($q_raw, $where_conditions, $where_values);
        }

        if (!$public_where_reserved['handled'] && !empty($params['tax_declaration_number'])) {
            $where_conditions[] = 'p.tax_declaration_number LIKE %s';
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
        
        if (!empty($params['kind_of_property'])) {
            $where_conditions[] = "p.kind_of_property = %s";
            $where_values[] = $params['kind_of_property'];
        }
        
        if (!empty($params['gen_class'])) {
            $where_conditions[] = "p.gen_class = %s";
            $where_values[] = $params['gen_class'];
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
            $raw_revision_id = trim(strval($params['revision_id']));
            error_log("🔍 REVISION FILTER START:");
            error_log("  - params['revision_id']: " . $raw_revision_id);
            
            // Resolve canonical revision (supports UUID v7, revision_code, and legacy numeric ID)
            $revision = null;
            if (class_exists('Assessor_Settings')) {
                $revision = Assessor_Settings::resolve_revision($raw_revision_id);
            } else {
                $table_revisions = $wpdb->prefix . 'assessor_revision_entries';
                $revision = $wpdb->get_row($wpdb->prepare(
                    "SELECT from_year, to_year FROM $table_revisions WHERE (id = %s OR revision_code = %s) AND status = 'active'",
                    $raw_revision_id,
                    $raw_revision_id
                ));
            }
            
            // Debug: Check if revision was found
            error_log("🔍 REVISION QUERY RESULT:");
            error_log("  - Revision found: " . ($revision ? 'YES' : 'NO'));
            if ($revision) {
                error_log("  - Revision data: " . print_r($revision, true));
                
                $from_year = intval($revision->from_year);
                // Treat 'present' as open-ended to include future years as well
                $to_year = (strtolower($revision->to_year) === 'present') ? 9999 : intval($revision->to_year);
                
                // Debug logging
                error_log("🔍 REVISION FILTER DEBUG:");
                error_log("  - identifier: " . $raw_revision_id);
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
                error_log("❌ REVISION FILTER: No revision found for identifier: " . $raw_revision_id);
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

        if (!empty($params['property_state'])) {
            $where_conditions[] = "COALESCE(ps.state, 'CURRENT') = %s";
            $where_values[] = strtoupper($params['property_state']);
        }
        
        // Always exclude deleted properties
        $where_conditions[] = "p.status != 'deleted'";

        // Only current (head) tax declarations — exclude superseded records in a TDN chain
        if (!empty($params['current_tdn_only'])) {
            $where_conditions[] = "NOT EXISTS (
                SELECT 1 FROM $table_properties p_newer
                WHERE p_newer.status != 'deleted'
                  AND (
                    p_newer.previous_tax_declaration_number = p.tax_declaration_number
                    OR FIND_IN_SET(p.tax_declaration_number, REPLACE(p_newer.previous_tax_declaration_number, ';', ',')) > 0
                  )
            )";
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
        $table_property_states = $wpdb->prefix . 'assessor_property_states';
        $count_query = "SELECT COUNT(*) FROM $table_properties p LEFT JOIN $table_property_states ps ON p.id = ps.property_id $where_clause";
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
                   gc.name as gen_class_name,
                   COALESCE(ps.state, 'CURRENT') as property_state
            FROM $table_properties p
            LEFT JOIN $table_users c ON p.created_by = c.id
            LEFT JOIN $table_users u ON p.updated_by = u.id
            LEFT JOIN $table_property_types pt ON p.kind_of_property = pt.code
            LEFT JOIN $table_general_classes gc ON p.gen_class = gc.code
            LEFT JOIN $table_property_states ps ON p.id = ps.property_id
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
        
        // Rewrite image URLs dynamically based on requesting host
        if (defined('ASSESSOR_IS_LOCAL_BUILD') && ASSESSOR_IS_LOCAL_BUILD) {
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
            $current_host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
            $local_base = rtrim($protocol . $current_host, '/');
            $live_domain = defined('ASSESSOR_LIVE_SITE_URL') ? rtrim(ASSESSOR_LIVE_SITE_URL, '/') : '';
            
            foreach ($properties as &$prop) {
                if (!empty($prop->supporting_documents)) {
                    $prop->supporting_documents = $this->rewrite_document_urls($prop->supporting_documents, $live_domain, $local_base);
                }
                if (!empty($prop->supporting_documents_old)) {
                    $prop->supporting_documents_old = $this->rewrite_document_urls($prop->supporting_documents_old, $live_domain, $local_base);
                }
            }
            unset($prop); // Break reference
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
        $table_property_states = $wpdb->prefix . 'assessor_property_states';
        $query = "
            SELECT p.*, 
                   c.full_name as created_by_name,
                   u.full_name as updated_by_name,
                   p.business as business_name,
                   pt.name as kind_of_property_name,
                   gc.name as gen_class_name,
                   COALESCE(ps.state, 'CURRENT') as property_state
            FROM $table_properties p
            LEFT JOIN $table_users c ON p.created_by = c.id
            LEFT JOIN $table_users u ON p.updated_by = u.id
            LEFT JOIN $table_property_types pt ON p.kind_of_property = pt.code
            LEFT JOIN $table_general_classes gc ON p.gen_class = gc.code
            LEFT JOIN $table_property_states ps ON p.id = ps.property_id
            WHERE p.id = %s AND p.status != 'deleted'
        ";
        
        $property = $wpdb->get_row($wpdb->prepare($query, $id));
        
        if (!$property) {
            return new WP_Error('property_not_found', 'Property not found', array('status' => 404));
        }

        // Rewrite image URLs dynamically based on requesting host
        if (defined('ASSESSOR_IS_LOCAL_BUILD') && ASSESSOR_IS_LOCAL_BUILD) {
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
            $current_host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
            $local_base = rtrim($protocol . $current_host, '/');
            $live_domain = defined('ASSESSOR_LIVE_SITE_URL') ? rtrim(ASSESSOR_LIVE_SITE_URL, '/') : '';
            
            if (!empty($property->supporting_documents)) {
                $property->supporting_documents = $this->rewrite_document_urls($property->supporting_documents, $live_domain, $local_base);
            }
            if (!empty($property->supporting_documents_old)) {
                $property->supporting_documents_old = $this->rewrite_document_urls($property->supporting_documents_old, $live_domain, $local_base);
            }
        }
        
        return $property;
    }
    
    public function create_property($request) {
        global $wpdb;
        $table_properties = $wpdb->prefix . 'assessor_properties';
        
        $params = $request->get_params();
        $user_id = $this->get_user_id_from_request($request);
        
        // Validate required fields
        $required_fields = array('tax_declaration_number', 'location', 'kind_of_property', 'effectivity_date');
        foreach ($required_fields as $field) {
            if (empty($params[$field])) {
                return new WP_Error('missing_field', "Field '$field' is required", array('status' => 400));
            }
        }

        // Validate effectivity_date and resolve authoritative revision UUID
        $client_rev = isset($params['revision_id']) ? $params['revision_id'] : null;
        $resolved_revision_id = $this->resolve_revision_by_effectivity_date($params['effectivity_date'], $client_rev);
        if (is_wp_error($resolved_revision_id)) {
            return $resolved_revision_id;
        }
        
        // Check if tax declaration number already exists in this revision
        if ($this->is_tdn_duplicate_in_revision($params['tax_declaration_number'], $resolved_revision_id)) {
            return new WP_Error(
                'duplicate_tax_number',
                sprintf("Tax declaration number '%s' already exists in this revision.", $params['tax_declaration_number']),
                array('status' => 400)
            );
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
        $survey_number = '';
        if (isset($params['survey_number'])) {
            $survey_number = sanitize_text_field($params['survey_number']);
        } else if (isset($params['unique_lot_number_identified'])) {
            $survey_number = sanitize_text_field($params['unique_lot_number_identified']);
        }

        // Generate UUID v7 for new property
        $property_id = Assessor_UUID::v7();

        $result = $wpdb->insert(
            $table_properties,
            array(
                'id' => $property_id,
                'tax_declaration_number' => sanitize_text_field($params['tax_declaration_number']),
                'previous_tax_declaration_number' => $normalized_previous_tdn,
                'declarant_last_name' => sanitize_text_field($params['declarant_last_name']),
                'declarant_first_name' => sanitize_text_field($params['declarant_first_name']),
                'declarant_middle_initial' => sanitize_text_field($params['declarant_middle_initial']),
                'business' => sanitize_text_field($params['business_name']),
                'location' => sanitize_textarea_field($params['location']),
                'lot_number' => sanitize_text_field($params['lot_number']),
                'unique_lot_number_identified' => sanitize_text_field($params['unique_lot_number_identified'] ?? ''),
                'survey_number' => $survey_number,
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
                'verifier_signatory_name' => sanitize_text_field($params['verifier_signatory_name']),
                'verifier_signatory_title' => sanitize_text_field($params['verifier_signatory_title']),
                'municipal_assessor_name' => sanitize_text_field($params['municipal_assessor_name']),
                'municipal_assessor_suffix' => sanitize_text_field($params['municipal_assessor_suffix']),
                'municipal_assessor_title' => sanitize_text_field($params['municipal_assessor_title']),
                'municipal_assessor_license' => $license_value,
                'status' => 'active',
                'revision_id' => $resolved_revision_id,
                'created_by' => $user_id,
                'updated_by' => $user_id,
                'created_at' => isset($params['created_at']) ? $params['created_at'] : date('Y-m-d H:i:s'),
                'updated_at' => isset($params['updated_at']) ? $params['updated_at'] : date('Y-m-d H:i:s')
            )
        );
        
        if ($result === false) {
            return new WP_Error('insert_failed', 'Failed to create property: ' . $wpdb->last_error, array('status' => 500));
        }
        
        // Remove prefix from license after successful insert to restore original value
        if (!empty($original_license)) {
            $result = $wpdb->update(
                $table_properties,
                array('municipal_assessor_license' => $original_license),
                array('id' => $property_id),
                array('%s'),
                array('%s')
            );
            error_log("🔍 CREATE: Removed prefix, final license = '$original_license'");
            
            // Verify the value was stored correctly
            $stored_license = $wpdb->get_var($wpdb->prepare(
                "SELECT municipal_assessor_license FROM $table_properties WHERE id = %s",
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
                'survey_number' => isset($created->survey_number) ? $created->survey_number : $created->unique_lot_number_identified,
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
        // Auto-cancel previous TDNs if provided, but ONLY if this property is CURRENT
        $current_state = $wpdb->get_var($wpdb->prepare(
            "SELECT state FROM {$wpdb->prefix}assessor_property_states WHERE property_id = %s",
            $property_id
        ));
        $current_state = $current_state ? strtoupper($current_state) : 'CURRENT';
        
        if (in_array($current_state, ['CURRENT', 'CANCELLED']) && !empty($normalized_previous_tdn)) {
            $tdn_list = array_map('trim', explode(';', $normalized_previous_tdn));
            foreach ($tdn_list as $tdn) {
                if (empty($tdn)) continue;
                // Find all property IDs with this TDN (across all revisions) to prevent leaving older revisions un-cancelled
                $prev_prop_ids = $wpdb->get_col($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}assessor_properties WHERE tax_declaration_number = %s AND status != 'deleted'",
                    $tdn
                ));
                if (!empty($prev_prop_ids)) {
                    foreach ($prev_prop_ids as $prev_prop_id) {
                        $wpdb->replace(
                            "{$wpdb->prefix}assessor_property_states",
                            [
                                'property_id' => $prev_prop_id,
                                'state' => 'CANCELLED',
                                'updated_by' => $user_id,
                                'updated_at' => current_time('mysql')
                            ],
                            ['%s', '%s', '%s', '%s']
                        );
                    }
                }
            }
        }

        // Auto-cancel THIS property if it is already referenced as a previous TD by another property
        if (isset($params['tax_declaration_number'])) {
            $this->auto_cancel_if_superseded($property_id, sanitize_text_field($params['tax_declaration_number']), $user_id);
        }

        $audit->log_activity($user_id, 'create', 'assessor_properties', $property_id, null, $new_values);
        
        // Enqueue for sync to live site (only on local builds, and not when the write came from sync itself)
        if (class_exists('Assessor_Sync')) {
            Assessor_Sync::enqueue_property($property_id, 'upsert');
        }

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
            'survey_number',
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
            'status',
            'revision_id'
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
        
        $update_data = array(
            'updated_by' => $user_id,
            'updated_at' => isset($params['updated_at']) ? $params['updated_at'] : date('Y-m-d H:i:s')
        );
        
        $allowed_fields = array(
            'tax_declaration_number', 'previous_tax_declaration_number', 'declarant_last_name', 'declarant_first_name', 
            'declarant_middle_initial', 'business', 'location', 'lot_number', 'unique_lot_number_identified', 'survey_number',
            'area_hectare', 'area_hectare_old', 'area_sqm', 'title_number', 'assessed_value', 'assessed_value_old', 'effectivity_date', 'pin', 
            'address', 'assessment_date', 'kind_of_property', 'gen_class', 'memoranda', 
            'supporting_documents', 'supporting_documents_old', 'verifier_signatory_name', 'verifier_signatory_title', 'municipal_assessor_name',
            'municipal_assessor_suffix', 'municipal_assessor_title', 'municipal_assessor_license', 'status', 'revision_id'
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
                } else if (in_array($field, array('address', 'memoranda', 'supporting_documents', 'supporting_documents_old'), true)) {
                    // Preserve newlines for textarea-like fields (MySQL TEXT supports \n)
                    $update_data[$field] = sanitize_textarea_field($params[$field]);
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
                } else if ($field === 'revision_id') {
                    // Handled authoritatively via effectivity_date logic below
                    continue;
                } else {
                    $update_data[$field] = sanitize_text_field($params[$field]);
                }
            }
        }

        // Authoritative revision assignment on UPDATE:
        // 1. If effectivity_date changes, recalculate revision UUID
        // 2. If effectivity_date does not change, preserve revision UUID
        // 3. If client provides revision_id, accept only after validating against resolved revision
        $old_effectivity = isset($current_property->effectivity_date) ? trim((string)$current_property->effectivity_date) : '';
        $client_rev = isset($params['revision_id']) ? $params['revision_id'] : null;

        if (array_key_exists('effectivity_date', $params)) {
            $new_effectivity = trim((string)$params['effectivity_date']);
            if ($new_effectivity !== $old_effectivity) {
                // Effectivity date changed: recalculate revision UUID authoritatively
                $resolved_rev_id = $this->resolve_revision_by_effectivity_date($new_effectivity, $client_rev);
                if (is_wp_error($resolved_rev_id)) {
                    return $resolved_rev_id;
                }
                $update_data['revision_id'] = $resolved_rev_id;
            } else {
                // Effectivity date did not change: preserve existing revision UUID
                // If the property previously had no revision_id (e.g. legacy/NULL) and has a valid date, calculate it
                if (empty($current_property->revision_id) && $new_effectivity !== '') {
                    $resolved_rev_id = $this->resolve_revision_by_effectivity_date($new_effectivity, $client_rev);
                    if (!is_wp_error($resolved_rev_id)) {
                        $update_data['revision_id'] = $resolved_rev_id;
                    }
                } elseif (!empty($client_rev) && !empty($current_property->revision_id)) {
                    // If client explicitly passed a revision_id while date didn't change, validate match
                    if (strcasecmp(trim($client_rev), $current_property->revision_id) !== 0) {
                        return new WP_Error(
                            'revision_mismatch',
                            sprintf("Client-supplied revision does not match current property revision for unchanged effectivity date '%s'.", $old_effectivity),
                            array('status' => 400)
                        );
                    }
                }
            }
        } elseif (!empty($client_rev)) {
            // effectivity_date was not passed, but client passed revision_id: validate against current property revision
            if (!empty($current_property->revision_id) && strcasecmp(trim($client_rev), $current_property->revision_id) !== 0) {
                return new WP_Error(
                    'revision_mismatch',
                    'Client-supplied revision does not match the property revision.',
                    array('status' => 400)
                );
            }
        }

        // Validate revision-aware TDN uniqueness:
        // Exclude current property UUID ($id), reject if duplicate TDN exists in the target revision
        $target_tdn = isset($update_data['tax_declaration_number']) ? $update_data['tax_declaration_number'] : $current_property->tax_declaration_number;
        $target_rev_id = isset($update_data['revision_id']) ? $update_data['revision_id'] : $current_property->revision_id;

        if ($this->is_tdn_duplicate_in_revision($target_tdn, $target_rev_id, $id)) {
            return new WP_Error(
                'duplicate_tax_number',
                sprintf("Tax declaration number '%s' already exists in this revision.", $target_tdn),
                array('status' => 400)
            );
        }
        
        $result = $wpdb->update(
            $table_properties,
            $update_data,
            array('id' => $id),
            null,
            array('%s')
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
                    array('%s')
                );
                error_log("🔍 UPDATE: Removed prefix, final license = '$original_license'");
                
                // Verify the value was stored correctly
                $stored_license = $wpdb->get_var($wpdb->prepare(
                    "SELECT municipal_assessor_license FROM $table_properties WHERE id = %s",
                    $id
                ));
                error_log("🔍 UPDATE: License after DB update = '$stored_license' (with leading zeros preserved)");
            }
        }
        
        // Business is stored on properties table directly
        
        // Auto-cancel previous TDNs if provided, but ONLY if this property is CURRENT
        $current_state = $wpdb->get_var($wpdb->prepare(
            "SELECT state FROM {$wpdb->prefix}assessor_property_states WHERE property_id = %s",
            $id
        ));
        $current_state = $current_state ? strtoupper($current_state) : 'CURRENT';
        
        $normalized_previous_tdn = isset($params['previous_tax_declaration_number']) ? $this->normalize_previous_tax_declaration_numbers($params['previous_tax_declaration_number']) : '';
        if (in_array($current_state, ['CURRENT', 'CANCELLED']) && !empty($normalized_previous_tdn)) {
            $tdn_list = array_map('trim', explode(';', $normalized_previous_tdn));
            foreach ($tdn_list as $tdn) {
                if (empty($tdn)) continue;
                // Find all property IDs with this TDN (across all revisions) to prevent leaving older revisions un-cancelled
                $prev_prop_ids = $wpdb->get_col($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}assessor_properties WHERE tax_declaration_number = %s AND status != 'deleted'",
                    $tdn
                ));
                if (!empty($prev_prop_ids)) {
                    foreach ($prev_prop_ids as $prev_prop_id) {
                        $wpdb->replace(
                            "{$wpdb->prefix}assessor_property_states",
                            [
                                'property_id' => $prev_prop_id,
                                'state' => 'CANCELLED',
                                'updated_by' => $user_id,
                                'updated_at' => current_time('mysql')
                            ],
                            ['%s', '%s', '%s', '%s']
                        );
                    }
                }
            }
        }
        
        // Revert any TDNs that were removed from previous_tax_declaration_number
        if (isset($params['previous_tax_declaration_number'])) {
            $old_tdns = empty($current_property->previous_tax_declaration_number) ? [] : array_map('trim', explode(';', $current_property->previous_tax_declaration_number));
            $new_tdns = empty($normalized_previous_tdn) ? [] : array_map('trim', explode(';', $normalized_previous_tdn));
            $removed_tdns = array_diff($old_tdns, $new_tdns);
            if (!empty($removed_tdns)) {
                $this->revert_cancelled_states(implode(';', $removed_tdns), $id, $user_id);
            }
        }
        
        // Auto-cancel THIS property if it is already referenced as a previous TD by another property
        $this_tdn = isset($params['tax_declaration_number']) ? sanitize_text_field($params['tax_declaration_number']) : $current_property->tax_declaration_number;
        $this->auto_cancel_if_superseded($id, $this_tdn, $user_id);
        
        // Log audit trail with captured changes (only if there are actual changes)
        if (!empty($old_values) && !empty($new_values)) {
            $audit = new Assessor_Audit();
            $audit->log_activity($user_id, 'update', 'assessor_properties', $id, $old_values, $new_values);
        }

        // Enqueue for sync to live site (only on local builds, and not when the write came from sync itself)
        if (class_exists('Assessor_Sync')) {
            Assessor_Sync::enqueue_property($id, 'upsert');
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
            array('%s')
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
            'survey_number' => isset($property->survey_number) ? $property->survey_number : $property->unique_lot_number_identified,
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
        
        $this->revert_cancelled_states($property->previous_tax_declaration_number, $id, $user_id);
        
        return array('success' => true, 'message' => 'Property deleted successfully');
    }
    public function update_property_state($id, $state, $request) {
        global $wpdb;
        $user_id = $this->get_user_id_from_request($request);

        // Fetch tax_declaration_number for this property
        $tax_declaration_number = $wpdb->get_var($wpdb->prepare(
            "SELECT tax_declaration_number FROM {$wpdb->prefix}assessor_properties WHERE id = %s",
            $id
        ));

        if (!empty($tax_declaration_number)) {
            $is_superseded = $wpdb->get_var($wpdb->prepare(
                "SELECT p.id FROM {$wpdb->prefix}assessor_properties p
                 LEFT JOIN {$wpdb->prefix}assessor_property_states ps ON p.id = ps.property_id
                 WHERE FIND_IN_SET(%s, REPLACE(p.previous_tax_declaration_number, ';', ',')) > 0
                 AND p.status != 'deleted' AND p.id != %s 
                 AND COALESCE(ps.state, 'CURRENT') IN ('CURRENT', 'CANCELLED') LIMIT 1",
                $tax_declaration_number,
                $id
            ));
            
            if ($is_superseded && strtoupper($state) === 'CURRENT') {
                $state = 'CANCELLED'; // Force cancelled if superseded and trying to be active
            }
        }

        $table_property_states = $wpdb->prefix . 'assessor_property_states';
        $result = $wpdb->replace(
            $table_property_states,
            [
                'property_id' => $id,
                'state'       => strtoupper($state),
                'updated_by'  => $user_id,
                'updated_at'  => current_time('mysql')
            ],
            ['%s', '%s', '%s', '%s']
        );

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to update property state', ['status' => 500]);
        }
        
        // Sync previous TDs states
        $previous_tax_declaration_number = $wpdb->get_var($wpdb->prepare(
            "SELECT previous_tax_declaration_number FROM {$wpdb->prefix}assessor_properties WHERE id = %s",
            $id
        ));
        
        if (!empty($previous_tax_declaration_number)) {
            if (in_array(strtoupper($state), ['CURRENT', 'CANCELLED'])) {
                $tdn_list = array_map('trim', explode(';', $previous_tax_declaration_number));
                foreach ($tdn_list as $tdn) {
                    if (empty($tdn)) continue;
                    $prev_prop_ids = $wpdb->get_col($wpdb->prepare(
                        "SELECT id FROM {$wpdb->prefix}assessor_properties WHERE tax_declaration_number = %s AND status != 'deleted'",
                        $tdn
                    ));
                    if (!empty($prev_prop_ids)) {
                        foreach ($prev_prop_ids as $prev_prop_id) {
                            $wpdb->replace(
                                $table_property_states,
                                [
                                    'property_id' => $prev_prop_id,
                                    'state' => 'CANCELLED',
                                    'updated_by' => $user_id,
                                    'updated_at' => current_time('mysql')
                                ],
                                ['%s', '%s', '%s', '%s']
                            );
                        }
                    }
                }
            } else {
                // If becoming INTERIM/PENDING, revert previous TDs if they are no longer superseded
                $this->revert_cancelled_states($previous_tax_declaration_number, $id, $user_id);
            }
        }
        
        return ['success' => true, 'state' => strtoupper($state)];
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
        $table_property_states = $wpdb->prefix . 'assessor_property_states';
        while (!empty($queue)) {
            $current_number = array_shift($queue);
            if (!$current_number || isset($visited[$current_number])) {
                continue;
            }
            $visited[$current_number] = true;

            $matching_properties = $wpdb->get_results($wpdb->prepare(
                "SELECT 
                        p.id, p.tax_declaration_number, p.previous_tax_declaration_number, 
                        p.declarant_last_name, p.declarant_first_name, p.declarant_middle_initial,
                        p.business,
                        p.location, p.lot_number, p.unique_lot_number_identified, p.survey_number, p.area_hectare, p.area_hectare_old, p.area_sqm, p.title_number, p.effectivity_date,
                        p.assessed_value, p.assessed_value_old, p.kind_of_property, p.memoranda, p.supporting_documents, p.supporting_documents_old, p.pin, p.address, p.assessment_date, p.gen_class, p.created_at, p.updated_at,
                        p.verifier_signatory_name, p.verifier_signatory_title,
                        p.municipal_assessor_name, p.municipal_assessor_suffix, p.municipal_assessor_title, p.municipal_assessor_license,
                        c.full_name AS created_by_name,
                        u.full_name AS updated_by_name,
                        pt.name as kind_of_property_name,
                        gc.name as gen_class_name,
                        COALESCE(ps.state, 'CURRENT') as property_state
                 FROM $table_properties p
                 LEFT JOIN $table_users c ON p.created_by = c.id
                 LEFT JOIN $table_users u ON p.updated_by = u.id
                 LEFT JOIN $table_property_types pt ON p.kind_of_property = pt.code
                 LEFT JOIN $table_general_classes gc ON p.gen_class = gc.code
                 LEFT JOIN $table_property_states ps ON p.id = ps.property_id
                 WHERE p.tax_declaration_number = %s AND p.status != 'deleted' 
                 ORDER BY p.created_at DESC",
                $current_number
            ));

            if (empty($matching_properties)) {
                continue;
            }

            foreach ($matching_properties as $property) {
                $memoranda_value = $property->memoranda;
                if (empty($memoranda_value)) {
                    $memoranda_value = $wpdb->get_var($wpdb->prepare(
                        "SELECT memoranda FROM $table_versions WHERE property_id = %s ORDER BY version_number DESC LIMIT 1",
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
                    'property_state' => $property->property_state,
                    'business_name' => $business_name,
                    'location' => $property->location,
                    'lot_number' => $property->lot_number,
                    'unique_lot_number_identified' => isset($property->unique_lot_number_identified) ? $property->unique_lot_number_identified : '',
                    'survey_number' => isset($property->survey_number) && $property->survey_number !== '' ? $property->survey_number : (isset($property->unique_lot_number_identified) ? $property->unique_lot_number_identified : ''),
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
        }
        // Rewrite URLs for local build offline viewing
        if (defined('ASSESSOR_IS_LOCAL_BUILD') && ASSESSOR_IS_LOCAL_BUILD) {
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
            $current_host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
            $local_base = rtrim($protocol . $current_host, '/');
            $live_domain = defined('ASSESSOR_LIVE_SITE_URL') ? rtrim(ASSESSOR_LIVE_SITE_URL, '/') : '';
            
            foreach ($history as &$item) {
                if (!empty($item['supporting_documents'])) {
                    $item['supporting_documents'] = $this->rewrite_document_urls($item['supporting_documents'], $live_domain, $local_base);
                }
                if (!empty($item['supporting_documents_old'])) {
                    $item['supporting_documents_old'] = $this->rewrite_document_urls($item['supporting_documents_old'], $live_domain, $local_base);
                }
            }
            unset($item);
        }

        return $history;
    }

    /**
     * Walk forward along previous_tax_declaration_number links to the latest (current) TDN.
     */
    public function resolve_head_tax_declaration_number($tax_declaration_number) {
        global $wpdb;

        $table_properties = $wpdb->prefix . 'assessor_properties';
        $head_number = trim((string) $tax_declaration_number);
        if ($head_number === '') {
            return '';
        }

        $visited_forward = array();
        while ($head_number && !in_array($head_number, $visited_forward, true)) {
            $visited_forward[] = $head_number;
            $next_number = $wpdb->get_var($wpdb->prepare(
                "SELECT tax_declaration_number
                 FROM $table_properties
                 WHERE FIND_IN_SET(%s, REPLACE(previous_tax_declaration_number, ';', ',')) > 0
                   AND status != 'deleted'
                 ORDER BY created_at DESC
                 LIMIT 1",
                $head_number
            ));
            if (!$next_number) {
                break;
            }
            $head_number = $next_number;
        }

        return $head_number;
    }
    
    public function get_property_by_tax_number($tax_declaration_number, $resolve_to_current = false, $revision_id = null, $all_matches = false) {
        global $wpdb;

        if ($resolve_to_current) {
            $tax_declaration_number = $this->resolve_head_tax_declaration_number($tax_declaration_number);
            if ($tax_declaration_number === '') {
                return $all_matches ? array() : null;
            }
        }
        
        $table_properties = $wpdb->prefix . 'assessor_properties';
        $table_users = $wpdb->prefix . 'assessor_users';
        
        $table_property_types = $wpdb->prefix . 'assessor_property_types';
        $table_general_classes = $wpdb->prefix . 'assessor_general_classes';

        $where_rev = '';
        $query_params = array($tax_declaration_number);
        if (!empty($revision_id)) {
            $where_rev = ' AND p.revision_id = %s';
            $query_params[] = (string)$revision_id;
        }

        if ($all_matches) {
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
                WHERE p.tax_declaration_number = %s AND p.status != 'deleted' $where_rev
                ORDER BY p.created_at DESC
            ";
            return $wpdb->get_results($wpdb->prepare($query, $query_params));
        }

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
            WHERE p.tax_declaration_number = %s AND p.status != 'deleted' $where_rev
            ORDER BY p.created_at DESC
            LIMIT 1
        ";
        
        return $wpdb->get_row($wpdb->prepare($query, $query_params));
    }
    
    private function create_property_version($property_id, $property_data, $change_reason) {
        global $wpdb;
        
        $table_versions = $wpdb->prefix . 'assessor_property_versions';
        
        // Get next version number
        $current_version = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(version_number) FROM $table_versions WHERE property_id = %s",
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
                'survey_number' => isset($property_data->survey_number) ? $property_data->survey_number : $property_data->unique_lot_number_identified,
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
                'created_by' => $property_data->updated_by,
                'revision_id' => isset($property_data->revision_id) ? $property_data->revision_id : null
            )
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

    /**
     * Public API only (current_tdn_only): supports one search type at a time.
     * - q alone: name search, OR TD-only when q is one whole TD-shaped token.
     * - tax_declaration_number alone: TD chain -> current row(s).
     * - both q + tax_declaration_number: intentionally unsupported.
     */
    private function apply_public_property_search_where_block($params, &$where_conditions, &$where_values, $table_properties, array &$reserved) {
        global $wpdb;

        $q_raw = isset($params['q']) ? trim((string) $params['q']) : '';
        $tax_td = isset($params['tax_declaration_number']) ? trim((string) $params['tax_declaration_number']) : '';

        $q_ok = strlen($q_raw) >= 2;
        $tax_ok = strlen($tax_td) >= 2;

        $reserved['handled'] = false;

        if ($tax_ok && $q_ok) {
            // Disabled by public API validation; keep defensive no-results behavior here.
            $where_conditions[] = '1=0';
            $reserved['handled'] = true;
            return;
        }

        if ($tax_ok && !$q_ok) {
            $head_ids = $this->find_heads_for_td_token($tax_td, $table_properties);
            $this->append_where_property_id_in_clause($head_ids, $where_conditions, $where_values);
            $reserved['handled'] = true;
            return;
        }

        if ($q_ok && !$tax_ok) {
            if ($this->is_entire_string_td_token($q_raw)) {
                $head_ids = $this->find_heads_for_td_token($q_raw, $table_properties);
                $this->append_where_property_id_in_clause($head_ids, $where_conditions, $where_values);
            } else {
                $this->apply_public_name_only_search($q_raw, $where_conditions, $where_values);
            }
            $reserved['handled'] = true;
            return;
        }

        // no q/tax search terms here — let other filters (location, pin, …) apply
    }

    private function append_where_property_id_in_clause($head_ids, &$where_conditions, &$where_values) {
        $head_ids = array_values(array_unique(array_filter(array_map('strval', (array) $head_ids))));
        if (empty($head_ids)) {
            $where_conditions[] = '1=0';
            return;
        }
        $placeholders = implode(',', array_fill(0, count($head_ids), '%s'));
        $where_conditions[] = 'p.id IN (' . $placeholders . ')';
        foreach ($head_ids as $id) {
            $where_values[] = $id;
        }
    }

    private function is_entire_string_td_token($s) {
        $s = trim((string) $s);
        if (strlen($s) < 3) {
            return false;
        }

        return (bool) preg_match('/^([A-Za-z]-[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*|\d+(?:-\d+)+)$/u', $s);
    }

    /** Resolve one TD token to current head property id(s). */
    private function find_heads_for_td_token($td, $table_properties) {
        global $wpdb;

        $td_like = '%' . $wpdb->esc_like($td) . '%';
        $td_digits = preg_replace('/[^0-9]/', '', $td);
        $match = '('
            . 'p.tax_declaration_number = %s'
            . ' OR p.tax_declaration_number LIKE %s'
            . ' OR p.previous_tax_declaration_number LIKE %s'
            . ' OR FIND_IN_SET(%s, REPLACE(p.previous_tax_declaration_number, \';\', \',\')) > 0';
        $vals = array($td, $td_like, $td_like, $td);
        if ($td_digits !== '') {
            $match .= ' OR REPLACE(REPLACE(p.tax_declaration_number, \'-\', \'\'), \' \', \'\') = %s';
            $vals[] = $td_digits;
        }
        $match .= ')';

        $sql = "SELECT DISTINCT p.tax_declaration_number
                FROM $table_properties p
                WHERE p.status != 'deleted' AND $match";
        $tdns = $wpdb->get_col($wpdb->prepare($sql, $vals));

        $head_ids = array();
        foreach ($tdns as $tdn) {
            $head_tdn = $this->resolve_head_tax_declaration_number($tdn);
            if ($head_tdn === '') {
                continue;
            }
            $hids = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM $table_properties
                 WHERE tax_declaration_number = %s AND status != 'deleted'",
                $head_tdn
            ));
            if (!empty($hids)) {
                foreach ($hids as $hid) {
                    $head_ids[] = (string) $hid;
                }
            }
        }

        return array_values(array_unique($head_ids));
    }

    /** Name-only: current rows whose declarant / combined fields match whole phrase (no digit-only OR on full string). */
    private function apply_public_name_only_search($q_raw, &$where_conditions, &$where_values) {
        global $wpdb;

        $q = '%' . $wpdb->esc_like($q_raw) . '%';
        $blob = $this->get_property_search_blob_sql('p');

        $or_sql = array(
            $blob . ' LIKE %s',
            'p.declarant_last_name LIKE %s',
            'p.declarant_first_name LIKE %s',
            'p.business LIKE %s',
        );
        $or_vals = array_fill(0, count($or_sql), $q);

        $q_no_dot = str_replace('.', '', preg_replace('/\s+/', ' ', $q_raw));
        $or_sql[] = "CONCAT(p.declarant_last_name, ', ', p.declarant_first_name) LIKE %s";
        $or_vals[] = '%' . $wpdb->esc_like($q_no_dot) . '%';
        $or_sql[] = "CONCAT(p.declarant_first_name, ' ', p.declarant_last_name) LIKE %s";
        $or_vals[] = '%' . $wpdb->esc_like($q_no_dot) . '%';
        $or_sql[] = "CONCAT(p.declarant_last_name, ' ', p.declarant_first_name) LIKE %s";
        $or_vals[] = '%' . $wpdb->esc_like($q_no_dot) . '%';

        $where_conditions[] = '(' . implode(' OR ', $or_sql) . ')';
        $where_values = array_merge($where_values, $or_vals);
    }

    /** Combined searchable text for one property row (whole-query matching). */
    private function get_property_search_blob_sql($alias) {
        $a = preg_replace('/[^a-z_]/', '', $alias);
        if ($a === '') {
            $a = 'p';
        }

        return "CONCAT_WS(' ',
            COALESCE({$a}.tax_declaration_number, ''),
            COALESCE({$a}.previous_tax_declaration_number, ''),
            COALESCE({$a}.declarant_last_name, ''),
            COALESCE({$a}.declarant_first_name, ''),
            COALESCE({$a}.declarant_middle_initial, ''),
            COALESCE({$a}.business, ''),
            COALESCE({$a}.location, ''),
            COALESCE({$a}.lot_number, ''),
            COALESCE({$a}.title_number, ''),
            COALESCE({$a}.pin, '')
        )";
    }

    /** Staff webapp search: entire query string matched as one phrase (unchanged behavior). */
    private function apply_whole_string_q_search($q_raw, &$where_conditions, &$where_values) {
        global $wpdb;

        $q = '%' . $wpdb->esc_like($q_raw) . '%';
        $or_sql = array(
            'p.tax_declaration_number LIKE %s',
            'p.declarant_last_name LIKE %s',
            'p.declarant_first_name LIKE %s',
            'p.lot_number LIKE %s',
            'p.title_number LIKE %s',
            'p.business LIKE %s',
        );
        $or_vals = array_fill(0, count($or_sql), $q);

        $q_no_spaces = preg_replace('/\s+/', ' ', $q_raw);
        $q_no_dot = str_replace('.', '', $q_no_spaces);

        $or_sql[] = "CONCAT(p.declarant_last_name, ', ', p.declarant_first_name) LIKE %s";
        $or_vals[] = '%' . $wpdb->esc_like($q_no_dot) . '%';
        $or_sql[] = "CONCAT(p.declarant_last_name, ', ', p.declarant_first_name, ' ', COALESCE(p.declarant_middle_initial, '')) LIKE %s";
        $or_vals[] = '%' . $wpdb->esc_like($q_no_dot) . '%';
        $or_sql[] = "CONCAT(p.declarant_first_name, ' ', p.declarant_last_name) LIKE %s";
        $or_vals[] = '%' . $wpdb->esc_like($q_no_dot) . '%';
        $or_sql[] = "CONCAT(p.declarant_first_name, ' ', COALESCE(p.declarant_middle_initial, ''), ' ', p.declarant_last_name) LIKE %s";
        $or_vals[] = '%' . $wpdb->esc_like($q_no_dot) . '%';
        $or_sql[] = "CONCAT(p.declarant_last_name, ' ', p.declarant_first_name) LIKE %s";
        $or_vals[] = '%' . $wpdb->esc_like($q_no_dot) . '%';

        $q_digits_raw = preg_replace('/[^0-9]/', '', $q_raw);
        if ($q_digits_raw !== '') {
            $or_sql[] = "REPLACE(REPLACE(p.tax_declaration_number, '-', ''), ' ', '') LIKE %s";
            $or_vals[] = '%' . $wpdb->esc_like($q_digits_raw) . '%';
        }

        $where_conditions[] = '(' . implode(' OR ', $or_sql) . ')';
        $where_values = array_merge($where_values, $or_vals);
    }

    /**
     * Safely rewrites relative URLs or live server URLs to use the current host's URL.
     */
    private function rewrite_document_urls($json_or_string, $live_domain, $local_base) {
        if (empty($json_or_string)) {
            return $json_or_string;
        }

        $decoded = json_decode($json_or_string, true);
        
        $rewrite_single = function($url) use ($local_base) {
            $url = trim($url);
            if (empty($url)) return $url;
            
            // If it starts with /wp-content, prepend local_base
            if (strpos($url, '/wp-content/uploads/') === 0) {
                return $local_base . $url;
            }
            
            // If it's an absolute URL containing /wp-content/uploads/, replace the domain
            if (preg_match('/^https?:\/\/[^\/]+(\/wp-content\/uploads\/.*)$/i', $url, $matches)) {
                return $local_base . $matches[1];
            }
            
            return $url;
        };
        
        if (is_array($decoded)) {
            // It's a valid JSON array of URLs
            foreach ($decoded as &$url) {
                $url = $rewrite_single($url);
            }
            // Use wp_json_encode but preserve slashes if possible
            return wp_json_encode($decoded, JSON_UNESCAPED_SLASHES);
        } else {
            // It's a pipe-separated string or just a raw URL string
            $parts = explode('|', $json_or_string);
            foreach ($parts as &$part) {
                $part = $rewrite_single($part);
            }
            return implode('|', $parts);
        }
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

    /**
     * Reverts the state of previously cancelled properties back to CURRENT
     * if they are no longer superseded by any active property.
     */
    private function auto_cancel_if_superseded($property_id, $tax_declaration_number, $user_id) {
        global $wpdb;
        if (empty($tax_declaration_number) || empty($property_id)) return;
        
        $is_superseded = $wpdb->get_var($wpdb->prepare(
            "SELECT p.id FROM {$wpdb->prefix}assessor_properties p
             LEFT JOIN {$wpdb->prefix}assessor_property_states ps ON p.id = ps.property_id
             WHERE FIND_IN_SET(%s, REPLACE(p.previous_tax_declaration_number, ';', ',')) > 0
             AND p.status != 'deleted' AND p.id != %s 
             AND COALESCE(ps.state, 'CURRENT') IN ('CURRENT', 'CANCELLED') LIMIT 1",
            $tax_declaration_number,
            $property_id
        ));

        if ($is_superseded) {
            $wpdb->replace(
                "{$wpdb->prefix}assessor_property_states",
                [
                    'property_id' => $property_id,
                    'state' => 'CANCELLED',
                    'updated_by' => $user_id,
                    'updated_at' => current_time('mysql')
                ],
                ['%s', '%s', '%s', '%s']
            );
        }
    }

    private function revert_cancelled_states($previous_tdns, $deleted_property_id, $user_id) {
        global $wpdb;
        $table_properties = $wpdb->prefix . 'assessor_properties';
        $table_property_states = $wpdb->prefix . 'assessor_property_states';

        if (empty($previous_tdns)) {
            return;
        }

        $tdn_list = array_map('trim', explode(';', $previous_tdns));
        foreach ($tdn_list as $tdn) {
            if (empty($tdn)) continue;

            // Check if ANY other property supersedes this TDN
            // We use FIND_IN_SET. Also exclude the currently deleted/updated property ID just in case
            $other_superseding_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT p.id FROM $table_properties p
                 LEFT JOIN $table_property_states ps ON p.id = ps.property_id
                 WHERE p.id != %s AND p.status != 'deleted' 
                 AND COALESCE(ps.state, 'CURRENT') IN ('CURRENT', 'CANCELLED')
                 AND FIND_IN_SET(%s, REPLACE(p.previous_tax_declaration_number, ';', ',')) > 0 LIMIT 1",
                $deleted_property_id,
                $tdn
            ));

            if (!$other_superseding_exists) {
                // Find all property IDs by this TDN to revert them all back to CURRENT
                $prev_prop_ids = $wpdb->get_col($wpdb->prepare(
                    "SELECT id FROM $table_properties WHERE tax_declaration_number = %s AND status != 'deleted'",
                    $tdn
                ));

                if (!empty($prev_prop_ids)) {
                    foreach ($prev_prop_ids as $prev_prop_id) {
                        $wpdb->replace(
                            $table_property_states,
                            [
                                'property_id' => $prev_prop_id,
                                'state' => 'CURRENT',
                                'updated_by' => $user_id,
                                'updated_at' => current_time('mysql')
                            ],
                            ['%s', '%s', '%s', '%s']
                        );
                    }
                }
            }
        }
    }

    /**
     * Resolve exactly one active revision for a given effectivity_date string.
     *
     * Rules:
     * - Date must contain a valid 4-digit year.
     * - Must match exactly one active revision where from_year <= year <= to_year ('present' treated as open-ended).
     * - If client provides $client_revision_id, validates that it matches the resolved revision.
     *
     * @param string $effectivity_date
     * @param string|null $client_revision_id
     * @return string|WP_Error Returns the resolved revision UUID (VARCHAR 36) or WP_Error on failure.
     */
    public function resolve_revision_by_effectivity_date($effectivity_date, $client_revision_id = null) {
        global $wpdb;

        $raw_date = trim((string)$effectivity_date);
        if ($raw_date === '') {
            return new WP_Error('invalid_effectivity_date', 'Effectivity date is required and cannot be empty.', array('status' => 400));
        }

        // Determine the effectivity year (4-digit year format)
        if (!preg_match('/^(\d{4})$/', $raw_date, $matches)) {
            return new WP_Error(
                'malformed_effectivity_date',
                sprintf("Malformed or unusable effectivity date '%s'. A valid 4-digit year is required.", $raw_date),
                array('status' => 400)
            );
        }

        $year = intval($matches[1]);
        $table_revisions = $wpdb->prefix . 'assessor_revision_entries';
        $active_revisions = $wpdb->get_results("SELECT id, revision_code, revision_year, from_year, to_year FROM $table_revisions WHERE status = 'active'", ARRAY_A);

        if (empty($active_revisions)) {
            return new WP_Error('no_active_revisions', 'No active property revisions found in system.', array('status' => 500));
        }

        $matching_revisions = array();
        foreach ($active_revisions as $rev) {
            $from = intval($rev['from_year']);
            $to = (strtolower(trim($rev['to_year'])) === 'present') ? 9999 : intval($rev['to_year']);

            if ($year >= $from && $year <= $to) {
                $matching_revisions[] = $rev;
            }
        }

        if (count($matching_revisions) === 0) {
            return new WP_Error(
                'no_matching_revision',
                sprintf("No active revision found covering effectivity year %d.", $year),
                array('status' => 400)
            );
        }

        if (count($matching_revisions) > 1) {
            $codes = implode(', ', array_column($matching_revisions, 'revision_code'));
            return new WP_Error(
                'multiple_matching_revisions',
                sprintf("Ambiguous revision: multiple active revisions match year %d (%s).", $year, $codes),
                array('status' => 400)
            );
        }

        $resolved_revision = $matching_revisions[0];
        $resolved_uuid = $resolved_revision['id'];

        // If client provided a revision UUID, validate that it matches the authoritative server resolution
        if (!empty($client_revision_id)) {
            $client_id_clean = trim((string)$client_revision_id);
            // Check if client passed the UUID or revision_code
            if (strcasecmp($client_id_clean, $resolved_uuid) !== 0 && strcasecmp($client_id_clean, $resolved_revision['revision_code']) !== 0) {
                return new WP_Error(
                    'revision_mismatch',
                    sprintf(
                        "Client-supplied revision '%s' does not match the authoritative revision '%s' (%s) for effectivity year %d.",
                        $client_id_clean,
                        $resolved_revision['revision_code'],
                        $resolved_uuid,
                        $year
                    ),
                    array('status' => 400)
                );
            }
        }

        return $resolved_uuid;
    }

    /**
     * Check if a tax declaration number already exists within the same active revision.
     *
     * Uniqueness rule:
     * - (tax_declaration_number + revision_id) must be unique among active/non-deleted properties.
     * - TDN X in Revision A is allowed even if TDN X exists in Revision B.
     * - On update, $exclude_property_id (UUID string) is excluded from duplicate checks.
     *
     * @param string $tax_declaration_number
     * @param string|null $revision_id
     * @param string|null $exclude_property_id
     * @return bool True if duplicate exists in same revision, false otherwise.
     */
    public function is_tdn_duplicate_in_revision($tax_declaration_number, $revision_id, $exclude_property_id = null) {
        global $wpdb;

        $tdn = trim((string)$tax_declaration_number);
        if ($tdn === '') {
            return false;
        }

        $table_properties = $wpdb->prefix . 'assessor_properties';
        $where_sql = "WHERE tax_declaration_number = %s AND status != 'deleted'";
        $params = array($tdn);

        if (!empty($revision_id)) {
            $where_sql .= " AND revision_id = %s";
            $params[] = $revision_id;
        } else {
            $where_sql .= " AND revision_id IS NULL";
        }

        if (!empty($exclude_property_id)) {
            $where_sql .= " AND id != %s";
            $params[] = (string)$exclude_property_id;
        }

        $query = "SELECT id FROM $table_properties $where_sql LIMIT 1";
        $existing_id = $wpdb->get_var($wpdb->prepare($query, $params));

        return !empty($existing_id);
    }
}

