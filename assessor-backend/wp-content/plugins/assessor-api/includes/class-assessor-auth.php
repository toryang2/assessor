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
        
        // Update last_login timestamp using MySQL's current timestamp for consistency with created_at
        $wpdb->query($wpdb->prepare(
            "UPDATE $table_users SET last_login = CURRENT_TIMESTAMP WHERE id = %s",
            $user->id
        ));

        // Generate JWT token
        $token = $this->generate_token($user);
        
        // Log audit trail
        $audit = new Assessor_Audit();
        $audit->log_activity($user->id, 'login', 'assessor_users', $user->id, null, array('event' => 'login'));
        
        return array(
            'success' => true,
            'token' => $token,
            'user' => array(
                'id' => $user->id,
                'username' => $user->username,
                'email' => $user->email,
                'avatar_url' => $user->avatar_url,
                'full_name' => $user->full_name,
                'role' => $user->role
            )
        );
    }
    
    public function logout($request) {
        $user_id = $this->get_user_id_from_token($request);
        
        if ($user_id) {
            $audit = new Assessor_Audit();
            $audit->log_activity($user_id, 'logout', 'assessor_users', $user_id, null, array('event' => 'logout'));
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
                    "SELECT id, username, email, avatar_url, full_name, role, status FROM $table_users WHERE id = %s AND status = 'active'",
                    $payload->user_id
                ));
                
                if ($user) {
                    return array(
                        'valid' => true,
                        'user' => array(
                            'id' => $user->id,
                            'username' => $user->username,
                        'email' => $user->email,
                        'avatar_url' => $user->avatar_url,
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
            if ($payload && isset($payload->role) && in_array($payload->role, array('superadmin','administrator','admin'), true)) {
                return true;
            }
            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    public function verify_manager($request) {
        $token = $this->get_token_from_request($request);
        if (!$token) {
            return false;
        }
        try {
            $payload = $this->verify_token_signature($token);
            if ($payload && isset($payload->role)) {
                $role = strtolower($payload->role);
                // Allow administrators and municipal assessors
                if (in_array($role, array('superadmin','admin','administrator','assessor','municipal assessor'), true)) {
                    return true;
                }
            }
            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    public function verify_superadmin($request) {
        $token = $this->get_token_from_request($request);
        if (!$token) {
            return new WP_Error('unauthorized', 'Authentication required.', array('status' => 401));
        }
        try {
            $payload = $this->verify_token_signature($token);
            if ($payload && isset($payload->role) && strtolower($payload->role) === 'superadmin') {
                return true;
            }
            return new WP_Error('forbidden', 'Superadmin access required.', array('status' => 403));
        } catch (Exception $e) {
            return new WP_Error('unauthorized', 'Invalid token.', array('status' => 401));
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
    
    public function get_users($request) {
        global $wpdb;
        $params = $request->get_params();
        $page = isset($params['page']) ? max(1, intval($params['page'])) : 1;
        $per_page = isset($params['per_page']) ? min(100, max(1, intval($params['per_page']))) : 20;
        $offset = ($page - 1) * $per_page;

        $table_users = $wpdb->prefix . 'assessor_users';
        $where = [];
        $vals = [];

        if (!empty($params['search'])) {
            $s = '%' . $wpdb->esc_like($params['search']) . '%';
            $where[] = '(username LIKE %s OR email LIKE %s OR full_name LIKE %s)';
            $vals = array_merge($vals, [$s, $s, $s]);
        }
        if (!empty($params['role'])) {
            $where[] = 'role = %s';
            $vals[] = sanitize_text_field($params['role']);
        }
        if (!empty($params['status'])) {
            $where[] = 'status = %s';
            $vals[] = sanitize_text_field($params['status']);
        }

        $where_sql = '';
        if (!empty($where)) {
            $where_sql = 'WHERE ' . implode(' AND ', $where);
        }

        $count_sql = "SELECT COUNT(*) FROM $table_users $where_sql";
        $total = !empty($vals) ? (int)$wpdb->get_var($wpdb->prepare($count_sql, $vals)) : (int)$wpdb->get_var($count_sql);

        $query = "SELECT id, username, email, avatar_url, full_name, role, status, last_login, created_at
                  FROM $table_users
                  $where_sql
                  ORDER BY created_at DESC
                  LIMIT %d OFFSET %d";
        $users = $wpdb->get_results($wpdb->prepare($query, array_merge($vals, [$per_page, $offset])));

        return array(
            'users' => $users,
            'pagination' => array(
                'page' => $page,
                'per_page' => $per_page,
                'total' => $total,
                'total_pages' => ceil($total / $per_page)
            )
        );
    }

    public function create_user($request) {
        global $wpdb;
        $params = $request->get_params();

        $required = array('username','full_name','role','password');
        foreach ($required as $field) {
            if (empty($params[$field])) {
                return new WP_Error('missing_field', "Field '$field' is required", array('status' => 400));
            }
        }

        $username = sanitize_text_field($params['username']);
        $email = isset($params['email']) ? sanitize_email($params['email']) : '';
        $email = ($email === '') ? null : $email;
        $full_name = sanitize_text_field($params['full_name']);
        $role = sanitize_text_field($params['role']);
        $password = $params['password'];
        $status = isset($params['status']) ? sanitize_text_field($params['status']) : 'active';

        if (!in_array($role, array('superadmin','admin','assessor','verifier','editor','viewer'), true)) {
            return new WP_Error('invalid_role', 'Invalid role', array('status' => 422));
        }

        if (isset($params['password_confirm']) && $params['password_confirm'] !== '' && $params['password_confirm'] !== $params['password']) {
            return new WP_Error('password_mismatch', 'Password confirmation does not match', array('status' => 422));
        }
        
        $table_users = $wpdb->prefix . 'assessor_users';
        $exists_user = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_users WHERE username = %s", $username));
        if ($exists_user) {
            return new WP_Error('duplicate_username', 'Username already exists', array('status' => 409));
        }
        if (!is_null($email)) {
            $exists_email = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_users WHERE email = %s", $email));
            if ($exists_email) {
                return new WP_Error('duplicate_email', 'Email already exists', array('status' => 409));
            }
        }

        $hash = wp_hash_password($password);

        $table_settings = $wpdb->prefix . 'assessor_settings';
        $settings_row = $wpdb->get_row("SELECT municipality_prefix FROM $table_settings LIMIT 1", ARRAY_A);
        
        $muni = 'GBL';
        if ($settings_row && !empty($settings_row['municipality_prefix'])) {
            $muni = strtoupper(substr(trim($settings_row['municipality_prefix']), 0, 3));
        }
        if (!class_exists('Assessor_ULID')) {
            require_once ASSESSOR_API_PLUGIN_DIR . 'includes/class-assessor-ulid.php';
        }
        $new_id = Assessor_ULID::generate_with_prefix($muni);

        $data = array(
            'id' => $new_id,
            'username' => $username,
            'password' => $hash,
            'full_name' => $full_name,
            'role' => $role,
            'status' => $status,
        );
        $formats = array('%s','%s','%s','%s','%s','%s');
        if (!is_null($email)) { $data['email'] = $email; $formats[] = '%s'; }
        $result = $wpdb->insert($table_users, $data, $formats);

        if ($result === false) {
            return new WP_Error('insert_failed', 'Failed to create user', array('status' => 500));
        }
        // Audit with user_id = 0 since public creation
        $audit = new Assessor_Audit();
        $audit->log_activity(0, 'create', 'assessor_users', $new_id, null, array('event' => 'create_user'));
        return array('success' => true, 'id' => $new_id);
    }

    public function update_user($id, $request) {
        global $wpdb;
        $params = $request->get_params();
        $table_users = $wpdb->prefix . 'assessor_users';

        $user = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_users WHERE id = %s", $id));
        if (!$user) {
            return new WP_Error('user_not_found', 'User not found', array('status' => 404));
        }

        // Prevent editing SUPERADMIN unless requester is SUPERADMIN
        if (isset($user->role) && strtolower($user->role) === 'superadmin') {
            $token = $this->get_token_from_request($request);
            $requester_is_super = false;
            if ($token) {
                try {
                    $payload = $this->verify_token_signature($token);
                    $requester_is_super = $payload && isset($payload->role) && strtolower($payload->role) === 'superadmin';
                } catch (Exception $e) {}
            }
            if (!$requester_is_super) {
                return new WP_Error('forbidden', 'You cannot modify the superadmin user', array('status' => 403));
            }
        }

        $data = array();
        $formats = array();
        if (isset($params['username'])) {
            $username = sanitize_text_field($params['username']);
            if ($username !== $user->username) {
                $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_users WHERE username = %s AND id != %s", $username, $id));
                if ($exists) return new WP_Error('duplicate_username', 'Username already exists', array('status' => 409));
            }
            $data['username'] = $username; $formats[] = '%s';
        }
        if (array_key_exists('email', $params)) {
            $incoming = $params['email'];
            if ($incoming === '') {
                // Set email to NULL explicitly
                $wpdb->query($wpdb->prepare("UPDATE $table_users SET email = NULL WHERE id = %s", $id));
            } else {
                $email = sanitize_email($incoming);
                if ($email !== $user->email) {
                    $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_users WHERE email = %s AND id != %s", $email, $id));
                    if ($exists) return new WP_Error('duplicate_email', 'Email already exists', array('status' => 409));
                }
                $data['email'] = $email; $formats[] = '%s';
            }
        }
        if (isset($params['full_name'])) { $data['full_name'] = sanitize_text_field($params['full_name']); $formats[] = '%s'; }
        if (isset($params['avatar_url'])) {
            $data['avatar_url'] = esc_url_raw($params['avatar_url']);
            $formats[] = '%s';
        }
        if (isset($params['role'])) {
            $role = sanitize_text_field($params['role']);
            if (!in_array($role, array('superadmin','admin','assessor','verifier','editor','viewer'), true)) {
                return new WP_Error('invalid_role', 'Invalid role', array('status' => 422));
            }
            $data['role'] = $role; $formats[] = '%s';
        }
        if (isset($params['status'])) { $data['status'] = sanitize_text_field($params['status']); $formats[] = '%s'; }
        if (!empty($params['password'])) {
            if (isset($params['password_confirm']) && $params['password_confirm'] !== '' && $params['password_confirm'] !== $params['password']) {
                return new WP_Error('password_mismatch', 'Password confirmation does not match', array('status' => 422));
            }
            $data['password'] = wp_hash_password($params['password']); $formats[] = '%s';
        }

        if (empty($data)) {
            return array('success' => true, 'message' => 'No changes');
        }

        $result = $wpdb->update($table_users, $data, array('id' => $id), $formats, array('%s'));
        if ($result === false) {
            return new WP_Error('update_failed', 'Failed to update user', array('status' => 500));
        }
        $audit = new Assessor_Audit();
        $audit->log_activity(0, 'update', 'assessor_users', $id, null, array('event' => 'update_user'));
        return array('success' => true);
    }

    public function delete_user($id) {
        global $wpdb;
        $table_users = $wpdb->prefix . 'assessor_users';
        $user = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_users WHERE id = %s", $id));
        if (!$user) {
            return new WP_Error('user_not_found', 'User not found', array('status' => 404));
        }
        // Prevent deleting SUPERADMIN unless requester is SUPERADMIN
        if (isset($user->role) && strtolower($user->role) === 'superadmin') {
            $token = func_num_args() > 1 ? $this->get_token_from_request(func_get_arg(1)) : null;
            $requester_is_super = false;
            if ($token) {
                try {
                    $payload = $this->verify_token_signature($token);
                    $requester_is_super = $payload && isset($payload->role) && strtolower($payload->role) === 'superadmin';
                } catch (Exception $e) {}
            }
            if (!$requester_is_super) {
                return new WP_Error('forbidden', 'You cannot delete the superadmin user', array('status' => 403));
            }
        }
        // prevent deleting last admin unless requester is superadmin
        if ($user->role === 'admin') {
            $canDeleteAdmin = false;
            // Safely retrieve the original request object if provided by caller
            $request_obj = func_num_args() > 1 ? func_get_arg(1) : null;
            $token = $request_obj ? $this->get_token_from_request($request_obj) : null;
            if ($token) {
                try {
                    $payload = $this->verify_token_signature($token);
                    if ($payload && isset($payload->role) && strtolower($payload->role) === 'superadmin') {
                        $canDeleteAdmin = true;
                    }
                } catch (Exception $e) {}
            }
            $admin_count = (int)$wpdb->get_var("SELECT COUNT(*) FROM $table_users WHERE role = 'admin'");
            if (!$canDeleteAdmin || $admin_count <= 1) {
                return new WP_Error('forbidden', 'Cannot delete admin user', array('status' => 403));
            }
        }
        $result = $wpdb->delete($table_users, array('id' => $id), array('%s'));
        if ($result === false) {
            return new WP_Error('delete_failed', 'Failed to delete user', array('status' => 500));
        }
        $audit = new Assessor_Audit();
        $audit->log_activity(0, 'delete', 'assessor_users', $id, null, array('event' => 'delete_user'));
        return array('success' => true);
    }

    public function upload_avatar($request) {
        global $wpdb;
        $user_id = isset($request['id']) ? intval($request['id']) : 0;
        if ($user_id <= 0) {
            return new WP_Error('invalid_user', 'Invalid user ID', array('status' => 400));
        }

        $current_user_id = $this->get_user_id_from_token($request);
        if (!$current_user_id) {
            return new WP_Error('unauthorized', 'Authentication required', array('status' => 401));
        }

        $table_users = $wpdb->prefix . 'assessor_users';
        $target_user = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_users WHERE id = %s", $user_id));
        if (!$target_user) {
            return new WP_Error('user_not_found', 'User not found', array('status' => 404));
        }

        $acting_user = $wpdb->get_row($wpdb->prepare("SELECT role FROM $table_users WHERE id = %s", $current_user_id));
        $allowed_roles = array('superadmin','admin','administrator');
        $can_update = ($current_user_id === $user_id);
        if (!$can_update && $acting_user && isset($acting_user->role)) {
            $can_update = in_array(strtolower($acting_user->role), $allowed_roles, true);
        }
        if (!$can_update) {
            return new WP_Error('forbidden', 'You do not have permission to update this profile', array('status' => 403));
        }

        if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
            return new WP_Error('upload_error', 'No file uploaded or upload error occurred', array('status' => 400));
        }

        $file = $_FILES['avatar'];
        $max_size = 5 * 1024 * 1024; // 5MB
        if ($file['size'] > $max_size) {
            return new WP_Error('file_too_large', 'Image exceeds 5MB limit', array('status' => 400));
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed_types = array('jpg','jpeg','png','gif','webp');
        if (!in_array($ext, $allowed_types, true)) {
            return new WP_Error('invalid_file_type', 'Only images are allowed (jpg, jpeg, png, gif, webp)', array('status' => 400));
        }

        $upload_dir = wp_upload_dir();
        $folder = trailingslashit($upload_dir['basedir']) . 'assessor-profile-photos';
        $base_url = trailingslashit($upload_dir['baseurl']) . 'assessor-profile-photos';
        if (!file_exists($folder)) {
            if (!wp_mkdir_p($folder)) {
                return new WP_Error('dir_creation_failed', 'Failed to create profile upload directory', array('status' => 500));
            }
        }

        $filename = 'avatar_' . $user_id . '_' . time() . '_' . wp_generate_password(6, false) . '.' . $ext;
        $path = $folder . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $path)) {
            return new WP_Error('move_failed', 'Failed to move uploaded avatar', array('status' => 500));
        }

        $url = $base_url . '/' . $filename;

        if (!empty($target_user->avatar_url)) {
            $old_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $target_user->avatar_url);
            $old_path = wp_normalize_path($old_path);
            $safe_base = wp_normalize_path($folder);
            if ($old_path && strpos($old_path, $safe_base) === 0 && file_exists($old_path)) {
                @unlink($old_path);
            }
        }

        $wpdb->update($table_users, array('avatar_url' => esc_url_raw($url)), array('id' => $user_id), array('%s'), array('%s'));

        $audit = new Assessor_Audit();
        $audit->log_activity($current_user_id, 'update', 'assessor_users', $user_id, null, array('event' => 'upload_avatar'));

        return array('success' => true, 'avatar_url' => $url);
    }
    
    private function generate_token($user) {
        $header = json_encode(array('typ' => 'JWT', 'alg' => $this->algorithm));
        $payload = json_encode(array(
            'user_id' => $user->id,
            'username' => $user->username,
            'role' => $user->role,
            'iss' => get_site_url(), // Add issuer field
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

