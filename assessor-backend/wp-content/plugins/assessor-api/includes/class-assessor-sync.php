<?php

/**
 * Assessor Sync Engine
 *
 * Handles bidirectional sync between a local WordPress install and the live website.
 *
 * === SETUP (local wp-config.php) ===
 *   define('ASSESSOR_IS_LOCAL_BUILD', true);
 *   define('ASSESSOR_LIVE_SITE_URL',  'https://your-live-domain.com');
 *   define('ASSESSOR_SYNC_TOKEN',     'your-shared-secret-token');
 *
 * === SETUP (live wp-config.php) ===
 *   define('ASSESSOR_SYNC_TOKEN', 'your-shared-secret-token');
 *   // Do NOT define ASSESSOR_IS_LOCAL_BUILD on live.
 *
 * === HOW IT WORKS ===
 *   1. Local edits enqueue the property ID in wp_assessor_sync_queue (status=pending).
 *   2. Every 5 minutes WP-Cron fires assessor_sync_cron:
 *      a. PUSH  — sends pending records to POST /assessor/v1/sync/push on the live site.
 *      b. PULL  — fetches records changed on live since last pull via GET /assessor/v1/sync/pull.
 *   3. Conflict resolution: last-write-wins by updated_at timestamp.
 *   4. Writes that originate from sync do NOT re-enqueue (guarded by Assessor_Sync::$syncing flag).
 */
class Assessor_Sync {

    /** Prevents property-save hooks from re-enqueuing records written by the sync pull. */
    public static $syncing = false;

    /**
     * Config tables that are synced as complete snapshots (local -> live, one-directional).
     * These are small reference/lookup tables edited only by admins on the local build.
     * Key = table suffix (without wp_ prefix), Value = primary unique column used for REPLACE.
     */
    private static $config_tables = array(
        'assessor_general_classes'   => 'code',
        'assessor_locations'         => 'code',
        'assessor_property_types'    => 'code',
        'assessor_request_purposes'  => 'purpose',
        'assessor_revision_entries'  => 'revision_year',
        'assessor_settings'          => null, // single-row table, full replace
    );

    // -------------------------------------------------------------------------
    // Cron registration
    // -------------------------------------------------------------------------

    public static function register_cron() {
        if (!defined('ASSESSOR_IS_LOCAL_BUILD') || !ASSESSOR_IS_LOCAL_BUILD) {
            return;
        }
        if (!wp_next_scheduled('assessor_sync_cron')) {
            wp_schedule_event(time(), 'assessor_every_5_min', 'assessor_sync_cron');
        }
    }

    public static function add_cron_interval($schedules) {
        $schedules['assessor_every_5_min'] = array(
            'interval' => 300,
            'display'  => __('Every 5 Minutes (Assessor Sync)'),
        );
        return $schedules;
    }

