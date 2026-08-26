<?php

class Assessor_Database {
    
    public function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Users table
        $table_users = $wpdb->prefix . 'assessor_users';
        $sql_users = "CREATE TABLE $table_users (
            id varchar(50) NOT NULL,
            username varchar(100) NOT NULL,
            password varchar(255) NOT NULL,
            email varchar(100) DEFAULT NULL,
            avatar_url varchar(255) DEFAULT NULL,
            full_name varchar(200) NOT NULL,
            role varchar(50) NOT NULL DEFAULT 'assessor',
            status varchar(20) NOT NULL DEFAULT 'active',
            last_login datetime NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY username (username),
            KEY email (email)
        ) $charset_collate;";
        
        // Properties table
        $table_properties = $wpdb->prefix . 'assessor_properties';
        $sql_properties = "CREATE TABLE $table_properties (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            tax_declaration_number varchar(100) NOT NULL,
            previous_tax_declaration_number varchar(100),
            declarant_last_name varchar(255) NOT NULL,
            declarant_first_name varchar(255) NOT NULL,
            declarant_middle_initial varchar(255),
            business varchar(200),
            location text NOT NULL,
            lot_number varchar(100),
            unique_lot_number_identified varchar(100),
            survey_number varchar(100),
            area_hectare decimal(10,4),
            area_hectare_old varchar(255),
            area_sqm decimal(15,2),
            title_number varchar(100),
            assessed_value decimal(15,2),
            assessed_value_old varchar(255),
            effectivity_date varchar(20),
            pin varchar(100),
            address text,
            assessment_date date,
            kind_of_property varchar(50) NOT NULL,
            gen_class varchar(50),
            memoranda text,
            supporting_documents text,
            supporting_documents_old text,
            verifier_signatory_name varchar(255) DEFAULT '',
            verifier_signatory_title varchar(255) DEFAULT '',
            municipal_assessor_name varchar(255) DEFAULT '',
            municipal_assessor_suffix varchar(255) DEFAULT '',
            municipal_assessor_title varchar(255) DEFAULT '',
            municipal_assessor_license varchar(255) DEFAULT '',
            status varchar(50) NOT NULL DEFAULT 'active',
            created_by varchar(50) DEFAULT NULL,
            updated_by varchar(50) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY tax_declaration_number (tax_declaration_number),
            KEY declarant_last_name (declarant_last_name),
            KEY declarant_first_name (declarant_first_name),
            KEY location (location(100)),
            KEY kind_of_property (kind_of_property),
            KEY status (status)
        ) $charset_collate;";
        
        // Property versions table
        $table_versions = $wpdb->prefix . 'assessor_property_versions';
        $sql_versions = "CREATE TABLE $table_versions (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            property_id mediumint(9) NOT NULL,
            version_number int NOT NULL,
            tax_declaration_number varchar(100) NOT NULL,
            previous_tax_declaration_number varchar(100),
            declarant_last_name varchar(255) NOT NULL,
            declarant_first_name varchar(255) NOT NULL,
            declarant_middle_initial varchar(255),
            business varchar(200),
            location text NOT NULL,
            lot_number varchar(100),
            unique_lot_number_identified varchar(100),
            survey_number varchar(100),
            area_hectare decimal(10,4),
            area_hectare_old varchar(255),
            area_sqm decimal(15,2),
            title_number varchar(100),
            assessed_value decimal(15,2),
            assessed_value_old varchar(255),
            effectivity_date varchar(20),
            pin varchar(100),
            address text,
            assessment_date date,
            kind_of_property varchar(50) NOT NULL,
            gen_class varchar(50),
            memoranda text,
            supporting_documents text,
            supporting_documents_old text,
            verifier_signatory_name varchar(255) DEFAULT '',
            verifier_signatory_title varchar(255) DEFAULT '',
            municipal_assessor_name varchar(255) DEFAULT '',
            municipal_assessor_suffix varchar(255) DEFAULT '',
            municipal_assessor_title varchar(255) DEFAULT '',
            municipal_assessor_license varchar(255) DEFAULT '',
            change_reason text,
            created_by varchar(50) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY property_id (property_id),
            KEY version_number (version_number),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        // Documents table
        $table_documents = $wpdb->prefix . 'assessor_documents';
        $sql_documents = "CREATE TABLE $table_documents (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            property_id mediumint(9) NOT NULL,
            filename varchar(255) NOT NULL,
            original_filename varchar(255) NOT NULL,
            file_path varchar(500) NOT NULL,
            file_type varchar(100) NOT NULL,
            file_size bigint NOT NULL,
            description text,
            uploaded_by varchar(50) DEFAULT NULL,
            uploaded_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY property_id (property_id),
            KEY file_type (file_type)
        ) $charset_collate;";
        
        // Audit trail table
        $table_audit = $wpdb->prefix . 'assessor_audit_trail';
        $sql_audit = "CREATE TABLE $table_audit (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id varchar(50) DEFAULT NULL,
            action varchar(100) NOT NULL,
            table_name varchar(100) NOT NULL,
            record_id varchar(50),
            old_values text,
            new_values text,
            ip_address varchar(45),
            user_agent text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY action (action),
            KEY table_name (table_name),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        // Settings table
        $table_settings = $wpdb->prefix . 'assessor_settings';
        // Migration: Add lgu_pin column to settings table
        $column = $wpdb->get_var($wpdb->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'lgu_pin'", $table_settings));
        if (!$column) {
            $wpdb->query("ALTER TABLE $table_settings ADD COLUMN lgu_pin varchar(255) DEFAULT '059-10' AFTER header_municipality");
        }

        // Migration: Add enable_etracs_features column to settings table
        $column = $wpdb->get_var($wpdb->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'enable_etracs_features'", $table_settings));
        if (!$column) {
            $wpdb->query("ALTER TABLE $table_settings ADD COLUMN enable_etracs_features tinyint(1) NOT NULL DEFAULT 0 AFTER public_api_enabled");
        }
        
        // Migration: Add municipality_prefix column to settings table
        $column = $wpdb->get_var($wpdb->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'municipality_prefix'", $table_settings));
        if (!$column) {
            $wpdb->query("ALTER TABLE $table_settings ADD COLUMN municipality_prefix varchar(10) DEFAULT 'GBL' AFTER header_municipality");
        }
        $sql_settings = "CREATE TABLE $table_settings (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            app_logo_url varchar(500) DEFAULT '',
            header_photo_url varchar(500) DEFAULT '',
            header_province varchar(255) DEFAULT '',
            header_municipality varchar(255) DEFAULT '',
            municipality_prefix varchar(10) DEFAULT 'GBL',
            lgu_pin varchar(255) DEFAULT '059-10',
            header_office varchar(255) DEFAULT '',
            request_place_issued_default varchar(255) DEFAULT '',
            verifier_signatory_name varchar(255) DEFAULT '',
            verifier_signatory_title varchar(255) DEFAULT '',
            municipal_assessor_name varchar(255) DEFAULT '',
            municipal_assessor_suffix varchar(255) DEFAULT '',
            municipal_assessor_title varchar(255) DEFAULT '',
            municipal_assessor_license varchar(255) DEFAULT '',
            afk_timeout int DEFAULT 30,
            public_api_key_hash varchar(255) DEFAULT '',
            public_api_key_prefix varchar(20) DEFAULT '',
            public_api_enabled tinyint(1) NOT NULL DEFAULT 0,
            enable_etracs_features tinyint(1) NOT NULL DEFAULT 0,
            print_layout_templates longtext,
            app_secondary_logo_url varchar(500) DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        // Property types table
        $table_property_types = $wpdb->prefix . 'assessor_property_types';
        $sql_property_types = "CREATE TABLE $table_property_types (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            code varchar(50) NOT NULL,
            name varchar(100) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            sort_order int NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY code (code),
            KEY status (status),
            KEY sort_order (sort_order)
        ) $charset_collate;";

        // General classes table
        $table_general_classes = $wpdb->prefix . 'assessor_general_classes';
        $sql_general_classes = "CREATE TABLE $table_general_classes (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            code varchar(50) NOT NULL,
            name varchar(100) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            sort_order int NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY code (code),
            KEY status (status),
            KEY sort_order (sort_order)
        ) $charset_collate;";

        // Locations table
        $table_locations = $wpdb->prefix . 'assessor_locations';
        $sql_locations = "CREATE TABLE $table_locations (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            code varchar(100) NOT NULL,
            name varchar(150) NOT NULL,
            pin varchar(50) DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            sort_order int NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY code (code),
            KEY status (status),
            KEY sort_order (sort_order)
        ) $charset_collate;";

        // Request purposes table (Purpose + Amount Paid)
        $table_request_purposes = $wpdb->prefix . 'assessor_request_purposes';
        $sql_request_purposes = "CREATE TABLE $table_request_purposes (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            purpose varchar(150) NOT NULL,
            amount decimal(10,2) NOT NULL DEFAULT 0.00,
            status varchar(20) NOT NULL DEFAULT 'active',
            sort_order int NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY purpose (purpose),
            KEY status (status),
            KEY sort_order (sort_order)
        ) $charset_collate;";
        
        // Requests table
        $table_requests = $wpdb->prefix . 'assessor_requests';
        $sql_requests = "CREATE TABLE $table_requests (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            property_id bigint(20) DEFAULT NULL,
            amount_paid decimal(10,2) NOT NULL,
            receipt_number varchar(100) NOT NULL,
            is_official_request tinyint(1) NOT NULL DEFAULT 0,
            date_issued datetime NOT NULL,
            place_issued varchar(255) NOT NULL,
            prepared_by varchar(255) NOT NULL,
            payment_type varchar(50) NOT NULL,
            purpose varchar(100) NOT NULL,
            client_name varchar(255) NOT NULL,
            client_address text,
            contact_number varchar(50),
            email varchar(255),
            remarks text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_by varchar(50) DEFAULT NULL,
            updated_by varchar(50) DEFAULT NULL,
            verifier_signatory_name varchar(255) DEFAULT NULL,
            verifier_signatory_title varchar(255) DEFAULT NULL,
            municipal_assessor_name varchar(255) DEFAULT NULL,
            municipal_assessor_title varchar(255) DEFAULT NULL,
            municipal_assessor_license varchar(255) DEFAULT NULL,
            municipal_assessor_suffix varchar(255) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY property_id (property_id),
            KEY receipt_number (receipt_number),
            KEY date_issued (date_issued),
            KEY created_at (created_at)
        ) $charset_collate;";

        // Revision entries table
        $table_revision_entries = $wpdb->prefix . 'assessor_revision_entries';
        $sql_revision_entries = "CREATE TABLE $table_revision_entries (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            revision_year varchar(100) NOT NULL,
            from_year varchar(10) NOT NULL,
            to_year varchar(20) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            sort_order int NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY status (status),
            KEY sort_order (sort_order)
        ) $charset_collate;";

        $table_api_keys = $wpdb->prefix . 'assessor_api_keys';
        $sql_api_keys = "CREATE TABLE $table_api_keys (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            name varchar(150) NOT NULL,
            api_scope varchar(80) NOT NULL DEFAULT 'public_properties',
            api_key varchar(80) NOT NULL,
            secret_hash varchar(255) NOT NULL,
            secret_encrypted text NULL,
            key_hash varchar(255) NOT NULL DEFAULT '',
            key_prefix varchar(30) NOT NULL DEFAULT '',
            status varchar(20) NOT NULL DEFAULT 'active',
            created_by varchar(50) DEFAULT NULL,
            last_used_at datetime NULL,
            last_used_ip varchar(45) DEFAULT '',
            revoked_at datetime NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY api_key (api_key),
            KEY api_scope (api_scope),
            KEY status (status),
            KEY created_by (created_by),
            KEY created_at (created_at)
        ) $charset_collate;";

        // Sync queue table — used on LOCAL builds to track properties pending upload to the live site.
        // On the live site this table exists but stays empty (live pushes nothing upstream).
        $table_sync_queue = $wpdb->prefix . 'assessor_sync_queue';
        $sql_sync_queue = "CREATE TABLE $table_sync_queue (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            property_id mediumint(9) NOT NULL,
            operation varchar(20) NOT NULL DEFAULT 'upsert',
            status varchar(20) NOT NULL DEFAULT 'pending',
            attempts int NOT NULL DEFAULT 0,
            last_error text NULL,
            queued_at datetime DEFAULT CURRENT_TIMESTAMP,
            synced_at datetime NULL,
            PRIMARY KEY (id),
            UNIQUE KEY property_op (property_id, operation),
            KEY status (status),
            KEY queued_at (queued_at)
        ) $charset_collate;";

        // Sync meta table — one row per site, stores timestamps for last successful push/pull.
        $table_sync_meta = $wpdb->prefix . 'assessor_sync_meta';
        $sql_sync_meta = "CREATE TABLE $table_sync_meta (
            id int(11) NOT NULL AUTO_INCREMENT,
            meta_key varchar(80) NOT NULL,
            meta_value text NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY meta_key (meta_key)
        ) $charset_collate;";

        // ETRACS Synced Tables
        $table_bldgrysetting = $wpdb->prefix . 'assessor_bldgrysetting';
        $sql_bldgrysetting = "CREATE TABLE $table_bldgrysetting (
            objid varchar(50) NOT NULL,
            state varchar(20) DEFAULT NULL,
            ry int DEFAULT NULL,
            ordinanceno varchar(100) DEFAULT NULL,
            ordinancedate date DEFAULT NULL,
            appliedto text,
            remarks text,
            PRIMARY KEY (objid)
        ) $charset_collate;";

        $table_bldgkind = $wpdb->prefix . 'assessor_bldgkind';
        $sql_bldgkind = "CREATE TABLE $table_bldgkind (
            objid varchar(50) NOT NULL,
            state varchar(10) NOT NULL,
            code varchar(20) NOT NULL,
            name varchar(100) NOT NULL,
            newid varchar(50) DEFAULT NULL,
            PRIMARY KEY (objid),
            UNIQUE KEY ux_bldgkind_code (code),
            UNIQUE KEY ux_bldgkind_name (name),
            KEY ix_bldgkind_state (state)
        ) $charset_collate;";

        $table_bldgkindbucc = $wpdb->prefix . 'assessor_bldgkindbucc';
        $sql_bldgkindbucc = "CREATE TABLE $table_bldgkindbucc (
            objid varchar(50) NOT NULL,
            bldgrysettingid varchar(50) NOT NULL,
            bldgtypeid varchar(50) NOT NULL,
            bldgkind_objid varchar(50) NOT NULL,
            basevaluetype varchar(25) NOT NULL,
            basevalue decimal(10,2) NOT NULL,
            minbasevalue decimal(10,2) NOT NULL,
            maxbasevalue decimal(10,2) NOT NULL,
            gapvalue int NOT NULL,
            minarea decimal(10,2) NOT NULL,
            maxarea decimal(10,2) NOT NULL,
            bldgclass varchar(50) DEFAULT NULL,
            previd varchar(50) DEFAULT NULL,
            PRIMARY KEY (objid)
        ) $charset_collate;";

        $table_bldgtype = $wpdb->prefix . 'assessor_bldgtype';
        $sql_bldgtype = "CREATE TABLE $table_bldgtype (
            objid varchar(50) NOT NULL,
            bldgrysettingid varchar(50) NOT NULL,
            code varchar(10) NOT NULL,
            name varchar(50) NOT NULL,
            basevaluetype varchar(10) NOT NULL,
            residualrate decimal(10,2) NOT NULL,
            previd varchar(50) DEFAULT NULL,
            usecdu int DEFAULT NULL,
            storeyadjtype varchar(10) DEFAULT NULL,
            PRIMARY KEY (objid)
        ) $charset_collate;";

        $table_propertyclassification = $wpdb->prefix . 'assessor_propertyclassification';
        $sql_propertyclassification = "CREATE TABLE $table_propertyclassification (
            objid varchar(50) NOT NULL,
            state varchar(10) DEFAULT 'active',
            code varchar(20) NOT NULL,
            name varchar(100) NOT NULL,
            orderno int DEFAULT 0,
            special int DEFAULT 0,
            correctid varchar(100) DEFAULT '',
            PRIMARY KEY (objid),
            UNIQUE KEY ux_classcode (code)
        ) $charset_collate;";

        
        
        // --- AUTO-GENERATED ETRACS TABLES ---
        $table_assessor_barangay = $wpdb->prefix . 'assessor_barangay';
        $sql_assessor_barangay = "CREATE TABLE $table_assessor_barangay (
  objid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  indexno varchar(15) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  pin varchar(15) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  name varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  parentid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  fullname varchar(250) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  address varchar(250) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  PRIMARY KEY (objid),
  KEY ix_barangay_pin (pin),
  KEY ix_barangay_name (name)
) $charset_collate;";

        $table_assessor_bldgadditionalitem = $wpdb->prefix . 'assessor_bldgadditionalitem';
        $sql_assessor_bldgadditionalitem = "CREATE TABLE $table_assessor_bldgadditionalitem (
  objid varchar(50) NOT NULL,
  bldgrysettingid varchar(50) NOT NULL,
  code varchar(10) NOT NULL,
  name varchar(100) NOT NULL,
  unit varchar(25) NOT NULL,
  expr varchar(100) NOT NULL,
  previd varchar(50) DEFAULT NULL,
  type varchar(50) DEFAULT NULL,
  addareatobldgtotalarea int DEFAULT NULL,
  idx int DEFAULT NULL,
  PRIMARY KEY (objid)
) $charset_collate;";

        $table_assessor_bldgfloor = $wpdb->prefix . 'assessor_bldgfloor';
        $sql_assessor_bldgfloor = "CREATE TABLE $table_assessor_bldgfloor (
  objid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  bldgrpuid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  bldguseid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  bldgusename varchar(100) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  floorno varchar(5) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  area decimal(16,2) NOT NULL DEFAULT '0.00',
  storeyrate decimal(16,2) NOT NULL DEFAULT '0.00',
  basevalue decimal(16,2) NOT NULL DEFAULT '0.00',
  unitvalue decimal(16,2) NOT NULL DEFAULT '0.00',
  basemarketvalue decimal(16,2) NOT NULL DEFAULT '0.00',
  adjustment decimal(16,2) NOT NULL DEFAULT '0.00',
  marketvalue decimal(16,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (objid),
  KEY ix_bldgfloor_bldgrpuid (bldgrpuid)
) $charset_collate;";

        $table_assessor_bldgflooradditional = $wpdb->prefix . 'assessor_bldgflooradditional';
        $sql_assessor_bldgflooradditional = "CREATE TABLE $table_assessor_bldgflooradditional (
  objid varchar(50) NOT NULL,
  bldgfloorid varchar(50) NOT NULL,
  bldgrpuid varchar(50) NOT NULL,
  additionalitem_objid varchar(50) NOT NULL,
  amount decimal(16,2) NOT NULL,
  expr text NOT NULL,
  depreciate int DEFAULT NULL,
  issystem int DEFAULT NULL,
  PRIMARY KEY (objid)
) $charset_collate;";

        $table_assessor_bldgrpu = $wpdb->prefix . 'assessor_bldgrpu';
        $sql_assessor_bldgrpu = "CREATE TABLE $table_assessor_bldgrpu (
  objid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  landrpuid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  houseno varchar(25) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  psic varchar(255) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  permitno varchar(255) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  permitdate datetime DEFAULT NULL,
  permitissuedby varchar(255) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  bldgtype_objid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  bldgtypename varchar(100) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  bldgkindbucc_objid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  basevalue decimal(16,2) NOT NULL DEFAULT '0.00',
  dtcompleted datetime DEFAULT NULL,
  dtoccupied datetime DEFAULT NULL,
  dtconstructed date DEFAULT NULL,
  floorcount int NOT NULL DEFAULT '0',
  depreciation decimal(16,2) NOT NULL DEFAULT '0.00',
  depreciationvalue decimal(16,2) NOT NULL DEFAULT '0.00',
  totaladjustment decimal(16,2) NOT NULL DEFAULT '0.00',
  additionalinfo text COLLATE utf8mb4_unicode_520_ci,
  bldgage int NOT NULL DEFAULT '0',
  effectiveage int NOT NULL DEFAULT '0',
  percentcompleted int NOT NULL DEFAULT '100',
  assesslevel decimal(16,2) NOT NULL DEFAULT '0.00',
  condominium int NOT NULL DEFAULT '0',
  bldgclass varchar(15) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  predominant int DEFAULT NULL,
  condocerttitle varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  dtcertcompletion date DEFAULT NULL,
  dtcertoccupancy date DEFAULT NULL,
  cdurating varchar(15) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  occpermitno varchar(25) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  PRIMARY KEY (objid),
  KEY ix_bldgrpu_landrpuid (landrpuid)
) $charset_collate;";

        $table_assessor_bldgrpu_structuraltype = $wpdb->prefix . 'assessor_bldgrpu_structuraltype';
        $sql_assessor_bldgrpu_structuraltype = "CREATE TABLE $table_assessor_bldgrpu_structuraltype (
  objid varchar(50) NOT NULL,
  bldgrpuid varchar(50) NOT NULL,
  bldgtype_objid varchar(50) NOT NULL,
  bldgkindbucc_objid varchar(50) DEFAULT NULL,
  floorcount int NOT NULL,
  basefloorarea decimal(16,2) NOT NULL,
  totalfloorarea decimal(16,2) NOT NULL,
  basevalue decimal(16,2) NOT NULL,
  unitvalue decimal(16,2) NOT NULL,
  classification_objid varchar(50) DEFAULT NULL,
  PRIMARY KEY (objid)
) $charset_collate;";

        $table_assessor_bldgstructure = $wpdb->prefix . 'assessor_bldgstructure';
        $sql_assessor_bldgstructure = "CREATE TABLE $table_assessor_bldgstructure (
  objid varchar(50) NOT NULL,
  bldgrpuid varchar(50) NOT NULL,
  structure_objid varchar(50) NOT NULL,
  material_objid varchar(50) DEFAULT NULL,
  floor int NOT NULL,
  PRIMARY KEY (objid)
) $charset_collate;";

        $table_assessor_bldgtype_depreciation = $wpdb->prefix . 'assessor_bldgtype_depreciation';
        $sql_assessor_bldgtype_depreciation = "CREATE TABLE $table_assessor_bldgtype_depreciation (
  objid varchar(50) NOT NULL,
  bldgtypeid varchar(50) NOT NULL,
  bldgrysettingid varchar(50) NOT NULL,
  agefrom int NOT NULL,
  ageto int NOT NULL,
  rate decimal(16,2) NOT NULL,
  excellent decimal(16,2) DEFAULT NULL,
  verygood decimal(16,2) DEFAULT NULL,
  good decimal(16,2) DEFAULT NULL,
  average decimal(16,2) DEFAULT NULL,
  fair decimal(16,2) DEFAULT NULL,
  poor decimal(16,2) DEFAULT NULL,
  verypoor decimal(16,2) DEFAULT NULL,
  unsound decimal(16,2) DEFAULT NULL,
  PRIMARY KEY (objid)
) $charset_collate;";

        $table_assessor_bldguse = $wpdb->prefix . 'assessor_bldguse';
        $sql_assessor_bldguse = "CREATE TABLE $table_assessor_bldguse (
  objid varchar(50) NOT NULL,
  bldgrpuid varchar(50) NOT NULL,
  structuraltype_objid varchar(50) DEFAULT NULL,
  actualuse_objid varchar(50) NOT NULL,
  basevalue decimal(16,2) NOT NULL,
  area decimal(16,2) NOT NULL,
  basemarketvalue decimal(16,2) NOT NULL,
  depreciationvalue decimal(16,2) NOT NULL,
  adjustment decimal(16,2) NOT NULL,
  marketvalue decimal(16,2) NOT NULL,
  assesslevel decimal(16,2) DEFAULT NULL,
  assessedvalue decimal(16,2) DEFAULT NULL,
  addlinfo varchar(255) DEFAULT NULL,
  adjfordepreciation decimal(16,2) DEFAULT NULL,
  taxable int DEFAULT NULL,
  PRIMARY KEY (objid)
) $charset_collate;";

        $table_assessor_entity = $wpdb->prefix . 'assessor_entity';
        $sql_assessor_entity = "CREATE TABLE $table_assessor_entity (
  objid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  entityno varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  name longtext COLLATE utf8mb4_unicode_520_ci NOT NULL,
  address_text varchar(255) COLLATE utf8mb4_unicode_520_ci NOT NULL DEFAULT '',
  mailingaddress varchar(255) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  type varchar(25) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  sys_lastupdate varchar(25) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  sys_lastupdateby varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  remarks text COLLATE utf8mb4_unicode_520_ci,
  entityname varchar(800) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  address_objid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  mobileno varchar(25) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  phoneno varchar(25) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  email varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  state varchar(25) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  first_name varchar(100) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  last_name varchar(100) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  middle_name varchar(500) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  birthdate date DEFAULT NULL,
  birthplace varchar(160) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  gender varchar(10) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  civil_status varchar(15) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  citizenship varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  profession varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  tin varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  sss varchar(25) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  acr varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  religion varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  height varchar(10) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  weight varchar(10) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  date_registered datetime DEFAULT NULL,
  org_type varchar(25) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  nature_of_business varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  place_registered varchar(100) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  admin_name varchar(100) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  admin_position varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  admin_address varchar(255) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  telephone_no varchar(100) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  PRIMARY KEY (objid),
  UNIQUE KEY uix_entityno (entityno),
  UNIQUE KEY entityno (entityno),
  KEY ix_entityname (entityname(255)),
  KEY ix_address_objid (address_objid),
  KEY ix_state (state),
  KEY ix_entityname_state (state,entityname(255))
) $charset_collate;";

        $table_assessor_entity_address = $wpdb->prefix . 'assessor_entity_address';
        $sql_assessor_entity_address = "CREATE TABLE $table_assessor_entity_address (
  objid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL DEFAULT '',
  parentid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  type varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  addresstype varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  barangay_objid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  barangay_name varchar(100) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  city varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  province varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  municipality varchar(500) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  bldgno varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  bldgname varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  unitno varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  street varchar(255) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  subdivision varchar(100) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  pin varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  text varchar(255) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  PRIMARY KEY (objid),
  KEY ix_barangay_objid (barangay_objid),
  KEY ix_parentid (parentid)
) $charset_collate;";

        $table_assessor_entity_fingerprint = $wpdb->prefix . 'assessor_entity_fingerprint';
        $sql_assessor_entity_fingerprint = "CREATE TABLE $table_assessor_entity_fingerprint (
  objid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  entityid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  dtfiled datetime DEFAULT NULL,
  fingertype varchar(20) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  data longtext COLLATE utf8mb4_unicode_520_ci,
  image longtext COLLATE utf8mb4_unicode_520_ci,
  PRIMARY KEY (objid),
  UNIQUE KEY uix_entityid_fingertype (entityid,fingertype),
  KEY ix_dtfiled (dtfiled)
) $charset_collate;";

        $table_assessor_entity_mapping = $wpdb->prefix . 'assessor_entity_mapping';
        $sql_assessor_entity_mapping = "CREATE TABLE $table_assessor_entity_mapping (
  objid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  parent_objid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  org_objid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  PRIMARY KEY (objid)
) $charset_collate;";

        $table_assessor_entity_reconciled = $wpdb->prefix . 'assessor_entity_reconciled';
        $sql_assessor_entity_reconciled = "CREATE TABLE $table_assessor_entity_reconciled (
  objid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  info longtext COLLATE utf8mb4_unicode_520_ci,
  masterid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  PRIMARY KEY (objid),
  KEY FK_entity_reconciled_entity (masterid)
) $charset_collate;";

        $table_assessor_entity_reconciled_txn = $wpdb->prefix . 'assessor_entity_reconciled_txn';
        $sql_assessor_entity_reconciled_txn = "CREATE TABLE $table_assessor_entity_reconciled_txn (
  objid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  reftype varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  refid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  tag char(1) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  PRIMARY KEY (objid,reftype,refid)
) $charset_collate;";

        $table_assessor_entity_relation = $wpdb->prefix . 'assessor_entity_relation';
        $sql_assessor_entity_relation = "CREATE TABLE $table_assessor_entity_relation (
  objid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  entity_objid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  relateto_objid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  relation_objid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  PRIMARY KEY (objid),
  UNIQUE KEY uix_sender_receiver (entity_objid,relateto_objid),
  KEY ix_entity_objid (entity_objid),
  KEY ix_relateto_objid (relateto_objid),
  KEY ix_relation_objid (relation_objid)
) $charset_collate;";

        $table_assessor_entity_relation_type = $wpdb->prefix . 'assessor_entity_relation_type';
        $sql_assessor_entity_relation_type = "CREATE TABLE $table_assessor_entity_relation_type (
  objid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL DEFAULT '',
  gender varchar(1) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  inverse_any varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  inverse_male varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  inverse_female varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  PRIMARY KEY (objid)
) $charset_collate;";

        $table_assessor_entitycontact = $wpdb->prefix . 'assessor_entitycontact';
        $sql_assessor_entitycontact = "CREATE TABLE $table_assessor_entitycontact (
  objid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  entityid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  contacttype varchar(25) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  contact varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  PRIMARY KEY (objid),
  KEY ix_entityid (entityid)
) $charset_collate;";

        $table_assessor_entityid = $wpdb->prefix . 'assessor_entityid';
        $sql_assessor_entityid = "CREATE TABLE $table_assessor_entityid (
  objid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  entityid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  idtype varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  idno varchar(25) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  dtissued date DEFAULT NULL,
  dtexpiry date DEFAULT NULL,
  PRIMARY KEY (objid),
  UNIQUE KEY uix_idtype_idno (entityid,idtype,idno),
  KEY ix_dtexpiry (dtexpiry),
  KEY ix_entityid (entityid),
  KEY ix_idno (idno),
  KEY ix_idtype (idtype)
) $charset_collate;";

        $table_assessor_entityindividual = $wpdb->prefix . 'assessor_entityindividual';
        $sql_assessor_entityindividual = "CREATE TABLE $table_assessor_entityindividual (
  objid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  lastname varchar(100) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  firstname varchar(100) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  middlename varchar(500) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  birthdate date DEFAULT NULL,
  birthplace varchar(160) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  citizenship varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  gender varchar(10) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  civilstatus varchar(15) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  profession varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  tin varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  sss varchar(25) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  height varchar(10) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  weight varchar(10) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  acr varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  religion varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  photo mediumblob,
  thumbnail blob,
  profileid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  PRIMARY KEY (objid),
  KEY ix_fname (firstname),
  KEY ix_lfname (lastname,firstname),
  KEY ix_ss (sss),
  KEY ix_tin (tin),
  KEY ix_profileid (profileid)
) $charset_collate;";

        $table_assessor_entityjuridical = $wpdb->prefix . 'assessor_entityjuridical';
        $sql_assessor_entityjuridical = "CREATE TABLE $table_assessor_entityjuridical (
  objid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  tin varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  dtregistered datetime DEFAULT NULL,
  orgtype varchar(25) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  nature varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  placeregistered varchar(100) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  administrator_name varchar(100) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  administrator_address varchar(255) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  administrator_position varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  administrator_objid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  administrator_address_objid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  administrator_address_text varchar(255) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  PRIMARY KEY (objid),
  KEY ix_tin (tin),
  KEY ix_dtregistered (dtregistered),
  KEY ix_administrator_objid (administrator_objid),
  KEY ix_administrator_name (administrator_name),
  KEY ix_administrator_address_objid (administrator_address_objid)
) $charset_collate;";

        $table_assessor_entitymember = $wpdb->prefix . 'assessor_entitymember';
        $sql_assessor_entitymember = "CREATE TABLE $table_assessor_entitymember (
  objid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  entityid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  itemno int NOT NULL,
  prefix varchar(100) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  member_objid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  member_name varchar(800) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  member_address_text varchar(160) COLLATE utf8mb4_unicode_520_ci NOT NULL DEFAULT '',
  suffix varchar(100) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  remarks varchar(160) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  member_address varchar(255) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  PRIMARY KEY (objid),
  KEY entityid (entityid),
  KEY ix_taxpayer_objid (member_objid)
) $charset_collate;";

        $table_assessor_entitymultiple = $wpdb->prefix . 'assessor_entitymultiple';
        $sql_assessor_entitymultiple = "CREATE TABLE $table_assessor_entitymultiple (
  objid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  fullname longtext COLLATE utf8mb4_unicode_520_ci,
  PRIMARY KEY (objid)
) $charset_collate;";

        $table_assessor_entityprofile = $wpdb->prefix . 'assessor_entityprofile';
        $sql_assessor_entityprofile = "CREATE TABLE $table_assessor_entityprofile (
  objid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  idno varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  lastname varchar(60) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  firstname varchar(60) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  middlename varchar(60) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  birthdate date DEFAULT NULL,
  gender varchar(10) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  address longtext COLLATE utf8mb4_unicode_520_ci,
  defaultentityid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  PRIMARY KEY (objid),
  KEY ix_defaultentityid (defaultentityid),
  KEY ix_firstname (firstname),
  KEY ix_idno (idno),
  KEY ix_lastname (lastname),
  KEY ix_lfname (lastname,firstname)
) $charset_collate;";

        $table_assessor_exemptiontype = $wpdb->prefix . 'assessor_exemptiontype';
        $sql_assessor_exemptiontype = "CREATE TABLE $table_assessor_exemptiontype (
  objid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  code varchar(20) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  name varchar(100) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  PRIMARY KEY (objid),
  UNIQUE KEY ux_exemptcode (code)
) $charset_collate;";

        $table_assessor_faas = $wpdb->prefix . 'assessor_faas';
        $sql_assessor_faas = "CREATE TABLE $table_assessor_faas (
  objid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  state varchar(25) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  rpuid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  datacapture int NOT NULL DEFAULT '0',
  autonumber int NOT NULL DEFAULT '0',
  utdno varchar(25) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  tdno varchar(25) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  txntype_objid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  effectivityyear int NOT NULL DEFAULT '0',
  effectivityqtr int NOT NULL DEFAULT '0',
  titletype varchar(10) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  titleno varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  titledate datetime DEFAULT NULL,
  taxpayer_objid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  owner_name longtext COLLATE utf8mb4_unicode_520_ci,
  owner_address varchar(150) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  administrator_objid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  administrator_name text COLLATE utf8mb4_unicode_520_ci,
  administrator_address varchar(150) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  beneficiary_objid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  beneficiary_name varchar(150) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  beneficiary_address varchar(150) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  memoranda text COLLATE utf8mb4_unicode_520_ci,
  cancelnote varchar(250) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  restrictionid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  backtaxyrs int NOT NULL DEFAULT '0',
  prevtdno varchar(800) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  prevpin text COLLATE utf8mb4_unicode_520_ci,
  prevowner longtext COLLATE utf8mb4_unicode_520_ci,
  prevav text COLLATE utf8mb4_unicode_520_ci,
  prevmv text COLLATE utf8mb4_unicode_520_ci,
  cancelreason varchar(10) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  canceldate date DEFAULT NULL,
  cancelledbytdnos text COLLATE utf8mb4_unicode_520_ci,
  lguid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL DEFAULT '',
  txntimestamp varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  cancelledtimestamp varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  name varchar(100) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  dtapproved date DEFAULT NULL,
  realpropertyid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  lgutype varchar(25) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  signatories text COLLATE utf8mb4_unicode_520_ci,
  ryordinanceno varchar(25) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  ryordinancedate date DEFAULT NULL,
  prevareaha text COLLATE utf8mb4_unicode_520_ci,
  prevareasqm text COLLATE utf8mb4_unicode_520_ci,
  fullpin varchar(35) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  preveffectivity varchar(10) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  year int DEFAULT NULL,
  qtr int DEFAULT NULL,
  month int DEFAULT NULL,
  day int DEFAULT NULL,
  cancelledyear int DEFAULT NULL,
  cancelledqtr int DEFAULT NULL,
  cancelledmonth int DEFAULT NULL,
  cancelledday int DEFAULT NULL,
  prevadministrator varchar(200) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  originlguid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  parentfaasid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  publicland int DEFAULT NULL,
  assessments longtext COLLATE utf8mb4_unicode_520_ci,
  PRIMARY KEY (objid),
  UNIQUE KEY ux_faas_utdno (utdno),
  KEY FK_faas_rpu (rpuid),
  KEY ix_faas_appraisedby (objid),
  KEY ix_faas_beneficiary (beneficiary_name),
  KEY ix_faas_cancelledtimestamp (cancelledtimestamp),
  KEY ix_faas_name (name),
  KEY ix_faas_realproperty (realpropertyid),
  KEY ix_faas_restrictionid (restrictionid),
  KEY ix_faas_state (state),
  KEY ix_faas_tdno (tdno),
  KEY ix_faas_titleno (titleno),
  KEY ix_faas_txntimestamp (txntimestamp),
  KEY txntype_objid (txntype_objid),
  KEY taxpayer_objid (taxpayer_objid),
  KEY ix_faas_cancelledyear (year),
  KEY ix_faas_cancelledyear_qtr (year,qtr),
  KEY ix_faas_cancelledyear_qtr_month (year,qtr,month),
  KEY ix_faas_cancelledyear_qtr_month_day (year,qtr,month,day),
  KEY ix_faas_year (year),
  KEY ix_faas_year_qtr (year,qtr),
  KEY ix_faas_year_qtr_month (year,qtr,month),
  KEY ix_faas_year_qtr_month_day (year,qtr,month,day),
  KEY ix_dtapproved (dtapproved),
  KEY ix_faas_canceldate (canceldate),
  KEY ix_prevtdno (prevtdno(255))
) $charset_collate;";

        $table_assessor_faas_previous = $wpdb->prefix . 'assessor_faas_previous';
        $sql_assessor_faas_previous = "CREATE TABLE $table_assessor_faas_previous (
  objid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  faasid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  prevfaasid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  prevrpuid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  prevtdno varchar(800) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  prevpin varchar(800) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  prevowner text COLLATE utf8mb4_unicode_520_ci,
  prevadministrator text COLLATE utf8mb4_unicode_520_ci,
  prevav varchar(500) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  prevmv varchar(500) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  prevareasqm varchar(500) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  prevareaha varchar(500) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  preveffectivity varchar(10) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  prevtaxability varchar(10) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  PRIMARY KEY (objid),
  KEY ix_faas_previous_faasid (faasid)
) $charset_collate;";


        $table_assessor_faas_list = $wpdb->prefix . 'assessor_faas_list';
        $sql_assessor_faas_list = "CREATE TABLE $table_assessor_faas_list (
  objid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  state varchar(30) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  rpuid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  realpropertyid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  datacapture int NOT NULL DEFAULT '0',
  ry int NOT NULL DEFAULT '0',
  txntype_objid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  tdno varchar(25) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  utdno varchar(25) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  prevtdno varchar(800) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  displaypin varchar(35) COLLATE utf8mb4_unicode_520_ci NOT NULL DEFAULT '',
  pin varchar(35) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  taxpayer_objid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  owner_name varchar(5000) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  owner_address varchar(150) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  administrator_name varchar(150) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  administrator_address varchar(150) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  rputype varchar(10) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  barangayid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL DEFAULT '',
  barangay varchar(75) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  classification_objid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  classcode varchar(20) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  cadastrallotno varchar(900) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  blockno varchar(100) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  surveyno varchar(255) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  titleno varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  totalareaha decimal(16,6) NOT NULL DEFAULT '0.000000',
  totalareasqm decimal(16,6) NOT NULL DEFAULT '0.000000',
  totalmv decimal(16,2) NOT NULL DEFAULT '0.00',
  totalav decimal(16,2) NOT NULL DEFAULT '0.00',
  effectivityyear int NOT NULL DEFAULT '0',
  effectivityqtr int NOT NULL DEFAULT '0',
  cancelreason varchar(15) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  cancelledbytdnos mediumtext COLLATE utf8mb4_unicode_520_ci,
  lguid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL DEFAULT '',
  originlguid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL DEFAULT '',
  yearissued int DEFAULT NULL,
  taskid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  taskstate varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  assignee_objid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  trackingno varchar(20) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  publicland int DEFAULT NULL,
  assessments longtext COLLATE utf8mb4_unicode_520_ci,
  PRIMARY KEY (objid),
  KEY ix_faaslist_state (state),
  KEY ix_faaslist_rpuid (rpuid),
  KEY ix_faaslist_realpropertyid (realpropertyid),
  KEY ix_faaslist_ry (ry),
  KEY ix_faaslist_tdno (tdno),
  KEY ix_faaslist_utdno (utdno),
  KEY ix_faaslist_prevtdno (prevtdno(255)),
  KEY ix_faaslist_pin (pin),
  KEY ix_faaslist_taxpayer_objid (taxpayer_objid),
  KEY ix_faaslist_owner_name (owner_name(100)),
  KEY ix_faaslist_administrator_name (administrator_name(100)),
  KEY ix_faaslist_rputype (rputype),
  KEY ix_faaslist_barangayid (barangayid),
  KEY ix_faaslist_barangay (barangay),
  KEY ix_faaslist_classification_objid (classification_objid),
  KEY ix_faaslist_classcode (classcode),
  KEY ix_faaslist_cadastrallotno (cadastrallotno(255)),
  KEY ix_faaslist_blockno (blockno),
  KEY ix_faaslist_surveyno (surveyno),
  KEY ix_faaslist_titleno (titleno),
  KEY ix_faaslist_lguid (lguid),
  KEY ix_faaslist_originlguid (originlguid),
  KEY ix_faaslist_taskstate (taskstate),
  KEY ix_faaslist_trackingno (trackingno),
  KEY ix_faaslist_assigneeid (assignee_objid),
  KEY ix_faaslist_publicland (publicland),
  KEY ix_faaslist_txntype_objid (txntype_objid)
) $charset_collate;";

        $table_assessor_faas_signatory = $wpdb->prefix . 'assessor_faas_signatory';
        $sql_assessor_faas_signatory = "CREATE TABLE $table_assessor_faas_signatory (
  objid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  taxmapper_objid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  taxmapper_name varchar(100) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  taxmapper_title varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  taxmapper_dtsigned datetime DEFAULT NULL,
  taxmapperchief_objid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  taxmapperchief_name varchar(100) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  taxmapperchief_title varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  taxmapperchief_dtsigned datetime DEFAULT NULL,
  appraiser_objid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  appraiser_name varchar(150) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  appraiser_title varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  appraiser_dtsigned datetime DEFAULT NULL,
  appraiserchief_objid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  appraiserchief_name varchar(100) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  appraiserchief_title varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  appraiserchief_dtsigned datetime DEFAULT NULL,
  recommender_objid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  recommender_name varchar(100) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  recommender_title varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  recommender_dtsigned datetime DEFAULT NULL,
  approver_objid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  approver_name varchar(100) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  approver_title varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  approver_dtsigned datetime DEFAULT NULL,
  assessor_name varchar(100) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  assessor_title varchar(100) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  reviewer_name varchar(100) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  reviewer_title varchar(75) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  PRIMARY KEY (objid)
) $charset_collate;";

        $table_assessor_faas_txntypes = $wpdb->prefix . 'assessor_faas_txntypes';
        $sql_assessor_faas_txntypes = "CREATE TABLE $table_assessor_faas_txntypes (
  id mediumint NOT NULL AUTO_INCREMENT,
  code varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  name varchar(150) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  status varchar(20) COLLATE utf8mb4_unicode_520_ci NOT NULL DEFAULT 'active',
  sort_order int NOT NULL DEFAULT '0',
  created_at datetime DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY code (code),
  KEY status (status),
  KEY sort_order (sort_order)
) $charset_collate;";

        $table_assessor_landdetail = $wpdb->prefix . 'assessor_landdetail';
        $sql_assessor_landdetail = "CREATE TABLE $table_assessor_landdetail (
  objid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  landrpuid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  subclass_objid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  subclassname varchar(100) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  specificclass_objid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  specificclassname varchar(100) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  actualuse_objid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  actualusename varchar(100) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  stripping_objid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  striprate decimal(16,2) NOT NULL DEFAULT '0.00',
  areatype varchar(10) COLLATE utf8mb4_unicode_520_ci NOT NULL DEFAULT 'SQM',
  addlinfo varchar(250) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  area decimal(18,6) NOT NULL DEFAULT '0.000000',
  areasqm decimal(18,2) NOT NULL DEFAULT '0.00',
  areaha decimal(18,6) NOT NULL DEFAULT '0.000000',
  basevalue decimal(16,2) NOT NULL DEFAULT '0.00',
  unitvalue decimal(16,2) NOT NULL DEFAULT '0.00',
  taxable int NOT NULL DEFAULT '1',
  basemarketvalue decimal(16,2) NOT NULL DEFAULT '0.00',
  adjustment decimal(16,2) NOT NULL DEFAULT '0.00',
  landvalueadjustment decimal(16,2) NOT NULL DEFAULT '0.00',
  actualuseadjustment decimal(16,2) NOT NULL DEFAULT '0.00',
  marketvalue decimal(16,2) NOT NULL DEFAULT '0.00',
  assesslevel decimal(16,2) NOT NULL DEFAULT '0.00',
  assessedvalue decimal(16,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (objid),
  KEY ix_landdetail_landrpuid (landrpuid)
) $charset_collate;";

        $table_assessor_landrpu = $wpdb->prefix . 'assessor_landrpu';
        $sql_assessor_landrpu = "CREATE TABLE $table_assessor_landrpu (
  objid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  idleland int NOT NULL DEFAULT '0',
  publicland int DEFAULT '0',
  totallandbmv decimal(16,2) NOT NULL DEFAULT '0.00',
  totallandmv decimal(16,2) NOT NULL DEFAULT '0.00',
  totallandav decimal(16,2) NOT NULL DEFAULT '0.00',
  totalplanttreebmv decimal(16,2) NOT NULL DEFAULT '0.00',
  totalplanttreemv decimal(16,2) NOT NULL DEFAULT '0.00',
  totalplanttreeadjustment decimal(16,2) NOT NULL DEFAULT '0.00',
  totalplanttreeav decimal(16,2) NOT NULL DEFAULT '0.00',
  landvalueadjustment decimal(16,2) NOT NULL DEFAULT '0.00',
  distanceawr decimal(16,2) DEFAULT NULL,
  distanceltc decimal(16,2) DEFAULT NULL,
  PRIMARY KEY (objid)
) $charset_collate;";

        $table_assessor_machine_smv = $wpdb->prefix . 'assessor_machine_smv';
        $sql_assessor_machine_smv = "CREATE TABLE $table_assessor_machine_smv (
  objid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  parent_objid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  machine_objid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  machinename varchar(250) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  expr varchar(255) COLLATE utf8mb4_unicode_520_ci NOT NULL DEFAULT '',
  acquisitioncost decimal(16,2) DEFAULT NULL,
  acquisitiondate date DEFAULT NULL,
  yearacquired int DEFAULT NULL,
  usefullife int DEFAULT NULL,
  remaininglife int DEFAULT NULL,
  rcnld decimal(16,2) DEFAULT NULL,
  marketvalue decimal(16,2) DEFAULT NULL,
  assesslevel decimal(16,2) DEFAULT NULL,
  assessedvalue decimal(16,2) DEFAULT NULL,
  taxable int DEFAULT '1',
  PRIMARY KEY (objid),
  KEY ix_machinesmv_parent (parent_objid)
) $charset_collate;";

        $table_assessor_machrpu = $wpdb->prefix . 'assessor_machrpu';
        $sql_assessor_machrpu = "CREATE TABLE $table_assessor_machrpu (
  objid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  landrpuid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  bldgmaster_objid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  PRIMARY KEY (objid),
  KEY ix_machrpu_landrpuid (landrpuid)
) $charset_collate;";

        $table_assessor_material = $wpdb->prefix . 'assessor_material';
        $sql_assessor_material = "CREATE TABLE $table_assessor_material (
  objid varchar(50) NOT NULL,
  state varchar(10) NOT NULL,
  code varchar(20) NOT NULL,
  name varchar(100) NOT NULL,
  PRIMARY KEY (objid),
  UNIQUE KEY ux_material_code (code),
  UNIQUE KEY ux_material_name (name),
  KEY ix_material_state (state)
) $charset_collate;";

        $table_assessor_miscrpu = $wpdb->prefix . 'assessor_miscrpu';
        $sql_assessor_miscrpu = "CREATE TABLE $table_assessor_miscrpu (
  objid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  actualuse_objid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  actualusename varchar(100) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  landrpuid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  PRIMARY KEY (objid)
) $charset_collate;";

        $table_assessor_miscrpuitem = $wpdb->prefix . 'assessor_miscrpuitem';
        $sql_assessor_miscrpuitem = "CREATE TABLE $table_assessor_miscrpuitem (
  objid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  miscrpuid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  miscitem_objid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  miscitemname varchar(200) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  expr varchar(255) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  depreciation decimal(16,2) NOT NULL DEFAULT '0.00',
  depreciatedvalue decimal(16,2) NOT NULL DEFAULT '0.00',
  basemarketvalue decimal(16,2) NOT NULL DEFAULT '0.00',
  marketvalue decimal(16,2) NOT NULL DEFAULT '0.00',
  assesslevel decimal(16,2) NOT NULL DEFAULT '0.00',
  assessedvalue decimal(16,2) NOT NULL DEFAULT '0.00',
  appraisalstartdate date DEFAULT NULL,
  taxable int DEFAULT '1',
  PRIMARY KEY (objid),
  KEY ix_miscrpuitem_miscrpuid (miscrpuid)
) $charset_collate;";

        $table_assessor_planttreerpu = $wpdb->prefix . 'assessor_planttreerpu';
        $sql_assessor_planttreerpu = "CREATE TABLE $table_assessor_planttreerpu (
  objid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  landrpuid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  productive decimal(16,2) NOT NULL DEFAULT '0.00',
  nonproductive decimal(16,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (objid),
  KEY ix_planttreerpu_landrpuid (landrpuid)
) $charset_collate;";

        $table_assessor_property_states = $wpdb->prefix . 'assessor_property_states';
        $sql_assessor_property_states = "CREATE TABLE $table_assessor_property_states (
  property_id mediumint NOT NULL,
  state varchar(20) COLLATE utf8mb4_unicode_520_ci NOT NULL DEFAULT 'CURRENT',
  updated_by mediumint DEFAULT NULL,
  updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (property_id),
  KEY state (state)
) $charset_collate;";

        $table_assessor_real_property = $wpdb->prefix . 'assessor_real_property';
        $sql_assessor_real_property = "CREATE TABLE $table_assessor_real_property (
  id mediumint NOT NULL AUTO_INCREMENT,
  objid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  pin varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  cadastrallotno varchar(900) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  surveyno varchar(255) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  blockno varchar(100) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  barangay varchar(100) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  municipality varchar(100) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  province varchar(100) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  north varchar(255) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  south varchar(255) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  east varchar(255) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  west varchar(255) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  etracs_objid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  pintype varchar(5) COLLATE utf8mb4_unicode_520_ci DEFAULT 'old',
  ry int DEFAULT NULL,
  barangayid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  lguid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  lgutype varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  purok varchar(100) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  street varchar(100) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  stewardshipno varchar(3) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  portionof varchar(255) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  autonumber int DEFAULT NULL,
  previd varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  claimno varchar(5) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  section varchar(3) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  parcel varchar(3) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY ux_rp_objid (objid),
  KEY ix_etracs_objid (etracs_objid)
) $charset_collate;";

        $table_assessor_rpu = $wpdb->prefix . 'assessor_rpu';
        $sql_assessor_rpu = "CREATE TABLE $table_assessor_rpu (
  id mediumint NOT NULL AUTO_INCREMENT,
  objid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  state varchar(25) COLLATE utf8mb4_unicode_520_ci DEFAULT 'CURRENT',
  real_property_id mediumint NOT NULL,
  rpu_type varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  classification varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  ry int DEFAULT '0',
  total_market_value float DEFAULT '0',
  total_assessed_value float DEFAULT '0',
  taxable tinyint(1) DEFAULT '1',
  etracs_objid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  total_area_hectare float DEFAULT NULL,
  total_area_sqm float DEFAULT NULL,
  fullpin varchar(35) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  suffix int DEFAULT '0',
  subsuffix int DEFAULT NULL,
  classification_objid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  exemptiontype_objid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  totalbmv decimal(16,2) NOT NULL DEFAULT '0.00',
  previd varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  rpumasterid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  barangayid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY ux_rpu_objid (objid),
  KEY real_property_id (real_property_id),
  KEY ix_etracs_objid (etracs_objid)
) $charset_collate;";

        $table_assessor_rpu_assessment = $wpdb->prefix . 'assessor_rpu_assessment';
        $sql_assessor_rpu_assessment = "CREATE TABLE $table_assessor_rpu_assessment (
  objid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  rpuid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  classification_objid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  classcode varchar(20) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  classname varchar(100) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  actualuse_objid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  actualuse varchar(100) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  areasqm decimal(16,2) NOT NULL DEFAULT '0.00',
  areaha decimal(16,6) NOT NULL DEFAULT '0.000000',
  areatype varchar(10) COLLATE utf8mb4_unicode_520_ci DEFAULT 'SQM',
  marketvalue decimal(16,2) NOT NULL DEFAULT '0.00',
  assesslevel decimal(16,2) NOT NULL DEFAULT '0.00',
  assessedvalue decimal(16,2) NOT NULL DEFAULT '0.00',
  rputype varchar(25) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  taxable int DEFAULT '1',
  PRIMARY KEY (objid),
  KEY ix_rpuassess_rpuid (rpuid)
) $charset_collate;";

        $table_assessor_rpumaster = $wpdb->prefix . 'assessor_rpumaster';
        $sql_assessor_rpumaster = "CREATE TABLE $table_assessor_rpumaster (
  objid varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  currentfaasid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  currentrpuid varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  PRIMARY KEY (objid),
  KEY ix_rpumaster_faasid (currentfaasid),
  KEY ix_rpumaster_rpuid (currentrpuid)
) $charset_collate;";

        $table_assessor_structure = $wpdb->prefix . 'assessor_structure';
        $sql_assessor_structure = "CREATE TABLE $table_assessor_structure (
  objid varchar(50) NOT NULL,
  state varchar(10) NOT NULL,
  code varchar(20) NOT NULL,
  name varchar(100) NOT NULL,
  indexno int NOT NULL,
  showinfaas int NOT NULL,
  PRIMARY KEY (objid),
  UNIQUE KEY ux_structure_code (code),
  UNIQUE KEY ux_structure_name (name),
  KEY ix_structure_state (state)
) $charset_collate;";

        // --- MISSING DETAIL TABLES (needed for sync from ETRACS) ---

        $table_assessor_machdetail = $wpdb->prefix . 'assessor_machdetail';
        $sql_assessor_machdetail = "CREATE TABLE $table_assessor_machdetail (
  objid varchar(50) NOT NULL,
  machuseid varchar(50) DEFAULT NULL,
  machrpuid varchar(50) NOT NULL,
  machine_objid varchar(50) NOT NULL,
  operationyear int DEFAULT NULL,
  replacementcost decimal(16,2) NOT NULL,
  depreciation decimal(16,2) NOT NULL,
  depreciationvalue decimal(16,2) NOT NULL,
  basemarketvalue decimal(16,2) NOT NULL,
  marketvalue decimal(16,2) NOT NULL,
  assesslevel decimal(16,2) NOT NULL,
  assessedvalue decimal(16,2) NOT NULL,
  brand varchar(100) DEFAULT NULL,
  capacity varchar(100) DEFAULT NULL,
  model varchar(100) DEFAULT NULL,
  serialno varchar(100) DEFAULT NULL,
  status varchar(25) DEFAULT NULL,
  yearacquired int DEFAULT NULL,
  estimatedlife int DEFAULT NULL,
  remaininglife int DEFAULT NULL,
  yearinstalled int DEFAULT NULL,
  yearsused int DEFAULT NULL,
  originalcost decimal(16,2) NOT NULL,
  freightcost decimal(16,2) NOT NULL,
  insurancecost decimal(16,2) NOT NULL,
  installationcost decimal(16,2) NOT NULL,
  brokeragecost decimal(16,2) NOT NULL,
  arrastrecost decimal(16,2) NOT NULL,
  othercost decimal(16,2) NOT NULL,
  acquisitioncost decimal(16,2) NOT NULL,
  feracid varchar(50) DEFAULT NULL,
  ferac decimal(16,2) NOT NULL,
  forexid varchar(50) DEFAULT NULL,
  forex decimal(16,2) NOT NULL,
  residualrate decimal(16,4) NOT NULL,
  conversionfactor decimal(16,4) NOT NULL,
  swornamount decimal(16,2) NOT NULL,
  useswornamount int DEFAULT NULL,
  imported int DEFAULT NULL,
  newlyinstalled int DEFAULT NULL,
  autodepreciate int DEFAULT NULL,
  taxable int DEFAULT NULL,
  smvid varchar(50) DEFAULT NULL,
  params text,
  PRIMARY KEY (objid),
  KEY ix_machdetail_machrpuid (machrpuid)
) $charset_collate;";

        $table_assessor_miscitem = $wpdb->prefix . 'assessor_miscitem';
        $sql_assessor_miscitem = "CREATE TABLE $table_assessor_miscitem (
  objid varchar(50) NOT NULL,
  state varchar(10) DEFAULT 'active',
  code varchar(20) NOT NULL,
  name varchar(200) NOT NULL,
  PRIMARY KEY (objid),
  UNIQUE KEY ux_miscitem_code (code)
) $charset_collate;";

        $table_assessor_bldgflooradditional = $wpdb->prefix . 'assessor_bldgflooradditional';
        $sql_assessor_bldgflooradditional = "CREATE TABLE $table_assessor_bldgflooradditional (
  objid varchar(50) NOT NULL,
  bldgfloorid varchar(50) NOT NULL,
  bldgrpuid varchar(50) NOT NULL,
  additionalitem_objid varchar(50) NOT NULL,
  amount decimal(16,2) NOT NULL,
  expr text NOT NULL,
  depreciate int DEFAULT NULL,
  issystem int DEFAULT NULL,
  PRIMARY KEY (objid),
  KEY FK_bldgflooradditional_additionalitem (additionalitem_objid),
  KEY FK_bldgflooradditional_bldgfloor (bldgfloorid),
  KEY FK_bldgflooradditional_bldgrpu (bldgrpuid)
) $charset_collate;";

        $table_assessor_bldgadditionalitem = $wpdb->prefix . 'assessor_bldgadditionalitem';
        $sql_assessor_bldgadditionalitem = "CREATE TABLE $table_assessor_bldgadditionalitem (
  objid varchar(50) NOT NULL,
  bldgrysettingid varchar(50) NOT NULL,
  code varchar(10) NOT NULL,
  name varchar(100) NOT NULL,
  unit varchar(25) NOT NULL,
  expr varchar(100) NOT NULL,
  previd varchar(50) DEFAULT NULL,
  type varchar(50) DEFAULT NULL,
  addareatobldgtotalarea int DEFAULT NULL,
  idx int DEFAULT NULL,
  PRIMARY KEY (objid),
  KEY bldgrysettingid (bldgrysettingid),
  KEY ix_previd (previd)
) $charset_collate;";

        $table_assessor_bldgtype_depreciation = $wpdb->prefix . 'assessor_bldgtype_depreciation';
        $sql_assessor_bldgtype_depreciation = "CREATE TABLE $table_assessor_bldgtype_depreciation (
  objid varchar(50) NOT NULL,
  bldgtypeid varchar(50) NOT NULL,
  bldgrysettingid varchar(50) NOT NULL,
  agefrom int NOT NULL,
  ageto int NOT NULL,
  rate decimal(16,2) NOT NULL,
  excellent decimal(16,2) DEFAULT NULL,
  verygood decimal(16,2) DEFAULT NULL,
  good decimal(16,2) DEFAULT NULL,
  average decimal(16,2) DEFAULT NULL,
  fair decimal(16,2) DEFAULT NULL,
  poor decimal(16,2) DEFAULT NULL,
  verypoor decimal(16,2) DEFAULT NULL,
  unsound decimal(16,2) DEFAULT NULL,
  PRIMARY KEY (objid),
  KEY FK_bldgtype_depreciation_bldgrysetting (bldgrysettingid),
  KEY ix_bldgtypeid (bldgtypeid)
) $charset_collate;";

        $table_assessor_machine_smv = $wpdb->prefix . 'assessor_machine_smv';
        $sql_assessor_machine_smv = "CREATE TABLE $table_assessor_machine_smv (
  objid varchar(50) NOT NULL,
  parent_objid varchar(50) NOT NULL,
  machine_objid varchar(50) NOT NULL,
  expr varchar(255) NOT NULL,
  previd varchar(50) DEFAULT NULL,
  PRIMARY KEY (objid),
  UNIQUE KEY ux_parent_machine (parent_objid,machine_objid),
  KEY ix_parent_objid (parent_objid),
  KEY ix_machine_objid (machine_objid),
  KEY ix_previd (previd)
) $charset_collate;";

        $table_assessor_miscrpuitem = $wpdb->prefix . 'assessor_miscrpuitem';
        $sql_assessor_miscrpuitem = "CREATE TABLE $table_assessor_miscrpuitem (
  objid varchar(50) NOT NULL,
  miscrpuid varchar(50) NOT NULL,
  miv_objid varchar(50) NOT NULL,
  miscitem_objid varchar(50) NOT NULL,
  expr varchar(255) NOT NULL,
  depreciation decimal(16,2) NOT NULL,
  depreciatedvalue decimal(16,2) NOT NULL,
  basemarketvalue decimal(16,2) NOT NULL,
  marketvalue decimal(16,2) NOT NULL,
  assesslevel decimal(16,2) NOT NULL,
  assessedvalue decimal(16,2) NOT NULL,
  appraisalstartdate date DEFAULT NULL,
  taxable int DEFAULT NULL,
  PRIMARY KEY (objid),
  KEY FK_miscrpuitem_miscitem (miscitem_objid),
  KEY FK_miscrpuitem_miscitemvalue (miv_objid),
  KEY FK_miscrpuitem_miscrpu (miscrpuid)
) $charset_collate;";

        $table_assessor_planttreerpu = $wpdb->prefix . 'assessor_planttreerpu';
        $sql_assessor_planttreerpu = "CREATE TABLE $table_assessor_planttreerpu (
  objid varchar(50) NOT NULL,
  landrpuid varchar(50) NOT NULL,
  productive decimal(16,2) NOT NULL,
  nonproductive decimal(16,2) NOT NULL,
  PRIMARY KEY (objid),
  KEY FK_planttreerpu_landrpu (landrpuid)
) $charset_collate;";


        $table_assessor_landassesslevel = $wpdb->prefix . 'assessor_landassesslevel';
        $sql_assessor_landassesslevel = "CREATE TABLE $table_assessor_landassesslevel (
  objid varchar(50) NOT NULL,
  landrysettingid varchar(50) DEFAULT NULL,
  classification_objid varchar(50) DEFAULT NULL,
  code varchar(20) DEFAULT NULL,
  name varchar(100) DEFAULT NULL,
  fixrate int DEFAULT '0',
  rate decimal(16,2) NOT NULL DEFAULT '0.00',
  previd varchar(50) DEFAULT NULL,
  PRIMARY KEY (objid)
) $charset_collate;";

        $table_assessor_bldgassesslevel = $wpdb->prefix . 'assessor_bldgassesslevel';
        $sql_assessor_bldgassesslevel = "CREATE TABLE $table_assessor_bldgassesslevel (
  objid varchar(50) NOT NULL,
  bldgrysettingid varchar(50) DEFAULT NULL,
  classification_objid varchar(50) DEFAULT NULL,
  code varchar(20) DEFAULT NULL,
  name varchar(100) DEFAULT NULL,
  fixrate int DEFAULT '0',
  rate decimal(16,2) NOT NULL DEFAULT '0.00',
  previd varchar(50) DEFAULT NULL,
  PRIMARY KEY (objid)
) $charset_collate;";

        $table_assessor_machassesslevel = $wpdb->prefix . 'assessor_machassesslevel';
        $sql_assessor_machassesslevel = "CREATE TABLE $table_assessor_machassesslevel (
  objid varchar(50) NOT NULL,
  machrysettingid varchar(50) DEFAULT NULL,
  classification_objid varchar(50) DEFAULT NULL,
  code varchar(20) DEFAULT NULL,
  name varchar(100) DEFAULT NULL,
  fixrate int DEFAULT '0',
  rate decimal(16,2) NOT NULL DEFAULT '0.00',
  previd varchar(50) DEFAULT NULL,
  PRIMARY KEY (objid)
) $charset_collate;";

        $table_assessor_planttreeassesslevel = $wpdb->prefix . 'assessor_planttreeassesslevel';
        $sql_assessor_planttreeassesslevel = "CREATE TABLE $table_assessor_planttreeassesslevel (
  objid varchar(50) NOT NULL,
  planttreerysettingid varchar(50) DEFAULT NULL,
  classification_objid varchar(50) DEFAULT NULL,
  code varchar(20) DEFAULT NULL,
  name varchar(100) DEFAULT NULL,
  fixrate int DEFAULT '0',
  rate decimal(16,2) NOT NULL DEFAULT '0.00',
  previd varchar(50) DEFAULT NULL,
  PRIMARY KEY (objid)
) $charset_collate;";

        $table_assessor_miscassesslevel = $wpdb->prefix . 'assessor_miscassesslevel';
        $sql_assessor_miscassesslevel = "CREATE TABLE $table_assessor_miscassesslevel (
  objid varchar(50) NOT NULL,
  miscrysettingid varchar(50) DEFAULT NULL,
  classification_objid varchar(50) DEFAULT NULL,
  code varchar(20) DEFAULT NULL,
  name varchar(100) DEFAULT NULL,
  fixrate int DEFAULT '0',
  rate decimal(16,2) NOT NULL DEFAULT '0.00',
  previd varchar(50) DEFAULT NULL,
  PRIMARY KEY (objid)
) $charset_collate;";



        // Execute SQL statements
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        dbDelta($sql_users);
        dbDelta($sql_properties);
        dbDelta($sql_versions);
        dbDelta($sql_documents);
        dbDelta($sql_audit);
        
        // Ensure record_id is varchar(50) for ULID support (dbDelta sometimes skips type changes)
        $wpdb->query("ALTER TABLE $table_audit MODIFY record_id VARCHAR(50)");

        dbDelta($sql_settings);
        if (!$wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}assessor_property_types'")) {
            $wpdb->query($sql_property_types);
        } else {
            dbDelta($sql_property_types);
        }

        if (!$wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}assessor_general_classes'")) {
            $wpdb->query($sql_general_classes);
        } else {
            dbDelta($sql_general_classes);
        }

        if (!$wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}assessor_locations'")) {
            $wpdb->query($sql_locations);
        } else {
            dbDelta($sql_locations);
        }
        dbDelta($sql_request_purposes);
        dbDelta($sql_requests);
        dbDelta($sql_revision_entries);
        dbDelta($sql_api_keys);
        dbDelta($sql_sync_queue);
        dbDelta($sql_sync_meta);
        
        dbDelta($sql_bldgrysetting);
        dbDelta($sql_bldgkind);
        dbDelta($sql_bldgkindbucc);
        dbDelta($sql_bldgtype);
        dbDelta($sql_propertyclassification);
        
        

                dbDelta($sql_assessor_barangay);
        dbDelta($sql_assessor_bldgadditionalitem);
        dbDelta($sql_assessor_bldgfloor);
        dbDelta($sql_assessor_bldgflooradditional);
        dbDelta($sql_assessor_bldgrpu);
        dbDelta($sql_assessor_bldgrpu_structuraltype);
        dbDelta($sql_assessor_bldgstructure);
        dbDelta($sql_assessor_bldgtype_depreciation);
        dbDelta($sql_assessor_bldguse);
        dbDelta($sql_assessor_entity);
        dbDelta($sql_assessor_entity_address);
        dbDelta($sql_assessor_entity_fingerprint);
        dbDelta($sql_assessor_entity_mapping);
        dbDelta($sql_assessor_entity_reconciled);
        dbDelta($sql_assessor_entity_reconciled_txn);
        dbDelta($sql_assessor_entity_relation);
        dbDelta($sql_assessor_entity_relation_type);
        dbDelta($sql_assessor_entitycontact);
        dbDelta($sql_assessor_entityid);
        dbDelta($sql_assessor_entityindividual);
        dbDelta($sql_assessor_entityjuridical);
        dbDelta($sql_assessor_entitymember);
        dbDelta($sql_assessor_entitymultiple);
        dbDelta($sql_assessor_entityprofile);
        dbDelta($sql_assessor_exemptiontype);
        dbDelta($sql_assessor_faas);
        dbDelta($sql_assessor_faas_previous);
        dbDelta($sql_assessor_faas_list);
        dbDelta($sql_assessor_faas_signatory);
        dbDelta($sql_assessor_faas_txntypes);
        dbDelta($sql_assessor_landdetail);
        dbDelta($sql_assessor_landrpu);
        dbDelta($sql_assessor_machine_smv);
        dbDelta($sql_assessor_machrpu);
        dbDelta($sql_assessor_material);
        dbDelta($sql_assessor_miscrpu);
        dbDelta($sql_assessor_miscrpuitem);
        dbDelta($sql_assessor_planttreerpu);
        if (!$wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}assessor_property_states'")) {
            $wpdb->query($sql_assessor_property_states);
        } else {
            dbDelta($sql_assessor_property_states);
        }
        dbDelta($sql_assessor_real_property);
        dbDelta($sql_assessor_rpu);
        dbDelta($sql_assessor_rpu_assessment);
        dbDelta($sql_assessor_rpumaster);
        dbDelta($sql_assessor_structure);

        // Missing detail & assess-level tables
        dbDelta($sql_assessor_machdetail);
        dbDelta($sql_assessor_miscitem);
        dbDelta($sql_assessor_bldgflooradditional);
        dbDelta($sql_assessor_bldgadditionalitem);
        dbDelta($sql_assessor_bldgtype_depreciation);
        dbDelta($sql_assessor_machine_smv);
        dbDelta($sql_assessor_miscrpuitem);
        dbDelta($sql_assessor_planttreerpu);
        dbDelta($sql_assessor_faas_signatory);
        dbDelta($sql_assessor_landassesslevel);
        dbDelta($sql_assessor_bldgassesslevel);
        dbDelta($sql_assessor_machassesslevel);
        dbDelta($sql_assessor_planttreeassesslevel);
        dbDelta($sql_assessor_miscassesslevel);


        // Add foreign key constraints separately
        $this->add_foreign_keys();
        
        // Insert default admin user if table is empty
        $this->insert_default_admin();

        // Seed initial settings data
        $this->seed_default_settings();

        // Run database migrations
        $this->run_migrations();

        // Adjust users table: make email nullable and non-unique to allow multiple NULL emails
        $this->adjust_users_email_column_and_index();
        // Ensure last_login column exists
        $this->ensure_users_last_login_column();
        // Ensure avatar_url column exists
        $this->ensure_users_avatar_column();
    }
    
    private function add_foreign_keys() {
        global $wpdb;
        
        $table_properties = $wpdb->prefix . 'assessor_properties';
        $table_versions = $wpdb->prefix . 'assessor_property_versions';
        $table_documents = $wpdb->prefix . 'assessor_documents';
        
        // Add foreign key for property_versions.property_id -> properties.id if it does not already exist
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = %s AND CONSTRAINT_NAME = %s",
            $table_versions,
            'fk_property_versions_property_id'
        ));
        if (intval($exists) === 0) {
            $wpdb->query("ALTER TABLE $table_versions ADD CONSTRAINT fk_property_versions_property_id FOREIGN KEY (property_id) REFERENCES $table_properties(id) ON DELETE CASCADE");
        }
        
        // Add foreign key for documents.property_id -> properties.id if it does not already exist
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = %s AND CONSTRAINT_NAME = %s",
            $table_documents,
            'fk_documents_property_id'
        ));
        if (intval($exists) === 0) {
            $wpdb->query("ALTER TABLE $table_documents ADD CONSTRAINT fk_documents_property_id FOREIGN KEY (property_id) REFERENCES $table_properties(id) ON DELETE CASCADE");
        }
    }
    
    private function insert_default_admin() {
        global $wpdb;
        
        $table_users = $wpdb->prefix . 'assessor_users';
        $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_users");
        
        if ($count == 0) {
            if (!class_exists('Assessor_ULID')) {
                require_once ASSESSOR_API_PLUGIN_DIR . 'includes/class-assessor-ulid.php';
            }
            $super_id = Assessor_ULID::generate_with_prefix('GBL');
            
            // Create default superadmin as requested
            $wpdb->insert(
                $table_users,
                array(
                    'id' => $super_id,
                    'username' => 'super',
                    'password' => wp_hash_password('super'),
                    'email' => 'superadmin@localgov.ph',
                    'full_name' => 'Super Administrator',
                    'role' => 'superadmin',
                    'status' => 'active'
                ),
                array('%s', '%s', '%s', '%s', '%s', '%s', '%s')
            );
            $admin_id = Assessor_ULID::generate_with_prefix('GBL');
            
            // Create default admin as requested
            $wpdb->insert(
                $table_users,
                array(
                    'id' => $admin_id,
                    'username' => 'admin',
                    'password' => wp_hash_password('admin123'),
                    'email' => 'admin@localgov.ph',
                    'full_name' => 'System Administrator',
                    'role' => 'admin',
                    'status' => 'active'
                ),
                array('%s', '%s', '%s', '%s', '%s', '%s', '%s')
            );
        }
        // If table already has users, ensure superadmin exists
        $exists_super = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_users WHERE username = %s", 'super'));
        if (!$exists_super) {
            if (!class_exists('Assessor_ULID')) {
                require_once ASSESSOR_API_PLUGIN_DIR . 'includes/class-assessor-ulid.php';
            }
            $super_id = Assessor_ULID::generate_with_prefix('GBL');

            $wpdb->insert(
                $table_users,
                array(
                    'id' => $super_id,
                    'username' => 'super',
                    'password' => wp_hash_password('super'),
                    'email' => 'superadmin@localgov.ph',
                    'full_name' => 'Super Administrator',
                    'role' => 'superadmin',
                    'status' => 'active'
                ),
                array('%s', '%s', '%s', '%s', '%s', '%s', '%s')
            );
        }
    }

    private function seed_default_settings() {
        global $wpdb;

        $table_property_types = $wpdb->prefix . 'assessor_property_types';
        $table_general_classes = $wpdb->prefix . 'assessor_general_classes';

        $types_count = intval($wpdb->get_var("SELECT COUNT(*) FROM $table_property_types"));
        if ($types_count === 0) {
            $default_types = array(
                array('code' => 'LAND', 'name' => 'LAND', 'sort_order' => 1, 'status' => 'active'),
                array('code' => 'BUILDING', 'name' => 'BUILDING', 'sort_order' => 2, 'status' => 'active'),
                array('code' => 'MACHINERY', 'name' => 'MACHINERY', 'sort_order' => 3, 'status' => 'active'),
                array('code' => 'IMPROVEMENTS', 'name' => 'IMPROVEMENTS', 'sort_order' => 4, 'status' => 'active'),
                array('code' => 'PLANT_TREES', 'name' => 'PLANT/TREES', 'sort_order' => 5, 'status' => 'active')
            );
            foreach ($default_types as $row) {
                $wpdb->insert($table_property_types, $row, array('%s','%s','%d','%s'));
            }
        }

        $classes_count = intval($wpdb->get_var("SELECT COUNT(*) FROM $table_general_classes"));
        if ($classes_count === 0) {
            $default_classes = array(
                array('code' => 'RESIDENTIAL', 'name' => 'RESIDENTIAL', 'sort_order' => 1, 'status' => 'active'),
                array('code' => 'AGRICULTURAL', 'name' => 'AGRICULTURAL', 'sort_order' => 2, 'status' => 'active'),
                array('code' => 'COMMERCIAL', 'name' => 'COMMERCIAL', 'sort_order' => 3, 'status' => 'active'),
                array('code' => 'INDUSTRIAL', 'name' => 'INDUSTRIAL', 'sort_order' => 4, 'status' => 'active'),
                array('code' => 'MINERAL', 'name' => 'MINERAL', 'sort_order' => 5, 'status' => 'active'),
                array('code' => 'SPECIAL', 'name' => 'SPECIAL', 'sort_order' => 6, 'status' => 'active'),
                array('code' => 'TIMBERLAND_FORESTAL', 'name' => 'TIMBERLAND/FORESTAL', 'sort_order' => 7, 'status' => 'active'),
                array('code' => 'IMPROVEMENTS', 'name' => 'IMPROVEMENTS', 'sort_order' => 8, 'status' => 'active')
            );
            foreach ($default_classes as $row) {
                $wpdb->insert($table_general_classes, $row, array('%s','%s','%d','%s'));
            }
        }

        $table_locations = $wpdb->prefix . 'assessor_locations';
        $locations_count = intval($wpdb->get_var("SELECT COUNT(*) FROM $table_locations"));
        if ($locations_count === 0) {
            $default_locations = array(
                array('code' => 'BARANGAY', 'name' => 'BARANGAY', 'sort_order' => 1, 'status' => 'active')
            );
            foreach ($default_locations as $row) {
                $wpdb->insert($table_locations, $row, array('%s','%s','%d','%s'));
            }
        }
    }

    private function adjust_users_email_column_and_index() {
        global $wpdb;
        $table_users = $wpdb->prefix . 'assessor_users';

        // Ensure email column allows NULL
        // Some environments may not update nullability via dbDelta reliably
        $wpdb->query("ALTER TABLE $table_users MODIFY email varchar(100) NULL DEFAULT NULL");

        // Drop UNIQUE index on email if it exists, then add a normal (non-unique) index
        $index = $wpdb->get_row($wpdb->prepare("SHOW INDEX FROM $table_users WHERE Key_name = %s", 'email'));
        if ($index && intval($index->Non_unique) === 0) {
            // It is a unique index; drop it
            $wpdb->query("ALTER TABLE $table_users DROP INDEX email");
            // Re-add as non-unique index for lookup performance
            $wpdb->query("ALTER TABLE $table_users ADD INDEX email (email)");
        }
    }

    private function ensure_users_last_login_column() {
        global $wpdb;
        $table_users = $wpdb->prefix . 'assessor_users';
        $column = $wpdb->get_var($wpdb->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'last_login'", $table_users));
        if (!$column) {
            $wpdb->query("ALTER TABLE $table_users ADD COLUMN last_login datetime NULL AFTER status");
        }
    }

    private function ensure_users_avatar_column() {
        global $wpdb;
        $table_users = $wpdb->prefix . 'assessor_users';
        $column = $wpdb->get_var($wpdb->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'avatar_url'", $table_users));
        if (!$column) {
            $wpdb->query("ALTER TABLE $table_users ADD COLUMN avatar_url varchar(255) DEFAULT NULL AFTER email");
        }
    }

    private function run_migrations() {
        global $wpdb;
        
        // Migration: Add header_photo_url column to settings table
        $table_settings = $wpdb->prefix . 'assessor_settings';
        $column = $wpdb->get_var($wpdb->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'header_photo_url'", $table_settings));
        if (!$column) {
            $wpdb->query("ALTER TABLE $table_settings ADD COLUMN header_photo_url varchar(500) DEFAULT '' AFTER app_logo_url");
        }
        
        // Migration: Add area_hectare_old column to properties table
        $table_properties = $wpdb->prefix . 'assessor_properties';
        $column = $wpdb->get_var($wpdb->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'survey_number'", $table_properties));
        if (!$column) {
            $wpdb->query("ALTER TABLE $table_properties ADD COLUMN survey_number varchar(100) DEFAULT NULL AFTER unique_lot_number_identified");
        }

        $column = $wpdb->get_var($wpdb->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'area_hectare_old'", $table_properties));
        if (!$column) {
            $wpdb->query("ALTER TABLE $table_properties ADD COLUMN area_hectare_old varchar(255) DEFAULT NULL AFTER area_hectare");
        }
        
        // Migration: Add area_hectare_old column to property_versions table
        $table_versions = $wpdb->prefix . 'assessor_property_versions';
        $column = $wpdb->get_var($wpdb->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'survey_number'", $table_versions));
        if (!$column) {
            $wpdb->query("ALTER TABLE $table_versions ADD COLUMN survey_number varchar(100) DEFAULT NULL AFTER unique_lot_number_identified");
        }

        $column = $wpdb->get_var($wpdb->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'area_hectare_old'", $table_versions));
        if (!$column) {
            $wpdb->query("ALTER TABLE $table_versions ADD COLUMN area_hectare_old varchar(255) DEFAULT NULL AFTER area_hectare");
        }

        // Migration: seed survey_number from unique_lot_number_identified for existing rows
        $wpdb->query("UPDATE $table_properties SET survey_number = unique_lot_number_identified WHERE (survey_number IS NULL OR survey_number = '') AND unique_lot_number_identified IS NOT NULL AND unique_lot_number_identified != ''");
        $wpdb->query("UPDATE $table_versions SET survey_number = unique_lot_number_identified WHERE (survey_number IS NULL OR survey_number = '') AND unique_lot_number_identified IS NOT NULL AND unique_lot_number_identified != ''");

        // Migration: Add supporting_documents_old column to properties table
        $column = $wpdb->get_var($wpdb->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'supporting_documents_old'", $table_properties));
        if (!$column) {
            $wpdb->query("ALTER TABLE $table_properties ADD COLUMN supporting_documents_old text AFTER supporting_documents");
        }

        // Migration: Add supporting_documents_old column to property_versions table
        $column = $wpdb->get_var($wpdb->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'supporting_documents_old'", $table_versions));
        if (!$column) {
            $wpdb->query("ALTER TABLE $table_versions ADD COLUMN supporting_documents_old text AFTER supporting_documents");
        }

        // Migration: Add assessed_value_old column to properties table
        $column = $wpdb->get_var($wpdb->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'assessed_value_old'", $table_properties));
        if (!$column) {
            $wpdb->query("ALTER TABLE $table_properties ADD COLUMN assessed_value_old varchar(255) AFTER assessed_value");
        }

        // Migration: Add assessed_value_old column to property_versions table
        $column = $wpdb->get_var($wpdb->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'assessed_value_old'", $table_versions));
        if (!$column) {
            $wpdb->query("ALTER TABLE $table_versions ADD COLUMN assessed_value_old varchar(255) AFTER assessed_value");
        }

        // Migration: Add afk_timeout column to settings table
        $column = $wpdb->get_var($wpdb->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'afk_timeout'", $table_settings));
        if (!$column) {
            $wpdb->query("ALTER TABLE $table_settings ADD COLUMN afk_timeout int DEFAULT 30 AFTER municipal_assessor_license");
        }

        // Migration: Public API master switch on settings row
        $column = $wpdb->get_var($wpdb->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'public_api_enabled'", $table_settings));
        if (!$column) {
            $wpdb->query("ALTER TABLE $table_settings ADD COLUMN public_api_enabled tinyint(1) NOT NULL DEFAULT 0");
        }

        // Migration: Add request_place_issued_default column to settings table
        $column = $wpdb->get_var($wpdb->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'request_place_issued_default'", $table_settings));
        if (!$column) {
            $wpdb->query("ALTER TABLE $table_settings ADD COLUMN request_place_issued_default varchar(255) DEFAULT '' AFTER header_office");
        }

        // Cleanup: remove legacy purpose fields from settings table (migrated to assessor_request_purposes)
        $column = $wpdb->get_var($wpdb->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'request_purposes'", $table_settings));
        if ($column) {
            $wpdb->query("ALTER TABLE $table_settings DROP COLUMN request_purposes");
        }
        $column = $wpdb->get_var($wpdb->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'request_purpose_amounts'", $table_settings));
        if ($column) {
            $wpdb->query("ALTER TABLE $table_settings DROP COLUMN request_purpose_amounts");
        }

        // Normalize existing request purposes: spaces -> underscores (skip conflicts)
        $table_request_purposes = $wpdb->prefix . 'assessor_request_purposes';
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_request_purposes));
        if (!empty($table_exists)) {
            $rows = $wpdb->get_results("SELECT id, purpose FROM $table_request_purposes WHERE purpose LIKE '% %'", ARRAY_A);
            if (!empty($rows)) {
                foreach ($rows as $r) {
                    $id = intval($r['id']);
                    $old = isset($r['purpose']) ? (string)$r['purpose'] : '';
                    $normalized = trim(preg_replace('/\s+/', ' ', $old));
                    $new = str_replace(' ', '_', $normalized);
                    if ($new === '' || $new === $old) continue;

                    $conflict = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM $table_request_purposes WHERE LOWER(purpose) = LOWER(%s) AND id != %d LIMIT 1",
                        $new,
                        $id
                    ));
                    if ($conflict) continue;

                    $wpdb->update(
                        $table_request_purposes,
                        array('purpose' => $new),
                        array('id' => $id),
                        array('%s'),
                        array('%d')
                    );
                }
            }
        }

        $table_api_keys = $wpdb->prefix . 'assessor_api_keys';
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_api_keys));
        if (!$table_exists) {
            $charset_collate = $wpdb->get_charset_collate();
            $sql_api_keys = "CREATE TABLE $table_api_keys (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                name varchar(150) NOT NULL,
                api_scope varchar(80) NOT NULL DEFAULT 'public_properties',
                api_key varchar(80) NOT NULL,
                secret_hash varchar(255) NOT NULL,
                secret_encrypted text NULL,
                key_hash varchar(255) NOT NULL DEFAULT '',
                key_prefix varchar(30) NOT NULL DEFAULT '',
                status varchar(20) NOT NULL DEFAULT 'active',
                created_by mediumint(9) DEFAULT NULL,
                last_used_at datetime NULL,
                last_used_ip varchar(45) DEFAULT '',
                revoked_at datetime NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY api_key (api_key),
                KEY api_scope (api_scope),
                KEY status (status),
                KEY created_by (created_by),
                KEY created_at (created_at)
            ) $charset_collate;";
            $wpdb->query($sql_api_keys);
        }
        $column = $wpdb->get_var($wpdb->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'api_key'", $table_api_keys));
        if (!$column) {
            $wpdb->query("ALTER TABLE $table_api_keys ADD COLUMN api_key varchar(80) NOT NULL DEFAULT '' AFTER api_scope");
        }
        $column = $wpdb->get_var($wpdb->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'secret_hash'", $table_api_keys));
        if (!$column) {
            $wpdb->query("ALTER TABLE $table_api_keys ADD COLUMN secret_hash varchar(255) NOT NULL DEFAULT '' AFTER api_key");
        }
        $wpdb->query("UPDATE $table_api_keys SET api_key = key_prefix WHERE (api_key IS NULL OR api_key = '') AND key_prefix != ''");
        $wpdb->query("UPDATE $table_api_keys SET secret_hash = key_hash WHERE (secret_hash IS NULL OR secret_hash = '') AND key_hash != ''");
        $wpdb->query("UPDATE $table_api_keys SET status = 'disabled' WHERE status = 'revoked'");
        $column = $wpdb->get_var($wpdb->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'secret_encrypted'", $table_api_keys));
        if (!$column) {
            $wpdb->query("ALTER TABLE $table_api_keys ADD COLUMN secret_encrypted text NULL AFTER secret_hash");
        }

        // Migration: Ensure avatar_url exists on users table
        $table_users = $wpdb->prefix . 'assessor_users';
        $column = $wpdb->get_var($wpdb->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'avatar_url'", $table_users));
        if (!$column) {
            $wpdb->query("ALTER TABLE $table_users ADD COLUMN avatar_url varchar(255) DEFAULT NULL AFTER email");
        }

        // Migration: Ensure revision entries table exists
        $table_revision_entries = $wpdb->prefix . 'assessor_revision_entries';
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_revision_entries));
        if (!$table_exists) {
            $charset_collate = $wpdb->get_charset_collate();
            $sql_revision_entries = "CREATE TABLE $table_revision_entries (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                revision_year varchar(100) NOT NULL,
                from_year varchar(10) NOT NULL,
                to_year varchar(20) NOT NULL,
                status varchar(20) NOT NULL DEFAULT 'active',
                sort_order int NOT NULL DEFAULT 0,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY status (status),
                KEY sort_order (sort_order)
            ) $charset_collate;";
            $wpdb->query($sql_revision_entries);
        } else {
            // Migration: Update to_year column length if it exists but is too short
            $column_info = $wpdb->get_row($wpdb->prepare("SELECT CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'to_year'", $table_revision_entries));
            if ($column_info && intval($column_info->CHARACTER_MAXIMUM_LENGTH) < 20) {
                $wpdb->query("ALTER TABLE $table_revision_entries MODIFY to_year varchar(20) NOT NULL");
            }
        }

        // Migration: Expand declarant name fields to varchar(255) in properties table
        $table_properties = $wpdb->prefix . 'assessor_properties';
        $col = $wpdb->get_var($wpdb->prepare("SELECT CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'declarant_last_name'", $table_properties));
        if ($col && intval($col) < 255) {
            $wpdb->query("ALTER TABLE $table_properties MODIFY declarant_last_name varchar(255) NOT NULL");
        }
        $col = $wpdb->get_var($wpdb->prepare("SELECT CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'declarant_first_name'", $table_properties));
        if ($col && intval($col) < 255) {
            $wpdb->query("ALTER TABLE $table_properties MODIFY declarant_first_name varchar(255) NOT NULL");
        }
        $col = $wpdb->get_var($wpdb->prepare("SELECT CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'declarant_middle_initial'", $table_properties));
        if ($col && intval($col) < 255) {
            $wpdb->query("ALTER TABLE $table_properties MODIFY declarant_middle_initial varchar(255) NULL");
        }

        // Migration: Expand declarant name fields to varchar(255) in property_versions table
        $table_versions = $wpdb->prefix . 'assessor_property_versions';
        $col = $wpdb->get_var($wpdb->prepare("SELECT CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'declarant_last_name'", $table_versions));
        if ($col && intval($col) < 255) {
            $wpdb->query("ALTER TABLE $table_versions MODIFY declarant_last_name varchar(255) NOT NULL");
        }
        $col = $wpdb->get_var($wpdb->prepare("SELECT CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'declarant_first_name'", $table_versions));
        if ($col && intval($col) < 255) {
            $wpdb->query("ALTER TABLE $table_versions MODIFY declarant_first_name varchar(255) NOT NULL");
        }
        $col = $wpdb->get_var($wpdb->prepare("SELECT CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'declarant_middle_initial'", $table_versions));
        if ($col && intval($col) < 255) {
            $wpdb->query("ALTER TABLE $table_versions MODIFY declarant_middle_initial varchar(255) NULL");
        }

        // Migration: Add revision_id column to properties table
        $table_properties = $wpdb->prefix . 'assessor_properties';
        $column = $wpdb->get_var($wpdb->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'revision_id'", $table_properties));
        if (!$column) {
            // Prefer placing after status if it exists; otherwise append at the end
            $has_status = $wpdb->get_var($wpdb->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'status'", $table_properties));
            if ($has_status) {
                $wpdb->query("ALTER TABLE $table_properties ADD COLUMN revision_id mediumint(9) DEFAULT NULL AFTER status");
            } else {
                $wpdb->query("ALTER TABLE $table_properties ADD COLUMN revision_id mediumint(9) DEFAULT NULL");
            }
        }

        // Migration: Add revision_id column to property_versions table
        $table_versions = $wpdb->prefix . 'assessor_property_versions';
        $column = $wpdb->get_var($wpdb->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'revision_id'", $table_versions));
        if (!$column) {
            // Place after status only if such column exists; otherwise append
            $has_status_versions = $wpdb->get_var($wpdb->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'status'", $table_versions));
            if ($has_status_versions) {
                $wpdb->query("ALTER TABLE $table_versions ADD COLUMN revision_id mediumint(9) DEFAULT NULL AFTER status");
            } else {
                $wpdb->query("ALTER TABLE $table_versions ADD COLUMN revision_id mediumint(9) DEFAULT NULL");
            }
        }

        // Migration: Ensure pin column exists on assessor_locations table
        $table_locations = $wpdb->prefix . 'assessor_locations';
        $column = $wpdb->get_var($wpdb->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'pin'", $table_locations));
        if (!$column) {
            $wpdb->query("ALTER TABLE $table_locations ADD COLUMN pin varchar(50) DEFAULT NULL AFTER name");
        }

        // Migration: Backfill property states for existing properties
        $table_property_states = $wpdb->prefix . 'assessor_property_states';
        // 1. First, insert all missing properties as 'CURRENT'
        $wpdb->query("INSERT IGNORE INTO $table_property_states (property_id, state) SELECT id, 'CURRENT' FROM $table_properties");
        // 2. Then update state to 'CANCELLED' for any property whose TD number is listed as 'previous_tax_declaration_number' in another property
        $wpdb->query("
            UPDATE $table_property_states s
            INNER JOIN $table_properties p ON s.property_id = p.id
            SET s.state = 'CANCELLED'
            WHERE EXISTS (
                SELECT 1 FROM $table_properties child 
                WHERE child.previous_tax_declaration_number = p.tax_declaration_number 
                AND child.previous_tax_declaration_number != ''
            )
        ");
    }
}

