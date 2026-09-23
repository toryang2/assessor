<?php

/**
 * Assessor Sync Receiver
 *
 * Runs on the LIVE website. Exposes REST endpoints consumed by local builds:
 *
 *   GET  /assessor/v1/sync/health     — connectivity ping (public, no auth)
 *   POST /assessor/v1/sync/push       — receive batch of property records from local
 *   GET  /assessor/v1/sync/pull       — serve records changed since ?since= to local
 *
 * Authentication: X-Sync-Token header must match ASSESSOR_SYNC_TOKEN in wp-config.php.
 * Both the local site and the live site must define the same token value.
 */
class Assessor_Sync_Receiver {

    // -------------------------------------------------------------------------
    // Token authentication
    // -------------------------------------------------------------------------

    /**
     * Permission callback for sync endpoints.
     * Returns true when X-Sync-Token matches the configured constant.
     */
    public function verify_sync_token($request) {
        if (!defined('ASSESSOR_SYNC_TOKEN') || empty(ASSESSOR_SYNC_TOKEN)) {
            return new WP_Error(
                'sync_not_configured',
                'Sync token is not configured on this server. Add define(\'ASSESSOR_SYNC_TOKEN\', \'...\') to wp-config.php.',
                array('status' => 503)
            );
        }

        $token = $request->get_header('x_sync_token');
        if (empty($token)) {
            $token = $request->get_header('x-sync-token');
        }

        if (empty($token) || !hash_equals((string) ASSESSOR_SYNC_TOKEN, (string) $token)) {
            return new WP_Error('invalid_sync_token', 'Invalid or missing X-Sync-Token.', array('status' => 403));
        }

        return true;
    }

    // -------------------------------------------------------------------------
    // GET /assessor/v1/sync/health
    // -------------------------------------------------------------------------

    /**
     * Lightweight health check — no auth required.
     * Local builds use this to test connectivity before attempting push/pull.
     */
    public function health($request) {
        global $wpdb;
        $db_server_ts = $wpdb->get_var("SELECT NOW()");
        return array(
            'status'    => 'ok',
            'server_ts' => $db_server_ts ? $db_server_ts : Assessor_Timezone::now_mysql(),
        );
    }

    // -------------------------------------------------------------------------
    // POST /assessor/v1/sync/push
    // -------------------------------------------------------------------------

    /**
     * Receive a batch of property records from a local build and upsert them.
     * Applies last-write-wins: skips any record where local updated_at <= live updated_at.
     *
     * Request body: { "records": [ { property fields... }, ... ] }
     *
     * Response: {
     *   "results": {
     *     "<tax_declaration_number>": { "status": "synced"|"skipped"|"error", "message": "..." }
     *   },
     *   "summary": { "synced": N, "skipped": N, "errors": N }
     * }
     */
    public function receive_push($request) {
        $params  = $request->get_json_params();
        $records = isset($params['records']) ? $params['records'] : array();

        if (!is_array($records) || empty($records)) {
            return new WP_Error('no_records', 'No records provided in request body.', array('status' => 400));
        }

        // Cap batch size to prevent abuse
        if (count($records) > 200) {
            return new WP_Error('batch_too_large', 'Maximum 200 records per push request.', array('status' => 400));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'assessor_properties';

        $results = array();
        $synced  = 0;
        $skipped = 0;
        $errors  = 0;

        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }

            // Check if this record is a request
            $record_type = isset($record['_record_type']) ? $record['_record_type'] : '';
            if (empty($record_type) && isset($record['receipt_number']) && !isset($record['tax_declaration_number'])) {
                $record_type = 'request';
            }

            if ($record_type === 'request') {
                $table_requests = $wpdb->prefix . 'assessor_requests';
                $result = $this->upsert_request($record, $table_requests, $wpdb);
                $req_id = isset($record['id']) ? trim((string)$record['id']) : '';
                $key = !empty($req_id) ? $req_id : '__missing_req_id_' . ($errors + 1);
                $results[$key] = $result;

                if ($result['status'] === 'synced') {
                    $synced++;
                } elseif ($result['status'] === 'skipped') {
                    $skipped++;
                } else {
                    $errors++;
                }
                continue;
            }

            $tax_num = isset($record['tax_declaration_number']) ? trim($record['tax_declaration_number']) : '';
            if ($tax_num === '') {
                $errors++;
                $results['__missing_tdn_' . $errors] = array(
                    'status'  => 'error',
                    'message' => 'Missing tax_declaration_number',
                );
                continue;
            }

            $result = $this->upsert_property($record, $table, $wpdb);

            $prop_id = isset($record['id']) ? trim((string)$record['id']) : '';
            $key = !empty($prop_id) ? $prop_id : $tax_num;
            $results[$key] = $result;
            // Also store under TDN for backward compatibility if not colliding
            if (!empty($tax_num) && !isset($results[$tax_num])) {
                $results[$tax_num] = $result;
            }
            if ($result['status'] === 'synced') {
                $synced++;
            } elseif ($result['status'] === 'skipped') {
                $skipped++;
            } else {
                $errors++;
            }
        }

