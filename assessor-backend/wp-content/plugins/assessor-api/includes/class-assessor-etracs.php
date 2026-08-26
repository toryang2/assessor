<?php

/**
 * Assessor ETRACS Properties Module
 * 
 * Manages ETRACS-modeled FAAS (Field Appraisal & Assessment Sheet) records
 * with separate Entity, Real Property, and RPU tables following the
 * ETRACS 2.5.4 database structure.
 */
class Assessor_Etracs {

    // ──────────────────────────────────────────────
    // ENTITIES (Taxpayer / Owner)
    // ──────────────────────────────────────────────

    public function get_entities($request) {
        global $wpdb;
        $params = $request->get_params();
        $table = $wpdb->prefix . 'assessor_entity';

        $page = isset($params['page']) ? max(1, intval($params['page'])) : 1;
        $per_page = isset($params['per_page']) ? min(100, max(1, intval($params['per_page']))) : 20;
        $offset = ($page - 1) * $per_page;

        $where = array("1=1");
        $values = array();

        if (!empty($params['q'])) {
            $like = '%' . $wpdb->esc_like($params['q']) . '%';
            $where[] = "(e.name LIKE %s OR e.address_text LIKE %s)";
            $values[] = $like;
            $values[] = $like;
        }

        $where_sql = implode(' AND ', $where);
        $count_query = "SELECT COUNT(*) FROM $table e WHERE $where_sql";
        $total = !empty($values) ? $wpdb->get_var($wpdb->prepare($count_query, $values)) : $wpdb->get_var($count_query);

        $sql = "SELECT e.*, e.name AS entity_name, e.address_text AS entity_address, e.type AS entity_type FROM $table e WHERE $where_sql ORDER BY e.name ASC LIMIT %d OFFSET %d";
        $query_values = array_merge($values, array($per_page, $offset));
        $results = $wpdb->get_results($wpdb->prepare($sql, $query_values));

        return rest_ensure_response(array(
            'data' => $results,
            'total' => intval($total),
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => ceil(intval($total) / $per_page)
        ));
    }

    public function create_entity($request) {
        global $wpdb;
        $params = $request->get_params();
        $table = $wpdb->prefix . 'assessor_entity';

        if (empty($params['entity_name'])) {
            return new WP_Error('missing_field', "Field 'entity_name' is required", array('status' => 400));
        }

        $objid = wp_generate_uuid4();
        
        $data = array(
            'objid' => $objid,
            'entityno' => 'ENT-' . time(), // Basic generation for now
            'name' => sanitize_text_field($params['entity_name']),
            'entityname' => sanitize_text_field($params['entity_name']),
            'address_text' => sanitize_textarea_field($params['entity_address'] ?? ''),
            'type' => sanitize_text_field($params['entity_type'] ?? 'INDIVIDUAL'),
        );

        $result = $wpdb->insert($table, $data);
        if ($result === false) {
            return new WP_Error('insert_failed', 'Failed to create entity', array('status' => 500));
        }

        $entity = $wpdb->get_row($wpdb->prepare("SELECT e.*, e.name AS entity_name, e.address_text AS entity_address, e.type AS entity_type FROM $table e WHERE objid = %s", $objid));
        return rest_ensure_response($entity);
    }

    public function update_entity($id, $request) {
        global $wpdb;
        $params = $request->get_params();
        $table = $wpdb->prefix . 'assessor_entity';

        $data = array();
        if (isset($params['entity_name'])) {
            $data['name'] = sanitize_text_field($params['entity_name']);
            $data['entityname'] = sanitize_text_field($params['entity_name']);
        }
        if (isset($params['entity_address'])) {
            $data['address_text'] = sanitize_textarea_field($params['entity_address']);
        }
        if (isset($params['entity_type'])) {
            $data['type'] = sanitize_text_field($params['entity_type']);
        }

        if (empty($data)) {
            return new WP_Error('no_data', 'No data provided to update', array('status' => 400));
        }

        $wpdb->update($table, $data, array('objid' => $id));
        $entity = $wpdb->get_row($wpdb->prepare("SELECT e.*, e.name AS entity_name, e.address_text AS entity_address, e.type AS entity_type FROM $table e WHERE objid = %s", $id));
        return rest_ensure_response($entity);
    }

    public function delete_entity($id, $request) {
        global $wpdb;
        $table = $wpdb->prefix . 'assessor_entity';

        // Check if entity is used in any FAAS record
        $faas_table = $wpdb->prefix . 'assessor_faas';
        $in_use = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $faas_table WHERE taxpayer_objid = %s AND state != 'DELETED'", $id));
        
        if ($in_use > 0) {
            return new WP_Error('entity_in_use', 'Cannot delete entity because it is used in active FAAS records', array('status' => 400));
        }

        $wpdb->delete($table, array('objid' => $id), array('%s'));
        return rest_ensure_response(array('success' => true, 'message' => 'Entity deleted successfully'));
    }

    // ──────────────────────────────────────────────
    // TRANSACTION TYPES
    // ──────────────────────────────────────────────

    public function get_transaction_types($request) {
        global $wpdb;
        $table = $wpdb->prefix . 'assessor_faas_txntypes';
        $results = $wpdb->get_results("SELECT * FROM $table WHERE status = 'active' ORDER BY sort_order ASC, name ASC");
        return rest_ensure_response($results);
    }

    // ──────────────────────────────────────────────
    // FAAS LISTING (main list view)
    // ──────────────────────────────────────────────

