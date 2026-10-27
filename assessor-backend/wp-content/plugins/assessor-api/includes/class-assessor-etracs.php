<?php

/**
 * Assessor ETRACS Properties Module
 * 
 * Manages ETRACS-modeled FAAS (Field Appraisal & Assessment Sheet) records
 * with separate Entity, Real Property, and RPU tables following the
 * ETRACS 2.5.4 database structure.
 */
class Assessor_Etracs {

    // ──────────────────────────────────────────────
    // SHADOW ETRACS AUDIT TRAIL (WP-07)
    // ──────────────────────────────────────────────

    /**
     * Resolve actor user ID and display name snapshot safely from request/token/environment.
     *
     * @param WP_REST_Request|array|null $request
     * @param string|null $fallback_user_id
     * @return array array($user_id, $actor_name)
     */
    public static function resolve_actor($request = null, $fallback_user_id = null) {
        global $wpdb;

        $user_id = $fallback_user_id;
        $actor_name = null;

        if ($request && is_object($request) && method_exists($request, 'get_headers')) {
            if (class_exists('Assessor_Auth')) {
                $auth = new Assessor_Auth();
                $extracted = $auth->get_user_id_from_token($request);
                if ($extracted) {
                    $user_id = $extracted;
                }
            }
            if (empty($user_id) && method_exists($request, 'get_param')) {
                $param_uid = $request->get_param('user_id');
                if (!empty($param_uid)) {
                    $user_id = $param_uid;
                }
            }
        } elseif (is_array($request) && !empty($request['user_id'])) {
            $user_id = $request['user_id'];
        }

        if ($user_id) {
            $table_users = $wpdb->prefix . 'assessor_users';
            $user_row = $wpdb->get_row($wpdb->prepare(
                "SELECT username, full_name FROM $table_users WHERE id = %s",
                $user_id
            ));
            if ($user_row) {
                $actor_name = !empty($user_row->full_name) ? $user_row->full_name : $user_row->username;
            } else {
                $actor_name = 'User ' . $user_id;
            }
        }

        if (empty($user_id)) {
            $user_id = 'SYSTEM';
            $actor_name = 'System Process';
        }

        return array($user_id, $actor_name);
    }