    public static function deregister_cron() {
        $timestamp = wp_next_scheduled('assessor_sync_cron');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'assessor_sync_cron');
        }
    }

    // -------------------------------------------------------------------------
    // Queue management
    // -------------------------------------------------------------------------

    /**
     * Add or reset a property to the sync queue.
     * Called automatically after create_property() / update_property().
     * Skipped when Assessor_Sync::$syncing is true (write came from sync pull).
     */
    public static function enqueue_property($property_id, $operation = 'upsert') {
        if (self::$syncing) {
            return; // Came from sync — do not re-enqueue
        }
        if (!defined('ASSESSOR_IS_LOCAL_BUILD') || !ASSESSOR_IS_LOCAL_BUILD) {
            return; // Only queue on local builds
        }

        global $wpdb;
        $table = $wpdb->prefix . 'assessor_sync_queue';

        // INSERT ... ON DUPLICATE KEY UPDATE so re-edits reset back to 'pending'
        $wpdb->query($wpdb->prepare(
            "INSERT INTO $table (property_id, operation, status, attempts, last_error, queued_at, synced_at)
             VALUES (%d, %s, 'pending', 0, NULL, %s, NULL)
             ON DUPLICATE KEY UPDATE
                status     = 'pending',
                attempts   = 0,
                last_error = NULL,
                queued_at  = %s,
                synced_at  = NULL",
            $property_id,
            $operation,
            current_time('mysql'),
            current_time('mysql')
        ));
    }

    /**
     * Mark a config table as dirty so it will be pushed on the next sync cycle.
     * Called after any save/delete on: general_classes, locations, property_types,
     * request_purposes, revision_entries, settings.
     *
     * @param string $table_suffix  e.g. 'assessor_general_classes'
     */
    public static function enqueue_config_table($table_suffix) {
        if (self::$syncing) {
            return;
        }
        if (!defined('ASSESSOR_IS_LOCAL_BUILD') || !ASSESSOR_IS_LOCAL_BUILD) {
            return;
        }
        self::set_meta('config_dirty_' . $table_suffix, '1');
    }

    /**
     * Get a summary of the sync queue for the admin UI.
     */
    public static function get_queue_status() {
        if (!defined('ASSESSOR_IS_LOCAL_BUILD') || !ASSESSOR_IS_LOCAL_BUILD) {
            return array(
                'enabled'   => false,
                'live_url'  => '',
                'pending'   => 0,
                'synced'    => 0,
                'failed'    => 0,
                'skipped'   => 0,
                'last_push' => null,
                'last_pull' => null,
            );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'assessor_sync_queue';
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));

        $counts = array('pending' => 0, 'synced' => 0, 'failed' => 0, 'skipped' => 0);
        if ($table_exists) {
            $rows = $wpdb->get_results(
                "SELECT status, COUNT(*) AS cnt FROM $table GROUP BY status",
                ARRAY_A
            );
            foreach ($rows as $row) {
                $key = isset($row['status']) ? $row['status'] : '';
                if (isset($counts[$key])) {
                    $counts[$key] = intval($row['cnt']);
                }
            }
        }

        return array(
            'enabled'   => true,
            'live_url'  => defined('ASSESSOR_LIVE_SITE_URL') ? ASSESSOR_LIVE_SITE_URL : '',
            'pending'   => $counts['pending'],
            'synced'    => $counts['synced'],
            'failed'    => $counts['failed'],
            'skipped'   => $counts['skipped'],
            'last_push' => self::get_meta('last_push_at'),
            'last_pull' => self::get_meta('last_pull_at'),
        );
    }

    /**
     * Reset all 'failed' items back to 'pending' so they will be retried.
     */
    public static function clear_failed() {
        global $wpdb;
        $table = $wpdb->prefix . 'assessor_sync_queue';
        $wpdb->update(
            $table,
            array('status' => 'pending', 'attempts' => 0, 'last_error' => null),
            array('status' => 'failed'),
            array('%s', '%d', '%s'),
            array('%s')
        );
    }

    // -------------------------------------------------------------------------
    // Main cron callback — runs every 5 minutes on local
    // -------------------------------------------------------------------------

    public static function run_sync() {
        if (!defined('ASSESSOR_IS_LOCAL_BUILD') || !ASSESSOR_IS_LOCAL_BUILD) {
            return;
        }
        if (!self::is_online()) {
            error_log('Assessor Sync: No internet — skipping sync cycle.');
            return;
        }

        $push_result        = self::push_pending();
        $push_config_result = self::push_pending_config();
        $pull_result        = self::pull_from_live();

        error_log('Assessor Sync: Property push result: '  . json_encode($push_result));
        error_log('Assessor Sync: Config push result: '    . json_encode($push_config_result));
        error_log('Assessor Sync: Property pull result: '  . json_encode($pull_result));
    }

    /**
     * Triggered by admin "Sync Now" REST endpoint — runs push + pull immediately.
     */
    public static function manual_sync() {
        if (!self::is_online()) {
            return array(
                'success' => false,
                'message' => 'No internet connection. Cannot reach the live site.',
                'push'    => null,
                'config'  => null,
                'pull'    => null,
            );
        }

        $push   = self::push_pending();
        $config = self::push_pending_config();
        $pull   = self::pull_from_live();

        return array(
            'success' => true,
            'message' => 'Sync completed.',
            'push'    => $push,
            'config'  => $config,
            'pull'    => $pull,
        );
    }

    // -------------------------------------------------------------------------
    // PUSH  (local -> live)
    // -------------------------------------------------------------------------

    /**
     * Send all pending local records to the live site.
     *
     * @return array { pushed, skipped, errors }
     */
    public static function push_pending() {
        global $wpdb;
        $table_queue      = $wpdb->prefix . 'assessor_sync_queue';
        $table_properties = $wpdb->prefix . 'assessor_properties';

        $pending = $wpdb->get_results(
            "SELECT q.id AS queue_id, q.property_id, q.attempts
             FROM $table_queue q
             WHERE q.status = 'pending' AND q.operation = 'upsert'
             ORDER BY q.queued_at ASC
             LIMIT 100",
            ARRAY_A
        );

        if (empty($pending)) {
            return array('pushed' => 0, 'skipped' => 0, 'errors' => array());
        }

        // Collect full property rows
        $ids          = array_map('intval', array_column($pending, 'property_id'));
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $properties = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table_properties WHERE id IN ($placeholders)",
                $ids
            ),
            ARRAY_A
        );

        // Map property_id -> row
        $prop_map = array();
        foreach ($properties as $p) {
            $prop_map[$p['id']] = $p;
        }

        // Build records array keeping queue_id + tax number for response matching
        $records = array();
        foreach ($pending as $qi) {
            $pid = intval($qi['property_id']);
            if (isset($prop_map[$pid])) {
                $records[] = array(
                    'queue_id' => intval($qi['queue_id']),
                    'attempts' => intval($qi['attempts']),
                    'tax_num'  => $prop_map[$pid]['tax_declaration_number'],
                    'data'     => $prop_map[$pid],
                );
            }
        }

        if (empty($records)) {
            return array('pushed' => 0, 'skipped' => 0, 'errors' => array('Properties not found for queued IDs'));
        }

        $payload  = array('records' => array_column($records, 'data'));
        $response = self::live_api_request('POST', '/assessor/v1/sync/push', $payload);

        if (is_wp_error($response)) {
            $err_msg = $response->get_error_message();
            foreach ($pending as $qi) {
                $wpdb->update(
                    $table_queue,
                    array(
                        'status'     => 'failed',
                        'attempts'   => intval($qi['attempts']) + 1,
                        'last_error' => $err_msg,
                    ),
                    array('id' => intval($qi['queue_id'])),
                    array('%s', '%d', '%s'),
                    array('%d')
                );
            }
            return array('pushed' => 0, 'skipped' => 0, 'errors' => array($err_msg));
        }

        $body    = json_decode(wp_remote_retrieve_body($response), true);
        $results = isset($body['results']) ? $body['results'] : array();

        $pushed = 0;
        $skipped = 0;
        $errors  = array();

        foreach ($records as $rec) {
            $res_item   = isset($results[$rec['tax_num']]) ? $results[$rec['tax_num']] : array('status' => 'error', 'message' => 'No response from live site');
            $res_status = isset($res_item['status']) ? $res_item['status'] : 'error';

            if ($res_status === 'synced') {
                $queue_status = 'synced';
                $pushed++;
            } elseif ($res_status === 'skipped') {
                $queue_status = 'skipped';
                $skipped++;
            } else {
                $queue_status = 'failed';
                $errors[] = $rec['tax_num'] . ': ' . (isset($res_item['message']) ? $res_item['message'] : 'unknown error');
            }

            $wpdb->update(
                $table_queue,
                array(
                    'status'     => $queue_status,
                    'attempts'   => $rec['attempts'] + 1,
                    'last_error' => $queue_status === 'failed' ? (isset($res_item['message']) ? $res_item['message'] : 'unknown') : null,
                    'synced_at'  => $queue_status !== 'failed' ? current_time('mysql') : null,
                ),
                array('id' => $rec['queue_id']),
                array('%s', '%d', '%s', '%s'),
                array('%d')
            );
        }

        self::set_meta('last_push_at', current_time('mysql'));
        return array('pushed' => $pushed, 'skipped' => $skipped, 'errors' => $errors);
    }

    // -------------------------------------------------------------------------
    // CONFIG TABLE PUSH  (local -> live, full-table snapshot)
    // -------------------------------------------------------------------------

    /**
     * Push all dirty config tables to the live site.
     * Each dirty table is sent in full; live site does a complete replace.
     *
     * @return array { tables_pushed: [], tables_skipped: [], errors: [] }
     */
    public static function push_pending_config() {
        global $wpdb;
        $pushed  = array();
        $skipped = array();
        $errors  = array();

        foreach (self::$config_tables as $table_suffix => $unique_col) {
            $meta_key = 'config_dirty_' . $table_suffix;
            $dirty    = self::get_meta($meta_key);

            if ($dirty !== '1') {
                $skipped[] = $table_suffix;
                continue;
            }

            // Fetch all rows from this table
            $table = $wpdb->prefix . $table_suffix;
            $rows  = $wpdb->get_results("SELECT * FROM $table", ARRAY_A);
            if ($rows === null) {
                $rows = array();
            }

            $payload  = array(
                'table'  => $table_suffix,
                'rows'   => $rows,
            );
            $response = self::live_api_request('POST', '/assessor/v1/sync/push-config', $payload);

            if (is_wp_error($response)) {
                $errors[] = $table_suffix . ': ' . $response->get_error_message();
                continue;
            }

            // Clear dirty flag on success
            self::set_meta($meta_key, '0');
            $pushed[] = $table_suffix;
        }

        return array('pushed' => $pushed, 'skipped' => $skipped, 'errors' => $errors);
    }

    // -------------------------------------------------------------------------
    // PULL  (live -> local)
    // -------------------------------------------------------------------------

    /**
     * Fetch records changed on the live site since the last pull and apply locally.
     *
     * @return array { pulled, skipped, errors }
     */
    public static function pull_from_live() {
        $last_pull = self::get_meta('last_pull_at');
        $since     = $last_pull ? $last_pull : '2000-01-01 00:00:00';

        $response = self::live_api_request('GET', '/assessor/v1/sync/pull', array('since' => $since));

        if (is_wp_error($response)) {
            return array('pulled' => 0, 'skipped' => 0, 'errors' => array($response->get_error_message()));
        }

        $body    = json_decode(wp_remote_retrieve_body($response), true);
        $records = isset($body['records']) ? $body['records'] : array();

        if (empty($records)) {
            self::set_meta('last_pull_at', current_time('mysql'));
            return array('pulled' => 0, 'skipped' => 0, 'errors' => array());
        }

        $pulled  = 0;
        $skipped = 0;
        $errors  = array();

        // Tell property-save hooks not to re-enqueue these writes
        self::$syncing = true;

        foreach ($records as $remote) {
            $result = self::apply_remote_record($remote);
            if ($result === 'synced') {
                $pulled++;
            } elseif ($result === 'skipped') {
                $skipped++;
            } else {
                $errors[] = $result;
            }
        }

        self::$syncing = false;
        self::set_meta('last_pull_at', current_time('mysql'));

        return array('pulled' => $pulled, 'skipped' => $skipped, 'errors' => $errors);
    }

    /**
     * Apply a single record from the live site to the local database.
     * Last-write-wins: only writes if remote updated_at > local updated_at.
     *
     * @return string 'synced' | 'skipped' | error message
     */
    private static function apply_remote_record($remote) {
        global $wpdb;
        $table = $wpdb->prefix . 'assessor_properties';

        $tax_num = isset($remote['tax_declaration_number']) ? trim($remote['tax_declaration_number']) : '';
        if ($tax_num === '') {
            return 'skipped: missing tax_declaration_number';
        }

        $local = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, updated_at FROM $table WHERE tax_declaration_number = %s LIMIT 1",
                $tax_num
            ),
            ARRAY_A
        );

        $remote_ts = isset($remote['updated_at']) ? strtotime($remote['updated_at']) : 0;
        $local_ts  = $local ? strtotime($local['updated_at']) : 0;

        // Skip if local is same age or newer
        if ($local && $local_ts >= $remote_ts) {
            return 'skipped';
        }

        $safe = self::sanitize_sync_record($remote);

        if ($local) {
            $wpdb->update($table, $safe, array('id' => intval($local['id'])));
            if ($wpdb->last_error) {
                return 'error updating ' . $tax_num . ': ' . $wpdb->last_error;
            }
        } else {
            unset($safe['id']);
            if (empty($safe['created_by'])) {
                $safe['created_by'] = 0;
            }
            if (empty($safe['updated_by'])) {
                $safe['updated_by'] = 0;
            }
            $result = $wpdb->insert($table, $safe);
            if ($result === false) {
                return 'error inserting ' . $tax_num . ': ' . $wpdb->last_error;
            }
        }

        return 'synced';
    }

    /**
     * Whitelist columns safe to sync from live -> local.
     * Excludes local-only meta (created_by, updated_by user IDs from another system).
     */
    private static function sanitize_sync_record($record) {
        $allowed = array(
            'tax_declaration_number', 'previous_tax_declaration_number',
            'declarant_last_name', 'declarant_first_name', 'declarant_middle_initial',
            'business', 'business_name', 'location', 'lot_number',
            'unique_lot_number_identified', 'survey_number',
            'area_hectare', 'area_sqm', 'title_number',
            'assessed_value', 'effectivity_date', 'pin', 'address',
            'assessment_date', 'kind_of_property', 'gen_class',
            'memoranda', 'supporting_documents', 'status',
            'verifier_signatory_name', 'verifier_signatory_title',
            'municipal_assessor_name', 'municipal_assessor_suffix',
            'municipal_assessor_title', 'municipal_assessor_license',
            'updated_at', 'created_at',
        );

        $safe = array();
        foreach ($allowed as $col) {
            if (array_key_exists($col, $record)) {
                $safe[$col] = $record[$col];
            }
        }
        return $safe;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Check if we can reach the live site by hitting the lightweight /sync/health endpoint.
     */
    public static function is_online() {
        if (!defined('ASSESSOR_LIVE_SITE_URL') || empty(ASSESSOR_LIVE_SITE_URL)) {
            return false;
        }
        $url      = rtrim(ASSESSOR_LIVE_SITE_URL, '/') . '/wp-json/assessor/v1/sync/health';
        $response = wp_remote_head($url, array('timeout' => 5, 'sslverify' => false));
        if (is_wp_error($response)) {
            return false;
        }
        $code = wp_remote_retrieve_response_code($response);
        return $code >= 200 && $code < 300;
    }

    /**
     * Make an authenticated request to the live site's sync API.
     *
     * @param  string $method GET|POST
     * @param  string $path   e.g. '/assessor/v1/sync/push'
     * @param  array  $data   Query params (GET) or JSON body (POST)
     * @return array|WP_Error wp_remote_request() response or WP_Error
     */
    private static function live_api_request($method, $path, $data = array()) {
        if (!defined('ASSESSOR_LIVE_SITE_URL') || empty(ASSESSOR_LIVE_SITE_URL)) {
            return new WP_Error('no_live_url', 'ASSESSOR_LIVE_SITE_URL is not configured in wp-config.php.');
        }
        if (!defined('ASSESSOR_SYNC_TOKEN') || empty(ASSESSOR_SYNC_TOKEN)) {
            return new WP_Error('no_sync_token', 'ASSESSOR_SYNC_TOKEN is not configured in wp-config.php.');
        }

        $base = rtrim(ASSESSOR_LIVE_SITE_URL, '/') . '/wp-json';
        $url  = $base . $path;

        $args = array(
            'method'    => strtoupper($method),
            'timeout'   => 30,
            'sslverify' => false,
            'headers'   => array(
                'X-Sync-Token' => ASSESSOR_SYNC_TOKEN,
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ),
        );

        if ($method === 'GET' && !empty($data)) {
            $url = add_query_arg($data, $url);
        } elseif (!empty($data)) {
            $args['body'] = wp_json_encode($data);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            $preview = substr(wp_remote_retrieve_body($response), 0, 200);
            return new WP_Error('live_api_error', "Live site returned HTTP $code: $preview");
        }

        return $response;
    }

    // -------------------------------------------------------------------------
    // Sync meta (last_push_at / last_pull_at)
    // -------------------------------------------------------------------------

    public static function get_meta($key) {
        global $wpdb;
        $table = $wpdb->prefix . 'assessor_sync_meta';
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if (!$table_exists) {
            return null;
        }
        return $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM $table WHERE meta_key = %s LIMIT 1",
            $key
        ));
    }

    public static function set_meta($key, $value) {
        global $wpdb;
        $table = $wpdb->prefix . 'assessor_sync_meta';
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if (!$table_exists) {
            return;
        }
        $wpdb->query($wpdb->prepare(
            "INSERT INTO $table (meta_key, meta_value) VALUES (%s, %s)
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
            $key,
            $value
        ));
    }

    // -------------------------------------------------------------------------
    // Token management REST handlers
    // -------------------------------------------------------------------------

    /**
     * GET /sync/config
     * Returns the current sync configuration status for the Settings UI.
     */
    public static function rest_get_sync_config($request) {
        $is_local = defined('ASSESSOR_IS_LOCAL_BUILD') && ASSESSOR_IS_LOCAL_BUILD;
        $has_token = defined('ASSESSOR_SYNC_TOKEN') && !empty(ASSESSOR_SYNC_TOKEN);
        $live_url  = defined('ASSESSOR_LIVE_SITE_URL') ? ASSESSOR_LIVE_SITE_URL : '';

        return array(
            'is_local_build' => $is_local,
            'has_token'      => $has_token,
            'live_url'       => $live_url,
            'last_push'      => self::get_meta('last_push_at'),
            'last_pull'      => self::get_meta('last_pull_at'),
        );
    }

    /**
     * POST /sync/generate-token
     * Generates a new cryptographically random 64-char hex token.
     * Does NOT write it anywhere — just returns it to the UI.
     */
    public static function rest_generate_token($request) {
        $bytes = openssl_random_pseudo_bytes(48, $strong);
        if ($bytes === false || !$strong) {
            return new WP_Error('openssl_error', 'Failed to generate a secure random token. Ensure OpenSSL is available.', array('status' => 500));
        }
        $token = base64_encode($bytes);
        return array('token' => $token);
    }

    /**
     * POST /sync/save-token
     * Reads wp-config.php, replaces or inserts the ASSESSOR_SYNC_TOKEN define, saves the file.
     * Body: { "token": "..." }
     */
    public static function rest_save_token($request) {
        $params = $request->get_json_params();
        $token  = isset($params['token']) ? trim($params['token']) : '';

        if (empty($token) || strlen($token) < 32) {
            return new WP_Error('invalid_token', 'Token must be at least 32 characters.', array('status' => 400));
        }

        // Sanitize: allow base64 characters
        if (!preg_match('/^[a-zA-Z0-9+\\/=]+$/', $token)) {
            return new WP_Error('invalid_token', 'Token contains invalid characters.', array('status' => 400));
        }

        $config_path = self::find_wp_config();
        if (!$config_path) {
            return new WP_Error('config_not_found', 'Could not locate wp-config.php.', array('status' => 500));
        }

        if (!is_writable($config_path)) {
            return new WP_Error('not_writable', 'wp-config.php is not writable. Please check file permissions.', array('status' => 500));
        }

        $content = file_get_contents($config_path);
        if ($content === false) {
            return new WP_Error('read_error', 'Failed to read wp-config.php.', array('status' => 500));
        }

        $new_define = "define('ASSESSOR_SYNC_TOKEN', '{$token}');";
        $pattern    = "/define\s*\(\s*['\"]ASSESSOR_SYNC_TOKEN['\"]\s*,\s*['\"][^'\"]*['\"]\s*\)\s*;/";

        if (preg_match($pattern, $content)) {
            // Replace existing define
            $new_content = preg_replace($pattern, $new_define, $content);
        } else {
            $insert_before = "/* That's all, stop editing!";
            if (strpos($content, $insert_before) !== false) {
                $new_content = str_replace($insert_before, $new_define . "\n\n" . $insert_before, $content);
            } else {
                $new_content = rtrim($content) . "\n" . $new_define . "\n";
            }
        }

        if ($new_content === null || $new_content === $content) {
            // preg_replace returned null on error, or nothing changed
            if ($new_content === null) {
                return new WP_Error('replace_error', 'Regex error while modifying wp-config.php.', array('status' => 500));
            }
        }

        $result = file_put_contents($config_path, $new_content);
        if ($result === false) {
            return new WP_Error('write_error', 'Failed to write wp-config.php. Check permissions.', array('status' => 500));
        }

        return array(
            'success' => true,
            'message' => 'ASSESSOR_SYNC_TOKEN saved to wp-config.php successfully.',
            'token'   => $token,
        );
    }

    /**
     * Locate wp-config.php — checks in ABSPATH and one level up (standard WP locations).
     */
    private static function find_wp_config() {
        if (file_exists(ABSPATH . 'wp-config.php')) {
            return ABSPATH . 'wp-config.php';
        }
        // WordPress allows wp-config.php one directory above ABSPATH
        $parent = dirname(ABSPATH) . '/wp-config.php';
        if (file_exists($parent) && !file_exists(dirname(ABSPATH) . '/wp-settings.php')) {
            return $parent;
        }
        return false;
    }
}

