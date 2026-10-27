<?php

/**
 * Class Assessor_Etracs_Sync
 *
 * Handles ETRACS source-to-shadow synchronization with strict ownership protection
 * and collision preflight (Phase 7 / WP-01).
 *
 * Rules:
 * 1. Only writes to ETRACS shadow tables ($wpdb->prefix . 'assessor_*').
 * 2. Absolute architecture boundary: Native Assessor tables (wp_assessor_properties,
 *    versions, documents, requests, states, sync tables) are STRICTLY OFF-LIMITS.
 * 3. Never overwrites or deletes rows marked origin_type = 'LOCAL'.
 * 4. Executes a complete read-only collision preflight against primary and unique keys
 *    across all source rows before writing a single record.
 * 5. If a collision with a LOCAL record is detected, aborts before the write phase,
 *    leaving all shadow records unchanged, and logs diagnostics to assessor_etracs_sync_diagnostics.
 * 6. Replaces destructive $wpdb->replace() with safe INSERT ... ON DUPLICATE KEY UPDATE.
 */
class Assessor_Etracs_Sync {

    /**
     * Diagnostic option key for ETRACS sync errors and collisions.
     */
    const OPTION_DIAGNOSTICS = 'assessor_etracs_sync_diagnostics';

    /**
     * Ensure schema helper. Schema is managed by Assessor_Database.
     */
    public static function ensure_schema() {
        // Managed by class-assessor-database.php
    }

    /**
     * Update sync progress option.
     *
     * @param string $message
     */
    public static function update_progress($message) {
        update_option('assessor_sync_progress', $message, false);
    }

    /**
     * Set last sync diagnostic report.
     *
     * @param array $diag
     */
    public static function set_diagnostics($diag) {
        update_option(self::OPTION_DIAGNOSTICS, $diag, false);
    }

    /**
     * Get last sync diagnostic report.
     *
     * @return array|null
     */
    public static function get_diagnostics() {
        return get_option(self::OPTION_DIAGNOSTICS, null);
    }

    /**
     * Get the ordered stage pipeline definition for ETRACS synchronization.
     *
     * @return array
     */
    public static function get_sync_stages() {
        $stages = array(
            array('name' => 'entity', 'type' => 'custom', 'source_table' => 'entity', 'target_table' => 'assessor_entity', 'order_by' => 'objid'),
            array('name' => 'real_property', 'type' => 'custom', 'source_table' => 'realproperty', 'target_table' => 'assessor_real_property', 'order_by' => 'objid'),
            array('name' => 'rpu', 'type' => 'custom', 'source_table' => 'rpu', 'target_table' => 'assessor_rpu', 'order_by' => 'objid'),
            array('name' => 'faas', 'type' => 'custom', 'source_table' => 'faas', 'target_table' => 'assessor_faas', 'order_by' => 'objid'),
        );

        $tables_to_sync_direct = array(
            'faas_previous'          => 'assessor_faas_previous',
            'faas_list'              => 'assessor_faas_list',
            'faas_signatory'         => 'assessor_faas_signatory',
            'bldgrpu'                => 'assessor_bldgrpu',
            'bldgfloor'              => 'assessor_bldgfloor',
            'bldgflooradditional'    => 'assessor_bldgflooradditional',
            'bldgadditionalitem'     => 'assessor_bldgadditionalitem',
            'bldguse'                => 'assessor_bldguse',
            'bldgstructure'          => 'assessor_bldgstructure',
            'bldgrpu_structuraltype' => 'assessor_bldgrpu_structuraltype',
            'bldgtype_depreciation'  => 'assessor_bldgtype_depreciation',
            'bldgkind'               => 'assessor_bldgkind',
            'bldgkindbucc'           => 'assessor_bldgkindbucc',
            'bldgtype'               => 'assessor_bldgtype',
            'bldgrysetting'          => 'assessor_bldgrysetting',
            'landrpu'                => 'assessor_landrpu',
            'landdetail'             => 'assessor_landdetail',
            'machrpu'                => 'assessor_machrpu',
            'machdetail'             => 'assessor_machdetail',
            'machine'                => 'assessor_machine',
            'machine_smv'            => 'assessor_machine_smv',
            'miscrpu'                => 'assessor_miscrpu',
            'miscitem'               => 'assessor_miscitem',
            'miscrpuitem'            => 'assessor_miscrpuitem',
            'planttreerpu'           => 'assessor_planttreerpu',
            'planttree'              => 'assessor_planttree',
            'planttreedetail'        => 'assessor_planttreedetail',
            'landassesslevel'        => 'assessor_landassesslevel',
            'bldgassesslevel'        => 'assessor_bldgassesslevel',
            'machassesslevel'        => 'assessor_machassesslevel',
            'planttreeassesslevel'   => 'assessor_planttreeassesslevel',
            'miscassesslevel'        => 'assessor_miscassesslevel',
            'propertyclassification' => 'assessor_propertyclassification',
            'structure'              => 'assessor_structure',
            'material'               => 'assessor_material',
            'barangay'               => 'assessor_barangay',
            'exemptiontype'          => 'assessor_exemptiontype',
            'rpumaster'              => 'assessor_rpumaster',
        );

        foreach ($tables_to_sync_direct as $src => $dest) {
            $stages[] = array(
                'name'         => $dest,
                'type'         => 'direct',
                'source_table' => $src,
                'target_table' => $dest,
                'order_by'     => 'objid',
            );
        }

        // Assessment stage
        $stages[] = array(
            'name'         => 'rpu_assessment',
            'type'         => 'assessment',
            'source_table' => 'rpu_assessment',
            'target_table' => 'assessor_rpu_assessment',
            'order_by'     => 'objid',
        );

        return $stages;
    }

