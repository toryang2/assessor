<?php
/**
 * Assessor Lookup UUID + Sync Compatibility Layer
 *
 * Converts the four small assessor lookup/configuration tables to UUID v7 IDs
 * without changing their business keys, and keeps their existing local -> live
 * snapshot sync behavior while preserving the UUID supplied by the local site.
 */
class Assessor_Lookup_UUID_Sync {

    /**
     * Lookup/config tables that now use UUID v7 primary keys.
     * Value = unique business key already used by the existing config sync.
     */
    private static $tables = array(
        'assessor_request_purposes' => 'purpose',
        'assessor_property_types'   => 'code',
        'assessor_locations'        => 'code',
        'assessor_general_classes' => 'code',
    );

    /** Mapping table used to keep legacy numeric IDs resolvable after migration. */
    const MAP_TABLE_SUFFIX = 'assessor_lookup_id_uuid_map';

    /** Register schema and REST overrides before the API registers its routes. */
    public static function register() {
        add_filter('dbdelta_create_queries', array(__CLASS__, 'filter_dbdelta_create_queries'));
        add_action('rest_api_init', array(__CLASS__, 'register_rest_overrides'), 99);
    }

    /**
     * Keep dbDelta from trying to revert UUID IDs back to mediumint on future
     * database version upgrades. New tables are initially allowed to use the
     * existing numeric schema and are converted immediately after create_tables().
     */
    public static function filter_dbdelta_create_queries($queries) {
        global $wpdb;

        if (!is_array($queries)) {
            return $queries;
        }

        foreach ($queries as &$query) {
            if (!is_string($query)) {
                continue;
            }

            foreach (self::$tables as $suffix => $unique_col) {
                $table = $wpdb->prefix . $suffix;
                $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
                if ($exists !== $table) {
                    continue;
                }

                if (stripos($query, 'CREATE TABLE ' . $table) === false) {
                    continue;
                }

                $query = preg_replace(
                    '/\\bid\\s+mediumint\\(9\\)\\s+NOT\\s+NULL\\s+AUTO_INCREMENT\\b/i',
                    'id varchar(36) NOT NULL',
                    $query,
                    1
                );
            }
        }
        unset($query);

        return $queries;
    }

    /**
     * Migrate all four lookup IDs to UUID v7. Safe to call after create_tables().
     * Returns a structured result rather than throwing through the WordPress load path.
     */
    public static function maybe_migrate() {
        $result = array(
            'success' => true,
            'tables'  => array(),
            'errors'  => array(),
        );

        try {
            self::ensure_map_table();

            foreach (self::$tables as $suffix => $unique_col) {
                $result['tables'][$suffix] = self::migrate_table($suffix, $unique_col);
            }
        } catch (Exception $e) {
            $result['success'] = false;
            $result['errors'][] = $e->getMessage();
        }

        return $result;
    }

    /** Ensure the legacy-ID mapping table exists. */
    private static function ensure_map_table() {
        global $wpdb;

        $table = $wpdb->prefix . self::MAP_TABLE_SUFFIX;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table (
            table_suffix varchar(64) NOT NULL,
            old_id varchar(50) NOT NULL,
            new_uuid varchar(36) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (table_suffix, old_id),
            UNIQUE KEY lookup_uuid (table_suffix, new_uuid)
        ) $charset_collate;";

        if ($wpdb->query($sql) === false) {
            throw new Exception('Failed to create lookup UUID mapping table: ' . $wpdb->last_error);
        }
    }

