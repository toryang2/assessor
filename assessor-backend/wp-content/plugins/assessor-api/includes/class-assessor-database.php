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
            owner_name varchar(200) NOT NULL,
            owner_address text,
            property_location text NOT NULL,
            property_type varchar(100) NOT NULL,
            land_area decimal(10,2),
            building_area decimal(10,2),
            assessed_value decimal(15,2),
            market_value decimal(15,2),
            status varchar(50) NOT NULL DEFAULT 'active',
            created_by mediumint(9) NOT NULL,
            updated_by mediumint(9) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY tax_declaration_number (tax_declaration_number),
            KEY owner_name (owner_name),
            KEY property_location (property_location(100)),
            KEY status (status)
        ) $charset_collate;";
        
        // Property versions table
        $table_versions = $wpdb->prefix . 'assessor_property_versions';
        $sql_versions = "CREATE TABLE $table_versions (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            property_id mediumint(9) NOT NULL,
            version_number int NOT NULL,
            tax_declaration_number varchar(100) NOT NULL,
            owner_name varchar(200) NOT NULL,
            owner_address text,
            property_location text NOT NULL,
            property_type varchar(100) NOT NULL,
            land_area decimal(10,2),
            building_area decimal(10,2),
            assessed_value decimal(15,2),
            market_value decimal(15,2),
            change_reason text,
            created_by mediumint(9) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY property_id (property_id),
            KEY version_number (version_number)
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
        
        // Execute SQL statements
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        dbDelta($sql_users);
        dbDelta($sql_properties);
        dbDelta($sql_versions);
        dbDelta($sql_documents);
        dbDelta($sql_audit);
        
        // Add foreign key constraints separately
        $this->add_foreign_keys();
        
        // Insert default admin user if table is empty
        $this->insert_default_admin();
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
}

