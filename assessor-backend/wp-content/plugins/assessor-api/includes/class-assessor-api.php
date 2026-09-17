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
        
        // Check hardware lock
        add_filter('rest_pre_dispatch', array($this, 'check_hardware_lock'), 10, 3);
        
        error_log('🔍 Assessor API Class: init method completed!');
    }

    public function check_hardware_lock($result, $server, $request) {
        $route = $request->get_route();
        
        // Only protect our namespace
        if (strpos($route, '/assessor/v1/') !== 0) {
            return $result;
        }
        
        // Exempt the hardware lock endpoints
        if (strpos($route, '/hardware-lock/') !== false) {
            return $result;
        }
        
        // Only enforce hardware lock on local builds
        if (!defined('ASSESSOR_IS_LOCAL_BUILD') || !ASSESSOR_IS_LOCAL_BUILD) {
            return $result;
        }
        
        if (!Assessor_Hardware_Lock::is_unlocked()) {
            return new WP_Error('hardware_locked', 'Hardware locked. Activation required.', array('status' => 403));
        }
        
        return $result;
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

        // Hardware lock routes
        $hw_lock = new Assessor_Hardware_Lock();
        register_rest_route('assessor/v1', '/hardware-lock/status', array(
            'methods' => 'GET',
            'callback' => array($hw_lock, 'get_status'),
            'permission_callback' => '__return_true'
        ));
        register_rest_route('assessor/v1', '/hardware-lock/activate', array(
            'methods' => 'POST',
            'callback' => array($hw_lock, 'activate'),
            'permission_callback' => '__return_true'
        ));
        
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
        
        register_rest_route('assessor/v1', '/properties/(?P<id>[a-zA-Z0-9\-\_]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_property'),
            'permission_callback' => array($this, 'check_auth')
        ));
        
        register_rest_route('assessor/v1', '/properties/(?P<id>[a-zA-Z0-9\-\_]+)', array(
            'methods' => 'PUT',
            'callback' => array($this, 'update_property'),
            'permission_callback' => array($this, 'check_auth')
        ));
        
        register_rest_route('assessor/v1', '/properties/(?P<id>[a-zA-Z0-9\-\_]+)', array(
            'methods' => 'DELETE',
            'callback' => array($this, 'delete_property'),
            'permission_callback' => array($this, 'check_auth'),
        ));
        
        // Property State
        register_rest_route('assessor/v1', '/properties/(?P<id>[a-zA-Z0-9\-\_]+)/state', array(
            'methods' => 'PUT',
            'callback' => array($this, 'update_property_state'),
            'permission_callback' => array($this, 'check_auth'),
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
        register_rest_route('assessor/v1', '/public/properties/(?P<id>[a-zA-Z0-9\-\_]+)', array(
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
        register_rest_route('assessor/v1', '/properties/(?P<id>[a-zA-Z0-9\-\_]+)/versions', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_property_versions'),
            'permission_callback' => array($this, 'check_auth')
        ));
        
        // Document routes
        register_rest_route('assessor/v1', '/properties/(?P<id>[a-zA-Z0-9\-\_]+)/documents', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_property_documents'),
            'permission_callback' => array($this, 'check_auth')
        ));
        
        register_rest_route('assessor/v1', '/properties/(?P<id>[a-zA-Z0-9\-\_]+)/documents', array(
            'methods' => 'POST',
            'callback' => array($this, 'upload_document'),
            'permission_callback' => array($this, 'check_auth')
        ));

        register_rest_route('assessor/v1', '/properties/(?P<id>[a-zA-Z0-9\-\_]+)/documents/(?P<doc_id>\d+)', array(
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

        // Memoranda Templates routes
        register_rest_route('assessor/v1', '/settings/memoranda-templates', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_memoranda_templates'),
            'permission_callback' => '__return_true'
        ));
        register_rest_route('assessor/v1', '/settings/memoranda-templates', array(
            'methods' => 'POST',
            'callback' => array($this, 'save_memoranda_template'),
            'permission_callback' => array($this, 'check_auth')
        ));
        register_rest_route('assessor/v1', '/settings/memoranda-templates/delete', array(
            'methods' => 'POST',
            'callback' => array($this, 'delete_memoranda_template'),
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
        register_rest_route('assessor/v1', '/users/(?P<id>[\w-]+)', array(
            'methods' => 'PUT',
            'callback' => array($this, 'update_user'),
            'permission_callback' => '__return_true'
        ));
        // Delete user (public as requested)
        register_rest_route('assessor/v1', '/users/(?P<id>[\w-]+)', array(
            'methods' => 'DELETE',
            'callback' => array($this, 'delete_user'),
            'permission_callback' => '__return_true'
        ));
        register_rest_route('assessor/v1', '/users/(?P<id>[\w-]+)/avatar', array(
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
        
        register_rest_route('assessor/v1', '/requests/(?P<id>[a-zA-Z0-9\-\_]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_request'),
            'permission_callback' => array($this, 'check_auth')
        ));
        
        register_rest_route('assessor/v1', '/requests/(?P<id>[a-zA-Z0-9\-\_]+)', array(
            'methods' => 'PUT',
            'callback' => array($this, 'update_request'),
            'permission_callback' => array($this, 'check_auth')
        ));
        
        register_rest_route('assessor/v1', '/requests/(?P<id>[a-zA-Z0-9\-\_]+)', array(
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

        // Local admin: bulk download missing image files from live
        register_rest_route('assessor/v1', '/sync/download-files', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'sync_download_files'),
            'permission_callback' => array($this, 'check_auth'),
        ));

        // Local admin: check how many files are still missing
        register_rest_route('assessor/v1', '/sync/download-status', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'sync_download_status'),
            'permission_callback' => array($this, 'check_auth'),
        ));

        // Local admin: get a list of all missing files
        register_rest_route('assessor/v1', '/sync/missing-files-list', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'sync_missing_files_list'),
            'permission_callback' => array($this, 'check_auth'),
        ));

        // Local admin: download a specific batch of files
        register_rest_route('assessor/v1', '/sync/download-batch', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'sync_download_batch'),
            'permission_callback' => array($this, 'check_auth'),
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

        // ──────────────────────────────────────────────
        // ETRACS MODULE ROUTES (admin+ only)
        // ──────────────────────────────────────────────

        // FAAS CRUD
        register_rest_route('assessor/v1', '/etracs/faas', array(
            'methods'  => 'GET',
            'callback' => array($this, 'etracs_get_faas_list'),
            'permission_callback' => array($this, 'check_admin'),
        ));
        register_rest_route('assessor/v1', '/etracs/faas', array(
            'methods'  => 'POST',
            'callback' => array($this, 'etracs_create_faas'),
            'permission_callback' => array($this, 'check_admin'),
        ));
        register_rest_route('assessor/v1', '/etracs/faas/(?P<id>[a-zA-Z0-9\-\:]+)', array(
            'methods'  => 'GET',
            'callback' => array($this, 'etracs_get_faas'),
            'permission_callback' => array($this, 'check_admin'),
        ));
        register_rest_route('assessor/v1', '/etracs/faas/(?P<id>[a-zA-Z0-9\-\:]+)', array(
            'methods'  => 'PUT',
            'callback' => array($this, 'etracs_update_faas'),
            'permission_callback' => array($this, 'check_admin'),
        ));
        register_rest_route('assessor/v1', '/etracs/faas/(?P<id>[a-zA-Z0-9\-\:]+)', array(
            'methods'  => 'DELETE',
            'callback' => array($this, 'etracs_delete_faas'),
            'permission_callback' => array($this, 'check_admin'),
        ));
        register_rest_route('assessor/v1', '/etracs/faas/(?P<id>[a-zA-Z0-9\-\:]+)/cancel', array(
            'methods'  => 'POST',
            'callback' => array($this, 'etracs_cancel_faas'),
            'permission_callback' => array($this, 'check_admin'),
        ));
        
        // ETRACS Sync Pull
        register_rest_route('assessor/v1', '/etracs/pull', array(
            'methods'  => 'POST',
            'callback' => array($this, 'etracs_pull_sync'),
            'permission_callback' => array($this, 'check_manager'),
        ));
        
        // ETRACS Sync Pull Status
        register_rest_route('assessor/v1', '/etracs/pull/status', array(
            'methods'  => 'GET',
            'callback' => array($this, 'etracs_pull_sync_status'),
            'permission_callback' => array($this, 'check_manager'),
        ));

        // ETRACS stats
        register_rest_route('assessor/v1', '/etracs/stats', array(
            'methods'  => 'GET',
            'callback' => array($this, 'etracs_get_stats'),
            'permission_callback' => array($this, 'check_admin'),
        ));

        // Transaction types
        register_rest_route('assessor/v1', '/etracs/transaction-types', array(
            'methods'  => 'GET',
            'callback' => array($this, 'etracs_get_transaction_types'),
            'permission_callback' => array($this, 'check_admin'),
        ));

        // Entities
        register_rest_route('assessor/v1', '/etracs/entities', array(
            'methods'  => 'GET',
            'callback' => array($this, 'etracs_get_entities'),
            'permission_callback' => array($this, 'check_admin'),
        ));
        register_rest_route('assessor/v1', '/etracs/entities', array(
            'methods'  => 'POST',
            'callback' => array($this, 'etracs_create_entity'),
            'permission_callback' => array($this, 'check_admin'),
        ));
        register_rest_route('assessor/v1', '/etracs/entities/(?P<id>[a-zA-Z0-9\-\:]+)', array(
            'methods'  => 'PUT',
            'callback' => array($this, 'etracs_update_entity'),
            'permission_callback' => array($this, 'check_admin'),
        ));
        register_rest_route('assessor/v1', '/etracs/entities/(?P<id>[a-zA-Z0-9\-\:]+)', array(
            'methods'  => 'DELETE',
            'callback' => array($this, 'etracs_delete_entity'),
            'permission_callback' => array($this, 'check_admin'),
        ));

        // Lookups
        register_rest_route('assessor/v1', '/etracs/barangay', array(
            'methods'  => 'GET',
            'callback' => array($this, 'etracs_get_barangays'),
            'permission_callback' => array($this, 'check_admin'),
        ));
        register_rest_route('assessor/v1', '/etracs/exemption-types', array(
            'methods'  => 'GET',
            'callback' => array($this, 'etracs_get_exemption_types'),
            'permission_callback' => array($this, 'check_admin'),
        ));
        register_rest_route('assessor/v1', '/etracs/classifications', array(
            'methods'  => 'GET',
            'callback' => array($this, 'etracs_get_classifications'),
            'permission_callback' => array($this, 'check_admin'),
        ));
        register_rest_route('assessor/v1', '/etracs/faas/(?P<id>[a-zA-Z0-9\-\:]+)/signatory', array(
            'methods'  => 'GET',
            'callback' => array($this, 'etracs_get_faas_signatory'),
            'permission_callback' => array($this, 'check_admin'),
        ));
        register_rest_route('assessor/v1', '/etracs/building/lookups', array(
            'methods'  => 'GET',
            'callback' => array($this, 'etracs_get_building_lookups'),
            'permission_callback' => array($this, 'check_admin'),
        ));
        register_rest_route('assessor/v1', '/etracs/building/revision-settings', array(
            'methods'  => 'GET',
            'callback' => array($this, 'etracs_get_building_revision_settings'),
            'permission_callback' => array($this, 'check_admin'),
        ));

        // RPU
        register_rest_route('assessor/v1', '/etracs/rpu/(?P<id>[a-zA-Z0-9\-\:]+)/detail', array(
            'methods'  => 'GET',
            'callback' => array($this, 'etracs_get_rpu_detail'),
            'permission_callback' => array($this, 'check_admin'),
        ));

        // ──────────────────────────────────────────────
        // MIGRATION RUNNER ROUTES (manager/admin only)
        // ──────────────────────────────────────────────
        $migration_runner = new Assessor_Migration_Runner();

        // 1. Read-only preflight
        register_rest_route('assessor/v1', '/migrations/preflight', array(
            'methods'             => 'GET',
            'callback'            => array($migration_runner, 'get_preflight'),
            'permission_callback' => array($this, 'check_manager'),
        ));

        // 2. Status inspection
        register_rest_route('assessor/v1', '/migrations/status', array(
            'methods'             => 'GET',
            'callback'            => array($migration_runner, 'get_status'),
            'permission_callback' => array($this, 'check_manager'),
        ));

        // 3. Explicit single-migration execution
        register_rest_route('assessor/v1', '/migrations/execute', array(
            'methods'             => 'POST',
            'callback'            => array($migration_runner, 'execute_migration'),
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

    public function update_property_state($request) {
        $properties = new Assessor_Properties();
        $id = $request->get_param('id');
        $state = $request->get_param('state');
        if (empty($state)) {
            $state = 'CURRENT';
        }
        return $properties->update_property_state($id, $state, $request);
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
            return array(
                'success' => true,
                'message' => 'Live server is receiving updates.',
                'is_live' => true
            );
        }
        $params     = $request->get_json_params();
        $force_full = !empty($params['force_full']);
        return Assessor_Sync::manual_sync($force_full);
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

    /** POST /assessor/v1/sync/download-files — bulk download missing images from live */
    public function sync_download_files($request) {
        if (!defined('ASSESSOR_IS_LOCAL_BUILD') || !ASSESSOR_IS_LOCAL_BUILD) {
            return new WP_Error(
                'not_local_build',
                'File download is only available on local builds.',
                array('status' => 400)
            );
        }
        return Assessor_Sync::bulk_download_files();
    }

    /** GET /assessor/v1/sync/missing-files-list — get a list of all missing files */
    public function sync_missing_files_list($request) {
        if (!defined('ASSESSOR_IS_LOCAL_BUILD') || !ASSESSOR_IS_LOCAL_BUILD) {
            return new WP_Error(
                'not_local_build',
                'File download is only available on local builds.',
                array('status' => 400)
            );
        }
        return Assessor_Sync::get_missing_files_list();
    }

    /** POST /assessor/v1/sync/download-batch — process a specific batch of files */
    public function sync_download_batch($request) {
        if (!defined('ASSESSOR_IS_LOCAL_BUILD') || !ASSESSOR_IS_LOCAL_BUILD) {
            return new WP_Error(
                'not_local_build',
                'File download is only available on local builds.',
                array('status' => 400)
            );
        }
        $params = $request->get_json_params();
        if (empty($params['files']) || !is_array($params['files'])) {
            return new WP_Error('invalid_params', 'Missing or invalid files array.', array('status' => 400));
        }
        return Assessor_Sync::download_specific_batch($params['files']);
    }

    /** GET /assessor/v1/sync/download-status — check how many files are missing */
    public function sync_download_status($request) {
        if (!defined('ASSESSOR_IS_LOCAL_BUILD') || !ASSESSOR_IS_LOCAL_BUILD) {
            return new WP_Error(
                'not_local_build',
                'File download status is only available on local builds.',
                array('status' => 400)
            );
        }
        return array(
            'missing_files' => Assessor_Sync::count_missing_files(),
        );
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
        $revision_id = $request->get_param('revision_id') ?: $request->get_param('revision');
        $all_matches = filter_var($request->get_param('all'), FILTER_VALIDATE_BOOLEAN) || filter_var($request->get_param('all_matches'), FILTER_VALIDATE_BOOLEAN);
        $result = $properties->get_property_by_tax_number($request['tax_number'], false, $revision_id, $all_matches);
        if ($result === null) {
            return new WP_Error('property_not_found', 'Property not found', array('status' => 404));
        }
        return $result;
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

        // Requests totals and monthly counts (excluding soft-deleted)
        $total_requests = (int)$wpdb->get_var("SELECT COUNT(*) FROM $table_requests WHERE deleted_at IS NULL");
        $requests_this_month = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_requests WHERE deleted_at IS NULL AND created_at >= %s AND created_at < %s",
            $curr_start,
            $next_start
        ));
        $requests_last_month = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_requests WHERE deleted_at IS NULL AND created_at >= %s AND created_at < %s",
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

    public function get_memoranda_templates($request) {
        $settings = new Assessor_Settings();
        return $settings->get_memoranda_templates();
    }

    public function save_memoranda_template($request) {
        $settings = new Assessor_Settings();
        return $settings->save_memoranda_template($request);
    }

    public function delete_memoranda_template($request) {
        $settings = new Assessor_Settings();
        return $settings->delete_memoranda_template($request);
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

    // ──────────────────────────────────────────────
    // ETRACS MODULE HANDLERS
    // ──────────────────────────────────────────────

    public function etracs_get_faas_list($request) {
        $etracs = new Assessor_Etracs();
        return $etracs->get_faas_list($request);
    }
    public function etracs_create_faas($request) {
        $etracs = new Assessor_Etracs();
        return $etracs->create_faas($request);
    }
    public function etracs_get_faas($request) {
        $etracs = new Assessor_Etracs();
        $result = $etracs->get_faas($request['id']);
        if (is_wp_error($result)) return $result;
        return rest_ensure_response($result);
    }
    public function etracs_update_faas($request) {
        $etracs = new Assessor_Etracs();
        return $etracs->update_faas($request['id'], $request);
    }
    public function etracs_delete_faas($request) {
        $etracs = new Assessor_Etracs();
        return $etracs->delete_faas($request['id'], $request);
    }
    public function etracs_cancel_faas($request) {
        $etracs = new Assessor_Etracs();
        return $etracs->cancel_faas($request['id'], $request);
    }
    public function etracs_get_stats($request) {
        $etracs = new Assessor_Etracs();
        return $etracs->get_faas_stats($request);
    }
    public function etracs_get_transaction_types($request) {
        $etracs = new Assessor_Etracs();
        return $etracs->get_transaction_types($request);
    }
    public function etracs_get_entities($request) {
        $etracs = new Assessor_Etracs();
        return $etracs->get_entities($request);
    }
    public function etracs_create_entity($request) {
        $etracs = new Assessor_Etracs();
        return $etracs->create_entity($request);
    }
    public function etracs_update_entity($request) {
        $etracs = new Assessor_Etracs();
        return $etracs->update_entity($request['id'], $request);
    }
    public function etracs_delete_entity($request) {
        $etracs = new Assessor_Etracs();
        return $etracs->delete_entity($request['id'], $request);
    }

    public function etracs_get_barangays($request) {
        $etracs = new Assessor_Etracs();
        return rest_ensure_response($etracs->get_barangays());
    }

    public function etracs_get_exemption_types($request) {
        $etracs = new Assessor_Etracs();
        return rest_ensure_response($etracs->get_exemption_types());
    }

    public function etracs_get_classifications($request) {
        $etracs = new Assessor_Etracs();
        return rest_ensure_response($etracs->get_classifications());
    }

    public function etracs_get_faas_signatory($request) {
        $etracs = new Assessor_Etracs();
        $result = $etracs->get_faas_signatory($request['id']);
        if (is_wp_error($result)) return $result;
        return rest_ensure_response($result);
    }

    public function etracs_get_building_lookups($request) {
        $etracs = new Assessor_Etracs();
        return rest_ensure_response($etracs->get_building_lookups());
    }

    public function etracs_get_building_revision_settings($request) {
        $etracs = new Assessor_Etracs();
        return rest_ensure_response($etracs->get_building_revision_settings());
    }

    public function etracs_get_rpu_detail($request) {
        $etracs = new Assessor_Etracs();
        $result = $etracs->get_rpu_detail($request);
        if (is_wp_error($result)) return $result;
        return rest_ensure_response($result);
    }

    public function etracs_pull_sync($request) {
        $stats = Assessor_Etracs_Sync::trigger_sync();
        if (is_wp_error($stats)) {
            return $stats;
        }
        return rest_ensure_response(array(
            'success' => true,
            'message' => 'ETRACS sync completed successfully.',
            'stats' => $stats
        ));
    }

    public function etracs_pull_sync_status($request) {
        $status = get_option('assessor_sync_progress', 'Not running');
        return rest_ensure_response(array(
            'status' => $status
        ));
    }
}