    public function get_faas_list($request) {
        global $wpdb;
        $params = $request->get_params();

        $t_faas = $wpdb->prefix . 'assessor_faas';
        $t_rpu = $wpdb->prefix . 'assessor_rpu';
        $t_rp = $wpdb->prefix . 'assessor_real_property';
        $t_entity = $wpdb->prefix . 'assessor_entity';
        $t_txn = $wpdb->prefix . 'assessor_faas_txntypes';

        $page = isset($params['page']) ? max(1, intval($params['page'])) : 1;
        $per_page = isset($params['per_page']) ? min(100, max(1, intval($params['per_page']))) : 20;
        $offset = ($page - 1) * $per_page;

        $where = array("f.state != 'DELETED'");
        $values = array();

        // Free-text search
        if (!empty($params['q'])) {
            $like = '%' . $wpdb->esc_like($params['q']) . '%';
            $where[] = "(f.tdno LIKE %s OR f.owner_name LIKE %s OR e.name LIKE %s OR rp.cadastrallotno LIKE %s OR f.fullpin LIKE %s)";
            $values = array_merge($values, array($like, $like, $like, $like, $like));
        }

        // Specific filters
        if (!empty($params['state'])) {
            $where[] = "f.state = %s";
            $values[] = strtoupper($params['state']);
        }
        if (!empty($params['rpu_type'])) {
            $where[] = "r.rpu_type = %s";
            $values[] = strtoupper($params['rpu_type']);
        }
        if (!empty($params['txntype'])) {
            $where[] = "f.txntype_objid = %s";
            $values[] = $params['txntype'];
        }
        if (!empty($params['barangay'])) {
            $where[] = "rp.barangay LIKE %s";
            $values[] = '%' . $wpdb->esc_like($params['barangay']) . '%';
        }
        if (!empty($params['classification'])) {
            $where[] = "r.classification = %s";
            $values[] = $params['classification'];
        }
        if (!empty($params['tdno'])) {
            $where[] = "f.tdno LIKE %s";
            $values[] = '%' . $wpdb->esc_like($params['tdno']) . '%';
        }
        if (!empty($params['owner_name'])) {
            $where[] = "(f.owner_name LIKE %s OR e.name LIKE %s)";
            $like = '%' . $wpdb->esc_like($params['owner_name']) . '%';
            $values[] = $like;
            $values[] = $like;
        }

        $where_sql = implode(' AND ', $where);

        $t_rpu_assessment = $wpdb->prefix . 'assessor_rpu_assessment';
        $t_faas_previous = $wpdb->prefix . 'assessor_faas_previous';
        $joins = "
            LEFT JOIN $t_rpu r ON f.rpuid = r.objid
            LEFT JOIN $t_rp rp ON f.realpropertyid = rp.objid
            LEFT JOIN $t_entity e ON f.taxpayer_objid = e.objid
            LEFT JOIN $t_txn tx ON f.txntype_objid = tx.code
            LEFT JOIN (SELECT rpuid, GROUP_CONCAT(DISTINCT actualuse SEPARATOR ', ') as actualuse FROM $t_rpu_assessment GROUP BY rpuid) ra ON r.objid = ra.rpuid
            LEFT JOIN (
                SELECT 
                    faasid,
                    GROUP_CONCAT(prevtdno SEPARATOR ' | ') as fp_prevtdno,
                    GROUP_CONCAT(prevpin SEPARATOR ' | ') as fp_prevpin,
                    GROUP_CONCAT(prevowner SEPARATOR ' | ') as fp_prevowner,
                    GROUP_CONCAT(prevav SEPARATOR ' | ') as fp_prevav,
                    GROUP_CONCAT(prevmv SEPARATOR ' | ') as fp_prevmv,
                    GROUP_CONCAT(prevareasqm SEPARATOR ' | ') as fp_prevareasqm,
                    GROUP_CONCAT(prevareaha SEPARATOR ' | ') as fp_prevareaha,
                    GROUP_CONCAT(prevadministrator SEPARATOR ' | ') as fp_prevadministrator
                FROM $t_faas_previous
                GROUP BY faasid
            ) fp ON f.objid = fp.faasid
        ";

        // Count
        $count_sql = "SELECT COUNT(*) FROM $t_faas f $joins WHERE $where_sql";
        $total = !empty($values) ? $wpdb->get_var($wpdb->prepare($count_sql, $values)) : $wpdb->get_var($count_sql);

        // Build ORDER BY
        $order_by = 'f.txntimestamp DESC';
        if (!empty($params['order_by'])) {
            $allowed = array('tdno', 'owner_name', 'total_assessed_value', 'created_at', 'effectivity_year', 'txntimestamp');
            $field = $params['order_by'];
            if ($field === 'created_at') $field = 'txntimestamp';
            
            if (in_array($field, $allowed)) {
                $dir = (!empty($params['order_direction']) && strtoupper($params['order_direction']) === 'ASC') ? 'ASC' : 'DESC';
                if ($field === 'total_assessed_value') {
                    $order_by = "r.$field $dir";
                } else {
                    $order_by = "f.$field $dir";
                }
            }
        }

        $select_sql = "
            SELECT
                f.*,
                f.objid AS id,
                f.rpuid AS rpu_id,
                f.realpropertyid AS real_property_id,
                f.taxpayer_objid AS taxpayer_id,
                f.effectivityyear AS effectivity_year,
                f.effectivityqtr AS effectivity_qtr,
                f.txntype_objid AS txntype_code,
                f.titletype AS title_type,
                f.titleno AS title_no,
                f.titledate AS title_date,
                r.rpu_type,
                r.classification,
                r.total_market_value,
                r.total_assessed_value,
                r.ry AS revision_year,
                rp.pin AS rp_pin,
                rp.cadastrallotno AS cadastral_lot_no,
                rp.surveyno AS survey_no,
                rp.blockno AS block_no,
                rp.barangay,
                e.name AS taxpayer_name,
                e.address_text AS taxpayer_address,
                tx.name AS txntype_name,
                r.taxable,
                ra.actualuse,
                COALESCE(NULLIF(fp.fp_prevtdno, ''), f.prevtdno) AS prevtdno,
                COALESCE(NULLIF(fp.fp_prevpin, ''), f.prevpin) AS prev_pin,
                COALESCE(NULLIF(fp.fp_prevowner, ''), f.prevowner) AS prev_owner,
                COALESCE(NULLIF(fp.fp_prevav, ''), f.prevav) AS prev_assessed_value,
                COALESCE(NULLIF(fp.fp_prevmv, ''), f.prevmv) AS prev_market_value,
                COALESCE(NULLIF(fp.fp_prevareasqm, ''), f.prevareasqm) AS prev_area_sqm,
                COALESCE(NULLIF(fp.fp_prevareaha, ''), f.prevareaha) AS prev_area_hectare,
                COALESCE(NULLIF(fp.fp_prevadministrator, ''), f.prevadministrator) AS prev_administrator,
                f.originlguid,
                f.state,
                f.cancelledbytdnos AS cancelled_by_tdnos,
                f.canceldate AS cancel_date,
                f.cancelledyear AS cancelled_year,
                (SELECT f2.fullpin FROM $t_faas f2 WHERE f2.tdno = f.cancelledbytdnos LIMIT 1) AS cancelled_by_pin
            FROM $t_faas f
            $joins
            WHERE $where_sql
            ORDER BY $order_by
            LIMIT %d OFFSET %d
        ";

        $query_values = array_merge($values, array($per_page, $offset));
        $results = $wpdb->get_results($wpdb->prepare($select_sql, $query_values));

        return rest_ensure_response(array(
            'data' => $results,
            'total' => intval($total),
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => ceil(intval($total) / $per_page)
        ));
    }

