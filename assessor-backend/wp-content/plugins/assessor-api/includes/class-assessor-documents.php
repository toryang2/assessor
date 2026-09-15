<?php

class Assessor_Documents {
    
    private $upload_dir;
    private $allowed_types = array('pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif');
    private $max_file_size = 10485760; // 10MB
    
    public function __construct() {
        $this->upload_dir = wp_upload_dir();
    }
    
    public function get_property_documents($property_id) {
        global $wpdb;
        
        $table_documents = $wpdb->prefix . 'assessor_documents';
        $table_users = $wpdb->prefix . 'assessor_users';
        
        $query = "
            SELECT d.*, u.full_name as uploaded_by_name
            FROM $table_documents d
            LEFT JOIN $table_users u ON d.uploaded_by = u.id
            WHERE d.property_id = %s
            ORDER BY d.uploaded_at DESC
        ";
        
        $documents = $wpdb->get_results($wpdb->prepare($query, $property_id));
        
        // Add public URL for each document based on stored file path (backward/forward compatible)
        if (is_array($documents)) {
            foreach ($documents as $doc) {
                $doc->file_url = $this->path_to_url($doc->file_path);
            }
        }
        
        return array('documents' => $documents);
    }
    
    public function upload_document($request) {
        global $wpdb;
        
        $params = $request->get_params();
        $property_id = sanitize_text_field($params['property_id']);
        $description = sanitize_textarea_field($params['description'] ?? '');
        
        // Check if property exists
        $properties = new Assessor_Properties();
        $property = $properties->get_property($property_id);
        if (is_wp_error($property)) {
            return $property;
        }
        
        // Check if file was uploaded
        if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
            return new WP_Error('upload_error', 'No file uploaded or upload error occurred', array('status' => 400));
        }
        
        $file = $_FILES['document'];
        
        // Validate file
        $validation = $this->validate_file($file);
        if (is_wp_error($validation)) {
            return $validation;
        }
        
        // Create upload directory named after Tax Declaration Number
        $upload_path = $this->create_upload_directory($property);
        if (is_wp_error($upload_path)) {
            return $upload_path;
        }
        
