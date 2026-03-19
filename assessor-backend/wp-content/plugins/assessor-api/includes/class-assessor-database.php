<?php

class Assessor_Database {
    
    public function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Users table
        $table_users = $wpdb->prefix . 'assessor_users';
        $sql_users = "CREATE TABLE $table_users (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
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
            created_by mediumint(9) NOT NULL,
            updated_by mediumint(9) NOT NULL,
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
            created_by mediumint(9) NOT NULL,
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
            uploaded_by mediumint(9) NOT NULL,
            uploaded_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY property_id (property_id),
            KEY file_type (file_type)
        ) $charset_collate;";
        
        // Audit trail table
        $table_audit = $wpdb->prefix . 'assessor_audit_trail';
        $sql_audit = "CREATE TABLE $table_audit (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id mediumint(9) NOT NULL,
            action varchar(100) NOT NULL,
            table_name varchar(100) NOT NULL,
            record_id mediumint(9),
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
        $sql_settings = "CREATE TABLE $table_settings (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            app_logo_url varchar(500) DEFAULT '',
            header_photo_url varchar(500) DEFAULT '',
            header_province varchar(255) DEFAULT '',
            header_municipality varchar(255) DEFAULT '',
            header_office varchar(255) DEFAULT '',
            request_place_issued_default varchar(255) DEFAULT '',
            verifier_signatory_name varchar(255) DEFAULT '',
            verifier_signatory_title varchar(255) DEFAULT '',
            municipal_assessor_name varchar(255) DEFAULT '',
            municipal_assessor_suffix varchar(255) DEFAULT '',
            municipal_assessor_title varchar(255) DEFAULT '',
            municipal_assessor_license varchar(255) DEFAULT '',
            afk_timeout int DEFAULT 30,
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
            created_by bigint(20) DEFAULT NULL,
            updated_by bigint(20) DEFAULT NULL,
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
        
        // Execute SQL statements
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        dbDelta($sql_users);
        dbDelta($sql_properties);
        dbDelta($sql_versions);
        dbDelta($sql_documents);
        dbDelta($sql_audit);
        dbDelta($sql_settings);
        dbDelta($sql_property_types);
        dbDelta($sql_general_classes);
        dbDelta($sql_locations);
        dbDelta($sql_request_purposes);
        dbDelta($sql_requests);
        dbDelta($sql_revision_entries);
        
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
            // Create default superadmin as requested
            $wpdb->insert(
                $table_users,
                array(
                    'username' => 'super',
                    'password' => wp_hash_password('super'),
                    'email' => 'superadmin@localgov.ph',
                    'full_name' => 'Super Administrator',
                    'role' => 'superadmin',
                    'status' => 'active'
                ),
                array('%s', '%s', '%s', '%s', '%s', '%s')
            );
            // Create default admin as requested
            $wpdb->insert(
                $table_users,
                array(
                    'username' => 'admin',
                    'password' => wp_hash_password('admin123'),
                    'email' => 'admin@localgov.ph',
                    'full_name' => 'System Administrator',
                    'role' => 'admin',
                    'status' => 'active'
                ),
                array('%s', '%s', '%s', '%s', '%s', '%s')
            );
        }
        // If table already has users, ensure superadmin exists
        $exists_super = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_users WHERE username = %s", 'super'));
        if (!$exists_super) {
            $wpdb->insert(
                $table_users,
                array(
                    'username' => 'super',
                    'password' => wp_hash_password('super'),
                    'email' => 'superadmin@localgov.ph',
                    'full_name' => 'Super Administrator',
                    'role' => 'superadmin',
                    'status' => 'active'
                ),
                array('%s', '%s', '%s', '%s', '%s', '%s')
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
                array('code' => 'LAND', 'name' => 'LAND', 'sort_order' => 1),
                array('code' => 'BUILDING', 'name' => 'BUILDING', 'sort_order' => 2),
                array('code' => 'MACHINERY', 'name' => 'MACHINERY', 'sort_order' => 3),
                array('code' => 'IMPROVEMENTS', 'name' => 'IMPROVEMENTS', 'sort_order' => 4),
                array('code' => 'PLANT_TREES', 'name' => 'PLANT/TREES', 'sort_order' => 5)
            );
            foreach ($default_types as $row) {
                $wpdb->insert($table_property_types, $row, array('%s','%s','%d'));
            }
        }

        $classes_count = intval($wpdb->get_var("SELECT COUNT(*) FROM $table_general_classes"));
        if ($classes_count === 0) {
            $default_classes = array(
                array('code' => 'RESIDENTIAL', 'name' => 'RESIDENTIAL', 'sort_order' => 1),
                array('code' => 'AGRICULTURAL', 'name' => 'AGRICULTURAL', 'sort_order' => 2),
                array('code' => 'COMMERCIAL', 'name' => 'COMMERCIAL', 'sort_order' => 3),
                array('code' => 'INDUSTRIAL', 'name' => 'INDUSTRIAL', 'sort_order' => 4),
                array('code' => 'MINERAL', 'name' => 'MINERAL', 'sort_order' => 5),
                array('code' => 'SPECIAL', 'name' => 'SPECIAL', 'sort_order' => 6),
                array('code' => 'TIMBERLAND_FORESTAL', 'name' => 'TIMBERLAND/FORESTAL', 'sort_order' => 7),
                array('code' => 'IMPROVEMENTS', 'name' => 'IMPROVEMENTS', 'sort_order' => 8)
            );
            foreach ($default_classes as $row) {
                $wpdb->insert($table_general_classes, $row, array('%s','%s','%d'));
            }
        }

        $table_locations = $wpdb->prefix . 'assessor_locations';
        $locations_count = intval($wpdb->get_var("SELECT COUNT(*) FROM $table_locations"));
        if ($locations_count === 0) {
            $default_locations = array(
                array('code' => 'BARANGAY', 'name' => 'BARANGAY', 'sort_order' => 1)
            );
            foreach ($default_locations as $row) {
                $wpdb->insert($table_locations, $row, array('%s','%s','%d'));
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
        $column = $wpdb->get_var($wpdb->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'area_hectare_old'", $table_properties));
        if (!$column) {
            $wpdb->query("ALTER TABLE $table_properties ADD COLUMN area_hectare_old varchar(255) DEFAULT NULL AFTER area_hectare");
        }
        
        // Migration: Add area_hectare_old column to property_versions table
        $table_versions = $wpdb->prefix . 'assessor_property_versions';
        $column = $wpdb->get_var($wpdb->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'area_hectare_old'", $table_versions));
        if (!$column) {
            $wpdb->query("ALTER TABLE $table_versions ADD COLUMN area_hectare_old varchar(255) DEFAULT NULL AFTER area_hectare");
        }

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
    }
}

