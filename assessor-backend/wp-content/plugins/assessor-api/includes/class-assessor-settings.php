<?php

class Assessor_Settings {

	private $upload_dir;
	private $logo_folder = 'assessor-settings';
	private $allowed_image_types = array('jpg', 'jpeg', 'png', 'gif', 'webp');
	private $max_image_size = 5 * 1024 * 1024; // 5MB

	public function __construct() {
		$this->upload_dir = wp_upload_dir();
	}

	public function get_settings() {
		global $wpdb;
		$table = $wpdb->prefix . 'assessor_settings';
		$settings = $wpdb->get_row("SELECT * FROM $table ORDER BY id DESC LIMIT 1", ARRAY_A);
		if (!$settings) {
			$settings = array(
				'app_logo_url' => '',
				'header_photo_url' => '',
				'header_province' => 'BUKIDNON',
				'header_municipality' => 'KITAOTAO',
				'header_office' => 'OFFICE OF THE MUNICIPAL ASSESSOR',
				'request_place_issued_default' => '',
				'verifier_signatory_name' => '',
				'verifier_signatory_title' => '',
				'municipal_assessor_name' => '',
				'municipal_assessor_license' => '',
				'municipal_assessor_title' => '',
				'municipal_assessor_suffix' => '',
				'afk_timeout' => 30
			);
		}
		// Ensure municipal_assessor_license is always returned as a string to preserve leading zeros
		if (isset($settings['municipal_assessor_license'])) {
			error_log('🔍 SETTINGS: Raw license from DB = ' . var_export($settings['municipal_assessor_license'], true));
			error_log('🔍 SETTINGS: License type = ' . gettype($settings['municipal_assessor_license']));
			$settings['municipal_assessor_license'] = (string) $settings['municipal_assessor_license'];
			error_log('🔍 SETTINGS: After string cast = ' . var_export($settings['municipal_assessor_license'], true));
		}
		return Assessor_Public_API::append_public_api_settings($settings);
	}

	public function save_settings($request) {
		$params = $request->get_json_params();
		if (!$params) {
			$params = $request->get_params();
		}

		$allowed_keys = array('app_logo_url','header_photo_url','header_province','header_municipality','header_office','request_place_issued_default','verifier_signatory_name','verifier_signatory_title','municipal_assessor_name','municipal_assessor_license','municipal_assessor_suffix','municipal_assessor_title','afk_timeout');
		$uppercase_keys = array('verifier_signatory_name','verifier_signatory_title','municipal_assessor_name','municipal_assessor_license','municipal_assessor_suffix','municipal_assessor_title');
		$integer_keys = array('afk_timeout');
		$data = array();
		foreach ($allowed_keys as $key) {
			if (isset($params[$key])) {
				if (in_array($key, $integer_keys, true)) {
					$val = intval($params[$key]);
					// Ensure afk_timeout is within valid range (5-480 minutes)
					if ($key === 'afk_timeout' && ($val < 5 || $val > 480)) {
						$val = 30; // Default to 30 minutes if invalid
					}
				} else {
					// Special handling for municipal_assessor_license to preserve leading zeros
					if ($key === 'municipal_assessor_license') {
						$val = is_string($params[$key]) ? $params[$key] : '';
						// Don't sanitize or uppercase the license to preserve leading zeros
					} else {
						$val = is_string($params[$key]) ? sanitize_text_field($params[$key]) : '';
						if (in_array($key, $uppercase_keys, true)) {
							$val = strtoupper($val);
						}
					}
				}
				$data[$key] = $val;
				
			}
		}
		if (empty($data)) {
			return $this->get_settings();
		}
		global $wpdb;
		$table = $wpdb->prefix . 'assessor_settings';
		$existing_id = $wpdb->get_var("SELECT id FROM $table ORDER BY id DESC LIMIT 1");
		if ($existing_id) {
			$wpdb->update($table, $data, array('id' => $existing_id));
		} else {
			$wpdb->insert($table, $data);
		}
		return $this->get_settings();
	}