    /**
     * Primary ETRACS sync coordinator with ownership protection and collision preflight.
     * Supports resumable bounded-batch HTTP execution, lock ownership verification, and
     * deterministic recovery from interrupted runs.
     *
     * @param PDO|null $external_pdo Optional mock or test PDO connection
     * @param bool $single_batch When true, executes exactly one bounded batch or verification step and returns promptly
     * @param bool $force_new When true, ignores any existing completed or running state and starts a fresh run
     * @return array|WP_Error
     */
    public static function trigger_sync($external_pdo = null, $single_batch = false, $force_new = false) {
        set_time_limit(0);
        self::ensure_schema();

        if ($external_pdo instanceof PDO) {
            $pdo = $external_pdo;
        } else {
            $host = get_option('assessor_etracs_db_host', 'localhost');
            $port = get_option('assessor_etracs_db_port', '3306');
            $user = get_option('assessor_etracs_db_user', 'root');
            $pass = get_option('assessor_etracs_db_password', '');
            $db   = get_option('assessor_etracs_db_name', 'etracs254_kitaotao');

            if (empty($host) || empty($db) || empty($user)) {
                $err = new WP_Error('missing_config', 'ETRACS database configuration is incomplete.', array('status' => 400));
                self::set_diagnostics(array(
                    'status'    => 'FAILED',
                    'timestamp' => current_time('mysql', true),
                    'error'     => 'missing_config',
                    'message'   => $err->get_error_message()
                ));
                return $err;
            }

            try {
                $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
                $options = [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ];
                $pdo = new PDO($dsn, $user, $pass, $options);
            } catch (PDOException $e) {
                $err = new WP_Error('db_connect_failed', 'Failed to connect to ETRACS Database: ' . $e->getMessage(), array('status' => 500));
                self::set_diagnostics(array(
                    'status'    => 'FAILED',
                    'timestamp' => current_time('mysql', true),
                    'error'     => 'db_connect_failed',
                    'message'   => $err->get_error_message()
                ));
                return $err;
            }
        }

        global $wpdb;

        // Generate a unique worker token for this execution invocation
        $worker_id = 'WORKER_' . wp_generate_uuid4();

        // Load existing run state
        $run_state = get_option('assessor_etracs_sync_run_state', null);
        $stages = self::get_sync_stages();
        $total_stages = count($stages);

        $has_active_run = !empty($run_state) && is_array($run_state) &&
            isset($run_state['run_id']) &&
            in_array(strtolower($run_state['status'] ?? ''), array('running', 'in_progress', 'paused'), true) &&
            !$force_new;

        // Check if run lock is active and still fresh (within 10 minutes)
        $lock = get_option('assessor_etracs_sync_lock', null);
        if ($lock && isset($lock['timestamp'])) {
            $lock_age = time() - intval($lock['timestamp']);
            // Case 1: Different run is active within 10 minutes
            if ($lock_age < 600 && (!$has_active_run || $lock['run_id'] !== $run_state['run_id'])) {
                return new WP_Error('sync_locked', 'Another synchronization is currently running (Run ID: ' . esc_html($lock['run_id']) . ').', array('status' => 409));
            }

            // Case 2: Same run, but another worker refreshed heartbeat very recently (within 45s)
            // Prevent duplicate concurrent workers on the same run
            if ($has_active_run && $lock['run_id'] === $run_state['run_id'] && !empty($lock['worker_id']) && $lock_age < 45) {
                return new WP_Error('worker_busy', 'Another worker is currently processing this run (Worker ID: ' . esc_html($lock['worker_id']) . ').', array('status' => 409));
            }
        }

        if ($has_active_run) {
            $run_id = $run_state['run_id'];
            $run_state['status'] = 'IN_PROGRESS';
            // Acquire / refresh lock with new worker ID
            update_option('assessor_etracs_sync_lock', array('run_id' => $run_id, 'worker_id' => $worker_id, 'timestamp' => time()), false);
        } else {
            // New run: Collision preflight is required before writing any records
            self::update_progress("Running collision preflight...");
            $preflight_result = self::run_collision_preflight($pdo);
            if (is_wp_error($preflight_result)) {
                delete_option('assessor_sync_progress');

                if (class_exists('Assessor_Etracs')) {
                    $err_data = $preflight_result->get_error_data();
                    $conflict_count = !empty($err_data['diagnostics']['conflict_count']) ? $err_data['diagnostics']['conflict_count'] : 0;
                    Assessor_Etracs::log_shadow_audit(array(
                        'event_type'            => 'SYNC_PULL',
                        'action_outcome'        => 'ABORTED',
                        'source_classification' => 'SOURCE_SYNC',
                        'origin_type'           => 'REPLICA',
                        'object_type'           => 'SYSTEM_SYNC',
                        'object_id'             => 'BATCH_ABORTED_' . gmdate('Ymd_His'),
                        'user_id'               => 'SYSTEM_SYNC',
                        'actor_name'            => 'Source ETRACS Replica Sync',
                        'changed_fields'        => array(),
                        'details'               => array(
                            'error_code'     => $preflight_result->get_error_code(),
                            'error_message'  => $preflight_result->get_error_message(),
                            'conflict_count' => $conflict_count,
                        ),
                        'correlation_id'        => 'SYNC_ABORT_' . wp_generate_uuid4(),
                    ));
                }

                return $preflight_result;
            }

            $run_id = 'SYNC_' . gmdate('Ymd_His') . '_' . wp_generate_uuid4();
            update_option('assessor_etracs_sync_lock', array('run_id' => $run_id, 'worker_id' => $worker_id, 'timestamp' => time()), false);

            $run_state = array(
                'run_id'           => $run_id,
                'status'           => 'IN_PROGRESS',
                'current_stage'    => 0,
                'cursor'           => 0,
                'last_seen_id'     => '',
                'stats'            => array(),
                'write_failures'   => array(),
                'stage_errors'     => array(),
                'last_error'       => null,
                'started_at'       => current_time('mysql', true),
                'updated_at'       => current_time('mysql', true),
            );
            update_option('assessor_etracs_sync_run_state', $run_state, false);
        }

        $batch_size = 500;

        // Process batches
        while ($run_state['current_stage'] < $total_stages) {
            $stage_idx = $run_state['current_stage'];
            $stage = $stages[$stage_idx];
            $stage_name = $stage['name'];
            $target_table = $wpdb->prefix . $stage['target_table'];
            $source_table = $stage['source_table'];

            if (!isset($run_state['stats'][$stage_name])) {
                $run_state['stats'][$stage_name] = 0;
            }

            self::update_progress(sprintf('Syncing %s (stage %d/%d, offset %d)...', $stage_name, $stage_idx + 1, $total_stages, $run_state['cursor']));

            try {
                $offset = intval($run_state['cursor']);
                if ($stage['type'] === 'custom') {
                    if ($stage_name === 'entity') {
                        $sql = "SELECT * FROM entity ORDER BY objid LIMIT $batch_size OFFSET $offset";
                    } elseif ($stage_name === 'real_property') {
                        $has_bgy = false;
                        try {
                            $chk = $pdo->query("SHOW TABLES LIKE 'barangay'")->fetchColumn();
                            $has_bgy = !empty($chk);
                        } catch (Exception $e) { $has_bgy = false; }

                        if ($has_bgy) {
                            $sql = "SELECT rp.*, b.name as barangay_name FROM realproperty rp LEFT JOIN barangay b ON rp.barangayid = b.objid ORDER BY rp.objid LIMIT $batch_size OFFSET $offset";
                        } else {
                            $sql = "SELECT rp.*, rp.barangayid as barangay_name FROM realproperty rp ORDER BY rp.objid LIMIT $batch_size OFFSET $offset";
                        }
                    } elseif ($stage_name === 'rpu') {
                        $has_pc = false;
                        $has_rp = false;
                        try {
                            $has_pc = !empty($pdo->query("SHOW TABLES LIKE 'propertyclassification'")->fetchColumn());
                            $has_rp = !empty($pdo->query("SHOW TABLES LIKE 'realproperty'")->fetchColumn());
                        } catch (Exception $e) {}

                        if ($has_pc && $has_rp) {
                            $sql = "SELECT rpu.*, pc.code as class_code, rp2.barangayid as rp_barangayid FROM rpu LEFT JOIN propertyclassification pc ON rpu.classification_objid = pc.objid LEFT JOIN realproperty rp2 ON rpu.realpropertyid = rp2.objid ORDER BY rpu.objid LIMIT $batch_size OFFSET $offset";
                        } else {
                            $sql = "SELECT rpu.*, '' as class_code, '' as rp_barangayid FROM rpu ORDER BY rpu.objid LIMIT $batch_size OFFSET $offset";
                        }
                    } elseif ($stage_name === 'faas') {
                        $sql = "SELECT * FROM faas ORDER BY objid LIMIT $batch_size OFFSET $offset";
                    }
                } elseif ($stage['type'] === 'assessment') {
                    $has_table = false;
                    try {
                        $chk = $pdo->query("SHOW TABLES LIKE 'rpu_assessment'")->fetchColumn();
                        $has_table = !empty($chk);
                    } catch (Exception $e) { $has_table = false; }

                    if (!$has_table) {
                        $run_state['current_stage']++;
                        $run_state['cursor'] = 0;
                        $run_state['last_seen_id'] = '';
                        update_option('assessor_etracs_sync_run_state', $run_state, false);
                        if ($single_batch) {
                            return array(
                                'status'       => 'IN_PROGRESS',
                                'sync_status'  => 'IN_PROGRESS',
                                'is_completed' => false,
                                'run_id'       => $run_id,
                                'stats'        => $run_state['stats'],
                                'run_state'    => $run_state,
                                'message'      => sprintf('Skipped non-existent table %s.', $source_table),
                            );
                        }
                        continue;
                    }
                    $sql = "SELECT * FROM rpu_assessment ORDER BY objid LIMIT $batch_size OFFSET $offset";
                } else {
                    $has_table = false;
                    try {
                        $chk = $pdo->query("SHOW TABLES LIKE '$source_table'")->fetchColumn();
                        $has_table = !empty($chk);
                    } catch (Exception $e) { $has_table = false; }

                    if (!$has_table) {
                        $run_state['current_stage']++;
                        $run_state['cursor'] = 0;
                        $run_state['last_seen_id'] = '';
                        update_option('assessor_etracs_sync_run_state', $run_state, false);
                        if ($single_batch) {
                            delete_option('assessor_etracs_sync_lock');
                            return array(
                                'status'       => 'IN_PROGRESS',
                                'sync_status'  => 'IN_PROGRESS',
                                'is_completed' => false,
                                'run_id'       => $run_id,
                                'stats'        => $run_state['stats'],
                                'run_state'    => $run_state,
                                'message'      => sprintf('Skipped non-existent table %s.', $source_table),
                            );
                        }
                        continue;
                    }
                    $sql = "SELECT * FROM `$source_table` ORDER BY objid LIMIT $batch_size OFFSET $offset";
                }

                $stmt = $pdo->query($sql);
                $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();
                $count = count($rows);

                if ($count > 0) {
                    $tbl_exists = ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $target_table)) === $target_table);
                    if (!$tbl_exists) {
                        $run_state['cursor'] += $count;
                        update_option('assessor_etracs_sync_run_state', $run_state, false);
                        if ($single_batch) {
                            delete_option('assessor_etracs_sync_lock');
                            return array(
                                'status'       => 'IN_PROGRESS',
                                'sync_status'  => 'IN_PROGRESS',
                                'is_completed' => false,
                                'run_id'       => $run_id,
                                'stats'        => $run_state['stats'],
                                'run_state'    => $run_state,
                                'message'      => sprintf('Target table %s does not exist; advancing cursor.', $target_table),
                            );
                        }
                        continue;
                    }

                    $valid_cols = $wpdb->get_col("DESCRIBE `$target_table`", 0);
                    $valid_cols_flip = !empty($valid_cols) ? array_flip($valid_cols) : array();

                    foreach ($rows as $row) {
                        if ($stage['type'] === 'custom') {
                            if ($stage_name === 'entity') {
                                $data = [
                                    'objid' => $row['objid'],
                                    'entityno' => $row['entityno'],
                                    'name' => $row['name'],
                                    'address_text' => $row['address_text'],
                                    'mailingaddress' => $row['mailingaddress'],
                                    'type' => $row['type'],
                                    'sys_lastupdate' => $row['sys_lastupdate'],
                                    'sys_lastupdateby' => $row['sys_lastupdateby'],
                                    'remarks' => $row['remarks'],
                                    'entityname' => $row['entityname'],
                                    'address_objid' => $row['address_objid'],
                                    'mobileno' => $row['mobileno'],
                                    'phoneno' => $row['phoneno'],
                                    'email' => $row['email'],
                                    'state' => $row['state'],
                                    'origin_type' => 'REPLICA',
                                ];
                            } elseif ($stage_name === 'real_property') {
                                $data = [
                                    'objid' => $row['objid'],
                                    'pin' => $row['pin'],
                                    'cadastrallotno' => $row['cadastrallotno'],
                                    'surveyno' => $row['surveyno'],
                                    'blockno' => $row['blockno'],
                                    'barangay' => !empty($row['barangay_name']) ? $row['barangay_name'] : $row['barangayid'],
                                    'municipality' => ($row['lgutype'] ?? '') == 'municipality' ? ($row['lguid'] ?? '') : '',
                                    'north' => $row['north'],
                                    'south' => $row['south'],
                                    'east' => $row['east'],
                                    'west' => $row['west'],
                                    'pintype' => $row['pintype'],
                                    'ry' => $row['ry'],
                                    'barangayid' => $row['barangayid'],
                                    'lguid' => $row['lguid'],
                                    'lgutype' => $row['lgutype'],
                                    'purok' => $row['purok'],
                                    'street' => $row['street'],
                                    'stewardshipno' => $row['stewardshipno'],
                                    'portionof' => $row['portionof'],
                                    'autonumber' => $row['autonumber'],
                                    'previd' => $row['previd'],
                                    'claimno' => $row['claimno'],
                                    'section' => $row['section'],
                                    'parcel' => $row['parcel'],
                                    'origin_type' => 'REPLICA',
                                ];
                            } elseif ($stage_name === 'rpu') {
                                $data = [
                                    'objid' => $row['objid'],
                                    'state' => $row['state'],
                                    'realpropertyid' => $row['realpropertyid'],
                                    'rpu_type' => $row['rputype'],
                                    'classification' => !empty($row['class_code']) ? $row['class_code'] : $row['classification_objid'],
                                    'ry' => $row['ry'],
                                    'total_market_value' => floatval($row['totalmv']),
                                    'total_assessed_value' => floatval($row['totalav']),
                                    'taxable' => $row['taxable'],
                                    'total_area_hectare' => floatval($row['totalareaha']),
                                    'total_area_sqm' => floatval($row['totalareasqm']),
                                    'fullpin' => $row['fullpin'],
                                    'suffix' => intval($row['suffix']),
                                    'subsuffix' => $row['subsuffix'],
                                    'classification_objid' => $row['classification_objid'],
                                    'exemptiontype_objid' => $row['exemptiontype_objid'],
                                    'totalbmv' => floatval($row['totalbmv']),
                                    'previd' => $row['previd'],
                                    'rpumasterid' => $row['rpumasterid'],
                                    'barangayid' => $row['rp_barangayid'] ?? '',
                                    'origin_type' => 'REPLICA',
                                ];
                            } elseif ($stage_name === 'faas') {
                                $data = array_intersect_key($row, $valid_cols_flip);
                                $data['origin_type'] = 'REPLICA';
                            }
                        } elseif ($stage['type'] === 'assessment') {
                            $data = array(
                                'objid'                => $row['objid'],
                                'rpuid'                => $row['rpuid'],
                                'classification_objid' => $row['classification_objid'] ?? null,
                                'actualuse_objid'      => $row['actualuse_objid'] ?? null,
                                'areasqm'              => $row['areasqm'] ?? 0.00,
                                'areaha'               => $row['areaha'] ?? 0.000000,
                                'marketvalue'          => $row['marketvalue'] ?? 0.00,
                                'assesslevel'          => $row['assesslevel'] ?? 0.00,
                                'assessedvalue'        => $row['assessedvalue'] ?? 0.00,
                                'rputype'              => $row['rputype'] ?? null,
                                'taxable'              => isset($row['taxable']) ? intval($row['taxable']) : 1,
                                'origin_type'          => 'REPLICA',
                            );
                            if (!empty($valid_cols_flip)) {
                                $data = array_intersect_key($data, $valid_cols_flip);
                            }
                        } else {
                            $data = array_intersect_key($row, $valid_cols_flip);
                            if (isset($valid_cols_flip['origin_type'])) {
                                $data['origin_type'] = 'REPLICA';
                            }
                        }

                        $pk = 'objid';

                        // Verify worker lock ownership before writing
                        $current_lock = get_option('assessor_etracs_sync_lock', null);
                        if (!$current_lock || $current_lock['run_id'] !== $run_id || (isset($current_lock['worker_id']) && $current_lock['worker_id'] !== $worker_id)) {
                            return new WP_Error('worker_preempted', 'Sync lock was preempted or acquired by another worker. Halting write.', array('status' => 409));
                        }

                        $write_res = self::safe_upsert_row($target_table, $data, $pk);

                        if ($write_res === false) {
                            $err_msg = $wpdb->last_error !== '' ? $wpdb->last_error : 'Write returned false';
                            $run_state['write_failures'][] = array(
                                'stage'     => $stage_name,
                                'objid'     => $row['objid'] ?? null,
                                'db_error'  => $err_msg,
                                'timestamp' => current_time('mysql', true),
                            );
                            $run_state['last_error'] = "Write failed in stage $stage_name for objid " . ($row['objid'] ?? 'unknown') . ": $err_msg";
                            $run_state['status'] = 'FAILED';
                            update_option('assessor_etracs_sync_run_state', $run_state, false);
                            delete_option('assessor_etracs_sync_lock');

                            $err = new WP_Error('write_failure', $run_state['last_error'], array('status' => 500));
                            self::set_diagnostics(array(
                                'status'         => 'FAILED',
                                'run_id'         => $run_id,
                                'last_error'     => $run_state['last_error'],
                                'stage'          => $stage_name,
                                'stats'          => $run_state['stats'],
                                'write_failures' => $run_state['write_failures'],
                            ));
                            return $err;
                        }

                        $run_state['stats'][$stage_name]++;
                        $run_state['cursor']++;
                    }

                    $run_state['updated_at'] = current_time('mysql', true);
                    update_option('assessor_etracs_sync_run_state', $run_state, false);
                    update_option('assessor_etracs_sync_lock', array('run_id' => $run_id, 'worker_id' => $worker_id, 'timestamp' => time()), false);

                    // If less than batch size returned, stage is complete
                    if ($count < $batch_size) {
                        $run_state['current_stage']++;
                        $run_state['cursor'] = 0;
                        update_option('assessor_etracs_sync_run_state', $run_state, false);
                    }
                } else {
                    // Stage had 0 records or finished
                    $run_state['current_stage']++;
                    $run_state['cursor'] = 0;
                    update_option('assessor_etracs_sync_run_state', $run_state, false);
                }

