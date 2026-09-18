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

        // Check if record_type column exists
        $has_record_type = $wpdb->get_row("SHOW COLUMNS FROM $table LIKE 'record_type'");

        $uuid = class_exists('Assessor_UUID') ? Assessor_UUID::v7() : wp_generate_uuid4();

        if ($has_record_type) {
            // INSERT ... ON DUPLICATE KEY UPDATE with record_type
            $wpdb->query($wpdb->prepare(
                "INSERT INTO $table (id, record_type, property_id, operation, status, attempts, last_error, queued_at, synced_at)
                 VALUES (%s, 'property', %s, %s, 'pending', 0, NULL, %s, NULL)
                 ON DUPLICATE KEY UPDATE
                    status     = 'pending',
                    attempts   = 0,
                    last_error = NULL,
                    queued_at  = %s,
                    synced_at  = NULL",
                $uuid,
                $property_id,
                $operation,
                current_time('mysql'),
                current_time('mysql')
            ));
        } else {
            // Backward compatibility
            $wpdb->query($wpdb->prepare(
                "INSERT INTO $table (id, property_id, operation, status, attempts, last_error, queued_at, synced_at)
                 VALUES (%s, %s, %s, 'pending', 0, NULL, %s, NULL)
                 ON DUPLICATE KEY UPDATE
                    status     = 'pending',
                    attempts   = 0,
                    last_error = NULL,
                    queued_at  = %s,
                    synced_at  = NULL",
                $uuid,
                $property_id,
                $operation,
                current_time('mysql'),
                current_time('mysql')
            ));
        }
    }

    /**
     * Add or reset a request to the sync queue.
     * Called automatically after create_request() / update_request() / delete_request().
     * Skipped when Assessor_Sync::$syncing is true (write came from sync pull).
     *
     * @param string $request_id UUID v7 of the request.
     * @param string $operation  'upsert' | 'delete'
     */
    public static function enqueue_request($request_id, $operation = 'upsert') {
        if (self::$syncing) {
            return; // Came from sync — do not re-enqueue
        }
        if (!defined('ASSESSOR_IS_LOCAL_BUILD') || !ASSESSOR_IS_LOCAL_BUILD) {
            return; // Only queue on local builds
        }

        global $wpdb;
        $table = $wpdb->prefix . 'assessor_sync_queue';
        $has_record_type = $wpdb->get_row("SHOW COLUMNS FROM $table LIKE 'record_type'");
        $uuid = class_exists('Assessor_UUID') ? Assessor_UUID::v7() : wp_generate_uuid4();

        if ($has_record_type) {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO $table (id, record_type, property_id, operation, status, attempts, last_error, queued_at, synced_at)
                 VALUES (%s, 'request', %s, %s, 'pending', 0, NULL, %s, NULL)
                 ON DUPLICATE KEY UPDATE
                    status     = 'pending',
                    attempts   = 0,
                    last_error = NULL,
                    queued_at  = %s,
                    synced_at  = NULL",
                $uuid,
                $request_id,
                $operation,
                current_time('mysql'),
                current_time('mysql')
            ));
        } else {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO $table (id, property_id, operation, status, attempts, last_error, queued_at, synced_at)
                 VALUES (%s, %s, %s, 'pending', 0, NULL, %s, NULL)
                 ON DUPLICATE KEY UPDATE
                    status     = 'pending',
                    attempts   = 0,
                    last_error = NULL,
                    queued_at  = %s,
                    synced_at  = NULL",
                $uuid,
                $request_id,
                $operation,
                current_time('mysql'),
                current_time('mysql')
            ));
        }
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

        // Before sync, check if there is any pending report from a previous run that needs mirroring
        self::check_pending_report_mirror();

        if (!self::is_online()) {
            error_log('Assessor Sync: No internet — recording failed connection run.');
            if (class_exists('Assessor_Sync_Report')) {
                $failed_report = Assessor_Sync_Report::start_run('incremental');
                $failed_report->finish_run('failed', 'Unable to connect to Live Server.');
            }
            return;
        }

        $report = class_exists('Assessor_Sync_Report')
            ? Assessor_Sync_Report::start_run('incremental')
            : null;

        if ($report) {
            $report->update_phase('push', 'running');
        }
        $push_result = self::push_pending($report);
        if ($report) {
            $report->update_phase('push', empty($push_result['errors']) ? 'completed' : 'failed', $push_result);
            $report->update_phase('config', 'running');
        }

        $push_config_result = self::push_pending_config();
        if ($report) {
            $report->update_phase('config', empty($push_config_result['errors']) ? 'completed' : 'failed', $push_config_result);
            $report->update_phase('revisions', 'running');
        }

        $pull_revisions_result = self::pull_revision_entries_from_live(false);

        error_log('Assessor Sync: Property push result: '     . json_encode($push_result));
        error_log('Assessor Sync: Config push result: '       . json_encode($push_config_result));
        error_log('Assessor Sync: Revision pull result: '     . json_encode($pull_revisions_result));

        if (empty($pull_revisions_result['success'])) {
            error_log('Assessor Sync: Property pull blocked because revision entries could not be synchronized.');
            if ($report) {
                $report->update_phase('revisions', 'failed', $pull_revisions_result);
                $report->finish_run('failed', 'Revision synchronization failed. Property pull was not started.');
                self::mirror_report_to_live($report->get_run_id());
            }
            return;
        }

        if ($report) {
            $report->update_phase('revisions', 'completed', $pull_revisions_result);
            $report->update_phase('properties', 'running');
        }

        $pull_result = self::pull_from_live(false, $report);
        if ($report) {
            $report->update_phase('properties', empty($pull_result['errors']) ? 'completed' : 'failed', $pull_result);
            $report->update_phase('requests', 'running');
        }

        $pull_requests_result = self::pull_requests_from_live(false, $report);
        if ($report) {
            $report->update_phase('requests', empty($pull_requests_result['errors']) ? 'completed' : 'failed', $pull_requests_result);
            $report->update_phase('users', 'running');
        }

        $pull_users_result = self::pull_users_from_live();
        if ($report) {
            $report->update_phase('users', empty($pull_users_result['errors']) ? 'completed' : 'failed', $pull_users_result);
        }

        error_log('Assessor Sync: Property pull result: '     . json_encode($pull_result));
        error_log('Assessor Sync: Requests pull result: '     . json_encode($pull_requests_result));
        error_log('Assessor Sync: Users pull result: '        . json_encode($pull_users_result));

        if ($report) {
            $has_errors = !empty($push_result['errors']) || !empty($push_config_result['errors']) ||
                          !empty($pull_result['errors']) || !empty($pull_requests_result['errors']) || !empty($pull_users_result['errors']);

            $total_changes = 0;
            if (isset($pull_result['synced'])) $total_changes += (int)$pull_result['synced'];
            if (isset($pull_requests_result['synced'])) $total_changes += (int)$pull_requests_result['synced'];
            if (isset($push_result['synced'])) $total_changes += (int)$push_result['synced'];

            if ($has_errors) {
                $final_status = 'failed';
                $message = 'Background sync encountered errors.';
            } else {
                $final_status = 'completed';
                $message = $total_changes === 0 ? 'No changes found. Data is already synchronized.' : 'Background sync completed successfully.';
            }

            $report->finish_run($final_status, $message);
            self::mirror_report_to_live($report->get_run_id());
        }
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

        $report = class_exists('Assessor_Sync_Report') 
            ? Assessor_Sync_Report::start_run($force_full ? 'full' : 'incremental')
            : null;

        if ($force_full) {
            // Reset the pull cursor so every record on the live site is fetched again.
            self::set_meta('last_pull_at', '2000-01-01 00:00:00');
            self::set_meta('pull_offset', 0);
            self::set_meta('last_pull_requests_at', '2000-01-01 00:00:00');
            self::set_meta('pull_requests_offset', 0);
        }

        if ($report) {
            $report->update_phase('push', 'running');
        }
        $push = self::push_pending($report);
        if ($report) {
            $report->update_phase('push', empty($push['errors']) ? 'completed' : 'failed', $push);
            $report->update_phase('config', 'running');
        }

        // Force bidirectional lookup tables reconciliation regardless of dirty flags
        $lookup_reconcile = self::sync_lookup_tables_bidirectional(true);
        $config     = self::push_pending_config();
        $config['lookups'] = $lookup_reconcile;
        if ($report) {
            $report->update_phase('config', empty($config['errors']) ? 'completed' : 'failed', $config);
            $report->update_phase('revisions', 'running');
        }

        $revisions  = self::pull_revision_entries_from_live($force_full);

        if (empty($revisions['success'])) {
            if ($report) {
                $report->update_phase('revisions', 'failed', $revisions);
                $report->finish_run('failed', 'Revision synchronization failed. Property pull was not started.');
            }
            return array(
                'success'     => false,
                'message'     => 'Revision synchronization failed. Property pull was not started.',
                'force_full'  => $force_full,
                'sync_run_id' => $report ? $report->get_run_id() : null,
                'push'        => $push,
                'config'      => $config,
                'revisions'   => $revisions,
                'pull'        => array(
                    'pulled'  => 0,
                    'skipped' => 0,
                    'errors'  => array(
                        'Property pull blocked because revision entries could not be synchronized.'
                    ),
                ),
                'requests'    => array('pulled' => 0, 'skipped' => 0, 'errors' => array()),
                'users'       => array('upserted' => 0, 'errors' => array()),
            );
        }

        if ($report) {
            $report->update_phase('revisions', 'completed', $revisions);
            $report->update_phase('properties', 'running');
        }

        $pull = self::pull_from_live($force_full, $report);
        if ($report) {
            $report->update_phase('properties', empty($pull['errors']) ? 'completed' : 'failed', $pull);
            $report->update_phase('requests', 'running');
        }

        $requests = self::pull_requests_from_live($force_full, $report);
        if ($report) {
            $report->update_phase('requests', empty($requests['errors']) ? 'completed' : 'failed', $requests);
            $report->update_phase('users', 'running');
        }

        $users = self::pull_users_from_live();
        if ($report) {
            $report->update_phase('users', empty($users['errors']) ? 'completed' : 'failed', $users);
        }

        $message = $force_full
            ? 'Full resync completed. All records pulled from live site.'
            : 'Sync completed.';

        $has_errors = !empty($push['errors']) || !empty($config['errors']) || !empty($pull['errors']) || !empty($requests['errors']) || !empty($users['errors']);
        $final_status = $has_errors ? 'failed' : 'completed';

        if ($report) {
            $report->finish_run($final_status, $message);
            self::mirror_report_to_live($report->get_run_id());
        }

        return array(
            'success'     => true,
            'message'     => $message,
            'force_full'  => $force_full,
            'sync_run_id' => $report ? $report->get_run_id() : null,
            'push'        => $push,
            'config'      => $config,
            'revisions'   => $revisions,
            'pull'        => $pull,
            'requests'    => $requests,
            'users'       => $users,
        );
    }

    /**
     * Pull revision entries from live site and upsert locally preserving original UUIDs.
     *
     * @param bool $force_full
     * @return array
     */
    public static function pull_revision_entries_from_live($force_full = false) {
        global $wpdb;

        $table = $wpdb->prefix . 'assessor_revision_entries';

        $response = self::live_api_request(
            'GET',
            '/assessor/v1/sync/revision-entries/'
        );

        if (is_wp_error($response)) {
            return array(
                'success'  => false,
                'upserted' => 0,
                'errors'   => array($response->get_error_message()),
            );
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!is_array($body)) {
            return array(
                'success'  => false,
                'upserted' => 0,
                'errors'   => array('Invalid JSON returned by revision-entries endpoint.'),
            );
        }

        $records = isset($body['records']) && is_array($body['records'])
            ? $body['records']
            : array();

        $upserted = 0;
        $errors   = array();

        foreach ($records as $row) {
            if (!is_array($row) || empty($row['id'])) {
                $errors[] = 'Skipped revision entry with missing id.';
                continue;
            }

            $clean = array(
                'id'            => (string) $row['id'],
                'revision_code' => isset($row['revision_code']) ? (string) $row['revision_code'] : '',
                'revision_year' => isset($row['revision_year']) ? (string) $row['revision_year'] : '',
                'from_year'     => isset($row['from_year']) ? (string) $row['from_year'] : '',
                'to_year'       => isset($row['to_year']) ? (string) $row['to_year'] : '',
                'status'        => isset($row['status']) ? (string) $row['status'] : 'active',
                'sort_order'    => isset($row['sort_order']) ? (int) $row['sort_order'] : 0,
                'created_at'    => isset($row['created_at']) ? (string) $row['created_at'] : null,
                'updated_at'    => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
            );

            $existing = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id
                     FROM $table
                     WHERE id = %s
                     LIMIT 1",
                    $clean['id']
                )
            );

            if ($existing) {
                $result = $wpdb->update(
                    $table,
                    $clean,
                    array('id' => $clean['id'])
                );
            } else {
                $result = $wpdb->insert(
                    $table,
                    $clean
                );
            }

            if ($result === false) {
                $errors[] =
                    'Failed revision ' . $clean['id'] .
                    ' (' . $clean['revision_code'] . '): ' .
                    $wpdb->last_error;
                continue;
            }

            $upserted++;
        }

        return array(
            'success'  => empty($errors),
            'upserted' => $upserted,
            'errors'   => $errors,
            'count'    => count($records),
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
    /**
     * Send all pending local records to the live site.
     *
     * @param Assessor_Sync_Report|null $report
     * @return array { pushed, skipped, errors }
     */
    public static function push_pending($report = null) {
        global $wpdb;
        $table_queue      = $wpdb->prefix . 'assessor_sync_queue';
        $table_properties = $wpdb->prefix . 'assessor_properties';
        $table_requests   = $wpdb->prefix . 'assessor_requests';

        $total_pushed  = 0;
        $total_skipped = 0;
        $total_errors  = array();

        // Check if record_type column exists
        $has_record_type = $wpdb->get_row("SHOW COLUMNS FROM $table_queue LIKE 'record_type'");

        // Loop until all pending records have been sent (100 per batch).
        while (true) {
            if ($has_record_type) {
                $pending = $wpdb->get_results(
                    "SELECT q.id AS queue_id, q.record_type, q.property_id, q.attempts, q.operation
                     FROM $table_queue q
                     WHERE q.status = 'pending' AND q.operation IN ('upsert', 'delete')
                     ORDER BY q.queued_at ASC
                     LIMIT 100",
                    ARRAY_A
                );
            } else {
                $pending = $wpdb->get_results(
                    "SELECT q.id AS queue_id, 'property' AS record_type, q.property_id, q.attempts, q.operation
                     FROM $table_queue q
                     WHERE q.status = 'pending' AND q.operation IN ('upsert', 'delete')
                     ORDER BY q.queued_at ASC
                     LIMIT 100",
                    ARRAY_A
                );
            }

            if (empty($pending)) {
                break; // No more pending records — done.
            }

            // Separate into property and request IDs
            $prop_queue_items = array();
            $req_queue_items  = array();
            foreach ($pending as $qi) {
                $rtype = !empty($qi['record_type']) ? $qi['record_type'] : 'property';
                if ($rtype === 'request') {
                    $req_queue_items[] = $qi;
                } else {
                    $prop_queue_items[] = $qi;
                }
            }

            $prop_map = array();
            if (!empty($prop_queue_items)) {
                $prop_ids     = array_map('sanitize_text_field', array_column($prop_queue_items, 'property_id'));
                $placeholders = implode(',', array_fill(0, count($prop_ids), '%s'));
                $props = $wpdb->get_results(
                    $wpdb->prepare("SELECT * FROM $table_properties WHERE id IN ($placeholders)", $prop_ids),
                    ARRAY_A
                );
                if ($props) {
                    foreach ($props as $p) {
                        $prop_map[$p['id']] = $p;
                    }
                }
            }

            $req_map = array();
            if (!empty($req_queue_items)) {
                $req_ids      = array_map('sanitize_text_field', array_column($req_queue_items, 'property_id'));
                $placeholders = implode(',', array_fill(0, count($req_ids), '%s'));
                $reqs = $wpdb->get_results(
                    $wpdb->prepare("SELECT * FROM $table_requests WHERE id IN ($placeholders)", $req_ids),
                    ARRAY_A
                );
                if ($reqs) {
                    foreach ($reqs as $r) {
                        $req_map[$r['id']] = $r;
                    }
                }
            }

            // Build records array keeping queue_id for response matching
            $records = array();
            $missing_queue_ids = array();

            foreach ($pending as $qi) {
                $rtype = !empty($qi['record_type']) ? $qi['record_type'] : 'property';
                $rec_id = sanitize_text_field($qi['property_id']);
                $qid    = (string) $qi['queue_id'];

                if ($rtype === 'request') {
                    if (isset($req_map[$rec_id])) {
                        $req_data = $req_map[$rec_id];
                        $req_data['_record_type'] = 'request';
                        $records[] = array(
                            'queue_id'    => $qid,
                            'property_id' => $rec_id,
                            'record_type' => 'request',
                            'attempts'    => intval($qi['attempts']),
                            'match_key'   => $rec_id,
                            'data'        => $req_data,
                        );
                    } else {
                        $missing_queue_ids[] = $qi;
                    }
                } else {
                    if (isset($prop_map[$rec_id])) {
                        $prop_data = $prop_map[$rec_id];
                        $prop_data['_record_type'] = 'property';

                        // Attach local documents with base64 encoded physical files
                        $table_docs = $wpdb->prefix . 'assessor_documents';
                        $docs = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_docs WHERE property_id = %s", $rec_id), ARRAY_A);
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
                        $local_state = $wpdb->get_var($wpdb->prepare("SELECT state FROM $table_states WHERE property_id = %s", $rec_id));
                        $prop_data['property_state'] = $local_state ? $local_state : 'CURRENT';

                        $records[] = array(
                            'queue_id'    => $qid,
                            'property_id' => $rec_id,
                            'record_type' => 'property',
                            'attempts'    => intval($qi['attempts']),
                            'match_key'   => $prop_data['tax_declaration_number'],
                            'data'        => $prop_data,
                        );
                    } else {
                        $missing_queue_ids[] = $qi;
                    }
                }
            }

            if (!empty($missing_queue_ids)) {
                foreach ($missing_queue_ids as $qi) {
                    $wpdb->update(
                        $table_queue,
                        array(
                            'status'     => 'failed',
                            'attempts'   => intval($qi['attempts']) + 1,
                            'last_error' => 'Row not found for queued ID',
                        ),
                        array('id' => (string) $qi['queue_id']),
                        array('%s', '%d', '%s'),
                        array('%s')
                    );
                    if ($report) {
                        $rtype = !empty($qi['record_type']) ? $qi['record_type'] : 'property';
                        $report->record_item(
                            $rtype,
                            $qi['property_id'],
                            'local_to_live',
                            'failed',
                            array('error' => 'Row not found for queued ID')
                        );
                    }
                }
                $total_errors[] = 'Rows not found for ' . count($missing_queue_ids) . ' queued IDs';
            }

            if (empty($records)) {
                continue; // Try next batch
            }

            $payload  = array('records' => array_column($records, 'data'));
            $response = self::live_api_request('POST', '/assessor/v1/sync/push', $payload);

            if (is_wp_error($response)) {
                $err_msg = $response->get_error_message();
                foreach ($records as $rec) {
                    $wpdb->update(
                        $table_queue,
                        array(
                            'status'     => 'failed',
                            'attempts'   => intval($rec['attempts']) + 1,
                            'last_error' => $err_msg,
                        ),
                        array('id' => (string) $rec['queue_id']),
                        array('%s', '%d', '%s'),
                        array('%s')
                    );
                    if ($report) {
                        $rdata = isset($rec['data']) ? $rec['data'] : array();
                        $display = array('error' => $err_msg);
                        if ($rec['record_type'] === 'property') {
                            $display['tax_declaration_number']  = $rdata['tax_declaration_number'] ?? '';
                            $display['declarant_last_name']     = $rdata['declarant_last_name'] ?? '';
                            $display['declarant_first_name']    = $rdata['declarant_first_name'] ?? '';
                            $display['declarant_middle_initial'] = $rdata['declarant_middle_initial'] ?? '';
                            $display['business_name']           = $rdata['business_name'] ?? '';
                            $display['location']                = $rdata['location'] ?? '';
                            $display['pin']                     = $rdata['pin'] ?? '';
                            $display['assessed_value']          = $rdata['assessed_value'] ?? null;
                            $display['assessed_value_old']      = $rdata['assessed_value_old'] ?? null;
                        } else {
                            $display['receipt_number'] = $rdata['receipt_number'] ?? '';
                            $display['client_name']    = $rdata['client_name'] ?? '';
                            $display['purpose']        = $rdata['purpose'] ?? '';
                        }
                        $report->record_item($rec['record_type'], $rec['property_id'], 'local_to_live', 'failed', $display);
                    }
                }
                $total_errors[] = $err_msg;
                break; // Network error — stop trying; will retry on next sync cycle.
            }

            $body    = json_decode(wp_remote_retrieve_body($response), true);
            $results = isset($body['results']) ? $body['results'] : array();

            foreach ($records as $rec) {
                $pid = $rec['property_id'];
                $match_key = $rec['match_key'];

                if (isset($results[$pid])) {
                    $res_item = $results[$pid];
                } elseif (isset($results[$match_key])) {
                    $res_item = $results[$match_key];
                } else {
                    $res_item = array('status' => 'error', 'message' => 'No response from live site');
                }
                $res_status = isset($res_item['status']) ? $res_item['status'] : 'error';

                if ($res_status === 'synced') {
                    $queue_status = 'synced';
                    $item_action  = 'updated'; // Pushed upstream
                    $total_pushed++;
                } elseif ($res_status === 'skipped') {
                    $queue_status = 'skipped';
                    $item_action  = 'skipped';
                    $total_skipped++;
                } else {
                    $queue_status = 'failed';
                    $item_action  = 'failed';
                    $total_errors[] = $pid . ': ' . (isset($res_item['message']) ? $res_item['message'] : 'unknown error');
                }

                $wpdb->update(
                    $table_queue,
                    array(
                        'status'     => $queue_status,
                        'attempts'   => $rec['attempts'] + 1,
                        'last_error' => $queue_status === 'failed' ? (isset($res_item['message']) ? $res_item['message'] : 'unknown') : null,
                        'synced_at'  => $queue_status !== 'failed' ? current_time('mysql') : null,
                    ),
                    array('id' => (string) $rec['queue_id']),
                    array('%s', '%d', '%s', '%s'),
                    array('%s')
                );

                if ($report) {
                    $rdata = isset($rec['data']) ? $rec['data'] : array();
                    $display = array();
                    if ($queue_status === 'failed') {
                        $display['error'] = isset($res_item['message']) ? $res_item['message'] : 'unknown';
                    }
                    if ($rec['record_type'] === 'property') {
                        $display['tax_declaration_number']  = $rdata['tax_declaration_number'] ?? '';
                        $display['declarant_last_name']     = $rdata['declarant_last_name'] ?? '';
                        $display['declarant_first_name']    = $rdata['declarant_first_name'] ?? '';
                        $display['declarant_middle_initial'] = $rdata['declarant_middle_initial'] ?? '';
                        $display['business_name']           = $rdata['business_name'] ?? '';
                        $display['location']                = $rdata['location'] ?? '';
                        $display['pin']                     = $rdata['pin'] ?? '';
                        $display['assessed_value']          = $rdata['assessed_value'] ?? null;
                        $display['assessed_value_old']      = $rdata['assessed_value_old'] ?? null;
                    } else {
                        $display['receipt_number'] = $rdata['receipt_number'] ?? '';
                        $display['client_name']    = $rdata['client_name'] ?? '';
                        $display['purpose']        = $rdata['purpose'] ?? '';
                        $display['amount_paid']    = $rdata['amount_paid'] ?? null;
                    }
                    $report->record_item($rec['record_type'], $rec['property_id'], 'local_to_live', $item_action, $display);
                }
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
     * Dedicated sync function for assessor_property_types.
     * Synchronizes complete local snapshot to live using UUID v7 id and unique business key 'code'.
     *
     * @param bool $force If true, ignores dirty flag and synchronizes immediately.
     * @return array { success: bool, table: string, rows_sent: int, rows_inserted: int, message: string, error: string|null }
     */
    public static function sync_property_types($force = false) {
        return self::sync_lookup_table('assessor_property_types', 'code', $force);
    }

    /**
     * Dedicated sync function for assessor_general_classes.
     * Synchronizes complete local snapshot to live using UUID v7 id and unique business key 'code'.
     *
     * @param bool $force If true, ignores dirty flag and synchronizes immediately.
     * @return array { success: bool, table: string, rows_sent: int, rows_inserted: int, message: string, error: string|null }
     */
    public static function sync_general_classes($force = false) {
        return self::sync_lookup_table('assessor_general_classes', 'code', $force);
    }

    /**
     * Dedicated sync function for assessor_locations.
     * Synchronizes complete local snapshot to live using UUID v7 id and unique business key 'code'.
     *
     * @param bool $force If true, ignores dirty flag and synchronizes immediately.
     * @return array { success: bool, table: string, rows_sent: int, rows_inserted: int, message: string, error: string|null }
     */
    public static function sync_locations($force = false) {
        return self::sync_lookup_table('assessor_locations', 'code', $force);
    }

    /**
     * Dedicated sync function for assessor_request_purposes.
     * Synchronizes complete local snapshot to live using UUID v7 id and unique business key 'purpose'.
     *
     * @param bool $force If true, ignores dirty flag and synchronizes immediately.
     * @return array { success: bool, table: string, rows_sent: int, rows_inserted: int, message: string, error: string|null }
     */
    public static function sync_request_purposes($force = false) {
        return self::sync_lookup_table('assessor_request_purposes', 'purpose', $force);
    }

    /**
     * Internal implementation helper for dedicated lookup table synchronization (push-only).
     *
     * @param string $table_suffix e.g. 'assessor_property_types'
     * @param string $unique_col   e.g. 'code' or 'purpose'
     * @param bool   $force        Sync even if dirty flag is not '1'
     * @return array
     */
    private static function sync_lookup_table($table_suffix, $unique_col, $force = false) {
        if (!defined('ASSESSOR_IS_LOCAL_BUILD') || !ASSESSOR_IS_LOCAL_BUILD) {
            return array(
                'success'       => false,
                'table'         => $table_suffix,
                'rows_sent'     => 0,
                'rows_inserted' => 0,
                'message'       => 'Outbound config sync is only permitted from Local builds.',
                'error'         => 'not_local_build',
            );
        }

        $meta_key = 'config_dirty_' . $table_suffix;
        $dirty    = self::get_meta($meta_key);

        if (!$force && $dirty !== '1') {
            return array(
                'success'       => true,
                'table'         => $table_suffix,
                'rows_sent'     => 0,
                'rows_inserted' => 0,
                'message'       => 'Table is clean (not dirty). Skipped.',
                'error'         => null,
            );
        }

        global $wpdb;
        $full_table = $wpdb->prefix . $table_suffix;
        $rows = $wpdb->get_results("SELECT * FROM $full_table", ARRAY_A);

        if ($rows === null) {
            $err_msg = 'Database query failure reading ' . $full_table . ': ' . $wpdb->last_error;
            error_log('Assessor Sync: ' . $table_suffix . ' push failed — ' . $err_msg);
            return array(
                'success'       => false,
                'table'         => $table_suffix,
                'rows_sent'     => 0,
                'rows_inserted' => 0,
                'message'       => $err_msg,
                'error'         => 'db_query_failed',
            );
        }

        $row_count = count($rows);
        $payload   = array(
            'table' => $table_suffix,
            'rows'  => $rows,
        );

        $response = self::live_api_request('POST', '/assessor/v1/sync/push-config', $payload);

        if (is_wp_error($response)) {
            $err_msg = $response->get_error_message();
            error_log('Assessor Sync: ' . $table_suffix . ' push failed — ' . $err_msg);
            return array(
                'success'       => false,
                'table'         => $table_suffix,
                'rows_sent'     => $row_count,
                'rows_inserted' => 0,
                'message'       => $err_msg,
                'error'         => 'http_request_failed',
            );
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $inserted = isset($body['inserted']) ? intval($body['inserted']) : $row_count;

        // Reset dirty flag only after successful delivery & acceptance
        self::set_meta($meta_key, '0');

        error_log('Assessor Sync: ' . str_replace('assessor_', '', $table_suffix) . ' pushed — rows=' . $inserted);

        return array(
            'success'       => true,
            'table'         => $table_suffix,
            'rows_sent'     => $row_count,
            'rows_inserted' => $inserted,
            'message'       => "Successfully synchronized $table_suffix ($inserted rows confirmed by live).",
            'error'         => null,
        );
    }

    /**
     * Reconcile the 4 lookup tables bidirectionally between Local and Live.
     *
     * Authority is row-level based on updated_at timestamps (last write wins),
     * with deterministic content-hash tie-breaking when timestamps match.
     *
     * 1. GET /assessor/v1/sync/pull-config from Live
     * 2. For each table, match by UUID then by business key
     * 3. Select winning row (Local vs Live)
     * 4. Write canonical rows locally under self::$syncing guard
     * 5. Push canonical snapshot to Live via POST /assessor/v1/sync/push-config
     * 6. Clear dirty flag on success; preserve dirty flag on failure
     *
     * @param bool $force If true, forces reconciliation even if local dirty flags are 0.
     * @return array
     */
    public static function sync_lookup_tables_bidirectional($force = false) {
        if (!defined('ASSESSOR_IS_LOCAL_BUILD') || !ASSESSOR_IS_LOCAL_BUILD) {
            return array(
                'success' => false,
                'message' => 'Bidirectional lookup reconciliation is initiated by Local.',
                'tables'  => array(),
            );
        }

        $target_tables = array(
            'assessor_property_types'   => 'code',
            'assessor_general_classes'  => 'code',
            'assessor_locations'        => 'code',
            'assessor_request_purposes' => 'purpose',
        );

        // Check if any table is dirty or if reconciliation is forced
        $has_dirty = false;
        foreach ($target_tables as $table_suffix => $biz_col) {
            if (self::get_meta('config_dirty_' . $table_suffix) === '1') {
                $has_dirty = true;
                break;
            }
        }

        if (!$force && !$has_dirty) {
            // Check if remote check is desired; per Step 14, small lookup tables can be checked
            // but if not forced and not dirty, we still proceed to ensure Live edits are pulled.
        }

        error_log('Assessor Sync: Starting bidirectional lookup reconciliation.');

        // Step 1: Fetch Live lookup snapshot
        $response = self::live_api_request('GET', '/assessor/v1/sync/pull-config');

        if (is_wp_error($response)) {
            $err_msg = $response->get_error_message();
            error_log('Assessor Sync: Unable to retrieve live lookup snapshot — ' . $err_msg);
            return array(
                'success' => false,
                'message' => 'Unable to retrieve live lookup snapshot.',
                'errors'  => array($err_msg),
                'tables'  => array(),
            );
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['success']) || !isset($body['tables'])) {
            $err_msg = 'Invalid JSON response from /assessor/v1/sync/pull-config';
            error_log('Assessor Sync: ' . $err_msg);
            return array(
                'success' => false,
                'message' => $err_msg,
                'errors'  => array($err_msg),
                'tables'  => array(),
            );
        }

        $remote_tables = $body['tables'];
        $table_results = array();
        $overall_success = true;

        foreach ($target_tables as $table_suffix => $biz_col) {
            $remote_rows = isset($remote_tables[$table_suffix]) && is_array($remote_tables[$table_suffix])
                ? $remote_tables[$table_suffix]
                : array();

            $result = self::reconcile_single_lookup_table($table_suffix, $biz_col, $remote_rows);
            $table_results[$table_suffix] = $result;

            if (!$result['success']) {
                $overall_success = false;
                error_log("Assessor Sync: Lookup reconciliation failed for $table_suffix — " . ($result['error'] ?? 'unknown'));
            } else {
                error_log("Assessor Sync: $table_suffix — LOCAL won: {$result['local_wins']}, LIVE won: {$result['live_wins']}, unchanged: {$result['unchanged']}.");
            }
        }

        if ($overall_success) {
            error_log('Assessor Sync: Lookup reconciliation completed successfully.');
        }

        return array(
            'success' => $overall_success,
            'message' => $overall_success ? 'Lookup tables reconciled successfully.' : 'One or more lookup tables failed reconciliation.',
            'tables'  => $table_results,
        );
    }

    /**
     * Reconcile a single lookup table against remote rows.
     *
     * @param string $table_suffix
     * @param string $biz_col
     * @param array  $remote_rows
     * @return array
     */
    private static function reconcile_single_lookup_table($table_suffix, $biz_col, $remote_rows) {
        global $wpdb;
        $meta_key   = 'config_dirty_' . $table_suffix;
        $full_table = $wpdb->prefix . $table_suffix;

        $local_rows = $wpdb->get_results("SELECT * FROM $full_table", ARRAY_A);
        if ($local_rows === null) {
            return array(
                'success'           => false,
                'table'             => $table_suffix,
                'local_rows'        => 0,
                'live_rows'         => count($remote_rows),
                'local_wins'        => 0,
                'live_wins'         => 0,
                'unchanged'         => 0,
                'new_rows'          => 0,
                'rows_sent_to_live' => 0,
                'error'             => 'Local DB query error: ' . $wpdb->last_error,
            );
        }

        // Index local rows by UUID and business key
        $local_by_uuid = array();
        $local_by_biz  = array();
        foreach ($local_rows as $lr) {
            $id = (string)($lr['id'] ?? '');
            $bk = (string)($lr[$biz_col] ?? '');
            if ($id !== '') {
                $local_by_uuid[$id] = $lr;
            }
            if ($bk !== '') {
                $local_by_biz[$bk] = $lr;
            }
        }

        // Track matched/processed local records
        $matched_local_ids = array();
        $canonical_rows    = array();

        $local_wins = 0;
        $live_wins  = 0;
        $unchanged  = 0;
        $new_rows   = 0;

        foreach ($remote_rows as $rr) {
            if (!is_array($rr) || empty($rr['id'])) {
                continue;
            }
            $r_id  = (string)$rr['id'];
            $r_biz = (string)($rr[$biz_col] ?? '');

            // Priority 1: Match by UUID
            $local_match = null;
            if (isset($local_by_uuid[$r_id])) {
                $local_match = $local_by_uuid[$r_id];
            } elseif ($r_biz !== '' && isset($local_by_biz[$r_biz])) {
                // Priority 2: Match by business key
                $local_match = $local_by_biz[$r_biz];
            }

            if ($local_match) {
                $matched_local_ids[(string)$local_match['id']] = true;

                // Compare updated_at
                $l_ts = isset($local_match['updated_at']) ? strtotime($local_match['updated_at']) : 0;
                $r_ts = isset($rr['updated_at']) ? strtotime($rr['updated_at']) : 0;

                if ($l_ts > $r_ts) {
                    // Local is newer -> LOCAL wins
                    $canonical_rows[] = $local_match;
                    $local_wins++;
                } elseif ($r_ts > $l_ts) {
                    // Live is newer -> LIVE wins
                    $canonical_rows[] = $rr;
                    $live_wins++;
                } else {
                    // Timestamps equal: compare deterministic content hashes
                    $l_hash = self::hash_lookup_row($local_match, $biz_col);
                    $r_hash = self::hash_lookup_row($rr, $biz_col);

                    if ($l_hash === $r_hash) {
                        // Identical content
                        $canonical_rows[] = $local_match;
                        $unchanged++;
                    } else {
                        // Lexical tie-breaker: larger hash wins deterministically
                        if (strcmp($l_hash, $r_hash) >= 0) {
                            $canonical_rows[] = $local_match;
                            $local_wins++;
                        } else {
                            $canonical_rows[] = $rr;
                            $live_wins++;
                        }
                    }
                }
            } else {
                // Row exists only on Live -> LIVE is canonical
                $canonical_rows[] = $rr;
                $live_wins++;
                $new_rows++;
            }
        }

        // Rows existing only on Local -> LOCAL is canonical
        foreach ($local_rows as $lr) {
            $l_id = (string)($lr['id'] ?? '');
            if (!isset($matched_local_ids[$l_id])) {
                $canonical_rows[] = $lr;
                $local_wins++;
                $new_rows++;
            }
        }

        // Step 4: Write canonical rows to Local (under $syncing guard)
        $prev_syncing = self::$syncing;
        self::$syncing = true;
        try {
            foreach ($canonical_rows as $crow) {
                $clean = array_filter($crow, 'is_scalar');
                if (empty($clean['id'])) {
                    continue;
                }
                $wpdb->replace($full_table, $clean);
            }
        } finally {
            self::$syncing = $prev_syncing;
        }

        // Step 5: Push the already-reconciled canonical snapshot to Live
        $payload = array(
            'table' => $table_suffix,
            'rows'  => $canonical_rows,
        );

        $push_resp = self::live_api_request('POST', '/assessor/v1/sync/push-config', $payload);

        if (is_wp_error($push_resp)) {
            $err_msg = $push_resp->get_error_message();
            self::set_meta($meta_key, '1'); // Preserve dirty flag for retry
            return array(
                'success'           => false,
                'table'             => $table_suffix,
                'local_rows'        => count($local_rows),
                'live_rows'         => count($remote_rows),
                'local_wins'        => $local_wins,
                'live_wins'         => $live_wins,
                'unchanged'         => $unchanged,
                'new_rows'          => $new_rows,
                'rows_sent_to_live' => count($canonical_rows),
                'error'             => 'Push to Live failed: ' . $err_msg,
            );
        }

        // Step 6: Mark table clean on success
        self::set_meta($meta_key, '0');

        return array(
            'success'           => true,
            'table'             => $table_suffix,
            'local_rows'        => count($local_rows),
            'live_rows'         => count($remote_rows),
            'local_wins'        => $local_wins,
            'live_wins'         => $live_wins,
            'unchanged'         => $unchanged,
            'new_rows'          => $new_rows,
            'rows_sent_to_live' => count($canonical_rows),
            'error'             => null,
        );
    }

    /**
     * Compute a deterministic content hash for a lookup row.
     *
     * @param array  $row
     * @param string $biz_col
     * @return string
     */
    private static function hash_lookup_row($row, $biz_col) {
        $data = array(
            'id'         => (string)($row['id'] ?? ''),
            'biz'        => (string)($row[$biz_col] ?? ''),
            'name'       => (string)($row['name'] ?? ''),
            'status'     => (string)($row['status'] ?? 'active'),
            'sort_order' => (int)($row['sort_order'] ?? 0),
            'pin'        => (string)($row['pin'] ?? ''),
            'amount'     => isset($row['amount']) ? (string)floatval($row['amount']) : '',
        );
        ksort($data);
        return md5(json_encode($data));
    }

    /**
     * Push all dirty config tables to the live site.
     * Dispatches bidirectional lookup reconciliation for the 4 lookup tables,
     * and synchronizes revision entries using its established snapshot behavior.
     *
     * @return array { tables_pushed: [], tables_skipped: [], errors: [] }
     */
    public static function push_pending_config() {
        $pushed  = array();
        $skipped = array();
        $errors  = array();

        // 1. Bidirectional reconciliation for the 4 lookup tables
        $reconcile_res = self::sync_lookup_tables_bidirectional(false);
        if (!empty($reconcile_res['tables'])) {
            foreach ($reconcile_res['tables'] as $table_suffix => $tinfo) {
                if (!empty($tinfo['success'])) {
                    $pushed[] = $table_suffix;
                } else {
                    $errors[] = $table_suffix . ': ' . ($tinfo['error'] ?? 'Reconciliation failed');
                }
            }
        } elseif (!$reconcile_res['success']) {
            $errors[] = 'Lookup reconciliation: ' . $reconcile_res['message'];
        }

        // 2. Existing config snapshot behavior for assessor_revision_entries
        $dirty_rev = (self::get_meta('config_dirty_assessor_revision_entries') === '1');
        if ($dirty_rev) {
            global $wpdb;
            $table = $wpdb->prefix . 'assessor_revision_entries';
            $rows  = $wpdb->get_results("SELECT * FROM $table", ARRAY_A);
            if ($rows === null) {
                $rows = array();
            }

            $payload = array(
                'table' => 'assessor_revision_entries',
                'rows'  => $rows,
            );
            $response = self::live_api_request('POST', '/assessor/v1/sync/push-config', $payload);

            if (is_wp_error($response)) {
                $errors[] = 'assessor_revision_entries: ' . $response->get_error_message();
            } else {
                self::set_meta('config_dirty_assessor_revision_entries', '0');
                $pushed[] = 'assessor_revision_entries';
            }
        } else {
            $skipped[] = 'assessor_revision_entries';
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
     * @param Assessor_Sync_Report|null $report
     * @return array { pulled, skipped, errors }
     */
    public static function pull_from_live($force_full = false, $report = null) {
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
                $result = self::apply_remote_record($remote, $force_full, $report);
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
     * @param Assessor_Sync_Report|null $report
     * @return string 'synced' | 'skipped' | error message
     */
    private static function apply_remote_record($remote, $force_full = false, $report = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'assessor_properties';

        $tax_num = isset($remote['tax_declaration_number']) ? trim($remote['tax_declaration_number']) : '';
        $prop_id = isset($remote['id']) ? trim((string)$remote['id']) : '';
        $revision_id = isset($remote['revision_id']) && !empty($remote['revision_id']) ? trim((string)$remote['revision_id']) : null;

        if ($tax_num === '' && $prop_id === '') {
            if ($report) {
                $report->record_item('property', $prop_id ?: 'unknown', 'live_to_local', 'skipped', array(
                    'reason' => 'missing tax_declaration_number and id'
                ));
            }
            return 'skipped: missing tax_declaration_number and id';
        }

        // Exact match by UUID first
        $local = null;
        if (!empty($prop_id)) {
            $local = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, tax_declaration_number, declarant_last_name, declarant_first_name, declarant_middle_initial, business_name, location, pin, assessed_value, assessed_value_old, revision_id, status, updated_at FROM $table WHERE id = %s LIMIT 1",
                    $prop_id
                ),
                ARRAY_A
            );
        }

        // Secondary fallback: match by (tax_declaration_number, revision_id) if UUID didn't match
        if (!$local && !empty($tax_num) && !empty($revision_id)) {
            $local = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, tax_declaration_number, declarant_last_name, declarant_first_name, declarant_middle_initial, business_name, location, pin, assessed_value, assessed_value_old, revision_id, status, updated_at FROM $table WHERE tax_declaration_number = %s AND revision_id = %s LIMIT 1",
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
            if ($report) {
                $report->record_item('property', $local['id'], 'live_to_local', 'skipped', array(
                    'tax_declaration_number'   => $tax_num ?: ($local['tax_declaration_number'] ?? ''),
                    'declarant_last_name'      => $local['declarant_last_name'] ?? ($remote['declarant_last_name'] ?? ''),
                    'declarant_first_name'     => $local['declarant_first_name'] ?? ($remote['declarant_first_name'] ?? ''),
                    'declarant_middle_initial' => $local['declarant_middle_initial'] ?? ($remote['declarant_middle_initial'] ?? ''),
                    'business_name'            => $local['business_name'] ?? ($remote['business_name'] ?? ''),
                    'location'                 => $local['location'] ?? ($remote['location'] ?? ''),
                    'pin'                      => $local['pin'] ?? ($remote['pin'] ?? ''),
                    'assessed_value'           => $local['assessed_value'] ?? ($remote['assessed_value'] ?? null),
                    'assessed_value_old'       => $local['assessed_value_old'] ?? ($remote['assessed_value_old'] ?? null),
                    'revision_id'              => $local['revision_id'] ?? ($remote['revision_id'] ?? ''),
                    'status'                   => $local['status'] ?? ($remote['status'] ?? 'active'),
                    'reason'                   => 'local version is equal or newer'
                ));
            }
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

        $action = $local ? 'updated' : 'created';
        $old_assessed_value = $local ? ($local['assessed_value'] ?? null) : null;
        $old_status         = $local ? ($local['status'] ?? null) : null;

        if ($local) {
            $wpdb->update($table, $safe, array('id' => $local['id']), null, array('%s'));
            if ($wpdb->last_error) {
                $err = 'error updating ' . $tax_num . ': ' . $wpdb->last_error;
                if ($report) {
                    $report->record_item('property', $local['id'], 'live_to_local', 'failed', array(
                        'tax_declaration_number'   => $tax_num,
                        'declarant_last_name'      => $safe['declarant_last_name'] ?? ($local['declarant_last_name'] ?? ''),
                        'declarant_first_name'     => $safe['declarant_first_name'] ?? ($local['declarant_first_name'] ?? ''),
                        'declarant_middle_initial' => $safe['declarant_middle_initial'] ?? ($local['declarant_middle_initial'] ?? ''),
                        'business_name'            => $safe['business_name'] ?? ($local['business_name'] ?? ''),
                        'location'                 => $safe['location'] ?? ($local['location'] ?? ''),
                        'pin'                      => $safe['pin'] ?? ($local['pin'] ?? ''),
                        'assessed_value'           => $safe['assessed_value'] ?? ($local['assessed_value'] ?? null),
                        'assessed_value_old'       => $old_assessed_value,
                        'error'                    => $wpdb->last_error,
                    ));
                }
                return $err;
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
                $err = 'error inserting ' . $tax_num . ': ' . $wpdb->last_error;
                if ($report) {
                    $report->record_item('property', $safe['id'], 'live_to_local', 'failed', array(
                        'tax_declaration_number'   => $tax_num,
                        'declarant_last_name'      => $safe['declarant_last_name'] ?? '',
                        'declarant_first_name'     => $safe['declarant_first_name'] ?? '',
                        'declarant_middle_initial' => $safe['declarant_middle_initial'] ?? '',
                        'business_name'            => $safe['business_name'] ?? '',
                        'location'                 => $safe['location'] ?? '',
                        'pin'                      => $safe['pin'] ?? '',
                        'assessed_value'           => $safe['assessed_value'] ?? null,
                        'assessed_value_old'       => null,
                        'error'                    => $wpdb->last_error,
                    ));
                }
                return $err;
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
            error_log(
                'Assessor Sync Pull Apply: property_id=' . $local_property_id .
                ' property_state=' . strtoupper(trim($property_state))
            );

            $table_property_states = $wpdb->prefix . 'assessor_property_states';
            $wpdb->replace($table_property_states, array(
                'property_id' => $local_property_id,
                'state'       => strtoupper(trim($property_state))
            ), array('%s', '%s'));
        }

        if ($report) {
            $report->record_item('property', $local_property_id, 'live_to_local', $action, array(
                'tax_declaration_number'   => $tax_num,
                'declarant_last_name'      => $safe['declarant_last_name'] ?? '',
                'declarant_first_name'     => $safe['declarant_first_name'] ?? '',
                'declarant_middle_initial' => $safe['declarant_middle_initial'] ?? '',
                'business_name'            => $safe['business_name'] ?? '',
                'location'                 => $safe['location'] ?? '',
                'pin'                      => $safe['pin'] ?? '',
                'revision_id'              => $safe['revision_id'] ?? '',
                'assessed_value'           => $safe['assessed_value'] ?? null,
                'assessed_value_old'       => $old_assessed_value,
                'status'                   => $safe['status'] ?? 'active',
                'status_old'               => $old_status,
            ));
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

    /**
     * Fetch recently changed request records from the live site and upsert locally.
     *
     * @param bool $force_full If true, passes the flag to apply_remote_request to force overwrite.
     * @param Assessor_Sync_Report|null $report
     * @return array { pulled, skipped, errors }
     */
    public static function pull_requests_from_live($force_full = false, $report = null) {
        $last_pull = self::get_meta('last_pull_requests_at');
        $since     = $last_pull ? $last_pull : '2000-01-01 00:00:00';

        $pulled  = 0;
        $skipped = 0;
        $errors  = array();
        $page_limit = 500;
        $sync_start = '';
        $pull_completed_successfully = true;

        $offset = (int) self::get_meta('pull_requests_offset');

        self::$syncing = true;

        while (true) {
            $response = self::live_api_request(
                'GET',
                '/assessor/v1/sync/pull',
                array('since' => $since, 'limit' => $page_limit, 'offset' => $offset, 'type' => 'requests')
            );

            if (is_wp_error($response)) {
                $errors[] = $response->get_error_message();
                $pull_completed_successfully = false;
                break;
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);

            if (empty($sync_start) && !empty($body['server_ts'])) {
                $sync_start = $body['server_ts'];
            }

            $records = isset($body['records']) ? $body['records'] : array();

            if (empty($records)) {
                break;
            }

            foreach ($records as $remote) {
                $result = self::apply_remote_request($remote, $force_full, $report);
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
                break;
            }

            $offset += $page_limit;
            self::set_meta('pull_requests_offset', $offset);
        }

        self::$syncing = false;

        if ($pull_completed_successfully && !empty($sync_start)) {
            self::set_meta('last_pull_requests_at', $sync_start);
            self::set_meta('pull_requests_offset', 0);
        }

        return array('pulled' => $pulled, 'skipped' => $skipped, 'errors' => $errors);
    }

    /**
     * Apply a single request record from the live site to the local database.
     * Last-write-wins: only writes if remote updated_at > local updated_at.
     * Preserves UUID v7 and supports soft-deletes (deleted_at).
     *
     * @param array $remote The record array from the live site.
     * @param bool $force_full If true, ignores timestamps and forces an overwrite of the local record.
     * @param Assessor_Sync_Report|null $report
     * @return string 'synced' | 'skipped' | error message
     */
    private static function apply_remote_request($remote, $force_full = false, $report = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'assessor_requests';

        $req_id = isset($remote['id']) ? trim((string)$remote['id']) : '';
        if (empty($req_id)) {
            if ($report) {
                $report->record_item('request', 'unknown', 'live_to_local', 'skipped', array('reason' => 'missing id'));
            }
            return 'skipped: missing id';
        }

        // Exact match by UUID
        $local = $wpdb->get_row(
            $wpdb->prepare("SELECT id, receipt_number, client_name, purpose, amount_paid, updated_at, deleted_at FROM $table WHERE id = %s LIMIT 1", $req_id),
            ARRAY_A
        );

        $remote_ts = isset($remote['updated_at']) ? strtotime($remote['updated_at']) : 0;
        $local_ts  = $local ? strtotime($local['updated_at']) : 0;

        // Skip if local is same age or newer (unless forcing a full resync)
        if (!$force_full && $local && $local_ts >= $remote_ts) {
            if ($report) {
                $report->record_item('request', $req_id, 'live_to_local', 'skipped', array(
                    'receipt_number' => $remote['receipt_number'] ?? ($local['receipt_number'] ?? ''),
                    'client_name'    => $remote['client_name'] ?? ($local['client_name'] ?? ''),
                    'purpose'        => $remote['purpose'] ?? ($local['purpose'] ?? ''),
                    'reason'         => 'local version is equal or newer'
                ));
            }
            return 'skipped';
        }

        $safe = self::sanitize_sync_request_record($remote);

        $is_deleted = !empty($remote['deleted_at']);
        $action = $is_deleted ? 'deleted' : ($local ? 'updated' : 'created');

        if ($local) {
            $updated = $wpdb->update($table, $safe, array('id' => $local['id']), null, array('%s'));
            if ($updated === false) {
                $err = 'error updating request ' . $req_id . ': ' . $wpdb->last_error;
                if ($report) {
                    $report->record_item('request', $req_id, 'live_to_local', 'failed', array(
                        'receipt_number' => $safe['receipt_number'] ?? '',
                        'client_name'    => $safe['client_name'] ?? '',
                        'error'          => $wpdb->last_error,
                    ));
                }
                return $err;
            }
        } else {
            $inserted = $wpdb->insert($table, $safe);
            if ($inserted === false) {
                $err = 'error inserting request ' . $req_id . ': ' . $wpdb->last_error;
                if ($report) {
                    $report->record_item('request', $req_id, 'live_to_local', 'failed', array(
                        'receipt_number' => $safe['receipt_number'] ?? '',
                        'client_name'    => $safe['client_name'] ?? '',
                        'error'          => $wpdb->last_error,
                    ));
                }
                return $err;
            }
        }

        if ($report) {
            $report->record_item('request', $req_id, 'live_to_local', $action, array(
                'receipt_number' => $safe['receipt_number'] ?? '',
                'client_name'    => $safe['client_name'] ?? '',
                'purpose'        => $safe['purpose'] ?? '',
                'amount_paid'    => $safe['amount_paid'] ?? null,
                'date_issued'    => $safe['date_issued'] ?? '',
                'deleted_at'     => $safe['deleted_at'] ?? null,
            ));
        }

        return 'synced';
    }

    /**
     * Whitelist columns safe to sync for requests.
     */
    private static function sanitize_sync_request_record($record) {
        $allowed = array(
            'id', 'property_id', 'amount_paid', 'receipt_number',
            'is_official_request', 'date_issued', 'place_issued',
            'prepared_by', 'payment_type', 'purpose', 'client_name',
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
        $uuid = class_exists('Assessor_UUID') ? Assessor_UUID::v7() : wp_generate_uuid4();
        $wpdb->query($wpdb->prepare(
            "INSERT INTO $table (id, meta_key, meta_value) VALUES (%s, %s, %s)
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
            $uuid,
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

    /**
     * Check if there is an unmirrored sync run and attempt to publish it.
     */
    public static function check_pending_report_mirror() {
        $last_run_id = self::get_meta('last_sync_run_id');
        $last_pub_id = self::get_meta('last_published_sync_run_id');
        $pending     = self::get_meta('sync_report_publish_pending');

        if (!empty($last_run_id) && ($last_run_id !== $last_pub_id || !empty($pending))) {
            self::mirror_report_to_live($last_run_id);
        }
    }

    /**
     * Mirror a completed sync report and its items to the Live Server.
     * Authenticated via X-Sync-Token using live_api_request.
     * Never fails or marks the local database sync as failed if Live mirroring fails.
     *
     * @param string $run_id UUID v7 of the sync run.
     */
    public static function mirror_report_to_live($run_id) {
        if (empty($run_id)) {
            return;
        }

        if (!defined('ASSESSOR_IS_LOCAL_BUILD') || !ASSESSOR_IS_LOCAL_BUILD) {
            return; // Only local pushes mirrored reports to live
        }

        if (!self::is_online()) {
            self::set_meta('sync_report_publish_pending', '1');
            return;
        }

        global $wpdb;
        $table_runs  = $wpdb->prefix . 'assessor_sync_runs';
        $table_items = $wpdb->prefix . 'assessor_sync_run_items';

        $run_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_runs WHERE id = %s LIMIT 1", $run_id), ARRAY_A);
        if (!$run_row) {
            return;
        }

        $summary = !empty($run_row['summary_json']) ? json_decode($run_row['summary_json'], true) : array();

        $run_payload = array(
            'run' => array(
                'id'           => $run_row['id'],
                'mode'         => $run_row['mode'],
                'status'       => $run_row['status'],
                'started_at'   => $run_row['started_at'],
                'completed_at' => $run_row['completed_at'],
                'created_at'   => $run_row['created_at'],
                'summary'      => $summary,
            )
        );

        // 1. Publish the run metadata
        $res = self::live_api_request('POST', '/assessor/v1/sync/report/publish', $run_payload);
        if (is_wp_error($res)) {
            error_log('Assessor Sync: Failed to publish sync report to Live: ' . $res->get_error_message());
            self::set_meta('sync_report_publish_pending', '1');
            return;
        }

        // 2. Publish items in batches of 100
        $items_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_items WHERE run_id = %s", $run_id));
        $batch_size = 100;
        $pages = $items_count > 0 ? (int) ceil($items_count / $batch_size) : 0;

        for ($p = 0; $p < $pages; $p++) {
            $offset = $p * $batch_size;
            $items = $wpdb->get_results($wpdb->prepare(
                "SELECT id, run_id, record_type, record_id, direction, action, display_data_json, created_at
                 FROM $table_items WHERE run_id = %s ORDER BY id ASC LIMIT %d OFFSET %d",
                $run_id,
                $batch_size,
                $offset
            ), ARRAY_A);

            if (empty($items)) {
                continue;
            }

            $batch_payload = array(
                'run_id' => $run_id,
                'items'  => array_map(function($it) {
                    return array(
                        'id'                => $it['id'],
                        'run_id'            => $it['run_id'],
                        'record_type'       => $it['record_type'],
                        'record_id'         => $it['record_id'],
                        'direction'         => $it['direction'],
                        'action'            => $it['action'],
                        'display_data_json' => $it['display_data_json'],
                        'created_at'        => $it['created_at'],
                    );
                }, $items)
            );

            $item_res = self::live_api_request('POST', '/assessor/v1/sync/report/publish-items', $batch_payload);
            if (is_wp_error($item_res)) {
                error_log("Assessor Sync: Failed to publish batch $p for run $run_id: " . $item_res->get_error_message());
                self::set_meta('sync_report_publish_pending', '1');
                return;
            }
        }

        // Successfully published run and all items
        self::set_meta('last_published_sync_run_id', $run_id);
        self::set_meta('sync_report_publish_pending', '0');
        error_log("Assessor Sync: Successfully published sync report $run_id to Live Server.");
    }
}

