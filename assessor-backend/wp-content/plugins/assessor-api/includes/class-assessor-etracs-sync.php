<?php

class Assessor_Etracs_Sync {

    public static function ensure_schema() {
        global $wpdb;
        
        $rp_table = $wpdb->prefix . 'assessor_real_property';
        $rpu_table = $wpdb->prefix . 'assessor_rpu';

        $rp_cols = $wpdb->get_col("DESC $rp_table", 0);
        if (!in_array('etracs_objid', $rp_cols)) {
            $wpdb->query("ALTER TABLE $rp_table ADD COLUMN etracs_objid varchar(50) DEFAULT NULL");
            $wpdb->query("ALTER TABLE $rp_table ADD INDEX ix_etracs_objid (etracs_objid)");
        }

        $rpu_cols = $wpdb->get_col("DESC $rpu_table", 0);
        if (!in_array('etracs_objid', $rpu_cols)) {
            $wpdb->query("ALTER TABLE $rpu_table ADD COLUMN etracs_objid varchar(50) DEFAULT NULL");
            $wpdb->query("ALTER TABLE $rpu_table ADD INDEX ix_etracs_objid (etracs_objid)");
        }
    }

    public static function trigger_sync() {
        self::ensure_schema();

        $host = get_option('assessor_etracs_db_host', 'localhost');
        $port = get_option('assessor_etracs_db_port', '3306');
        $user = get_option('assessor_etracs_db_user', 'root');
        $pass = get_option('assessor_etracs_db_password', '');
        $db   = get_option('assessor_etracs_db_name', 'etracs254_kitaotao');

        if (empty($host) || empty($db) || empty($user)) {
            return new WP_Error('missing_config', 'ETRACS database configuration is incomplete.', array('status' => 400));
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
            return new WP_Error('db_connect_failed', 'Failed to connect to ETRACS Database: ' . $e->getMessage(), array('status' => 500));
        }

        global $wpdb;
        $stats = [
            'entity' => 0,
            'real_property' => 0,
            'rpu' => 0,
            'faas' => 0
        ];

        // We will sync everything in this basic implementation to ensure consistency.
        // For production, we can optimize by only syncing where txntimestamp/sys_lastupdate > last_sync
        
        $wp_entity = $wpdb->prefix . 'assessor_entity';
        $wp_rp = $wpdb->prefix . 'assessor_real_property';
        $wp_rpu = $wpdb->prefix . 'assessor_rpu';
        $wp_faas = $wpdb->prefix . 'assessor_faas';

        // 1. Entity Sync
        $stmt = $pdo->query("SELECT * FROM entity");
        while ($row = $stmt->fetch()) {
            $wpdb->replace($wp_entity, [
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
            ]);
            $stats['entity']++;
        }

        // 2. Real Property Sync
        $rp_map = []; // Maps ETRACS objid to local ID
        $stmt = $pdo->query("
            SELECT rp.*, b.name as barangay_name 
            FROM realproperty rp 
            LEFT JOIN barangay b ON rp.barangayid = b.objid
        ");
        while ($row = $stmt->fetch()) {
            $etracs_id = $row['objid'];
            $existing_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $wp_rp WHERE etracs_objid = %s", $etracs_id));
            
            $data = [
                'objid' => $etracs_id,
                'etracs_objid' => $etracs_id,
                'pin' => $row['pin'],
                'cadastral_lot_no' => $row['cadastrallotno'],
                'survey_no' => $row['surveyno'],
                'block_no' => $row['blockno'],
                'barangay' => !empty($row['barangay_name']) ? $row['barangay_name'] : $row['barangayid'],
                'municipality' => $row['lgutype'] == 'municipality' ? $row['lguid'] : '',
                'north' => $row['north'],
                'south' => $row['south'],
                'east' => $row['east'],
                'west' => $row['west']
            ];
            
            if ($existing_id) {
                $wpdb->update($wp_rp, $data, ['id' => $existing_id]);
                $rp_map[$etracs_id] = $existing_id;
            } else {
                $wpdb->insert($wp_rp, $data);
                $rp_map[$etracs_id] = $wpdb->insert_id;
            }
            $stats['real_property']++;
        }

        // 3. RPU Sync
        $rpu_map = []; // Maps ETRACS objid to local ID
        $stmt = $pdo->query("
            SELECT rpu.*, pc.code as class_code 
            FROM rpu 
            LEFT JOIN propertyclassification pc ON rpu.classification_objid = pc.objid
        ");
        while ($row = $stmt->fetch()) {
            $etracs_id = $row['objid'];
            $rp_etracs_id = $row['realpropertyid'];
            
            // Link to the local real_property_id using our map
            $local_rp_id = $rp_map[$rp_etracs_id] ?? 0;
            
            $existing_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $wp_rpu WHERE etracs_objid = %s", $etracs_id));
            
            $data = [
                'objid' => $etracs_id,
                'etracs_objid' => $etracs_id,
                'real_property_id' => $local_rp_id,
                'rpu_type' => $row['rputype'],
                'classification' => !empty($row['class_code']) ? $row['class_code'] : $row['classification_objid'],
                'ry' => $row['ry'],
                'total_market_value' => floatval($row['totalmv']),
                'total_assessed_value' => floatval($row['totalav']),
                'taxable' => $row['taxable']
            ];
            
            if ($existing_id) {
                $wpdb->update($wp_rpu, $data, ['id' => $existing_id]);
                $rpu_map[$etracs_id] = $existing_id;
            } else {
                $wpdb->insert($wp_rpu, $data);
                $rpu_map[$etracs_id] = $wpdb->insert_id;
            }
            $stats['rpu']++;
        }

        // 4. FAAS Sync
        // It's safer to map rxntypes and classification, but for now we pull raw data
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
            ];

            $wpdb->replace($wp_faas, $data);
            $stats['faas']++;
        }

        // 5. Sync detail & lookup tables (Building, Land, Mach, Misc, Lookups)
        $tables_to_sync_direct = [
            'faas_previous' => 'assessor_faas_previous',
            'bldgrpu' => 'assessor_bldgrpu',
            'bldgfloor' => 'assessor_bldgfloor',
            'bldguse' => 'assessor_bldguse',
            'bldgstructure' => 'assessor_bldgstructure',
            'bldgrpu_structuraltype' => 'assessor_bldgrpu_structuraltype',
            'bldgkind' => 'assessor_bldgkind',
            'bldgkindbucc' => 'assessor_bldgkindbucc',
            'bldgtype' => 'assessor_bldgtype',
            'bldgrysetting' => 'assessor_bldgrysetting',
            'propertyclassification' => 'assessor_propertyclassification',
            'landrpu' => 'assessor_landrpu',
            'landdetail' => 'assessor_landdetail',
            'machrpu' => 'assessor_machrpu',
            'machdetail' => 'assessor_machdetail',
            'miscrpu' => 'assessor_miscrpu',
            'miscitem' => 'assessor_miscitem',
            'landassesslevel' => 'assessor_landassesslevel',
            'bldgassesslevel' => 'assessor_bldgassesslevel',
            'machassesslevel' => 'assessor_machassesslevel',
            'planttreeassesslevel' => 'assessor_planttreeassesslevel',
            'miscassesslevel' => 'assessor_miscassesslevel',
        ];

        foreach ($tables_to_sync_direct as $etracs_table => $local_table) {
            $full_local_table = $wpdb->prefix . $local_table;
            try {
                $stmt = $pdo->query("SELECT * FROM `$etracs_table`");
                if ($stmt) {
                    $stats[$local_table] = 0;
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $wpdb->replace($full_local_table, $row);
                        $stats[$local_table]++;
                    }
                }
            } catch (Exception $e) {
                // Ignore if table doesn't exist in ETRACS
            }
        }

        // 6. Sync rpu_assessment with JOINs to get classname and actualuse
        $wp_rpu_assessment = $wpdb->prefix . 'assessor_rpu_assessment';
        try {
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
            if ($stmt) {
                $stats['rpu_assessment'] = 0;
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $wpdb->replace($wp_rpu_assessment, $row);
                    $stats['rpu_assessment']++;
                }
            }
        } catch (Exception $e) {}

        update_option('assessor_etracs_last_sync', current_time('mysql'));

        return $stats;
    }
}