    // ──────────────────────────────────────────────
    // FAAS SINGLE RECORD
    // ──────────────────────────────────────────────

    public function get_faas($id) {
        global $wpdb;

        $t_faas = $wpdb->prefix . 'assessor_faas';
        $t_rpu = $wpdb->prefix . 'assessor_rpu';
        $t_rp = $wpdb->prefix . 'assessor_real_property';
        $t_entity = $wpdb->prefix . 'assessor_entity';
        $t_txn = $wpdb->prefix . 'assessor_faas_txntypes';
        $t_faas_previous = $wpdb->prefix . 'assessor_faas_previous';

        $sql = "
            SELECT
                f.*,
                f.objid AS id,
                f.rpuid AS rpu_id,
                f.realpropertyid AS real_property_id,
                f.taxpayer_objid AS taxpayer_id,
                f.effectivityyear AS effectivity_year,
                f.effectivityqtr AS effectivity_qtr,
                f.txntype_objid AS txntype_code,
                f.titletype AS title_type,
                f.titleno AS title_no,
                f.titledate AS title_date,
                r.rpu_type,
                r.classification,
                r.total_market_value,
                r.total_assessed_value,
                r.total_area_sqm,
                r.total_area_hectare,
                r.ry AS revision_year,
                f.state,
                rp.pin AS rp_pin,
                rp.cadastrallotno AS cadastral_lot_no,
                rp.surveyno AS survey_no,
                rp.blockno AS block_no,
                rp.barangay,
                rp.north,
                rp.south,
                rp.east,
                rp.west,
                e.name AS taxpayer_name,
                e.address_text AS taxpayer_address,
                e.type AS taxpayer_type,
                tx.name AS txntype_name,
                r.taxable,
                COALESCE(NULLIF(fp.fp_prevtdno, ''), f.prevtdno) AS prevtdno,
                COALESCE(NULLIF(fp.fp_prevpin, ''), f.prevpin) AS prev_pin,
                COALESCE(NULLIF(fp.fp_prevowner, ''), f.prevowner) AS prev_owner,
                COALESCE(NULLIF(fp.fp_prevav, ''), f.prevav) AS prev_assessed_value,
                COALESCE(NULLIF(fp.fp_prevmv, ''), f.prevmv) AS prev_market_value,
                COALESCE(NULLIF(fp.fp_prevareasqm, ''), f.prevareasqm) AS prev_area_sqm,
                COALESCE(NULLIF(fp.fp_prevareaha, ''), f.prevareaha) AS prev_area_hectare,
                COALESCE(NULLIF(fp.fp_prevadministrator, ''), f.prevadministrator) AS prev_administrator,
                f.originlguid,
                f.cancelledbytdnos AS cancelled_by_tdnos,
                f.canceldate AS cancel_date,
                f.cancelledyear AS cancelled_year,
                (SELECT f2.fullpin FROM $t_faas f2 WHERE f2.tdno = f.cancelledbytdnos LIMIT 1) AS cancelled_by_pin
            FROM $t_faas f
            LEFT JOIN $t_rpu r ON f.rpuid = r.objid
            LEFT JOIN $t_rp rp ON f.realpropertyid = rp.objid
            LEFT JOIN $t_entity e ON f.taxpayer_objid = e.objid
            LEFT JOIN $t_txn tx ON f.txntype_objid = tx.code
            LEFT JOIN (
                SELECT 
                    faasid,
                    GROUP_CONCAT(prevtdno SEPARATOR ' | ') as fp_prevtdno,
                    GROUP_CONCAT(prevpin SEPARATOR ' | ') as fp_prevpin,
                    GROUP_CONCAT(prevowner SEPARATOR ' | ') as fp_prevowner,
                    GROUP_CONCAT(prevav SEPARATOR ' | ') as fp_prevav,
                    GROUP_CONCAT(prevmv SEPARATOR ' | ') as fp_prevmv,
                    GROUP_CONCAT(prevareasqm SEPARATOR ' | ') as fp_prevareasqm,
                    GROUP_CONCAT(prevareaha SEPARATOR ' | ') as fp_prevareaha,
                    GROUP_CONCAT(prevadministrator SEPARATOR ' | ') as fp_prevadministrator
                FROM $t_faas_previous
                GROUP BY faasid
            ) fp ON f.objid = fp.faasid
            WHERE f.objid = %s
        ";

        $record = $wpdb->get_row($wpdb->prepare($sql, $id));
        if (!$record) {
            return new WP_Error('not_found', 'FAAS record not found', array('status' => 404));
        }

        // Dynamic fallback lookup for previous FAAS details if empty/missing
        if (!empty($record->prevtdno) && $record->prevtdno !== '-') {
            $has_empty_prev = empty($record->prev_owner) || $record->prev_owner === '-' ||
                              empty($record->prev_pin) || $record->prev_pin === '-' ||
                              empty($record->prev_assessed_value) || floatval($record->prev_assessed_value) == 0;

            if ($has_empty_prev) {
                $prev_record = $wpdb->get_row($wpdb->prepare("
                    SELECT 
                        f.owner_name AS prev_owner,
                        f.administrator_name AS prev_administrator,
                        rp.pin AS prev_pin,
                        r.total_market_value AS prev_market_value,
                        r.total_assessed_value AS prev_assessed_value,
                        r.total_area_sqm AS prev_area_sqm,
                        r.total_area_hectare AS prev_area_hectare
                    FROM {$wpdb->prefix}assessor_faas f
                    LEFT JOIN {$wpdb->prefix}assessor_rpu r ON f.rpuid = r.objid
                    LEFT JOIN {$wpdb->prefix}assessor_real_property rp ON f.realpropertyid = rp.objid
                    WHERE f.tdno = %s
                    LIMIT 1
                ", $record->prevtdno));

                if ($prev_record) {
                    if (empty($record->prev_owner) || $record->prev_owner === '-') $record->prev_owner = $prev_record->prev_owner;
                    if (empty($record->prev_administrator) || $record->prev_administrator === '-') $record->prev_administrator = $prev_record->prev_administrator;
                    if (empty($record->prev_pin) || $record->prev_pin === '-') $record->prev_pin = $prev_record->prev_pin;
                    if (empty($record->prev_market_value) || floatval($record->prev_market_value) == 0) $record->prev_market_value = $prev_record->prev_market_value;
                    if (empty($record->prev_assessed_value) || floatval($record->prev_assessed_value) == 0) $record->prev_assessed_value = $prev_record->prev_assessed_value;
                    if (empty($record->prev_area_sqm) || floatval($record->prev_area_sqm) == 0) $record->prev_area_sqm = $prev_record->prev_area_sqm;
                    if (empty($record->prev_area_hectare) || floatval($record->prev_area_hectare) == 0) $record->prev_area_hectare = $prev_record->prev_area_hectare;
                }
            }
        }

        return $record;
    }

    // ──────────────────────────────────────────────
    // FAAS CREATE
    // ──────────────────────────────────────────────

    public function create_faas($request) {
        global $wpdb;
        $params = $request->get_params();
        $auth = new Assessor_Auth();
        $user_id = $auth->get_user_id_from_token($request);

        // Validate required
        $required = array('tdno', 'owner_name');
        foreach ($required as $field) {
            if (empty($params[$field])) {
                return new WP_Error('missing_field', "Field '$field' is required", array('status' => 400));
            }
        }

        // Check duplicate TD
        $t_faas = $wpdb->prefix . 'assessor_faas';
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT objid FROM $t_faas WHERE tdno = %s AND state != 'DELETED'",
            $params['tdno']
        ));
        if ($existing) {
            return new WP_Error('duplicate_tdno', 'Tax Declaration Number already exists', array('status' => 400));
        }

        // 1. Upsert Entity (taxpayer)
        $taxpayer_id = $this->upsert_entity($params);

        // 2. Create Real Property
        $real_property_id = $this->create_real_property($params);

        // 3. Create RPU
        $rpu_id = $this->create_rpu($params, $real_property_id);

        // 4. Create FAAS
        $faas_objid = wp_generate_uuid4();
        $faas_data = array(
            'objid'                 => $faas_objid,
            'state'                 => 'CURRENT',
            'rpuid'                 => $rpu_id,
            'realpropertyid'        => $real_property_id,
            'taxpayer_objid'        => $taxpayer_id,
            'tdno'                  => sanitize_text_field($params['tdno']),
            'utdno'                 => !empty($params['utdno']) ? sanitize_text_field($params['utdno']) : sanitize_text_field($params['tdno']),
            'txntype_objid'         => sanitize_text_field($params['txntype_code'] ?? 'GR'),
            'effectivityyear'       => intval($params['effectivity_year'] ?? 0),
            'effectivityqtr'        => intval($params['effectivity_qtr'] ?? 0),
            'owner_name'            => sanitize_text_field($params['owner_name']),
            'owner_address'         => sanitize_textarea_field($params['owner_address'] ?? ''),
            'administrator_name'    => sanitize_text_field($params['administrator_name'] ?? ''),
            'administrator_address' => sanitize_text_field($params['administrator_address'] ?? ''),
            'beneficiary_name'      => sanitize_text_field($params['beneficiary_name'] ?? ''),
            'beneficiary_address'   => sanitize_text_field($params['beneficiary_address'] ?? ''),
            'fullpin'               => sanitize_text_field($params['pin'] ?? ''),
            'titletype'             => sanitize_text_field($params['title_type'] ?? ''),
            'titleno'               => sanitize_text_field($params['title_no'] ?? ''),
            'titledate'             => !empty($params['title_date']) ? $params['title_date'] : null,
            'prevtdno'              => sanitize_text_field($params['prevtdno'] ?? ''),
            'prevowner'             => sanitize_text_field($params['prev_owner'] ?? ''),
            'prevav'                => sanitize_text_field($params['prev_assessed_value'] ?? ''),
            'prevmv'                => sanitize_text_field($params['prev_market_value'] ?? ''),
            'prevareaha'            => sanitize_text_field($params['prev_area_hectare'] ?? ''),
            'prevareasqm'           => sanitize_text_field($params['prev_area_sqm'] ?? ''),
            'preveffectivity'       => sanitize_text_field($params['prev_effectivity'] ?? ''),
            'memoranda'             => sanitize_textarea_field($params['memoranda'] ?? ''),
            'backtaxyrs'            => intval($params['back_tax_years'] ?? 0),
            'ryordinanceno'         => sanitize_text_field($params['ry_ordinance_no'] ?? ''),
            'ryordinancedate'       => !empty($params['ry_ordinance_date']) ? $params['ry_ordinance_date'] : null,
            'dtapproved'            => !empty($params['date_approved']) ? $params['date_approved'] : null,
            'year'                  => !empty($params['year_issued']) ? intval($params['year_issued']) : null,
            'publicland'            => intval($params['public_land'] ?? 0),
            'txntimestamp'          => current_time('mysql')
        );

        $result = $wpdb->insert($t_faas, $faas_data);
        if ($result === false) {
            return new WP_Error('insert_failed', 'Failed to create FAAS record: ' . $wpdb->last_error, array('status' => 500));
        }

        // Cancel previous TD if provided
        if (!empty($params['prevtdno'])) {
            $prev_td = sanitize_text_field($params['prevtdno']);
            $wpdb->query($wpdb->prepare(
                "UPDATE $t_faas SET state = 'CANCELLED', canceldate = %s, cancelledtimestamp = %s, cancelledbytdnos = %s WHERE tdno = %s AND state != 'CANCELLED'",
                current_time('mysql'),
                current_time('mysql'),
                $faas_data['tdno'],
                $prev_td
            ));
        }

        return rest_ensure_response($this->get_faas($faas_objid));
    }

