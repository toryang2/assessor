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
     * Primary ETRACS sync coordinator with ownership protection and collision preflight.
     *
     * @param PDO|null $external_pdo Optional mock or test PDO connection
     * @return array|WP_Error
     */
    public static function trigger_sync($external_pdo = null) {
        set_time_limit(0);
        self::update_progress("Starting sync...");
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
                    'status'    => 'failed',
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
                    'status'    => 'failed',
                    'timestamp' => current_time('mysql', true),
                    'error'     => 'db_connect_failed',
                    'message'   => $err->get_error_message()
                ));
                return $err;
            }
        }

        global $wpdb;

        // ──────────────────────────────────────────────
        // STEP 1: READ-ONLY COLLISION PREFLIGHT
        // ──────────────────────────────────────────────
        self::update_progress("Running collision preflight...");
        $preflight_result = self::run_collision_preflight($pdo);
        if (is_wp_error($preflight_result)) {
            delete_option('assessor_sync_progress');

            // Log WP-07 Audit Event: Preflight Aborted Collision
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

        // ──────────────────────────────────────────────
        // STEP 2: SAFE PROTECTED WRITE PHASE
        // ──────────────────────────────────────────────
        $stats = [
            'entity' => 0,
            'real_property' => 0,
            'rpu' => 0,
            'faas' => 0
        ];

        $wp_entity = $wpdb->prefix . 'assessor_entity';
        $wp_rp = $wpdb->prefix . 'assessor_real_property';
        $wp_rpu = $wpdb->prefix . 'assessor_rpu';
        $wp_faas = $wpdb->prefix . 'assessor_faas';

        // 1. Entity Sync
        try {
            $stmt = $pdo->query("SELECT * FROM entity");
            while ($row = $stmt->fetch()) {
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
                self::safe_upsert_row($wp_entity, $data, 'objid');
                $stats['entity']++;
                if ($stats['entity'] % 500 === 0) self::update_progress("Syncing Entities ({$stats['entity']} rows)...");
            }
        } catch (Exception $e) {
            error_log("Assessor ETRACS Sync Error in Entity: " . $e->getMessage());
        }

        // 2. Real Property Sync
        try {
            $stmt = $pdo->query("
                SELECT rp.*, b.name as barangay_name 
                FROM realproperty rp 
                LEFT JOIN barangay b ON rp.barangayid = b.objid
            ");
            while ($row = $stmt->fetch()) {
                $data = [
                    'objid' => $row['objid'],
                    'pin' => $row['pin'],
                    'cadastrallotno' => $row['cadastrallotno'],
                    'surveyno' => $row['surveyno'],
                    'blockno' => $row['blockno'],
                    'barangay' => !empty($row['barangay_name']) ? $row['barangay_name'] : $row['barangayid'],
                    'municipality' => $row['lgutype'] == 'municipality' ? $row['lguid'] : '',
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
                self::safe_upsert_row($wp_rp, $data, 'objid');
                $stats['real_property']++;
                if ($stats['real_property'] % 500 === 0) self::update_progress("Syncing Real Properties ({$stats['real_property']} rows)...");
            }
        } catch (Exception $e) {
            error_log("Assessor ETRACS Sync Error in Real Property: " . $e->getMessage());
        }

        // 3. RPU Sync
        try {
            $stmt = $pdo->query("
                SELECT rpu.*, pc.code as class_code, rp2.barangayid as rp_barangayid
                FROM rpu 
                LEFT JOIN propertyclassification pc ON rpu.classification_objid = pc.objid
                LEFT JOIN realproperty rp2 ON rpu.realpropertyid = rp2.objid
            ");
            while ($row = $stmt->fetch()) {
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
                self::safe_upsert_row($wp_rpu, $data, 'objid');
                $stats['rpu']++;
                if ($stats['rpu'] % 500 === 0) self::update_progress("Syncing RPUs ({$stats['rpu']} rows)...");
            }
        } catch (Exception $e) {
            error_log("Assessor ETRACS Sync Error in RPU: " . $e->getMessage());
        }

        // 4. FAAS Sync
        try {
            $stmt = $pdo->query("SELECT * FROM faas");
            while ($row = $stmt->fetch()) {
                $data = [
                    'objid' => $row['objid'],
                    'state' => $row['state'],
                    'rpuid' => $row['rpuid'],
                    'realpropertyid' => $row['realpropertyid'],
                    'datacapture' => $row['datacapture'],
                    'autonumber' => $row['autonumber'],
                    'utdno' => $row['utdno'],
                    'tdno' => $row['tdno'],
                    'txntype_objid' => $row['txntype_objid'],
                    'effectivityyear' => $row['effectivityyear'],
                    'effectivityqtr' => $row['effectivityqtr'],
                    'titletype' => $row['titletype'],
                    'titleno' => $row['titleno'],
                    'titledate' => $row['titledate'],
                    'taxpayer_objid' => $row['taxpayer_objid'],
                    'owner_name' => $row['owner_name'],
                    'owner_address' => $row['owner_address'],
                    'administrator_objid' => $row['administrator_objid'],
                    'administrator_name' => $row['administrator_name'],
                    'administrator_address' => $row['administrator_address'],
                    'beneficiary_objid' => $row['beneficiary_objid'],
                    'beneficiary_name' => $row['beneficiary_name'],
                    'beneficiary_address' => $row['beneficiary_address'],
                    'memoranda' => $row['memoranda'],
                    'cancelnote' => $row['cancelnote'],
                    'restrictionid' => $row['restrictionid'],
                    'backtaxyrs' => $row['backtaxyrs'],
                    'prevtdno' => $row['prevtdno'],
                    'prevpin' => $row['prevpin'],
                    'prevowner' => $row['prevowner'],
                    'prevav' => $row['prevav'],
                    'prevmv' => $row['prevmv'],
                    'cancelreason' => $row['cancelreason'],
                    'canceldate' => $row['canceldate'],
                    'cancelledbytdnos' => $row['cancelledbytdnos'],
                    'lguid' => $row['lguid'],
                    'publicland' => $row['publicland'],
                    'txntimestamp' => $row['txntimestamp'],
                    'cancelledtimestamp' => $row['cancelledtimestamp'],
                    'name' => $row['name'],
                    'dtapproved' => $row['dtapproved'],
                    'lgutype' => $row['lgutype'],
                    'signatories' => $row['signatories'],
                    'ryordinanceno' => $row['ryordinanceno'],
                    'ryordinancedate' => $row['ryordinancedate'],
                    'prevareaha' => $row['prevareaha'],
                    'prevareasqm' => $row['prevareasqm'],
                    'fullpin' => $row['fullpin'],
                    'preveffectivity' => $row['preveffectivity'],
                    'year' => $row['year'],
                    'qtr' => $row['qtr'],
                    'month' => $row['month'],
                    'day' => $row['day'],
                    'cancelledyear' => $row['cancelledyear'],
                    'cancelledqtr' => $row['cancelledqtr'],
                    'cancelledmonth' => $row['cancelledmonth'],
                    'cancelledday' => $row['cancelledday'],
                    'prevadministrator' => $row['prevadministrator'],
                    'originlguid' => $row['originlguid'],
                    'parentfaasid' => $row['parentfaasid'],
                    'origin_type' => 'REPLICA',
                ];
                self::safe_upsert_row($wp_faas, $data, 'objid');
                $stats['faas']++;
                if ($stats['faas'] % 500 === 0) self::update_progress("Syncing FAAS ({$stats['faas']} rows)...");
            }
        } catch (Exception $e) {
            error_log("Assessor ETRACS Sync Error in FAAS: " . $e->getMessage());
        }

        // 5. Sync detail & lookup tables
        $tables_to_sync_direct = [
            'faas_previous' => 'assessor_faas_previous',
            'faas_list' => 'assessor_faas_list',
            'faas_signatory' => 'assessor_faas_signatory',
            // Building detail tables
            'bldgrpu' => 'assessor_bldgrpu',
            'bldgfloor' => 'assessor_bldgfloor',
            'bldgflooradditional' => 'assessor_bldgflooradditional',
            'bldgadditionalitem' => 'assessor_bldgadditionalitem',
            'bldguse' => 'assessor_bldguse',
            'bldgstructure' => 'assessor_bldgstructure',
            'bldgrpu_structuraltype' => 'assessor_bldgrpu_structuraltype',
            'bldgtype_depreciation' => 'assessor_bldgtype_depreciation',
            // Building lookups
            'bldgkind' => 'assessor_bldgkind',
            'bldgkindbucc' => 'assessor_bldgkindbucc',
            'bldgtype' => 'assessor_bldgtype',
            'bldgrysetting' => 'assessor_bldgrysetting',
            // Land detail tables
            'landrpu' => 'assessor_landrpu',
            'landdetail' => 'assessor_landdetail',
            // Machinery detail tables
            'machrpu' => 'assessor_machrpu',
            'machdetail' => 'assessor_machdetail',
            'machine' => 'assessor_machine',
            'machine_smv' => 'assessor_machine_smv',
            // Misc detail tables
            'miscrpu' => 'assessor_miscrpu',
            'miscitem' => 'assessor_miscitem',
            'miscrpuitem' => 'assessor_miscrpuitem',
            // Plant/Tree detail tables
            'planttreerpu' => 'assessor_planttreerpu',
            'planttree' => 'assessor_planttree',
            'planttreedetail' => 'assessor_planttreedetail',
            // Assessment level lookups
            'landassesslevel' => 'assessor_landassesslevel',
            'bldgassesslevel' => 'assessor_bldgassesslevel',
            'machassesslevel' => 'assessor_machassesslevel',
            'planttreeassesslevel' => 'assessor_planttreeassesslevel',
            'miscassesslevel' => 'assessor_miscassesslevel',
            // General lookups
            'propertyclassification' => 'assessor_propertyclassification',
            'structure' => 'assessor_structure',
            'material' => 'assessor_material',
            'barangay' => 'assessor_barangay',
            'exemptiontype' => 'assessor_exemptiontype',
            'rpumaster' => 'assessor_rpumaster',
        ];

        foreach ($tables_to_sync_direct as $etracs_table => $local_table) {
            $full_local_table = $wpdb->prefix . $local_table;
            try {
                $valid_columns = $wpdb->get_col("DESCRIBE `$full_local_table`", 0);
                if (empty($valid_columns)) continue;
                $valid_columns_flip = array_flip($valid_columns);

                $stmt = $pdo->query("SELECT * FROM `$etracs_table`");
                if ($stmt) {
                    $stats[$local_table] = 0;
                    self::update_progress("Syncing $etracs_table...");
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $filtered_row = array_intersect_key($row, $valid_columns_flip);
                        if (isset($valid_columns_flip['origin_type'])) {
                            $filtered_row['origin_type'] = 'REPLICA';
                        }
                        self::safe_upsert_row($full_local_table, $filtered_row, 'objid');

                        $stats[$local_table]++;
                        if ($stats[$local_table] % 500 === 0) self::update_progress("Syncing $etracs_table ({$stats[$local_table]} rows)...");
                    }
                }
            } catch (Exception $e) {
                // Ignore if table doesn't exist in source ETRACS
            }
        }

        // 6. Sync rpu_assessment with JOINs to get classname and actualuse
        $wp_rpu_assessment = $wpdb->prefix . 'assessor_rpu_assessment';
        try {
            $valid_columns_ra = $wpdb->get_col("DESCRIBE `$wp_rpu_assessment`", 0);
            $valid_columns_ra_flip = !empty($valid_columns_ra) ? array_flip($valid_columns_ra) : [];

            $stmt = $pdo->query("
                SELECT ra.*, 
                       pc1.code as classcode, 
                       pc1.name as classname, 
                       COALESCE(la.name, ba.name, ma.name, pa.name, mia.name, pc2.name) as actualuse
                FROM rpu_assessment ra
                LEFT JOIN propertyclassification pc1 ON pc1.objid = ra.classification_objid
                LEFT JOIN propertyclassification pc2 ON pc2.objid = ra.actualuse_objid
                LEFT JOIN landassesslevel la ON la.objid = ra.actualuse_objid
                LEFT JOIN bldgassesslevel ba ON ba.objid = ra.actualuse_objid
                LEFT JOIN machassesslevel ma ON ma.objid = ra.actualuse_objid
                LEFT JOIN planttreeassesslevel pa ON pa.objid = ra.actualuse_objid
                LEFT JOIN miscassesslevel mia ON mia.objid = ra.actualuse_objid
            ");
            if ($stmt && !empty($valid_columns_ra_flip)) {
                $stats['rpu_assessment'] = 0;
                self::update_progress("Syncing rpu_assessment...");
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $filtered_row = array_intersect_key($row, $valid_columns_ra_flip);
                    if (isset($valid_columns_ra_flip['origin_type'])) {
                        $filtered_row['origin_type'] = 'REPLICA';
                    }
                    self::safe_upsert_row($wp_rpu_assessment, $filtered_row, 'objid');
                    $stats['rpu_assessment']++;
                    if ($stats['rpu_assessment'] % 500 === 0) self::update_progress("Syncing rpu_assessment ({$stats['rpu_assessment']} rows)...");
                }
            }
        } catch (Exception $e) {
            error_log("Assessor ETRACS Sync Error in rpu_assessment: " . $e->getMessage());
        }

        update_option('assessor_etracs_last_sync', current_time('mysql', true));
        delete_option('assessor_sync_progress');

        // Log WP-07 Audit Event: Source Replica Synchronization
        $audit_logged = false;
        if (class_exists('Assessor_Etracs')) {
            $total_synced = array_sum(array_filter($stats, 'is_numeric'));
            $audit_id = Assessor_Etracs::log_shadow_audit(array(
                'event_type'            => 'SYNC_PULL',
                'action_outcome'        => 'SUCCESS',
                'source_classification' => 'SOURCE_SYNC',
                'origin_type'           => 'REPLICA',
                'object_type'           => 'SYSTEM_SYNC',
                'object_id'             => 'BATCH_' . gmdate('Ymd_His'),
                'user_id'               => 'SYSTEM_SYNC',
                'actor_name'            => 'Source ETRACS Replica Sync',
                'changed_fields'        => array_keys($stats),
                'details'               => array(
                    'total_synced_rows' => $total_synced,
                    'table_breakdown'   => $stats,
                ),
                'correlation_id'        => 'SYNC_' . wp_generate_uuid4(),
            ));
            $audit_logged = ($audit_id !== false);
        }

        if ($audit_logged) {
            self::set_diagnostics(array(
                'status'       => 'success',
                'sync_status'  => 'success',
                'audit_status' => 'success',
                'timestamp'    => current_time('mysql', true),
                'stats'        => $stats,
                'message'      => 'Synchronization and audit logging completed successfully with zero collisions.'
            ));
        } else {
            // Data sync completed and committed, but audit event could not be persisted.
            // Do NOT claim the operation was fully audited or that writes were rolled back.
            self::set_diagnostics(array(
                'status'       => 'completed_audit_failed',
                'sync_status'  => 'success',
                'audit_status' => 'failed',
                'timestamp'    => current_time('mysql', true),
                'stats'        => $stats,
                'message'      => 'Synchronization completed successfully, but recording the SYNC_PULL audit event failed.'
            ));
            $stats['_audit_status'] = 'failed';
            $stats['_audit_warning'] = 'Data synchronization succeeded, but audit event persistence failed.';
        }

        return $stats;
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