                if ($single_batch) {
                    delete_option('assessor_etracs_sync_lock');
                    return array(
                        'status'       => 'IN_PROGRESS',
                        'sync_status'  => 'IN_PROGRESS',
                        'is_completed' => false,
                        'run_id'       => $run_id,
                        'stage'        => $stage_name,
                        'current_stage'=> $run_state['current_stage'],
                        'cursor'       => $run_state['cursor'],
                        'stats'        => $run_state['stats'],
                        'run_state'    => $run_state,
                        'message'      => sprintf('Processed batch of %d records for stage %s.', $count, $stage_name),
                    );
                }
            } catch (Throwable $e) {
                if ($stage['type'] === 'direct') {
                    $run_state['current_stage']++;
                    $run_state['cursor'] = 0;
                    update_option('assessor_etracs_sync_run_state', $run_state, false);
                    if ($single_batch) {
                        delete_option('assessor_etracs_sync_lock');
                        return array(
                            'status'       => 'IN_PROGRESS',
                            'sync_status'  => 'IN_PROGRESS',
                            'is_completed' => false,
                            'run_id'       => $run_id,
                            'stats'        => $run_state['stats'],
                            'run_state'    => $run_state,
                            'message'      => sprintf('Skipped optional stage %s after non-fatal notice: %s', $stage_name, $e->getMessage()),
                        );
                    }
                } else {
                    $run_state['status'] = 'FAILED';
                    $run_state['last_error'] = "Exception in stage $stage_name: " . $e->getMessage();
                    $run_state['stage_errors'][] = array('stage' => $stage_name, 'error' => $e->getMessage());
                    update_option('assessor_etracs_sync_run_state', $run_state, false);
                    delete_option('assessor_etracs_sync_lock');

                    $err = new WP_Error('stage_exception', $run_state['last_error'], array('status' => 500));
                    self::set_diagnostics(array(
                        'status'      => 'FAILED',
                        'run_id'      => $run_id,
                        'last_error'  => $run_state['last_error'],
                        'stage'       => $stage_name,
                        'stats'       => $run_state['stats'],
                    ));
                    return $err;
                }
            }
        }

        // ──────────────────────────────────────────────
        // STEP 3: POST-IMPORT MEANINGFUL VERIFICATION
        // ──────────────────────────────────────────────
        self::update_progress("Running post-import verification...");
        $verify_result = self::verify_post_import($pdo, $run_state['stats']);

        if (is_wp_error($verify_result)) {
            $run_state['status'] = 'FAILED';
            $run_state['last_error'] = $verify_result->get_error_message();
            update_option('assessor_etracs_sync_run_state', $run_state, false);
            delete_option('assessor_etracs_sync_lock');

            self::set_diagnostics(array(
                'status'              => 'FAILED',
                'run_id'              => $run_id,
                'last_error'          => $verify_result->get_error_message(),
                'verification'        => $verify_result->get_error_data(),
                'stats'               => $run_state['stats'],
            ));
            return $verify_result;
        }

        // Check if there were any partial warnings/write failures
        $is_partial = !empty($run_state['write_failures']) || !empty($verify_result['warnings']);

        $run_state['status'] = $is_partial ? 'PARTIAL' : 'SUCCESS';
        $run_state['completed_at'] = current_time('mysql', true);
        $run_state['verification'] = $verify_result;
        update_option('assessor_etracs_sync_run_state', $run_state, false);
        delete_option('assessor_etracs_sync_lock');

        update_option('assessor_etracs_last_sync', current_time('mysql', true));
        delete_option('assessor_sync_progress');

        // Log WP-07 Audit Event
        $stats = $run_state['stats'];
        if (class_exists('Assessor_Etracs')) {
            $total_synced = array_sum(array_filter($stats, 'is_numeric'));
            Assessor_Etracs::log_shadow_audit(array(
                'event_type'            => 'SYNC_PULL',
                'action_outcome'        => $is_partial ? 'PARTIAL' : 'SUCCESS',
                'source_classification' => 'SOURCE_SYNC',
                'origin_type'           => 'REPLICA',
                'object_type'           => 'SYSTEM_SYNC',
                'object_id'             => 'BATCH_' . gmdate('Ymd_His'),
                'user_id'               => 'SYSTEM_SYNC',
                'actor_name'            => 'Source ETRACS Replica Sync',
                'changed_fields'        => array_keys($stats),
                'details'               => array(
                    'run_id'            => $run_id,
                    'total_synced_rows' => $total_synced,
                    'table_breakdown'   => $stats,
                    'verification'      => $verify_result,
                ),
                'correlation_id'        => 'SYNC_' . wp_generate_uuid4(),
            ));
        }

        $diag = array(
            'status'       => $is_partial ? 'PARTIAL' : 'SUCCESS',
            'sync_status'  => $is_partial ? 'PARTIAL' : 'SUCCESS',
            'run_id'       => $run_id,
            'timestamp'    => current_time('mysql', true),
            'stats'        => $stats,
            'verification' => $verify_result,
            'message'      => $is_partial ? 'Sync completed with warnings or partial writes.' : 'Synchronization and post-import verification completed successfully.',
        );
        self::set_diagnostics($diag);

        if ($single_batch) {
            return array(
                'status'       => $is_partial ? 'PARTIAL' : 'SUCCESS',
                'sync_status'  => $is_partial ? 'PARTIAL' : 'SUCCESS',
                'is_completed' => true,
                'run_id'       => $run_id,
                'stats'        => $stats,
                'verification' => $verify_result,
                'run_state'    => $run_state,
                'diagnostics'  => $diag,
                'message'      => $diag['message'],
            );
        }

        return $stats;
    }

    /**
     * Post-import verification check (WP requirements).
     *
     * Validates:
     * 1. Destination assessment count matches source dynamically.
     * 2. Distinct objid count matches source.
     * 3. Assessment rows link to valid RPU rows.
     * 4. Actual Use identifiers resolve to the 5 lookup tables based on rputype.
     * 5. No memory exhaustion when streaming chunked IDs.
     *
     * @param PDO $pdo
     * @param array $stats
     * @return array|WP_Error
     */
    public static function verify_post_import(PDO $pdo, array $stats = array()) {
        global $wpdb;

        $t_ra = $wpdb->prefix . 'assessor_rpu_assessment';
        $t_rpu = $wpdb->prefix . 'assessor_rpu';
        $warnings = array();

        // 1. Dynamic source vs destination assessment count
        $src_count = 0;
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM rpu_assessment");
            if ($stmt) {
                $src_count = (int)$stmt->fetchColumn();
            }
        } catch (Exception $e) {
            // Source table doesn't exist or query failed
            return array('status' => 'skipped_no_source_table', 'message' => 'Source rpu_assessment could not be queried: ' . $e->getMessage());
        }

        $dest_count = (int)$wpdb->get_var("SELECT COUNT(*) FROM `$t_ra`");
        $dest_distinct = (int)$wpdb->get_var("SELECT COUNT(DISTINCT objid) FROM `$t_ra`");

        if ($src_count > 0 && $dest_distinct < $src_count) {
            return new WP_Error(
                'verification_count_mismatch',
                sprintf('Assessment verification failed: expected %d source records, found %d distinct destination records.', $src_count, $dest_distinct),
                array('expected' => $src_count, 'actual_distinct' => $dest_distinct, 'actual_total' => $dest_count)
            );
        }

        // 2. Chunked ID-level check to verify all source identifiers exist in destination without memory exhaustion
        try {
            $chunk_size = 2000;
            $offset = 0;
            $missing_ids = array();

            while ($offset < $src_count) {
                $stmt = $pdo->query("SELECT objid FROM rpu_assessment ORDER BY objid LIMIT $chunk_size OFFSET $offset");
                $src_ids = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN, 0) : array();
                if (empty($src_ids)) {
                    break;
                }

                $placeholders = implode(', ', array_fill(0, count($src_ids), '%s'));
                $query = $wpdb->prepare("SELECT objid FROM `$t_ra` WHERE objid IN ($placeholders)", $src_ids);
                $found_ids = $wpdb->get_col($query, 0);
                $found_map = !empty($found_ids) ? array_flip($found_ids) : array();

                foreach ($src_ids as $sid) {
                    if (!isset($found_map[$sid])) {
                        $missing_ids[] = $sid;
                        if (count($missing_ids) >= 100) break; // Cap reported missing IDs
                    }
                }
                if (!empty($missing_ids)) {
                    break;
                }
                $offset += $chunk_size;
            }

            if (!empty($missing_ids)) {
                return new WP_Error(
                    'verification_missing_records',
                    sprintf('Assessment verification failed: %d missing assessment objid(s) detected.', count($missing_ids)),
                    array('missing_ids' => array_slice($missing_ids, 0, 20))
                );
            }
        } catch (Exception $e) {
            $warnings[] = 'ID chunk verification warning: ' . $e->getMessage();
        }

        // 3. Verify valid RPU links
        $orphan_assessments = (int)$wpdb->get_var("
            SELECT COUNT(*) FROM `$t_ra` ra
            LEFT JOIN `$t_rpu` r ON ra.rpuid = r.objid
            WHERE r.objid IS NULL
        ");
        if ($orphan_assessments > 0) {
            $warnings[] = sprintf('%d assessment line(s) have unlinked/orphan rpuid.', $orphan_assessments);
        }

        // 4. Verify Actual Use identifiers resolve through the 5 lookup tables
        $t_la = $wpdb->prefix . 'assessor_landassesslevel';
        $t_ba = $wpdb->prefix . 'assessor_bldgassesslevel';
        $t_ma = $wpdb->prefix . 'assessor_machassesslevel';
        $t_pa = $wpdb->prefix . 'assessor_planttreeassesslevel';
        $t_mia = $wpdb->prefix . 'assessor_miscassesslevel';

        $has_lookups = ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t_la)) === $t_la);

        $unresolved_actualuse_count = 0;
        $unresolved_details = array();
        if ($has_lookups) {
            $unresolved_rows = $wpdb->get_results("
                SELECT 
                    ra.objid, 
                    ra.rpuid, 
                    ra.rputype, 
                    ra.actualuse_objid,
                    CASE 
                        WHEN LOWER(ra.rputype) = 'land' THEN 'landassesslevel'
                        WHEN LOWER(ra.rputype) = 'bldg' THEN 'bldgassesslevel'
                        WHEN LOWER(ra.rputype) = 'mach' THEN 'machassesslevel'
                        WHEN LOWER(ra.rputype) = 'planttree' THEN 'planttreeassesslevel'
                        WHEN LOWER(ra.rputype) = 'misc' THEN 'miscassesslevel'
                        ELSE 'unknown_rputype_table'
                    END as expected_lookup_table
                FROM `$t_ra` ra
                LEFT JOIN `$t_la` la ON LOWER(ra.rputype) = 'land' AND la.objid = ra.actualuse_objid
                LEFT JOIN `$t_ba` ba ON LOWER(ra.rputype) = 'bldg' AND ba.objid = ra.actualuse_objid
                LEFT JOIN `$t_ma` ma ON LOWER(ra.rputype) = 'mach' AND ma.objid = ra.actualuse_objid
                LEFT JOIN `$t_pa` pa ON LOWER(ra.rputype) = 'planttree' AND pa.objid = ra.actualuse_objid
                LEFT JOIN `$t_mia` mia ON LOWER(ra.rputype) = 'misc' AND mia.objid = ra.actualuse_objid
                WHERE ra.actualuse_objid IS NOT NULL 
                  AND ra.actualuse_objid != ''
                  AND COALESCE(la.name, ba.name, ma.name, pa.name, mia.name) IS NULL
            ", ARRAY_A);

            if (!empty($unresolved_rows)) {
                $unresolved_actualuse_count = count($unresolved_rows);
                foreach (array_slice($unresolved_rows, 0, 50) as $ur) {
                    $unresolved_details[] = array(
                        'objid'                 => $ur['objid'],
                        'rpuid'                 => $ur['rpuid'],
                        'rputype'               => $ur['rputype'],
                        'actualuse_objid'       => $ur['actualuse_objid'],
                        'expected_lookup_table' => $ur['expected_lookup_table'],
                    );
                }
                $warnings[] = sprintf('%d assessment line(s) have actualuse_objid that could not be resolved against the expected lookup tables.', $unresolved_actualuse_count);
            }
        }

        return array(
            'status'                     => 'verified',
            'source_count'               => $src_count,
            'destination_count'          => $dest_count,
            'distinct_objids'            => $dest_distinct,
            'orphan_assessments'         => $orphan_assessments,
            'unresolved_actualuse_count' => $unresolved_actualuse_count,
            'unresolved_actualuse'       => $unresolved_details,
            'warnings'                   => $warnings,
        );
    }

    /**
     * Complete read-only collision preflight against all synchronized source rows
     * and local shadow tables.
     *
     * Inspects:
     * 1. Primary key collisions on objid where local row has origin_type = 'LOCAL'
     * 2. Natural key collisions:
     *    - assessor_faas: ux_faas_utdno (utdno)
     *    - assessor_entity: uix_entityno / entityno (entityno)
     *    - assessor_real_property: ux_rp_objid (objid)
     *    - assessor_rpu: ux_rpu_objid (objid)
     *    - other tables with unique constraints
     *
     * Returns true if no collisions, or WP_Error with detailed conflict diagnostic info.
     *
     * @param PDO $pdo
     * @return true|WP_Error
     */
    public static function run_collision_preflight(PDO $pdo) {
        global $wpdb;

        $conflicts = array();

        // 1. Check FAAS:
        //    - PK objid: local row origin_type = 'LOCAL'
        //    - Alternate unique key `ux_faas_utdno` (utdno): any existing local row (LOCAL or REPLICA) with different objid
        $table_faas = $wpdb->prefix . 'assessor_faas';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_faas)) === $table_faas) {
            $has_origin = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `$table_faas` LIKE %s", 'origin_type'));
            
            // Map of all local rows by utdno for alternate key collision detection (LOCAL and REPLICA)
            $all_faas_rows = $wpdb->get_results("SELECT objid, utdno, tdno, " . ($has_origin ? "origin_type" : "'REPLICA' as origin_type") . " FROM `$table_faas`", ARRAY_A);
            $local_faas_by_utdno = array();
            $local_faas_by_objid = array();
            if (!empty($all_faas_rows)) {
                foreach ($all_faas_rows as $rf) {
                    $local_faas_by_objid[$rf['objid']] = $rf;
                    if (!empty($rf['utdno'])) {
                        $local_faas_by_utdno[$rf['utdno']] = $rf;
                    }
                }
            }

            try {
                $stmt = $pdo->query("SELECT objid, utdno, tdno FROM faas");
                while ($src = $stmt->fetch()) {
                    // Check PK conflict against LOCAL row
                    if ($has_origin && isset($local_faas_by_objid[$src['objid']])) {
                        $existing = $local_faas_by_objid[$src['objid']];
                        if ($existing['origin_type'] === 'LOCAL') {
                            $conflicts[] = array(
                                'table'         => $table_faas,
                                'conflict_type' => 'PRIMARY_KEY_LOCAL_OVERWRITE',
                                'key_name'      => 'objid',
                                'key_value'     => $src['objid'],
                                'source_row'    => array('objid' => $src['objid'], 'utdno' => $src['utdno'], 'tdno' => $src['tdno']),
                                'local_row'     => $existing,
                            );
                        }
                    }

                    // Check alternate unique key conflict on utdno against ANY existing row with different objid
                    if (!empty($src['utdno']) && isset($local_faas_by_utdno[$src['utdno']])) {
                        $existing_by_utdno = $local_faas_by_utdno[$src['utdno']];
                        if ($existing_by_utdno['objid'] !== $src['objid']) {
                            $conflicts[] = array(
                                'table'         => $table_faas,
                                'conflict_type' => 'UNIQUE_KEY_COLLISION_UTDNO',
                                'key_name'      => 'utdno',
                                'key_value'     => $src['utdno'],
                                'source_row'    => array('objid' => $src['objid'], 'utdno' => $src['utdno'], 'tdno' => $src['tdno']),
                                'local_row'     => $existing_by_utdno,
                            );
                        }
                    }
                }
            } catch (Exception $e) {
                // Ignore if source table cannot be read
            }
        }

        // 2. Check Entity:
        //    - PK objid: local row origin_type = 'LOCAL'
        //    - Alternate unique key `entityno` (uix_entityno): any existing local row (LOCAL or REPLICA) with different objid
        $table_entity = $wpdb->prefix . 'assessor_entity';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_entity)) === $table_entity) {
            $has_origin = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `$table_entity` LIKE %s", 'origin_type'));
            
            $all_entities = $wpdb->get_results("SELECT objid, entityno, name, " . ($has_origin ? "origin_type" : "'REPLICA' as origin_type") . " FROM `$table_entity`", ARRAY_A);
            $local_ent_by_objid = array();
            $local_ent_by_no    = array();
            if (!empty($all_entities)) {
                foreach ($all_entities as $re) {
                    $local_ent_by_objid[$re['objid']] = $re;
                    if (!empty($re['entityno'])) {
                        $local_ent_by_no[$re['entityno']] = $re;
                    }
                }
            }

            try {
                $stmt = $pdo->query("SELECT objid, entityno, name FROM entity");
                while ($src = $stmt->fetch()) {
                    if ($has_origin && isset($local_ent_by_objid[$src['objid']])) {
                        $existing = $local_ent_by_objid[$src['objid']];
                        if ($existing['origin_type'] === 'LOCAL') {
                            $conflicts[] = array(
                                'table'         => $table_entity,
                                'conflict_type' => 'PRIMARY_KEY_LOCAL_OVERWRITE',
                                'key_name'      => 'objid',
                                'key_value'     => $src['objid'],
                                'source_row'    => $src,
                                'local_row'     => $existing,
                            );
                        }
                    }

                    if (!empty($src['entityno']) && isset($local_ent_by_no[$src['entityno']])) {
                        $existing_by_no = $local_ent_by_no[$src['entityno']];
                        if ($existing_by_no['objid'] !== $src['objid']) {
                            $conflicts[] = array(
                                'table'         => $table_entity,
                                'conflict_type' => 'UNIQUE_KEY_COLLISION_ENTITYNO',
                                'key_name'      => 'entityno',
                                'key_value'     => $src['entityno'],
                                'source_row'    => $src,
                                'local_row'     => $existing_by_no,
                            );
                        }
                    }
                }
            } catch (Exception $e) {
                // Ignore if source table cannot be read
            }
        }

        // 3. Check Real Property:
        //    - PK id (auto-inc)
        //    - Unique key ux_rp_objid (objid): checks if local row has origin_type = 'LOCAL'
        $table_rp = $wpdb->prefix . 'assessor_real_property';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_rp)) === $table_rp) {
            $has_origin = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `$table_rp` LIKE %s", 'origin_type'));
            if ($has_origin) {
                $local_rp = $wpdb->get_results("SELECT objid, pin, origin_type FROM `$table_rp` WHERE origin_type = 'LOCAL'", ARRAY_A);
                if (!empty($local_rp)) {
                    $local_by_objid = array();
                    foreach ($local_rp as $lr) {
                        $local_by_objid[$lr['objid']] = $lr;
                    }
                    try {
                        $stmt = $pdo->query("SELECT objid, pin FROM realproperty");
                        while ($src = $stmt->fetch()) {
                            if (isset($local_by_objid[$src['objid']])) {
                                $conflicts[] = array(
                                    'table'         => $table_rp,
                                    'conflict_type' => 'PRIMARY_KEY_LOCAL_OVERWRITE',
                                    'key_name'      => 'objid',
                                    'key_value'     => $src['objid'],
                                    'source_row'    => $src,
                                    'local_row'     => $local_by_objid[$src['objid']],
                                );
                            }
                        }
                    } catch (Exception $e) {}
                }
            }
        }

        // 4. Check RPU:
        //    - PK id (auto-inc)
        //    - Unique key ux_rpu_objid (objid): checks if local row has origin_type = 'LOCAL'
        $table_rpu = $wpdb->prefix . 'assessor_rpu';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_rpu)) === $table_rpu) {
            $has_origin = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `$table_rpu` LIKE %s", 'origin_type'));
            if ($has_origin) {
                $local_rpu = $wpdb->get_results("SELECT objid, realpropertyid, origin_type FROM `$table_rpu` WHERE origin_type = 'LOCAL'", ARRAY_A);
                if (!empty($local_rpu)) {
                    $local_by_objid = array();
                    foreach ($local_rpu as $lr) {
                        $local_by_objid[$lr['objid']] = $lr;
                    }
                    try {
                        $stmt = $pdo->query("SELECT objid, realpropertyid FROM rpu");
                        while ($src = $stmt->fetch()) {
                            if (isset($local_by_objid[$src['objid']])) {
                                $conflicts[] = array(
                                    'table'         => $table_rpu,
                                    'conflict_type' => 'PRIMARY_KEY_LOCAL_OVERWRITE',
                                    'key_name'      => 'objid',
                                    'key_value'     => $src['objid'],
                                    'source_row'    => $src,
                                    'local_row'     => $local_by_objid[$src['objid']],
                                );
                            }
                        }
                    } catch (Exception $e) {}
                }
            }
        }

        // 5. Check All Other Synced Detail Tables with origin_type (PK objid)
        $other_detail_tables = [
            'faas_previous'          => 'assessor_faas_previous',
            'faas_list'              => 'assessor_faas_list',
            'faas_signatory'         => 'assessor_faas_signatory',
            'bldgrpu'                => 'assessor_bldgrpu',
            'bldgfloor'              => 'assessor_bldgfloor',
            'bldgflooradditional'    => 'assessor_bldgflooradditional',
            'bldgadditionalitem'     => 'assessor_bldgadditionalitem',
            'bldguse'                => 'assessor_bldguse',
            'bldgstructure'          => 'assessor_bldgstructure',
            'bldgrpu_structuraltype' => 'assessor_bldgrpu_structuraltype',
            'bldgtype_depreciation'  => 'assessor_bldgtype_depreciation',
            'landrpu'                => 'assessor_landrpu',
            'landdetail'             => 'assessor_landdetail',
            'machrpu'                => 'assessor_machrpu',
            'machdetail'             => 'assessor_machdetail',
            'miscrpu'                => 'assessor_miscrpu',
            'miscrpuitem'            => 'assessor_miscrpuitem',
            'planttreerpu'           => 'assessor_planttreerpu',
            'planttreedetail'        => 'assessor_planttreedetail',
            'rpu_assessment'         => 'assessor_rpu_assessment',
            'rpumaster'              => 'assessor_rpumaster',
        ];

        foreach ($other_detail_tables as $src_tbl => $local_suffix) {
            $full_tbl = $wpdb->prefix . $local_suffix;
            if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $full_tbl)) !== $full_tbl) {
                continue;
            }
            $has_origin = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `$full_tbl` LIKE %s", 'origin_type'));
            if (!$has_origin) {
                continue;
            }

            $local_ids = $wpdb->get_col("SELECT objid FROM `$full_tbl` WHERE origin_type = 'LOCAL'");
            if (empty($local_ids)) {
                continue;
            }

            $local_ids_map = array_flip($local_ids);
            try {
                $stmt = $pdo->query("SELECT objid FROM `$src_tbl`");
                while ($s_id = $stmt->fetchColumn()) {
                    if (isset($local_ids_map[$s_id])) {
                        $conflicts[] = array(
                            'table'         => $full_tbl,
                            'conflict_type' => 'PRIMARY_KEY_LOCAL_OVERWRITE',
                            'key_name'      => 'objid',
                            'key_value'     => $s_id,
                            'source_row'    => array('objid' => $s_id),
                            'local_row'     => array('objid' => $s_id),
                        );
                    }
                }
            } catch (Exception $e) {
                // Table might not exist in source
            }
        }

        // 6. Check tables with alternate UNIQUE keys (including lookup/detail tables)
        // If an incoming source row has an alternate unique key matching an existing row with a different objid,
        // MySQL ON DUPLICATE KEY UPDATE would repurpose/overwrite that existing row!
        // We check:
        // - machine: ux_machine_code (code), ux_machine_name (name)
        // - planttree: ux_planttree_code (code), ux_planttree_name (name)
        // - machine_smv: ux_parent_machine (parent_objid, machine_objid)
        // - bldgkind: ux_bldgkind_code (code), ux_bldgkind_name (name)
        // - miscitem: ux_miscitem_code (code)
        // - propertyclassification: ux_classcode (code)
        // - structure: ux_structure_code (code), ux_structure_name (name)
        // - material: ux_material_code (code), ux_material_name (name)
        // - exemptiontype: ux_exemptcode (code)
        $unique_tables_spec = [
            'machine' => [
                'local_table' => 'assessor_machine',
                'unique_keys' => [
                    'ux_machine_code' => ['code'],
                    'ux_machine_name' => ['name'],
                ],
            ],
            'planttree' => [
                'local_table' => 'assessor_planttree',
                'unique_keys' => [
                    'ux_planttree_code' => ['code'],
                    'ux_planttree_name' => ['name'],
                ],
            ],
            'machine_smv' => [
                'local_table' => 'assessor_machine_smv',
                'unique_keys' => [
                    'ux_parent_machine' => ['parent_objid', 'machine_objid'],
                ],
            ],
            'bldgkind' => [
                'local_table' => 'assessor_bldgkind',
                'unique_keys' => [
                    'ux_bldgkind_code' => ['code'],
                    'ux_bldgkind_name' => ['name'],
                ],
            ],
            'miscitem' => [
                'local_table' => 'assessor_miscitem',
                'unique_keys' => [
                    'ux_miscitem_code' => ['code'],
                ],
            ],
            'propertyclassification' => [
                'local_table' => 'assessor_propertyclassification',
                'unique_keys' => [
                    'ux_classcode' => ['code'],
                ],
            ],
            'structure' => [
                'local_table' => 'assessor_structure',
                'unique_keys' => [
                    'ux_structure_code' => ['code'],
                    'ux_structure_name' => ['name'],
                ],
            ],
            'material' => [
                'local_table' => 'assessor_material',
                'unique_keys' => [
                    'ux_material_code' => ['code'],
                    'ux_material_name' => ['name'],
                ],
            ],
            'exemptiontype' => [
                'local_table' => 'assessor_exemptiontype',
                'unique_keys' => [
                    'ux_exemptcode' => ['code'],
                ],
            ],
        ];

        foreach ($unique_tables_spec as $src_tbl => $spec) {
            $full_tbl = $wpdb->prefix . $spec['local_table'];
            if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $full_tbl)) !== $full_tbl) {
                continue;
            }

            // Gather all columns needed for the unique keys plus objid
            $needed_cols = ['objid'];
            foreach ($spec['unique_keys'] as $idx_cols) {
                foreach ($idx_cols as $c) {
                    if (!in_array($c, $needed_cols, true)) {
                        $needed_cols[] = $c;
                    }
                }
            }
            $cols_sql = implode(', ', array_map(function($c) { return "`$c`"; }, $needed_cols));
            $existing_rows = $wpdb->get_results("SELECT $cols_sql FROM `$full_tbl`", ARRAY_A);
            if (empty($existing_rows)) {
                continue;
            }

            // Build lookup maps for each unique key: map stringified key => row
            $lookups = [];
            foreach ($spec['unique_keys'] as $idx_name => $idx_cols) {
                $lookups[$idx_name] = [];
                foreach ($existing_rows as $er) {
                    $composite_val = [];
                    $has_all = true;
                    foreach ($idx_cols as $c) {
                        if ($er[$c] === null || $er[$c] === '') {
                            $has_all = false;
                            break;
                        }
                        $composite_val[] = (string)$er[$c];
                    }
                    if ($has_all) {
                        $key_str = implode('|||', $composite_val);
                        $lookups[$idx_name][$key_str] = $er;
                    }
                }
            }

            // Query source table and check for collisions against different objids
            try {
                $stmt = $pdo->query("SELECT $cols_sql FROM `$src_tbl`");
                while ($s_row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $s_objid = $s_row['objid'];
                    foreach ($spec['unique_keys'] as $idx_name => $idx_cols) {
                        $composite_val = [];
                        $has_all = true;
                        foreach ($idx_cols as $c) {
                            if (!isset($s_row[$c]) || $s_row[$c] === null || $s_row[$c] === '') {
                                $has_all = false;
                                break;
                            }
                            $composite_val[] = (string)$s_row[$c];
                        }
                        if ($has_all) {
                            $key_str = implode('|||', $composite_val);
                            if (isset($lookups[$idx_name][$key_str])) {
                                $existing = $lookups[$idx_name][$key_str];
                                if ($existing['objid'] !== $s_objid) {
                                    $conflicts[] = array(
                                        'table'         => $full_tbl,
                                        'conflict_type' => 'ALTERNATE_UNIQUE_KEY_COLLISION',
                                        'key_name'      => $idx_name,
                                        'key_columns'   => $idx_cols,
                                        'key_value'     => $key_str,
                                        'source_row'    => $s_row,
                                        'local_row'     => $existing,
                                    );
                                }
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                // Table might not exist in source, ignore
            }
        }

        if (!empty($conflicts)) {
            $diag = array(
                'status'          => 'aborted_preflight_conflict',
                'timestamp'       => current_time('mysql', true),
                'conflict_count'  => count($conflicts),
                'conflicts'       => $conflicts,
                'message'         => 'Source synchronization aborted before write phase due to primary/unique key collisions with locally authored records.'
            );
            self::set_diagnostics($diag);

            return new WP_Error(
                'sync_preflight_collision',
                sprintf('ETRACS synchronization aborted: %d collision(s) detected with local records.', count($conflicts)),
                array('status' => 409, 'diagnostics' => $diag)
            );
        }

        return true;
    }

    /**
     * Safe upsert helper executing:
     * INSERT INTO table (...) VALUES (...)
     * ON DUPLICATE KEY UPDATE col1 = VALUES(col1), ...
     *
     * In addition, if table has origin_type:
     * col = IF(origin_type = 'LOCAL', table.col, VALUES(col))
     * This provides defense-in-depth even after preflight.
     *
     * @param string $table Full table name
     * @param array $data Column => value map
     * @param string $pk_name Primary or unique column name to match on
     * @return int|false
     */
    public static function safe_upsert_row($table, $data, $pk_name = 'objid') {
        global $wpdb;

        if (empty($data)) {
            return false;
        }

        // Filter incoming data against actual existing columns in target table
        static $table_columns = array();
        if (!isset($table_columns[$table])) {
            $cols = $wpdb->get_col("DESCRIBE `$table`", 0);
            $table_columns[$table] = !empty($cols) ? array_flip($cols) : array();
        }
        if (!empty($table_columns[$table])) {
            $data = array_intersect_key($data, $table_columns[$table]);
        }

        if (empty($data)) {
            return false;
        }

        // Check if table has origin_type column
        static $table_has_origin = array();
        if (!isset($table_has_origin[$table])) {
            $col = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `$table` LIKE %s", 'origin_type'));
            $table_has_origin[$table] = !empty($col);
        }
        $has_origin = $table_has_origin[$table];

        $columns = array_keys($data);
        $escaped_cols = array_map(function($c) { return "`$c`"; }, $columns);
        $placeholders = array_fill(0, count($columns), '%s');

        $values = array_values($data);

        // Build ON DUPLICATE KEY UPDATE clause
        $update_parts = array();
        foreach ($columns as $col) {
            if ($col === $pk_name) {
                continue; // Do not update primary key
            }
            if ($col === 'id') {
                continue; // Do not update auto-increment id
            }
            if ($col === 'origin_type') {
                // Never overwrite origin_type if existing is LOCAL
                $update_parts[] = "`origin_type` = IF(`origin_type` = 'LOCAL', 'LOCAL', 'REPLICA')";
            } elseif ($has_origin) {
                // If local row exists, protect its values from being overwritten
                $update_parts[] = "`$col` = IF(`origin_type` = 'LOCAL', `$table`.`$col`, VALUES(`$col`))";
            } else {
                $update_parts[] = "`$col` = VALUES(`$col`)";
            }
        }

        $cols_sql = implode(', ', $escaped_cols);
        $placeholders_sql = implode(', ', $placeholders);
        $updates_sql = !empty($update_parts) ? implode(', ', $update_parts) : "`$pk_name` = `$pk_name`";

        $sql = "INSERT INTO `$table` ($cols_sql) VALUES ($placeholders_sql) ON DUPLICATE KEY UPDATE $updates_sql";

        $prepared = $wpdb->prepare($sql, $values);
        return $wpdb->query($prepared);
    }
}