    // ──────────────────────────────────────────────
    // FAAS UPDATE
    // ──────────────────────────────────────────────

    public function update_faas($id, $request) {
        global $wpdb;
        $params = $request->get_params();
        $auth = new Assessor_Auth();
        $user_id = $auth->get_user_id_from_token($request);

        $t_faas = $wpdb->prefix . 'assessor_faas';
        $current = $this->get_faas($id);
        if (is_wp_error($current)) return $current;

        // Check duplicate TD if changing
        if (isset($params['tdno'])) {
            $new_td = sanitize_text_field($params['tdno']);
            $dup = $wpdb->get_var($wpdb->prepare(
                "SELECT objid FROM $t_faas WHERE tdno = %s AND objid != %s AND state != 'DELETED'",
                $new_td,
                $id
            ));
            if ($dup) {
                return new WP_Error('duplicate_tdno', 'Tax Declaration Number already exists', array('status' => 400));
            }
        }

        // Update Entity if name/address changed
        if (isset($params['owner_name']) || isset($params['owner_address'])) {
            $this->upsert_entity($params, $current->taxpayer_id);
        }

        // Update Real Property
        if ($current->real_property_id) {
            $this->update_real_property($current->real_property_id, $params);
        }

        // Update RPU
        if ($current->rpu_id) {
            $this->update_rpu($current->rpu_id, $params);
        }

        // Map frontend fields to DB columns
        $field_map = array(
            'tdno'                  => 'tdno',
            'utdno'                 => 'utdno',
            'txntype_code'          => 'txntype_objid',
            'effectivity_year'      => 'effectivityyear',
            'effectivity_qtr'       => 'effectivityqtr',
            'owner_name'            => 'owner_name',
            'owner_address'         => 'owner_address',
            'administrator_name'    => 'administrator_name',
            'administrator_address' => 'administrator_address',
            'beneficiary_name'      => 'beneficiary_name',
            'beneficiary_address'   => 'beneficiary_address',
            'pin'                   => 'fullpin',
            'title_type'            => 'titletype',
            'title_no'              => 'titleno',
            'title_date'            => 'titledate',
            'prevtdno'              => 'prevtdno',
            'prev_owner'            => 'prevowner',
            'prev_assessed_value'   => 'prevav',
            'prev_market_value'     => 'prevmv',
            'prev_area_hectare'     => 'prevareaha',
            'prev_area_sqm'         => 'prevareasqm',
            'prev_effectivity'      => 'preveffectivity',
            'memoranda'             => 'memoranda',
            'back_tax_years'        => 'backtaxyrs',
            'ry_ordinance_no'       => 'ryordinanceno',
            'ry_ordinance_date'     => 'ryordinancedate',
            'date_approved'         => 'dtapproved',
            'year_issued'           => 'year',
            'public_land'           => 'publicland',
            'state'                 => 'state',
            'cancel_reason'         => 'cancelreason',
            'cancel_note'           => 'cancelnote',
            'cancel_date'           => 'canceldate',
            'cancelled_by_tdnos'    => 'cancelledbytdnos'
        );

        $update_data = array();
        // Since ETRACS schema doesn't have an updated_by column, skip it.
        // Or if it does, add it. The schema given by user does not have updated_by.

        foreach ($field_map as $param_key => $db_col) {
            if (!array_key_exists($param_key, $params)) continue;
            
            $val = $params[$param_key];
            
            if (in_array($param_key, array('effectivity_year', 'effectivity_qtr', 'back_tax_years', 'public_land', 'year_issued'))) {
                $update_data[$db_col] = !empty($val) ? intval($val) : 0;
            } elseif (in_array($param_key, array('title_date', 'ry_ordinance_date', 'date_approved', 'cancel_date'))) {
                $update_data[$db_col] = !empty($val) ? $val : null;
            } else {
                $update_data[$db_col] = sanitize_text_field($val);
            }
        }

        if (isset($update_data['utdno']) && empty($update_data['utdno'])) {
            $update_data['utdno'] = $update_data['tdno'] ?? $current->tdno;
        }

        $wpdb->update($t_faas, $update_data, array('objid' => $id));

        // Cancel previous TD if provided/updated
        if (!empty($params['prevtdno'])) {
            $prev_td = sanitize_text_field($params['prevtdno']);
            $new_td = $update_data['tdno'] ?? $current->tdno;
            $wpdb->query($wpdb->prepare(
                "UPDATE $t_faas SET state = 'CANCELLED', canceldate = %s, cancelledtimestamp = %s, cancelledbytdnos = %s WHERE tdno = %s AND state != 'CANCELLED'",
                current_time('mysql'),
                current_time('mysql'),
                $new_td,
                $prev_td
            ));
        }

        return rest_ensure_response($this->get_faas($id));
    }