    /** Migrate one lookup table and validate the final schema/data. */
    private static function migrate_table($suffix, $unique_col) {
        global $wpdb;

        $table = $wpdb->prefix . $suffix;
        $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

        if ($table_exists !== $table) {
            return array(
                'status' => 'missing',
                'rows'   => 0,
                'changed'=> 0,
            );
        }

        $id_col = $wpdb->get_row("SHOW COLUMNS FROM $table LIKE 'id'");
        if (!$id_col) {
            throw new Exception("Table $table does not have an id column.");
        }

        $rows = $wpdb->get_results("SELECT id, $unique_col FROM $table ORDER BY id ASC", ARRAY_A);
        if ($rows === null) {
            throw new Exception("Failed reading table $table: " . $wpdb->last_error);
        }

        $needs_conversion = stripos($id_col->Type, 'varchar(36)') === false;
        if (!$needs_conversion) {
            foreach ($rows as $row) {
                if (!self::is_valid_uuid($row['id'])) {
                    $needs_conversion = true;
                    break;
                }
            }
        }

        if (!$needs_conversion) {
            self::validate_table($table);
            return array(
                'status' => 'already_uuid',
                'rows'   => count($rows),
                'changed'=> 0,
            );
        }

        self::assert_no_incoming_foreign_keys($table);

        $backup_table = $table . '_bak_' . gmdate('YmdHis');
        if (strlen($backup_table) > 64) {
            $backup_table = substr($backup_table, 0, 64);
        }

        $created_backup = $wpdb->query("CREATE TABLE $backup_table LIKE $table");
        if ($created_backup === false) {
            throw new Exception("Failed to create backup table $backup_table: " . $wpdb->last_error);
        }

        $copied = $wpdb->query("INSERT INTO $backup_table SELECT * FROM $table");
        if ($copied === false) {
            throw new Exception("Failed to populate backup table $backup_table: " . $wpdb->last_error);
        }

        $backup_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $backup_table");
        if ($backup_count !== count($rows)) {
            throw new Exception("Backup verification failed for $table: expected " . count($rows) . ", got $backup_count.");
        }

        $temp_col = 'lookup_uuid_tmp';
        $temp_exists = $wpdb->get_row("SHOW COLUMNS FROM $table LIKE '$temp_col'");
        if ($temp_exists) {
            $wpdb->query("ALTER TABLE $table DROP COLUMN $temp_col");
        }

        if ($wpdb->query("ALTER TABLE $table ADD COLUMN $temp_col VARCHAR(36) NULL AFTER id") === false) {
            throw new Exception("Failed to add temporary UUID column to $table: " . $wpdb->last_error);
        }

        $changed = 0;
        $seen_new_ids = array();

        foreach ($rows as $row) {
            $old_id = (string) $row['id'];
            $new_uuid = self::is_valid_uuid($old_id) ? $old_id : self::generate_uuid();

            if (isset($seen_new_ids[$new_uuid])) {
                throw new Exception("Generated duplicate UUID '$new_uuid' while migrating $table.");
            }
            $seen_new_ids[$new_uuid] = true;

            $updated = $wpdb->update(
                $table,
                array($temp_col => $new_uuid),
                array('id' => $old_id),
                array('%s'),
                array('%s')
            );

            if ($updated === false) {
                throw new Exception("Failed assigning UUID for legacy id '$old_id' in $table: " . $wpdb->last_error);
            }

            if (!self::is_valid_uuid($old_id)) {
                $map = $wpdb->replace(
                    $wpdb->prefix . self::MAP_TABLE_SUFFIX,
                    array(
                        'table_suffix' => $suffix,
                        'old_id'       => $old_id,
                        'new_uuid'     => $new_uuid,
                        'created_at'   => current_time('mysql'),
                    ),
                    array('%s', '%s', '%s', '%s')
                );
                if ($map === false) {
                    throw new Exception("Failed storing legacy ID mapping for $table id '$old_id': " . $wpdb->last_error);
                }
                $changed++;
            }
        }

        $initial_count = count($rows);

        if ($wpdb->query("ALTER TABLE $table DROP PRIMARY KEY") === false) {
            throw new Exception("Failed to drop primary key from $table: " . $wpdb->last_error);
        }

        if ($wpdb->query("ALTER TABLE $table DROP COLUMN id") === false) {
            throw new Exception("Failed to drop old id column from $table: " . $wpdb->last_error);
        }

        if ($wpdb->query("ALTER TABLE $table CHANGE COLUMN $temp_col id VARCHAR(36) NOT NULL") === false) {
            throw new Exception("Failed to promote UUID column on $table: " . $wpdb->last_error);
        }

        if ($wpdb->query("ALTER TABLE $table ADD PRIMARY KEY (id)") === false) {
            throw new Exception("Failed to restore primary key on $table: " . $wpdb->last_error);
        }

        self::validate_table($table, $initial_count);

        return array(
            'status'       => 'migrated',
            'rows'         => $initial_count,
            'changed'      => $changed,
            'backup_table' => $backup_table,
            'id_type'      => 'varchar(36)',
        );
    }

