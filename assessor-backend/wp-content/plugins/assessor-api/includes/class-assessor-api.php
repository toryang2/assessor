<?php

class Assessor_API {
    
    public function init() {
        error_log('🔍 Assessor API Class: init method called!');
        add_action('rest_api_init', array($this, 'register_routes'));
        error_log('🔍 Assessor API Class: rest_api_init action added!');
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('init', array($this, 'handle_cors'));
        // Add minimal CORS for our REST namespace only
        add_action('rest_api_init', array($this, 'attach_assessor_cors_headers'));
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

    public function attach_assessor_cors_headers() {
        add_filter('rest_send_cors_headers', array($this, 'assessor_cors_headers'), 100, 2);
    }

    public function assessor_cors_headers($headers, $request) {
        // Only apply to our namespace
        $route = method_exists($request, 'get_route') ? $request->get_route() : '';
        if (is_string($route) && strpos($route, '/assessor/v1/') === 0) {
            $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
            if (!empty($origin)) {
                $headers['Access-Control-Allow-Origin'] = $origin;
                $headers['Vary'] = 'Origin';
            }
            $headers['Access-Control-Allow-Credentials'] = 'true';
            $headers['Access-Control-Allow-Methods'] = 'GET, POST, PUT, DELETE, OPTIONS';
            $headers['Access-Control-Allow-Headers'] = 'Authorization, Content-Type, X-Requested-With';
            
            // Add cache control headers to prevent caching
            $headers['Cache-Control'] = 'no-cache, no-store, must-revalidate, max-age=0';
            $headers['Pragma'] = 'no-cache';
            $headers['Expires'] = '0';
        }
        return $headers;
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

        register_rest_route('assessor/v1', '/properties/(?P<id>\d+)/documents/(?P<doc_id>\d+)', array(
            'methods' => 'DELETE',
            'callback' => array($this, 'delete_document'),
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

        // Settings routes
        error_log('🔍 Assessor API: Registering settings routes');
        register_rest_route('assessor/v1', '/settings', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_settings'),
            'permission_callback' => '__return_true'
        ));
        register_rest_route('assessor/v1', '/settings', array(
            'methods' => 'POST',
            'callback' => array($this, 'save_settings'),
            'permission_callback' => array($this, 'check_auth')
        ));
        register_rest_route('assessor/v1', '/settings/logo', array(
            'methods' => 'POST',
            'callback' => array($this, 'upload_logo'),
            'permission_callback' => array($this, 'check_auth')
        ));
        // Property types
        register_rest_route('assessor/v1', '/settings/property-types', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_property_types'),
            'permission_callback' => '__return_true'
        ));
        register_rest_route('assessor/v1', '/settings/property-types', array(
            'methods' => 'POST',
            'callback' => array($this, 'save_property_type'),
            'permission_callback' => array($this, 'check_auth')
        ));
        register_rest_route('assessor/v1', '/settings/property-types/delete', array(
            'methods' => 'POST',
            'callback' => array($this, 'delete_property_type'),
            'permission_callback' => array($this, 'check_auth')
        ));
        // General classes
        register_rest_route('assessor/v1', '/settings/general-classes', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_general_classes'),
            'permission_callback' => '__return_true'
        ));
        register_rest_route('assessor/v1', '/settings/general-classes', array(
            'methods' => 'POST',
            'callback' => array($this, 'save_general_class'),
            'permission_callback' => array($this, 'check_auth')
        ));
        register_rest_route('assessor/v1', '/settings/general-classes/delete', array(
            'methods' => 'POST',
            'callback' => array($this, 'delete_general_class'),
            'permission_callback' => array($this, 'check_auth')
        ));
        // Locations
        register_rest_route('assessor/v1', '/settings/locations', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_locations'),
            'permission_callback' => '__return_true'
        ));
        register_rest_route('assessor/v1', '/settings/locations', array(
            'methods' => 'POST',
            'callback' => array($this, 'save_location'),
            'permission_callback' => array($this, 'check_auth')
        ));
        register_rest_route('assessor/v1', '/settings/locations/delete', array(
            'methods' => 'POST',
            'callback' => array($this, 'delete_location'),
            'permission_callback' => array($this, 'check_auth')
        ));
        error_log('🔍 Assessor API: Settings routes registered');
        
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
            'permission_callback' => array($this, 'check_manager')
        ));
        // Create user (public as requested)
        register_rest_route('assessor/v1', '/users', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_user'),
            'permission_callback' => '__return_true'
        ));
        // Update user (public as requested)
        register_rest_route('assessor/v1', '/users/(?P<id>\d+)', array(
            'methods' => 'PUT',
            'callback' => array($this, 'update_user'),
            'permission_callback' => '__return_true'
        ));
        // Delete user (public as requested)
        register_rest_route('assessor/v1', '/users/(?P<id>\d+)', array(
            'methods' => 'DELETE',
            'callback' => array($this, 'delete_user'),
            'permission_callback' => '__return_true'
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
        
        // Requests routes
        register_rest_route('assessor/v1', '/requests', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_requests'),
            'permission_callback' => array($this, 'check_auth')
        ));
        
        register_rest_route('assessor/v1', '/requests', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_request'),
            'permission_callback' => array($this, 'check_auth')
        ));
        
        register_rest_route('assessor/v1', '/requests/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_request'),
            'permission_callback' => array($this, 'check_auth')
        ));
        
        register_rest_route('assessor/v1', '/requests/(?P<id>\d+)', array(
            'methods' => 'PUT',
            'callback' => array($this, 'update_request'),
            'permission_callback' => array($this, 'check_auth')
        ));
        
        register_rest_route('assessor/v1', '/requests/(?P<id>\d+)', array(
            'methods' => 'DELETE',
            'callback' => array($this, 'delete_request'),
            'permission_callback' => array($this, 'check_auth')
        ));
        
        register_rest_route('assessor/v1', '/requests/statistics', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_request_statistics'),
            'permission_callback' => array($this, 'check_auth')
        ));
    }
    
    public function check_auth($request) {
        $auth = new Assessor_Auth();
        return $auth->verify_token($request);
    }
    
    public function check_admin($request) {
        $auth = new Assessor_Auth();
        return $auth->verify_admin($request);
    }
    public function check_manager($request) {
        $auth = new Assessor_Auth();
        return $auth->verify_manager($request);
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
        return $properties->delete_property($request['id'], $request);
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

    public function delete_document($request) {
        $documents = new Assessor_Documents();
        $doc_id = isset($request['doc_id']) ? intval($request['doc_id']) : 0;
        // Pass the full request so the service can extract the user token for auditing
        return $documents->delete_document($doc_id, $request);
    }
    
    public function get_dashboard_data($request) {
        // Get real dashboard data
        $properties = new Assessor_Properties();
        
        $total_properties = $properties->get_total_count();
        $version_counts = $properties->get_version_counts();
        
        // Avoid calling get_users() without a request; query DB directly for count
        global $wpdb;
        $table_users = $wpdb->prefix . 'assessor_users';
        $total_users = (int)$wpdb->get_var("SELECT COUNT(*) FROM $table_users");
        
        return array(
            'total_properties' => $total_properties,
            'total_versions' => $version_counts,
            'total_users' => $total_users,
            'recent_activity' => array() // Will be populated by audit trail
        );
    }
    
    public function export_data($request) {
        $export = new Assessor_Export();
        return $export->export_data($request);
    }

    public function get_settings($request) {
        $settings = new Assessor_Settings();
        return $settings->get_settings();
    }

    public function save_settings($request) {
        $settings = new Assessor_Settings();
        return $settings->save_settings($request);
    }

    public function upload_logo($request) {
        $settings = new Assessor_Settings();
        return $settings->upload_logo($request);
    }

    public function get_property_types($request) {
        $settings = new Assessor_Settings();
        return $settings->get_property_types();
    }

    public function save_property_type($request) {
        $settings = new Assessor_Settings();
        return $settings->save_property_type($request);
    }

    public function delete_property_type($request) {
        $settings = new Assessor_Settings();
        return $settings->delete_property_type($request);
    }

    public function get_general_classes($request) {
        $settings = new Assessor_Settings();
        return $settings->get_general_classes();
    }

    public function save_general_class($request) {
        $settings = new Assessor_Settings();
        return $settings->save_general_class($request);
    }

    public function delete_general_class($request) {
        $settings = new Assessor_Settings();
        return $settings->delete_general_class($request);
    }

    public function get_locations($request) {
        $settings = new Assessor_Settings();
        return $settings->get_locations();
    }

    public function save_location($request) {
        $settings = new Assessor_Settings();
        return $settings->save_location($request);
    }

    public function delete_location($request) {
        $settings = new Assessor_Settings();
        return $settings->delete_location($request);
    }
    
    public function get_audit_trail($request) {
        $audit = new Assessor_Audit();
        return $audit->get_audit_trail($request);
    }
    
    public function get_users($request) {
        $auth = new Assessor_Auth();
        return $auth->get_users($request);
    }
    public function create_user($request) {
        $auth = new Assessor_Auth();
        return $auth->create_user($request);
    }
    public function update_user($request) {
        $auth = new Assessor_Auth();
        return $auth->update_user($request['id'], $request);
    }
    public function delete_user($request) {
        $auth = new Assessor_Auth();
        // pass request to allow role-based delete protection
        return $auth->delete_user($request['id'], $request);
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
    
    // Request handlers
    public function get_requests($request) {
        $requests = new Assessor_Requests();
        return $requests->get_requests($request->get_params());
    }
    
    public function create_request($request) {
        $requests = new Assessor_Requests();
        return $requests->create_request($request->get_params(), $request);
    }
    
    public function get_request($request) {
        $requests = new Assessor_Requests();
        return $requests->get_request($request['id']);
    }
    
    public function update_request($request) {
        $requests = new Assessor_Requests();
        return $requests->update_request($request['id'], $request->get_params(), $request);
    }
    
    public function delete_request($request) {
        $requests = new Assessor_Requests();
        return $requests->delete_request($request['id']);
    }
    
    public function get_request_statistics($request) {
        $requests = new Assessor_Requests();
        return $requests->get_statistics($request->get_params());
    }
    
    public function enqueue_scripts() {
        // Enqueue any necessary scripts
    }
}