    // ──────────────────────────────────────────────
    // FAAS DELETE (soft delete → DELETED state)
    // ──────────────────────────────────────────────

    public function delete_faas($id, $request) {
        global $wpdb;
        $t_faas = $wpdb->prefix . 'assessor_faas';

        $current = $this->get_faas($id);
        if (is_wp_error($current)) return $current;

        $wpdb->update($t_faas, array('state' => 'DELETED'), array('objid' => $id));

        return rest_ensure_response(array('success' => true, 'message' => 'FAAS record deleted'));
    }

    // ──────────────────────────────────────────────
    // FAAS CANCEL
    // ──────────────────────────────────────────────

    public function cancel_faas($id, $request) {
        global $wpdb;
        $params = $request->get_params();
        $t_faas = $wpdb->prefix . 'assessor_faas';

        $current = $this->get_faas($id);
        if (is_wp_error($current)) return $current;

        if ($current->state !== 'CURRENT') {
            return new WP_Error('invalid_state', 'Only CURRENT records can be cancelled', array('status' => 400));
        }

        $update = array(
            'state'             => 'CANCELLED',
            'cancelreason'      => sanitize_text_field($params['cancel_reason'] ?? ''),
            'canceldate'        => !empty($params['cancel_date']) ? $params['cancel_date'] : current_time('mysql'),
            'cancelnote'        => sanitize_text_field($params['cancel_note'] ?? ''),
            'cancelledbytdnos'  => sanitize_text_field($params['cancelled_by_tdnos'] ?? ''),
            'cancelledtimestamp'=> current_time('mysql'),
        );

        $wpdb->update($t_faas, $update, array('objid' => $id));
        return rest_ensure_response($this->get_faas($id));
    }

