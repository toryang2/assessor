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
				'header_province' => 'Province of Bukidnon',
				'header_municipality' => 'MUNICIPALITY OF KITAOTAO',
				'header_office' => 'OFFICE OF THE MUNICIPAL ASSESSOR'
			);
		}
		return $settings;
	}

	public function save_settings($request) {
		$params = $request->get_json_params();
		if (!$params) {
			$params = $request->get_params();
		}

		$allowed_keys = array('app_logo_url','header_province','header_municipality','header_office');
		$data = array();
		foreach ($allowed_keys as $key) {
			if (isset($params[$key])) {
				$data[$key] = is_string($params[$key]) ? sanitize_text_field($params[$key]) : '';
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
		$old_url = get_option('assessor_app_logo_url', '');
		if (!empty($old_url) && $old_url !== $url) {
			$old_path = str_replace($this->upload_dir['baseurl'], $this->upload_dir['basedir'], $old_url);
			$old_path = wp_normalize_path($old_path);
			$safe_base = wp_normalize_path($base_dir);
			if (strpos($old_path, $safe_base) === 0 && file_exists($old_path)) {
				@unlink($old_path);
			}
		}

		// Persist to settings table
		global $wpdb;
		$table = $wpdb->prefix . 'assessor_settings';
		$existing_id = $wpdb->get_var("SELECT id FROM $table ORDER BY id DESC LIMIT 1");
		if ($existing_id) {
			$wpdb->update($table, array('app_logo_url' => esc_url_raw($url)), array('id' => $existing_id));
		} else {
			$wpdb->insert($table, array('app_logo_url' => esc_url_raw($url)));
		}

		return array('app_logo_url' => $url);
	}
}


