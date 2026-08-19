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
            'first_name' => sanitize_text_field($params['first_name'] ?? ''),
            'last_name' => sanitize_text_field($params['last_name'] ?? ''),
            'middle_name' => sanitize_text_field($params['middle_name'] ?? ''),
            'birthdate' => !empty($params['birthdate']) ? sanitize_text_field($params['birthdate']) : null,
            'birthplace' => sanitize_text_field($params['birthplace'] ?? ''),
            'gender' => sanitize_text_field($params['gender'] ?? ''),
            'civil_status' => sanitize_text_field($params['civil_status'] ?? ''),
            'citizenship' => sanitize_text_field($params['citizenship'] ?? ''),
            'profession' => sanitize_text_field($params['profession'] ?? ''),
            'tin' => sanitize_text_field($params['tin'] ?? ''),
            'sss' => sanitize_text_field($params['sss'] ?? ''),
            'acr' => sanitize_text_field($params['acr'] ?? ''),
            'religion' => sanitize_text_field($params['religion'] ?? ''),
            'height' => sanitize_text_field($params['height'] ?? ''),
            'weight' => sanitize_text_field($params['weight'] ?? ''),
            'date_registered' => !empty($params['date_registered']) ? sanitize_text_field($params['date_registered']) : null,
            'org_type' => sanitize_text_field($params['org_type'] ?? ''),
            'nature_of_business' => sanitize_text_field($params['nature_of_business'] ?? ''),
            'place_registered' => sanitize_text_field($params['place_registered'] ?? ''),
            'admin_name' => sanitize_text_field($params['admin_name'] ?? ''),
            'admin_position' => sanitize_text_field($params['admin_position'] ?? ''),
            'admin_address' => sanitize_text_field($params['admin_address'] ?? ''),
        );

        $result = $wpdb->insert($table, $data);
        if ($result === false) {
            return new WP_Error('insert_failed', 'Failed to create entity', array('status' => 500));
        }

        $entity = $wpdb->get_row($wpdb->prepare("SELECT e.*, e.name AS entity_name, e.address_text AS entity_address, e.type AS entity_type FROM $table e WHERE objid = %s", $objid));
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
            $where[] = "(f.tdno LIKE %s OR f.owner_name LIKE %s OR e.name LIKE %s OR rp.cadastral_lot_no LIKE %s OR f.fullpin LIKE %s)";
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

        $joins = "
            LEFT JOIN $t_rpu r ON f.rpuid = r.id
            LEFT JOIN $t_rp rp ON f.realpropertyid = rp.id
            LEFT JOIN $t_entity e ON f.taxpayer_objid = e.objid
            LEFT JOIN $t_txn tx ON f.txntype_objid = tx.code
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
                rp.cadastral_lot_no,
                rp.survey_no,
                rp.block_no,
                rp.barangay,
                rp.total_area_hectare,
                rp.total_area_sqm,
                e.name AS taxpayer_name,
                e.address_text AS taxpayer_address,
                tx.name AS txntype_name
            FROM $t_faas f
            $joins
            WHERE $where_sql
            ORDER BY $order_by
            LIMIT %d OFFSET %d
        ";

        $query_values = array_merge($values, array($per_page, $offset));
        $results = $wpdb->get_results($wpdb->prepare($select_sql, $query_values));
                f.txntype_objid AS txntype_code,
                f.titletype AS title_type,
                f.titleno AS title_no,
                f.titledate AS title_date,
                f.dtapproved AS date_approved,
                f.year AS year_issued,
                f.cancelreason AS cancel_reason,
                f.canceldate AS cancel_date,
                f.cancelnote AS cancel_note,
                f.prevowner AS prev_owner,
                f.prevav AS prev_assessed_value,
                f.prevmv AS prev_market_value,
                f.prevareaha AS prev_area_hectare,
                f.prevareasqm AS prev_area_sqm,
                f.preveffectivity AS prev_effectivity,
                f.cancelledyear AS cancelled_year,
                f.cancelledbytdnos AS cancelled_by_tdnos,
                (SELECT f2.fullpin FROM wp_assessor_faas f2 WHERE f2.tdno = f.cancelledbytdnos LIMIT 1) AS cancelled_by_pin,
                r.rpu_type,
        $t_rp = $wpdb->prefix . 'assessor_real_property';
        $t_entity = $wpdb->prefix . 'assessor_entity';
        $t_txn = $wpdb->prefix . 'assessor_faas_txntypes';

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
                r.ry AS revision_year,
                rp.pin AS rp_pin,
                rp.cadastrallotno AS cadastral_lot_no,
                rp.surveyno AS survey_no,
                rp.blockno AS block_no,
                rp.barangay,
                r.total_area_hectare,
                r.total_area_sqm,
                rp.north,
                rp.south,
                rp.east,
                rp.west,
                e.name AS taxpayer_name,
                e.address_text AS taxpayer_address,
                e.type AS taxpayer_type,
                tx.name AS txntype_name
            FROM $t_faas f
            LEFT JOIN $t_rpu r ON f.rpuid = r.objid
            LEFT JOIN $t_rp rp ON f.realpropertyid = rp.objid
            LEFT JOIN $t_entity e ON f.taxpayer_objid = e.objid
            LEFT JOIN $t_txn tx ON f.txntype_objid = tx.code
            WHERE f.objid = %s
        ";

        $record = $wpdb->get_row($wpdb->prepare($sql, $id));
        if (!$record) {
            return new WP_Error('not_found', 'FAAS record not found', array('status' => 404));
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
                e.telephone_no AS taxpayer_telephone_no,
                e.type AS taxpayer_type,
                tx.name AS txntype_name
            FROM $t_faas f
            LEFT JOIN $t_rpu r ON f.rpuid = r.objid
            LEFT JOIN $t_rp rp ON f.realpropertyid = rp.objid
            LEFT JOIN $t_entity e ON f.taxpayer_objid = e.objid
            LEFT JOIN $t_txn tx ON f.txntype_objid = tx.code
            WHERE f.objid = %s
        ";

        $record = $wpdb->get_row($wpdb->prepare($sql, $id));
        if (!$record) {
            return new WP_Error('not_found', 'FAAS record not found', array('status' => 404));
        }

        return $record;
    }

    public function check_permission() {
        return true;
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
            'total_assessed_value' => isset($params['total_assessed_value']) && $params['total_assessed_value'] !== '' ? floatval($params['total_assessed_value']) : 0,
            'total_area_hectare'   => isset($params['total_area_hectare']) && $params['total_area_hectare'] !== '' ? floatval($params['total_area_hectare']) : null,
            'total_area_sqm'       => isset($params['total_area_sqm']) && $params['total_area_sqm'] !== '' ? floatval($params['total_area_sqm']) : null,
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
        if (array_key_exists('total_area_hectare', $params)) $update['total_area_hectare'] = $params['total_area_hectare'] !== '' && $params['total_area_hectare'] !== null ? floatval($params['total_area_hectare']) : null;
        if (array_key_exists('total_area_sqm', $params)) $update['total_area_sqm'] = $params['total_area_sqm'] !== '' && $params['total_area_sqm'] !== null ? floatval($params['total_area_sqm']) : null;
        if (isset($params['taxable'])) $update['taxable'] = intval($params['taxable']);

        if (!empty($update)) {
            $wpdb->update($table, $update, array('id' => $id));
        }
    }
}


        $rp_objid = 'RP-LOCAL-' . substr(md5(uniqid('', true)), 0, 16);
        $data = array(
            'objid'           => $rp_objid,
            'etracs_objid'    => null,
            'pin'             => sanitize_text_field($params['pin'] ?? ''),
            'cadastrallotno'  => sanitize_text_field($params['cadastral_lot_no'] ?? $params['lot_number'] ?? ''),
            'surveyno'        => sanitize_text_field($params['survey_no'] ?? ''),
            'blockno'         => sanitize_text_field($params['block_no'] ?? ''),
            'barangay'        => sanitize_text_field($params['barangay'] ?? ''),
            'barangayid'      => sanitize_text_field($params['barangayid'] ?? ''),
            'purok'           => sanitize_text_field($params['purok'] ?? ''),
            'street'          => sanitize_text_field($params['street'] ?? ''),
            'municipality'    => sanitize_text_field($params['municipality'] ?? ''),
            'province'        => sanitize_text_field($params['province'] ?? ''),
            'north'           => sanitize_text_field($params['north'] ?? ''),
            'south'           => sanitize_text_field($params['south'] ?? ''),
            'east'            => sanitize_text_field($params['east'] ?? ''),
            'west'            => sanitize_text_field($params['west'] ?? ''),
        );

        $wpdb->insert($table, $data);
        return $rp_objid;
    }

    private function update_real_property($id, $params) {
        global $wpdb;
        $table = $wpdb->prefix . 'assessor_real_property';

        $field_map = array(
            'pin'              => 'pin',
            'cadastral_lot_no' => 'cadastrallotno',
            'lot_number'       => 'cadastrallotno',
            'survey_no'        => 'surveyno',
            'block_no'         => 'blockno',
            'barangay'         => 'barangay',
            'barangayid'       => 'barangayid',
            'purok'            => 'purok',
            'street'           => 'street',
            'municipality'     => 'municipality',
            'province'         => 'province',
            'north'            => 'north',
            'south'            => 'south',
            'east'             => 'east',
            'west'             => 'west',
        );
        $update = array();
        foreach ($field_map as $param_key => $col) {
            if (isset($params[$param_key]) && !isset($update[$col])) {
                $update[$col] = sanitize_text_field($params[$param_key]);
            }
        }

        if (!empty($update)) {
            // Try objid first (new schema), fall back to id
            $wpdb->update($table, $update, array('objid' => $id));
            if ($wpdb->rows_affected === 0) {
                $wpdb->update($table, $update, array('id' => $id));
            }
        }
    }

    private function create_rpu($params, $real_property_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'assessor_rpu';

        $rpu_objid = 'RPU-LOCAL-' . substr(md5(uniqid('', true)), 0, 16);
        $data = array(
            'objid'                => $rpu_objid,
            'etracs_objid'         => null,
            'state'                => 'CURRENT',
            'realpropertyid'       => $real_property_id,
            'real_property_id'     => 0,  // legacy compat
            'rpu_type'             => strtoupper(sanitize_text_field($params['rpu_type'] ?? 'LAND')),
            'classification'       => sanitize_text_field($params['classification'] ?? ''),
            'classification_objid' => sanitize_text_field($params['classification_objid'] ?? ''),
            'exemptiontype_objid'  => sanitize_text_field($params['exemptiontype_objid'] ?? ''),
            'ry'                   => intval($params['ry'] ?? $params['revision_year'] ?? 0),
            'fullpin'              => sanitize_text_field($params['fullpin'] ?? $params['pin'] ?? ''),
            'suffix'               => intval($params['suffix'] ?? 0),
            'total_market_value'   => isset($params['total_market_value']) && $params['total_market_value'] !== '' ? floatval($params['total_market_value']) : 0,
            'total_assessed_value' => isset($params['total_assessed_value']) && $params['total_assessed_value'] !== '' ? floatval($params['total_assessed_value']) : 0,
            'totalbmv'             => floatval($params['totalbmv'] ?? 0),
            'taxable'              => intval($params['taxable'] ?? 1),
        );

        $wpdb->insert($table, $data);
        return $rpu_objid;
    }

    private function update_rpu($id, $params) {
        global $wpdb;
        $table = $wpdb->prefix . 'assessor_rpu';

        $update = array();
        if (isset($params['rpu_type']))            $update['rpu_type']             = strtoupper(sanitize_text_field($params['rpu_type']));
        if (isset($params['classification']))      $update['classification']        = sanitize_text_field($params['classification']);
        if (isset($params['classification_objid']))$update['classification_objid'] = sanitize_text_field($params['classification_objid']);
        if (isset($params['exemptiontype_objid'])) $update['exemptiontype_objid']  = sanitize_text_field($params['exemptiontype_objid']);
        if (isset($params['ry']) || isset($params['revision_year'])) $update['ry'] = intval($params['ry'] ?? $params['revision_year']);
        if (array_key_exists('total_market_value', $params))   $update['total_market_value']   = floatval($params['total_market_value']);
        if (array_key_exists('total_assessed_value', $params)) $update['total_assessed_value'] = floatval($params['total_assessed_value']);
        if (array_key_exists('totalbmv', $params))             $update['totalbmv']             = floatval($params['totalbmv']);
        if (isset($params['taxable']))             $update['taxable']              = intval($params['taxable']);
        if (isset($params['fullpin']))             $update['fullpin']              = sanitize_text_field($params['fullpin']);

        if (!empty($update)) {
            // Try objid first (new schema), fall back to id
            $wpdb->update($table, $update, array('objid' => $id));
            if ($wpdb->rows_affected === 0) {
                $wpdb->update($table, $update, array('id' => $id));
            }
        }
    }
}