    // ──────────────────────────────────────────────
    // DASHBOARD / STATS
    // ──────────────────────────────────────────────

    public function get_faas_stats($request) {
        global $wpdb;
        $t_faas = $wpdb->prefix . 'assessor_faas';
        $t_rpu = $wpdb->prefix . 'assessor_rpu';

        $total = $wpdb->get_var("SELECT COUNT(*) FROM $t_faas WHERE state != 'DELETED'");
        $current = $wpdb->get_var("SELECT COUNT(*) FROM $t_faas WHERE state = 'CURRENT'");
        $cancelled = $wpdb->get_var("SELECT COUNT(*) FROM $t_faas WHERE state = 'CANCELLED'");

        $by_type = $wpdb->get_results("
            SELECT r.rpu_type, COUNT(*) as count
            FROM $t_faas f
            LEFT JOIN $t_rpu r ON f.rpuid = r.objid
            WHERE f.state != 'DELETED'
            GROUP BY r.rpu_type
        ");

        return rest_ensure_response(array(
            'total' => intval($total),
            'current' => intval($current),
            'cancelled' => intval($cancelled),
            'by_type' => $by_type
        ));
    }

    // ──────────────────────────────────────────────
    // PRIVATE HELPERS
    // ──────────────────────────────────────────────

    private function upsert_entity($params, $existing_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'assessor_entity';

        $name = sanitize_text_field($params['owner_name'] ?? $params['entity_name'] ?? '');
        $address = sanitize_textarea_field($params['owner_address'] ?? $params['entity_address'] ?? '');
        $type = sanitize_text_field($params['entity_type'] ?? 'INDIVIDUAL');

        if ($existing_id) {
            $wpdb->update($table, array(
                'name' => $name,
                'entityname' => $name,
                'address_text' => $address,
            ), array('objid' => $existing_id));
            return $existing_id;
        }

        // Try to find by exact name match
        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT objid FROM $table WHERE entityname = %s LIMIT 1",
            $name
        ));
        if ($found) return $found;

        // Create new
        $objid = wp_generate_uuid4();
        $wpdb->insert($table, array(
            'objid' => $objid,
            'entityno' => 'ENT-' . time(),
            'name' => $name,
            'entityname' => $name,
            'address_text' => $address,
            'type' => $type,
        ));

