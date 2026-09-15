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
        'assessor_revision_entries'  => 'revision_code',
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
        if (!wp_next_scheduled('assessor_sync_files_cron')) {
            wp_schedule_event(time(), 'assessor_every_2_min', 'assessor_sync_files_cron');
        }
    }

    public static function add_cron_interval($schedules) {
        $schedules['assessor_every_5_min'] = array(
            'interval' => 300,
            'display'  => __('Every 5 Minutes (Assessor Sync)'),
        );
        $schedules['assessor_every_2_min'] = array(
            'interval' => 120,
            'display'  => __('Every 2 Minutes (Assessor File Sync)'),
        );
        return $schedules;
    }

    public static function deregister_cron() {
        $timestamp = wp_next_scheduled('assessor_sync_cron');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'assessor_sync_cron');
        }
        $timestamp2 = wp_next_scheduled('assessor_sync_files_cron');
        if ($timestamp2) {
            wp_unschedule_event($timestamp2, 'assessor_sync_files_cron');
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
             VALUES (%s, %s, 'pending', 0, NULL, %s, NULL)
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
        $pull_users_result  = self::pull_users_from_live();

        error_log('Assessor Sync: Property push result: '  . json_encode($push_result));
        error_log('Assessor Sync: Config push result: '    . json_encode($push_config_result));
        error_log('Assessor Sync: Property pull result: '  . json_encode($pull_result));
        error_log('Assessor Sync: Users pull result: '     . json_encode($pull_users_result));
    }

    /**
     * Triggered by admin "Sync Now" REST endpoint — runs push + pull immediately.
     *
     * @param bool $force_full When true, ignores last_pull_at and re-pulls ALL records
     *                         from the live site from the beginning of time.
     *                         Use this to recover from an incomplete initial sync.
     */
    public static function manual_sync($force_full = false) {
        // A full resync of 12,000+ records can easily take >30 seconds, exceeding default PHP max_execution_time
        set_time_limit(0);
        // Ensure the sync finishes in the background even if the frontend/proxy times out the request
        ignore_user_abort(true);

        if ($force_full) {
            // Reset the pull cursor so every record on the live site is fetched again.
            self::set_meta('last_pull_at', '2000-01-01 00:00:00');
            self::set_meta('pull_offset', 0);
        }

        $push   = self::push_pending();
        $config = self::push_pending_config();
        $pull   = self::pull_from_live($force_full);
        $users  = self::pull_users_from_live();

        $message = $force_full
            ? 'Full resync completed. All records pulled from live site.'
            : 'Sync completed.';

        return array(
            'success'    => true,
            'message'    => $message,
            'force_full' => $force_full,
            'push'       => $push,
            'config'     => $config,
            'pull'       => $pull,
            'users'      => $users,
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

        $total_pushed  = 0;
        $total_skipped = 0;
        $total_errors  = array();

        // Loop until all pending records have been sent (100 per batch).
        while (true) {
            $pending = $wpdb->get_results(
                "SELECT q.id AS queue_id, q.property_id, q.attempts
                 FROM $table_queue q
                 WHERE q.status = 'pending' AND q.operation = 'upsert'
                 ORDER BY q.queued_at ASC
                 LIMIT 100",
                ARRAY_A
            );

            if (empty($pending)) {
                break; // No more pending records — done.
            }

            // Collect full property rows
            $ids          = array_map('sanitize_text_field', array_column($pending, 'property_id'));
            $placeholders = implode(',', array_fill(0, count($ids), '%s'));
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
                $pid = sanitize_text_field($qi['property_id']);
                if (isset($prop_map[$pid])) {
                    $prop_data = $prop_map[$pid];
                    
                    // Attach local documents with base64 encoded physical files
                    $table_docs = $wpdb->prefix . 'assessor_documents';
                    $docs = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_docs WHERE property_id = %s", $pid), ARRAY_A);
                    if ($docs) {
                        foreach ($docs as &$doc) {
                            if (!empty($doc['file_path']) && file_exists($doc['file_path'])) {
                                $doc['file_data'] = base64_encode(file_get_contents($doc['file_path']));
                            } else {
                                $doc['file_data'] = null;
                            }
                        }
                    }
                    $prop_data['assessor_documents'] = $docs ? $docs : array();

                    // Attach local property state
                    $table_states = $wpdb->prefix . 'assessor_property_states';
                    $local_state = $wpdb->get_var($wpdb->prepare("SELECT state FROM $table_states WHERE property_id = %s", $pid));
                    $prop_data['property_state'] = $local_state ? $local_state : 'CURRENT';

                    $records[] = array(
                        'queue_id'    => intval($qi['queue_id']),
                        'property_id' => $pid,
                        'attempts'    => intval($qi['attempts']),
                        'tax_num'     => $prop_data['tax_declaration_number'],
                        'data'        => $prop_data,
                    );
                }
            }

            if (empty($records)) {
                // All queued IDs in this batch were missing from the properties table — mark failed.
                foreach ($pending as $qi) {
                    $wpdb->update(
                        $table_queue,
                        array(
                            'status'     => 'failed',
                            'attempts'   => intval($qi['attempts']) + 1,
                            'last_error' => 'Property row not found',
                        ),
                        array('id' => intval($qi['queue_id'])),
                        array('%s', '%d', '%s'),
                        array('%d')
                    );
                }
                $total_errors[] = 'Properties not found for queued IDs';
                continue; // Try next batch
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
                $total_errors[] = $err_msg;
                break; // Network error — stop trying; will retry on next sync cycle.
            }

            $body    = json_decode(wp_remote_retrieve_body($response), true);
            $results = isset($body['results']) ? $body['results'] : array();

            foreach ($records as $rec) {
                // Match by property_id first, then fallback to tax_num
                $pid = $rec['property_id'];
                if (isset($results[$pid])) {
                    $res_item = $results[$pid];
                } elseif (isset($results[$rec['tax_num']])) {
                    $res_item = $results[$rec['tax_num']];
                } else {
                    $res_item = array('status' => 'error', 'message' => 'No response from live site');
                }
                $res_status = isset($res_item['status']) ? $res_item['status'] : 'error';

                if ($res_status === 'synced') {
                    $queue_status = 'synced';
                    $total_pushed++;
                } elseif ($res_status === 'skipped') {
                    $queue_status = 'skipped';
                    $total_skipped++;
                } else {
                    $queue_status = 'failed';
                    $total_errors[] = $rec['tax_num'] . ': ' . (isset($res_item['message']) ? $res_item['message'] : 'unknown error');
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
        } // end while

        if ($total_pushed > 0 || $total_skipped > 0 || !empty($total_errors)) {
            self::set_meta('last_push_at', current_time('mysql'));
        }
        return array('pushed' => $total_pushed, 'skipped' => $total_skipped, 'errors' => $total_errors);
    }

    /**
     * Pull users from live site and upsert locally. (Live -> Local, one-way)
     */
    public static function pull_users_from_live() {
        $total_upserted = 0;
        $errors = array();

        // Check if there is an existing offset
        $offset = intval(self::get_meta('pull_users_offset'));
        $batch_size = 500;

        while (true) {
            $params = array(
                'since'  => '2000-01-01 00:00:00', // Always fetch all
                'limit'  => $batch_size,
                'offset' => $offset,
                'type'   => 'users',
            );
            $query_string = http_build_query($params);
            $response = self::live_api_request('GET', '/assessor/v1/sync/pull?' . $query_string);

            if (is_wp_error($response)) {
                $errors[] = 'Request failed: ' . $response->get_error_message();
                break;
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            $records = isset($body['records']) ? $body['records'] : array();

            if (empty($records)) {
                // Done
                self::set_meta('pull_users_offset', 0);
                break;
            }

            global $wpdb;
            $table_users = $wpdb->prefix . 'assessor_users';

            foreach ($records as $user) {
                if (!isset($user['id'])) continue;
                $clean = array_filter($user, 'is_scalar');
                // Use REPLACE to safely upsert based on the ID
                $wpdb->replace($table_users, $clean);
                $total_upserted++;
            }

            $offset += count($records);
            self::set_meta('pull_users_offset', $offset);

            // If we got fewer than requested, we're at the end
            if (count($records) < $batch_size) {
                self::set_meta('pull_users_offset', 0);
                break;
            }
        }

        return array('upserted' => $total_upserted, 'errors' => $errors);
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
     * Fetch recently changed records from the live site and upsert locally.
     *
     * @param bool $force_full If true, passes the flag to apply_remote_record to force overwrite.
     * @return array { pulled, skipped, errors }
     */
    public static function pull_from_live($force_full = false) {
        $last_pull = self::get_meta('last_pull_at');
        $since     = $last_pull ? $last_pull : '2000-01-01 00:00:00';
        
        $pulled  = 0;
        $skipped = 0;
        $errors  = array();
        $page_limit = 500; // Reduced from 2000 to 500 to prevent server timeouts/memory limits on large syncs
        $sync_start = ''; // Capture the server_ts from the first response
        $pull_completed_successfully = true;
        
        // Fetch the saved offset so we can resume exactly where we left off if Apache kills us
        $offset = (int) self::get_meta('pull_offset');

        // Tell property-save hooks not to re-enqueue these writes
        self::$syncing = true;

        // Paginate: keep pulling pages until we get a short page.
        while (true) {
            $response = self::live_api_request(
                'GET',
                '/assessor/v1/sync/pull',
                array('since' => $since, 'limit' => $page_limit, 'offset' => $offset)
            );

            if (is_wp_error($response)) {
                $errors[] = $response->get_error_message();
                $pull_completed_successfully = false;
                break; // Network error — stop; will retry on next cycle.
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            
            // Capture the exact time the pull started on the live server
            if (empty($sync_start) && !empty($body['server_ts'])) {
                $sync_start = $body['server_ts'];
            }

            $records = isset($body['records']) ? $body['records'] : array();

            if (empty($records)) {
                break; // No more records on this page — done.
            }

            foreach ($records as $remote) {
                $result = self::apply_remote_record($remote, $force_full);
                if ($result === 'synced') {
                    $pulled++;
                } elseif ($result === 'skipped') {
                    $skipped++;
                } else {
                    $errors[] = $result;
                }
            }

            $count = count($records);

            if ($count < $page_limit) {
                break; // Last page — fewer records than page size means no more pages.
            }

            // Advance cursor using offset instead of updating $since. 
            // Updating $since drops records with the exact same timestamp!
            $offset += $page_limit;
            
            // CHECKPOINT: Save progress immediately after every page.
            // If Apache or PHP kills this script halfway through 12,000 records, 
            // the next sync will resume exactly at this offset instead of starting over!
            self::set_meta('pull_offset', $offset);
        }

        self::$syncing = false;
        
        // At the very end, if we completed everything successfully without errors, 
        // we can set the cursor to the exact time the sync started and RESET the offset.
        if ($pull_completed_successfully && !empty($sync_start)) {
            self::set_meta('last_pull_at', $sync_start);
            self::set_meta('pull_offset', 0);
        }

        return array('pulled' => $pulled, 'skipped' => $skipped, 'errors' => $errors);
    }

    /**
     * Apply a single record from the live site to the local database.
     * Last-write-wins: only writes if remote updated_at > local updated_at.
     *
     * @param array $remote The record array from the live site.
     * @param bool $force_full If true, ignores timestamps and forces an overwrite of the local record.
     * @return string 'synced' | 'skipped' | error message
     */
    private static function apply_remote_record($remote, $force_full = false) {
        global $wpdb;
        $table = $wpdb->prefix . 'assessor_properties';

        $tax_num = isset($remote['tax_declaration_number']) ? trim($remote['tax_declaration_number']) : '';
        $prop_id = isset($remote['id']) ? trim((string)$remote['id']) : '';
        $revision_id = isset($remote['revision_id']) && !empty($remote['revision_id']) ? trim((string)$remote['revision_id']) : null;

        if ($tax_num === '' && $prop_id === '') {
            return 'skipped: missing tax_declaration_number and id';
        }

        // Exact match by UUID first
        $local = null;
        if (!empty($prop_id)) {
            $local = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, updated_at FROM $table WHERE id = %s LIMIT 1",
                    $prop_id
                ),
                ARRAY_A
            );
        }

        // Secondary fallback: match by (tax_declaration_number, revision_id) if UUID didn't match
        if (!$local && !empty($tax_num) && !empty($revision_id)) {
            $local = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, updated_at FROM $table WHERE tax_declaration_number = %s AND revision_id = %s LIMIT 1",
                    $tax_num,
                    $revision_id
                ),
                ARRAY_A
            );
        }

        $remote_ts = isset($remote['updated_at']) ? strtotime($remote['updated_at']) : 0;
        $local_ts  = $local ? strtotime($local['updated_at']) : 0;

        // Skip if local is same age or newer (unless forcing a full resync)
        if (!$force_full && $local && $local_ts >= $remote_ts) {
            return 'skipped';
        }

        $safe = self::sanitize_sync_record($remote);

        // Rewrite live-site URLs to relative paths so they work from any machine
        // e.g. https://archive.massokitaotao.net/wp-content/uploads/assessor/...
        //   -> /wp-content/uploads/assessor/...
        if (defined('ASSESSOR_IS_LOCAL_BUILD') && ASSESSOR_IS_LOCAL_BUILD && defined('ASSESSOR_LIVE_SITE_URL')) {
            $live_domain = rtrim(ASSESSOR_LIVE_SITE_URL, '/');
            $url_fields = array('supporting_documents', 'supporting_documents_old');
            foreach ($url_fields as $field) {
                if (!empty($safe[$field])) {
                    $safe[$field] = str_replace($live_domain, '', $safe[$field]);
                }
            }
        }

        $property_state = null;
        if (isset($safe['property_state'])) {
            $property_state = $safe['property_state'];
            unset($safe['property_state']);
        }

        if ($local) {
            $wpdb->update($table, $safe, array('id' => $local['id']), null, array('%s'));
            if ($wpdb->last_error) {
                return 'error updating ' . $tax_num . ': ' . $wpdb->last_error;
            }
            $local_property_id = $local['id'];
        } else {
            if (empty($safe['id'])) {
                $safe['id'] = class_exists('Assessor_UUID') ? Assessor_UUID::v7() : wp_generate_uuid4();
            }
            if (empty($safe['created_by'])) {
                $safe['created_by'] = null;
            }
            if (empty($safe['updated_by'])) {
                $safe['updated_by'] = null;
            }
            $result = $wpdb->insert($table, $safe);
            if ($result === false) {
                return 'error inserting ' . $tax_num . ': ' . $wpdb->last_error;
            }
            $local_property_id = $safe['id'];
        }

        // Sync documents if present
        if (isset($remote['assessor_documents']) && is_array($remote['assessor_documents'])) {
            $table_docs = $wpdb->prefix . 'assessor_documents';
            foreach ($remote['assessor_documents'] as $doc) {
                $doc_safe = array(
                    'property_id'       => $local_property_id,
                    'filename'          => sanitize_file_name($doc['filename']),
                    'original_filename' => sanitize_text_field($doc['original_filename']),
                    'file_path'         => sanitize_text_field($doc['file_path']),
                    'file_type'         => sanitize_text_field($doc['file_type']),
                    'description'       => sanitize_textarea_field($doc['description'] ?? ''),
                    'uploaded_at'       => sanitize_text_field($doc['uploaded_at']),
                    'uploaded_by'       => 0 // 0 means synced by system
                );

                $existing_doc = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_docs WHERE filename = %s LIMIT 1", $doc['filename']));
                if ($existing_doc) {
                    $wpdb->update($table_docs, $doc_safe, array('id' => $existing_doc));
                } else {
                    $wpdb->insert($table_docs, $doc_safe);
                }
            }
        }

        // Sync property state if provided
        if ($property_state !== null) {
            $table_property_states = $wpdb->prefix . 'assessor_property_states';
            $wpdb->replace($table_property_states, array(
                'property_id' => $local_property_id,
                'state'       => strtoupper(trim($property_state))
            ), array('%s', '%s'));
        }

        return 'synced';
    }

    /**
     * Whitelist columns safe to sync from live -> local.
     * Excludes local-only meta (created_by, updated_by user IDs from another system).
     */
    private static function sanitize_sync_record($record) {
        $allowed = array(
            'id', 'tax_declaration_number', 'previous_tax_declaration_number',
            'declarant_last_name', 'declarant_first_name', 'declarant_middle_initial',
            'business', 'business_name', 'location', 'lot_number',
            'unique_lot_number_identified', 'survey_number',
            'area_hectare', 'area_hectare_old', 'area_sqm', 'title_number',
            'assessed_value', 'assessed_value_old', 'effectivity_date', 'pin', 'address',
            'assessment_date', 'kind_of_property', 'gen_class',
            'memoranda', 'supporting_documents', 'supporting_documents_old', 'status',
            'change_reason',
            'verifier_signatory_name', 'verifier_signatory_title',
            'municipal_assessor_name', 'municipal_assessor_suffix',
            'municipal_assessor_title', 'municipal_assessor_license',
            'status', 'revision_id', 'updated_at', 'created_at',
            'created_by', 'updated_by', 'property_state', // Allow syncing user IDs and state
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
    // File Downloading (Cron)
    // -------------------------------------------------------------------------

    public static function download_missing_files($max_downloads = 50) {
        if (!defined('ASSESSOR_IS_LOCAL_BUILD') || !ASSESSOR_IS_LOCAL_BUILD) {
            return array('downloaded' => 0, 'failed' => 0, 'remaining' => 0);
        }
        
        global $wpdb;
        $table_docs = $wpdb->prefix . 'assessor_documents';
        $table_prop = $wpdb->prefix . 'assessor_properties';
        $upload_dir = wp_upload_dir();
        $basedir = rtrim($upload_dir['basedir'], '/');
        $live_domain = defined('ASSESSOR_LIVE_SITE_URL') ? rtrim(ASSESSOR_LIVE_SITE_URL, '/') : '';
        
        $downloaded = 0;
        $failed = 0;
        
        // 1. Check assessor_documents table
        $docs = $wpdb->get_results("SELECT id, file_path FROM $table_docs ORDER BY id DESC", ARRAY_A);
        if ($docs) {
            foreach ($docs as $doc) {
                if (($downloaded + $failed) >= $max_downloads) break;
                
                if (!empty($doc['file_path']) && !file_exists($doc['file_path'])) {
                    if (strpos($doc['file_path'], $basedir) === 0) {
                        $relative = substr($doc['file_path'], strlen($basedir));
                        $live_url = $live_domain . '/wp-content/uploads' . str_replace('\\', '/', $relative);
                        
                        if (self::download_single_file($live_url, $doc['file_path'])) {
                            $downloaded++;
                        } else {
                            $failed++;
                        }
                    }
                }
            }
        }
        
        // 2. Check supporting_documents in properties table
        // URLs may be stored as:
        //   (a) relative paths: /wp-content/uploads/assessor/lot-pictures/file.jpg
        //   (b) absolute live URLs: https://archive.massokitaotao.net/wp-content/uploads/assessor/...
        if (($downloaded + $failed) < $max_downloads) {
            $props = $wpdb->get_results("SELECT id, supporting_documents, supporting_documents_old FROM $table_prop ORDER BY id DESC", ARRAY_A);
            if ($props) {
                foreach ($props as $prop) {
                    if (($downloaded + $failed) >= $max_downloads) break;
                    
                    $all_urls = self::extract_urls_from_supporting_docs($prop);
                    foreach ($all_urls as $url) {
                        if (($downloaded + $failed) >= $max_downloads) break;
                        
                        $local_path = null;
                        $download_url = null;
                        
                        // Case (a): relative path like /wp-content/uploads/assessor/...
                        if (strpos($url, '/wp-content/uploads/') === 0) {
                            $relative = str_replace('/wp-content/uploads', '', $url);
                            $local_path = $basedir . $relative;
                            $download_url = $live_domain . $url;
                        }
                        // Case (b): absolute live URL
                        elseif (!empty($live_domain) && strpos($url, $live_domain . '/wp-content/uploads') !== false) {
                            $relative = str_replace($live_domain . '/wp-content/uploads', '', $url);
                            $local_path = $basedir . $relative;
                            $download_url = $url;
                        }
                        
                        if ($local_path && $download_url && !file_exists($local_path)) {
                            if (self::download_single_file($download_url, $local_path)) {
                                $downloaded++;
                            } else {
                                $failed++;
                            }
                        }
                    }
                }
            }
        }
        
        // Count remaining missing files for status reporting
        $remaining = self::count_missing_files();
        
        return array('downloaded' => $downloaded, 'failed' => $failed, 'remaining' => $remaining);
    }

    /**
     * Download a single file from a remote URL to a local path.
     */
    private static function download_single_file($url, $local_path) {
        $response = wp_remote_get($url, array(
            'timeout'    => 5,
            'sslverify'  => false,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
        ));
        
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $dir = dirname($local_path);
            if (!file_exists($dir)) wp_mkdir_p($dir);
            file_put_contents($local_path, wp_remote_retrieve_body($response));
            return true;
        }
        return false;
    }

    /**
     * Extract all image/document URLs from a property's supporting_documents fields.
     */
    private static function extract_urls_from_supporting_docs($prop) {
        $urls = array();
        
        foreach (array('supporting_documents', 'supporting_documents_old') as $field) {
            if (empty($prop[$field])) continue;
            
            $decoded = json_decode($prop[$field], true);
            if (is_array($decoded)) {
                foreach ($decoded as $item) {
                    if (is_string($item) && !empty($item)) {
                        $urls[] = $item;
                    }
                }
            } elseif (is_string($prop[$field]) && !empty($prop[$field])) {
                // Could be a pipe-delimited string or a single URL
                $parts = explode('|', $prop[$field]);
                foreach ($parts as $part) {
                    $part = trim($part);
                    if (!empty($part)) {
                        $urls[] = $part;
                    }
                }
            }
        }
        
        return $urls;
    }

    /**
     * Count files that still need to be downloaded.
     */
    public static function count_missing_files() {
        if (!defined('ASSESSOR_IS_LOCAL_BUILD') || !ASSESSOR_IS_LOCAL_BUILD) {
            return 0;
        }
        
        global $wpdb;
        $table_prop = $wpdb->prefix . 'assessor_properties';
        $upload_dir = wp_upload_dir();
        $basedir = rtrim($upload_dir['basedir'], '/');
        $live_domain = defined('ASSESSOR_LIVE_SITE_URL') ? rtrim(ASSESSOR_LIVE_SITE_URL, '/') : '';
        $missing = 0;
        
        $props = $wpdb->get_results("SELECT supporting_documents, supporting_documents_old FROM $table_prop", ARRAY_A);
        if ($props) {
            foreach ($props as $prop) {
                $all_urls = self::extract_urls_from_supporting_docs($prop);
                foreach ($all_urls as $url) {
                    $local_path = null;
                    if (strpos($url, '/wp-content/uploads/') === 0) {
                        $relative = str_replace('/wp-content/uploads', '', $url);
                        $local_path = $basedir . $relative;
                    } elseif (!empty($live_domain) && strpos($url, $live_domain . '/wp-content/uploads') !== false) {
                        $relative = str_replace($live_domain . '/wp-content/uploads', '', $url);
                        $local_path = $basedir . $relative;
                    }
                    if ($local_path && !file_exists($local_path)) {
                        $missing++;
                    }
                }
            }
        }
        
        return $missing;
    }

    /**
     * Bulk download — called manually from the admin to download missing files.
     * Processes 25 files per call so the frontend can receive live progress updates.
     * @deprecated Use get_missing_files_list and download_specific_batch instead.
     */
    public static function bulk_download_files() {
        set_time_limit(0);
        ignore_user_abort(true);
        
        return self::download_missing_files(25);
    }

    /**
     * Get a complete list of all missing files to be downloaded by the frontend.
     */
    public static function get_missing_files_list() {
        set_time_limit(0);
        
        if (!defined('ASSESSOR_IS_LOCAL_BUILD') || !ASSESSOR_IS_LOCAL_BUILD) {
            return array('missing_files' => array());
        }
        
        global $wpdb;
        $table_docs = $wpdb->prefix . 'assessor_documents';
        $table_prop = $wpdb->prefix . 'assessor_properties';
        $upload_dir = wp_upload_dir();
        $basedir = rtrim($upload_dir['basedir'], '/');
        $live_domain = defined('ASSESSOR_LIVE_SITE_URL') ? rtrim(ASSESSOR_LIVE_SITE_URL, '/') : '';
        
        $missing_files = array();
        
        // 1. Check assessor_documents table
        $docs = $wpdb->get_results("SELECT id, file_path FROM $table_docs ORDER BY id DESC", ARRAY_A);
        if ($docs) {
            foreach ($docs as $doc) {
                if (!empty($doc['file_path']) && !file_exists($doc['file_path'])) {
                    if (strpos($doc['file_path'], $basedir) === 0) {
                        $relative = substr($doc['file_path'], strlen($basedir));
                        $live_url = $live_domain . '/wp-content/uploads' . str_replace('\\', '/', $relative);
                        $missing_files[] = array('url' => $live_url, 'path' => $doc['file_path']);
                    }
                }
            }
        }
        
        // 2. Check supporting_documents in properties table
        $props = $wpdb->get_results("SELECT id, supporting_documents, supporting_documents_old FROM $table_prop ORDER BY id DESC", ARRAY_A);
        if ($props) {
            foreach ($props as $prop) {
                $all_urls = self::extract_urls_from_supporting_docs($prop);
                foreach ($all_urls as $url) {
                    $local_path = null;
                    $download_url = null;
                    
                    if (strpos($url, '/wp-content/uploads/') === 0) {
                        $relative = str_replace('/wp-content/uploads', '', $url);
                        $local_path = $basedir . $relative;
                        $download_url = $live_domain . $url;
                    } elseif (!empty($live_domain) && strpos($url, $live_domain . '/wp-content/uploads') !== false) {
                        $relative = str_replace($live_domain . '/wp-content/uploads', '', $url);
                        $local_path = $basedir . $relative;
                        $download_url = $url;
                    }
                    
                    if ($local_path && $download_url && !file_exists($local_path)) {
                        $missing_files[] = array('url' => $download_url, 'path' => $local_path);
                    }
                }
            }
        }
        
        return array('missing_files' => $missing_files);
    }

    /**
     * Download a specific array of files sent from the frontend.
     */
    public static function download_specific_batch($files) {
        if (!defined('ASSESSOR_IS_LOCAL_BUILD') || !ASSESSOR_IS_LOCAL_BUILD) {
            return array('downloaded' => 0, 'failed' => 0);
        }

        $downloaded = 0;
        $failed = 0;

        foreach ($files as $file) {
            if (empty($file['url']) || empty($file['path'])) {
                $failed++;
                continue;
            }
            if (self::download_single_file($file['url'], $file['path'])) {
                $downloaded++;
            } else {
                $failed++;
            }
        }

        return array('downloaded' => $downloaded, 'failed' => $failed);
    }

    // -------------------------------------------------------------------------
    // Sync Logic
    // -------------------------------------------------------------------------

    /**
     * Check if we can reach the live site by hitting the lightweight /sync/health endpoint.
     */
    public static function is_online() {
        if (!defined('ASSESSOR_LIVE_SITE_URL') || empty(ASSESSOR_LIVE_SITE_URL)) {
            return false;
        }
        $url      = rtrim(ASSESSOR_LIVE_SITE_URL, '/') . '/wp-json/assessor/v1/sync/health';
        // Use GET — the health endpoint only accepts GET; HEAD returns 405.
        // Increase timeout to 10s to accommodate slow/shared-hosting cold starts.
        $response = wp_remote_get($url, array('timeout' => 10, 'sslverify' => false));
        if (is_wp_error($response)) {
            return false;
        }
        $code = wp_remote_retrieve_response_code($response);
        // Accept 2xx AND 3xx — a redirect (e.g. HTTP→HTTPS) still means the host is reachable.
        return $code >= 200 && $code < 400;
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
            'timeout'   => 120, // Increased from 30 to 120 to allow large page generations on the live server
            'sslverify' => false,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
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
            'last_local_pull' => get_option('assessor_last_local_pull', null),
            'last_local_push' => get_option('assessor_last_local_push', null),
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