	public function upload_logo($request) {
		// Validate file
		if (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
			return new WP_Error('upload_error', 'No file uploaded or upload error occurred', array('status' => 400));
		}

		$file = $_FILES['logo'];
		if ($file['size'] > $this->max_image_size) {
			return new WP_Error('file_too_large', 'Image exceeds 5MB limit', array('status' => 400));
		}

		$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
		if (!in_array($ext, $this->allowed_image_types)) {
			return new WP_Error('invalid_file_type', 'Only images are allowed (jpg, jpeg, png, gif, webp)', array('status' => 400));
		}

		// Ensure folder exists under uploads
		$base_dir = trailingslashit($this->upload_dir['basedir']) . $this->logo_folder;
		$base_url = trailingslashit($this->upload_dir['baseurl']) . $this->logo_folder;
		if (!file_exists($base_dir)) {
			if (!wp_mkdir_p($base_dir)) {
				return new WP_Error('dir_creation_failed', 'Failed to create settings upload directory', array('status' => 500));
			}
		}

		$filename = 'logo_' . time() . '_' . wp_generate_password(6, false) . '.' . $ext;
		$path = $base_dir . '/' . $filename;

		if (!move_uploaded_file($file['tmp_name'], $path)) {
			return new WP_Error('move_failed', 'Failed to move uploaded logo', array('status' => 500));
		}

		$url = $base_url . '/' . $filename;

		// Remove previously saved logo if it exists and is inside our settings folder
		global $wpdb;
		$settings_table = $wpdb->prefix . 'assessor_settings';
		$old_url = $wpdb->get_var("SELECT app_logo_url FROM $settings_table ORDER BY id DESC LIMIT 1");
		if (!empty($old_url) && $old_url !== $url) {
			$old_path = str_replace($this->upload_dir['baseurl'], $this->upload_dir['basedir'], $old_url);
			$old_path = wp_normalize_path($old_path);
			$safe_base = wp_normalize_path($base_dir);
			if (strpos($old_path, $safe_base) === 0 && file_exists($old_path)) {
				@unlink($old_path);
			}
		}

		// Persist to settings table
		$table = $wpdb->prefix . 'assessor_settings';
		$existing_id = $wpdb->get_var("SELECT id FROM $table ORDER BY id DESC LIMIT 1");
		if ($existing_id) {
			$wpdb->update($table, array('app_logo_url' => esc_url_raw($url)), array('id' => $existing_id));
		} else {
			$wpdb->insert($table, array('app_logo_url' => esc_url_raw($url)));
		}

		return array('app_logo_url' => $url);
	}

	public function upload_header_photo($request) {
		// Validate file
		if (!isset($_FILES['header_photo']) || $_FILES['header_photo']['error'] !== UPLOAD_ERR_OK) {
			return new WP_Error('upload_error', 'No file uploaded or upload error occurred', array('status' => 400));
		}

		$file = $_FILES['header_photo'];
		if ($file['size'] > $this->max_image_size) {
			return new WP_Error('file_too_large', 'Image exceeds 5MB limit', array('status' => 400));
		}

		$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
		if (!in_array($ext, $this->allowed_image_types)) {
			return new WP_Error('invalid_file_type', 'Only images are allowed (jpg, jpeg, png, gif, webp)', array('status' => 400));
		}

		// Ensure folder exists under uploads
		$base_dir = trailingslashit($this->upload_dir['basedir']) . $this->logo_folder;
		$base_url = trailingslashit($this->upload_dir['baseurl']) . $this->logo_folder;
		if (!file_exists($base_dir)) {
			if (!wp_mkdir_p($base_dir)) {
				return new WP_Error('dir_creation_failed', 'Failed to create settings upload directory', array('status' => 500));
			}
		}

		$filename = 'header_photo_' . time() . '_' . wp_generate_password(6, false) . '.' . $ext;
		$path = $base_dir . '/' . $filename;

		if (!move_uploaded_file($file['tmp_name'], $path)) {
			return new WP_Error('move_failed', 'Failed to move uploaded header photo', array('status' => 500));
		}

		$url = $base_url . '/' . $filename;

		// Remove previously saved header photo if it exists and is inside our settings folder
		global $wpdb;
		$settings_table = $wpdb->prefix . 'assessor_settings';
		$old_url = $wpdb->get_var("SELECT header_photo_url FROM $settings_table ORDER BY id DESC LIMIT 1");
		if (!empty($old_url) && $old_url !== $url) {
			$old_path = str_replace($this->upload_dir['baseurl'], $this->upload_dir['basedir'], $old_url);
			$old_path = wp_normalize_path($old_path);
			$safe_base = wp_normalize_path($base_dir);
			if (strpos($old_path, $safe_base) === 0 && file_exists($old_path)) {
				@unlink($old_path);
			}
		}

		// Persist to settings table
		$table = $wpdb->prefix . 'assessor_settings';
		$existing_id = $wpdb->get_var("SELECT id FROM $table ORDER BY id DESC LIMIT 1");
		if ($existing_id) {
			$wpdb->update($table, array('header_photo_url' => esc_url_raw($url)), array('id' => $existing_id));
		} else {
			$wpdb->insert($table, array('header_photo_url' => esc_url_raw($url)));
		}

		return array('header_photo_url' => $url);
	}