        return $objid;
    }

    private function create_real_property($params) {
        global $wpdb;
        $table = $wpdb->prefix . 'assessor_real_property';

        $data = array(
            'pin'              => sanitize_text_field($params['pin'] ?? ''),
            'cadastrallotno'  => sanitize_text_field($params['cadastral_lot_no'] ?? $params['lot_number'] ?? ''),
            'surveyno'        => sanitize_text_field($params['survey_no'] ?? ''),
            'blockno'         => sanitize_text_field($params['block_no'] ?? ''),
            'barangay'         => sanitize_text_field($params['barangay'] ?? ''),
            'municipality'     => sanitize_text_field($params['municipality'] ?? ''),
            'province'         => sanitize_text_field($params['province'] ?? ''),
            // 'total_area_hectare' => isset($params['total_area_hectare']) && $params['total_area_hectare'] !== '' ? floatval($params['total_area_hectare']) : null,
            // 'total_area_sqm'     => isset($params['total_area_sqm']) && $params['total_area_sqm'] !== '' ? floatval($params['total_area_sqm']) : null,
            'north'            => sanitize_text_field($params['north'] ?? ''),
            'south'            => sanitize_text_field($params['south'] ?? ''),
            'east'             => sanitize_text_field($params['east'] ?? ''),
            'west'             => sanitize_text_field($params['west'] ?? ''),
        );

        $wpdb->insert($table, $data);
        return $wpdb->insert_id;
    }

    private function update_real_property($id, $params) {
        global $wpdb;
        $table = $wpdb->prefix . 'assessor_real_property';

        $fields = array('pin', 'cadastrallotno', 'surveyno', 'blockno', 'barangay', 'municipality', 'province', 'north', 'south', 'east', 'west');
        $update = array();
        foreach ($fields as $f) {
            $param_key = $f;
            if ($f === 'cadastrallotno' && !isset($params[$f]) && isset($params['lot_number'])) $param_key = 'lot_number';
            if ($f === 'cadastrallotno' && !isset($params[$f]) && isset($params['cadastral_lot_no'])) $param_key = 'cadastral_lot_no';
            if ($f === 'surveyno' && !isset($params[$f]) && isset($params['survey_no'])) $param_key = 'survey_no';
            if ($f === 'blockno' && !isset($params[$f]) && isset($params['block_no'])) $param_key = 'block_no';
            if (isset($params[$param_key])) {
                $update[$f] = sanitize_text_field($params[$param_key]);
            }
        }
        // Numeric area fields removed from schema
        // foreach (array('total_area_hectare', 'total_area_sqm') as $af) {
        //     if (array_key_exists($af, $params)) {
        //         $update[$af] = $params[$af] !== '' && $params[$af] !== null ? floatval($params[$af]) : null;
        //     }
        // }

        if (!empty($update)) {
            $wpdb->update($table, $update, array('id' => $id));
        }
    }

    private function create_rpu($params, $real_property_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'assessor_rpu';

        $data = array(
            'real_property_id'     => $real_property_id,
            'rpu_type'             => strtoupper(sanitize_text_field($params['rpu_type'] ?? 'LAND')),
            'classification'       => sanitize_text_field($params['classification'] ?? ''),
            'ry'                   => intval($params['ry'] ?? $params['revision_year'] ?? 0),
            'total_market_value'   => isset($params['total_market_value']) && $params['total_market_value'] !== '' ? floatval($params['total_market_value']) : 0,
            'total_assessed_value' => isset($params['total_assessed_value']) && $params['total_assessed_value'] !== '' ? floatval($params['total_assessed_value']) : 0,
            'taxable'              => intval($params['taxable'] ?? 1),
        );

        $wpdb->insert($table, $data);
        return $wpdb->insert_id;
    }

    private function update_rpu($id, $params) {
        global $wpdb;
        $table = $wpdb->prefix . 'assessor_rpu';

        $update = array();
        if (isset($params['rpu_type'])) $update['rpu_type'] = strtoupper(sanitize_text_field($params['rpu_type']));
        if (isset($params['classification'])) $update['classification'] = sanitize_text_field($params['classification']);
        if (isset($params['ry']) || isset($params['revision_year'])) $update['ry'] = intval($params['ry'] ?? $params['revision_year']);
        if (array_key_exists('total_market_value', $params)) $update['total_market_value'] = floatval($params['total_market_value']);
        if (array_key_exists('total_assessed_value', $params)) $update['total_assessed_value'] = floatval($params['total_assessed_value']);
        if (isset($params['taxable'])) $update['taxable'] = intval($params['taxable']);

        if (!empty($update)) {
            $wpdb->update($table, $update, array('id' => $id));
        }
    }

    public function get_barangays() {
        global $wpdb;
        $table = $wpdb->prefix . 'assessor_barangay';
        return $wpdb->get_results("SELECT objid, name FROM $table ORDER BY name ASC");
    }

    public function get_exemption_types() {
        global $wpdb;
        $table = $wpdb->prefix . 'assessor_exemptiontype';
        return $wpdb->get_results("SELECT objid, name FROM $table ORDER BY name ASC");
    }

    public function get_classifications() {
        global $wpdb;
        $table = $wpdb->prefix . 'assessor_propertyclassification';
        return $wpdb->get_results("SELECT objid, code, name, special, orderno, state FROM $table ORDER BY orderno ASC, name ASC");
    }

    public function get_faas_signatory($id) {
        global $wpdb;
        $table = $wpdb->prefix . 'assessor_faas_signatory';
        $record = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE objid = %s", $id));
        if (!$record) {
            return array();
        }
        return $record;
    }

    public function get_building_lookups($request = null) {
        global $wpdb;
        $results = array();
        
        $t_kind = $wpdb->prefix . 'assessor_bldgkind';
        $t_type = $wpdb->prefix . 'assessor_bldgtype';
        $t_bucc = $wpdb->prefix . 'assessor_bldgkindbucc';
        $t_use = $wpdb->prefix . 'assessor_bldguse';
        $t_material = $wpdb->prefix . 'assessor_material';
        $t_class = $wpdb->prefix . 'assessor_propertyclassification';
        $t_rysetting = $wpdb->prefix . 'assessor_bldgrysetting';
        
        $ry = null;
        if (is_array($request) && !empty($request['ry'])) {
            $ry = intval($request['ry']);
        } elseif (is_object($request) && method_exists($request, 'get_param') && $request->get_param('ry')) {
            $ry = intval($request->get_param('ry'));
        }

        $ry_setting_id = null;
        if ($ry) {
            $ry_setting_id = $wpdb->get_var($wpdb->prepare("SELECT objid FROM $t_rysetting WHERE ry = %d ORDER BY objid DESC LIMIT 1", $ry));
        }
        if (!$ry_setting_id) {
            // Default to active/latest revision setting (e.g. 2022)
            $ry_setting_id = $wpdb->get_var("SELECT objid FROM $t_rysetting ORDER BY ry DESC LIMIT 1");
        }

        if ($ry_setting_id) {
            $results['types'] = $wpdb->get_results($wpdb->prepare("SELECT objid, code, name FROM $t_type WHERE bldgrysettingid = %s ORDER BY code ASC", $ry_setting_id));
            $results['unitCosts'] = $wpdb->get_results($wpdb->prepare("SELECT objid, bldgkind_objid, bldgtypeid, basevalue, basevaluetype FROM $t_bucc WHERE bldgrysettingid = %s", $ry_setting_id));
        } else {
            // Deduplicate by code if no setting ID found
            $results['types'] = $wpdb->get_results("SELECT objid, code, name FROM $t_type GROUP BY code ORDER BY code ASC");
            $results['unitCosts'] = $wpdb->get_results("SELECT objid, bldgkind_objid, bldgtypeid, basevalue, basevaluetype FROM $t_bucc");
        }

        $results['kinds'] = $wpdb->get_results("SELECT objid, code, name FROM $t_kind ORDER BY name ASC");
        $results['uses'] = $wpdb->get_results("SELECT objid, name FROM $t_use ORDER BY name ASC");
        $results['materials'] = $wpdb->get_results("SELECT objid, name FROM $t_material ORDER BY name ASC");
        $results['classifications'] = $wpdb->get_results("SELECT objid, code, name, special, orderno, state FROM $t_class ORDER BY orderno ASC, name ASC");
        
        return $results;
    }

    public function get_building_revision_settings($request = null) {
        global $wpdb;
        $t_rysetting = $wpdb->prefix . 'assessor_bldgrysetting';
        $t_type = $wpdb->prefix . 'assessor_bldgtype';
        $t_kind = $wpdb->prefix . 'assessor_bldgkind';
        $t_bucc = $wpdb->prefix . 'assessor_bldgkindbucc';
        $t_class = $wpdb->prefix . 'assessor_propertyclassification';

        $ry = null;
        if (is_array($request) && !empty($request['ry'])) {
            $ry = intval($request['ry']);
        } elseif (is_object($request) && method_exists($request, 'get_param') && $request->get_param('ry')) {
            $ry = intval($request->get_param('ry'));
        }

        if ($ry) {
            $setting = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_rysetting WHERE ry = %d ORDER BY objid DESC LIMIT 1", $ry));
        } else {
            $setting = $wpdb->get_row("SELECT * FROM $t_rysetting ORDER BY ry DESC LIMIT 1");
        }

        if (!$setting) {
            return new WP_Error('not_found', 'Building Revision Setting not found', array('status' => 404));
        }

        $setting_id = $setting->objid;
        $types = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t_type WHERE bldgrysettingid = %s ORDER BY code ASC", $setting_id));
        $unitCosts = $wpdb->get_results($wpdb->prepare("SELECT bucc.*, k.code as kind_code, k.name as kind_name FROM $t_bucc bucc LEFT JOIN $t_kind k ON bucc.bldgkind_objid = k.objid WHERE bucc.bldgrysettingid = %s ORDER BY k.name ASC", $setting_id));
        $classifications = $wpdb->get_results("SELECT * FROM $t_class ORDER BY orderno ASC, name ASC");

        $allSettings = $wpdb->get_results("SELECT objid, ry, ordinanceno, ordinancedate, remarks FROM $t_rysetting ORDER BY ry DESC");

        return array(
            'setting' => $setting,
            'types' => $types,
            'unitCosts' => $unitCosts,
            'classifications' => $classifications,
            'allSettings' => $allSettings
        );
    }

    public function get_rpu_detail($request) {
        global $wpdb;
        $id = $request['id'];

        $t_rpu = $wpdb->prefix . 'assessor_rpu';
        
        $rpu = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_rpu WHERE objid = %s", $id));
        if (!$rpu) {
            return new WP_Error('not_found', 'RPU not found', array('status' => 404));
        }

        $assessments = $wpdb->get_results($wpdb->prepare("
            SELECT *
            FROM {$wpdb->prefix}assessor_rpu_assessment
            WHERE rpuid = %s
        ", $id));

        $rpu->assessments = $assessments ? $assessments : [];

        $rpu_type = strtoupper($rpu->rpu_type);

        if ($rpu_type === 'LAND') {
            // Fetch land RPU summary
            $t_landrpu = $wpdb->prefix . 'assessor_landrpu';
            $landrpu = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_landrpu WHERE objid = %s", $id));
            if ($landrpu) {
                $rpu->landrpu = $landrpu;
            }

            // Fetch land details (subclass, area, unit value, etc.)
            $t_landdetail = $wpdb->prefix . 'assessor_landdetail';
            $rpu->landdetail = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t_landdetail WHERE landrpuid = %s", $id));

            // Fetch plant/tree RPU if linked to this land
            $t_planttreerpu = $wpdb->prefix . 'assessor_planttreerpu';
            $rpu->planttrees = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t_planttreerpu WHERE landrpuid = %s", $id));

        } elseif ($rpu_type === 'BLDG') {
            // Fetch building subtype data
            $t_bldgrpu = $wpdb->prefix . 'assessor_bldgrpu';
            $subtype = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_bldgrpu WHERE objid = %s", $id));
            if ($subtype) {
                $rpu->subtype = $subtype;
            }

            // Fetch structuraltype
            $t_bldgrpu_structuraltype = $wpdb->prefix . 'assessor_bldgrpu_structuraltype';
            $structuraltype = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_bldgrpu_structuraltype WHERE bldgrpuid = %s LIMIT 1", $id));
            if ($structuraltype) {
                $rpu->structuraltype = $structuraltype;
            }

            // Fetch floors
            $t_bldgfloor = $wpdb->prefix . 'assessor_bldgfloor';
            $rpu->floors = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t_bldgfloor WHERE bldgrpuid = %s ORDER BY floorno ASC", $id));
            
            // Fetch floor additionals (building additional items per floor)
            $t_bldgflooradditional = $wpdb->prefix . 'assessor_bldgflooradditional';
            $t_bldgadditionalitem = $wpdb->prefix . 'assessor_bldgadditionalitem';
            $rpu->floorAdditionals = $wpdb->get_results($wpdb->prepare("
                SELECT fa.*, ai.code as item_code, ai.name as item_name, ai.unit as item_unit
                FROM $t_bldgflooradditional fa
                LEFT JOIN $t_bldgadditionalitem ai ON fa.additionalitem_objid = ai.objid
                WHERE fa.bldgrpuid = %s
            ", $id));

            // Fetch structures
            $t_bldgstructure = $wpdb->prefix . 'assessor_bldgstructure';
            $t_structure = $wpdb->prefix . 'assessor_structure';
            $t_material = $wpdb->prefix . 'assessor_material';
            $rpu->structures = $wpdb->get_results($wpdb->prepare("
                SELECT bs.*, s.name as structure_name, m.name as material_name
                FROM $t_bldgstructure bs
                LEFT JOIN $t_structure s ON bs.structure_objid = s.objid
                LEFT JOIN $t_material m ON bs.material_objid = m.objid
                WHERE bs.bldgrpuid = %s
            ", $id));
            
            // Fetch uses
            $t_bldguse = $wpdb->prefix . 'assessor_bldguse';
            $rpu->uses = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t_bldguse WHERE bldgrpuid = %s", $id));

        } elseif ($rpu_type === 'MACH') {
            // Fetch machinery RPU summary
            $t_machrpu = $wpdb->prefix . 'assessor_machrpu';
            $machrpu = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_machrpu WHERE objid = %s", $id));
            if ($machrpu) {
                $rpu->machrpu = $machrpu;
            }

            // Fetch machinery SMV detail items
            $t_machine_smv = $wpdb->prefix . 'assessor_machine_smv';
            $rpu->machines = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t_machine_smv WHERE parent_objid = %s", $id));

        } elseif ($rpu_type === 'MISC') {
            // Fetch misc RPU summary
            $t_miscrpu = $wpdb->prefix . 'assessor_miscrpu';
            $miscrpu = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_miscrpu WHERE objid = %s", $id));
            if ($miscrpu) {
                $rpu->miscrpu = $miscrpu;
            }

            // Fetch misc RPU items
            $t_miscrpuitem = $wpdb->prefix . 'assessor_miscrpuitem';
            $rpu->miscitems = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t_miscrpuitem WHERE miscrpuid = %s", $id));

        } elseif ($rpu_type === 'PLANTTREE') {
            // Fetch plant/tree RPU
            $t_planttreerpu = $wpdb->prefix . 'assessor_planttreerpu';
            $planttreerpu = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_planttreerpu WHERE objid = %s", $id));
            if ($planttreerpu) {
                $rpu->planttreerpu = $planttreerpu;
            }
        }

        return array(
            'data' => $rpu
        );
    }
}