    /** Validate UUID type, row count and UUID integrity. */
    private static function validate_table($table, $expected_count = null) {
        global $wpdb;

        $id_col = $wpdb->get_row("SHOW COLUMNS FROM $table LIKE 'id'");
        if (!$id_col || stripos($id_col->Type, 'varchar(36)') === false) {
            throw new Exception("Validation failed for $table: id is not VARCHAR(36).");
        }

        $rows = $wpdb->get_results("SELECT id FROM $table", ARRAY_A);
        $actual_count = count($rows);

        if ($expected_count !== null && $actual_count !== (int) $expected_count) {
            throw new Exception("Validation failed for $table: row count changed from $expected_count to $actual_count.");
        }

        $seen = array();
        foreach ($rows as $row) {
            $id = (string) $row['id'];
            if (!self::is_valid_uuid($id)) {
                throw new Exception("Validation failed for $table: invalid UUID '$id'.");
            }
            if (isset($seen[$id])) {
                throw new Exception("Validation failed for $table: duplicate UUID '$id'.");
            }
            $seen[$id] = true;
        }
    }

    /** Abort rather than silently breaking an FK that references a lookup ID. */
    private static function assert_no_incoming_foreign_keys($table) {
        global $wpdb;

        $references = $wpdb->get_results($wpdb->prepare(
            "SELECT TABLE_NAME, CONSTRAINT_NAME
             FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
             WHERE REFERENCED_TABLE_SCHEMA = DATABASE()
               AND REFERENCED_TABLE_NAME = %s
               AND REFERENCED_COLUMN_NAME = 'id'",
            $table
        ), ARRAY_A);

        if (!empty($references)) {
            $details = array();
            foreach ($references as $reference) {
                $details[] = $reference['TABLE_NAME'] . '.' . $reference['CONSTRAINT_NAME'];
            }
            throw new Exception(
                "Lookup UUID migration blocked for $table because foreign-key references exist: " .
                implode(', ', $details)
            );
        }
    }

    private static function generate_uuid() {
        if (class_exists('Assessor_UUID')) {
            return Assessor_UUID::v7();
        }
        return wp_generate_uuid4();
    }

