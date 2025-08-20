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
            email varchar(100) NOT NULL,
            full_name varchar(200) NOT NULL,
            role varchar(50) NOT NULL DEFAULT 'assessor',
            status varchar(20) NOT NULL DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY username (username),
            UNIQUE KEY email (email)
        ) $charset_collate;";
        
        // Properties table
        $table_properties = $wpdb->prefix . 'assessor_properties';
        $sql_properties = "CREATE TABLE $table_properties (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            tax_declaration_number varchar(100) NOT NULL,
            previous_tax_declaration_number varchar(100),
            declarant_last_name varchar(100) NOT NULL,
            declarant_first_name varchar(100) NOT NULL,
            declarant_middle_initial varchar(10),
            business varchar(200),
            location text NOT NULL,
            lot_number varchar(100),
            unique_lot_number_identified varchar(100),
            area_hectare decimal(10,4),
            title_number varchar(100),
            assessed_value decimal(15,2),
            effectivity_date varchar(20),
            pin varchar(100),
            address text,
            assessment_date date,
            kind_of_property varchar(50) NOT NULL,
            gen_class varchar(50),
            memoranda text,
            supporting_documents text,
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
            declarant_last_name varchar(100) NOT NULL,
            declarant_first_name varchar(100) NOT NULL,
            declarant_middle_initial varchar(10),
            business varchar(200),
            location text NOT NULL,
            lot_number varchar(100),
            unique_lot_number_identified varchar(100),
            area_hectare decimal(10,4),
            title_number varchar(100),
            assessed_value decimal(15,2),
            effectivity_date varchar(20),
            pin varchar(100),
            address text,
            assessment_date date,
            kind_of_property varchar(50) NOT NULL,
            gen_class varchar(50),
            memoranda text,
            supporting_documents text,
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
            header_province varchar(255) DEFAULT '',
            header_municipality varchar(255) DEFAULT '',
            header_office varchar(255) DEFAULT '',
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
        
        // Add foreign key constraints separately
        $this->add_foreign_keys();
        
        // Insert default admin user if table is empty
        $this->insert_default_admin();

        // Seed initial settings data
        $this->seed_default_settings();
    }
    
    private function add_foreign_keys() {
        global $wpdb;
        
        $table_properties = $wpdb->prefix . 'assessor_properties';
        $table_versions = $wpdb->prefix . 'assessor_property_versions';
        $table_documents = $wpdb->prefix . 'assessor_documents';
        
        // Add foreign key for property_versions table
        $wpdb->query("ALTER TABLE $table_versions ADD CONSTRAINT fk_property_versions_property_id FOREIGN KEY (property_id) REFERENCES $table_properties(id) ON DELETE CASCADE");
        
        // Add foreign key for documents table
        $wpdb->query("ALTER TABLE $table_documents ADD CONSTRAINT fk_documents_property_id FOREIGN KEY (property_id) REFERENCES $table_properties(id) ON DELETE CASCADE");
    }
    
    private function insert_default_admin() {
        global $wpdb;
        
        $table_users = $wpdb->prefix . 'assessor_users';
        $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_users");
        
        if ($count == 0) {
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
}

