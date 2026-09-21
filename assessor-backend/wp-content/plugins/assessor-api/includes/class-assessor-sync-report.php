<?php
/**
 * Assessor Sync Report Engine
 *
 * Persists historical synchronization runs (assessor_sync_runs)
 * and record-level synchronization activity items (assessor_sync_run_items)
 * with UUID v7 primary keys.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Assessor_Sync_Report {

    /** @var string Current active sync run UUID */
    private $run_id;

    /** @var string Mode ('incremental' | 'full') */
    private $mode;

    /** @var string Start timestamp (MySQL format) */
    private $started_at;

    /** @var array In-memory buffer of report items awaiting batch write */
    private $item_buffer = array();

    /** @var int Batch flush size (50-200 rows) */
    private $batch_size = 100;

    /** @var array Phase tracking data */
    private $phases = array();

    /** @var array Running counters */
    private $counters = array(
        'properties' => array('created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'total' => 0),
        'requests'   => array('created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'deleted' => 0, 'total' => 0),
    );

    /**
     * Start a new sync run and persist initial record to assessor_sync_runs.
     *
     * @param string $mode 'incremental' | 'full'
     * @return Assessor_Sync_Report
     */
    public static function start_run($mode = 'incremental') {
        global $wpdb;

        $report = new self();
        $report->run_id = class_exists('Assessor_UUID') ? Assessor_UUID::v7() : wp_generate_uuid4();
        $report->mode = $mode === 'full' ? 'full' : 'incremental';
        $report->started_at = Assessor_Timezone::now_mysql();

        $table_runs = $wpdb->prefix . 'assessor_sync_runs';
        $wpdb->insert(
            $table_runs,
            array(
                'id'           => $report->run_id,
                'mode'         => $report->mode,
                'status'       => 'running',
                'started_at'   => $report->started_at,
                'completed_at' => null,
                'summary_json' => wp_json_encode(array(
                    'status'   => 'running',
                    'phases'   => array(),
                    'counters' => $report->counters
                )),
                'created_at'   => $report->started_at,
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        // Persist global last sync run pointer in assessor_sync_meta
        self::persist_last_run_pointer($report->run_id);

        return $report;
    }

    /**
     * Persist last_sync_run_id in assessor_sync_meta safely with UUID v7 PK.
     *
     * @param string $run_id
     */
    public static function persist_last_run_pointer($run_id) {
        global $wpdb;
        $table_meta = $wpdb->prefix . 'assessor_sync_meta';
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_meta));
        if (!$table_exists) {
            return;
        }

        $uuid = class_exists('Assessor_UUID') ? Assessor_UUID::v7() : wp_generate_uuid4();
        $wpdb->query($wpdb->prepare(
            "INSERT INTO $table_meta (id, meta_key, meta_value) VALUES (%s, 'last_sync_run_id', %s)
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
            $uuid,
            (string) $run_id
        ));
    }

    public function get_run_id() {
        return $this->run_id;
    }

    /**
     * Update phase execution status.
     *
     * @param string $phase Phase key: 'push', 'config', 'lookups', 'revisions', 'properties', 'requests', 'users'
     * @param string $status 'running' | 'completed' | 'skipped' | 'failed'
     * @param array  $details Additional metrics or errors
     */
    public function update_phase($phase, $status, $details = array()) {
        $this->phases[$phase] = array_merge(
            array('status' => $status, 'updated_at' => Assessor_Timezone::now_mysql()),
            $details
        );
    }

    /**
     * Buffer a record-level event item and flush in batches of 100.
     *
     * Allowed action values: created | updated | skipped | failed | deleted
     * Allowed direction values: local_to_live | live_to_local
     *
     * @param string $record_type 'property' | 'request'
     * @param string $record_id UUID v7 of the property or request
     * @param string $direction 'local_to_live' | 'live_to_local'
     * @param string $action 'created' | 'updated' | 'skipped' | 'failed' | 'deleted'
     * @param array  $display_data Metadata for UI (TDN, owner, location, PIN, client_name, purpose, etc.)
     */
    public function record_item($record_type, $record_id, $direction, $action, $display_data = array()) {
        // Track running counters
        if (isset($this->counters[$record_type . 's'])) {
            $grp = &$this->counters[$record_type . 's'];
            if (isset($grp[$action])) {
                $grp[$action]++;
            }
            $grp['total']++;
        }

        // Normalize property display data
        if ($record_type === 'property' && is_array($display_data)) {
            $last   = isset($display_data['declarant_last_name']) ? trim((string)$display_data['declarant_last_name']) : '';
            $first  = isset($display_data['declarant_first_name']) ? trim((string)$display_data['declarant_first_name']) : '';
            $middle = isset($display_data['declarant_middle_initial']) ? trim((string)$display_data['declarant_middle_initial']) : '';

            // Clean middle: remove dots
            $mi = str_replace('.', '', $middle);
            $middle_formatted = $mi !== '' ? (strlen($mi) === 1 ? " {$mi}." : " {$mi}") : '';

            // Generate clean canonical owner_name without dangling commas
            if ($last !== '' && $first !== '') {
                $clean_owner = "{$last}, {$first}{$middle_formatted}";
            } elseif ($last !== '') {
                $clean_owner = $last;
            } elseif ($first !== '') {
                $clean_owner = trim("{$first}{$middle_formatted}");
            } else {
                $clean_owner = isset($display_data['owner_name']) ? trim((string)$display_data['owner_name']) : '';
                // Strip trailing/leading/double commas from legacy owner_name
                $clean_owner = trim(preg_replace('/^,\s*|\s*,\s*$/', '', preg_replace('/\s*,\s*/', ', ', $clean_owner)));
                if ($clean_owner === ',') {
                    $clean_owner = '';
                }
            }

            $display_data['owner_name'] = $clean_owner;

            // Ensure discrete fields are preserved if provided
            if (!isset($display_data['declarant_last_name'])) $display_data['declarant_last_name'] = $last;
            if (!isset($display_data['declarant_first_name'])) $display_data['declarant_first_name'] = $first;
            if (!isset($display_data['declarant_middle_initial'])) $display_data['declarant_middle_initial'] = $middle;

            // Normalize business_name
            if (isset($display_data['business_name'])) {
                $display_data['business_name'] = trim(preg_replace('/,\s*/', ' ', (string)$display_data['business_name']));
            } else {
                $display_data['business_name'] = '';
            }

            // Ensure assessed_value is present
            if (!array_key_exists('assessed_value', $display_data)) {
                $display_data['assessed_value'] = null;
            }
        }

        $item_id = class_exists('Assessor_UUID') ? Assessor_UUID::v7() : wp_generate_uuid4();
        $this->item_buffer[] = array(
            'id'                => $item_id,
            'run_id'            => $this->run_id,
            'record_type'       => $record_type,
            'record_id'         => (string) $record_id,
            'direction'         => $direction,
            'action'            => $action,
            'display_data_json' => wp_json_encode($display_data),
            'created_at'        => Assessor_Timezone::now_mysql(),
        );

        if (count($this->item_buffer) >= $this->batch_size) {
            $this->flush_items();
        }
    }

    /**
     * Flush buffered record items to assessor_sync_run_items table in a single bulk INSERT query.
     */
    public function flush_items() {
        if (empty($this->item_buffer)) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'assessor_sync_run_items';

        $values = array();
        $placeholders = array();

        foreach ($this->item_buffer as $item) {
            $placeholders[] = "(%s, %s, %s, %s, %s, %s, %s, %s)";
            $values[] = $item['id'];
            $values[] = $item['run_id'];
            $values[] = $item['record_type'];
            $values[] = $item['record_id'];
            $values[] = $item['direction'];
            $values[] = $item['action'];
            $values[] = $item['display_data_json'];
            $values[] = $item['created_at'];
        }

        $sql = "INSERT INTO $table (id, run_id, record_type, record_id, direction, action, display_data_json, created_at) VALUES "
             . implode(', ', $placeholders);

        $wpdb->query($wpdb->prepare($sql, $values));
        $this->item_buffer = array();
    }

    /**
     * Finalize the sync run report, update summary and status.
     *
     * @param string $overall_status 'completed' | 'partial' | 'failed'
     * @param string $message User-friendly status message
     * @param array  $extra_summary
     */
    public function finish_run($overall_status = 'completed', $message = 'Sync completed.', $extra_summary = array()) {
        $this->flush_items();

        global $wpdb;
        $table_runs = $wpdb->prefix . 'assessor_sync_runs';

        $summary = array_merge(array(
            'message'    => $message,
            'status'     => $overall_status,
            'phases'     => $this->phases,

            // Canonical counter structure used by the Sync Center UI.
            'counters'   => $this->counters,

            // Keep these legacy/top-level fields for backward compatibility.
            'properties' => $this->counters['properties'],
            'requests'   => $this->counters['requests'],
        ), $extra_summary);

        $wpdb->update(
            $table_runs,
            array(
                'status'       => $overall_status,
                'completed_at' => Assessor_Timezone::now_mysql(),
                'summary_json' => wp_json_encode($summary),
            ),
            array('id' => $this->run_id),
            array('%s', '%s', '%s'),
            array('%s')
        );

        return $summary;
    }

    // -------------------------------------------------------------------------
    // Query APIs for REST Endpoints
    // -------------------------------------------------------------------------

    /**
     * Get the latest sync run summary.
     * First checks assessor_sync_meta for 'last_sync_run_id' pointer.
     * Falls back to ORDER BY started_at DESC if pointer is missing.
     */
    public static function get_latest_run() {
        global $wpdb;
        $table = $wpdb->prefix . 'assessor_sync_runs';
        $table_meta = $wpdb->prefix . 'assessor_sync_meta';

        $row = null;

        // 1. Try global pointer from assessor_sync_meta
        $meta_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_meta));
        if ($meta_exists) {
            $last_run_id = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM $table_meta WHERE meta_key = 'last_sync_run_id' LIMIT 1"
            ));
            if (!empty($last_run_id)) {
                $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %s LIMIT 1", $last_run_id), ARRAY_A);
            }
        }

        // 2. Fallback to latest run by started_at if pointer was missing or row not found
        if (!$row) {
            $row = $wpdb->get_row("SELECT * FROM $table ORDER BY started_at DESC LIMIT 1", ARRAY_A);
        }

        if (!$row) {
            return null;
        }

        return self::format_run_row($row);
    }

    /**
     * Get a specific sync run by run_id.
     */
    public static function get_run($run_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'assessor_sync_runs';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %s LIMIT 1", $run_id), ARRAY_A);

        if (!$row) {
            return null;
        }

        return self::format_run_row($row);
    }

    /**
     * Format a run database row for JSON response.
     */
    private static function format_run_row($row) {
        $summary = !empty($row['summary_json']) ? json_decode($row['summary_json'], true) : array();
        return array(
            'id'           => $row['id'],
            'mode'         => $row['mode'],
            'status'       => $row['status'],
            'started_at'   => $row['started_at'],
            'completed_at' => $row['completed_at'],
            'created_at'   => $row['created_at'],
            'summary'      => $summary,
        );
    }

    /**
     * Get paginated record-level items for a specific run.
     *
     * @param string $run_id
     * @param array $args { record_type, action, direction, search, page, per_page }
     * @return array { items, total, page, per_page, total_pages }
     */
    public static function get_run_items($run_id, $args = array()) {
        global $wpdb;
        $table = $wpdb->prefix . 'assessor_sync_run_items';

        $record_type = isset($args['record_type']) ? sanitize_text_field($args['record_type']) : 'all';
        $action      = isset($args['action']) ? sanitize_text_field($args['action']) : 'all';
        $direction   = isset($args['direction']) ? sanitize_text_field($args['direction']) : 'all';
        $search      = isset($args['search']) ? trim(sanitize_text_field($args['search'])) : '';
        $page        = max(1, isset($args['page']) ? intval($args['page']) : 1);
        $per_page    = min(100, max(1, isset($args['per_page']) ? intval($args['per_page']) : 25));
        $offset      = ($page - 1) * $per_page;

        $where_clauses = array("run_id = %s");
        $params        = array($run_id);

        if ($record_type !== 'all' && in_array($record_type, array('property', 'request'))) {
            $where_clauses[] = "record_type = %s";
            $params[]        = $record_type;
        }

        if ($action !== 'all' && in_array($action, array('created', 'updated', 'skipped', 'failed', 'deleted'))) {
            $where_clauses[] = "action = %s";
            $params[]        = $action;
        }

        if ($direction !== 'all' && in_array($direction, array('local_to_live', 'live_to_local'))) {
            $where_clauses[] = "direction = %s";
            $params[]        = $direction;
        }

        if (!empty($search)) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where_clauses[] = "(record_id LIKE %s OR display_data_json LIKE %s)";
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = implode(' AND ', $where_clauses);

        // Count query
        $count_sql = "SELECT COUNT(*) FROM $table WHERE $where_sql";
        $total = (int) $wpdb->get_var($wpdb->prepare($count_sql, $params));

        // Data query
        $data_sql = "SELECT id, run_id, record_type, record_id, direction, action, display_data_json, created_at "
                  . "FROM $table WHERE $where_sql ORDER BY id ASC LIMIT %d OFFSET %d";
        $data_params = array_merge($params, array($per_page, $offset));
        $rows = $wpdb->get_results($wpdb->prepare($data_sql, $data_params), ARRAY_A);

        $items = array();
        if ($rows) {
            foreach ($rows as $row) {
                $display = !empty($row['display_data_json']) ? json_decode($row['display_data_json'], true) : array();
                $items[] = array(
                    'id'           => $row['id'],
                    'run_id'       => $row['run_id'],
                    'record_type'  => $row['record_type'],
                    'record_id'    => $row['record_id'],
                    'direction'    => $row['direction'],
                    'action'       => $row['action'],
                    'display_data' => $display,
                    'created_at'   => $row['created_at'],
                );
            }
        }

        $total_pages = $total > 0 ? (int) ceil($total / $per_page) : 1;

        return array(
            'items'       => $items,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => $total_pages,
        );
    }

    /**
     * Retrieve recent synchronization runs history.
     *
     * @param array $params Filtering and pagination params: page, per_page, status, mode
     * @return array
     */
    public static function get_history($params = array()) {
        global $wpdb;
        $table = $wpdb->prefix . 'assessor_sync_runs';

        $page = isset($params['page']) ? max(1, (int) $params['page']) : 1;
        $per_page = isset($params['per_page']) ? max(1, min(100, (int) $params['per_page'])) : 10;
        $offset = ($page - 1) * $per_page;

        $where = array('1=1');
        $query_params = array();

        if (!empty($params['status'])) {
            $where[] = 'status = %s';
            $query_params[] = sanitize_text_field($params['status']);
        }

        if (!empty($params['mode'])) {
            $where[] = 'mode = %s';
            $query_params[] = sanitize_text_field($params['mode']);
        }

        $where_sql = implode(' AND ', $where);

        $count_sql = "SELECT COUNT(*) FROM $table WHERE $where_sql";
        $total = (int) $wpdb->get_var(!empty($query_params) ? $wpdb->prepare($count_sql, $query_params) : $count_sql);

        $data_sql = "SELECT * FROM $table WHERE $where_sql ORDER BY started_at DESC LIMIT %d OFFSET %d";
        $fetch_params = array_merge($query_params, array($per_page, $offset));
        $rows = $wpdb->get_results($wpdb->prepare($data_sql, $fetch_params), ARRAY_A);

        $runs = array();
        if ($rows) {
            foreach ($rows as $row) {
                $runs[] = self::format_run_row($row);
            }
        }

        $total_pages = $total > 0 ? (int) ceil($total / $per_page) : 1;

        return array(
            'runs'        => $runs,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => $total_pages,
        );
    }

    /**
     * Cleanup old sync reports older than X days.
     * Preserves last_sync_run_id and last_published_sync_run_id pointers.
     */
    public static function cleanup_runs($days = 30) {
        global $wpdb;
        $table_runs  = $wpdb->prefix . 'assessor_sync_runs';
        $table_items = $wpdb->prefix . 'assessor_sync_run_items';
        $table_meta  = $wpdb->prefix . 'assessor_sync_meta';

        $cutoff = date('Y-m-d H:i:s', strtotime("-$days days"));

        // Protected run IDs (last active pointer and last published pointer)
        $protected_ids = array();
        $meta_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_meta));
        if ($meta_exists) {
            $pointers = $wpdb->get_col("SELECT meta_value FROM $table_meta WHERE meta_key IN ('last_sync_run_id', 'last_published_sync_run_id')");
            if (!empty($pointers)) {
                $protected_ids = array_filter(array_unique($pointers));
            }
        }

        // Find run IDs to delete
        $run_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM $table_runs WHERE started_at < %s", $cutoff));

        if (!empty($protected_ids) && !empty($run_ids)) {
            $run_ids = array_diff($run_ids, $protected_ids);
        }

        if (!empty($run_ids)) {
            $placeholders = implode(',', array_fill(0, count($run_ids), '%s'));
            $wpdb->query($wpdb->prepare("DELETE FROM $table_items WHERE run_id IN ($placeholders)", $run_ids));
            $wpdb->query($wpdb->prepare("DELETE FROM $table_runs WHERE id IN ($placeholders)", $run_ids));
        }

        return count($run_ids);
    }
}