	public function get_property_types() {
		global $wpdb;
		$table = $wpdb->prefix . 'assessor_property_types';
		$rows = $wpdb->get_results("SELECT id, code, name, status, sort_order FROM $table WHERE status IN ('active','disabled') ORDER BY sort_order ASC, name ASC", ARRAY_A);
		return array('items' => $rows);
	}

	public function save_property_type($request) {
		$params = $request->get_json_params();
		if (!$params) {
			$params = $request->get_params();
		}
		$code = isset($params['code']) ? sanitize_text_field($params['code']) : '';
		$name = isset($params['name']) ? sanitize_text_field($params['name']) : '';
		$sort_order = isset($params['sort_order']) ? intval($params['sort_order']) : 0;
		$status = isset($params['status']) ? sanitize_text_field($params['status']) : 'active';
		if (!in_array($status, array('active','disabled'), true)) {
			$status = 'active';
		}
		if ($code === '' || $name === '') {
			return new WP_Error('invalid_input', 'Code and name are required', array('status' => 400));
		}
		global $wpdb;
		$table = $wpdb->prefix . 'assessor_property_types';
		$existing_id = isset($params['id']) ? intval($params['id']) : 0;
		if ($existing_id > 0) {
			$wpdb->update($table, array('code' => $code, 'name' => $name, 'sort_order' => $sort_order, 'status' => $status), array('id' => $existing_id), array('%s','%s','%d','%s'), array('%d'));
		} else {
			$wpdb->insert($table, array('code' => $code, 'name' => $name, 'sort_order' => $sort_order, 'status' => $status), array('%s','%s','%d','%s'));
		}
		return $this->get_property_types();
	}

	public function delete_property_type($request) {
		$params = $request->get_json_params();
		if (!$params) {
			$params = $request->get_params();
		}
		$id = isset($params['id']) ? intval($params['id']) : 0;
		if ($id <= 0) {
			return new WP_Error('invalid_id', 'Invalid id', array('status' => 400));
		}
		global $wpdb;
		$table = $wpdb->prefix . 'assessor_property_types';
		$wpdb->delete($table, array('id' => $id), array('%d'));
		return array('success' => true);
	}

	public function get_general_classes() {
		global $wpdb;
		$table = $wpdb->prefix . 'assessor_general_classes';
		$rows = $wpdb->get_results("SELECT id, code, name, status, sort_order FROM $table WHERE status IN ('active','disabled') ORDER BY sort_order ASC, name ASC", ARRAY_A);
		return array('items' => $rows);
	}

	public function save_general_class($request) {
		$params = $request->get_json_params();
		if (!$params) {
			$params = $request->get_params();
		}
		$code = isset($params['code']) ? sanitize_text_field($params['code']) : '';
		$name = isset($params['name']) ? sanitize_text_field($params['name']) : '';
		$sort_order = isset($params['sort_order']) ? intval($params['sort_order']) : 0;
		$status = isset($params['status']) ? sanitize_text_field($params['status']) : 'active';
		if (!in_array($status, array('active','disabled'), true)) {
			$status = 'active';
		}
		if ($code === '' || $name === '') {
			return new WP_Error('invalid_input', 'Code and name are required', array('status' => 400));
		}
		global $wpdb;
		$table = $wpdb->prefix . 'assessor_general_classes';
		$existing_id = isset($params['id']) ? intval($params['id']) : 0;
		if ($existing_id > 0) {
			$wpdb->update($table, array('code' => $code, 'name' => $name, 'sort_order' => $sort_order, 'status' => $status), array('id' => $existing_id), array('%s','%s','%d','%s'), array('%d'));
		} else {
			$wpdb->insert($table, array('code' => $code, 'name' => $name, 'sort_order' => $sort_order, 'status' => $status), array('%s','%s','%d','%s'));
		}
		return $this->get_general_classes();
	}

