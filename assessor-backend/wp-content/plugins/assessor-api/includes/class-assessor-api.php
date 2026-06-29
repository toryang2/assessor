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
        // Ensure database tables exist
        $this->ensure_database_tables();
        error_log('🔍 Assessor API Class: init method completed!');
    }

    private function ensure_database_tables() {
        global $wpdb;
        $table_revision_entries = $wpdb->prefix . 'assessor_revision_entries';
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_revision_entries));
        
        if (!$table_exists) {
            error_log('🔍 Assessor API: Revision entries table not found, creating...');
            $database = new Assessor_Database();
            $database->create_tables();
            error_log('🔍 Assessor API: Database tables created/updated');
        }
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
            // Allow common headers used by the frontend, including cache-busting headers
            $headers['Access-Control-Allow-Headers'] = 'Authorization, Content-Type, X-Requested-With, Cache-Control, Pragma, Expires, Accept, Origin, X-API-Key, X-API-Secret';
            $expose = 'X-Assessor-One-Time-Key, X-Assessor-One-Time-Credential, X-Assessor-Key-Id';
            if (!empty($headers['Access-Control-Expose-Headers'])) {
                $expose = $headers['Access-Control-Expose-Headers'] . ', ' . $expose;
            }
            $headers['Access-Control-Expose-Headers'] = $expose;

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

        $public_api = new Assessor_Public_API();
        register_rest_route('assessor/v1', '/public/properties', array(
            'methods' => 'GET',
            'callback' => array($this, 'public_search_properties'),
            'permission_callback' => array($public_api, 'check_api_key'),
        ));
        register_rest_route('assessor/v1', '/public/properties/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'public_get_property'),
            'permission_callback' => array($public_api, 'check_api_key'),
        ));
        register_rest_route('assessor/v1', '/public/properties/by-tax-number/(?P<tax_number>[^/]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'public_get_property_by_tax_number'),
            'permission_callback' => array($public_api, 'check_api_key'),
        ));
        register_rest_route('assessor/v1', '/settings/public-api-keys', array(
            'methods' => 'GET',
            'callback' => array($this, 'list_public_api_keys'),
            'permission_callback' => array($this, 'check_manager'),
        ));
        register_rest_route('assessor/v1', '/settings/public-api-keys', array(
            'methods' => 'POST',
            'callback' => array($this, 'generate_public_api_key'),
            'permission_callback' => array($this, 'check_manager'),
        ));
        register_rest_route('assessor/v1', '/settings/public-api-keys/(?P<id>\d+)', array(
            'methods' => 'PUT',
            'callback' => array($this, 'update_public_api_key'),
            'permission_callback' => array($this, 'check_manager'),
        ));
        register_rest_route('assessor/v1', '/settings/public-api-keys/(?P<id>\d+)', array(
            'methods' => 'DELETE',
            'callback' => array($this, 'revoke_public_api_key'),
            'permission_callback' => array($this, 'check_manager'),
        ));
        register_rest_route('assessor/v1', '/settings/public-api-keys/(?P<id>\d+)/reveal-secret', array(
            'methods' => 'POST',
            'callback' => array($this, 'reveal_public_api_secret'),
            'permission_callback' => array($this, 'check_manager'),
        ));
        register_rest_route('assessor/v1', '/settings/public-api-enabled', array(
            'methods' => 'POST',
            'callback' => array($this, 'set_public_api_enabled'),
            'permission_callback' => array($this, 'check_manager'),
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
        register_rest_route('assessor/v1', '/settings/header-photo', array(
            'methods' => 'POST',
            'callback' => array($this, 'upload_header_photo'),
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
        
        // Revision entries routes
        register_rest_route('assessor/v1', '/settings/revision-entries', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_revision_entries'),
            'permission_callback' => '__return_true'
        ));
        register_rest_route('assessor/v1', '/settings/revision-entries', array(
            'methods' => 'POST',
            'callback' => array($this, 'save_revision_entry'),
            'permission_callback' => array($this, 'check_auth')
        ));
        register_rest_route('assessor/v1', '/settings/revision-entries/delete', array(
            'methods' => 'POST',
            'callback' => array($this, 'delete_revision_entry'),
            'permission_callback' => array($this, 'check_auth')
        ));

        // Request purposes routes (Purpose + Amount Paid)
        register_rest_route('assessor/v1', '/settings/request-purposes', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_request_purposes'),
            'permission_callback' => '__return_true'
        ));
        register_rest_route('assessor/v1', '/settings/request-purposes', array(
            'methods' => 'POST',
            'callback' => array($this, 'save_request_purpose'),
            'permission_callback' => array($this, 'check_auth')
        ));
        register_rest_route('assessor/v1', '/settings/request-purposes/delete', array(
            'methods' => 'POST',
            'callback' => array($this, 'delete_request_purpose'),
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
        register_rest_route('assessor/v1', '/users/(?P<id>\d+)/avatar', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_upload_avatar'),
            'permission_callback' => array($this, 'check_auth')
        ));
        
        // Test route (no authentication required)
        register_rest_route('assessor/v1', '/test', array(
            'methods' => 'GET',
            'callback' => array($this, 'test_endpoint'),
            'permission_callback' => '__return_true'
        ));
        
        // Database setup route (no authentication required)
        register_rest_route('assessor/v1', '/setup-database', array(
            'methods' => 'GET',
            'callback' => array($this, 'setup_database'),
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

        // -------------------------------------------------------------------------
        // Sync routes (bidirectional local <-> live)
        // -------------------------------------------------------------------------
        $sync_receiver = new Assessor_Sync_Receiver();

        // Public health check — local uses this to test connectivity (no auth needed)
        register_rest_route('assessor/v1', '/sync/health', array(
            'methods'             => 'GET',
            'callback'            => array($sync_receiver, 'health'),
            'permission_callback' => '__return_true',
        ));

        // Live site: receive a batch push from a local build
        register_rest_route('assessor/v1', '/sync/push', array(
            'methods'             => 'POST',
            'callback'            => array($sync_receiver, 'receive_push'),
            'permission_callback' => array($sync_receiver, 'verify_sync_token'),
        ));

        // Live site: serve records changed since a timestamp (for local pull)
        register_rest_route('assessor/v1', '/sync/pull', array(
            'methods'             => 'GET',
            'callback'            => array($sync_receiver, 'serve_pull'),
            'permission_callback' => array($sync_receiver, 'verify_sync_token'),
        ));

        // Local admin: queue status dashboard
        register_rest_route('assessor/v1', '/sync/queue-status', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'sync_queue_status'),
            'permission_callback' => array($this, 'check_manager'),
        ));

        // Local admin: trigger immediate push + pull
        register_rest_route('assessor/v1', '/sync/push-now', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'sync_push_now'),
            'permission_callback' => array($this, 'check_auth'),
        ));

        // Local admin: reset failed items back to pending
        register_rest_route('assessor/v1', '/sync/clear-failed', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'sync_clear_failed'),
            'permission_callback' => array($this, 'check_manager'),
        ));

        // Live site: receive a full config table snapshot from a local build
        register_rest_route('assessor/v1', '/sync/push-config', array(
            'methods'             => 'POST',
            'callback'            => array($sync_receiver, 'receive_config_push'),
            'permission_callback' => array($sync_receiver, 'verify_sync_token'),
        ));

        // Sync token management (superadmin, admin, assessor)
        register_rest_route('assessor/v1', '/sync/config', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'sync_get_config'),
            'permission_callback' => array($this, 'check_manager'),
        ));

        register_rest_route('assessor/v1', '/sync/generate-token', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'sync_generate_token'),
            'permission_callback' => array($this, 'check_manager'),
        ));

        register_rest_route('assessor/v1', '/sync/save-token', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'sync_save_token'),
            'permission_callback' => array($this, 'check_manager'),
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
    public function check_superadmin($request) {
        $auth = new Assessor_Auth();
        return $auth->verify_superadmin($request);
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
        error_log('🔍 MAIN API: get_properties called, delegating to Properties class');
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

    // -------------------------------------------------------------------------
    // Sync handler methods (delegates to Assessor_Sync)
    // -------------------------------------------------------------------------

    /** GET /assessor/v1/sync/queue-status — returns pending/synced/failed counts */
    public function sync_queue_status($request) {
        return Assessor_Sync::get_queue_status();
    }

    /** POST /assessor/v1/sync/push-now — triggers an immediate push + pull */
    public function sync_push_now($request) {
        if (!defined('ASSESSOR_IS_LOCAL_BUILD') || !ASSESSOR_IS_LOCAL_BUILD) {
            return new WP_Error(
                'not_local_build',
                'Sync Now is only available on local builds. Define ASSESSOR_IS_LOCAL_BUILD=true in wp-config.php.',
                array('status' => 400)
            );
        }
        return Assessor_Sync::manual_sync();
    }

    /** POST /assessor/v1/sync/clear-failed — resets failed queue items to pending */
    public function sync_clear_failed($request) {
        if (!defined('ASSESSOR_IS_LOCAL_BUILD') || !ASSESSOR_IS_LOCAL_BUILD) {
            return new WP_Error(
                'not_local_build',
                'Sync queue management is only available on local builds.',
                array('status' => 400)
            );
        }
        Assessor_Sync::clear_failed();
        return array('success' => true, 'message' => 'Failed items reset to pending.');
    }
    
    /** GET /assessor/v1/sync/config */
    public function sync_get_config($request) {
        return Assessor_Sync::rest_get_sync_config($request);
    }

    /** POST /assessor/v1/sync/generate-token */
    public function sync_generate_token($request) {
        return Assessor_Sync::rest_generate_token($request);
    }

    /** POST /assessor/v1/sync/save-token */
    public function sync_save_token($request) {
        return Assessor_Sync::rest_save_token($request);
    }

    public function get_tax_declaration_history($request) {
        $properties = new Assessor_Properties();
        return $properties->get_tax_declaration_history($request['tax_number']);
    }

    public function get_property_by_tax_number($request) {
        $properties = new Assessor_Properties();
        return $properties->get_property_by_tax_number($request['tax_number']);
    }

    public function public_search_properties($request) {
        $public = new Assessor_Public_API();
        return $public->search_properties($request);
    }

    public function public_get_property($request) {
        $public = new Assessor_Public_API();
        return $public->get_property_by_id($request);
    }

    public function public_get_property_by_tax_number($request) {
        $public = new Assessor_Public_API();
        return $public->get_property_by_tax_number($request);
    }

    public function generate_public_api_key($request) {
        $public = new Assessor_Public_API();
        return $public->generate_api_key($request);
    }

    public function list_public_api_keys($request) {
        $public = new Assessor_Public_API();
        return $public->list_api_keys($request);
    }

    public function update_public_api_key($request) {
        $public = new Assessor_Public_API();
        return $public->update_api_key($request);
    }

    public function revoke_public_api_key($request) {
        $public = new Assessor_Public_API();
        return $public->revoke_api_key($request);
    }

    public function reveal_public_api_secret($request) {
        $public = new Assessor_Public_API();
        return $public->reveal_api_secret($request);
    }

    public function set_public_api_enabled($request) {
        $public = new Assessor_Public_API();
        return $public->set_enabled($request);
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
        
        // Compute properties created counts for current and previous month (based on created_at)
        $table_properties = $wpdb->prefix . 'assessor_properties';
        $table_requests = $wpdb->prefix . 'assessor_requests';
        $now_ts = current_time('timestamp');
        $curr_start = date('Y-m-01 00:00:00', $now_ts);
        $next_start = date('Y-m-01 00:00:00', strtotime('+1 month', $now_ts));
        $prev_start = date('Y-m-01 00:00:00', strtotime('-1 month', $now_ts));
        
        $sql_month = "SELECT COUNT(*) FROM $table_properties WHERE status != 'deleted' AND created_at >= %s AND created_at < %s";
        $created_this_month = (int)$wpdb->get_var($wpdb->prepare($sql_month, $curr_start, $next_start));
        $created_last_month = (int)$wpdb->get_var($wpdb->prepare($sql_month, $prev_start, $curr_start));

        // RPTs added this month - count of chain heads (most recent declarations) created this month
        // This counts the head of each chain that was created this month
        $rpts_this_month_query = "
            SELECT COUNT(*) as rpts_this_month
            FROM {$table_properties} p1
            WHERE p1.status != 'deleted'
              AND p1.created_at >= %s AND p1.created_at < %s
              AND NOT EXISTS (
                  SELECT 1 FROM {$table_properties} p2 
                  WHERE p2.previous_tax_declaration_number = p1.tax_declaration_number
                    AND p2.status != 'deleted'
              )
        ";
        $rpts_this_month = (int)$wpdb->get_var($wpdb->prepare($rpts_this_month_query, $curr_start, $next_start));

        // Requests totals and monthly counts (based on created_at)
        $total_requests = (int)$wpdb->get_var("SELECT COUNT(*) FROM $table_requests");
        $requests_this_month = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_requests WHERE created_at >= %s AND created_at < %s",
            $curr_start,
            $next_start
        ));
        $requests_last_month = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_requests WHERE created_at >= %s AND created_at < %s",
            $prev_start,
            $curr_start
        ));
        
        // Get header photo URL from settings
        $settings = new Assessor_Settings();
        $settings_data = $settings->get_settings();
        $header_photo_url = isset($settings_data['header_photo_url']) ? $settings_data['header_photo_url'] : '';
        
        return array(
            'total_properties' => $total_properties,
            'version_counts' => $version_counts,
            'total_users' => $total_users,
            'properties_created_this_month' => $created_this_month,
            'properties_created_last_month' => $created_last_month,
            // RPTs summary for dashboard direct consumption
            'rpts_this_month' => $rpts_this_month,
            // Requests summary for dashboard direct consumption
            'requests_count' => $total_requests,
            'requests_this_month' => $requests_this_month,
            'requests_last_month' => $requests_last_month,
            'header_photo_url' => $header_photo_url,
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

    public function upload_header_photo($request) {
        $settings = new Assessor_Settings();
        return $settings->upload_header_photo($request);
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

    public function get_revision_entries($request) {
        $settings = new Assessor_Settings();
        return $settings->get_revision_entries();
    }

    public function save_revision_entry($request) {
        $settings = new Assessor_Settings();
        return $settings->save_revision_entry($request);
    }

    public function delete_revision_entry($request) {
        $settings = new Assessor_Settings();
        return $settings->delete_revision_entry($request);
    }

    public function get_request_purposes($request) {
        $settings = new Assessor_Settings();
        return $settings->get_request_purposes();
    }

    public function save_request_purpose($request) {
        $settings = new Assessor_Settings();
        return $settings->save_request_purpose($request);
    }

    public function delete_request_purpose($request) {
        $settings = new Assessor_Settings();
        return $settings->delete_request_purpose($request);
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

    public function handle_upload_avatar($request) {
        $auth = new Assessor_Auth();
        return $auth->upload_avatar($request);
    }
    
    public function test_endpoint($request) {
        error_log('🔍 Assessor API: Test endpoint called!');
        return array('message' => 'Test endpoint working!', 'timestamp' => time());
    }

    public function setup_database($request) {
        try {
            $database = new Assessor_Database();
            $database->create_tables();
            
            // Check if revision entries table exists
            global $wpdb;
            $table_revision_entries = $wpdb->prefix . 'assessor_revision_entries';
            $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_revision_entries));
            
            return array(
                'success' => true,
                'message' => 'Database tables created/updated successfully',
                'revision_entries_table_exists' => !empty($table_exists),
                'table_name' => $table_revision_entries,
                'timestamp' => current_time('mysql')
            );
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => 'Error creating database tables: ' . $e->getMessage(),
                'timestamp' => current_time('mysql')
            );
        }
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
