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
        return array(
            'status'    => 'ok',
            'server_ts' => current_time('mysql'),
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

            $results[$tax_num] = $result;
            if ($result['status'] === 'synced') {
                $synced++;
            } elseif ($result['status'] === 'skipped') {
                $skipped++;
            } else {
                $errors++;
            }
        }

        update_option('assessor_last_local_push', current_time('mysql'));

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

        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, updated_at FROM $table WHERE tax_declaration_number = %s LIMIT 1",
                $tax_num
            ),
            ARRAY_A
        );

        $remote_ts   = isset($record['updated_at']) ? strtotime($record['updated_at']) : 0;
        $existing_ts = $existing ? strtotime($existing['updated_at']) : 0;

        // Skip if live record is same age or newer (live wins)
        if ($existing && $existing_ts >= $remote_ts) {
            return array('status' => 'skipped', 'message' => 'Live record is up to date');
        }

        $safe = $this->sanitize_incoming_record($record);

        if ($existing) {
            // UPDATE existing record
            $updated = $wpdb->update($table, $safe, array('id' => intval($existing['id'])));
            if ($updated === false) {
                return array('status' => 'error', 'message' => $wpdb->last_error);
            }
            $live_property_id = intval($existing['id']);
        } else {
            // INSERT new record
            unset($safe['id']);
            if (empty($safe['created_by'])) {
                $safe['created_by'] = 0;
            }
            if (empty($safe['updated_by'])) {
                $safe['updated_by'] = 0;
            }
            $inserted = $wpdb->insert($table, $safe);
            if ($inserted === false) {
                return array('status' => 'error', 'message' => $wpdb->last_error);
            }
            $live_property_id = $wpdb->insert_id;
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
            if (empty($tdn_safe)) {
                $tdn_safe = (string)$live_property_id;
            }
            $property_dir = $base_dir . '/' . $tdn_safe;
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
                        ));
                    }
                }
            }
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
            
            return array(
                'records'   => $records ?: array(),
                'count'     => count($records ?: array()),
                'since'     => $since,
                'server_ts' => current_time('mysql'),
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
                $wpdb->prepare("SELECT filename, original_filename, file_path, file_type, description, uploaded_at FROM $table_docs WHERE property_id = %d", $record['id']),
                ARRAY_A
            );
            $safe['assessor_documents'] = $docs ? $docs : array();
            
            $safe_records[] = $safe;
        }

        update_option('assessor_last_local_pull', current_time('mysql'));

        return array(
            'records'   => $safe_records,
            'count'     => count($safe_records),
            'since'     => $since,
            'server_ts' => current_time('mysql'),
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
            
            // Remove 'id' so it matches on the UNIQUE keys (code, purpose, etc.) rather than overwriting unrelated IDs
            if (isset($clean['id'])) {
                unset($clean['id']);
            }

            // wpdb->replace uses REPLACE INTO, which updates if unique key exists, or inserts if not
            $result = $wpdb->replace($table, $clean);
            if ($result !== false) {
                $inserted++;
            }
        }

        return array(
            'table'     => $table_suffix,
            'inserted'  => $inserted,
            'server_ts' => current_time('mysql'),
        );
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
            'tax_declaration_number', 'previous_tax_declaration_number',
            'declarant_last_name', 'declarant_first_name', 'declarant_middle_initial',
            'business', 'business_name', 'location', 'lot_number',
            'unique_lot_number_identified', 'survey_number',
            'area_hectare', 'area_hectare_old', 'area_sqm', 'title_number',
            'assessed_value', 'assessed_value_old', 'effectivity_date',
            'pin', 'address', 'assessment_date',
            'kind_of_property', 'gen_class', 'memoranda', 'supporting_documents',
            'supporting_documents_old', 'change_reason',
            'verifier_signatory_name', 'verifier_signatory_title',
            'municipal_assessor_name', 'municipal_assessor_suffix',
            'municipal_assessor_title', 'municipal_assessor_license',
            'status', 'updated_at', 'created_at',
            'created_by', 'updated_by',
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
            'tax_declaration_number', 'previous_tax_declaration_number',
            'declarant_last_name', 'declarant_first_name', 'declarant_middle_initial',
            'business', 'business_name', 'location', 'lot_number',
            'unique_lot_number_identified', 'survey_number',
            'area_hectare', 'area_hectare_old', 'area_sqm', 'title_number',
            'assessed_value', 'assessed_value_old', 'effectivity_date',
            'pin', 'address', 'assessment_date',
            'kind_of_property', 'gen_class', 'memoranda', 'supporting_documents',
            'supporting_documents_old', 'change_reason',
            'verifier_signatory_name', 'verifier_signatory_title',
            'municipal_assessor_name', 'municipal_assessor_suffix',
            'municipal_assessor_title', 'municipal_assessor_license',
            'status', 'updated_at', 'created_at',
            'created_by', 'updated_by',
        );

        $safe = array();
        foreach ($allowed as $col) {
            if (array_key_exists($col, $record)) {
                $safe[$col] = $record[$col];
            }
        }
        return $safe;
    }
}