        update_option('assessor_last_local_push', Assessor_Timezone::now_mysql());

        return array(
            'results' => $results,
            'summary' => array(
                'synced'  => $synced,
                'skipped' => $skipped,
                'errors'  => $errors,
            ),
        );
    }

    /**
     * Upsert a single property record with last-write-wins.
     *
     * @return array { status: 'synced'|'skipped'|'error', message?: string }
     */
    private function upsert_property($record, $table, $wpdb) {
        $tax_num = trim($record['tax_declaration_number']);
        $prop_id = isset($record['id']) ? trim((string)$record['id']) : '';
        $revision_id = isset($record['revision_id']) && !empty($record['revision_id']) ? trim((string)$record['revision_id']) : null;

        // Exact match by UUID first
        $existing = null;
        if (!empty($prop_id)) {
            $existing = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, updated_at FROM $table WHERE id = %s LIMIT 1",
                    $prop_id
                ),
                ARRAY_A
            );
        }

        // Secondary fallback: match by (tax_declaration_number, revision_id) if UUID didn't match
        if (!$existing && !empty($tax_num) && !empty($revision_id)) {
            $existing = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, updated_at FROM $table WHERE tax_declaration_number = %s AND revision_id = %s LIMIT 1",
                    $tax_num,
                    $revision_id
                ),
                ARRAY_A
            );
        }

        $remote_ts   = isset($record['updated_at']) ? strtotime($record['updated_at']) : 0;
        $existing_ts = $existing ? strtotime($existing['updated_at']) : 0;

        // Skip if live record is same age or newer (live wins)
        if ($existing && $existing_ts >= $remote_ts) {
            return array('status' => 'skipped', 'message' => 'Live record is up to date');
        }

        $property_state = isset($record['property_state'])
            ? strtoupper(trim((string)$record['property_state']))
            : null;

        $record_for_property = $record;
        if ($property_state !== null) {
            unset($record_for_property['property_state']);
        }

        $safe = $this->sanitize_incoming_record($record_for_property);

        if ($existing) {
            // UPDATE existing record
            $updated = $wpdb->update($table, $safe, array('id' => $existing['id']), null, array('%s'));
            if ($updated === false) {
                return array('status' => 'error', 'message' => $wpdb->last_error);
            }
            $live_property_id = $existing['id'];
        } else {
            // INSERT new record (preserve incoming UUID v7 from local build, or generate fresh one)
            if (empty($safe['id'])) {
                $safe['id'] = class_exists('Assessor_UUID') ? Assessor_UUID::v7() : wp_generate_uuid4();
            }
            if (empty($safe['created_by'])) {
                $safe['created_by'] = null;
            }
            if (empty($safe['updated_by'])) {
                $safe['updated_by'] = null;
            }
            $inserted = $wpdb->insert($table, $safe);
            if ($inserted === false) {
                return array('status' => 'error', 'message' => $wpdb->last_error);
            }
            $live_property_id = $safe['id'];
        }

        if (class_exists('Assessor_Audit')) {
            $audit = new Assessor_Audit();
            $audit->log_activity(0, 'SYNC_FROM_LOCAL', $table, $live_property_id, null, array('tax_declaration_number' => $tax_num));
        }

        // Process pushed documents
        if (!empty($record['assessor_documents']) && is_array($record['assessor_documents'])) {
            $table_docs = $wpdb->prefix . 'assessor_documents';
            $upload_dir = wp_upload_dir();
            $base_dir = $upload_dir['basedir'] . '/assessor-documents';
            
            $tdn_safe = preg_replace('/[^A-Za-z0-9_.\-]/', '_', $tax_num);
            $prop_id_safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)$live_property_id);
            if (!empty($tdn_safe) && !empty($prop_id_safe)) {
                $folder_name = $tdn_safe . '_' . $prop_id_safe;
            } elseif (!empty($tdn_safe)) {
                $folder_name = $tdn_safe;
            } elseif (!empty($prop_id_safe)) {
                $folder_name = $prop_id_safe;
            } else {
                $folder_name = 'unknown';
            }
            $property_dir = $base_dir . '/' . $folder_name;
            if (!file_exists($property_dir)) {
                wp_mkdir_p($property_dir);
            }
            
            foreach ($record['assessor_documents'] as $doc) {
                $existing_doc = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_docs WHERE filename = %s LIMIT 1", $doc['filename']));
                
                if (!empty($doc['file_data']) && !$existing_doc) {
                    $file_data = base64_decode($doc['file_data']);
                    if ($file_data !== false) {
                        $file_path = $property_dir . '/' . sanitize_file_name($doc['filename']);
                        file_put_contents($file_path, $file_data);
                        
                        $wpdb->insert($table_docs, array(
                            'property_id' => $live_property_id,
                            'filename' => sanitize_file_name($doc['filename']),
                            'original_filename' => sanitize_text_field($doc['original_filename']),
                            'file_path' => $file_path,
                            'file_type' => sanitize_text_field($doc['file_type']),
                            'description' => sanitize_textarea_field($doc['description'] ?? ''),
                            'uploaded_by' => 0
                        ), array('%s', '%s', '%s', '%s', '%s', '%s', '%s'));
                    }
                }
            }
        }

        // Sync property state if provided
        if ($property_state !== null && $property_state !== '') {
            $table_property_states = $wpdb->prefix . 'assessor_property_states';
            $wpdb->replace($table_property_states, array(
                'property_id' => $live_property_id,
                'state'       => $property_state,
            ), array('%s', '%s'));
        }

        return array('status' => 'synced');
    }

    /**
     * Upsert a single request record with last-write-wins and soft-delete support.
     *
     * @return array { status: 'synced'|'skipped'|'error', message?: string }
     */
    private function upsert_request($record, $table, $wpdb) {
        $req_id = isset($record['id']) ? trim((string)$record['id']) : '';
        if (empty($req_id)) {
            return array('status' => 'error', 'message' => 'Missing request ID (UUID)');
        }

        // Exact match by UUID
        $existing = $wpdb->get_row(
            $wpdb->prepare("SELECT id, updated_at, deleted_at FROM $table WHERE id = %s LIMIT 1", $req_id),
            ARRAY_A
        );

        $remote_ts   = isset($record['updated_at']) ? strtotime($record['updated_at']) : 0;
        $existing_ts = $existing ? strtotime($existing['updated_at']) : 0;

        // Skip if live record is same age or newer (live wins)
        if ($existing && $existing_ts >= $remote_ts) {
            return array('status' => 'skipped', 'message' => 'Live record is up to date');
        }

        $safe = $this->sanitize_incoming_request_record($record);

        if ($existing) {
            // UPDATE existing record
            $updated = $wpdb->update($table, $safe, array('id' => $existing['id']), null, array('%s'));
            if ($updated === false) {
                return array('status' => 'error', 'message' => $wpdb->last_error);
            }
        } else {
            // INSERT new record (preserve incoming UUID v7!)
            $inserted = $wpdb->insert($table, $safe);
            if ($inserted === false) {
                return array('status' => 'error', 'message' => $wpdb->last_error);
            }
        }

        if (class_exists('Assessor_Audit')) {
            $audit = new Assessor_Audit();
            $action = !empty($safe['deleted_at']) ? 'SYNC_DELETE_REQUEST' : 'SYNC_REQUEST_FROM_LOCAL';
            $audit->log_activity(0, $action, $table, $req_id, null, array('receipt_number' => $record['receipt_number'] ?? ''));
        }

        return array('status' => 'synced');
    }

    // -------------------------------------------------------------------------
    // GET /assessor/v1/sync/pull
    // -------------------------------------------------------------------------

    /**
     * Return all property records updated since ?since= (MySQL datetime string).
     * The local build calls this after a push to get any changes made directly on live.
     *
     * Query params:
     *   since  (required) — MySQL datetime e.g. "2025-01-01 00:00:00"
     *   limit  (optional) — default 500, max 1000
     *
     * Response: { "records": [ { ... }, ... ], "count": N, "since": "...", "server_ts": "..." }
     */
    public function serve_pull($request) {
        $params = $request->get_params();

        $since = isset($params['since']) ? sanitize_text_field($params['since']) : '';
        if (empty($since)) {
            return new WP_Error('missing_since', 'Query parameter "since" is required (MySQL datetime).', array('status' => 400));
        }

        // Validate datetime format loosely
        if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $since)) {
            return new WP_Error('invalid_since', '"since" must be a valid datetime string (YYYY-MM-DD ...).', array('status' => 400));
        }

        $limit  = isset($params['limit']) ? min(2000, max(1, intval($params['limit']))) : 500;
        $offset = isset($params['offset']) ? max(0, intval($params['offset'])) : 0;

        $type = isset($params['type']) ? sanitize_text_field($params['type']) : 'properties';

        global $wpdb;
        $db_server_ts = $wpdb->get_var("SELECT NOW()");
        if (empty($db_server_ts)) {
            $db_server_ts = Assessor_Timezone::now_mysql();
        }

        error_log(
            'Assessor Sync Pull Cursor: since=' . $since .
            ' db_now=' . $db_server_ts .
            ' type=' . $type
        );

        if ($type === 'users') {
            $table_users = $wpdb->prefix . 'assessor_users';
            $records = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM $table_users ORDER BY id ASC LIMIT %d OFFSET %d",
                    $limit,
                    $offset
                ),
                ARRAY_A
            );
            
            error_log('Assessor Sync Pull: returned=' . count($records ?: array()));

            return array(
                'records'   => $records ?: array(),
                'count'     => count($records ?: array()),
                'since'     => $since,
                'server_ts' => $db_server_ts,
            );
        }

        if ($type === 'requests') {
            $table_requests = $wpdb->prefix . 'assessor_requests';
            // Pull records updated since $since, including soft-deleted ones (deleted_at IS NOT NULL)
            $records = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM $table_requests WHERE updated_at > %s ORDER BY updated_at ASC, id ASC LIMIT %d OFFSET %d",
                    $since,
                    $limit,
                    $offset
                ),
                ARRAY_A
            );

            $safe_records = array();
            if ($records) {
                foreach ($records as $record) {
                    $safe = $this->sanitize_outgoing_request_record($record);
                    $safe['_record_type'] = 'request';
                    $safe_records[] = $safe;
                }
            }

            error_log('Assessor Sync Pull: returned=' . count($safe_records));

            update_option('assessor_last_local_pull_requests', Assessor_Timezone::now_mysql());

            return array(
                'records'   => $safe_records,
                'count'     => count($safe_records),
                'since'     => $since,
                'server_ts' => $db_server_ts,
            );
        }

        $table = $wpdb->prefix . 'assessor_properties';

        $records = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE updated_at > %s ORDER BY updated_at ASC LIMIT %d OFFSET %d",
                $since,
                $limit,
                $offset
            ),
            ARRAY_A
        );

        if ($records === null) {
            $records = array();
        }

        $table_docs = $wpdb->prefix . 'assessor_documents';

        // Strip internal IDs that should not overwrite local user assignments
        $safe_records = array();
        foreach ($records as $record) {
            $safe = $this->sanitize_outgoing_record($record);
            
            // Attach documents metadata (excluding local-only uploaded_by)
            $docs = $wpdb->get_results(
                $wpdb->prepare("SELECT filename, original_filename, file_path, file_type, description, uploaded_at FROM $table_docs WHERE property_id = %s", $record['id']),
                ARRAY_A
            );
            $safe['assessor_documents'] = $docs ? $docs : array();
            
            // Attach property state
            $table_states = $wpdb->prefix . 'assessor_property_states';
            $property_state = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT state
                     FROM $table_states
                     WHERE property_id = %s
                     LIMIT 1",
                    $record['id']
                )
            );

            $safe['property_state'] = $property_state !== null
                ? strtoupper(trim($property_state))
                : 'CURRENT';

            error_log(
                'Assessor Sync Pull: property_id=' . $record['id'] .
                ' tax_declaration_number=' . ($record['tax_declaration_number'] ?? '') .
                ' property_state=' . ($safe['property_state'] ?? 'NULL')
            );
            
            $safe_records[] = $safe;
        }

        error_log('Assessor Sync Pull: returned=' . count($safe_records));

        update_option('assessor_last_local_pull', Assessor_Timezone::now_mysql());

        return array(
            'records'   => $safe_records,
            'count'     => count($safe_records),
            'since'     => $since,
            'server_ts' => $db_server_ts,
        );
    }

    // -------------------------------------------------------------------------
    // POST /assessor/v1/sync/push-config
    // -------------------------------------------------------------------------

    /**
     * Receive a full snapshot of a config table from a local build and replace it on live.
     *
     * Strategy: DELETE all rows from the live table, then INSERT all received rows.
     * This is safe because config tables are small and local is authoritative.
     *
     * Request body: { "table": "assessor_general_classes", "rows": [ {...}, ... ] }
     * Response:     { "table": "...", "inserted": N, "server_ts": "..." }
     */
    public function receive_config_push($request) {
        $params = $request->get_json_params();

        $table_suffix = isset($params['table']) ? sanitize_key($params['table']) : '';
        $rows         = isset($params['rows'])  ? $params['rows']                : array();

        // Whitelist: only allow known config tables — never let arbitrary table names through
        $allowed_tables = array(
            'assessor_general_classes',
            'assessor_locations',
            'assessor_property_types',
            'assessor_request_purposes',
            'assessor_revision_entries',
        );

        if (!in_array($table_suffix, $allowed_tables, true)) {
            return new WP_Error(
                'invalid_table',
                "Table '$table_suffix' is not allowed for config sync.",
                array('status' => 400)
            );
        }

        if (!is_array($rows)) {
            return new WP_Error('invalid_rows', 'rows must be an array.', array('status' => 400));
        }

        global $wpdb;
        $table = $wpdb->prefix . $table_suffix;

        // Instead of wiping the live table (which deletes barangays added on live),
        // we will upsert (replace) records based on their unique keys.
        $inserted = 0;
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row)) {
                continue;
            }
            // Only allow scalar values — strip any nested arrays
            $clean = array_filter($row, 'is_scalar');
            if (empty($clean)) {
                continue;
            }
            
            // If 'id' is present and is a UUID string, preserve it so live and local share identical UUID v7 IDs.
            // Only strip if id is empty or numeric 0 to avoid DB errors on non-null PK columns.
            if (isset($clean['id']) && (empty($clean['id']) || (is_numeric($clean['id']) && intval($clean['id']) <= 0))) {
                unset($clean['id']);
            }

            // wpdb->replace uses REPLACE INTO, which updates if primary/unique key exists, or inserts if not
            $result = $wpdb->replace($table, $clean);
            if ($result !== false) {
                $inserted++;
            }
        }

        return array(
            'table'     => $table_suffix,
            'inserted'  => $inserted,
            'server_ts' => Assessor_Timezone::now_mysql(),
        );
    }

    /**
     * Serve full snapshots of the four lookup tables for bidirectional reconciliation.
     *
     * GET /assessor/v1/sync/pull-config
     * Returns the 4 lookup tables with original UUIDs and complete column sets.
     * Does NOT mutate any data.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function serve_config_pull($request) {
        global $wpdb;

        $lookup_tables = array(
            'assessor_property_types',
            'assessor_general_classes',
            'assessor_locations',
            'assessor_request_purposes',
        );

        $tables_data = array();

        foreach ($lookup_tables as $table_suffix) {
            $table = $wpdb->prefix . $table_suffix;
            $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));

            if ($table_exists === $table) {
                $rows = $wpdb->get_results("SELECT * FROM $table", ARRAY_A);
                $tables_data[$table_suffix] = $rows ? $rows : array();
            } else {
                $tables_data[$table_suffix] = array();
            }
        }

        return rest_ensure_response(array(
            'success'   => true,
            'tables'    => $tables_data,
            'server_ts' => Assessor_Timezone::now_mysql(),
        ));
    }

    // -------------------------------------------------------------------------
    // Sanitization helpers
    // -------------------------------------------------------------------------

    /**
     * Columns accepted from an incoming (local -> live) push.
     * Includes local user IDs so that authorship is preserved on the live site.
     */
    private function sanitize_incoming_record($record) {
        $allowed = array(
            'id', 'tax_declaration_number', 'previous_tax_declaration_number',
            'declarant_last_name', 'declarant_first_name', 'declarant_middle_initial',
            'business', 'business_name', 'location', 'lot_number',
            'unique_lot_number_identified', 'survey_number',
            'area_hectare', 'area_hectare_old', 'area_sqm', 'title_number',
            'assessed_value', 'assessed_value_old', 'effectivity_date', 'effectivity_exempt',
            'pin', 'address', 'assessment_date',
            'kind_of_property', 'gen_class', 'memoranda', 'supporting_documents',
            'supporting_documents_old', 'change_reason',
            'verifier_signatory_name', 'verifier_signatory_title',
            'municipal_assessor_name', 'municipal_assessor_suffix',
            'municipal_assessor_title', 'municipal_assessor_license',
            'status', 'revision_id', 'updated_at', 'created_at',
            'created_by', 'updated_by', 'property_state'
        );

        $safe = array();
        foreach ($allowed as $col) {
            if (array_key_exists($col, $record)) {
                $safe[$col] = $record[$col];
            }
        }
        return $safe;
    }

    /**
     * Columns sent to local builds in a pull response.
     * Includes local user IDs so authorship is preserved.
     */
    private function sanitize_outgoing_record($record) {
        $allowed = array(
            'id', 'tax_declaration_number', 'previous_tax_declaration_number',
            'declarant_last_name', 'declarant_first_name', 'declarant_middle_initial',
            'business', 'business_name', 'location', 'lot_number',
            'unique_lot_number_identified', 'survey_number',
            'area_hectare', 'area_hectare_old', 'area_sqm', 'title_number',
            'assessed_value', 'assessed_value_old', 'effectivity_date', 'effectivity_exempt',
            'pin', 'address', 'assessment_date',
            'kind_of_property', 'gen_class', 'memoranda', 'supporting_documents',
            'supporting_documents_old', 'change_reason',
            'verifier_signatory_name', 'verifier_signatory_title',
            'municipal_assessor_name', 'municipal_assessor_suffix',
            'municipal_assessor_title', 'municipal_assessor_license',
            'status', 'revision_id', 'updated_at', 'created_at',
            'created_by', 'updated_by', 'property_state'
        );

        $safe = array();
        foreach ($allowed as $col) {
            if (array_key_exists($col, $record)) {
                $safe[$col] = $record[$col];
            }
        }
        return $safe;
    }

    /**
     * Columns accepted from an incoming (local -> live) request push.
     */
    private function sanitize_incoming_request_record($record) {
        $allowed = array(
            'id', 'property_id', 'amount_paid', 'receipt_number',
            'is_official_request', 'date_issued', 'place_issued',
            'prepared_by', 'payment_type', 'purpose', 'purpose_details', 'client_name',
            'client_address', 'contact_number', 'email', 'remarks',
            'created_at', 'updated_at', 'created_by', 'updated_by',
            'deleted_at',
            'verifier_signatory_name', 'verifier_signatory_title',
            'municipal_assessor_name', 'municipal_assessor_suffix',
            'municipal_assessor_title', 'municipal_assessor_license'
        );

        $safe = array();
        foreach ($allowed as $col) {
            if (array_key_exists($col, $record)) {
                $safe[$col] = $record[$col];
            }
        }
        return $safe;
    }

    /**
     * Columns sent to local builds in a request pull response.
     */
    private function sanitize_outgoing_request_record($record) {
        $allowed = array(
            'id', 'property_id', 'amount_paid', 'receipt_number',
            'is_official_request', 'date_issued', 'place_issued',
            'prepared_by', 'payment_type', 'purpose', 'purpose_details', 'client_name',
            'client_address', 'contact_number', 'email', 'remarks',
            'created_at', 'updated_at', 'created_by', 'updated_by',
            'deleted_at',
            'verifier_signatory_name', 'verifier_signatory_title',
            'municipal_assessor_name', 'municipal_assessor_suffix',
            'municipal_assessor_title', 'municipal_assessor_license'
        );

        $safe = array();
        foreach ($allowed as $col) {
            if (array_key_exists($col, $record)) {
                $safe[$col] = $record[$col];
            }
        }
        return $safe;
    }

    /**
     * GET /assessor/v1/sync/revision-entries
     * Serve all revision entries from live site to local.
     */
    public function serve_revision_entries($request) {
        global $wpdb;

        $table = $wpdb->prefix . 'assessor_revision_entries';

        $rows = $wpdb->get_results(
            "SELECT
                id,
                revision_code,
                revision_year,
                from_year,
                to_year,
                status,
                sort_order,
                created_at,
                updated_at
             FROM $table
             ORDER BY sort_order ASC, id ASC",
            ARRAY_A
        );

        if ($rows === null) {
            $rows = array();
        }

        return array(
            'records'   => $rows,
            'count'     => count($rows),
            'server_ts' => Assessor_Timezone::now_mysql(),
        );
    }

    /**
     * POST /assessor/v1/sync/report/publish
     * Mirrored sync run receiver on Live site.
     * Authenticated via X-Sync-Token.
     */
    public function receive_publish_report($request) {
        $params = $request->get_json_params();
        if (empty($params['run']) || !is_array($params['run'])) {
            return new WP_Error('invalid_payload', 'Missing "run" payload.', array('status' => 400));
        }

        $run = $params['run'];
        $run_id = isset($run['id']) ? trim($run['id']) : '';
        if (empty($run_id)) {
            return new WP_Error('invalid_run_id', 'Run ID is required.', array('status' => 400));
        }

        global $wpdb;
        $table_runs = $wpdb->prefix . 'assessor_sync_runs';
        $table_meta = $wpdb->prefix . 'assessor_sync_meta';

        // Check if assessor_sync_runs exists on Live
        $runs_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_runs));
        if (!$runs_exists) {
            return new WP_Error('table_missing', 'assessor_sync_runs table is missing on Live.', array('status' => 500));
        }

        $mode         = isset($run['mode']) ? sanitize_text_field($run['mode']) : 'incremental';
        $status       = isset($run['status']) ? sanitize_text_field($run['status']) : 'completed';
        $started_at   = isset($run['started_at']) ? sanitize_text_field($run['started_at']) : Assessor_Timezone::now_mysql();
        $completed_at = isset($run['completed_at']) ? sanitize_text_field($run['completed_at']) : Assessor_Timezone::now_mysql();
        $created_at   = isset($run['created_at']) ? sanitize_text_field($run['created_at']) : Assessor_Timezone::now_mysql();
        $summary_json = isset($run['summary']) ? wp_json_encode($run['summary']) : '{}';

        // Upsert into assessor_sync_runs preserving original UUID
        $wpdb->query($wpdb->prepare(
            "INSERT INTO $table_runs (id, mode, status, started_at, completed_at, summary_json, created_at)
             VALUES (%s, %s, %s, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE
                mode         = VALUES(mode),
                status       = VALUES(status),
                started_at   = VALUES(started_at),
                completed_at = VALUES(completed_at),
                summary_json = VALUES(summary_json)",
            $run_id,
            $mode,
            $status,
            $started_at,
            $completed_at,
            $summary_json,
            $created_at
        ));

        // Update last_sync_run_id in assessor_sync_meta on Live
        $meta_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_meta));
        if ($meta_exists) {
            $uuid = class_exists('Assessor_UUID') ? Assessor_UUID::v7() : wp_generate_uuid4();
            $wpdb->query($wpdb->prepare(
                "INSERT INTO $table_meta (id, meta_key, meta_value) VALUES (%s, 'last_sync_run_id', %s)
                 ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
                $uuid,
                $run_id
            ));
        }

        return array(
            'success' => true,
            'message' => 'Sync run mirrored successfully.',
            'run_id'  => $run_id,
        );
    }

    /**
     * POST /assessor/v1/sync/report/publish-items
     * Mirrored sync run items batch receiver on Live site.
     * Authenticated via X-Sync-Token.
     */
    public function receive_publish_items($request) {
        $params = $request->get_json_params();
        $run_id = isset($params['run_id']) ? trim($params['run_id']) : '';
        $items  = isset($params['items']) && is_array($params['items']) ? $params['items'] : array();

        if (empty($run_id) || empty($items)) {
            return new WP_Error('invalid_payload', 'Missing run_id or items array.', array('status' => 400));
        }

        global $wpdb;
        $table_items = $wpdb->prefix . 'assessor_sync_run_items';

        $items_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_items));
        if (!$items_exists) {
            return new WP_Error('table_missing', 'assessor_sync_run_items table is missing on Live.', array('status' => 500));
        }

        $placeholders = array();
        $values       = array();

        foreach ($items as $item) {
            $item_id     = isset($item['id']) ? trim($item['id']) : (class_exists('Assessor_UUID') ? Assessor_UUID::v7() : wp_generate_uuid4());
            $record_type = isset($item['record_type']) ? sanitize_text_field($item['record_type']) : 'property';
            $record_id   = isset($item['record_id']) ? sanitize_text_field($item['record_id']) : '';
            $direction   = isset($item['direction']) ? sanitize_text_field($item['direction']) : 'local_to_live';
            $action      = isset($item['action']) ? sanitize_text_field($item['action']) : 'updated';
            $display_json = isset($item['display_data_json']) ? (string)$item['display_data_json'] : '{}';
            $created_at  = isset($item['created_at']) ? sanitize_text_field($item['created_at']) : Assessor_Timezone::now_mysql();

            $placeholders[] = "(%s, %s, %s, %s, %s, %s, %s, %s)";
            $values[] = $item_id;
            $values[] = $run_id;
            $values[] = $record_type;
            $values[] = $record_id;
            $values[] = $direction;
            $values[] = $action;
            $values[] = $display_json;
            $values[] = $created_at;
        }

        if (!empty($placeholders)) {
            $sql = "INSERT INTO $table_items (id, run_id, record_type, record_id, direction, action, display_data_json, created_at) VALUES "
                 . implode(', ', $placeholders)
                 . " ON DUPLICATE KEY UPDATE
                    action            = VALUES(action),
                    display_data_json = VALUES(display_data_json)";

            $wpdb->query($wpdb->prepare($sql, $values));
        }

        return array(
            'success' => true,
            'count'   => count($items),
            'run_id'  => $run_id,
        );
    }
}