    /**
     * Log a Shadow ETRACS audit event.
     *
     * Executes within the caller's active database transaction (if any) so the audit
     * record commits or rolls back atomically with the business mutation.
     *
     * Redacts credentials, tokens, and passwords. Sanitizes before/after values to
     * minimize sensitive information footprint.
     *
     * @param array $entry
     * @return string|false Event ID on success, false on failure
     */
    public static function log_shadow_audit($entry) {
        global $wpdb;

        $t_audit = $wpdb->prefix . 'assessor_shadow_audit';

        // Check if table exists
        $table_exists = ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t_audit)) === $t_audit);
        if (!$table_exists) {
            return false;
        }

        $event_id = !empty($entry['event_id']) ? $entry['event_id'] : wp_generate_uuid4();
        $correlation_id = !empty($entry['correlation_id']) ? $entry['correlation_id'] : (!empty($entry['request_id']) ? $entry['request_id'] : wp_generate_uuid4());

        // Resolve actor if not explicitly provided
        $user_id = $entry['user_id'] ?? null;
        $actor_name = $entry['actor_name'] ?? null;
        if (empty($user_id) || empty($actor_name)) {
            list($resolved_uid, $resolved_name) = self::resolve_actor($entry['request'] ?? null, $user_id);
            if (empty($user_id)) $user_id = $resolved_uid;
            if (empty($actor_name)) $actor_name = $resolved_name;
        }

        // Changed fields formatting
        $changed_fields_raw = $entry['changed_fields'] ?? array();
        $changed_fields_json = is_array($changed_fields_raw) ? json_encode(array_values($changed_fields_raw)) : (is_string($changed_fields_raw) ? $changed_fields_raw : null);

        // Sanitize and redact details payload
        $details_raw = $entry['details'] ?? null;
        if (is_array($details_raw)) {
            // Remove sensitive fields
            $redact_keys = array('password', 'token', 'auth', 'secret', 'api_key', 'jwt', 'credentials');
            foreach ($details_raw as $k => $v) {
                foreach ($redact_keys as $rk) {
                    if (stripos($k, $rk) !== false) {
                        $details_raw[$k] = '[REDACTED]';
                    }
                }
            }
            $details_json = json_encode($details_raw);
        } elseif (is_string($details_raw)) {
            $details_json = $details_raw;
        } else {
            $details_json = null;
        }

        // IP address
        $ip_address = $entry['ip_address'] ?? null;
        if (empty($ip_address) && !empty($_SERVER['REMOTE_ADDR'])) {
            $ip_address = sanitize_text_field($_SERVER['REMOTE_ADDR']);
        }

        // Establish explicit UTC storage convention: MySQL DATETIME stored strictly in UTC.
        // If created_at or event_timestamp was provided, normalize to UTC string. Otherwise use gmdate.
        $raw_input_ts = !empty($entry['event_timestamp']) ? $entry['event_timestamp'] : (!empty($entry['created_at']) ? $entry['created_at'] : null);
        if ($raw_input_ts) {
            $parsed_ts = strtotime($raw_input_ts);
            $utc_datetime = ($parsed_ts !== false) ? gmdate('Y-m-d H:i:s', $parsed_ts) : gmdate('Y-m-d H:i:s');
        } else {
            $utc_datetime = gmdate('Y-m-d H:i:s');
        }

        $data = array(
            'event_id'              => $event_id,
            'created_at'            => $utc_datetime,
            'user_id'               => $user_id,
            'actor_name'            => $actor_name,
            'event_type'            => sanitize_text_field($entry['event_type'] ?? 'UNKNOWN'),
            'action_outcome'        => sanitize_text_field($entry['action_outcome'] ?? 'SUCCESS'),
            'source_classification' => sanitize_text_field($entry['source_classification'] ?? 'LOCAL_AUTHORING'),
            'origin_type'           => (!empty($entry['origin_type']) && strtoupper($entry['origin_type']) === 'REPLICA') ? 'REPLICA' : 'LOCAL',
            'object_type'           => sanitize_text_field($entry['object_type'] ?? 'FAAS'),
            'object_id'             => sanitize_text_field($entry['object_id'] ?? ''),
            'faas_id'               => !empty($entry['faas_id']) ? sanitize_text_field($entry['faas_id']) : null,
            'rpu_id'                => !empty($entry['rpu_id']) ? sanitize_text_field($entry['rpu_id']) : null,
            'real_property_id'      => !empty($entry['real_property_id']) ? sanitize_text_field($entry['real_property_id']) : null,
            'rpumaster_id'          => !empty($entry['rpumaster_id']) ? sanitize_text_field($entry['rpumaster_id']) : null,
            'old_state'             => !empty($entry['old_state']) ? sanitize_text_field($entry['old_state']) : null,
            'new_state'             => !empty($entry['new_state']) ? sanitize_text_field($entry['new_state']) : null,
            'changed_fields'        => $changed_fields_json,
            'details'               => $details_json,
            'correlation_id'        => $correlation_id,
            'ip_address'            => $ip_address,
        );

        $res = $wpdb->insert($t_audit, $data);
        if ($res === false) {
            return false;
        }

        return $event_id;
    }

    /**
     * Query Shadow ETRACS audit events.
     *
     * Supports filtering by faas_id, object_type, object_id, event_type,
     * source_classification, origin_type, correlation_id, and date ranges.
     *
     * @param WP_REST_Request|array $request
     * @return WP_REST_Response
     */
    public function get_shadow_audit_trail($request) {
        global $wpdb;

        $params = is_array($request) ? $request : (is_object($request) && method_exists($request, 'get_params') ? $request->get_params() : array());
        $t_audit = $wpdb->prefix . 'assessor_shadow_audit';

        // Check if table exists
        $table_exists = ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t_audit)) === $t_audit);
        if (!$table_exists) {
            return rest_ensure_response(array(
                'data'        => array(),
                'total'       => 0,
                'page'        => 1,
                'per_page'    => 20,
                'total_pages' => 0,
            ));
        }

        $page = isset($params['page']) ? max(1, intval($params['page'])) : 1;
        $per_page = isset($params['per_page']) ? min(100, max(1, intval($params['per_page']))) : 20;
        $offset = ($page - 1) * $per_page;

        $where = array("1=1");
        $values = array();

        if (!empty($params['faas_id'])) {
            $where[] = "faas_id = %s";
            $values[] = sanitize_text_field($params['faas_id']);
        }
        if (!empty($params['object_id'])) {
            $where[] = "object_id = %s";
            $values[] = sanitize_text_field($params['object_id']);
        }
        if (!empty($params['object_type'])) {
            $where[] = "object_type = %s";
            $values[] = sanitize_text_field($params['object_type']);
        }
        if (!empty($params['event_type'])) {
            $where[] = "event_type = %s";
            $values[] = sanitize_text_field($params['event_type']);
        }
        if (!empty($params['source_classification'])) {
            $where[] = "source_classification = %s";
            $values[] = sanitize_text_field($params['source_classification']);
        }
        if (!empty($params['origin_type'])) {
            $where[] = "origin_type = %s";
            $values[] = sanitize_text_field($params['origin_type']);
        }
        if (!empty($params['correlation_id'])) {
            $where[] = "correlation_id = %s";
            $values[] = sanitize_text_field($params['correlation_id']);
        }
        if (!empty($params['user_id'])) {
            $where[] = "user_id = %s";
            $values[] = sanitize_text_field($params['user_id']);
        }
        if (!empty($params['date_from'])) {
            $where[] = "created_at >= %s";
            $values[] = sanitize_text_field($params['date_from']);
        }
        if (!empty($params['date_to'])) {
            $where[] = "created_at <= %s";
            $values[] = sanitize_text_field($params['date_to']);
        }

        $where_sql = implode(' AND ', $where);
        $count_sql = "SELECT COUNT(*) FROM `$t_audit` WHERE $where_sql";
        $total = !empty($values) ? $wpdb->get_var($wpdb->prepare($count_sql, $values)) : $wpdb->get_var($count_sql);

        $sql = "SELECT * FROM `$t_audit` WHERE $where_sql ORDER BY id DESC LIMIT %d OFFSET %d";
        $query_values = array_merge($values, array($per_page, $offset));
        $rows = $wpdb->get_results($wpdb->prepare($sql, $query_values), ARRAY_A);

        // Decode JSON columns for cleaner API consumption and format ISO-8601 UTC timestamps
        $data = array();
        if (!empty($rows)) {
            foreach ($rows as $row) {
                if (!empty($row['changed_fields'])) {
                    $decoded_fields = json_decode($row['changed_fields'], true);
                    $row['changed_fields'] = ($decoded_fields !== null) ? $decoded_fields : $row['changed_fields'];
                }
                if (!empty($row['details'])) {
                    $decoded_details = json_decode($row['details'], true);
                    $row['details'] = ($decoded_details !== null) ? $decoded_details : $row['details'];
                }
                // Ensure unambiguous UTC timestamp serialization (ISO-8601 with trailing 'Z')
                if (!empty($row['created_at'])) {
                    $ts = strtotime($row['created_at'] . ' UTC');
                    $row['created_at_utc'] = gmdate('Y-m-d\TH:i:s\Z', $ts);
                }
                // Expose event_timestamp as documented alias with explicit UTC indicator
                $raw_event_ts = !empty($row['event_timestamp']) ? $row['event_timestamp'] : (!empty($row['created_at']) ? $row['created_at'] : null);
                if ($raw_event_ts) {
                    $row['event_timestamp'] = $raw_event_ts;
                    $row['event_timestamp_utc'] = gmdate('Y-m-d\TH:i:s\Z', strtotime($raw_event_ts . ' UTC'));
                }
                $data[] = $row;
            }
        }

        return rest_ensure_response(array(
            'data'        => $data,
            'total'       => intval($total),
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => ceil(intval($total) / $per_page),
        ));
    }

    // ──────────────────────────────────────────────
    // ENTITIES (Taxpayer / Owner)
    // ──────────────────────────────────────────────

    public function get_entities($request) {
        global $wpdb;
        $params = $request->get_params();
        $table = $wpdb->prefix . 'assessor_entity';

        $page = isset($params['page']) ? max(1, intval($params['page'])) : 1;
        $per_page = isset($params['per_page']) ? min(100, max(1, intval($params['per_page']))) : 20;
        $offset = ($page - 1) * $per_page;

        $where = array("1=1");
        $values = array();

        if (!empty($params['q'])) {
            $like = '%' . $wpdb->esc_like($params['q']) . '%';
            $where[] = "(e.name LIKE %s OR e.address_text LIKE %s)";
            $values[] = $like;
            $values[] = $like;
        }

        $where_sql = implode(' AND ', $where);
        $count_query = "SELECT COUNT(*) FROM $table e WHERE $where_sql";
        $total = !empty($values) ? $wpdb->get_var($wpdb->prepare($count_query, $values)) : $wpdb->get_var($count_query);

        $sql = "SELECT e.*, e.name AS entity_name, e.address_text AS entity_address, e.type AS entity_type FROM $table e WHERE $where_sql ORDER BY e.name ASC LIMIT %d OFFSET %d";
        $query_values = array_merge($values, array($per_page, $offset));
        $results = $wpdb->get_results($wpdb->prepare($sql, $query_values));

        return rest_ensure_response(array(
            'data' => $results,
            'total' => intval($total),
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => ceil(intval($total) / $per_page)
        ));
    }

    public function create_entity($request) {
        global $wpdb;
        $params = $request->get_params();
        $table = $wpdb->prefix . 'assessor_entity';

        if (empty($params['entity_name'])) {
            return new WP_Error('missing_field', "Field 'entity_name' is required", array('status' => 400));
        }

        $objid = wp_generate_uuid4();
        
        $data = array(
            'objid' => $objid,
            'entityno' => 'ENT-' . time(), // Basic generation for now
            'name' => sanitize_text_field($params['entity_name']),
            'entityname' => sanitize_text_field($params['entity_name']),
            'address_text' => sanitize_textarea_field($params['entity_address'] ?? ''),
            'type' => sanitize_text_field($params['entity_type'] ?? 'INDIVIDUAL'),
        );

        $result = $wpdb->insert($table, $data);
        if ($result === false) {
            return new WP_Error('insert_failed', 'Failed to create entity', array('status' => 500));
        }

        $entity = $wpdb->get_row($wpdb->prepare("SELECT e.*, e.name AS entity_name, e.address_text AS entity_address, e.type AS entity_type FROM $table e WHERE objid = %s", $objid));
        return rest_ensure_response($entity);
    }

    public function update_entity($id, $request) {
        global $wpdb;
        $params = $request->get_params();
        $table = $wpdb->prefix . 'assessor_entity';

        $data = array();
        if (isset($params['entity_name'])) {
            $data['name'] = sanitize_text_field($params['entity_name']);
            $data['entityname'] = sanitize_text_field($params['entity_name']);
        }
        if (isset($params['entity_address'])) {
            $data['address_text'] = sanitize_textarea_field($params['entity_address']);
        }
        if (isset($params['entity_type'])) {
            $data['type'] = sanitize_text_field($params['entity_type']);
        }

        if (empty($data)) {
            return new WP_Error('no_data', 'No data provided to update', array('status' => 400));
        }

        $wpdb->update($table, $data, array('objid' => $id));
        $entity = $wpdb->get_row($wpdb->prepare("SELECT e.*, e.name AS entity_name, e.address_text AS entity_address, e.type AS entity_type FROM $table e WHERE objid = %s", $id));
        return rest_ensure_response($entity);
    }

    public function delete_entity($id, $request) {
        global $wpdb;
        $table = $wpdb->prefix . 'assessor_entity';

        // Check if entity is used in any FAAS record
        $faas_table = $wpdb->prefix . 'assessor_faas';
        $in_use = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $faas_table WHERE taxpayer_objid = %s AND state != 'DELETED'", $id));
        
        if ($in_use > 0) {
            return new WP_Error('entity_in_use', 'Cannot delete entity because it is used in active FAAS records', array('status' => 400));
        }

        $wpdb->delete($table, array('objid' => $id), array('%s'));
        return rest_ensure_response(array('success' => true, 'message' => 'Entity deleted successfully'));
    }

    // ──────────────────────────────────────────────
    // TRANSACTION TYPES
    // ──────────────────────────────────────────────

    public function get_transaction_types($request) {
        global $wpdb;
        $table = $wpdb->prefix . 'assessor_faas_txntypes';
        $results = $wpdb->get_results("SELECT * FROM $table WHERE status = 'active' ORDER BY sort_order ASC, name ASC");
        return rest_ensure_response($results);
    }

    // ──────────────────────────────────────────────
    // FAAS LISTING (main list view)
    // ──────────────────────────────────────────────

    public function get_faas_list($request) {
        global $wpdb;
        $params = $request->get_params();

        $t_faas = $wpdb->prefix . 'assessor_faas';
        $t_rpu = $wpdb->prefix . 'assessor_rpu';
        $t_rp = $wpdb->prefix . 'assessor_real_property';
        $t_entity = $wpdb->prefix . 'assessor_entity';
        $t_txn = $wpdb->prefix . 'assessor_faas_txntypes';

        $page = isset($params['page']) ? max(1, intval($params['page'])) : 1;
        $per_page = isset($params['per_page']) ? min(100, max(1, intval($params['per_page']))) : 20;
        $offset = ($page - 1) * $per_page;

        $where = array("f.state != 'DELETED'");
        $values = array();

        // Free-text search
        if (!empty($params['q'])) {
            $like = '%' . $wpdb->esc_like($params['q']) . '%';
            $where[] = "(f.tdno LIKE %s OR f.owner_name LIKE %s OR e.name LIKE %s OR rp.cadastrallotno LIKE %s OR f.fullpin LIKE %s)";
            $values = array_merge($values, array($like, $like, $like, $like, $like));
        }

        // Effective state expression:
        // A record whose raw state is 'CURRENT' but has an approved successor (state IN 'CURRENT', 'CANCELLED')
        // is effectively 'CANCELLED' locally, preserving raw source-owned replica state.
        $effective_state_expr = "CASE WHEN f.state = 'CURRENT' AND succ_link.succ_tdno IS NOT NULL THEN 'CANCELLED' ELSE f.state END";

        // Specific filters
        if (!empty($params['state'])) {
            $where[] = "$effective_state_expr = %s";
            $values[] = strtoupper($params['state']);
        }
        if (!empty($params['rpu_type'])) {
            $where[] = "r.rpu_type = %s";
            $values[] = strtoupper($params['rpu_type']);
        }
        if (!empty($params['txntype'])) {
            $where[] = "f.txntype_objid = %s";
            $values[] = $params['txntype'];
        }
        if (!empty($params['barangay'])) {
            $where[] = "rp.barangay LIKE %s";
            $values[] = '%' . $wpdb->esc_like($params['barangay']) . '%';
        }
        if (!empty($params['classification'])) {
            $where[] = "r.classification = %s";
            $values[] = $params['classification'];
        }
        if (!empty($params['tdno'])) {
            $where[] = "f.tdno LIKE %s";
            $values[] = '%' . $wpdb->esc_like($params['tdno']) . '%';
        }
        if (!empty($params['owner_name'])) {
            $where[] = "(f.owner_name LIKE %s OR e.name LIKE %s)";
            $like = '%' . $wpdb->esc_like($params['owner_name']) . '%';
            $values[] = $like;
            $values[] = $like;
        }

        $where_sql = implode(' AND ', $where);

        $t_rpu_assessment = $wpdb->prefix . 'assessor_rpu_assessment';
        $t_faas_previous = $wpdb->prefix . 'assessor_faas_previous';
        $t_la = $wpdb->prefix . 'assessor_landassesslevel';
        $t_ba = $wpdb->prefix . 'assessor_bldgassesslevel';
        $t_ma = $wpdb->prefix . 'assessor_machassesslevel';
        $t_pa = $wpdb->prefix . 'assessor_planttreeassesslevel';
        $t_mia = $wpdb->prefix . 'assessor_miscassesslevel';

        $joins = "
            LEFT JOIN $t_rpu r ON f.rpuid = r.objid
            LEFT JOIN $t_rp rp ON f.realpropertyid = rp.objid
            LEFT JOIN $t_entity e ON f.taxpayer_objid = e.objid
            LEFT JOIN $t_txn tx ON f.txntype_objid = tx.code
            LEFT JOIN (
                SELECT 
                    ra.rpuid, 
                    GROUP_CONCAT(DISTINCT COALESCE(la.name, ba.name, ma.name, pa.name, mia.name) SEPARATOR ', ') as actualuse 
                FROM $t_rpu_assessment ra
                LEFT JOIN $t_la la ON LOWER(ra.rputype) = 'land' AND la.objid = ra.actualuse_objid
                LEFT JOIN $t_ba ba ON LOWER(ra.rputype) = 'bldg' AND ba.objid = ra.actualuse_objid
                LEFT JOIN $t_ma ma ON LOWER(ra.rputype) = 'mach' AND ma.objid = ra.actualuse_objid
                LEFT JOIN $t_pa pa ON LOWER(ra.rputype) = 'planttree' AND pa.objid = ra.actualuse_objid
                LEFT JOIN $t_mia mia ON LOWER(ra.rputype) = 'misc' AND mia.objid = ra.actualuse_objid
                GROUP BY ra.rpuid
            ) ra ON r.objid = ra.rpuid
            LEFT JOIN (
                SELECT 
                    faasid,
                    GROUP_CONCAT(prevtdno SEPARATOR ' | ') as fp_prevtdno,
                    GROUP_CONCAT(prevpin SEPARATOR ' | ') as fp_prevpin,
                    GROUP_CONCAT(prevowner SEPARATOR ' | ') as fp_prevowner,
                    GROUP_CONCAT(prevav SEPARATOR ' | ') as fp_prevav,
                    GROUP_CONCAT(prevmv SEPARATOR ' | ') as fp_prevmv,
                    GROUP_CONCAT(prevareasqm SEPARATOR ' | ') as fp_prevareasqm,
                    GROUP_CONCAT(prevareaha SEPARATOR ' | ') as fp_prevareaha,
                    GROUP_CONCAT(prevadministrator SEPARATOR ' | ') as fp_prevadministrator
                FROM $t_faas_previous
                GROUP BY faasid
            ) fp ON f.objid = fp.faasid
            LEFT JOIN (
                SELECT 
                    COALESCE(f_pred_id.objid, f_pred_td.objid) AS canonical_pred_id,
                    GROUP_CONCAT(DISTINCT f_succ.tdno ORDER BY f_succ.dtapproved ASC, f_succ.tdno ASC SEPARATOR ', ') AS succ_tdno,
                    GROUP_CONCAT(DISTINCT f_succ.fullpin ORDER BY f_succ.dtapproved ASC, f_succ.tdno ASC SEPARATOR ', ') AS succ_pin,
                    MIN(f_succ.dtapproved) AS succ_dtapproved,
                    CAST(SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(f_succ.year, YEAR(f_succ.dtapproved)) ORDER BY f_succ.dtapproved ASC, f_succ.tdno ASC SEPARATOR ','), ',', 1) AS UNSIGNED) AS succ_year
                FROM $t_faas_previous fp_sub
                INNER JOIN $t_faas f_succ ON fp_sub.faasid = f_succ.objid
                LEFT JOIN $t_faas f_pred_id ON (fp_sub.prevfaasid IS NOT NULL AND fp_sub.prevfaasid != '' AND fp_sub.prevfaasid = f_pred_id.objid)
                LEFT JOIN (
                    SELECT tdno, MIN(objid) AS objid
                    FROM $t_faas
                    WHERE tdno IS NOT NULL AND tdno != ''
                    GROUP BY tdno
                    HAVING COUNT(*) = 1
                ) f_pred_td ON (f_pred_id.objid IS NULL AND fp_sub.prevtdno IS NOT NULL AND fp_sub.prevtdno != '' AND fp_sub.prevtdno = f_pred_td.tdno)
                WHERE f_succ.state IN ('CURRENT', 'CANCELLED')
                  AND COALESCE(f_pred_id.objid, f_pred_td.objid) IS NOT NULL
                GROUP BY COALESCE(f_pred_id.objid, f_pred_td.objid)
            ) succ_link ON (f.state = 'CURRENT' AND f.objid = succ_link.canonical_pred_id)
        ";

        // Count
        $count_sql = "SELECT COUNT(*) FROM $t_faas f $joins WHERE $where_sql";
        $total = !empty($values) ? $wpdb->get_var($wpdb->prepare($count_sql, $values)) : $wpdb->get_var($count_sql);

        // Build ORDER BY
        $order_by = 'f.txntimestamp DESC';
        if (!empty($params['order_by'])) {
            $allowed = array('tdno', 'owner_name', 'total_assessed_value', 'created_at', 'effectivity_year', 'txntimestamp');
            $field = $params['order_by'];
            if ($field === 'created_at') $field = 'txntimestamp';
            
            if (in_array($field, $allowed)) {
                $dir = (!empty($params['order_direction']) && strtoupper($params['order_direction']) === 'ASC') ? 'ASC' : 'DESC';
                if ($field === 'total_assessed_value') {
                    $order_by = "r.$field $dir";
                } else {
                    $order_by = "f.$field $dir";
                }
            }
        }

        $select_sql = "
            SELECT
                f.*,
                f.objid AS id,
                f.rpuid AS rpu_id,
                f.realpropertyid AS real_property_id,
                f.taxpayer_objid AS taxpayer_id,
                f.effectivityyear AS effectivity_year,
                f.effectivityqtr AS effectivity_qtr,
                f.txntype_objid AS txntype_code,
                f.titletype AS title_type,
                f.titleno AS title_no,
                f.titledate AS title_date,
                r.rpu_type,
                r.classification,
                r.total_market_value,
                r.total_assessed_value,
                r.ry AS revision_year,
                rp.pin AS rp_pin,
                rp.cadastrallotno AS cadastral_lot_no,
                rp.surveyno AS survey_no,
                rp.blockno AS block_no,
                rp.barangay,
                e.name AS taxpayer_name,
                e.address_text AS taxpayer_address,
                tx.name AS txntype_name,
                r.taxable,
                ra.actualuse,
                COALESCE(NULLIF(fp.fp_prevtdno, ''), f.prevtdno) AS prevtdno,
                COALESCE(NULLIF(fp.fp_prevpin, ''), f.prevpin) AS prev_pin,
                COALESCE(NULLIF(fp.fp_prevowner, ''), f.prevowner) AS prev_owner,
                COALESCE(NULLIF(fp.fp_prevav, ''), f.prevav) AS prev_assessed_value,
                COALESCE(NULLIF(fp.fp_prevmv, ''), f.prevmv) AS prev_market_value,
                COALESCE(NULLIF(fp.fp_prevareasqm, ''), f.prevareasqm) AS prev_area_sqm,
                COALESCE(NULLIF(fp.fp_prevareaha, ''), f.prevareaha) AS prev_area_hectare,
                COALESCE(NULLIF(fp.fp_prevadministrator, ''), f.prevadministrator) AS prev_administrator,
                f.originlguid,
                f.state AS raw_state,
                $effective_state_expr AS state,
                $effective_state_expr AS effective_state,
                f.cancelledbytdnos AS raw_cancelledbytdnos,
                f.canceldate AS raw_canceldate,
                f.cancelledyear AS raw_cancelledyear,
                f.cancelreason AS raw_cancelreason,
                f.cancelnote AS raw_cancelnote,
                COALESCE(succ_link.succ_tdno, NULLIF(f.cancelledbytdnos, '')) AS cancelledbytdnos,
                COALESCE(succ_link.succ_tdno, NULLIF(f.cancelledbytdnos, '')) AS cancelled_by_tdnos,
                COALESCE(succ_link.succ_dtapproved, f.canceldate) AS canceldate,
                COALESCE(succ_link.succ_dtapproved, f.canceldate) AS cancel_date,
                COALESCE(succ_link.succ_year, f.cancelledyear) AS cancelledyear,
                COALESCE(succ_link.succ_year, f.cancelledyear) AS cancelled_year,
                COALESCE(
                    CASE WHEN succ_link.succ_tdno IS NOT NULL THEN CONCAT('Superseded by ', succ_link.succ_tdno) ELSE NULL END,
                    NULLIF(f.cancelreason, '')
                ) AS cancelreason,
                COALESCE(
                    CASE WHEN succ_link.succ_tdno IS NOT NULL THEN CONCAT('Superseded by ', succ_link.succ_tdno) ELSE NULL END,
                    NULLIF(f.cancelreason, '')
                ) AS cancel_reason,
                COALESCE(
                    (SELECT f2.fullpin FROM $t_faas f2 WHERE f2.tdno = COALESCE(succ_link.succ_tdno, NULLIF(f.cancelledbytdnos, '')) LIMIT 1),
                    succ_link.succ_pin
                ) AS cancelled_by_pin
            FROM $t_faas f
            $joins
            WHERE $where_sql
            ORDER BY $order_by
            LIMIT %d OFFSET %d
        ";

        $query_values = array_merge($values, array($per_page, $offset));
        $results = $wpdb->get_results($wpdb->prepare($select_sql, $query_values));

        return rest_ensure_response(array(
            'data' => $results,
            'total' => intval($total),
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => ceil(intval($total) / $per_page)
        ));
    }

    // ──────────────────────────────────────────────
    // FAAS SINGLE RECORD
    // ──────────────────────────────────────────────

    public function get_faas($id) {
        global $wpdb;

        $t_faas = $wpdb->prefix . 'assessor_faas';
        $t_rpu = $wpdb->prefix . 'assessor_rpu';
        $t_rp = $wpdb->prefix . 'assessor_real_property';
        $t_entity = $wpdb->prefix . 'assessor_entity';
        $t_txn = $wpdb->prefix . 'assessor_faas_txntypes';
        $t_faas_previous = $wpdb->prefix . 'assessor_faas_previous';

        $effective_state_expr = "CASE WHEN f.state = 'CURRENT' AND succ_link.succ_tdno IS NOT NULL THEN 'CANCELLED' ELSE f.state END";

        $sql = "
            SELECT
                f.*,
                f.objid AS id,
                f.rpuid AS rpu_id,
                f.realpropertyid AS real_property_id,
                f.taxpayer_objid AS taxpayer_id,
                f.effectivityyear AS effectivity_year,
                f.effectivityqtr AS effectivity_qtr,
                f.txntype_objid AS txntype_code,
                f.titletype AS title_type,
                f.titleno AS title_no,
                f.titledate AS title_date,
                r.rpu_type,
                r.classification,
                r.total_market_value,
                r.total_assessed_value,
                r.total_area_sqm,
                r.total_area_hectare,
                r.ry AS revision_year,
                f.state AS raw_state,
                $effective_state_expr AS state,
                $effective_state_expr AS effective_state,
                rp.pin AS rp_pin,
                rp.cadastrallotno AS cadastral_lot_no,
                rp.surveyno AS survey_no,
                rp.blockno AS block_no,
                rp.barangay,
                rp.north,
                rp.south,
                rp.east,
                rp.west,
                e.name AS taxpayer_name,
                e.address_text AS taxpayer_address,
                e.type AS taxpayer_type,
                tx.name AS txntype_name,
                r.taxable,
                COALESCE(NULLIF(fp.fp_prevtdno, ''), f.prevtdno) AS prevtdno,
                COALESCE(NULLIF(fp.fp_prevpin, ''), f.prevpin) AS prev_pin,
                COALESCE(NULLIF(fp.fp_prevowner, ''), f.prevowner) AS prev_owner,
                COALESCE(NULLIF(fp.fp_prevav, ''), f.prevav) AS prev_assessed_value,
                COALESCE(NULLIF(fp.fp_prevmv, ''), f.prevmv) AS prev_market_value,
                COALESCE(NULLIF(fp.fp_prevareasqm, ''), f.prevareasqm) AS prev_area_sqm,
                COALESCE(NULLIF(fp.fp_prevareaha, ''), f.prevareaha) AS prev_area_hectare,
                COALESCE(NULLIF(fp.fp_prevadministrator, ''), f.prevadministrator) AS prev_administrator,
                f.originlguid,
                f.cancelledbytdnos AS raw_cancelledbytdnos,
                f.canceldate AS raw_canceldate,
                f.cancelledyear AS raw_cancelledyear,
                f.cancelreason AS raw_cancelreason,
                f.cancelnote AS raw_cancelnote,
                COALESCE(succ_link.succ_tdno, NULLIF(f.cancelledbytdnos, '')) AS cancelledbytdnos,
                COALESCE(succ_link.succ_tdno, NULLIF(f.cancelledbytdnos, '')) AS cancelled_by_tdnos,
                COALESCE(succ_link.succ_dtapproved, f.canceldate) AS canceldate,
                COALESCE(succ_link.succ_dtapproved, f.canceldate) AS cancel_date,
                COALESCE(succ_link.succ_year, f.cancelledyear) AS cancelledyear,
                COALESCE(succ_link.succ_year, f.cancelledyear) AS cancelled_year,
                COALESCE(
                    CASE WHEN succ_link.succ_tdno IS NOT NULL THEN CONCAT('Superseded by ', succ_link.succ_tdno) ELSE NULL END,
                    NULLIF(f.cancelreason, '')
                ) AS cancelreason,
                COALESCE(
                    CASE WHEN succ_link.succ_tdno IS NOT NULL THEN CONCAT('Superseded by ', succ_link.succ_tdno) ELSE NULL END,
                    NULLIF(f.cancelreason, '')
                ) AS cancel_reason,
                COALESCE(
                    (SELECT f2.fullpin FROM $t_faas f2 WHERE f2.tdno = COALESCE(succ_link.succ_tdno, NULLIF(f.cancelledbytdnos, '')) LIMIT 1),
                    succ_link.succ_pin
                ) AS cancelled_by_pin
            FROM $t_faas f
            LEFT JOIN $t_rpu r ON f.rpuid = r.objid
            LEFT JOIN $t_rp rp ON f.realpropertyid = rp.objid
            LEFT JOIN $t_entity e ON f.taxpayer_objid = e.objid
            LEFT JOIN $t_txn tx ON f.txntype_objid = tx.code
            LEFT JOIN (
                SELECT 
                    faasid,
                    GROUP_CONCAT(prevtdno SEPARATOR ' | ') as fp_prevtdno,
                    GROUP_CONCAT(prevpin SEPARATOR ' | ') as fp_prevpin,
                    GROUP_CONCAT(prevowner SEPARATOR ' | ') as fp_prevowner,
                    GROUP_CONCAT(prevav SEPARATOR ' | ') as fp_prevav,
                    GROUP_CONCAT(prevmv SEPARATOR ' | ') as fp_prevmv,
                    GROUP_CONCAT(prevareasqm SEPARATOR ' | ') as fp_prevareasqm,
                    GROUP_CONCAT(prevareaha SEPARATOR ' | ') as fp_prevareaha,
                    GROUP_CONCAT(prevadministrator SEPARATOR ' | ') as fp_prevadministrator
                FROM $t_faas_previous
                GROUP BY faasid
            ) fp ON f.objid = fp.faasid
            LEFT JOIN (
                SELECT 
                    COALESCE(f_pred_id.objid, f_pred_td.objid) AS canonical_pred_id,
                    GROUP_CONCAT(DISTINCT f_succ.tdno ORDER BY f_succ.dtapproved ASC, f_succ.tdno ASC SEPARATOR ', ') AS succ_tdno,
                    GROUP_CONCAT(DISTINCT f_succ.fullpin ORDER BY f_succ.dtapproved ASC, f_succ.tdno ASC SEPARATOR ', ') AS succ_pin,
                    MIN(f_succ.dtapproved) AS succ_dtapproved,
                    CAST(SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(f_succ.year, YEAR(f_succ.dtapproved)) ORDER BY f_succ.dtapproved ASC, f_succ.tdno ASC SEPARATOR ','), ',', 1) AS UNSIGNED) AS succ_year
                FROM $t_faas_previous fp_sub
                INNER JOIN $t_faas f_succ ON fp_sub.faasid = f_succ.objid
                LEFT JOIN $t_faas f_pred_id ON (fp_sub.prevfaasid IS NOT NULL AND fp_sub.prevfaasid != '' AND fp_sub.prevfaasid = f_pred_id.objid)
                LEFT JOIN (
                    SELECT tdno, MIN(objid) AS objid
                    FROM $t_faas
                    WHERE tdno IS NOT NULL AND tdno != ''
                    GROUP BY tdno
                    HAVING COUNT(*) = 1
                ) f_pred_td ON (f_pred_id.objid IS NULL AND fp_sub.prevtdno IS NOT NULL AND fp_sub.prevtdno != '' AND fp_sub.prevtdno = f_pred_td.tdno)
                WHERE f_succ.state IN ('CURRENT', 'CANCELLED')
                  AND COALESCE(f_pred_id.objid, f_pred_td.objid) IS NOT NULL
                GROUP BY COALESCE(f_pred_id.objid, f_pred_td.objid)
            ) succ_link ON (f.state = 'CURRENT' AND f.objid = succ_link.canonical_pred_id)
            WHERE f.objid = %s
        ";

        $record = $wpdb->get_row($wpdb->prepare($sql, $id));
        if (!$record) {
            $err = !empty($wpdb->last_error) ? $wpdb->last_error : 'No row returned for objid: ' . $id;
            return new WP_Error('not_found', 'FAAS record not found: ' . $err, array('status' => 404));
        }

        // Dynamic fallback lookup for previous FAAS details if empty/missing
        if (!empty($record->prevtdno) && $record->prevtdno !== '-') {
            $has_empty_prev = empty($record->prev_owner) || $record->prev_owner === '-' ||
                              empty($record->prev_pin) || $record->prev_pin === '-' ||
                              empty($record->prev_assessed_value) || floatval($record->prev_assessed_value) == 0;

            if ($has_empty_prev) {
                $prev_record = $wpdb->get_row($wpdb->prepare("
                    SELECT 
                        f.owner_name AS prev_owner,
                        f.administrator_name AS prev_administrator,
                        f.fullpin AS prev_pin,
                        r.total_market_value AS prev_market_value,
                        r.total_assessed_value AS prev_assessed_value,
                        r.total_area_sqm AS prev_area_sqm,
                        r.total_area_hectare AS prev_area_hectare
                    FROM {$wpdb->prefix}assessor_faas f
                    LEFT JOIN {$wpdb->prefix}assessor_rpu r ON f.rpuid = r.objid
                    LEFT JOIN {$wpdb->prefix}assessor_real_property rp ON f.realpropertyid = rp.objid
                    WHERE f.tdno = %s
                    LIMIT 1
                ", $record->prevtdno));

                if ($prev_record) {
                    if (empty($record->prev_owner) || $record->prev_owner === '-') $record->prev_owner = $prev_record->prev_owner;
                    if (empty($record->prev_administrator) || $record->prev_administrator === '-') $record->prev_administrator = $prev_record->prev_administrator;
                    if (empty($record->prev_pin) || $record->prev_pin === '-') $record->prev_pin = $prev_record->prev_pin;
                    if (empty($record->prev_market_value) || floatval($record->prev_market_value) == 0) $record->prev_market_value = $prev_record->prev_market_value;
                    if (empty($record->prev_assessed_value) || floatval($record->prev_assessed_value) == 0) $record->prev_assessed_value = $prev_record->prev_assessed_value;
                    if (empty($record->prev_area_sqm) || floatval($record->prev_area_sqm) == 0) $record->prev_area_sqm = $prev_record->prev_area_sqm;
                    if (empty($record->prev_area_hectare) || floatval($record->prev_area_hectare) == 0) $record->prev_area_hectare = $prev_record->prev_area_hectare;
                }
            }
        }

        // Fetch detailed predecessor lineage records (WP-03)
        $record->predecessors = array();
        $has_prev_table = ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t_faas_previous)) === $t_faas_previous);
        if ($has_prev_table) {
            $record->predecessors = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $t_faas_previous WHERE faasid = %s ORDER BY objid ASC",
                $id
            ));
        }

        return $record;
    }

    // ──────────────────────────────────────────────
    // FAAS CREATE
    // ──────────────────────────────────────────────

    public function create_faas($request) {
        global $wpdb;
        $params = $request->get_params();
        $auth = new Assessor_Auth();
        $user_id = $auth->get_user_id_from_token($request);

        // Validate required
        $required = array('tdno', 'owner_name');
        foreach ($required as $field) {
            if (empty($params[$field])) {
                return new WP_Error('missing_field', "Field '$field' is required", array('status' => 400));
            }
        }

        // Check duplicate TD
        $t_faas = $wpdb->prefix . 'assessor_faas';
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT objid FROM $t_faas WHERE tdno = %s AND state != 'DELETED'",
            $params['tdno']
        ));
        if ($existing) {
            return new WP_Error('duplicate_tdno', 'Tax Declaration Number already exists', array('status' => 400));
        }

        $wpdb->query('START TRANSACTION');

        try {
            $t_rpu = $wpdb->prefix . 'assessor_rpu';
            $t_rp = $wpdb->prefix . 'assessor_real_property';
            $t_rpumaster = $wpdb->prefix . 'assessor_rpumaster';
            $t_faas_previous = $wpdb->prefix . 'assessor_faas_previous';

            // 1. Upsert Entity (taxpayer)
            $taxpayer_id = $this->upsert_entity($params);

            // 2. Resolve Predecessors & Transaction Semantics for Parcel Reuse & RPUMaster Lineage (WP-05)
            $txn_code = strtoupper(sanitize_text_field($params['txntype_code'] ?? 'GR'));
            $is_subdivision = ($txn_code === 'SD');
            $is_consolidation = ($txn_code === 'CS');

            $pred_faas_id = !empty($params['prevfaasid']) ? sanitize_text_field($params['prevfaasid']) : null;
            $pred_tdno = !empty($params['prevtdno']) ? sanitize_text_field($params['prevtdno']) : '';
            if ($pred_tdno === '-') $pred_tdno = '';

            $resolved_pred_faas = null;
            if ($pred_faas_id) {
                $resolved_pred_faas = $wpdb->get_row($wpdb->prepare(
                    "SELECT objid, state, origin_type, rpuid, realpropertyid, tdno FROM $t_faas WHERE objid = %s",
                    $pred_faas_id
                ));
            } elseif (!empty($pred_tdno) && strpos($pred_tdno, ' | ') === false && strpos($pred_tdno, ', ') === false) {
                $matching_preds = $wpdb->get_results($wpdb->prepare(
                    "SELECT objid, state, origin_type, rpuid, realpropertyid, tdno FROM $t_faas WHERE tdno = %s AND state != 'DELETED'",
                    $pred_tdno
                ));
                if (count($matching_preds) === 1) {
                    $resolved_pred_faas = $matching_preds[0];
                }
                // If count > 1, ambiguity will be enforced at approval (or when explicit selection is required)
            }

            // Resolve predecessor RPU and RPUMaster if available
            $pred_rpu = null;
            if ($resolved_pred_faas && !empty($resolved_pred_faas->rpuid)) {
                $pred_rpu = $wpdb->get_row($wpdb->prepare(
                    "SELECT objid, origin_type, realpropertyid, rpumasterid, previd FROM $t_rpu WHERE objid = %s",
                    $resolved_pred_faas->rpuid
                ));
            }

            // Determine Real Property ID (Parcel Reuse vs. Creation)
            $real_property_id = null;
            $parent_rp_id = null;
            if ($pred_rpu && !empty($pred_rpu->realpropertyid)) {
                $parent_rp_id = $pred_rpu->realpropertyid;
            } elseif ($resolved_pred_faas && !empty($resolved_pred_faas->realpropertyid)) {
                $parent_rp_id = $resolved_pred_faas->realpropertyid;
            }

            if (!empty($params['realpropertyid'])) {
                // Explicit parcel ID supplied
                $explicit_rp = $wpdb->get_row($wpdb->prepare(
                    "SELECT objid, origin_type FROM $t_rp WHERE objid = %s",
                    $params['realpropertyid']
                ));
                if (!$explicit_rp) {
                    throw new Exception("Specified realpropertyid '{$params['realpropertyid']}' does not exist.");
                }
                $real_property_id = $explicit_rp->objid;
            } elseif ($is_subdivision) {
                // Subdivision creates a distinct physical child parcel whose previd links to parent parcel
                $real_property_id = $this->create_real_property($params, $parent_rp_id);
            } elseif (!$is_consolidation && $parent_rp_id) {
                // Revision/Transfer/Reassessment: Reuse verified predecessor parcel
                $real_property_id = $parent_rp_id;
            } else {
                // Ambiguous parcel matching guard:
                // Do not match parcels using owner name, address, PIN, or other non-unique attributes alone.
                // If PIN is provided on a revision request but no explicit or unambiguous predecessor was resolved,
                // check if the PIN matches multiple parcels.
                if (!empty($params['pin'])) {
                    $matching_rps = $wpdb->get_results($wpdb->prepare(
                        "SELECT objid, origin_type FROM $t_rp WHERE pin = %s",
                        $params['pin']
                    ));
                    if (count($matching_rps) > 1) {
                        throw new Exception("Ambiguous parcel PIN '{$params['pin']}'. Multiple parcel records matched; explicit selection via realpropertyid or prevfaasid is required.");
                    }
                }
                $real_property_id = $this->create_real_property($params, $parent_rp_id);
            }

            // Determine RPUMaster linkage
            $rpumaster_id = null;
            if ($pred_rpu && !empty($pred_rpu->rpumasterid)) {
                $rpumaster_id = $pred_rpu->rpumasterid;
            } elseif (!empty($params['rpumasterid'])) {
                $rpumaster_id = sanitize_text_field($params['rpumasterid']);
            }

            // If no existing master found, check if a master exists for this real property
            if (!$rpumaster_id && $real_property_id) {
                $existing_master_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT r.rpumasterid FROM $t_rpu r WHERE r.realpropertyid = %s AND r.rpumasterid IS NOT NULL AND r.rpumasterid != '' LIMIT 1",
                    $real_property_id
                ));
                if ($existing_master_id) {
                    $rpumaster_id = $existing_master_id;
                }
            }

            // Create new RPUMaster row with origin_type = LOCAL if none exists yet
            if (!$rpumaster_id) {
                $rpumaster_id = wp_generate_uuid4();
                $has_master_table = ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t_rpumaster)) === $t_rpumaster);
                if ($has_master_table) {
                    $master_cols = $wpdb->get_col("DESCRIBE `$t_rpumaster`", 0);
                    $master_cols_flip = !empty($master_cols) ? array_flip($master_cols) : array();

                    $master_row = array(
                        'objid'         => $rpumaster_id,
                        'currentfaasid' => null, // Pointers advance at approval only, NOT at draft creation
                        'currentrpuid'  => null,
                        'origin_type'   => 'LOCAL',
                    );
                    if (!empty($master_cols_flip)) {
                        $master_row = array_intersect_key($master_row, $master_cols_flip);
                    }
                    $ins_m = $wpdb->insert($t_rpumaster, $master_row);
                    if ($ins_m === false) {
                        throw new Exception('Failed to create RPUMaster record: ' . $wpdb->last_error);
                    }
                }
            }

            // 3. Create RPU with predecessor link (rpu.previd) and master link (rpu.rpumasterid)
            $pred_rpu_id = $pred_rpu ? $pred_rpu->objid : null;
            $rpu_id = $this->create_rpu($params, $real_property_id, $pred_rpu_id, $rpumaster_id);

            // 3b. Persist RPU Assessment Lines (WP-04)
            if (isset($params['assessments'])) {
                $assess_res = $this->persist_rpu_assessments($rpu_id, $params['assessments'], false);
                if (is_wp_error($assess_res)) {
                    throw new Exception($assess_res->get_error_message());
                }
            }

            // 4. Create FAAS
            $faas_objid = wp_generate_uuid4();
            // WP-02: Local authoring must start in draft/interim state (INTERIM) with origin_type = LOCAL.
            // It must NOT automatically become CURRENT or cancel predecessor declarations upon creation.
            $faas_data = array(
                'objid'                 => $faas_objid,
                'state'                 => 'INTERIM',
                'origin_type'           => 'LOCAL',
                'rpuid'                 => $rpu_id,
                'realpropertyid'        => $real_property_id,
                'taxpayer_objid'        => $taxpayer_id,
                'tdno'                  => sanitize_text_field($params['tdno']),
                'utdno'                 => !empty($params['utdno']) ? sanitize_text_field($params['utdno']) : sanitize_text_field($params['tdno']),
                'txntype_objid'         => $txn_code,
                'effectivityyear'       => intval($params['effectivity_year'] ?? 0),
                'effectivityqtr'        => intval($params['effectivity_qtr'] ?? 0),
                'owner_name'            => sanitize_text_field($params['owner_name']),
                'owner_address'         => sanitize_textarea_field($params['owner_address'] ?? ''),
                'administrator_name'    => sanitize_text_field($params['administrator_name'] ?? ''),
                'administrator_address' => sanitize_text_field($params['administrator_address'] ?? ''),
                'beneficiary_name'      => sanitize_text_field($params['beneficiary_name'] ?? ''),
                'beneficiary_address'   => sanitize_text_field($params['beneficiary_address'] ?? ''),
                'fullpin'               => sanitize_text_field($params['pin'] ?? ''),
                'titletype'             => sanitize_text_field($params['title_type'] ?? ''),
                'titleno'               => sanitize_text_field($params['title_no'] ?? ''),
                'titledate'             => !empty($params['title_date']) ? $params['title_date'] : null,
                'prevtdno'              => sanitize_text_field($params['prevtdno'] ?? ''),
                'prevowner'             => sanitize_text_field($params['prev_owner'] ?? ''),
                'prevav'                => sanitize_text_field($params['prev_assessed_value'] ?? ''),
                'prevmv'                => sanitize_text_field($params['prev_market_value'] ?? ''),
                'prevareaha'            => sanitize_text_field($params['prev_area_hectare'] ?? ''),
                'prevareasqm'           => sanitize_text_field($params['prev_area_sqm'] ?? ''),
                'preveffectivity'       => sanitize_text_field($params['prev_effectivity'] ?? ''),
                'memoranda'             => sanitize_textarea_field($params['memoranda'] ?? ''),
                'backtaxyrs'            => intval($params['back_tax_years'] ?? 0),
                'ryordinanceno'         => sanitize_text_field($params['ry_ordinance_no'] ?? ''),
                'ryordinancedate'       => !empty($params['ry_ordinance_date']) ? $params['ry_ordinance_date'] : null,
                'dtapproved'            => null,
                'year'                  => !empty($params['year_issued']) ? intval($params['year_issued']) : null,
                'publicland'            => intval($params['public_land'] ?? 0),
                'txntimestamp'          => current_time('mysql')
            );

            $result = $wpdb->insert($t_faas, $faas_data);
            if ($result === false) {
                throw new Exception('Failed to create FAAS record: ' . $wpdb->last_error);
            }

            // Log WP-07 Audit Event (Atomic inside transaction)
            $correlation_id = !empty($params['correlation_id']) ? $params['correlation_id'] : wp_generate_uuid4();
            $audit_logged = self::log_shadow_audit(array(
                'request'               => $request,
                'user_id'               => $user_id,
                'event_type'            => 'CREATE',
                'action_outcome'        => 'SUCCESS',
                'source_classification' => 'LOCAL_AUTHORING',
                'origin_type'           => 'LOCAL',
                'object_type'           => 'FAAS',
                'object_id'             => $faas_objid,
                'faas_id'               => $faas_objid,
                'rpu_id'                => $rpu_id,
                'real_property_id'      => $real_property_id,
                'rpumaster_id'          => $rpumaster_id,
                'old_state'             => null,
                'new_state'             => 'INTERIM',
                'changed_fields'        => array('tdno', 'owner_name', 'rpu_id', 'realpropertyid', 'rpumasterid'),
                'details'               => array(
                    'tdno'             => $params['tdno'] ?? '',
                    'owner_name'       => $params['owner_name'] ?? '',
                    'rpu_id'           => $rpu_id,
                    'real_property_id' => $real_property_id,
                    'rpumaster_id'     => $rpumaster_id,
                    'txntype_code'     => $txn_code,
                ),
                'correlation_id'        => $correlation_id,
            ));
            if ($audit_logged === false) {
                throw new Exception('Failed to record mandatory shadow audit event for FAAS creation');
            }

            $wpdb->query('COMMIT');
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('insert_failed', $e->getMessage(), array('status' => 500));
        }

        return rest_ensure_response($this->get_faas($faas_objid));
    }

    // ──────────────────────────────────────────────
    // FAAS UPDATE
    // ──────────────────────────────────────────────

    public function update_faas($id, $request) {
        global $wpdb;
        $params = is_array($request) ? $request : $request->get_params();
        $t_faas = $wpdb->prefix . 'assessor_faas';
        $current = $this->get_faas($id);
        if (is_wp_error($current)) return $current;

        // WP-02 Guard: Protect REPLICA records from being mutated as local drafts
        if (isset($current->origin_type) && strtoupper($current->origin_type) === 'REPLICA') {
            return new WP_Error('replica_protected', 'REPLICA records cannot be modified via local authoring draft editor', array('status' => 403));
        }

        // WP-02 Guard: Only draft/unapproved declarations can be edited
        $editable_states = array('INTERIM', 'PENDING', 'DISAPPROVED');
        if (!in_array(strtoupper($current->state), $editable_states, true)) {
            return new WP_Error('invalid_state', 'Cannot edit declaration in state ' . $current->state . '. Only draft declarations (INTERIM, PENDING, DISAPPROVED) can be edited.', array('status' => 400));
        }

        // Check duplicate TD if changing
        if (isset($params['tdno'])) {
            $new_td = sanitize_text_field($params['tdno']);
            $dup = $wpdb->get_var($wpdb->prepare(
                "SELECT objid FROM $t_faas WHERE tdno = %s AND objid != %s AND state != 'DELETED'",
                $new_td,
                $id
            ));
            if ($dup) {
                return new WP_Error('duplicate_tdno', 'Tax Declaration Number already exists', array('status' => 400));
            }
        }

        $wpdb->query('START TRANSACTION');

        try {
            // Update Entity if name/address changed
            if (isset($params['owner_name']) || isset($params['owner_address'])) {
                $this->upsert_entity($params, $current->taxpayer_id);
            }

            // Update Real Property
            if ($current->real_property_id) {
                $this->update_real_property($current->real_property_id, $params);
            }

            // Update RPU
            if ($current->rpu_id) {
                $this->update_rpu($current->rpu_id, $params);
            }

            // Update RPU Assessment Lines (WP-04)
            if ($current->rpu_id && isset($params['assessments'])) {
                $assess_res = $this->persist_rpu_assessments($current->rpu_id, $params['assessments'], true);
                if (is_wp_error($assess_res)) {
                    throw new Exception($assess_res->get_error_message());
                }
            }

            // Map frontend fields to DB columns
            $field_map = array(
                'tdno'                  => 'tdno',
                'utdno'                 => 'utdno',
                'txntype_code'          => 'txntype_objid',
                'effectivity_year'      => 'effectivityyear',
                'effectivity_qtr'       => 'effectivityqtr',
                'owner_name'            => 'owner_name',
                'owner_address'         => 'owner_address',
                'administrator_name'    => 'administrator_name',
                'administrator_address' => 'administrator_address',
                'beneficiary_name'      => 'beneficiary_name',
                'beneficiary_address'   => 'beneficiary_address',
                'pin'                   => 'fullpin',
                'title_type'            => 'titletype',
                'title_no'              => 'titleno',
                'title_date'            => 'titledate',
                'prevtdno'              => 'prevtdno',
                'prev_owner'            => 'prevowner',
                'prev_assessed_value'   => 'prevav',
                'prev_market_value'     => 'prevmv',
                'prev_area_hectare'     => 'prevareaha',
                'prev_area_sqm'         => 'prevareasqm',
                'prev_effectivity'      => 'preveffectivity',
                'memoranda'             => 'memoranda',
                'back_tax_years'        => 'backtaxyrs',
                'ry_ordinance_no'       => 'ryordinanceno',
                'ry_ordinance_date'     => 'ryordinancedate',
                'year_issued'           => 'year',
                'public_land'           => 'publicland'
            );

            $update_data = array();
            foreach ($field_map as $param_key => $db_col) {
                if (!array_key_exists($param_key, $params)) continue;
                
                $val = $params[$param_key];
                
                if (in_array($param_key, array('effectivity_year', 'effectivity_qtr', 'back_tax_years', 'public_land', 'year_issued'))) {
                    $update_data[$db_col] = !empty($val) ? intval($val) : 0;
                } elseif (in_array($param_key, array('title_date', 'ry_ordinance_date'))) {
                    $update_data[$db_col] = !empty($val) ? $val : null;
                } else {
                    $update_data[$db_col] = sanitize_text_field($val);
                }
            }

            if (isset($update_data['utdno']) && empty($update_data['utdno'])) {
                $update_data['utdno'] = $update_data['tdno'] ?? $current->tdno;
            }

            if (!empty($update_data)) {
                $wpdb->update($t_faas, $update_data, array('objid' => $id));
            }

            // Log WP-07 Audit Event (Atomic inside transaction)
            $correlation_id = !empty($params['correlation_id']) ? $params['correlation_id'] : wp_generate_uuid4();
            $audit_logged = self::log_shadow_audit(array(
                'request'               => $request,
                'event_type'            => 'UPDATE',
                'action_outcome'        => 'SUCCESS',
                'source_classification' => 'LOCAL_AUTHORING',
                'origin_type'           => 'LOCAL',
                'object_type'           => 'FAAS',
                'object_id'             => $id,
                'faas_id'               => $id,
                'rpu_id'                => $current->rpu_id,
                'real_property_id'      => $current->real_property_id,
                'rpumaster_id'          => $current->rpumaster_id ?? null,
                'old_state'             => $current->state,
                'new_state'             => $current->state,
                'changed_fields'        => array_keys($update_data),
                'details'               => array(
                    'updated_fields' => array_keys($update_data),
                    'tdno'           => $update_data['tdno'] ?? $current->tdno,
                ),
                'correlation_id'        => $correlation_id,
            ));
            if ($audit_logged === false) {
                throw new Exception('Failed to record mandatory shadow audit event for FAAS update');
            }

            $wpdb->query('COMMIT');
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('update_failed', $e->getMessage(), array('status' => 500));
        }

        return rest_ensure_response($this->get_faas($id));
    }

    // ──────────────────────────────────────────────
    // FAAS DELETE (soft delete → DELETED state)
    // ──────────────────────────────────────────────

    public function delete_faas($id, $request) {
        global $wpdb;
        $t_faas = $wpdb->prefix . 'assessor_faas';

        $current = $this->get_faas($id);
        if (is_wp_error($current)) return $current;

        // WP-02: Protect REPLICA rows from local deletion
        if (isset($current->origin_type) && strtoupper($current->origin_type) === 'REPLICA') {
            return new WP_Error('replica_protected', 'REPLICA records cannot be deleted via local authoring lifecycle', array('status' => 403));
        }

        // Only unapproved drafts can be deleted
        $deletable_states = array('INTERIM', 'PENDING', 'DISAPPROVED');
        if (!in_array(strtoupper($current->state), $deletable_states, true)) {
            return new WP_Error('invalid_state', 'Cannot delete declaration in state ' . $current->state . '. Only unapproved declarations can be deleted.', array('status' => 400));
        }

        $params = is_array($request) ? $request : (is_object($request) && method_exists($request, 'get_params') ? $request->get_params() : array());
        $correlation_id = !empty($params['correlation_id']) ? $params['correlation_id'] : wp_generate_uuid4();

        $wpdb->query('START TRANSACTION');

        try {
            $upd = $wpdb->update($t_faas, array(
                'state'        => 'DELETED',
                'txntimestamp' => current_time('mysql'),
            ), array('objid' => $id));

            if ($upd === false) {
                throw new Exception('Failed to update declaration state to DELETED: ' . $wpdb->last_error);
            }

            // Log WP-07 Audit Event (Atomic inside transaction)
            $audit_logged = self::log_shadow_audit(array(
                'request'               => $request,
                'event_type'            => 'DELETE',
                'action_outcome'        => 'SUCCESS',
                'source_classification' => 'LOCAL_AUTHORING',
                'origin_type'           => 'LOCAL',
                'object_type'           => 'FAAS',
                'object_id'             => $id,
                'faas_id'               => $id,
                'rpu_id'                => $current->rpu_id ?? null,
                'real_property_id'      => $current->real_property_id ?? null,
                'rpumaster_id'          => $current->rpumaster_id ?? null,
                'old_state'             => $current->state,
                'new_state'             => 'DELETED',
                'changed_fields'        => array('state', 'txntimestamp'),
                'details'               => array(
                    'tdno'      => $current->tdno,
                    'old_state' => $current->state,
                ),
                'correlation_id'        => $correlation_id,
            ));

            if ($audit_logged === false) {
                throw new Exception('Failed to record mandatory shadow audit event for FAAS deletion');
            }

            $wpdb->query('COMMIT');
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('delete_failed', 'Failed to delete declaration: ' . $e->getMessage(), array('status' => 500));
        }

        return rest_ensure_response(array('success' => true, 'message' => 'FAAS record deleted'));
    }

    // ──────────────────────────────────────────────
    // FAAS SUBMIT FOR APPROVAL
    // ──────────────────────────────────────────────

    public function submit_faas($id, $request = array()) {
        global $wpdb;
        $t_faas = $wpdb->prefix . 'assessor_faas';

        $current = $this->get_faas($id);
        if (is_wp_error($current)) return $current;

        // Reject REPLICA records
        if (isset($current->origin_type) && strtoupper($current->origin_type) === 'REPLICA') {
            return new WP_Error('replica_protected', 'REPLICA records cannot be submitted through the local authoring lifecycle', array('status' => 403));
        }

        // Only INTERIM, PENDING, or DISAPPROVED records can be submitted for approval
        $submittable_states = array('INTERIM', 'PENDING', 'DISAPPROVED');
        if (!in_array(strtoupper($current->state), $submittable_states, true)) {
            return new WP_Error('invalid_state', 'Cannot submit declaration in state ' . $current->state . '. Only INTERIM, PENDING, or DISAPPROVED declarations can be submitted.', array('status' => 400));
        }

        $wpdb->query('START TRANSACTION');

        try {
            $wpdb->update($t_faas, array(
                'state' => 'FORAPPROVAL',
                'txntimestamp' => current_time('mysql')
            ), array('objid' => $id));

            // Log WP-07 Audit Event
            $params = is_array($request) ? $request : (is_object($request) && method_exists($request, 'get_params') ? $request->get_params() : array());
            $correlation_id = !empty($params['correlation_id']) ? $params['correlation_id'] : wp_generate_uuid4();

            $audit_logged = self::log_shadow_audit(array(
                'request'               => $request,
                'event_type'            => 'SUBMIT',
                'action_outcome'        => 'SUCCESS',
                'source_classification' => 'LOCAL_AUTHORING',
                'origin_type'           => 'LOCAL',
                'object_type'           => 'FAAS',
                'object_id'             => $id,
                'faas_id'               => $id,
                'rpu_id'                => $current->rpu_id,
                'real_property_id'      => $current->real_property_id,
                'rpumaster_id'          => $current->rpumaster_id ?? null,
                'old_state'             => $current->state,
                'new_state'             => 'FORAPPROVAL',
                'changed_fields'        => array('state', 'txntimestamp'),
                'details'               => array('tdno' => $current->tdno),
                'correlation_id'        => $correlation_id,
            ));
            if ($audit_logged === false) {
                throw new Exception('Failed to record mandatory shadow audit event for FAAS submission');
            }

            $wpdb->query('COMMIT');
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('submit_failed', 'Failed to submit declaration: ' . $e->getMessage(), array('status' => 500));
        }

        return rest_ensure_response($this->get_faas($id));
    }

    // ──────────────────────────────────────────────
    // FAAS APPROVE
    // ──────────────────────────────────────────────

    public function approve_faas($id, $request = array()) {
        global $wpdb;
        $params = is_array($request) ? $request : (!empty($request) && method_exists($request, 'get_params') ? $request->get_params() : array());
        $t_faas = $wpdb->prefix . 'assessor_faas';
        $t_faas_previous = $wpdb->prefix . 'assessor_faas_previous';

        $current = $this->get_faas($id);
        if (is_wp_error($current)) return $current;

        // Reject REPLICA records
        if (isset($current->origin_type) && strtoupper($current->origin_type) === 'REPLICA') {
            return new WP_Error('replica_protected', 'REPLICA records cannot be approved through the local authoring lifecycle', array('status' => 403));
        }

        // Only FORAPPROVAL records can be approved
        if (strtoupper($current->state) !== 'FORAPPROVAL') {
            return new WP_Error('invalid_state', 'Cannot approve declaration in state ' . $current->state . '. Only declarations in FORAPPROVAL state can be approved.', array('status' => 400));
        }

        $approval_date = !empty($params['date_approved']) ? sanitize_text_field($params['date_approved']) : current_time('mysql');

        // WP-02 Acceptance Hardening: Atomic Approval & Predecessor Effects
        // Execute the successor state transition and predecessor effects within a single transaction.
        $wpdb->query('START TRANSACTION');

        try {
            // 1. Lock and re-verify the successor row
            $locked_successor = $wpdb->get_row($wpdb->prepare(
                "SELECT objid, state, origin_type, tdno, prevtdno FROM $t_faas WHERE objid = %s FOR UPDATE",
                $id
            ));
            if (!$locked_successor || strtoupper($locked_successor->state) !== 'FORAPPROVAL') {
                throw new Exception('Successor declaration state changed or locked by another transaction');
            }

            // 2. Transition successor to CURRENT
            $res_successor = $wpdb->update($t_faas, array(
                'state' => 'CURRENT',
                'dtapproved' => $approval_date,
                'txntimestamp' => current_time('mysql')
            ), array('objid' => $id));

            if ($res_successor === false) {
                throw new Exception('Failed to update successor declaration state: ' . $wpdb->last_error);
            }

            // 3. Predecessor effects & Lineage Persistence (WP-03)
            // Persist lineage and enforce ambiguity check: if prevtdno matches multiple rows and prevfaasid is omitted, fail approval
            $lineage_params = array(
                'prevfaasid'          => $params['prevfaasid'] ?? null,
                'prevtdno'            => $locked_successor->prevtdno ?? '',
                'predecessors'        => $params['predecessors'] ?? null,
            );
            $lineage_res = $this->persist_predecessor_lineage($id, $lineage_params, true);
            if (is_wp_error($lineage_res)) {
                throw new Exception($lineage_res->get_error_message());
            }

            // For any locally authored predecessor resolved in wp_assessor_faas_previous,
            // transition physical state to CANCELLED (REPLICA predecessors remain untouched per WP-01/02)
            $all_pred_lineage = $wpdb->get_results($wpdb->prepare(
                "SELECT prevfaasid, prevtdno FROM $t_faas_previous WHERE faasid = %s",
                $id
            ));

            foreach ($all_pred_lineage as $plink) {
                $pred_row = null;
                if (!empty($plink->prevfaasid)) {
                    $pred_row = $wpdb->get_row($wpdb->prepare(
                        "SELECT objid, state, origin_type FROM $t_faas WHERE objid = %s FOR UPDATE",
                        $plink->prevfaasid
                    ));
                } elseif (!empty($plink->prevtdno)) {
                    $matching_by_td = $wpdb->get_results($wpdb->prepare(
                        "SELECT objid, state, origin_type FROM $t_faas WHERE tdno = %s AND state != 'DELETED' FOR UPDATE",
                        $plink->prevtdno
                    ));
                    if (count($matching_by_td) === 1) {
                        $pred_row = $matching_by_td[0];
                    }
                }

                if ($pred_row && isset($pred_row->origin_type) && strtoupper($pred_row->origin_type) === 'LOCAL') {
                    $full_pred = $wpdb->get_row($wpdb->prepare(
                        "SELECT objid, state, cancelreason, canceldate, cancelledbytdnos, cancelledyear FROM $t_faas WHERE objid = %s",
                        $pred_row->objid
                    ));

                    // Aggregate cancelledbytdnos deterministically
                    $existing_tds = array();
                    if (!empty($full_pred->cancelledbytdnos)) {
                        $parts = explode(',', $full_pred->cancelledbytdnos);
                        foreach ($parts as $p) {
                            $trimmed = trim($p);
                            if ($trimmed !== '' && !in_array($trimmed, $existing_tds)) {
                                $existing_tds[] = $trimmed;
                            }
                        }
                    }
                    if (!in_array($locked_successor->tdno, $existing_tds)) {
                        $existing_tds[] = $locked_successor->tdno;
                    }
                    $updated_by_tdnos = implode(', ', $existing_tds);

                    // Formulate structured supersession reason
                    $reason_prefix = 'Superseded by ';
                    $supersession_reason = $reason_prefix . $updated_by_tdnos;
                    if (!empty($full_pred->cancelreason) && strpos($full_pred->cancelreason, $reason_prefix) !== 0) {
                        // Preserve prior custom reason if not a generic supersession reason
                        $supersession_reason = $full_pred->cancelreason;
                    }

                    $approval_year = intval(date('Y', strtotime($approval_date)));
                    $cancel_date = !empty($full_pred->canceldate) ? $full_pred->canceldate : $approval_date;
                    $cancel_year = !empty($full_pred->cancelledyear) ? intval($full_pred->cancelledyear) : $approval_year;

                    $cancel_res = $wpdb->update($t_faas, array(
                        'state'              => 'CANCELLED',
                        'cancelreason'       => $supersession_reason,
                        'canceldate'         => $cancel_date,
                        'cancelledtimestamp' => current_time('mysql'),
                        'cancelledbytdnos'   => $updated_by_tdnos,
                        'cancelledyear'      => $cancel_year,
                    ), array('objid' => $pred_row->objid));

                    if ($cancel_res === false) {
                        throw new Exception('Failed to update local predecessor declaration state: ' . $wpdb->last_error);
                    }

                    // Log WP-07 Audit Event: Predecessor Superseded
                    $audit_sup = self::log_shadow_audit(array(
                        'request'               => $request,
                        'event_type'            => 'SUPERSEDED',
                        'action_outcome'        => 'SUCCESS',
                        'source_classification' => 'APPROVAL',
                        'origin_type'           => 'LOCAL',
                        'object_type'           => 'FAAS',
                        'object_id'             => $pred_row->objid,
                        'faas_id'               => $pred_row->objid,
                        'old_state'             => $pred_row->state,
                        'new_state'             => 'CANCELLED',
                        'changed_fields'        => array('state', 'cancelreason', 'canceldate', 'cancelledbytdnos'),
                        'details'               => array(
                            'superseded_by_faas_id' => $id,
                            'superseded_by_tdno'    => $locked_successor->tdno,
                            'cancelreason'          => $supersession_reason,
                            'cancelledbytdnos'      => $updated_by_tdnos,
                        ),
                        'correlation_id'        => !empty($params['correlation_id']) ? $params['correlation_id'] : wp_generate_uuid4(),
                    ));
                    if ($audit_sup === false) {
                        throw new Exception('Failed to record mandatory shadow audit event for predecessor supersession');
                    }
                }
            }

            // 4. Advance RPUMaster Pointers (WP-05)
            // Local currentfaasid and currentrpuid pointers advance ONLY upon approval to CURRENT.
            // REPLICA RPUMaster rows remain source-owned (never mutated in place).
            $t_rpumaster = $wpdb->prefix . 'assessor_rpumaster';
            $t_rpu = $wpdb->prefix . 'assessor_rpu';
            $has_master_table = ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t_rpumaster)) === $t_rpumaster);

            if ($has_master_table) {
                // Find RPU and master for this approved successor
                $succ_rpu = $wpdb->get_row($wpdb->prepare(
                    "SELECT objid, rpumasterid, realpropertyid FROM $t_rpu WHERE objid = (SELECT rpuid FROM $t_faas WHERE objid = %s)",
                    $id
                ));

                if ($succ_rpu && !empty($succ_rpu->rpumasterid)) {
                    $master_row = $wpdb->get_row($wpdb->prepare(
                        "SELECT objid, currentfaasid, currentrpuid, origin_type FROM $t_rpumaster WHERE objid = %s FOR UPDATE",
                        $succ_rpu->rpumasterid
                    ));

                    if ($master_row) {
                        if (isset($master_row->origin_type) && strtoupper($master_row->origin_type) === 'LOCAL') {
                            // Update LOCAL master pointers directly
                            $upd_m = $wpdb->update($t_rpumaster, array(
                                'currentfaasid' => $id,
                                'currentrpuid'  => $succ_rpu->objid,
                            ), array('objid' => $master_row->objid));

                            if ($upd_m === false) {
                                throw new Exception('Failed to update LOCAL RPUMaster current pointers: ' . $wpdb->last_error);
                            }

                            // Log WP-07 Audit Event: RPUMaster Pointer Advance
                            $audit_m = self::log_shadow_audit(array(
                                'request'               => $request,
                                'event_type'            => 'POINTER_ADVANCE',
                                'action_outcome'        => 'SUCCESS',
                                'source_classification' => 'APPROVAL',
                                'origin_type'           => 'LOCAL',
                                'object_type'           => 'RPUMASTER',
                                'object_id'             => $master_row->objid,
                                'rpumaster_id'          => $master_row->objid,
                                'faas_id'               => $id,
                                'rpu_id'                => $succ_rpu->objid,
                                'changed_fields'        => array('currentfaasid', 'currentrpuid'),
                                'details'               => array(
                                    'old_currentfaasid' => $master_row->currentfaasid,
                                    'new_currentfaasid' => $id,
                                    'old_currentrpuid'  => $master_row->currentrpuid,
                                    'new_currentrpuid'  => $succ_rpu->objid,
                                    ),
                                'correlation_id'        => !empty($params['correlation_id']) ? $params['correlation_id'] : wp_generate_uuid4(),
                            ));
                            if ($audit_m === false) {
                                throw new Exception('Failed to record mandatory shadow audit event for RPUMaster pointer advance');
                            }
                        } else {
                            // REPLICA master: source-owned fields must be preserved.
                            // Do NOT mutate REPLICA row directly. If a LOCAL master row does not exist for this property,
                            // create a LOCAL master row or maintain the linkage cleanly.
                        }
                    } else {
                        // Master ID was linked on RPU but row didn't exist in rpumaster table; create LOCAL master row
                        $master_cols = $wpdb->get_col("DESCRIBE `$t_rpumaster`", 0);
                        $master_cols_flip = !empty($master_cols) ? array_flip($master_cols) : array();
                        $new_m = array(
                            'objid'         => $succ_rpu->rpumasterid,
                            'currentfaasid' => $id,
                            'currentrpuid'  => $succ_rpu->objid,
                            'origin_type'   => 'LOCAL',
                        );
                        if (!empty($master_cols_flip)) {
                            $new_m = array_intersect_key($new_m, $master_cols_flip);
                        }
                        $ins_m = $wpdb->insert($t_rpumaster, $new_m);
                        if ($ins_m === false) {
                            throw new Exception('Failed to insert approved LOCAL RPUMaster record: ' . $wpdb->last_error);
                        }

                        // Log WP-07 Audit Event: RPUMaster Insert on Approval
                        $audit_ins_m = self::log_shadow_audit(array(
                            'request'               => $request,
                            'event_type'            => 'CREATE',
                            'action_outcome'        => 'SUCCESS',
                            'source_classification' => 'APPROVAL',
                            'origin_type'           => 'LOCAL',
                            'object_type'           => 'RPUMASTER',
                            'object_id'             => $succ_rpu->rpumasterid,
                            'rpumaster_id'          => $succ_rpu->rpumasterid,
                            'faas_id'               => $id,
                            'rpu_id'                => $succ_rpu->objid,
                            'changed_fields'        => array('currentfaasid', 'currentrpuid'),
                            'details'               => array(
                                'new_currentfaasid' => $id,
                                'new_currentrpuid'  => $succ_rpu->objid,
                            ),
                            'correlation_id'        => !empty($params['correlation_id']) ? $params['correlation_id'] : wp_generate_uuid4(),
                        ));
                        if ($audit_ins_m === false) {
                            throw new Exception('Failed to record mandatory shadow audit event for RPUMaster creation');
                        }
                    }
                }
            }

            // Log WP-07 Audit Event: Declaration Approval
            $correlation_id = !empty($params['correlation_id']) ? $params['correlation_id'] : wp_generate_uuid4();
            $audit_appr = self::log_shadow_audit(array(
                'request'               => $request,
                'event_type'            => 'APPROVE',
                'action_outcome'        => 'SUCCESS',
                'source_classification' => 'APPROVAL',
                'origin_type'           => 'LOCAL',
                'object_type'           => 'FAAS',
                'object_id'             => $id,
                'faas_id'               => $id,
                'rpu_id'                => $current->rpu_id,
                'real_property_id'      => $current->real_property_id,
                'rpumaster_id'          => $current->rpumaster_id ?? null,
                'old_state'             => 'FORAPPROVAL',
                'new_state'             => 'CURRENT',
                'changed_fields'        => array('state', 'dtapproved', 'txntimestamp'),
                'details'               => array(
                    'tdno'          => $locked_successor->tdno,
                    'dtapproved'    => $approval_date,
                    'approval_date' => $approval_date,
                ),
                'correlation_id'        => $correlation_id,
            ));
            if ($audit_appr === false) {
                throw new Exception('Failed to record mandatory shadow audit event for FAAS approval');
            }

            // Commit transaction
            $wpdb->query('COMMIT');
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('approval_failed', 'Declaration approval failed: ' . $e->getMessage(), array('status' => 500));
        }

        return rest_ensure_response($this->get_faas($id));
    }

    // ──────────────────────────────────────────────
    // FAAS DISAPPROVE / REJECT
    // ──────────────────────────────────────────────

    /**
     * Disapprove / Reject a declaration submitted for approval.
     *
     * State Semantics Note (WP-02 Hardening):
     * In original ETRACS, declarations are drafted in INTERIM/PENDING and submitted to FORAPPROVAL.
     * When disapproved, the declaration transitions to DISAPPROVED so the author can address deficiencies
     * and resubmit. In ETRACS schema, 'cancelreason' is primarily used on CANCELLED records (e.g. cancelled
     * by new TD or deed). For DISAPPROVED records, we store the assessor's rejection rationale prefixed with
     * '[DISAPPROVED]' and mirror it in 'cancelnote' so disapproval metadata is never conflated with true cancellation.
     */
    public function disapprove_faas($id, $request) {
        global $wpdb;
        $params = is_array($request) ? $request : $request->get_params();
        $t_faas = $wpdb->prefix . 'assessor_faas';

        $current = $this->get_faas($id);
        if (is_wp_error($current)) return $current;

        // Reject REPLICA records
        if (isset($current->origin_type) && strtoupper($current->origin_type) === 'REPLICA') {
            return new WP_Error('replica_protected', 'REPLICA records cannot be rejected through the local authoring lifecycle', array('status' => 403));
        }

        // Only FORAPPROVAL records can be disapproved
        if (strtoupper($current->state) !== 'FORAPPROVAL') {
            return new WP_Error('invalid_state', 'Cannot disapprove declaration in state ' . $current->state . '. Only declarations in FORAPPROVAL state can be disapproved.', array('status' => 400));
        }

        $raw_reason = sanitize_text_field($params['reason'] ?? $params['disapproval_reason'] ?? $params['cancel_reason'] ?? 'Disapproved by Assessor');

        $wpdb->query('START TRANSACTION');

        try {
            $wpdb->update($t_faas, array(
                'state'        => 'DISAPPROVED',
                'cancelreason' => $raw_reason,
                'cancelnote'   => '[DISAPPROVED] ' . $raw_reason,
                'txntimestamp' => current_time('mysql')
            ), array('objid' => $id));

            // Log WP-07 Audit Event
            $correlation_id = !empty($params['correlation_id']) ? $params['correlation_id'] : wp_generate_uuid4();

            $audit_logged = self::log_shadow_audit(array(
                'request'               => $request,
                'event_type'            => 'DISAPPROVE',
                'action_outcome'        => 'SUCCESS',
                'source_classification' => 'APPROVAL',
                'origin_type'           => 'LOCAL',
                'object_type'           => 'FAAS',
                'object_id'             => $id,
                'faas_id'               => $id,
                'rpu_id'                => $current->rpu_id,
                'real_property_id'      => $current->real_property_id,
                'rpumaster_id'          => $current->rpumaster_id ?? null,
                'old_state'             => 'FORAPPROVAL',
                'new_state'             => 'DISAPPROVED',
                'changed_fields'        => array('state', 'cancelreason', 'cancelnote', 'txntimestamp'),
                'details'               => array(
                    'tdno'   => $current->tdno,
                    'reason' => $raw_reason,
                ),
                'correlation_id'        => $correlation_id,
            ));
            if ($audit_logged === false) {
                throw new Exception('Failed to record mandatory shadow audit event for FAAS disapproval');
            }

            $wpdb->query('COMMIT');
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('disapproval_failed', 'Failed to disapprove declaration: ' . $e->getMessage(), array('status' => 500));
        }

        return rest_ensure_response($this->get_faas($id));
    }

    // ──────────────────────────────────────────────
    // FAAS CANCEL
    // ──────────────────────────────────────────────

    public function cancel_faas($id, $request) {
        global $wpdb;
        $params = is_array($request) ? $request : $request->get_params();
        $t_faas = $wpdb->prefix . 'assessor_faas';

        // 1. Transaction wrapping
        $wpdb->query('START TRANSACTION');

        try {
            // Lock and inspect the target declaration
            $current_row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $t_faas WHERE objid = %s FOR UPDATE",
                $id
            ));

            if (!$current_row) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('not_found', 'FAAS record not found for objid: ' . $id, array('status' => 404));
            }

            // 2. Reject REPLICA records (Requirement 3: Never allow local cancellation to mutate or reclassify a source-owned REPLICA FAAS row)
            if (isset($current_row->origin_type) && strtoupper($current_row->origin_type) === 'REPLICA') {
                $wpdb->query('ROLLBACK');
                return new WP_Error('replica_protected', 'REPLICA records cannot be cancelled through the local authoring lifecycle', array('status' => 403));
            }

            // 3. State validation (Requirement 2: Reject cancellation of declarations in invalid states)
            // If already CANCELLED, handle idempotently without mutating or appending conflicting details (Requirement 8)
            if (strtoupper($current_row->state) === 'CANCELLED') {
                $wpdb->query('COMMIT');
                return rest_ensure_response($this->get_faas($id));
            }

            if (strtoupper($current_row->state) !== 'CURRENT') {
                $wpdb->query('ROLLBACK');
                return new WP_Error('invalid_state', 'Cannot cancel declaration in state ' . $current_row->state . '. Only declarations in CURRENT state can be cancelled.', array('status' => 400));
            }

            // 4. Validate required cancellation reason (Requirement 1 & Step 3)
            $raw_reason = trim($params['cancel_reason'] ?? $params['reason'] ?? '');
            if ($raw_reason === '') {
                $wpdb->query('ROLLBACK');
                return new WP_Error('missing_reason', 'Cancellation reason is required', array('status' => 400));
            }

            $cancel_date = !empty($params['cancel_date']) ? sanitize_text_field($params['cancel_date']) : current_time('mysql');
            $cancel_note = isset($params['cancel_note']) ? sanitize_text_field($params['cancel_note']) : (isset($params['note']) ? sanitize_text_field($params['note']) : '');
            $cancelled_by_tdnos = isset($params['cancelled_by_tdnos']) ? sanitize_text_field($params['cancelled_by_tdnos']) : '';

            $cancelled_year = !empty($params['cancelled_year']) 
                ? intval($params['cancelled_year']) 
                : intval(date('Y', strtotime($cancel_date)));

            $update = array(
                'state'              => 'CANCELLED',
                'cancelreason'       => sanitize_text_field($raw_reason),
                'canceldate'         => $cancel_date,
                'cancelnote'         => $cancel_note,
                'cancelledbytdnos'   => $cancelled_by_tdnos,
                'cancelledtimestamp' => current_time('mysql'),
                'cancelledyear'      => $cancelled_year,
            );

            $update_res = $wpdb->update($t_faas, $update, array('objid' => $id));
            if ($update_res === false) {
                throw new Exception('Failed to update FAAS cancellation fields: ' . $wpdb->last_error);
            }

            // Log WP-07 Audit Event (Atomic inside transaction)
            $correlation_id = !empty($params['correlation_id']) ? $params['correlation_id'] : wp_generate_uuid4();

            $audit_logged = self::log_shadow_audit(array(
                'request'               => $request,
                'event_type'            => 'CANCEL',
                'action_outcome'        => 'SUCCESS',
                'source_classification' => 'CANCELLATION',
                'origin_type'           => 'LOCAL',
                'object_type'           => 'FAAS',
                'object_id'             => $id,
                'faas_id'               => $id,
                'rpu_id'                => $current_row->rpuid,
                'real_property_id'      => $current_row->realpropertyid,
                'old_state'             => $current_row->state,
                'new_state'             => 'CANCELLED',
                'changed_fields'        => array('state', 'cancelreason', 'canceldate', 'cancelnote', 'cancelledbytdnos', 'cancelledyear'),
                'details'               => array(
                    'tdno'               => $current_row->tdno,
                    'cancel_reason'      => $raw_reason,
                    'cancel_date'        => $cancel_date,
                    'cancel_note'        => $cancel_note,
                    'cancelled_by_tdnos' => $cancelled_by_tdnos,
                    'cancelled_year'     => $cancelled_year,
                ),
                'correlation_id'        => $correlation_id,
            ));
            if ($audit_logged === false) {
                throw new Exception('Failed to record mandatory shadow audit event for FAAS cancellation');
            }

            $wpdb->query('COMMIT');
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('cancellation_failed', 'Failed to cancel FAAS: ' . $e->getMessage(), array('status' => 500));
        }

        return rest_ensure_response($this->get_faas($id));
    }

    // ──────────────────────────────────────────────
    // DASHBOARD / STATS
    // ──────────────────────────────────────────────

    public function get_faas_stats($request) {
        global $wpdb;
        $t_faas = $wpdb->prefix . 'assessor_faas';
        $t_rpu = $wpdb->prefix . 'assessor_rpu';
        $t_faas_previous = $wpdb->prefix . 'assessor_faas_previous';

        // Effective Lifecycle Overlay:
        // A record with raw state 'CURRENT' that is superseded by an approved successor
        // in wp_assessor_faas_previous is counted as effectively 'CANCELLED', not 'CURRENT'.
        $joins_succ = "
            LEFT JOIN (
                SELECT 
                    COALESCE(f_pred_id.objid, f_pred_td.objid) AS canonical_pred_id,
                    1 AS is_superseded
                FROM $t_faas_previous fp_sub
                INNER JOIN $t_faas f_succ ON fp_sub.faasid = f_succ.objid
                LEFT JOIN $t_faas f_pred_id ON (fp_sub.prevfaasid IS NOT NULL AND fp_sub.prevfaasid != '' AND fp_sub.prevfaasid = f_pred_id.objid)
                LEFT JOIN (
                    SELECT tdno, MIN(objid) AS objid
                    FROM $t_faas
                    WHERE tdno IS NOT NULL AND tdno != ''
                    GROUP BY tdno
                    HAVING COUNT(*) = 1
                ) f_pred_td ON (f_pred_id.objid IS NULL AND fp_sub.prevtdno IS NOT NULL AND fp_sub.prevtdno != '' AND fp_sub.prevtdno = f_pred_td.tdno)
                WHERE f_succ.state IN ('CURRENT', 'CANCELLED')
                  AND COALESCE(f_pred_id.objid, f_pred_td.objid) IS NOT NULL
                GROUP BY COALESCE(f_pred_id.objid, f_pred_td.objid)
            ) succ_link ON (f.state = 'CURRENT' AND f.objid = succ_link.canonical_pred_id)
        ";

        $total = $wpdb->get_var("SELECT COUNT(*) FROM $t_faas f WHERE f.state != 'DELETED'");
        $current = $wpdb->get_var("SELECT COUNT(*) FROM $t_faas f $joins_succ WHERE f.state = 'CURRENT' AND succ_link.is_superseded IS NULL");
        $cancelled = $wpdb->get_var("SELECT COUNT(*) FROM $t_faas f $joins_succ WHERE f.state = 'CANCELLED' OR (f.state = 'CURRENT' AND succ_link.is_superseded IS NOT NULL)");
        $forapproval = $wpdb->get_var("SELECT COUNT(*) FROM $t_faas f WHERE f.state = 'FORAPPROVAL'");
        $interim = $wpdb->get_var("SELECT COUNT(*) FROM $t_faas f WHERE f.state IN ('INTERIM', 'PENDING')");
        $disapproved = $wpdb->get_var("SELECT COUNT(*) FROM $t_faas f WHERE f.state = 'DISAPPROVED'");

        $by_type = $wpdb->get_results("
            SELECT r.rpu_type, COUNT(*) as count
            FROM $t_faas f
            LEFT JOIN $t_rpu r ON f.rpuid = r.objid
            WHERE f.state != 'DELETED'
            GROUP BY r.rpu_type
        ");

        return rest_ensure_response(array(
            'total'       => intval($total),
            'current'     => intval($current),
            'cancelled'   => intval($cancelled),
            'forapproval' => intval($forapproval),
            'interim'     => intval($interim),
            'disapproved' => intval($disapproved),
            'by_type'     => $by_type
        ));
    }

    // ──────────────────────────────────────────────
    // PRIVATE HELPERS
    // ──────────────────────────────────────────────

    private function upsert_entity($params, $existing_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'assessor_entity';

        $name = sanitize_text_field($params['owner_name'] ?? $params['entity_name'] ?? '');
        $address = sanitize_textarea_field($params['owner_address'] ?? $params['entity_address'] ?? '');
        $type = sanitize_text_field($params['entity_type'] ?? 'INDIVIDUAL');

        if ($existing_id) {
            // Guard: Check if existing entity is REPLICA
            $existing_origin = $wpdb->get_var($wpdb->prepare("SELECT origin_type FROM $table WHERE objid = %s", $existing_id));
            if ($existing_origin && strtoupper($existing_origin) === 'REPLICA') {
                // Do not mutate source REPLICA entity. Create a new LOCAL entity instead.
                $new_objid = wp_generate_uuid4();
                $wpdb->insert($table, array(
                    'objid'        => $new_objid,
                    'origin_type'  => 'LOCAL',
                    'entityno'     => 'ENT-' . time() . '-' . substr($new_objid, 0, 8),
                    'name'         => $name,
                    'entityname'   => $name,
                    'address_text' => $address,
                    'type'         => $type,
                ));
                return $new_objid;
            }

            $wpdb->update($table, array(
                'name' => $name,
                'entityname' => $name,
                'address_text' => $address,
            ), array('objid' => $existing_id));
            return $existing_id;
        }

        // Try to find by exact name match
        $found = $wpdb->get_row($wpdb->prepare(
            "SELECT objid, origin_type FROM $table WHERE entityname = %s LIMIT 1",
            $name
        ));
        if ($found) {
            // Reusing an existing entity ID as a taxpayer reference is valid in ETRACS lineage,
            // returning its objid without mutating the entity row.
            return $found->objid;
        }

        // Create new
        $objid = wp_generate_uuid4();
        $wpdb->insert($table, array(
            'objid' => $objid,
            'origin_type' => 'LOCAL',
            'entityno' => 'ENT-' . time() . '-' . substr($objid, 0, 8),
            'name' => $name,
            'entityname' => $name,
            'address_text' => $address,
            'type' => $type,
        ));

        return $objid;
    }

    private function create_real_property($params, $previd = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'assessor_real_property';

        $objid = wp_generate_uuid4();
        $data = array(
            'objid'            => $objid,
            'origin_type'      => 'LOCAL',
            'pin'              => sanitize_text_field($params['pin'] ?? ''),
            'cadastrallotno'  => sanitize_text_field($params['cadastral_lot_no'] ?? $params['lot_number'] ?? ''),
            'surveyno'        => sanitize_text_field($params['survey_no'] ?? ''),
            'blockno'         => sanitize_text_field($params['block_no'] ?? ''),
            'barangay'         => sanitize_text_field($params['barangay'] ?? ''),
            'municipality'     => sanitize_text_field($params['municipality'] ?? ''),
            'province'         => sanitize_text_field($params['province'] ?? ''),
            'north'            => sanitize_text_field($params['north'] ?? ''),
            'south'            => sanitize_text_field($params['south'] ?? ''),
            'east'             => sanitize_text_field($params['east'] ?? ''),
            'west'             => sanitize_text_field($params['west'] ?? ''),
            'previd'           => !empty($previd) ? sanitize_text_field($previd) : (!empty($params['real_property_previd']) ? sanitize_text_field($params['real_property_previd']) : null),
        );

        $cols = $wpdb->get_col("DESCRIBE `$table`", 0);
        if (!empty($cols)) {
            $data = array_intersect_key($data, array_flip($cols));
        }

        $wpdb->insert($table, $data);
        return $objid;
    }

    private function update_real_property($id, $params) {
        global $wpdb;
        $table = $wpdb->prefix . 'assessor_real_property';

        // Guard: Protect REPLICA real property from being modified by local editing
        $existing_origin = $wpdb->get_var($wpdb->prepare("SELECT origin_type FROM $table WHERE objid = %s", $id));
        if ($existing_origin && strtoupper($existing_origin) === 'REPLICA') {
            return;
        }

        $fields = array('pin', 'cadastrallotno', 'surveyno', 'blockno', 'barangay', 'municipality', 'province', 'north', 'south', 'east', 'west');
        $update = array();
        foreach ($fields as $f) {
            $param_key = $f;
            if ($f === 'cadastrallotno' && !isset($params[$f]) && isset($params['lot_number'])) $param_key = 'lot_number';
            if ($f === 'cadastrallotno' && !isset($params[$f]) && isset($params['cadastral_lot_no'])) $param_key = 'cadastral_lot_no';
            if ($f === 'surveyno' && !isset($params[$f]) && isset($params['survey_no'])) $param_key = 'survey_no';
            if ($f === 'blockno' && !isset($params[$f]) && isset($params['block_no'])) $param_key = 'block_no';
            if (isset($params[$param_key])) {
                $update[$f] = sanitize_text_field($params[$param_key]);
            }
        }
        
        if (!empty($update)) {
            $cols = $wpdb->get_col("DESCRIBE `$table`", 0);
            if (!empty($cols)) {
                $update = array_intersect_key($update, array_flip($cols));
            }
            $wpdb->update($table, $update, array('objid' => $id));
        }
    }

    private function create_rpu($params, $real_property_id, $previd = null, $rpumasterid = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'assessor_rpu';

        $objid = wp_generate_uuid4();
        $data = array(
            'objid'                => $objid,
            'origin_type'          => 'LOCAL',
            'realpropertyid'       => $real_property_id,
            'rpu_type'             => strtoupper(sanitize_text_field($params['rpu_type'] ?? 'LAND')),
            'classification'       => sanitize_text_field($params['classification'] ?? ''),
            'ry'                   => intval($params['ry'] ?? $params['revision_year'] ?? 0),
            'total_market_value'   => isset($params['total_market_value']) && $params['total_market_value'] !== '' ? floatval($params['total_market_value']) : 0,
            'total_assessed_value' => isset($params['total_assessed_value']) && $params['total_assessed_value'] !== '' ? floatval($params['total_assessed_value']) : 0,
            'taxable'              => intval($params['taxable'] ?? 1),
            'previd'               => !empty($previd) ? sanitize_text_field($previd) : (!empty($params['rpu_previd']) ? sanitize_text_field($params['rpu_previd']) : null),
            'rpumasterid'          => !empty($rpumasterid) ? sanitize_text_field($rpumasterid) : (!empty($params['rpumasterid']) ? sanitize_text_field($params['rpumasterid']) : null),
        );

        $cols = $wpdb->get_col("DESCRIBE `$table`", 0);
        if (!empty($cols)) {
            $data = array_intersect_key($data, array_flip($cols));
        }

        $res = $wpdb->insert($table, $data);
        if ($res === false) {
            throw new Exception('Failed to create RPU record: ' . $wpdb->last_error);
        }
        return $objid;
    }

    private function update_rpu($id, $params) {
        global $wpdb;
        $table = $wpdb->prefix . 'assessor_rpu';

        // Guard: Protect REPLICA RPU from being modified by local editing
        $existing_origin = $wpdb->get_var($wpdb->prepare("SELECT origin_type FROM $table WHERE objid = %s", $id));
        if ($existing_origin && strtoupper($existing_origin) === 'REPLICA') {
            return;
        }

        $update = array();
        if (isset($params['rpu_type'])) $update['rpu_type'] = strtoupper(sanitize_text_field($params['rpu_type']));
        if (isset($params['classification'])) $update['classification'] = sanitize_text_field($params['classification']);
        if (isset($params['ry']) || isset($params['revision_year'])) $update['ry'] = intval($params['ry'] ?? $params['revision_year']);
        if (array_key_exists('total_market_value', $params)) $update['total_market_value'] = floatval($params['total_market_value']);
        if (array_key_exists('total_assessed_value', $params)) $update['total_assessed_value'] = floatval($params['total_assessed_value']);
        if (isset($params['taxable'])) $update['taxable'] = intval($params['taxable']);

        if (!empty($update)) {
            $wpdb->update($table, $update, array('objid' => $id));
        }
    }

    public function get_barangays() {
        global $wpdb;
        $table = $wpdb->prefix . 'assessor_barangay';
        return $wpdb->get_results("SELECT objid, name FROM $table ORDER BY name ASC");
    }

    public function get_exemption_types() {
        global $wpdb;
        $table = $wpdb->prefix . 'assessor_exemptiontype';
        return $wpdb->get_results("SELECT objid, name FROM $table ORDER BY name ASC");
    }

    public function get_classifications() {
        global $wpdb;
        $table = $wpdb->prefix . 'assessor_propertyclassification';
        return $wpdb->get_results("SELECT objid, code, name, special, orderno, state FROM $table ORDER BY orderno ASC, name ASC");
    }

    public function get_faas_signatory($id) {
        global $wpdb;
        $table = $wpdb->prefix . 'assessor_faas_signatory';
        $record = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE objid = %s", $id));
        if (!$record) {
            return array();
        }
        return $record;
    }

    public function get_building_lookups($request = null) {
        global $wpdb;
        $results = array();
        
        $t_kind = $wpdb->prefix . 'assessor_bldgkind';
        $t_type = $wpdb->prefix . 'assessor_bldgtype';
        $t_bucc = $wpdb->prefix . 'assessor_bldgkindbucc';
        $t_use = $wpdb->prefix . 'assessor_bldguse';
        $t_material = $wpdb->prefix . 'assessor_material';
        $t_class = $wpdb->prefix . 'assessor_propertyclassification';
        $t_rysetting = $wpdb->prefix . 'assessor_bldgrysetting';
        
        $ry = null;
        if (is_array($request) && !empty($request['ry'])) {
            $ry = intval($request['ry']);
        } elseif (is_object($request) && method_exists($request, 'get_param') && $request->get_param('ry')) {
            $ry = intval($request->get_param('ry'));
        }

        $ry_setting_id = null;
        if ($ry) {
            $ry_setting_id = $wpdb->get_var($wpdb->prepare("SELECT objid FROM $t_rysetting WHERE ry = %d ORDER BY objid DESC LIMIT 1", $ry));
        }
        if (!$ry_setting_id) {
            // Default to active/latest revision setting (e.g. 2022)
            $ry_setting_id = $wpdb->get_var("SELECT objid FROM $t_rysetting ORDER BY ry DESC LIMIT 1");
        }

        if ($ry_setting_id) {
            $results['types'] = $wpdb->get_results($wpdb->prepare("SELECT objid, code, name FROM $t_type WHERE bldgrysettingid = %s ORDER BY code ASC", $ry_setting_id));
            $results['unitCosts'] = $wpdb->get_results($wpdb->prepare("SELECT objid, bldgkind_objid, bldgtypeid, basevalue, basevaluetype FROM $t_bucc WHERE bldgrysettingid = %s", $ry_setting_id));
        } else {
            // Deduplicate by code if no setting ID found
            $results['types'] = $wpdb->get_results("SELECT objid, code, name FROM $t_type GROUP BY code ORDER BY code ASC");
            $results['unitCosts'] = $wpdb->get_results("SELECT objid, bldgkind_objid, bldgtypeid, basevalue, basevaluetype FROM $t_bucc");
        }

        $results['kinds'] = $wpdb->get_results("SELECT objid, code, name FROM $t_kind ORDER BY name ASC");
        $results['actualUses'] = $wpdb->get_results("SELECT objid, code, name FROM $t_class ORDER BY name ASC");
        $results['materials'] = $wpdb->get_results("SELECT objid, name FROM $t_material ORDER BY name ASC");
        $results['classifications'] = $wpdb->get_results("SELECT objid, code, name, special, orderno, state FROM $t_class ORDER BY orderno ASC, name ASC");
        
        return $results;
    }

    public function get_building_revision_settings($request = null) {
        global $wpdb;
        $t_rysetting = $wpdb->prefix . 'assessor_bldgrysetting';
        $t_type = $wpdb->prefix . 'assessor_bldgtype';
        $t_kind = $wpdb->prefix . 'assessor_bldgkind';
        $t_bucc = $wpdb->prefix . 'assessor_bldgkindbucc';
        $t_class = $wpdb->prefix . 'assessor_propertyclassification';

        $ry = null;
        if (is_array($request) && !empty($request['ry'])) {
            $ry = intval($request['ry']);
        } elseif (is_object($request) && method_exists($request, 'get_param') && $request->get_param('ry')) {
            $ry = intval($request->get_param('ry'));
        }

        if ($ry) {
            $setting = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_rysetting WHERE ry = %d ORDER BY objid DESC LIMIT 1", $ry));
        } else {
            $setting = $wpdb->get_row("SELECT * FROM $t_rysetting ORDER BY ry DESC LIMIT 1");
        }

        if (!$setting) {
            return new WP_Error('not_found', 'Building Revision Setting not found', array('status' => 404));
        }

        $setting_id = $setting->objid;
        $types = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t_type WHERE bldgrysettingid = %s ORDER BY code ASC", $setting_id));
        $unitCosts = $wpdb->get_results($wpdb->prepare("SELECT bucc.*, k.code as kind_code, k.name as kind_name FROM $t_bucc bucc LEFT JOIN $t_kind k ON bucc.bldgkind_objid = k.objid WHERE bucc.bldgrysettingid = %s ORDER BY k.name ASC", $setting_id));
        $classifications = $wpdb->get_results("SELECT * FROM $t_class ORDER BY orderno ASC, name ASC");

        $allSettings = $wpdb->get_results("SELECT objid, ry, ordinanceno, ordinancedate, remarks FROM $t_rysetting ORDER BY ry DESC");

        return array(
            'setting' => $setting,
            'types' => $types,
            'unitCosts' => $unitCosts,
            'classifications' => $classifications,
            'allSettings' => $allSettings
        );
    }

    public function get_rpu_detail($request) {
        global $wpdb;
        $id = is_array($request) ? ($request['id'] ?? '') : (is_object($request) && method_exists($request, 'get_param') ? $request->get_param('id') : (is_string($request) ? $request : ($request->id ?? '')));

        $t_rpu = $wpdb->prefix . 'assessor_rpu';
        
        $rpu = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_rpu WHERE objid = %s", $id));
        if (!$rpu) {
            return new WP_Error('not_found', 'RPU not found', array('status' => 404));
        }

        $assessments = $this->get_rpu_assessments($id);
        $rpu->assessments = !empty($assessments) ? $assessments : [];

        $rpu_type = strtoupper($rpu->rpu_type);

        if ($rpu_type === 'LAND') {
            // Fetch land RPU summary
            $t_landrpu = $wpdb->prefix . 'assessor_landrpu';
            $landrpu = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_landrpu WHERE objid = %s", $id));
            if ($landrpu) {
                $rpu->landrpu = $landrpu;
            }

            // Fetch land details (subclass, area, unit value, etc.)
            $t_landdetail = $wpdb->prefix . 'assessor_landdetail';
            $rpu->landdetail = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t_landdetail WHERE landrpuid = %s", $id));

            // Fetch plant/tree RPU if linked to this land
            $t_planttreerpu = $wpdb->prefix . 'assessor_planttreerpu';
            $rpu->planttrees = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t_planttreerpu WHERE landrpuid = %s", $id));

        } elseif ($rpu_type === 'BLDG') {
            // Fetch building subtype data
            $t_bldgrpu = $wpdb->prefix . 'assessor_bldgrpu';
            $t_bldgtype = $wpdb->prefix . 'assessor_bldgtype';
            $t_bldgkindbucc = $wpdb->prefix . 'assessor_bldgkindbucc';
            $t_bldgkind = $wpdb->prefix . 'assessor_bldgkind';

            // Building Brief Description resolution:
            // On the Philippine Tax Declaration form, the building "Brief Description" represents
            // the Building Kind/Use (e.g., "ONE FAMILY DWELLING" / "SINGLE FAMILY DWELLING"),
            // whereas "Structural Type" represents the structural classification (e.g., "TYPE IIA").
            $subtype = $wpdb->get_row($wpdb->prepare("
                SELECT br.*,
                       COALESCE(bk.name, br.bldgtypename, bt.name) as resolved_bldgtypename,
                       bk.name as bldgkind_name,
                       bt.name as bldgtype_name
                FROM $t_bldgrpu br
                LEFT JOIN $t_bldgtype bt ON br.bldgtype_objid = bt.objid
                LEFT JOIN $t_bldgkindbucc bu ON br.bldgkindbucc_objid = bu.objid
                LEFT JOIN $t_bldgkind bk ON bu.bldgkind_objid = bk.objid
                WHERE br.objid = %s
            ", $id));

            if ($subtype) {
                if (empty($subtype->bldgkind_name) && !empty($subtype->resolved_bldgtypename)) {
                    $subtype->bldgkind_name = $subtype->resolved_bldgtypename;
                }
                $rpu->subtype = $subtype;
            }

            // Fetch structuraltype
            $t_bldgrpu_structuraltype = $wpdb->prefix . 'assessor_bldgrpu_structuraltype';
            $structuraltype = $wpdb->get_row($wpdb->prepare("
                SELECT stt.*,
                       bt.name as bldgtype_name,
                       bk.name as bldgkind_name
                FROM $t_bldgrpu_structuraltype stt
                LEFT JOIN $t_bldgtype bt ON stt.bldgtype_objid = bt.objid
                LEFT JOIN $t_bldgkindbucc bu ON stt.bldgkindbucc_objid = bu.objid
                LEFT JOIN $t_bldgkind bk ON bu.bldgkind_objid = bk.objid
                WHERE stt.bldgrpuid = %s
                LIMIT 1
            ", $id));

            if ($structuraltype) {
                $rpu->structuraltype = $structuraltype;
                if ($subtype) {
                    if (empty($subtype->bldgkind_name) && !empty($structuraltype->bldgkind_name)) {
                        $subtype->bldgkind_name = $structuraltype->bldgkind_name;
                    }
                    if (empty($subtype->bldgtype_name) && !empty($structuraltype->bldgtype_name)) {
                        $subtype->bldgtype_name = $structuraltype->bldgtype_name;
                    }
                    if (empty($subtype->bldgtypename)) {
                        $subtype->bldgtypename = $structuraltype->bldgkind_name ?: ($structuraltype->bldgtype_name ?: '');
                    }
                }
            }

            // Fetch floors
            $t_bldgfloor = $wpdb->prefix . 'assessor_bldgfloor';
            $rpu->floors = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t_bldgfloor WHERE bldgrpuid = %s ORDER BY floorno ASC", $id));
            
            // Fetch floor additionals (building additional items per floor)
            $t_bldgflooradditional = $wpdb->prefix . 'assessor_bldgflooradditional';
            $t_bldgadditionalitem = $wpdb->prefix . 'assessor_bldgadditionalitem';
            $rpu->floorAdditionals = $wpdb->get_results($wpdb->prepare("
                SELECT fa.*, ai.code as item_code, ai.name as item_name, ai.unit as item_unit
                FROM $t_bldgflooradditional fa
                LEFT JOIN $t_bldgadditionalitem ai ON fa.additionalitem_objid = ai.objid
                WHERE fa.bldgrpuid = %s
            ", $id));

            // Fetch structures
            $t_bldgstructure = $wpdb->prefix . 'assessor_bldgstructure';
            $t_structure = $wpdb->prefix . 'assessor_structure';
            $t_material = $wpdb->prefix . 'assessor_material';
            $rpu->structures = $wpdb->get_results($wpdb->prepare("
                SELECT bs.*, s.name as structure_name, m.name as material_name
                FROM $t_bldgstructure bs
                LEFT JOIN $t_structure s ON bs.structure_objid = s.objid
                LEFT JOIN $t_material m ON bs.material_objid = m.objid
                WHERE bs.bldgrpuid = %s
            ", $id));
            
            // Fetch uses
            $t_bldguse = $wpdb->prefix . 'assessor_bldguse';
            $rpu->uses = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t_bldguse WHERE bldgrpuid = %s", $id));

        } elseif ($rpu_type === 'MACH') {
            // Fetch machinery RPU summary
            $t_machrpu = $wpdb->prefix . 'assessor_machrpu';
            $machrpu = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_machrpu WHERE objid = %s", $id));
            if ($machrpu) {
                $rpu->machrpu = $machrpu;
            }

            // Fetch machdetail items joined with machine lookup table
            $t_machdetail = $wpdb->prefix . 'assessor_machdetail';
            $t_machine = $wpdb->prefix . 'assessor_machine';
            $t_machine_smv = $wpdb->prefix . 'assessor_machine_smv';

            $machdetails = $wpdb->get_results($wpdb->prepare("
                SELECT md.*,
                       COALESCE(m.name, md.brand, md.model) as machinename,
                       m.code as machinecode,
                       m.name as machine_name
                FROM $t_machdetail md
                LEFT JOIN $t_machine m ON md.machine_objid = m.objid
                WHERE md.machrpuid = %s
            ", $id));

            if (!empty($machdetails)) {
                $rpu->machdetails = $machdetails;
                $rpu->machines = $machdetails;
            } else {
                // Fallback to machine_smv if machdetail is not present
                $rpu->machines = $wpdb->get_results($wpdb->prepare("
                    SELECT ms.*,
                           m.name as machinename,
                           m.code as machinecode
                    FROM $t_machine_smv ms
                    LEFT JOIN $t_machine m ON ms.machine_objid = m.objid
                    WHERE ms.parent_objid = %s
                ", $id));
                $rpu->machdetails = $rpu->machines;
            }

        } elseif ($rpu_type === 'MISC') {
            // Fetch misc RPU summary
            $t_miscrpu = $wpdb->prefix . 'assessor_miscrpu';
            $miscrpu = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_miscrpu WHERE objid = %s", $id));
            if ($miscrpu) {
                $rpu->miscrpu = $miscrpu;
            }

            // Fetch misc RPU items joined with miscitem lookup table
            $t_miscrpuitem = $wpdb->prefix . 'assessor_miscrpuitem';
            $t_miscitem = $wpdb->prefix . 'assessor_miscitem';
            $rpu->miscitems = $wpdb->get_results($wpdb->prepare("
                SELECT mri.*,
                       COALESCE(mi.name, mri.miscitemname) as miscitemname,
                       COALESCE(mi.name, mri.miscitemname) as miscitem_name,
                       mi.code as miscitem_code
                FROM $t_miscrpuitem mri
                LEFT JOIN $t_miscitem mi ON mri.miscitem_objid = mi.objid
                WHERE mri.miscrpuid = %s
            ", $id));

        } elseif ($rpu_type === 'PLANTTREE') {
            // Fetch plant/tree RPU
            $t_planttreerpu = $wpdb->prefix . 'assessor_planttreerpu';
            $planttreerpu = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_planttreerpu WHERE objid = %s", $id));
            if ($planttreerpu) {
                $rpu->planttreerpu = $planttreerpu;
            }

            // Fetch plant/tree details joined with planttree lookup table
            $t_planttreedetail = $wpdb->prefix . 'assessor_planttreedetail';
            $t_planttree = $wpdb->prefix . 'assessor_planttree';
            $rpu->planttreedetails = $wpdb->get_results($wpdb->prepare("
                SELECT ptd.*,
                       pt.name as planttreename,
                       pt.name as planttree_name,
                       pt.code as planttree_code
                FROM $t_planttreedetail ptd
                LEFT JOIN $t_planttree pt ON ptd.planttree_objid = pt.objid
                WHERE ptd.planttreerpuid = %s OR ptd.landrpuid = %s
            ", $id, $id));
        }

        return array(
            'data' => $rpu
        );
    }

    // ──────────────────────────────────────────────
    // PREDECESSOR LINEAGE & HISTORICAL SNAPSHOT PERSISTENCE (WP-03)
    // ──────────────────────────────────────────────

    /**
     * Persists predecessor lineage and immutable historical snapshots in wp_assessor_faas_previous.
     *
     * In accordance with WP-01 (ownership preservation), WP-02 (effective lifecycle overlay), and WP-03:
     * - Lineage rows are explicitly authored with origin_type = 'LOCAL'.
     * - Supports single predecessor (prevfaasid / prevtdno) and multiple predecessors (consolidation).
     * - Captures historical values (owner, pin, assessed value, market value, areas, administrator) at capture time.
     * - Re-editing/re-submitting a draft preserves established historical snapshot values (idempotent upsert).
     * - If prevtdno is ambiguous (matches > 1 record) and no explicit prevfaasid is provided:
     *   * In draft mode: sets prevfaasid to NULL and attaches structured warning [AMBIGUOUS_TD_LINEAGE].
     *   * On approval ($is_approval = true): throws an Exception / returns WP_Error to refuse arbitrary predecessor supersession.
     *
     * @param string $faas_id Successor FAAS objid
     * @param array $params Input parameters (from create, update, or approve)
     * @param bool $is_approval Whether this persistence is triggered during final approval
     * @return true|WP_Error
     */
    public function persist_predecessor_lineage($faas_id, $params, $is_approval = false) {
        global $wpdb;

        $t_faas = $wpdb->prefix . 'assessor_faas';
        $t_faas_previous = $wpdb->prefix . 'assessor_faas_previous';
        $t_rpu = $wpdb->prefix . 'assessor_rpu';

        $has_prev_table = ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t_faas_previous)) === $t_faas_previous);
        if (!$has_prev_table) {
            return true;
        }

        // 1. Normalize predecessor list
        $pred_inputs = array();
        if (!empty($params['predecessors']) && is_array($params['predecessors'])) {
            foreach ($params['predecessors'] as $p) {
                if (is_array($p)) {
                    $pred_inputs[] = $p;
                } elseif (is_string($p) && trim($p) !== '') {
                    $pred_inputs[] = array('prevtdno' => trim($p));
                }
            }
        } elseif (!empty($params['prevfaasid']) || !empty($params['prevtdno'])) {
            $prev_td = sanitize_text_field($params['prevtdno'] ?? '');
            if ($prev_td !== '-' && $prev_td !== '') {
                // If prevtdno contains delimited multiple TDs (e.g. "TD-1 | TD-2" or "TD-1, TD-2")
                if (strpos($prev_td, ' | ') !== false || strpos($prev_td, ', ') !== false) {
                    $delimiter = (strpos($prev_td, ' | ') !== false) ? ' | ' : ', ';
                    $split_tds = array_map('trim', explode($delimiter, $prev_td));
                    foreach ($split_tds as $std) {
                        if ($std !== '') {
                            $pred_inputs[] = array(
                                'prevtdno' => $std,
                            );
                        }
                    }
                } else {
                    $pred_inputs[] = array(
                        'prevfaasid'          => !empty($params['prevfaasid']) ? sanitize_text_field($params['prevfaasid']) : null,
                        'prevtdno'            => $prev_td,
                        'prevowner'           => !empty($params['prev_owner']) ? sanitize_text_field($params['prev_owner']) : null,
                        'prevpin'             => !empty($params['prev_pin']) ? sanitize_text_field($params['prev_pin']) : null,
                        'prevadministrator'   => !empty($params['prev_administrator']) ? sanitize_text_field($params['prev_administrator']) : null,
                        'prevav'              => !empty($params['prev_assessed_value']) ? (string)$params['prev_assessed_value'] : null,
                        'prevmv'              => !empty($params['prev_market_value']) ? (string)$params['prev_market_value'] : null,
                        'prevareasqm'         => !empty($params['prev_area_sqm']) ? (string)$params['prev_area_sqm'] : null,
                        'prevareaha'          => !empty($params['prev_area_hectare']) ? (string)$params['prev_area_hectare'] : null,
                        'preveffectivity'     => !empty($params['prev_effectivity']) ? sanitize_text_field($params['prev_effectivity']) : null,
                    );
                }
            } elseif (!empty($params['prevfaasid'])) {
                $pred_inputs[] = array(
                    'prevfaasid' => sanitize_text_field($params['prevfaasid']),
                    'prevtdno'   => ''
                );
            }
        }

        // If no predecessor inputs provided, nothing to write
        if (empty($pred_inputs)) {
            return true;
        }

        // Fetch existing lineage rows for this successor to preserve established historical snapshots
        $existing_lineage_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $t_faas_previous WHERE faasid = %s",
            $faas_id
        ), ARRAY_A);

        $existing_by_pred_id = array();
        $existing_by_pred_td = array();
        foreach ($existing_lineage_rows as $erow) {
            if (!empty($erow['prevfaasid'])) {
                $existing_by_pred_id[$erow['prevfaasid']] = $erow;
            }
            if (!empty($erow['prevtdno'])) {
                $existing_by_pred_td[$erow['prevtdno']] = $erow;
            }
        }

        $existing_cols = $wpdb->get_col("DESCRIBE `$t_faas_previous`", 0);
        $col_flip = !empty($existing_cols) ? array_flip($existing_cols) : array();

        $processed_row_ids = array();

        foreach ($pred_inputs as $input) {
            $input_faas_id = !empty($input['prevfaasid']) ? sanitize_text_field($input['prevfaasid']) : null;
            $input_td = !empty($input['prevtdno']) ? sanitize_text_field($input['prevtdno']) : '';

            // Check if matching lineage row already exists
            $matched_existing = null;
            if ($input_faas_id && isset($existing_by_pred_id[$input_faas_id])) {
                $matched_existing = $existing_by_pred_id[$input_faas_id];
            } elseif ($input_td && isset($existing_by_pred_td[$input_td])) {
                $matched_existing = $existing_by_pred_td[$input_td];
            }

            // Resolve predecessor record from DB for snapshot capture & validation
            $resolved_pred = null;
            $is_ambiguous = false;

            if ($input_faas_id) {
                $resolved_pred = $wpdb->get_row($wpdb->prepare(
                    "SELECT f.objid, f.tdno, f.fullpin, f.owner_name, f.administrator_name, f.rpuid, f.origin_type,
                            r.total_assessed_value, r.total_market_value, r.total_area_sqm, r.total_area_hectare
                     FROM $t_faas f
                     LEFT JOIN $t_rpu r ON f.rpuid = r.objid
                     WHERE f.objid = %s",
                    $input_faas_id
                ));
            }

            if (!$resolved_pred && !empty($input_td)) {
                $matched_preds = $wpdb->get_results($wpdb->prepare(
                    "SELECT f.objid, f.tdno, f.fullpin, f.owner_name, f.administrator_name, f.rpuid, f.origin_type,
                            r.total_assessed_value, r.total_market_value, r.total_area_sqm, r.total_area_hectare
                     FROM $t_faas f
                     LEFT JOIN $t_rpu r ON f.rpuid = r.objid
                     WHERE f.tdno = %s AND f.state != 'DELETED'",
                    $input_td
                ));

                if (count($matched_preds) === 1) {
                    $resolved_pred = $matched_preds[0];
                } elseif (count($matched_preds) > 1) {
                    $is_ambiguous = true;
                }
            }

            // Ambiguity check on approval
            if ($is_ambiguous && $is_approval && empty($input_faas_id)) {
                return new WP_Error(
                    'ambiguous_predecessor',
                    "Predecessor TD '{$input_td}' is ambiguous as it matches multiple declarations. Explicit selection via prevfaasid is required before approval.",
                    array('status' => 400, 'tdno' => $input_td)
                );
            }

            // If an existing lineage row is already present, retain its immutable snapshot values
            if ($matched_existing) {
                $processed_row_ids[] = $matched_existing['objid'];

                $update_fields = array();
                // Update stable prevfaasid if newly resolved or explicitly supplied
                if (empty($matched_existing['prevfaasid'])) {
                    if ($input_faas_id) {
                        $update_fields['prevfaasid'] = $input_faas_id;
                    } elseif ($resolved_pred) {
                        $update_fields['prevfaasid'] = $resolved_pred->objid;
                    }
                }
                if (empty($matched_existing['prevrpuid']) && $resolved_pred && !empty($resolved_pred->rpuid)) {
                    $update_fields['prevrpuid'] = $resolved_pred->rpuid;
                }
                if ($input_td && empty($matched_existing['prevtdno'])) {
                    $update_fields['prevtdno'] = $input_td;
                }

                if (!empty($update_fields)) {
                    if (!empty($col_flip)) {
                        $update_fields = array_intersect_key($update_fields, $col_flip);
                    }
                    $wpdb->update($t_faas_previous, $update_fields, array('objid' => $matched_existing['objid']));
                }
                continue;
            }

            // Build fresh snapshot record
            $row_objid = wp_generate_uuid4();
            $pred_faas_id = $input_faas_id ? $input_faas_id : ($resolved_pred ? $resolved_pred->objid : null);
            $pred_rpu_id  = $resolved_pred ? $resolved_pred->rpuid : null;
            $pred_td_val  = !empty($input_td) ? $input_td : ($resolved_pred ? $resolved_pred->tdno : '');

            // Snapshot capture: prefer user-supplied snapshot -> DB observed values at capture time -> null
            $snap_owner = !empty($input['prevowner']) ? $input['prevowner'] : ($resolved_pred ? $resolved_pred->owner_name : null);
            $snap_admin = !empty($input['prevadministrator']) ? $input['prevadministrator'] : ($resolved_pred ? $resolved_pred->administrator_name : null);
            $snap_pin   = !empty($input['prevpin']) ? $input['prevpin'] : ($resolved_pred ? $resolved_pred->fullpin : null);
            $snap_av    = !empty($input['prevav']) ? (string)$input['prevav'] : (($resolved_pred && $resolved_pred->total_assessed_value !== null) ? (string)$resolved_pred->total_assessed_value : null);
            $snap_mv    = !empty($input['prevmv']) ? (string)$input['prevmv'] : (($resolved_pred && $resolved_pred->total_market_value !== null) ? (string)$resolved_pred->total_market_value : null);
            $snap_areasqm = !empty($input['prevareasqm']) ? (string)$input['prevareasqm'] : (($resolved_pred && $resolved_pred->total_area_sqm !== null) ? (string)$resolved_pred->total_area_sqm : null);
            $snap_areaha  = !empty($input['prevareaha']) ? (string)$input['prevareaha'] : (($resolved_pred && $resolved_pred->total_area_hectare !== null) ? (string)$resolved_pred->total_area_hectare : null);
            $snap_eff     = !empty($input['preveffectivity']) ? $input['preveffectivity'] : null;
            $snap_tax     = !empty($input['prevtaxability']) ? $input['prevtaxability'] : null;

            $new_lineage_row = array(
                'objid'              => $row_objid,
                'origin_type'        => 'LOCAL',
                'faasid'             => $faas_id,
                'prevfaasid'         => $pred_faas_id,
                'prevrpuid'          => $pred_rpu_id,
                'prevtdno'           => $pred_td_val,
                'prevpin'            => $snap_pin,
                'prevowner'          => $snap_owner,
                'prevadministrator'  => $snap_admin,
                'prevav'             => $snap_av,
                'prevmv'             => $snap_mv,
                'prevareasqm'        => $snap_areasqm,
                'prevareaha'         => $snap_areaha,
                'preveffectivity'    => $snap_eff,
                'prevtaxability'     => $snap_tax,
            );

            if (!empty($col_flip)) {
                $new_lineage_row = array_intersect_key($new_lineage_row, $col_flip);
            }

            $ins = $wpdb->insert($t_faas_previous, $new_lineage_row);
            if ($ins === false) {
                return new WP_Error('lineage_insert_failed', 'Failed to persist predecessor lineage: ' . $wpdb->last_error, array('status' => 500));
            }

            $processed_row_ids[] = $row_objid;
        }

        // Log WP-07 Audit Event: Predecessor Lineage Persistence
        $audit_logged = self::log_shadow_audit(array(
            'event_type'            => 'LINEAGE_PERSIST',
            'action_outcome'        => 'SUCCESS',
            'source_classification' => 'LOCAL_AUTHORING',
            'origin_type'           => 'LOCAL',
            'object_type'           => 'LINEAGE',
            'object_id'             => $faas_id,
            'faas_id'               => $faas_id,
            'changed_fields'        => array('faas_previous'),
            'details'               => array(
                'faas_id'           => $faas_id,
                'predecessor_count' => count($processed_row_ids),
                'row_ids'           => $processed_row_ids,
            ),
        ));
        if ($audit_logged === false) {
            return new WP_Error('lineage_audit_failed', 'Failed to record shadow audit event for predecessor lineage', array('status' => 500));
        }

        return true;
    }

    // ──────────────────────────────────────────────
    // RPU ASSESSMENT LINES PERSISTENCE (WP-04)
    // ──────────────────────────────────────────────

    /**
     * Persists RPU assessment schedule lines in wp_assessor_rpu_assessment.
     *
     * In accordance with WP-01 (ownership preservation), WP-02 (lifecycle overlay), and WP-04:
     * 1. Newly authored assessment lines explicitly receive origin_type = 'LOCAL'.
     * 2. Assessment lines are strictly linked to the specified RPU ($rpu_id).
     * 3. Editing a LOCAL draft updates its existing local assessment lines without creating duplicates.
     * 4. Removal/replacement of lines affects ONLY the appropriate LOCAL draft-owned rows. Never deletes or mutates REPLICA assessment rows.
     * 5. A write targeting a REPLICA RPU or its source-owned lines is rejected with WP_Error.
     * 6. Multiple actual-use/classification lines are persisted independently.
     * 7. Source identifiers and relationships for REPLICA records remain completely untouched.
     * 8. Raw values and units are preserved. Where safe and verified, aggregates (total_market_value, total_assessed_value, total_area_sqm, total_area_hectare)
     *    on wp_assessor_rpu are synchronized with the sum of assessment lines.
     *
     * @param string $rpu_id Target RPU objid
     * @param array $assessments Array of assessment schedule lines
     * @param bool $is_update Whether this is an update to an existing RPU
     * @return true|WP_Error
     */
    public function persist_rpu_assessments($rpu_id, $assessments, $is_update = false) {
        global $wpdb;

        $t_rpu = $wpdb->prefix . 'assessor_rpu';
        $t_ra = $wpdb->prefix . 'assessor_rpu_assessment';

        if (empty($rpu_id)) {
            return new WP_Error('invalid_rpu_id', 'RPU ID is required for assessment persistence', array('status' => 400));
        }

        // Verify RPU exists and check its ownership
        $rpu = $wpdb->get_row($wpdb->prepare("SELECT objid, origin_type, rpu_type FROM $t_rpu WHERE objid = %s", $rpu_id));
        if (!$rpu) {
            return new WP_Error('rpu_not_found', 'RPU not found for assessment persistence', array('status' => 404));
        }

        // Guard: REPLICA RPU cannot receive local assessment line modifications
        if (isset($rpu->origin_type) && strtoupper($rpu->origin_type) === 'REPLICA') {
            return new WP_Error('replica_protected', 'Cannot modify assessment lines of a REPLICA RPU', array('status' => 403));
        }

        // If $assessments is not an array, return error
        if (!is_array($assessments)) {
            return new WP_Error('invalid_assessments_payload', 'Assessments payload must be an array', array('status' => 400));
        }

        // Fetch valid columns of assessor_rpu_assessment to adapt safely to schema
        $cols = $wpdb->get_col("DESCRIBE `$t_ra`", 0);
        $col_flip = !empty($cols) ? array_flip($cols) : array();

        // 1. Fetch existing LOCAL assessment rows for this RPU
        $existing_local_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT objid FROM $t_ra WHERE rpuid = %s AND (origin_type = 'LOCAL' OR origin_type IS NULL)",
            $rpu_id
        ));
        $existing_local_ids = !empty($existing_local_rows) ? wp_list_pluck($existing_local_rows, 'objid') : array();

        $processed_ids = array();
        $calc_total_mv = 0.0;
        $calc_total_av = 0.0;
        $calc_total_sqm = 0.0;
        $calc_total_ha = 0.0;
        $has_line_items = false;

        foreach ($assessments as $line) {
            if (!is_array($line)) {
                continue;
            }

            $line_id = !empty($line['objid']) ? sanitize_text_field($line['objid']) : (!empty($line['id']) ? sanitize_text_field($line['id']) : null);

            // If line_id is provided, verify it does not belong to a REPLICA row
            if ($line_id) {
                $check_line = $wpdb->get_row($wpdb->prepare("SELECT objid, rpuid, origin_type FROM $t_ra WHERE objid = %s", $line_id));
                if ($check_line) {
                    if (isset($check_line->origin_type) && strtoupper($check_line->origin_type) === 'REPLICA') {
                        return new WP_Error('replica_protected', 'Cannot overwrite or modify a REPLICA assessment line', array('status' => 403));
                    }
                    if ($check_line->rpuid !== $rpu_id) {
                        return new WP_Error('rpu_mismatch', 'Assessment line belongs to another RPU', array('status' => 400));
                    }
                }
            }

            // Extract & sanitize line fields
            $actualuse = isset($line['actualuse']) ? sanitize_text_field($line['actualuse']) : '';
            $actualuse_objid = isset($line['actualuse_objid']) ? sanitize_text_field($line['actualuse_objid']) : null;
            $classcode = isset($line['classcode']) ? sanitize_text_field($line['classcode']) : null;
            $classname = isset($line['classname']) ? sanitize_text_field($line['classname']) : null;
            $classification_objid = isset($line['classification_objid']) ? sanitize_text_field($line['classification_objid']) : null;
            $rputype = isset($line['rputype']) ? strtoupper(sanitize_text_field($line['rputype'])) : ($rpu->rpu_type ?? 'LAND');

            $areasqm = isset($line['areasqm']) && $line['areasqm'] !== '' ? floatval($line['areasqm']) : 0.0;
            $areaha = isset($line['areaha']) && $line['areaha'] !== '' ? floatval($line['areaha']) : 0.0;
            $areatype = isset($line['areatype']) ? sanitize_text_field($line['areatype']) : ($areaha > 0 && $areasqm == 0 ? 'HA' : 'SQM');

            $marketvalue = isset($line['marketvalue']) && $line['marketvalue'] !== '' ? floatval($line['marketvalue']) : 0.0;
            $assesslevel = isset($line['assesslevel']) && $line['assesslevel'] !== '' ? floatval($line['assesslevel']) : 0.0;
            
            // For local assessment lines, maintain assessedvalue = marketvalue * (assesslevel / 100) if not explicitly set
            if (isset($line['assessedvalue']) && $line['assessedvalue'] !== '') {
                $assessedvalue = floatval($line['assessedvalue']);
            } else {
                $assessedvalue = round($marketvalue * ($assesslevel / 100.0), 2);
            }
            $taxable = isset($line['taxable']) ? intval($line['taxable']) : 1;

            // Calculate aggregate accumulators
            $calc_total_mv += $marketvalue;
            $calc_total_av += $assessedvalue;
            $calc_total_sqm += $areasqm;
            $calc_total_ha += $areaha;
            $has_line_items = true;

            $line_data = array(
                'rpuid'                => $rpu_id,
                'origin_type'          => 'LOCAL',
                'actualuse_objid'      => $actualuse_objid,
                'classification_objid' => $classification_objid,
                'rputype'              => $rputype,
                'areasqm'              => $areasqm,
                'areaha'               => $areaha,
                'marketvalue'          => $marketvalue,
                'assesslevel'          => $assesslevel,
                'assessedvalue'        => $assessedvalue,
                'taxable'              => $taxable,
            );

            // Strict schema allowlist: only the 11 source business columns plus origin_type
            $allowed_assessment_cols = array(
                'objid'                => true,
                'rpuid'                => true,
                'classification_objid' => true,
                'actualuse_objid'      => true,
                'areasqm'              => true,
                'areaha'               => true,
                'marketvalue'          => true,
                'assesslevel'          => true,
                'assessedvalue'        => true,
                'rputype'              => true,
                'taxable'              => true,
                'origin_type'          => true,
            );

            // Filter by strict allowlist, and if target table has restricted columns, by valid table columns
            $line_data = array_intersect_key($line_data, $allowed_assessment_cols);
            if (!empty($col_flip)) {
                $line_data = array_intersect_key($line_data, $col_flip);
            }

            if ($line_id && in_array($line_id, $existing_local_ids, true)) {
                // Update existing LOCAL row
                $upd = $wpdb->update($t_ra, $line_data, array('objid' => $line_id, 'origin_type' => 'LOCAL'));
                if ($upd === false) {
                    return new WP_Error('update_failed', 'Failed to update assessment line: ' . $wpdb->last_error, array('status' => 500));
                }
                $processed_ids[] = $line_id;
            } else {
                // Insert new LOCAL row
                $new_objid = ($line_id && !in_array($line_id, $existing_local_ids, true)) ? $line_id : wp_generate_uuid4();
                $line_data['objid'] = $new_objid;
                $ins = $wpdb->insert($t_ra, $line_data);
                if ($ins === false) {
                    return new WP_Error('insert_failed', 'Failed to insert assessment line: ' . $wpdb->last_error, array('status' => 500));
                }
                $processed_ids[] = $new_objid;
            }
        }

        // 2. Remove any LOCAL rows that were excluded in this update (if updating)
        // Ensure REPLICA rows are NEVER deleted
        $to_delete = array_diff($existing_local_ids, $processed_ids);
        if (!empty($to_delete)) {
            foreach ($to_delete as $del_id) {
                $del = $wpdb->query($wpdb->prepare(
                    "DELETE FROM $t_ra WHERE objid = %s AND rpuid = %s AND origin_type = 'LOCAL'",
                    $del_id,
                    $rpu_id
                ));
                if ($del === false) {
                    return new WP_Error('delete_failed', 'Failed to remove stale assessment line: ' . $wpdb->last_error, array('status' => 500));
                }
            }
        }

        // 3. Keep RPU aggregates consistent when line items were supplied
        if ($has_line_items) {
            $rpu_cols = $wpdb->get_col("DESCRIBE `$t_rpu`", 0);
            $rpu_col_flip = !empty($rpu_cols) ? array_flip($rpu_cols) : array();

            $rpu_updates = array(
                'total_market_value'   => $calc_total_mv,
                'total_assessed_value' => $calc_total_av,
            );
            if ($calc_total_sqm > 0 || $calc_total_ha > 0) {
                if (isset($rpu_col_flip['total_area_sqm'])) $rpu_updates['total_area_sqm'] = $calc_total_sqm;
                if (isset($rpu_col_flip['total_area_hectare'])) $rpu_updates['total_area_hectare'] = $calc_total_ha;
            }
            $wpdb->update($t_rpu, $rpu_updates, array('objid' => $rpu_id, 'origin_type' => 'LOCAL'));
        }

        // Log WP-07 Audit Event: RPU Assessment Line Persistence
        $audit_logged = self::log_shadow_audit(array(
            'event_type'            => 'ASSESSMENT_UPDATE',
            'action_outcome'        => 'SUCCESS',
            'source_classification' => 'LOCAL_AUTHORING',
            'origin_type'           => 'LOCAL',
            'object_type'           => 'RPU_ASSESSMENT',
            'object_id'             => $rpu_id,
            'rpu_id'                => $rpu_id,
            'changed_fields'        => array('assessments', 'total_market_value', 'total_assessed_value'),
            'details'               => array(
                'rpu_id'              => $rpu_id,
                'line_count'          => count($processed_ids),
                'total_market_value'  => $calc_total_mv,
                'total_assessed_value'=> $calc_total_av,
            ),
        ));
        if ($audit_logged === false) {
            return new WP_Error('assessment_audit_failed', 'Failed to record shadow audit event for RPU assessments', array('status' => 500));
        }

        return true;
    }

    /**
     * REST handler to update assessment schedule lines on an RPU.
     *
     * @param string $rpuid
     * @param WP_REST_Request|array $request
     * @return WP_REST_Response|WP_Error
     */
    public function update_rpu_assessments($rpuid, $request) {
        $params = is_array($request) ? $request : $request->get_json_params();
        if (empty($params) && is_object($request) && method_exists($request, 'get_params')) {
            $params = $request->get_params();
        }
        $assessments = isset($params['assessments']) ? $params['assessments'] : (is_array($params) && isset($params[0]) ? $params : array());
        
        $res = $this->persist_rpu_assessments($rpuid, $assessments, true);
        if (is_wp_error($res)) {
            return $res;
        }

        return rest_ensure_response(array(
            'success'     => true,
            'message'     => 'Assessment lines updated successfully',
            'assessments' => $this->get_rpu_assessments($rpuid),
        ));
    }

    /**
     * Helper to retrieve assessment lines for an RPU.
     *
     * @param string $rpuid
     * @return array
     */
    public function get_rpu_assessments($rpuid) {
        if (!defined('ARRAY_A')) {
            define('ARRAY_A', 'ARRAY_A');
        }
        global $wpdb;
        $t_ra = $wpdb->prefix . 'assessor_rpu_assessment';
        $t_la = $wpdb->prefix . 'assessor_landassesslevel';
        $t_ba = $wpdb->prefix . 'assessor_bldgassesslevel';
        $t_ma = $wpdb->prefix . 'assessor_machassesslevel';
        $t_pa = $wpdb->prefix . 'assessor_planttreeassesslevel';
        $t_mia = $wpdb->prefix . 'assessor_miscassesslevel';
        $t_pc = $wpdb->prefix . 'assessor_propertyclassification';

        // Check if lookup tables exist
        $has_lookups = ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t_la)) === $t_la);

        if ($has_lookups) {
            $query = "
                SELECT 
                    ra.*,
                    COALESCE(la.name, ba.name, ma.name, pa.name, mia.name) AS actualuse,
                    pc.code AS classcode,
                    pc.name AS classname
                FROM $t_ra ra
                LEFT JOIN $t_la la ON LOWER(ra.rputype) = 'land' AND la.objid = ra.actualuse_objid
                LEFT JOIN $t_ba ba ON LOWER(ra.rputype) = 'bldg' AND ba.objid = ra.actualuse_objid
                LEFT JOIN $t_ma ma ON LOWER(ra.rputype) = 'mach' AND ma.objid = ra.actualuse_objid
                LEFT JOIN $t_pa pa ON LOWER(ra.rputype) = 'planttree' AND pa.objid = ra.actualuse_objid
                LEFT JOIN $t_mia mia ON LOWER(ra.rputype) = 'misc' AND mia.objid = ra.actualuse_objid
                LEFT JOIN $t_pc pc ON pc.objid = ra.classification_objid
                WHERE ra.rpuid = %s
                ORDER BY ra.objid ASC
            ";
        } else {
            $query = "SELECT ra.* FROM $t_ra ra WHERE ra.rpuid = %s ORDER BY ra.objid ASC";
        }

        $rows = $wpdb->get_results($wpdb->prepare($query, $rpuid), ARRAY_A);
        return !empty($rows) ? $rows : array();
    }
}


