<?php

/**
 * Public property search API secured by API key + secret (for external websites).
 */
class Assessor_Public_API {

    private static $public_fields = array(
        'id',
        'tax_declaration_number',
        'declarant_last_name',
        'declarant_first_name',
        'declarant_middle_initial',
        'business',
        'business_name',
        'location',
        'lot_number',
        'unique_lot_number_identified',
        'survey_number',
        'area_hectare',
        'area_sqm',
        'title_number',
        'assessed_value',
        'effectivity_date',
        'pin',
        'address',
        'assessment_date',
        'kind_of_property',
        'kind_of_property_name',
        'gen_class',
        'gen_class_name',
        'status',
    );

    private function keys_table() {
        global $wpdb;
        return $wpdb->prefix . 'assessor_api_keys';
    }

    private function settings_table() {
        global $wpdb;
        return $wpdb->prefix . 'assessor_settings';
    }

    /** Add public_api_enabled to wp_assessor_settings on older installs. */
    private function ensure_public_api_enabled_column() {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        global $wpdb;
        $table = $this->settings_table();
        $column = $wpdb->get_var($wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'public_api_enabled'",
            $table
        ));
        if (!$column) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN public_api_enabled tinyint(1) NOT NULL DEFAULT 0");
        }
    }

    private function sync_public_api_enabled_setting($enabled) {
        $this->ensure_public_api_enabled_column();

        global $wpdb;
        $settings_table = $this->settings_table();
        $value = $enabled ? 1 : 0;
        $settings_id = $wpdb->get_var("SELECT id FROM $settings_table ORDER BY id DESC LIMIT 1");
        if ($settings_id) {
            $wpdb->update($settings_table, array('public_api_enabled' => $value), array('id' => $settings_id));
        } else {
            $wpdb->insert($settings_table, array('public_api_enabled' => $value), array('%d'));
        }
    }

    private function key_scopes() {
        return array(
            'public_properties' => 'Public Property Search',
        );
    }

    private function encryption_key_material() {
        $material = defined('AUTH_KEY') ? AUTH_KEY : 'assessor_api_encryption';
        if (defined('SECURE_AUTH_KEY')) {
            $material .= SECURE_AUTH_KEY;
        }
        return hash('sha256', $material, true);
    }

    private function encrypt_secret($plaintext) {
        if ($plaintext === '' || !function_exists('openssl_encrypt')) {
            return '';
        }
        $key = $this->encryption_key_material();
        $iv = openssl_random_pseudo_bytes(16);
        $cipher = openssl_encrypt($plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            return '';
        }
        return base64_encode($iv . $cipher);
    }

    private function decrypt_secret($encoded) {
        if ($encoded === '' || !function_exists('openssl_decrypt')) {
            return '';
        }
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 17) {
            return '';
        }
        $iv = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $key = $this->encryption_key_material();
        $plaintext = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return $plaintext === false ? '' : $plaintext;
    }

    private function ensure_secret_encrypted_column() {
        global $wpdb;
        $table = $this->keys_table();
        $column = $wpdb->get_var($wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'secret_encrypted'",
            $table
        ));
        if (!$column) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN secret_encrypted text NULL AFTER secret_hash");
        }
    }

    public function check_api_key($request) {
        $api_key = $this->extract_api_key($request);
        $api_secret = $this->extract_api_secret($request);

        if (empty($api_key) || empty($api_secret)) {
            return new WP_Error(
                'missing_credentials',
                'API key and API secret required. Send X-API-Key and X-API-Secret headers (or api_key and api_secret query parameters).',
                array('status' => 401)
            );
        }

        if (!$this->is_valid_credentials($api_key, $api_secret, 'public_properties')) {
            return new WP_Error('invalid_api_key', 'Invalid API key, secret, or disabled key.', array('status' => 403));
        }

        return true;
    }

    public function extract_api_key($request) {
        $header = $request->get_header('x_api_key');
        if (empty($header)) {
            $header = $request->get_header('x-api-key');
        }
        if (!empty($header)) {
            return trim($header);
        }

        $params = $request->get_params();
        if (!empty($params['api_key'])) {
            return trim($params['api_key']);
        }

        return '';
    }

    public function extract_api_secret($request) {
        $header = $request->get_header('x_api_secret');
        if (empty($header)) {
            $header = $request->get_header('x-api-secret');
        }
        if (!empty($header)) {
            return trim($header);
        }

        $params = $request->get_params();
        if (!empty($params['api_secret'])) {
            return trim($params['api_secret']);
        }

        return '';
    }

    public function is_valid_credentials($api_key, $api_secret, $required_scope = 'public_properties') {
        if (defined('ASSESSOR_PUBLIC_API_KEY') && defined('ASSESSOR_PUBLIC_API_SECRET')
            && ASSESSOR_PUBLIC_API_KEY && ASSESSOR_PUBLIC_API_SECRET) {
            return hash_equals((string) ASSESSOR_PUBLIC_API_KEY, (string) $api_key)
                && hash_equals((string) ASSESSOR_PUBLIC_API_SECRET, (string) $api_secret);
        }

        global $wpdb;
        $table = $this->keys_table();
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if (!$table_exists) {
            return false;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, api_scope, secret_hash, key_hash FROM $table WHERE api_key = %s AND status = %s LIMIT 1",
                $api_key,
                'active'
            ),
            ARRAY_A
        );

        if (empty($row)) {
            return false;
        }

        $scope = isset($row['api_scope']) ? (string) $row['api_scope'] : '';
        if ($scope !== '*' && $scope !== $required_scope) {
            return false;
        }

        $secret_hash = !empty($row['secret_hash']) ? (string) $row['secret_hash'] : (string) $row['key_hash'];
        if ($secret_hash === '' || !wp_check_password($api_secret, $secret_hash)) {
            return false;
        }

        $this->touch_key_usage(intval($row['id']));
        return true;
    }

    private function touch_key_usage($key_id) {
        if ($key_id <= 0) {
            return;
        }
        global $wpdb;
        $table = $this->keys_table();
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        $wpdb->update(
            $table,
            array(
                'last_used_at' => current_time('mysql'),
                'last_used_ip' => $ip,
            ),
            array('id' => $key_id),
            array('%s', '%s'),
            array('%d')
        );
    }

    public function search_properties($request) {
        $params = $request->get_params();

        if (!$this->has_search_criteria($params)) {
            return new WP_Error(
                'search_required',
                'Provide a search term (q, min 2 characters) or at least one filter: tax_declaration_number, declarant_last_name, declarant_first_name, lot_number, title_number, pin, or location.',
                array('status' => 400)
            );
        }

        unset($params['all'], $params['image_status']);
        $params['per_page'] = isset($params['per_page'])
            ? min(25, max(1, intval($params['per_page'])))
            : 20;
        $params['page'] = isset($params['page']) ? max(1, intval($params['page'])) : 1;

        if (!empty($params['q'])) {
            $params['q'] = trim($params['q']);
            if (strlen($params['q']) < 2) {
                return new WP_Error('search_too_short', 'Search term q must be at least 2 characters.', array('status' => 400));
            }
        }

        $internal_request = new WP_REST_Request('GET', '/assessor/v1/properties');
        foreach ($params as $key => $value) {
            if ($value !== null && $value !== '') {
                $internal_request->set_param($key, $value);
            }
        }

        $properties = new Assessor_Properties();
        $result = $properties->get_properties($internal_request);

        if (is_wp_error($result)) {
            return $result;
        }

        $items = isset($result['properties']) ? $result['properties'] : array();
        $sanitized = array();
        foreach ($items as $item) {
            $sanitized[] = $this->sanitize_property($item);
        }

        return array(
            'properties' => $sanitized,
            'pagination' => isset($result['pagination']) ? $result['pagination'] : array(),
        );
    }

    public function get_property_by_id($request) {
        $properties = new Assessor_Properties();
        $property = $properties->get_property(intval($request['id']));

        if (is_wp_error($property)) {
            return $property;
        }

        return $this->sanitize_property($property);
    }

    public function get_property_by_tax_number($request) {
        $properties = new Assessor_Properties();
        $property = $properties->get_property_by_tax_number($request['tax_number']);

        if (is_wp_error($property)) {
            return $property;
        }

        if (empty($property)) {
            return new WP_Error('property_not_found', 'Property not found', array('status' => 404));
        }

        return $this->sanitize_property($property);
    }

    private function has_search_criteria($params) {
        if (!empty($params['q']) && strlen(trim($params['q'])) >= 2) {
            return true;
        }

        $filters = array(
            'tax_declaration_number',
            'declarant_last_name',
            'declarant_first_name',
            'lot_number',
            'title_number',
            'pin',
            'location',
            'business',
        );

        foreach ($filters as $field) {
            if (!empty($params[$field])) {
                return true;
            }
        }

        return false;
    }

    private function sanitize_property($property) {
        if (is_object($property)) {
            $property = (array) $property;
        }

        $out = array();
        foreach (self::$public_fields as $field) {
            if (array_key_exists($field, $property)) {
                $out[$field] = $property[$field];
            }
        }

        return $out;
    }

    public function list_api_keys($request) {
        global $wpdb;
        $table = $this->keys_table();
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if (!$table_exists) {
            return array(
                'items' => array(),
                'available_scopes' => $this->key_scopes(),
            );
        }

        $this->ensure_secret_encrypted_column();
        $rows = $wpdb->get_results(
            "SELECT id, name, api_key, key_prefix, status, created_at,
                    (CASE WHEN secret_encrypted IS NOT NULL AND secret_encrypted != '' THEN 1 ELSE 0 END) AS can_reveal_secret
             FROM $table
             ORDER BY id DESC",
            ARRAY_A
        );

        if (empty($rows)) {
            $rows = array();
        }

        foreach ($rows as &$row) {
            if (empty($row['api_key']) && !empty($row['key_prefix'])) {
                $row['api_key'] = $row['key_prefix'];
            }
            unset($row['key_prefix']);
            $secret_length = 48;
            $row['api_secret_length'] = $secret_length;
            $row['api_secret_masked'] = str_repeat('•', $secret_length);
            $row['can_reveal_secret'] = intval($row['can_reveal_secret']) === 1;
            $row['is_active'] = ($row['status'] === 'active');
        }

        return array(
            'items' => $rows,
            'available_scopes' => $this->key_scopes(),
        );
    }

    private function client_wants_plain_credentials($request) {
        $accept = $request->get_header('accept');
        return is_string($accept) && stripos($accept, 'text/plain') !== false;
    }

    private function attach_one_time_credential_headers(WP_REST_Response $response, $new_id, $api_key, $api_secret) {
        $response->header('X-Assessor-Key-Id', (string) $new_id);
        $response->header('X-Assessor-One-Time-Key', base64_encode($api_key));
        $response->header('X-Assessor-One-Time-Credential', base64_encode($api_secret));
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate');
        $response->header('Pragma', 'no-cache');
        return $response;
    }

    /**
     * One-time create response. Includes alternate field names because some hosts
     * strip JSON keys containing "secret" from REST responses.
     */
    private function format_new_key_response($new_id, $api_key, $api_secret, $name, $api_scope) {
        return array(
            'success' => true,
            'id' => $new_id,
            'api_key' => $api_key,
            'api_secret' => $api_secret,
            'plain_key' => $api_key,
            'plain_secret' => $api_secret,
            'one_time_credential' => base64_encode($api_secret),
            'credentials' => array(
                'id' => $new_id,
                'key' => $api_key,
                'token' => $api_secret,
            ),
            'name' => $name,
            'api_scope' => $api_scope,
            'public_api_enabled' => true,
            'message' => 'Copy the API key and API secret now. The secret will not be shown again.',
        );
    }

    public function generate_api_key($request) {
        $params = $request->get_json_params();
        if (!$params) {
            $params = $request->get_params();
        }

        $name = isset($params['name']) ? sanitize_text_field($params['name']) : '';
        if ($name === '') {
            $name = 'Public Website Key';
        }

        $api_scope = isset($params['api_scope']) ? sanitize_text_field($params['api_scope']) : 'public_properties';
        $allowed = array_keys($this->key_scopes());
        if (!in_array($api_scope, $allowed, true)) {
            return new WP_Error('invalid_scope', 'Invalid API scope.', array('status' => 400));
        }

        $api_key = 'assessor_' . strtolower(wp_generate_password(16, false, false));
        $api_secret = wp_generate_password(48, false, false);
        $secret_hash = wp_hash_password($api_secret);
        $this->ensure_secret_encrypted_column();
        $secret_encrypted = $this->encrypt_secret($api_secret);
        if ($secret_encrypted === '') {
            return new WP_Error(
                'encryption_failed',
                'Could not store an encrypted API secret. Ensure OpenSSL is enabled on the server.',
                array('status' => 500)
            );
        }

        $created_by = $this->get_user_id_from_request($request);

        global $wpdb;
        $table = $this->keys_table();
        $inserted = $wpdb->insert(
            $table,
            array(
                'name' => $name,
                'api_scope' => $api_scope,
                'api_key' => $api_key,
                'secret_hash' => $secret_hash,
                'secret_encrypted' => $secret_encrypted,
                'key_hash' => $secret_hash,
                'key_prefix' => substr($api_key, 0, 20),
                'status' => 'active',
                'created_by' => $created_by,
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d')
        );
        if ($inserted === false) {
            return new WP_Error('insert_failed', 'Failed to save API key.', array('status' => 500));
        }

        $new_id = (int) $wpdb->insert_id;

        $this->sync_public_api_enabled_setting(true);

        if ($this->client_wants_plain_credentials($request)) {
            $plain = $api_key . "\n" . $api_secret . "\n" . $new_id;
            $response = new WP_REST_Response($plain, 201);
            $response->header('Content-Type', 'text/plain; charset=UTF-8');
            return $this->attach_one_time_credential_headers($response, $new_id, $api_key, $api_secret);
        }

        $body = $this->format_new_key_response($new_id, $api_key, $api_secret, $name, $api_scope);
        $response = rest_ensure_response($body);
        if ($response instanceof WP_REST_Response) {
            $response->set_status(201);
            $this->attach_one_time_credential_headers($response, $new_id, $api_key, $api_secret);
        }
        return $response;
    }

    public function update_api_key($request) {
        global $wpdb;
        $table = $this->keys_table();
        $id = isset($request['id']) ? intval($request['id']) : 0;
        if ($id <= 0) {
            return new WP_Error('missing_id', 'API key id is required.', array('status' => 400));
        }

        $params = $request->get_json_params();
        if (!$params) {
            $params = $request->get_params();
        }

        $update = array();
        $formats = array();

        if (isset($params['name'])) {
            $name = sanitize_text_field($params['name']);
            if ($name === '') {
                return new WP_Error('invalid_name', 'Key name cannot be empty.', array('status' => 400));
            }
            $update['name'] = $name;
            $formats[] = '%s';
        }

        $status = null;
        if (isset($params['status'])) {
            $status = sanitize_text_field($params['status']);
        } elseif (isset($params['enabled'])) {
            $status = !empty($params['enabled']) ? 'active' : 'disabled';
        }

        if ($status !== null) {
            if (!in_array($status, array('active', 'disabled'), true)) {
                return new WP_Error('invalid_status', 'Status must be active or disabled.', array('status' => 400));
            }
            $update['status'] = $status;
            $formats[] = '%s';
            if ($status === 'disabled') {
                $update['revoked_at'] = current_time('mysql');
                $formats[] = '%s';
            }
        }

        if (empty($update)) {
            return new WP_Error('missing_fields', 'Provide name and/or status to update.', array('status' => 400));
        }

        $updated = $wpdb->update($table, $update, array('id' => $id), $formats, array('%d'));
        if ($updated === false) {
            return new WP_Error('update_failed', 'Failed to update API key.', array('status' => 500));
        }

        $active_count = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE status = %s",
            'active'
        )));

        $this->sync_public_api_enabled_setting($active_count > 0);

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, name, api_key, status, created_at FROM $table WHERE id = %d",
            $id
        ), ARRAY_A);

        return array(
            'success' => true,
            'item' => $row,
            'public_api_enabled' => $active_count > 0,
            'public_api_configured' => $active_count > 0,
        );
    }

    public function reveal_api_secret($request) {
        $auth = new Assessor_Auth();
        if (!$auth->verify_manager($request)) {
            return new WP_Error('forbidden', 'Not allowed to reveal API secrets.', array('status' => 403));
        }

        $params = $request->get_json_params();
        if (!$params) {
            $params = $request->get_params();
        }
        $password = isset($params['password']) ? (string) $params['password'] : '';
        if ($password === '') {
            return new WP_Error('missing_password', 'Your account password is required.', array('status' => 400));
        }

        $user_id = $auth->get_user_id_from_token($request);
        if (!$user_id) {
            return new WP_Error('unauthorized', 'Unauthorized.', array('status' => 401));
        }

        global $wpdb;
        $table_users = $wpdb->prefix . 'assessor_users';
        $user = $wpdb->get_row($wpdb->prepare(
            "SELECT password FROM $table_users WHERE id = %d LIMIT 1",
            $user_id
        ));
        if (!$user || !wp_check_password($password, $user->password)) {
            return new WP_Error('invalid_password', 'Incorrect password.', array('status' => 403));
        }

        $id = isset($request['id']) ? intval($request['id']) : 0;
        if ($id <= 0) {
            return new WP_Error('missing_id', 'API key id is required.', array('status' => 400));
        }

        $this->ensure_secret_encrypted_column();
        $table = $this->keys_table();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT secret_encrypted FROM $table WHERE id = %d LIMIT 1",
            $id
        ), ARRAY_A);

        if (!$row || empty($row['secret_encrypted'])) {
            return new WP_Error(
                'secret_unavailable',
                'This API key was created before secret reveal was available. Create a new API key and save the secret when shown.',
                array('status' => 404)
            );
        }

        $api_secret = $this->decrypt_secret($row['secret_encrypted']);
        if ($api_secret === '') {
            return new WP_Error('decrypt_failed', 'Could not decrypt API secret.', array('status' => 500));
        }

        return array(
            'success' => true,
            'api_secret' => $api_secret,
        );
    }

    public function revoke_api_key($request) {
        global $wpdb;
        $table = $this->keys_table();
        $id = isset($request['id']) ? intval($request['id']) : 0;

        if ($id <= 0) {
            return new WP_Error('missing_id', 'API key id is required.', array('status' => 400));
        }

        $deleted = $wpdb->delete($table, array('id' => $id), array('%d'));
        if ($deleted === false) {
            return new WP_Error('delete_failed', 'Failed to delete API key.', array('status' => 500));
        }
        if ($deleted === 0) {
            return new WP_Error('not_found', 'API key not found.', array('status' => 404));
        }

        $active_count = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE status = %s",
            'active'
        )));

        $this->sync_public_api_enabled_setting($active_count > 0);

        return array(
            'success' => true,
            'public_api_configured' => $active_count > 0,
            'message' => 'API key deleted.',
        );
    }

    public function set_enabled($request) {
        $params = $request->get_json_params();
        if (!$params) {
            $params = $request->get_params();
        }

        $enabled = !empty($params['enabled']) ? 1 : 0;

        global $wpdb;
        $table = $this->keys_table();
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        $active_count = 0;
        if ($table_exists) {
            $active_count = intval($wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE status = %s",
                'active'
            )));
        }

        if ($enabled && $active_count === 0) {
            return new WP_Error('no_api_key', 'Create an active API key before enabling the public API.', array('status' => 400));
        }

        $this->sync_public_api_enabled_setting((bool) $enabled);

        return array(
            'success' => true,
            'public_api_enabled' => (bool) $enabled,
            'public_api_configured' => $active_count > 0,
        );
    }

    public static function append_public_api_settings($settings) {
        if (!is_array($settings)) {
            $settings = array();
        }

        global $wpdb;
        $table = $wpdb->prefix . 'assessor_api_keys';
        $active_count = 0;
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if ($table_exists) {
            $active_count = intval($wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE status = %s",
                'active'
            )));
        }

        $configured = $active_count > 0;
        $settings['public_api_configured'] = $configured;
        if (array_key_exists('public_api_enabled', $settings)) {
            $settings['public_api_enabled'] = !empty($settings['public_api_enabled']);
        } else {
            $settings['public_api_enabled'] = $configured;
        }
        $settings['public_api_keys_count'] = $active_count;

        unset($settings['public_api_key_hash'], $settings['public_api_key_prefix']);

        return $settings;
    }

    private function get_user_id_from_request($request) {
        $auth_header = $request->get_header('authorization');
        if (!$auth_header || strpos($auth_header, 'Bearer ') !== 0) {
            return 0;
        }
        $token = substr($auth_header, 7);
        if (!$token) {
            return 0;
        }
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return 0;
        }
        $payload_json = base64_decode(strtr($parts[1], '-_', '+/'));
        if (!$payload_json) {
            return 0;
        }
        $payload = json_decode($payload_json, true);
        if (!is_array($payload) || empty($payload['user_id'])) {
            return 0;
        }
        return intval($payload['user_id']);
    }
}