        // Generate unique filename
        $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $unique_filename = uniqid() . '_' . time() . '.' . $file_extension;
        $file_path = $upload_path . '/' . $unique_filename;
        
        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $file_path)) {
            return new WP_Error('move_failed', 'Failed to move uploaded file', array('status' => 500));
        }
        
        // Get user ID from request
        $auth = new Assessor_Auth();
        $user_id = $auth->get_user_id_from_token($request);
        
        // Save document record
        $table_documents = $wpdb->prefix . 'assessor_documents';
        $result = $wpdb->insert(
            $table_documents,
            array(
                'property_id' => $property_id,
                'filename' => $unique_filename,
                'original_filename' => sanitize_file_name($file['name']),
                'file_path' => $file_path,
                'file_type' => $file_extension,
                'file_size' => $file['size'],
                'description' => $description,
                'uploaded_by' => $user_id
            ),
            array('%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
        );
        
        if ($result === false) {
            // Remove uploaded file if database insert failed
            unlink($file_path);
            return new WP_Error('insert_failed', 'Failed to save document record', array('status' => 500));
        }
        
        $document_id = $wpdb->insert_id;
        
        // Log audit trail with rich context (treat as generic 'upload' for UI)
        $propDetails = null;
        try {
            $propertiesSvc = new Assessor_Properties();
            $propDetails = $propertiesSvc->get_property($property_id);
        } catch (\Exception $e) {}
        $new_values = array(
            'property_id' => $property_id,
            'tax_declaration_number' => ($propDetails && isset($propDetails->tax_declaration_number)) ? $propDetails->tax_declaration_number : null,
            'filename' => $unique_filename,
            'original_filename' => $file['name'],
            'file_type' => $file_extension,
            'file_size' => number_format($file['size'] / 1048576, 2) . ' MB',
            'description' => $description
        );
        $audit = new Assessor_Audit();
        $audit->log_activity($user_id, 'upload', 'assessor_documents', $document_id, null, $new_values);
        
        return $this->get_document($document_id);
    }
    
    public function delete_document($document_id) {
        global $wpdb;
        
        $table_documents = $wpdb->prefix . 'assessor_documents';
        
        // Get document info
        $document = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_documents WHERE id = %d",
            $document_id
        ));
        
        if (!$document) {
            return new WP_Error('document_not_found', 'Document not found', array('status' => 404));
        }
        
        // Delete file from filesystem
        if (!empty($document->file_path) && file_exists($document->file_path)) {
            @unlink($document->file_path);
        }
        
        // Delete database record
        $result = $wpdb->delete(
            $table_documents,
            array('id' => $document_id),
            array('%d')
        );
        
        if ($result === false) {
            return new WP_Error('delete_failed', 'Failed to delete document record', array('status' => 500));
        }
        
        // Log audit trail for document deletion
        $auth = new Assessor_Auth();
        // Safely accept original request object when provided to extract user for auditing
        $request_obj = func_num_args() > 1 ? func_get_arg(1) : null;
        $user_id = $auth->get_user_id_from_token($request_obj);
        // Enrich audit with property info similar to upload path
        $propDetails = null;
        try {
            $propertiesSvc = new Assessor_Properties();
            $propDetails = $propertiesSvc->get_property($document->property_id);
        } catch (\Exception $e) {}
        $old_values = array(
            'property_id' => $document->property_id,
            'tax_declaration_number' => ($propDetails && isset($propDetails->tax_declaration_number)) ? $propDetails->tax_declaration_number : null,
            'filename' => $document->filename,
            'original_filename' => $document->original_filename,
            'file_type' => $document->file_type,
            'file_size' => number_format(((int)$document->file_size) / 1048576, 2) . ' MB',
            'description' => $document->description
        );
        $audit = new Assessor_Audit();
        $audit->log_activity($user_id ?: 0, 'delete', 'assessor_documents', $document_id, $old_values, null);
        
        return array('success' => true, 'message' => 'Document deleted successfully');
    }
    
    public function get_document($document_id) {
        global $wpdb;
        
        $table_documents = $wpdb->prefix . 'assessor_documents';
        $table_users = $wpdb->prefix . 'assessor_users';
        
        $query = "
            SELECT d.*, u.full_name as uploaded_by_name
            FROM $table_documents d
            LEFT JOIN $table_users u ON d.uploaded_by = u.id
            WHERE d.id = %d
        ";
        
        $document = $wpdb->get_row($wpdb->prepare($query, $document_id));
        
        if (!$document) {
            return new WP_Error('document_not_found', 'Document not found', array('status' => 404));
        }
        
        // Add public URL for client consumption
        $document->file_url = $this->path_to_url($document->file_path);
        
        return $document;
    }
    
    private function validate_file($file) {
        // Check file size
        if ($file['size'] > $this->max_file_size) {
            return new WP_Error('file_too_large', 'File size exceeds maximum allowed size', array('status' => 400));
        }
        
        // Check file type
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($file_extension, $this->allowed_types)) {
            return new WP_Error('invalid_file_type', 'File type not allowed', array('status' => 400));
        }
        
        // Check for malicious files
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        $allowed_mimes = array(
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif'
        );
        
        if (!isset($allowed_mimes[$file_extension]) || $allowed_mimes[$file_extension] !== $mime_type) {
            return new WP_Error('invalid_mime_type', 'File MIME type does not match extension', array('status' => 400));
        }
        
        return true;
    }
    
    private function create_upload_directory($property) {
        $base_dir = $this->upload_dir['basedir'] . '/assessor-documents';
        // Use TDN and Property UUID to namespace folder cleanly
        $tdn_raw = isset($property->tax_declaration_number) ? $property->tax_declaration_number : '';
        $tdn_safe = preg_replace('/[^A-Za-z0-9_.\-]/', '_', $tdn_raw);
        $prop_id_safe = isset($property->id) ? preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)$property->id) : '';
        if (!empty($tdn_safe) && !empty($prop_id_safe)) {
            $folder_name = $tdn_safe . '_' . $prop_id_safe;
        } elseif (!empty($tdn_safe)) {
            $folder_name = $tdn_safe;
        } elseif (!empty($prop_id_safe)) {
            $folder_name = $prop_id_safe;
        } else {
            $folder_name = 'unknown';
        }
        $property_dir = $base_dir . '/' . $folder_name;
        
        // Create base directory if it doesn't exist
        if (!file_exists($base_dir)) {
            if (!wp_mkdir_p($base_dir)) {
                return new WP_Error('dir_creation_failed', 'Failed to create base upload directory', array('status' => 500));
            }
        }
        
        // Create property-specific directory
        if (!file_exists($property_dir)) {
            if (!wp_mkdir_p($property_dir)) {
                return new WP_Error('dir_creation_failed', 'Failed to create property upload directory', array('status' => 500));
            }
        }
        
        return $property_dir;
    }

    private function path_to_url($absolute_path) {
        // Convert absolute path under uploads dir to a public URL
        $basedir = rtrim($this->upload_dir['basedir'], '/');
        $baseurl = rtrim($this->upload_dir['baseurl'], '/');
        if (strpos($absolute_path, $basedir) === 0) {
            $relative = substr($absolute_path, strlen($basedir));
            return $baseurl . $relative;
        }
        // If stored path is not under uploads (unexpected), return as-is
        return $absolute_path;
    }
}

