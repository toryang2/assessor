<?php

// FORCE RELOAD TEST
error_log('🔍 Assessor Auth: Class file loaded - Version 1.0.2');

class Assessor_Auth {
    
    private $secret_key;
    private $algorithm = 'HS256';
    
    public function __construct() {
        // Use JWT secret from wp-config.php if available, otherwise fallback to default
        if (defined('JWT_AUTH_SECRET_KEY')) {
            $this->secret_key = JWT_AUTH_SECRET_KEY;
            error_log('🔍 Assessor Auth: Using JWT secret from wp-config.php');
        } else {
            $this->secret_key = 'assessor_secret_key_2024';
            error_log('⚠️ Assessor Auth: JWT_AUTH_SECRET_KEY not defined, using fallback secret');
        }
        error_log('🔍 Assessor Auth: Secret key length: ' . strlen($this->secret_key));
    }
    
    public function login($request) {
        global $wpdb;
        
        // Debug: Log the request data
        error_log('🔍 Assessor Auth: Login request received');
        error_log('🔍 Assessor Auth: Request params: ' . print_r($request->get_params(), true));
        error_log('🔍 Assessor Auth: Request body: ' . print_r($request->get_body(), true));
        error_log('🔍 Assessor Auth: Request JSON: ' . print_r($request->get_json_params(), true));
        
        // Try multiple ways to get the parameters
        $params = $request->get_params();
        $json_params = $request->get_json_params();
        $body = $request->get_body();
        
        // Use JSON params if available, otherwise fall back to regular params
        $data = !empty($json_params) ? $json_params : $params;
        
        // If still empty, try to parse the body manually
        if (empty($data) && !empty($body)) {
            $data = json_decode($body, true);
        }
        
        error_log('🔍 Assessor Auth: Final data array: ' . print_r($data, true));
        
        $username = isset($data['username']) ? sanitize_text_field($data['username']) : '';
        $password = isset($data['password']) ? $data['password'] : '';
        
        error_log('🔍 Assessor Auth: Extracted username: ' . $username);
        error_log('🔍 Assessor Auth: Extracted password: ' . ($password ? 'YES' : 'NO'));
        
        if (empty($username) || empty($password)) {
            error_log('❌ Assessor Auth: Missing credentials - username: ' . $username . ', password: ' . ($password ? 'YES' : 'NO'));
            return new WP_Error('missing_credentials', 'Username and password are required', array('status' => 400));
        }
        
        // Check user in custom table
        $table_users = $wpdb->prefix . 'assessor_users';
        $user = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_users WHERE username = %s AND status = 'active'",
            $username
        ));
        
        if (!$user || !wp_check_password($password, $user->password)) {
            return new WP_Error('invalid_credentials', 'Invalid username or password', array('status' => 401));
        }
        
        // Generate JWT token
        $token = $this->generate_token($user);
        
        // Log audit trail
        $this->log_audit($user->id, 'login', 'assessor_users', $user->id);
        
        return array(
            'success' => true,
            'token' => $token,
            'user' => array(
                'id' => $user->id,
                'username' => $user->username,
                'email' => $user->email,
                'full_name' => $user->full_name,
                'role' => $user->role
            )
        );
    }
    
    public function logout($request) {
        $user_id = $this->get_user_id_from_token($request);
        
        if ($user_id) {
            $this->log_audit($user_id, 'logout', 'assessor_users', $user_id);
        }
        
        return array('success' => true, 'message' => 'Logged out successfully');
    }

    public function validate_token($request) {
        $token = $this->get_token_from_request($request);
        
        if (!$token) {
            return array('valid' => false, 'message' => 'No token provided');
        }
        
        try {
            $payload = $this->verify_token_signature($token);
            
            if ($payload) {
                // Get current user data
                global $wpdb;
                $table_users = $wpdb->prefix . 'assessor_users';
                $user = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, username, email, full_name, role, status FROM $table_users WHERE id = %d AND status = 'active'",
                    $payload->user_id
                ));
                
                if ($user) {
                    return array(
                        'valid' => true,
                        'user' => array(
                            'id' => $user->id,
                            'username' => $user->username,
                            'email' => $user->email,
                            'full_name' => $user->full_name,
                            'role' => $user->role
                        )
                    );
                } else {
                    return array('valid' => false, 'message' => 'User not found or inactive');
                }
            } else {
                return array('valid' => false, 'message' => 'Invalid token');
            }
        } catch (Exception $e) {
            return array('valid' => false, 'message' => 'Token validation failed');
        }
    }
    
    public function verify_token($request) {
        // Simple test to see if this method is called
        error_log('🔍 Assessor Auth: verify_token method called!');
        
        // Debug: Log the request headers
        error_log('🔍 Assessor Auth: verify_token called');
        error_log('🔍 Assessor Auth: Request headers: ' . print_r($request->get_headers(), true));
        
        $token = $this->get_token_from_request($request);
        
        error_log('🔍 Assessor Auth: Extracted token: ' . ($token ? 'YES' : 'NO'));
        if ($token) {
            error_log('🔍 Assessor Auth: Token preview: ' . substr($token, 0, 20) . '...');
        }
        
        if (!$token) {
            error_log('❌ Assessor Auth: No token found in request');
            return false;
        }
        
        try {
            $payload = $this->verify_token_signature($token);
            error_log('🔍 Assessor Auth: Token verification result: ' . ($payload ? 'SUCCESS' : 'FAILED'));
            
            if ($payload) {
                error_log('🔍 Assessor Auth: Payload user_id: ' . $payload->user_id);
                error_log('🔍 Assessor Auth: Payload username: ' . $payload->username);
                error_log('🔍 Assessor Auth: Payload role: ' . $payload->role);
            }
            
            return $payload !== false;
        } catch (Exception $e) {
            error_log('❌ Assessor Auth: Token verification exception: ' . $e->getMessage());
            return false;
        }
    }
    
    public function verify_admin($request) {
        $token = $this->get_token_from_request($request);
        
        if (!$token) {
            return false;
        }
        
        try {
            $payload = $this->verify_token_signature($token);
            if ($payload && isset($payload->role) && $payload->role === 'admin') {
                return true;
            }
            return false;
        } catch (Exception $e) {
            return false;
        }
    }
    
    public function get_user_id_from_token($request) {
        $token = $this->get_token_from_request($request);
        
        if (!$token) {
            return false;
        }
        
        try {
            $payload = $this->verify_token_signature($token);
            return $payload ? $payload->user_id : false;
        } catch (Exception $e) {
            return false;
        }
    }
    
    public function get_users() {
        global $wpdb;
        
        $table_users = $wpdb->prefix . 'assessor_users';
        $users = $wpdb->get_results(
            "SELECT id, username, email, full_name, role, status, created_at FROM $table_users ORDER BY created_at DESC"
        );
        
        return array('users' => $users);
    }
    
    private function generate_token($user) {
        $header = json_encode(array('typ' => 'JWT', 'alg' => $this->algorithm));
        $payload = json_encode(array(
            'user_id' => $user->id,
            'username' => $user->username,
            'role' => $user->role,
            'iat' => time(),
            'exp' => time() + (24 * 60 * 60) // 24 hours
        ));
        
        $base64_header = $this->base64url_encode($header);
        $base64_payload = $this->base64url_encode($payload);
        
        $signature = hash_hmac('sha256', $base64_header . "." . $base64_payload, $this->secret_key, true);
        $base64_signature = $this->base64url_encode($signature);
        
        return $base64_header . "." . $base64_payload . "." . $base64_signature;
    }
    
    private function verify_token_signature($token) {
        error_log('🔍 Assessor Auth: verify_token_signature called with token: ' . substr($token, 0, 20) . '...');
        
        $parts = explode('.', $token);
        
        if (count($parts) !== 3) {
            error_log('❌ Assessor Auth: Token has wrong number of parts: ' . count($parts));
            return false;
        }
        
        list($header, $payload, $signature) = $parts;
        error_log('🔍 Assessor Auth: Token parts extracted - header: ' . substr($header, 0, 10) . '..., payload: ' . substr($payload, 0, 10) . '..., signature: ' . substr($signature, 0, 10) . '...');
        
        $expected_signature = hash_hmac('sha256', $header . "." . $payload, $this->secret_key, true);
        $expected_signature = $this->base64url_encode($expected_signature);
        
        error_log('🔍 Assessor Auth: Expected signature: ' . $expected_signature);
        error_log('🔍 Assessor Auth: Actual signature: ' . $signature);
        
        if (!hash_equals($signature, $expected_signature)) {
            error_log('❌ Assessor Auth: Signature verification failed');
            return false;
        }
        
        error_log('✅ Assessor Auth: Signature verification passed');
        
        $payload_data = json_decode($this->base64url_decode($payload));
        
        error_log('🔍 Assessor Auth: Decoded payload: ' . print_r($payload_data, true));
        
        if (!$payload_data || !isset($payload_data->exp) || $payload_data->exp < time()) {
            error_log('❌ Assessor Auth: Payload validation failed - payload_data: ' . ($payload_data ? 'YES' : 'NO') . ', exp: ' . (isset($payload_data->exp) ? $payload_data->exp : 'NO') . ', current_time: ' . time());
            return false;
        }
        
        error_log('✅ Assessor Auth: Token verification successful');
        return $payload_data;
    }
    
    private function get_token_from_request($request) {
        $headers = $request->get_headers();
        
        error_log('🔍 Assessor Auth: get_token_from_request - all headers: ' . print_r($headers, true));
        
        if (isset($headers['authorization'])) {
            $auth_header = $headers['authorization'][0];
            error_log('🔍 Assessor Auth: Authorization header found: ' . $auth_header);
            
            if (preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
                error_log('🔍 Assessor Auth: Bearer token extracted successfully');
                return $matches[1];
            } else {
                error_log('❌ Assessor Auth: Bearer pattern not found in authorization header');
            }
        } else {
            error_log('❌ Assessor Auth: No authorization header found');
        }
        
        return false;
    }
    
    private function base64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    
    private function base64url_decode($data) {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', 3 - (3 + strlen($data)) % 4));
    }
    
    private function log_audit($user_id, $action, $table_name, $record_id) {
        $audit = new Assessor_Audit();
        $audit->log_activity($user_id, $action, $table_name, $record_id);
    }
}