	public function delete_general_class($request) {
		$params = $request->get_json_params();
		if (!$params) {
			$params = $request->get_params();
		}
		$id = isset($params['id']) ? intval($params['id']) : 0;
		if ($id <= 0) {
			return new WP_Error('invalid_id', 'Invalid id', array('status' => 400));
		}
		global $wpdb;
		$table = $wpdb->prefix . 'assessor_general_classes';
		$wpdb->delete($table, array('id' => $id), array('%d'));
		return array('success' => true);
	}

	public function get_locations() {
		global $wpdb;
		$table = $wpdb->prefix . 'assessor_locations';
		$rows = $wpdb->get_results("SELECT id, code, name, status, sort_order FROM $table WHERE status IN ('active','disabled') ORDER BY sort_order ASC, name ASC", ARRAY_A);
		return array('items' => $rows);
	}

	public function save_location($request) {
		$params = $request->get_json_params();
		if (!$params) {
			$params = $request->get_params();
		}
		$code = isset($params['code']) ? sanitize_text_field($params['code']) : '';
		$name = isset($params['name']) ? sanitize_text_field($params['name']) : '';
		$sort_order = isset($params['sort_order']) ? intval($params['sort_order']) : 0;
		$status = isset($params['status']) ? sanitize_text_field($params['status']) : 'active';
		if (!in_array($status, array('active','disabled'), true)) {
			$status = 'active';
		}
		if ($code === '' || $name === '') {
			return new WP_Error('invalid_input', 'Code and name are required', array('status' => 400));
		}
		global $wpdb;
		$table = $wpdb->prefix . 'assessor_locations';
		$existing_id = isset($params['id']) ? intval($params['id']) : 0;
		if ($existing_id > 0) {
			$wpdb->update($table, array('code' => $code, 'name' => $name, 'sort_order' => $sort_order, 'status' => $status), array('id' => $existing_id), array('%s','%s','%d','%s'), array('%d'));
		} else {
			$wpdb->insert($table, array('code' => $code, 'name' => $name, 'sort_order' => $sort_order, 'status' => $status), array('%s','%s','%d','%s'));
		}
		return $this->get_locations();
	}

	public function delete_location($request) {
		$params = $request->get_json_params();
		if (!$params) {
			$params = $request->get_params();
		}
		$id = isset($params['id']) ? intval($params['id']) : 0;
		if ($id <= 0) {
			return new WP_Error('invalid_id', 'Invalid id', array('status' => 400));
		}
		global $wpdb;
		$table = $wpdb->prefix . 'assessor_locations';
		$wpdb->delete($table, array('id' => $id), array('%d'));
		return array('success' => true);
	}

	// Revision entries functions
	public function get_revision_entries() {
		global $wpdb;
		$table = $wpdb->prefix . 'assessor_revision_entries';
		$entries = $wpdb->get_results(
			"SELECT * FROM $table WHERE status = 'active' ORDER BY sort_order ASC, from_year DESC",
			ARRAY_A
		);
		return array('items' => $entries);
	}

	public function save_revision_entry($request) {
		global $wpdb;
		$table = $wpdb->prefix . 'assessor_revision_entries';
		
		$params = $request->get_json_params();
		if (!$params) {
			$params = $request->get_params();
		}

		$revision_year = sanitize_text_field($params['revision_year'] ?? '');
		$from_year = sanitize_text_field($params['from_year'] ?? '');
		$to_year = sanitize_text_field($params['to_year'] ?? 'present');
		$status = sanitize_text_field($params['status'] ?? 'active');
		$sort_order = intval($params['sort_order'] ?? 0);
		$id = intval($params['id'] ?? 0);

		if (empty($revision_year) || empty($from_year)) {
			return new WP_Error('missing_fields', 'Revision year and from year are required', array('status' => 400));
		}

		$data = array(
			'revision_year' => $revision_year,
			'from_year' => $from_year,
			'to_year' => $to_year,
			'status' => $status,
			'sort_order' => $sort_order,
			'updated_at' => current_time('mysql')
		);

		if ($id > 0) {
			// Update existing entry
			$wpdb->update($table, $data, array('id' => $id), array('%s', '%s', '%s', '%s', '%d', '%s'), array('%d'));
		} else {
			// Insert new entry
			$data['created_at'] = current_time('mysql');
			$wpdb->insert($table, $data, array('%s', '%s', '%s', '%s', '%d', '%s', '%s'));
			$id = $wpdb->insert_id;
		}

		// Return updated list
		return $this->get_revision_entries();
	}