    private static function is_valid_uuid($id) {
        return class_exists('Assessor_UUID')
            ? Assessor_UUID::is_valid((string) $id)
            : (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', (string) $id);
    }

    /** Resolve UUID or legacy numeric ID for CRUD requests. */
    private static function resolve_id($suffix, $identifier) {
        global $wpdb;

        $identifier = trim((string) $identifier);
        if ($identifier === '') {
            return null;
        }

        if (self::is_valid_uuid($identifier)) {
            return $identifier;
        }

        if (is_numeric($identifier)) {
            $map_table = $wpdb->prefix . self::MAP_TABLE_SUFFIX;
            $mapped = $wpdb->get_var($wpdb->prepare(
                "SELECT new_uuid FROM $map_table WHERE table_suffix = %s AND old_id = %s LIMIT 1",
                $suffix,
                $identifier
            ));
            if (!empty($mapped) && self::is_valid_uuid($mapped)) {
                return $mapped;
            }
        }

        return null;
    }

    // ---------------------------------------------------------------------
    // REST overrides — UUID-safe CRUD + existing sync enqueue behavior
    // ---------------------------------------------------------------------

    public static function register_rest_overrides() {
        foreach (self::$tables as $suffix => $unique_col) {
            $slug = str_replace('assessor_', '', $suffix);
            $handler = $suffix === 'assessor_request_purposes'
                ? array(__CLASS__, 'request_purposes_handler')
                : array(__CLASS__, 'lookup_handler');

            register_rest_route('assessor/v1', '/settings/' . $slug, array(
                array(
                    'methods'             => 'GET',
                    'callback'            => $handler,
                    'permission_callback' => '__return_true',
                    'args'                => array('_lookup_table' => array('default' => $suffix)),
                ),
                array(
                    'methods'             => 'POST',
                    'callback'            => $handler,
                    'permission_callback' => array(__CLASS__, 'check_auth'),
                    'args'                => array('_lookup_table' => array('default' => $suffix)),
                ),
            ), true);

            $delete_handler = $suffix === 'assessor_request_purposes'
                ? array(__CLASS__, 'request_purposes_delete_handler')
                : array(__CLASS__, 'lookup_delete_handler');

            register_rest_route('assessor/v1', '/settings/' . $slug . '/delete', array(
                'methods'             => 'POST',
                'callback'            => $delete_handler,
                'permission_callback' => array(__CLASS__, 'check_auth'),
                'args'                => array('_lookup_table' => array('default' => $suffix)),
            ), true);
        }

        // Replace the config-push receiver so incoming UUID primary keys are preserved.
        register_rest_route('assessor/v1', '/sync/push-config', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'receive_config_push'),
            'permission_callback' => array(__CLASS__, 'verify_sync_token'),
        ), true);
    }

    public static function check_auth($request) {
        $auth = new Assessor_Auth();
        return $auth->verify_token($request);
    }

    public static function verify_sync_token($request) {
        if (!defined('ASSESSOR_SYNC_TOKEN') || empty(ASSESSOR_SYNC_TOKEN)) {
            return new WP_Error(
                'sync_not_configured',
                'Sync token is not configured on this server.',
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

    public static function lookup_handler($request) {
        $suffix = sanitize_key($request->get_param('_lookup_table'));
        if (!isset(self::$tables[$suffix]) || $suffix === 'assessor_request_purposes') {
            return new WP_Error('invalid_lookup_table', 'Invalid lookup table.', array('status' => 400));
        }

        global $wpdb;
        $table = $wpdb->prefix . $suffix;

        if ($request->get_method() === 'GET') {
            if ($suffix === 'assessor_locations') {
                $rows = $wpdb->get_results(
                    "SELECT id, code, name, pin, status, sort_order FROM $table
                     WHERE status IN ('active','disabled')
                     ORDER BY sort_order ASC, name ASC",
                    ARRAY_A
                );
            } else {
                $rows = $wpdb->get_results(
                    "SELECT id, code, name, status, sort_order FROM $table
                     WHERE status IN ('active','disabled')
                     ORDER BY sort_order ASC, name ASC",
                    ARRAY_A
                );
            }
            return array('items' => $rows ? $rows : array());
        }

        $params = $request->get_json_params();
        if (!$params) {
            $params = $request->get_params();
        }

        $code = isset($params['code']) ? sanitize_text_field($params['code']) : '';
        $name = isset($params['name']) ? sanitize_text_field($params['name']) : '';
        $sort_order = isset($params['sort_order']) ? intval($params['sort_order']) : 0;
        $status = isset($params['status']) ? sanitize_text_field($params['status']) : 'active';

        if ($code === '' || $name === '') {
            return new WP_Error('invalid_input', 'Code and name are required', array('status' => 400));
        }
        if (!in_array($status, array('active', 'disabled'), true)) {
            $status = 'active';
        }

        $requested_id = isset($params['id']) ? trim((string) $params['id']) : '';
        $resolved_id = '';
        if ($requested_id !== '') {
            $resolved_id = self::resolve_id($suffix, $requested_id);
            if ($resolved_id === null) {
                return new WP_Error('invalid_id', 'Invalid UUID id.', array('status' => 400));
            }
        }

        $data = array(
            'code'       => $code,
            'name'       => $name,
            'sort_order' => $sort_order,
            'status'     => $status,
            'updated_at' => current_time('mysql'),
        );

        if ($suffix === 'assessor_locations') {
            $data['pin'] = isset($params['pin']) ? sanitize_text_field($params['pin']) : '';
        }

        if ($resolved_id !== '') {
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE id = %s LIMIT 1",
                $resolved_id
            ));
            if (!$existing) {
                return new WP_Error('not_found', 'Lookup record not found.', array('status' => 404));
            }

            $updated = $wpdb->update(
                $table,
                $data,
                array('id' => $resolved_id)
            );
            if ($updated === false) {
                return new WP_Error('database_error', $wpdb->last_error, array('status' => 500));
            }
        } else {
            $data['id'] = self::generate_uuid();
            $data['created_at'] = current_time('mysql');

            $inserted = $wpdb->insert($table, $data);
            if ($inserted === false) {
                return new WP_Error('database_error', $wpdb->last_error, array('status' => 500));
            }
        }

        self::enqueue_config_sync($suffix);
        return self::get_lookup_rows($suffix);
    }

    public static function lookup_delete_handler($request) {
        $suffix = sanitize_key($request->get_param('_lookup_table'));
        if (!isset(self::$tables[$suffix]) || $suffix === 'assessor_request_purposes') {
            return new WP_Error('invalid_lookup_table', 'Invalid lookup table.', array('status' => 400));
        }

        $params = $request->get_json_params();
        if (!$params) {
            $params = $request->get_params();
        }

        $requested_id = isset($params['id']) ? trim((string) $params['id']) : '';
        $resolved_id = self::resolve_id($suffix, $requested_id);
        if ($resolved_id === null) {
            return new WP_Error('invalid_id', 'Invalid UUID id.', array('status' => 400));
        }

        global $wpdb;
        $table = $wpdb->prefix . $suffix;
        $deleted = $wpdb->delete($table, array('id' => $resolved_id), array('%s'));
        if ($deleted === false) {
            return new WP_Error('database_error', $wpdb->last_error, array('status' => 500));
        }
        if ((int) $deleted === 0) {
            return new WP_Error('not_found', 'Lookup record not found.', array('status' => 404));
        }

        self::enqueue_config_sync($suffix);
        return array('success' => true);
    }

    public static function request_purposes_handler($request) {
        if ($request->get_method() === 'GET') {
            return self::get_lookup_rows('assessor_request_purposes');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'assessor_request_purposes';

        $params = $request->get_json_params();
        if (!$params) {
            $params = $request->get_params();
        }

        $purpose = isset($params['purpose']) ? sanitize_text_field($params['purpose']) : '';
        $purpose = trim(preg_replace('/\s+/', ' ', $purpose));
        $purpose = str_replace(' ', '_', $purpose);
        $amount = isset($params['amount']) ? $params['amount'] : 0;
        $status = isset($params['status']) ? sanitize_text_field($params['status']) : 'active';
        $sort_order = isset($params['sort_order']) ? intval($params['sort_order']) : 0;

        if ($purpose === '') {
            return new WP_Error('invalid_input', 'Purpose is required', array('status' => 400));
        }
        if (!is_numeric($amount)) {
            return new WP_Error('invalid_input', 'Amount must be numeric', array('status' => 400));
        }
        $amount = round((float) $amount, 2);
        if ($amount < 0) {
            return new WP_Error('invalid_input', 'Amount cannot be negative', array('status' => 400));
        }
        if (!in_array($status, array('active', 'disabled'), true)) {
            $status = 'active';
        }

        $requested_id = isset($params['id']) ? trim((string) $params['id']) : '';
        $resolved_id = '';
        if ($requested_id !== '') {
            $resolved_id = self::resolve_id('assessor_request_purposes', $requested_id);
            if ($resolved_id === null) {
                return new WP_Error('invalid_id', 'Invalid UUID id.', array('status' => 400));
            }
        }

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $table WHERE LOWER(purpose) = LOWER(%s) LIMIT 1",
            $purpose
        ), ARRAY_A);
        if ($existing && $existing['id'] !== $resolved_id) {
            return new WP_Error('duplicate_purpose', 'Purpose already exists', array('status' => 400, 'code' => 'duplicate_purpose'));
        }

        $data = array(
            'purpose'    => $purpose,
            'amount'     => $amount,
            'status'     => $status,
            'sort_order' => $sort_order,
            'updated_at' => current_time('mysql'),
        );

        if ($resolved_id !== '') {
            $updated = $wpdb->update($table, $data, array('id' => $resolved_id));
            if ($updated === false) {
                return new WP_Error('database_error', $wpdb->last_error, array('status' => 500));
            }
        } else {
            $data['id'] = self::generate_uuid();
            $data['created_at'] = current_time('mysql');
            $inserted = $wpdb->insert($table, $data);
            if ($inserted === false) {
                return new WP_Error('database_error', $wpdb->last_error, array('status' => 500));
            }
        }

        self::enqueue_config_sync('assessor_request_purposes');
        return self::get_lookup_rows('assessor_request_purposes');
    }

    public static function request_purposes_delete_handler($request) {
        $params = $request->get_json_params();
        if (!$params) {
            $params = $request->get_params();
        }

        $requested_id = isset($params['id']) ? trim((string) $params['id']) : '';
        $resolved_id = self::resolve_id('assessor_request_purposes', $requested_id);
        if ($resolved_id === null) {
            return new WP_Error('invalid_id', 'Invalid UUID id.', array('status' => 400));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'assessor_request_purposes';
        $deleted = $wpdb->delete($table, array('id' => $resolved_id), array('%s'));
        if ($deleted === false) {
            return new WP_Error('database_error', $wpdb->last_error, array('status' => 500));
        }
        if ((int) $deleted === 0) {
            return new WP_Error('not_found', 'Request purpose not found.', array('status' => 404));
        }

        self::enqueue_config_sync('assessor_request_purposes');
        return array('success' => true);
    }

    private static function get_lookup_rows($suffix) {
        global $wpdb;
        $table = $wpdb->prefix . $suffix;

        if ($suffix === 'assessor_request_purposes') {
            $rows = $wpdb->get_results(
                "SELECT id, purpose, amount, status, sort_order
                 FROM $table
                 WHERE status IN ('active','disabled')
                 ORDER BY sort_order ASC, purpose ASC",
                ARRAY_A
            );
        } elseif ($suffix === 'assessor_locations') {
            $rows = $wpdb->get_results(
                "SELECT id, code, name, pin, status, sort_order
                 FROM $table
                 WHERE status IN ('active','disabled')
                 ORDER BY sort_order ASC, name ASC",
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                "SELECT id, code, name, status, sort_order
                 FROM $table
                 WHERE status IN ('active','disabled')
                 ORDER BY sort_order ASC, name ASC",
                ARRAY_A
            );
        }

        return array('items' => $rows ? $rows : array());
    }

    private static function enqueue_config_sync($suffix) {
        if (class_exists('Assessor_Sync')) {
            Assessor_Sync::enqueue_config_table($suffix);
        }
    }

    /**
     * Stable config snapshot receiver: preserve UUID IDs for the four migrated
     * lookup tables while retaining the existing local-authoritative upsert behavior.
     */
    public static function receive_config_push($request) {
        $params = $request->get_json_params();

        $table_suffix = isset($params['table']) ? sanitize_key($params['table']) : '';
        $rows = isset($params['rows']) ? $params['rows'] : array();

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
        $is_uuid_lookup = isset(self::$tables[$table_suffix]);
        $unique_col = $is_uuid_lookup ? self::$tables[$table_suffix] : null;

        $inserted = 0;
        $updated = 0;
        $errors = array();

        foreach ($rows as $row) {
            if (!is_array($row) || empty($row)) {
                continue;
            }

            $clean = array_filter($row, 'is_scalar');
            if (empty($clean)) {
                continue;
            }

            if ($is_uuid_lookup) {
                $incoming_id = isset($clean['id']) ? trim((string) $clean['id']) : '';

                if ($incoming_id !== '' && !self::is_valid_uuid($incoming_id)) {
                    // Legacy local payload: resolve by business key to an existing live UUID.
                    if ($unique_col !== null && isset($clean[$unique_col])) {
                        $existing_id = $wpdb->get_var($wpdb->prepare(
                            "SELECT id FROM $table WHERE LOWER($unique_col) = LOWER(%s) LIMIT 1",
                            (string) $clean[$unique_col]
                        ));
                        if ($existing_id && self::is_valid_uuid($existing_id)) {
                            $incoming_id = $existing_id;
                        } else {
                            $incoming_id = self::generate_uuid();
                        }
                    } else {
                        $incoming_id = self::generate_uuid();
                    }
                }

                if ($incoming_id === '') {
                    if ($unique_col !== null && isset($clean[$unique_col])) {
                        $existing_id = $wpdb->get_var($wpdb->prepare(
                            "SELECT id FROM $table WHERE LOWER($unique_col) = LOWER(%s) LIMIT 1",
                            (string) $clean[$unique_col]
                        ));
                        if ($existing_id && self::is_valid_uuid($existing_id)) {
                            $incoming_id = $existing_id;
                        }
                    }
                    if ($incoming_id === '') {
                        $incoming_id = self::generate_uuid();
                    }
                }

                $clean['id'] = $incoming_id;
            } else {
                // Keep legacy revision-entry config behavior unchanged.
                if (isset($clean['id'])) {
                    unset($clean['id']);
                }
            }

            $before_id = isset($clean['id']) ? (string) $clean['id'] : '';
            $existing_before = $before_id !== ''
                ? $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE id = %s LIMIT 1", $before_id))
                : null;

            $result = $wpdb->replace($table, $clean);
            if ($result === false) {
                $errors[] = $table_suffix . ': ' . $wpdb->last_error;
                continue;
            }

            if ($existing_before) {
                $updated++;
            } else {
                $inserted++;
            }
        }

        return array(
            'table'     => $table_suffix,
            'inserted'  => $inserted,
            'updated'   => $updated,
            'errors'    => $errors,
            'success'   => empty($errors),
            'server_ts' => current_time('mysql'),
        );
    }
}

Assessor_Lookup_UUID_Sync::register();
