<?php

class Assessor_API {
    
    public function init() {
        error_log('🔍 Assessor API Class: init method called!');
        add_action('rest_api_init', array($this, 'register_routes'));
        error_log('🔍 Assessor API Class: rest_api_init action added!');
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('init', array($this, 'handle_cors'));
        error_log('🔍 Assessor API Class: init method completed!');
    }
    
    public function handle_cors() {
        if (isset($_SERVER['HTTP_ORIGIN'])) {
            header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Max-Age: 86400');
        }
        
        if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
            if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
                header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
            }
            
            if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
                header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
            }
            exit(0);
        }
    }
    
    public function register_routes() {
        error_log('🔍 Assessor API Class: register_routes method called!');
        // Authentication routes
        register_rest_route('assessor/v1', '/login', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_login'),
            'permission_callback' => '__return_true',
            'args' => array(
                'username' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'password' => array(
                    'required' => true,
                    'type' => 'string'
                )
            )
        ));
        
        register_rest_route('assessor/v1', '/logout', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_logout'),
            'permission_callback' => array($this, 'check_auth')
        ));

        register_rest_route('assessor/v1', '/validate-token', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_validate_token'),
            'permission_callback' => '__return_true'
        ));
        
        // Properties routes
        register_rest_route('assessor/v1', '/properties', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_properties'),
            'permission_callback' => array($this, 'check_auth')
        ));
        
        register_rest_route('assessor/v1', '/properties', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_property'),
            'permission_callback' => array($this, 'check_auth')
        ));
        
        register_rest_route('assessor/v1', '/properties/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_property'),
            'permission_callback' => array($this, 'check_auth')
        ));
        
        register_rest_route('assessor/v1', '/properties/(?P<id>\d+)', array(
            'methods' => 'PUT',
            'callback' => array($this, 'update_property'),
            'permission_callback' => array($this, 'check_auth')
        ));
        
        register_rest_route('assessor/v1', '/properties/(?P<id>\d+)', array(
            'methods' => 'DELETE',
            'callback' => array($this, 'delete_property'),
            'permission_callback' => array($this, 'check_auth')
        ));
        
        // Tax declaration history routes
        register_rest_route('assessor/v1', '/tax-declaration-history/(?P<tax_number>[^/]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_tax_declaration_history'),
            'permission_callback' => array($this, 'check_auth')
        ));
        
        register_rest_route('assessor/v1', '/properties/by-tax-number/(?P<tax_number>[^/]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_property_by_tax_number'),
            'permission_callback' => array($this, 'check_auth')
        ));
        
        // Version history routes
        register_rest_route('assessor/v1', '/properties/(?P<id>\d+)/versions', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_property_versions'),
            'permission_callback' => array($this, 'check_auth')
        ));
        
        // Document routes
        register_rest_route('assessor/v1', '/properties/(?P<id>\d+)/documents', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_property_documents'),
            'permission_callback' => array($this, 'check_auth')
        ));
        
        register_rest_route('assessor/v1', '/properties/(?P<id>\d+)/documents', array(
            'methods' => 'POST',
            'callback' => array($this, 'upload_document'),
            'permission_callback' => array($this, 'check_auth')
        ));
        
        // Dashboard routes
        register_rest_route('assessor/v1', '/dashboard', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_dashboard_data'),
            'permission_callback' => array($this, 'check_auth')
        ));
        
        // Export routes
        register_rest_route('assessor/v1', '/export', array(
            'methods' => 'POST',
            'callback' => array($this, 'export_data'),
            'permission_callback' => array($this, 'check_auth')
        ));
        
        // Audit trail routes
        register_rest_route('assessor/v1', '/audit', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_audit_trail'),
            'permission_callback' => array($this, 'check_auth')
        ));
        
        // User management routes
        register_rest_route('assessor/v1', '/users', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_users'),
            'permission_callback' => array($this, 'check_admin')
        ));
        
        // Test route (no authentication required)
        register_rest_route('assessor/v1', '/test', array(
            'methods' => 'GET',
            'callback' => array($this, 'test_endpoint'),
            'permission_callback' => '__return_true'
        ));
        
        // JWT config test endpoint
        register_rest_route('assessor/v1', '/jwt-config', array(
            'methods' => 'GET',
            'callback' => array($this, 'jwt_config_test'),
            'permission_callback' => '__return_true'
        ));
    }
    
    public function check_auth($request) {
        error_log('🔍 Assessor API: check_auth method called!');
        $auth = new Assessor_Auth();
        $result = $auth->verify_token($request);
        error_log('🔍 Assessor API: check_auth result: ' . ($result ? 'TRUE' : 'FALSE'));
        return $result;
    }
    
    public function check_admin($request) {
        $auth = new Assessor_Auth();
        return $auth->verify_admin($request);
    }
    
    // Route handlers
    public function handle_login($request) {
        $auth = new Assessor_Auth();
        return $auth->login($request);
    }
    
    public function handle_logout($request) {
        $auth = new Assessor_Auth();
        return $auth->logout($request);
    }

    public function handle_validate_token($request) {
        $auth = new Assessor_Auth();
        return $auth->validate_token($request);
    }
    
    public function get_properties($request) {
        $properties = new Assessor_Properties();
        return $properties->get_properties($request);
    }
    
    public function create_property($request) {
        $properties = new Assessor_Properties();
        return $properties->create_property($request);
    }
    
    public function get_property($request) {
        $properties = new Assessor_Properties();
        return $properties->get_property($request['id']);
    }
    
    public function update_property($request) {
        $properties = new Assessor_Properties();
        return $properties->update_property($request['id'], $request);
    }
    
    public function delete_property($request) {
        $properties = new Assessor_Properties();
        return $properties->delete_property($request['id']);
    }
    
    public function get_tax_declaration_history($request) {
        $properties = new Assessor_Properties();
        return $properties->get_tax_declaration_history($request['tax_number']);
    }

    public function get_property_by_tax_number($request) {
        $properties = new Assessor_Properties();
        return $properties->get_property_by_tax_number($request['tax_number']);
    }
    
    public function get_property_versions($request) {
        $versions = new Assessor_Versions();
        return $versions->get_property_versions($request['id']);
    }
    
    public function get_property_documents($request) {
        $documents = new Assessor_Documents();
        return $documents->get_property_documents($request['id']);
    }
    
    public function upload_document($request) {
        $documents = new Assessor_Documents();
        return $documents->upload_document($request);
    }
    
    public function get_dashboard_data($request) {
        // Get real dashboard data
        $properties = new Assessor_Properties();
        $auth = new Assessor_Auth();
        
        $total_properties = $properties->get_total_count();
        $version_counts = $properties->get_version_counts();
        $users = $auth->get_users();
        
        return array(
            'total_properties' => $total_properties,
            'total_versions' => $version_counts,
            'total_users' => count($users['users']),
            'recent_activity' => array() // Will be populated by audit trail
        );
    }
    
    public function export_data($request) {
        $export = new Assessor_Export();
        return $export->export_data($request);
    }
    
    public function get_audit_trail($request) {
        $audit = new Assessor_Audit();
        return $audit->get_audit_trail($request);
    }
    
    public function get_users($request) {
        $auth = new Assessor_Auth();
        return $auth->get_users();
    }
    
    public function test_endpoint($request) {
        error_log('🔍 Assessor API: Test endpoint called!');
        return array('message' => 'Test endpoint working!', 'timestamp' => time());
    }
    
    public function jwt_config_test($request) {
        error_log('🔍 Assessor API: JWT config test endpoint called!');
        $auth = new Assessor_Auth();
        
        // Get the secret key (this will trigger the constructor)
        $reflection = new ReflectionClass($auth);
        $secret_property = $reflection->getProperty('secret_key');
        $secret_property->setAccessible(true);
        $secret_key = $secret_property->getValue($auth);
        
        return array(
            'message' => 'JWT Configuration Test',
            'jwt_secret_defined' => defined('JWT_AUTH_SECRET_KEY'),
            'jwt_secret_length' => strlen($secret_key),
            'jwt_secret_preview' => substr($secret_key, 0, 10) . '...',
            'timestamp' => current_time('mysql'),
            'version' => ASSESSOR_API_VERSION
        );
    }
    
    public function enqueue_scripts() {
        // Enqueue any necessary scripts
    }
}