	public function delete_revision_entry($request) {
		global $wpdb;
		$table = $wpdb->prefix . 'assessor_revision_entries';
		
		$params = $request->get_json_params();
		if (!$params) {
			$params = $request->get_params();
		}

		$id = intval($params['id'] ?? 0);
		if ($id <= 0) {
			return new WP_Error('invalid_id', 'Valid ID is required', array('status' => 400));
		}

		$wpdb->delete($table, array('id' => $id), array('%d'));
		return array('success' => true);
	}

	// Request purposes (Purpose + Amount Paid)
	public function get_request_purposes() {
		global $wpdb;
		$table = $wpdb->prefix . 'assessor_request_purposes';
		$rows = $wpdb->get_results("SELECT id, purpose, amount, status, sort_order FROM $table WHERE status IN ('active','disabled') ORDER BY sort_order ASC, purpose ASC", ARRAY_A);
		return array('items' => $rows);
	}

	public function save_request_purpose($request) {
		$params = $request->get_json_params();
		if (!$params) {
			$params = $request->get_params();
		}

		$purpose = isset($params['purpose']) ? sanitize_text_field($params['purpose']) : '';
		// Normalize: store with underscores; convert spaces to underscores for consistency
		$purpose = trim(preg_replace('/\s+/', ' ', $purpose));
		$purpose = str_replace(' ', '_', $purpose);
		$amount = isset($params['amount']) ? $params['amount'] : 0;
		$status = isset($params['status']) ? sanitize_text_field($params['status']) : 'active';
		$sort_order = isset($params['sort_order']) ? intval($params['sort_order']) : 0;
		$id = isset($params['id']) ? intval($params['id']) : 0;

		if ($purpose === '') {
			return new WP_Error('invalid_input', 'Purpose is required', array('status' => 400));
		}
		if (!is_numeric($amount)) {
			return new WP_Error('invalid_input', 'Amount must be numeric', array('status' => 400));
		}
		$amount = round((float)$amount, 2);
		if ($amount < 0) {
			return new WP_Error('invalid_input', 'Amount cannot be negative', array('status' => 400));
		}
		if (!in_array($status, array('active','disabled'), true)) {
			$status = 'active';
		}

		global $wpdb;
		$table = $wpdb->prefix . 'assessor_request_purposes';

		// Prevent duplicates (case-insensitive)
		$existing = $wpdb->get_row($wpdb->prepare("SELECT id FROM $table WHERE LOWER(purpose) = LOWER(%s) LIMIT 1", $purpose), ARRAY_A);
		if ($existing && intval($existing['id']) !== $id) {
			return new WP_Error('duplicate_purpose', 'Purpose already exists', array('status' => 400, 'code' => 'duplicate_purpose'));
		}

		$data = array(
			'purpose' => $purpose,
			'amount' => $amount,
			'status' => $status,
			'sort_order' => $sort_order,
			'updated_at' => current_time('mysql')
		);

		if ($id > 0) {
			$wpdb->update($table, $data, array('id' => $id), array('%s','%f','%s','%d','%s'), array('%d'));
		} else {
			$data['created_at'] = current_time('mysql');
			$wpdb->insert($table, $data, array('%s','%f','%s','%d','%s','%s'));
		}

		return $this->get_request_purposes();
	}

	public function delete_request_purpose($request) {
		$params = $request->get_json_params();
		if (!$params) {
			$params = $request->get_params();
		}
		$id = isset($params['id']) ? intval($params['id']) : 0;
		if ($id <= 0) {
			return new WP_Error('invalid_id', 'Invalid id', array('status' => 400));
		}

		global $wpdb;
		$table = $wpdb->prefix . 'assessor_request_purposes';
		$wpdb->delete($table, array('id' => $id), array('%d'));
		return array('success' => true);
	}
}


