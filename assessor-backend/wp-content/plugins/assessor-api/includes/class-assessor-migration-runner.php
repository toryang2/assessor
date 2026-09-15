<?php
/**
 * Assessor Migration Runner
 *
 * Production-safe migration coordinator:
 * - Read-only preflight checks (inspects DB connection, revision schema, row counts, migration status)
 * - Single migration explicit execution (prevents rerunning completed migrations, maintains execution status)
 * - Status reporting across all registered migrations
 *
 * Does NOT run automatically on WordPress load or plugin activation.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Assessor_Migration_Runner {

    /**
     * Migration tracking table name without prefix
     */
    const TABLE_MIGRATIONS = 'assessor_migrations';

    /**
     * Get registered migrations list with metadata.
     *
     * @return array
     */
    public function get_registered_migrations() {
        return array(
            '001_revision_uuid_migration' => array(
                'name'        => '001_revision_uuid_migration',
                'title'       => 'Migrate Assessor Revision Entries Primary Key to UUID v7',
                'description' => 'Migrates assessor_revision_entries table id column to UUID v7 (RFC 9562) and adds unique revision_code.',
                'handler'     => array($this, 'run_revision_uuid_migration'),
            ),
            '002_live_revision_code' => array(
                'name'        => '002_live_revision_code',
                'title'       => 'Validate and Ensure Live Revision Codes',
                'description' => 'Ensures all revision entries have unique, valid, and deterministic revision_code identifiers.',
                'handler'     => array($this, 'run_live_revision_code_migration'),
            ),
            '003_property_revision_link' => array(
                'name'        => '003_property_revision_link',
                'title'       => 'Apply Live Property to Revision UUID Link',
                'description' => 'Applies and validates assessor_properties.revision_id link, indexing, foreign key constraint, and referential integrity without orphan references.',
                'handler'     => array($this, 'run_property_revision_link_migration'),
            ),
            '004_property_revision_backfill' => array(
                'name'        => '004_property_revision_backfill',
                'title'       => 'Live Property Revision UUID Backfill',
                'description' => 'Deterministically populates assessor_properties.revision_id based on property effectivity_date and active revision year intervals.',
                'handler'     => array($this, 'run_property_revision_backfill_migration'),
            ),
        );
    }

    /**
     * Get full table name for migrations.
     *
     * @return string
     */
    private function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_MIGRATIONS;
    }

    /**
     * Ensure the assessor_migrations table exists.
     * Called on-demand when migration endpoints are accessed.
     */
    public function ensure_migrations_table() {
        global $wpdb;
        $table_name = $this->get_table_name();

        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name));
        if ($table_exists === $table_name) {
            return;
        }

        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            migration_name varchar(100) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            started_at datetime DEFAULT NULL,
            completed_at datetime DEFAULT NULL,
            error text DEFAULT NULL,
            batch int NOT NULL DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY migration_name (migration_name),
            KEY status (status)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * 1. READ-ONLY PREFLIGHT
     *
     * Inspects:
     * - Live database connection & version
     * - assessor_revision_entries existence, columns, and indexes
     * - assessor_properties existence & row count
     * - assessor_revision_entries row count
     * - existing migration status from assessor_migrations table
     * - helper/map tables if present
     *
     * Performs ZERO data mutations and ZERO schema alterations.
     *
     * @param WP_REST_Request|null $request
     * @return WP_REST_Response
     */
    public function get_preflight($request = null) {
        global $wpdb;

        $db_connected = false;
        $db_version   = null;
        $ping_error   = null;

        try {
            $db_version = $wpdb->db_version();
            $db_connected = !empty($db_version);
        } catch (Exception $e) {
            $ping_error = $e->getMessage();
        }

        $table_revisions  = $wpdb->prefix . 'assessor_revision_entries';
        $table_properties = $wpdb->prefix . 'assessor_properties';
        $table_map        = $wpdb->prefix . 'assessor_revision_id_uuid_map';
        $table_migrations = $this->get_table_name();

        // Check assessor_revision_entries existence and schema
        $rev_table_exists = ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_revisions)) === $table_revisions);
        $rev_columns      = array();
        $rev_indexes      = array();
        $rev_row_count    = 0;
        $id_column_type   = null;
        $has_revision_code = false;

        if ($rev_table_exists) {
            $raw_cols = $wpdb->get_results("DESCRIBE $table_revisions", ARRAY_A);
            if (!empty($raw_cols)) {
                foreach ($raw_cols as $c) {
                    $rev_columns[$c['Field']] = array(
                        'field'   => $c['Field'],
                        'type'    => $c['Type'],
                        'null'    => $c['Null'],
                        'key'     => $c['Key'],
                        'default' => $c['Default'],
                        'extra'   => $c['Extra'],
                    );
                    if ($c['Field'] === 'id') {
                        $id_column_type = $c['Type'];
                    }
                    if ($c['Field'] === 'revision_code') {
                        $has_revision_code = true;
                    }
                }
            }

            $raw_indexes = $wpdb->get_results("SHOW INDEX FROM $table_revisions", ARRAY_A);
            if (!empty($raw_indexes)) {
                foreach ($raw_indexes as $idx) {
                    $rev_indexes[] = array(
                        'key_name'    => $idx['Key_name'],
                        'column_name' => $idx['Column_name'],
                        'non_unique'  => $idx['Non_unique'],
                    );
                }
            }

            $rev_row_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_revisions");
        }

        // Properties table inspection
        $prop_table_exists = ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_properties)) === $table_properties);
        $prop_row_count    = $prop_table_exists ? (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_properties") : 0;
        $prop_revision_id_col = null;
        if ($prop_table_exists) {
            $col_info = $wpdb->get_row($wpdb->prepare("SHOW COLUMNS FROM $table_properties LIKE 'revision_id'"), ARRAY_A);
            if ($col_info) {
                $prop_revision_id_col = $col_info['Type'];
            }
        }

        // Check map table
        $map_table_exists = ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_map)) === $table_map);
        $map_row_count    = $map_table_exists ? (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_map") : 0;

        // Migration table & status inspection
        $migrations_table_exists = ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_migrations)) === $table_migrations);
        $migration_statuses      = array();
        if ($migrations_table_exists) {
            $records = $wpdb->get_results("SELECT * FROM $table_migrations ORDER BY id ASC", ARRAY_A);
            if (!empty($records)) {
                foreach ($records as $r) {
                    $migration_statuses[$r['migration_name']] = array(
                        'migration_name' => $r['migration_name'],
                        'status'         => $r['status'],
                        'started_at'     => $r['started_at'],
                        'completed_at'   => $r['completed_at'],
                        'error'          => $r['error'],
                        'batch'          => (int) $r['batch'],
                    );
                }
            }
        }

        // Evaluate revision schema state
        $is_uuid_pk = false;
        if ($id_column_type && stripos($id_column_type, 'varchar(36)') !== false) {
            $is_uuid_pk = true;
        }

        $readiness = array(
            'db_connected'          => $db_connected,
            'revision_table_exists' => $rev_table_exists,
            'revision_row_count'    => $rev_row_count,
            'properties_row_count'  => $prop_row_count,
            'revision_id_schema'    => $id_column_type,
            'is_uuid_pk'            => $is_uuid_pk,
            'has_revision_code'     => $has_revision_code,
            'map_table_exists'      => $map_table_exists,
            'map_row_count'         => $map_row_count,
            'migrations_table'      => $migrations_table_exists,
        );

        return new WP_REST_Response(array(
            'success'   => true,
            'timestamp' => current_time('mysql'),
            'database'  => array(
                'connected' => $db_connected,
                'version'   => $db_version,
                'error'     => $ping_error,
                'prefix'    => $wpdb->prefix,
            ),
            'tables'    => array(
                'revisions' => array(
                    'name'         => $table_revisions,
                    'exists'       => $rev_table_exists,
                    'row_count'    => $rev_row_count,
                    'id_type'      => $id_column_type,
                    'is_uuid_v7'   => $is_uuid_pk,
                    'columns'      => $rev_columns,
                    'indexes'      => $rev_indexes,
                ),
                'properties' => array(
                    'name'            => $table_properties,
                    'exists'          => $prop_table_exists,
                    'row_count'       => $prop_row_count,
                    'revision_id_col' => $prop_revision_id_col,
                ),
                'map' => array(
                    'name'      => $table_map,
                    'exists'    => $map_table_exists,
                    'row_count' => $map_row_count,
                ),
            ),
            'migration_state' => $migration_statuses,
            'readiness'       => $readiness,
        ), 200);
    }

    /**
     * 3. STATUS
     *
     * Returns:
     * - migration name
     * - status (pending, running, completed, failed)
     * - started_at
     * - completed_at
     * - error
     *
     * @param WP_REST_Request|null $request
     * @return WP_REST_Response
     */
    public function get_status($request = null) {
        global $wpdb;

        $this->ensure_migrations_table();
        $table_migrations = $this->get_table_name();

        $registered = $this->get_registered_migrations();
        $records = $wpdb->get_results("SELECT * FROM $table_migrations ORDER BY id ASC", ARRAY_A);

        $db_records_by_name = array();
        if (!empty($records)) {
            foreach ($records as $r) {
                $db_records_by_name[$r['migration_name']] = $r;
            }
        }

        $status_list = array();
        foreach ($registered as $name => $meta) {
            $db_rec = isset($db_records_by_name[$name]) ? $db_records_by_name[$name] : null;

            $status_list[] = array(
                'migration_name' => $name,
                'title'          => $meta['title'],
                'description'    => $meta['description'],
                'status'         => $db_rec ? $db_rec['status'] : 'pending',
                'started_at'     => $db_rec ? $db_rec['started_at'] : null,
                'completed_at'   => $db_rec ? $db_rec['completed_at'] : null,
                'error'          => $db_rec ? $db_rec['error'] : null,
                'batch'          => $db_rec ? (int) $db_rec['batch'] : null,
            );
        }

        return new WP_REST_Response(array(
            'success'    => true,
            'timestamp'  => current_time('mysql'),
            'migrations' => $status_list,
        ), 200);
    }

    /**
     * 2. EXECUTE ONE MIGRATION
     *
     * Rules:
     * - Authenticated admin/manager only (enforced via REST permission callback)
     * - Migration must be explicitly triggered with `migration_name`
     * - Prevent rerunning a completed migration
     * - Manage running lock & error capture
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function execute_migration($request) {
        global $wpdb;

        $this->ensure_migrations_table();
        $table_migrations = $this->get_table_name();

        $params = $request->get_json_params();
        if (empty($params)) {
            $params = $request->get_params();
        }

        $migration_name = isset($params['migration_name']) ? sanitize_text_field($params['migration_name']) : '';
        if (empty($migration_name)) {
            return new WP_Error('missing_migration_name', 'Parameter migration_name is required.', array('status' => 400));
        }

        $registered = $this->get_registered_migrations();
        if (!isset($registered[$migration_name])) {
            return new WP_Error(
                'unknown_migration',
                sprintf('Migration "%s" is not registered in the runner.', esc_html($migration_name)),
                array('status' => 404)
            );
        }

        // Check if already completed or running
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_migrations WHERE migration_name = %s",
            $migration_name
        ), ARRAY_A);

        if ($existing) {
            if ($existing['status'] === 'completed') {
                return new WP_Error(
                    'migration_already_completed',
                    sprintf('Migration "%s" has already been completed at %s.', esc_html($migration_name), $existing['completed_at']),
                    array('status' => 409, 'existing' => $existing)
                );
            }

            if ($existing['status'] === 'running') {
                return new WP_Error(
                    'migration_already_running',
                    sprintf('Migration "%s" is currently in progress (started at %s).', esc_html($migration_name), $existing['started_at']),
                    array('status' => 423, 'existing' => $existing)
                );
            }
        }

        // Acquire lock and record started_at
        $started_at = current_time('mysql');
        if ($existing) {
            $wpdb->update(
                $table_migrations,
                array(
                    'status'       => 'running',
                    'started_at'   => $started_at,
                    'completed_at' => null,
                    'error'        => null,
                ),
                array('migration_name' => $migration_name),
                array('%s', '%s', '%s', '%s'),
                array('%s')
            );
        } else {
            $wpdb->insert(
                $table_migrations,
                array(
                    'migration_name' => $migration_name,
                    'status'         => 'running',
                    'started_at'     => $started_at,
                    'completed_at'   => null,
                    'error'          => null,
                    'batch'          => 1,
                    'created_at'     => $started_at,
                ),
                array('%s', '%s', '%s', '%s', '%s', '%d', '%s')
            );
        }

        // Execute migration handler
        $handler = $registered[$migration_name]['handler'];

        try {
            if (!is_callable($handler)) {
                throw new Exception("Handler for migration '$migration_name' is not callable.");
            }

            $result = call_user_func($handler, $params);

            // Mark completed
            $completed_at = current_time('mysql');
            $wpdb->update(
                $table_migrations,
                array(
                    'status'       => 'completed',
                    'completed_at' => $completed_at,
                    'error'        => null,
                ),
                array('migration_name' => $migration_name),
                array('%s', '%s', '%s'),
                array('%s')
            );

            return new WP_REST_Response(array(
                'success'        => true,
                'migration_name' => $migration_name,
                'status'         => 'completed',
                'started_at'     => $started_at,
                'completed_at'   => $completed_at,
                'details'        => $result,
            ), 200);

        } catch (Exception $e) {
            $error_message = $e->getMessage();
            $wpdb->update(
                $table_migrations,
                array(
                    'status' => 'failed',
                    'error'  => $error_message,
                ),
                array('migration_name' => $migration_name),
                array('%s', '%s'),
                array('%s')
            );

            return new WP_Error(
                'migration_execution_failed',
                sprintf('Migration "%s" failed: %s', esc_html($migration_name), $error_message),
                array('status' => 500, 'error' => $error_message)
            );
        }
    }

    /**
     * Helper function to derive clean revision_code
     * e.g. "CA 470" -> "CA-470", "RA 7160 ART. 310" -> "RA-7160-ART-310"
     *
     * @param string $rev_year
     * @param string $from_year
     * @return string
     */
     private function derive_revision_code($rev_year, $from_year) {
        $clean = preg_replace('/[^a-zA-Z0-9]+/', '-', trim($rev_year));
        $clean = trim($clean, '-');
        if (empty($clean)) {
            $clean = 'REV-' . trim($from_year);
        }
        return strtoupper($clean);
    }

    /**
     * PROD-02: Live Migration Handler for 001_revision_uuid_migration
     *
     * Rules:
     * - One UUID per existing revision (RFC 9562 UUID v7 via Assessor_UUID::v7())
     * - Never regenerate an existing UUID (preserves mapped UUIDs)
     * - Restorable backup table created prior to modification
     * - Preserve revision row count
     * - Preserve revision_year, from_year, to_year, status, sort_order, timestamps
     * - Validate row count unchanged, UUIDs unique and valid v7, non-null
     * - Do NOT modify assessor_properties, TDN behavior, or ETRACS business data
     *
     * @param array $params
     * @return array
     * @throws Exception
     */
    public function run_revision_uuid_migration($params = array()) {
        global $wpdb;

        $table_revisions = $wpdb->prefix . 'assessor_revision_entries';
        $table_map       = $wpdb->prefix . 'assessor_revision_id_uuid_map';

        // 1. Verify table exists
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_revisions));
        if ($table_exists !== $table_revisions) {
            throw new Exception("Target table $table_revisions does not exist.");
        }

        // 2. Inspect initial rows & schema
        $initial_rows = $wpdb->get_results("SELECT * FROM $table_revisions ORDER BY id ASC", ARRAY_A);
        $initial_count = count($initial_rows);
        if ($initial_count === 0) {
            throw new Exception("Table $table_revisions has 0 rows. Aborting migration.");
        }

        $id_col = $wpdb->get_row("SHOW COLUMNS FROM $table_revisions LIKE 'id'");
        if (!$id_col) {
            throw new Exception("Table $table_revisions has no 'id' column.");
        }
        $is_already_varchar = (stripos($id_col->Type, 'varchar') !== false || stripos($id_col->Type, 'char') !== false);

        // 3. Create database backup table
        $backup_table = $wpdb->prefix . 'assessor_revision_entries_backup_' . date('Ymd_His');
        $create_backup = $wpdb->query("CREATE TABLE $backup_table LIKE $table_revisions");
        if ($create_backup === false) {
            throw new Exception("Failed to create backup table $backup_table: " . $wpdb->last_error);
        }
        $populate_backup = $wpdb->query("INSERT INTO $backup_table SELECT * FROM $table_revisions");
        if ($populate_backup === false) {
            throw new Exception("Failed to populate backup table $backup_table: " . $wpdb->last_error);
        }
        $backup_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $backup_table");
        if ($backup_count !== $initial_count) {
            throw new Exception("Backup table verification failed: expected $initial_count rows, found $backup_count.");
        }

        // 4. Ensure persistent ID -> UUID mapping table exists
        $charset_collate = $wpdb->get_charset_collate();
        $wpdb->query("
            CREATE TABLE IF NOT EXISTS $table_map (
                old_id INT NOT NULL,
                new_uuid VARCHAR(36) NOT NULL,
                revision_code VARCHAR(100) NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (old_id),
                UNIQUE KEY (new_uuid),
                UNIQUE KEY (revision_code)
            ) $charset_collate;
        ");

        // 5. Populate / reconcile mapping table (NEVER regenerate existing UUID)
        $mapped_records = 0;
        foreach ($initial_rows as $idx => $row) {
            $curr_id = $row['id'];
            $is_uuid = Assessor_UUID::is_valid($curr_id);

            // Check if mapped by UUID or numeric old_id
            if ($is_uuid) {
                $map_entry = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_map WHERE new_uuid = %s", $curr_id), ARRAY_A);
            } else {
                $map_entry = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_map WHERE old_id = %d", intval($curr_id)), ARRAY_A);
            }

            if ($map_entry) {
                $mapped_records++;
                continue;
            }

            // If not yet mapped: generate new UUID v7 or reuse existing valid UUID
            $assigned_uuid = $is_uuid ? $curr_id : Assessor_UUID::v7();
            $base_code = $this->derive_revision_code($row['revision_year'], $row['from_year']);
            $code = $base_code;

            // Ensure revision_code uniqueness
            $counter = 1;
            while ($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_map WHERE revision_code = %s", $code)) > 0) {
                $code = $base_code . '-' . $counter;
                $counter++;
            }

            $int_old_id = $is_uuid ? ($idx + 1) : intval($curr_id);
            $inserted = $wpdb->insert(
                $table_map,
                array(
                    'old_id'        => $int_old_id,
                    'new_uuid'      => $assigned_uuid,
                    'revision_code' => $code,
                    'created_at'    => current_time('mysql'),
                ),
                array('%d', '%s', '%s', '%s')
            );
            if ($inserted === false) {
                throw new Exception("Failed to insert mapping for revision '{$row['revision_year']}': " . $wpdb->last_error);
            }
            $mapped_records++;
        }

        // 6. Ensure revision_code column exists on assessor_revision_entries
        $rev_code_col = $wpdb->get_row("SHOW COLUMNS FROM $table_revisions LIKE 'revision_code'");
        if (!$rev_code_col) {
            $alt = $wpdb->query("ALTER TABLE $table_revisions ADD COLUMN revision_code VARCHAR(100) NOT NULL DEFAULT '' AFTER id");
            if ($alt === false) {
                throw new Exception("Failed to add revision_code column: " . $wpdb->last_error);
            }
        }

        // 7. Schema migration to UUID v7 primary key
        if (!$is_already_varchar) {
            // Remove AUTO_INCREMENT
            $res = $wpdb->query("ALTER TABLE $table_revisions MODIFY id mediumint NOT NULL");
            if ($res === false) {
                throw new Exception("Failed to remove AUTO_INCREMENT from id: " . $wpdb->last_error);
            }

            // Add temp_uuid column
            $has_temp = $wpdb->get_row("SHOW COLUMNS FROM $table_revisions LIKE 'temp_uuid'");
            if (!$has_temp) {
                $wpdb->query("ALTER TABLE $table_revisions ADD COLUMN temp_uuid VARCHAR(36) NULL AFTER id");
            }

            // Populate temp_uuid and revision_code from map
            $update_res = $wpdb->query("
                UPDATE $table_revisions r
                JOIN $table_map m ON r.id = m.old_id
                SET r.temp_uuid = m.new_uuid,
                    r.revision_code = m.revision_code
            ");
            if ($update_res === false) {
                throw new Exception("Failed to populate temp_uuid and revision_code from map: " . $wpdb->last_error);
            }

            // Drop old primary key, drop old id, promote temp_uuid to id
            $wpdb->query("ALTER TABLE $table_revisions DROP PRIMARY KEY");
            $wpdb->query("ALTER TABLE $table_revisions DROP COLUMN id");
            $wpdb->query("ALTER TABLE $table_revisions CHANGE COLUMN temp_uuid id VARCHAR(36) NOT NULL");
            $wpdb->query("ALTER TABLE $table_revisions ADD PRIMARY KEY (id)");

        } else {
            // id column is already VARCHAR(36). Verify and sync codes & UUIDs from map
            $wpdb->query("
                UPDATE $table_revisions r
                JOIN $table_map m ON r.id = m.new_uuid
                SET r.revision_code = m.revision_code
                WHERE r.revision_code IS NULL OR r.revision_code = ''
            ");
        }

        // 8. Ensure UNIQUE KEY on revision_code
        $indexes = $wpdb->get_results("SHOW INDEX FROM $table_revisions WHERE Key_name = 'revision_code'", ARRAY_A);
        if (empty($indexes)) {
            $wpdb->query("ALTER TABLE $table_revisions ADD UNIQUE KEY revision_code (revision_code)");
        }

        // 9. Exhaustive Post-Migration Validation
        $final_rows = $wpdb->get_results("SELECT * FROM $table_revisions ORDER BY sort_order ASC, from_year DESC", ARRAY_A);
        $final_count = count($final_rows);

        if ($final_count !== $initial_count) {
            throw new Exception("Integrity mismatch: final row count ($final_count) does not equal initial row count ($initial_count).");
        }

        $uuid_seen = array();
        $code_seen = array();
        $invalid_uuids = array();

        foreach ($final_rows as $r) {
            $id = $r['id'];
            $code = $r['revision_code'];

            if (!Assessor_UUID::is_valid($id)) {
                $invalid_uuids[] = $id;
            }

            if (isset($uuid_seen[$id])) {
                throw new Exception("Duplicate UUID detected in migrated revisions: $id");
            }
            $uuid_seen[$id] = true;

            if (empty($code)) {
                throw new Exception("Empty revision_code found for revision ID: $id");
            }
            if (isset($code_seen[$code])) {
                throw new Exception("Duplicate revision_code detected: $code");
            }
            $code_seen[$code] = true;
        }

        if (!empty($invalid_uuids)) {
            throw new Exception("Invalid UUID v7 identifiers detected: " . implode(', ', $invalid_uuids));
        }

        return array(
            'backup_table'     => $backup_table,
            'mapping_table'    => $table_map,
            'initial_count'    => $initial_count,
            'final_count'      => $final_count,
            'validated_uuids'  => count($uuid_seen),
            'validated_codes'  => count($code_seen),
            'is_uuid_v7'       => true,
            'schema_validated' => true,
        );
    }

    /**
     * PROD-03: Validate and Ensure Live Revision Codes
     *
     * Rules:
     * - Preserve an existing valid revision_code (never overwrite existing valid codes)
     * - Never create random codes
     * - Do not overwrite existing user-facing meaning
     * - If missing, derive deterministically from revision_year / from_year
     * - Detect collisions before writing; stop immediately on ambiguity
     * - Do not modify assessor_properties, TDNs, ETRACS, or effectivity dates
     * - Validate required revision codes present, no unexpected collisions, row count unchanged
     *
     * @param array $params
     * @return array
     * @throws Exception
     */
    public function run_live_revision_code_migration($params = array()) {
        global $wpdb;

        $table_revisions = $wpdb->prefix . 'assessor_revision_entries';

        // 1. Verify table exists
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_revisions));
        if ($table_exists !== $table_revisions) {
            throw new Exception("Table $table_revisions does not exist.");
        }

        // 2. Ensure revision_code column exists
        $col_info = $wpdb->get_row("SHOW COLUMNS FROM $table_revisions LIKE 'revision_code'");
        if (!$col_info) {
            $added = $wpdb->query("ALTER TABLE $table_revisions ADD COLUMN revision_code VARCHAR(100) NOT NULL DEFAULT '' AFTER id");
            if ($added === false) {
                throw new Exception("Failed to add revision_code column: " . $wpdb->last_error);
            }
        }

        // 3. Ensure UNIQUE index on revision_code exists
        $indexes = $wpdb->get_results("SHOW INDEX FROM $table_revisions WHERE Key_name = 'revision_code'", ARRAY_A);
        if (empty($indexes)) {
            $wpdb->query("ALTER TABLE $table_revisions ADD UNIQUE KEY revision_code (revision_code)");
        }

        // 4. Fetch all live revisions
        $revisions = $wpdb->get_results("SELECT * FROM $table_revisions ORDER BY sort_order ASC, from_year ASC", ARRAY_A);
        $total_rows = count($revisions);
        if ($total_rows === 0) {
            throw new Exception("Table $table_revisions has 0 rows.");
        }

        $existing_codes = array();
        $missing_code_rows = array();

        foreach ($revisions as $rev) {
            $id = $rev['id'];
            $code = isset($rev['revision_code']) ? trim($rev['revision_code']) : '';

            if ($code !== '') {
                if (isset($existing_codes[$code])) {
                    throw new Exception("Collision detected in existing revision_code: '$code' belongs to multiple rows!");
                }
                $existing_codes[$code] = $id;
            } else {
                $missing_code_rows[] = $rev;
            }
        }

        // 5. Deterministically populate missing codes (if any)
        $populated_codes = array();
        foreach ($missing_code_rows as $missing_rev) {
            $rev_year = $missing_rev['revision_year'];
            $from_year = $missing_rev['from_year'];
            $derived_code = $this->derive_revision_code($rev_year, $from_year);

            if (empty($derived_code)) {
                throw new Exception("Failed to deterministically derive revision_code for revision ID '{$missing_rev['id']}' (year: '$rev_year').");
            }

            // Check collision against other existing or populated codes
            if (isset($existing_codes[$derived_code]) || isset($populated_codes[$derived_code])) {
                throw new Exception("Collision detected for derived code '$derived_code' (revision ID '{$missing_rev['id']}'). Stopping on ambiguity.");
            }

            $updated = $wpdb->update(
                $table_revisions,
                array('revision_code' => $derived_code),
                array('id' => $missing_rev['id']),
                array('%s'),
                array('%s')
            );
            if ($updated === false) {
                throw new Exception("Failed to write derived revision_code '$derived_code': " . $wpdb->last_error);
            }

            $populated_codes[$derived_code] = $missing_rev['id'];
            $existing_codes[$derived_code] = $missing_rev['id'];
        }

        // 6. Comprehensive Validation
        $final_revisions = $wpdb->get_results("SELECT id, revision_year, from_year, to_year, revision_code, status FROM $table_revisions ORDER BY from_year ASC", ARRAY_A);
        $final_count = count($final_revisions);

        if ($final_count !== $total_rows) {
            throw new Exception("Revision count mismatch after revision_code validation: expected $total_rows, got $final_count.");
        }

        $all_codes = array();
        $code_details = array();
        foreach ($final_revisions as $r) {
            $code = $r['revision_code'];
            if (empty($code)) {
                throw new Exception("Found empty revision_code for revision ID '{$r['id']}'.");
            }
            if (isset($all_codes[$code])) {
                throw new Exception("Duplicate revision_code detected: '$code'.");
            }
            $all_codes[$code] = true;
            $code_details[] = array(
                'id'            => $r['id'],
                'revision_year' => $r['revision_year'],
                'revision_code' => $r['revision_code'],
                'from_year'     => $r['from_year'],
                'to_year'       => $r['to_year'],
                'status'        => $r['status'],
            );
        }

        return array(
            'total_revisions'    => $final_count,
            'existing_valid'     => count($existing_codes) - count($populated_codes),
            'derived_populated'  => count($populated_codes),
            'unique_codes_count' => count($all_codes),
            'revisions'          => $code_details,
        );
    }

    /**
     * PROD-04: Apply Live Property -> Revision UUID Link
     *
     * Rules:
     * - Uses canonical field name `revision_id` (do NOT create duplicate fields)
     * - UUID reference to `assessor_revision_entries.id`
     * - Indexed (single key & compound idx_tdn_revision)
     * - Safe for existing data
     * - `effectivity_date` unchanged
     * - Property UUID unchanged
     * - TDN uniqueness unchanged
     * - Foreign key constraint `fk_properties_revision_id` created/validated only after all values resolve
     * - Validate property count unchanged, all populated references resolve, no orphan references
     *
     * @param array $params
     * @return array
     * @throws Exception
     */
    public function run_property_revision_link_migration($params = array()) {
        global $wpdb;

        $table_properties = $wpdb->prefix . 'assessor_properties';
        $table_revisions  = $wpdb->prefix . 'assessor_revision_entries';

        // 1. Verify tables exist
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_properties)) !== $table_properties) {
            throw new Exception("Table $table_properties does not exist.");
        }
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_revisions)) !== $table_revisions) {
            throw new Exception("Table $table_revisions does not exist.");
        }

        // 2. Verify parent revision table primary key is VARCHAR(36) UUID v7
        $rev_id_col = $wpdb->get_row("SHOW COLUMNS FROM $table_revisions LIKE 'id'");
        if (!$rev_id_col || stripos($rev_id_col->Type, 'varchar') === false) {
            throw new Exception("Parent table $table_revisions.id is not VARCHAR(36) UUID v7. Run PROD-02 first.");
        }

        // 3. Inspect initial property state
        $initial_prop_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_properties");

        // 4. Ensure canonical column `revision_id` exists and is VARCHAR(36) NULL
        $col = $wpdb->get_row("SHOW COLUMNS FROM $table_properties LIKE 'revision_id'");
        if (!$col) {
            $add_col = $wpdb->query("ALTER TABLE $table_properties ADD COLUMN revision_id VARCHAR(36) DEFAULT NULL AFTER status");
            if ($add_col === false) {
                throw new Exception("Failed to add column revision_id: " . $wpdb->last_error);
            }
        } else {
            if (stripos($col->Type, 'varchar(36)') === false) {
                $mod_col = $wpdb->query("ALTER TABLE $table_properties MODIFY revision_id VARCHAR(36) DEFAULT NULL");
                if ($mod_col === false) {
                    throw new Exception("Failed to modify revision_id to VARCHAR(36): " . $wpdb->last_error);
                }
            }
        }

        // 5. Ensure indexes exist on revision_id
        $single_idx = $wpdb->get_results("SHOW INDEX FROM $table_properties WHERE Key_name = 'revision_id'", ARRAY_A);
        if (empty($single_idx)) {
            $wpdb->query("ALTER TABLE $table_properties ADD KEY revision_id (revision_id)");
        }

        $compound_idx = $wpdb->get_results("SHOW INDEX FROM $table_properties WHERE Key_name = 'idx_tdn_revision'", ARRAY_A);
        if (empty($compound_idx)) {
            $wpdb->query("ALTER TABLE $table_properties ADD KEY idx_tdn_revision (tax_declaration_number, revision_id)");
        }

        // 6. Null empty strings to ensure valid foreign key semantics
        $wpdb->query("UPDATE $table_properties SET revision_id = NULL WHERE revision_id = ''");

        // 7. Check for orphan references
        $orphan_count = (int) $wpdb->get_var("
            SELECT COUNT(*)
            FROM $table_properties p
            LEFT JOIN $table_revisions r ON p.revision_id = r.id
            WHERE p.revision_id IS NOT NULL AND r.id IS NULL
        ");

        if ($orphan_count > 0) {
            throw new Exception("Detected $orphan_count orphan revision_id references in $table_properties. Stopping for safety.");
        }

        // 8. Create or validate foreign key constraint
        $db_name = DB_NAME;
        $fk_exists = $wpdb->get_var($wpdb->prepare("
            SELECT CONSTRAINT_NAME
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = %s
              AND TABLE_NAME = %s
              AND COLUMN_NAME = 'revision_id'
              AND REFERENCED_TABLE_NAME = %s
              AND REFERENCED_COLUMN_NAME = 'id'
        ", $db_name, $table_properties, $table_revisions));

        if (!$fk_exists) {
            $add_fk = $wpdb->query("
                ALTER TABLE $table_properties
                ADD CONSTRAINT fk_properties_revision_id
                FOREIGN KEY (revision_id) REFERENCES $table_revisions (id)
                ON DELETE SET NULL
                ON UPDATE CASCADE
            ");
            if ($add_fk === false) {
                // If database engine/privileges disallow FK, verify indexed logical relationship
                error_log("Assessor Migration Runner: Notice - FK creation returned: " . $wpdb->last_error . ". Falling back to indexed logical constraint.");
            } else {
                $fk_exists = 'fk_properties_revision_id';
            }
        }

        // 9. Post-Migration Comprehensive Validation
        $final_prop_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_properties");
        if ($final_prop_count !== $initial_prop_count) {
            throw new Exception("Property count changed! Expected $initial_prop_count, got $final_prop_count.");
        }

        $populated_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_properties WHERE revision_id IS NOT NULL");
        $null_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_properties WHERE revision_id IS NULL");

        $final_orphan_check = (int) $wpdb->get_var("
            SELECT COUNT(*)
            FROM $table_properties p
            LEFT JOIN $table_revisions r ON p.revision_id = r.id
            WHERE p.revision_id IS NOT NULL AND r.id IS NULL
        ");

        if ($final_orphan_check !== 0) {
            throw new Exception("Post-migration integrity failure: $final_orphan_check orphan references detected!");
        }

        return array(
            'canonical_column'   => 'revision_id',
            'column_type'        => 'VARCHAR(36) DEFAULT NULL',
            'property_count'     => $final_prop_count,
            'populated_count'    => $populated_count,
            'null_count'         => $null_count,
            'orphan_count'       => $final_orphan_check,
            'foreign_key'        => $fk_exists ? $fk_exists : 'indexed_logical',
            'all_references_ok'  => true,
        );
    }

    /**
     * PROD-05: Live Property Revision UUID Backfill
     *
     * Rule:
     * from_year <= property_year <= to_year (with to_year = 'present' open-ended up to 9999).
     *
     * Pre-checks & HARD STOP rules:
     * - Count malformed effectivity dates
     * - Count out-of-range dates
     * - Count multiple matching active revisions (HARD STOP)
     * - Detect overlapping active revision ranges (HARD STOP)
     * - Never guess or silently fabricate revisions for unresolved properties
     *
     * Post-validation:
     * - Every eligible property has one revision UUID
     * - Every revision UUID resolves (0 orphans)
     * - Property count unchanged
     * - Property UUIDs and effectivity_date unchanged
     * - Other property data and TDN duplicate handling unchanged
     *
     * @param array $params
     * @return array
     * @throws Exception
     */
    public function run_property_revision_backfill_migration($params = array()) {
        global $wpdb;

        $table_properties = $wpdb->prefix . 'assessor_properties';
        $table_revisions  = $wpdb->prefix . 'assessor_revision_entries';

        // 1. Verify tables and columns
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_properties)) !== $table_properties) {
            throw new Exception("Table $table_properties does not exist.");
        }
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_revisions)) !== $table_revisions) {
            throw new Exception("Table $table_revisions does not exist.");
        }

        $rev_col = $wpdb->get_row("SHOW COLUMNS FROM $table_properties LIKE 'revision_id'");
        if (!$rev_col || stripos($rev_col->Type, 'varchar(36)') === false) {
            throw new Exception("Column $table_properties.revision_id is missing or not VARCHAR(36).");
        }

        // 2. Fetch and inspect active revisions
        $revisions = $wpdb->get_results("SELECT id, revision_code, revision_year, from_year, to_year, status FROM $table_revisions WHERE status = 'active' ORDER BY CAST(from_year AS UNSIGNED) ASC", ARRAY_A);
        if (empty($revisions)) {
            throw new Exception("No active revisions found in $table_revisions.");
        }

        $intervals = array();
        foreach ($revisions as $r) {
            $from = intval($r['from_year']);
            $to = (strtolower(trim($r['to_year'])) === 'present') ? 9999 : intval($r['to_year']);

            if ($from <= 0 || $to <= 0 || $from > $to) {
                throw new Exception("Invalid year boundaries on revision '{$r['revision_code']}': from={$r['from_year']}, to={$r['to_year']}.");
            }

            $intervals[] = array(
                'id'   => $r['id'],
                'code' => $r['revision_code'],
                'year' => $r['revision_year'],
                'from' => $from,
                'to'   => $to
            );
        }

        // 3. HARD STOP check: Overlapping active revisions
        for ($i = 0; $i < count($intervals); $i++) {
            for ($j = $i + 1; $j < count($intervals); $j++) {
                $a = $intervals[$i];
                $b = $intervals[$j];
                if (max($a['from'], $b['from']) <= min($a['to'], $b['to'])) {
                    throw new Exception("HARD STOP: Overlap detected between revision '{$a['code']}' ({$a['from']}-{$a['to']}) and '{$b['code']}' ({$b['from']}-{$b['to']})!");
                }
            }
        }

        // 4. Pre-analysis of property effectivity dates
        $initial_prop_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_properties");
        $initial_effectivity_checksum = $wpdb->get_var("SELECT MD5(GROUP_CONCAT(CONCAT(id, ':', effectivity_date) ORDER BY id)) FROM $table_properties");

        $props = $wpdb->get_results("SELECT id, tax_declaration_number, effectivity_date, revision_id FROM $table_properties", ARRAY_A);

        $cleanly_mappable = 0;
        $blank_dates = 0;
        $malformed_dates = 0;
        $out_of_range_dates = 0;
        $multiple_matches = 0;

        foreach ($props as $p) {
            $raw = trim($p['effectivity_date'] ?? '');
            if ($raw === '') {
                $blank_dates++;
                continue;
            }

            if (preg_match('/^(\d{4})$/', $raw, $m)) {
                $year = intval($m[1]);
                $matched_revs = 0;
                foreach ($intervals as $inv) {
                    if ($year >= $inv['from'] && $year <= $inv['to']) {
                        $matched_revs++;
                    }
                }

                if ($matched_revs === 1) {
                    $cleanly_mappable++;
                } elseif ($matched_revs > 1) {
                    $multiple_matches++;
                } else {
                    $out_of_range_dates++;
                }
            } else {
                $malformed_dates++;
            }
        }

        // HARD STOP check: Multiple matching active revisions for any property
        if ($multiple_matches > 0) {
            throw new Exception("HARD STOP: $multiple_matches properties matched multiple active revisions. Aborting backfill.");
        }

        // 5. Execute backfill in deterministic batches per revision interval
        $assigned_per_revision = array();
        foreach ($intervals as $inv) {
            $rev_uuid = $inv['id'];
            $from     = $inv['from'];
            $to       = $inv['to'];

            $query = $wpdb->prepare("
                UPDATE $table_properties
                SET revision_id = %s
                WHERE effectivity_date REGEXP '^[0-9]{4}$'
                  AND CAST(effectivity_date AS UNSIGNED) >= %d
                  AND CAST(effectivity_date AS UNSIGNED) <= %d
            ", $rev_uuid, $from, $to);

            $updated = $wpdb->query($query);
            if ($updated === false) {
                throw new Exception("Update failed for revision '{$inv['code']}': " . $wpdb->last_error);
            }

            $current_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_properties WHERE revision_id = %s",
                $rev_uuid
            ));
            $assigned_per_revision[$inv['code']] = $current_count;
        }

        // Explicitly ensure properties that are blank, malformed, or out of range remain NULL
        $wpdb->query("
            UPDATE $table_properties
            SET revision_id = NULL
            WHERE effectivity_date IS NULL
               OR effectivity_date = ''
               OR effectivity_date NOT REGEXP '^[0-9]{4}$'
               OR CAST(effectivity_date AS UNSIGNED) < 1965
        ");

        // 6. Post-backfill comprehensive validation
        $final_prop_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_properties");
        if ($final_prop_count !== $initial_prop_count) {
            throw new Exception("Property count changed! Initial: $initial_prop_count, Final: $final_prop_count.");
        }

        $final_effectivity_checksum = $wpdb->get_var("SELECT MD5(GROUP_CONCAT(CONCAT(id, ':', effectivity_date) ORDER BY id)) FROM $table_properties");
        if ($final_effectivity_checksum !== $initial_effectivity_checksum) {
            throw new Exception("effectivity_date values were modified during backfill!");
        }

        $total_populated = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_properties WHERE revision_id IS NOT NULL");
        $total_null = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_properties WHERE revision_id IS NULL");

        $expected_unassigned = $blank_dates + $malformed_dates + $out_of_range_dates;
        if ($total_null !== $expected_unassigned) {
            throw new Exception("Unassigned count mismatch: expected $expected_unassigned NULL properties, found $total_null.");
        }

        if ($total_populated !== $cleanly_mappable) {
            throw new Exception("Populated count mismatch: expected $cleanly_mappable, found $total_populated.");
        }

        // Check for orphan references
        $orphan_count = (int) $wpdb->get_var("
            SELECT COUNT(*)
            FROM $table_properties p
            LEFT JOIN $table_revisions r ON p.revision_id = r.id
            WHERE p.revision_id IS NOT NULL AND r.id IS NULL
        ");

        if ($orphan_count !== 0) {
            throw new Exception("Post-backfill integrity error: $orphan_count orphan references detected!");
        }

        return array(
            'property_count'          => $final_prop_count,
            'cleanly_mappable'        => $cleanly_mappable,
            'total_populated'         => $total_populated,
            'total_null'              => $total_null,
            'blank_dates'             => $blank_dates,
            'malformed_dates'         => $malformed_dates,
            'out_of_range_dates'      => $out_of_range_dates,
            'multiple_matches'        => $multiple_matches,
            'orphan_count'            => $orphan_count,
            'assigned_per_revision'   => $assigned_per_revision,
            'effectivity_dates_valid' => true,
        );
    }
}




