<?php

class Assessor_Versions {
    
    public function get_property_versions($property_id) {
        global $wpdb;
        
        $table_versions = $wpdb->prefix . 'assessor_property_versions';
        $table_users = $wpdb->prefix . 'assessor_users';
        
        $query = "
            SELECT v.*, u.full_name as created_by_name
            FROM $table_versions v
            LEFT JOIN $table_users u ON v.created_by = u.id
            WHERE v.property_id = %d
            ORDER BY v.version_number DESC
        ";
        
        $versions = $wpdb->get_results($wpdb->prepare($query, $property_id));
        
        return array('versions' => $versions);
    }
    
    public function get_version($version_id) {
        global $wpdb;
        
        $table_versions = $wpdb->prefix . 'assessor_property_versions';
        $table_users = $wpdb->prefix . 'assessor_users';
        
        $query = "
            SELECT v.*, u.full_name as created_by_name
            FROM $table_versions v
            LEFT JOIN $table_users u ON v.created_by = u.id
            WHERE v.id = %d
        ";
        
        $version = $wpdb->get_row($wpdb->prepare($query, $version_id));
        
        if (!$version) {
            return new WP_Error('version_not_found', 'Version not found', array('status' => 404));
        }
        
        return $version;
    }
    
    public function restore_version($property_id, $version_id) {
        global $wpdb;
        
        // Get version data
        $version = $this->get_version($version_id);
        if (is_wp_error($version)) {
            return $version;
        }
        
        // Check if version belongs to the property
        if ($version->property_id != $property_id) {
            return new WP_Error('invalid_version', 'Version does not belong to this property', array('status' => 400));
        }
        
        // Get current property data
        $properties = new Assessor_Properties();
        $current_property = $properties->get_property($property_id);
        if (is_wp_error($current_property)) {
            return $current_property;
        }
        
        // Create new version with current data
        $this->create_property_version($property_id, $current_property, 'Restored from version ' . $version->version_number);
        
        // Update property with version data
        $table_properties = $wpdb->prefix . 'assessor_properties';
        $result = $wpdb->update(
            $table_properties,
            array(
                'owner_name' => $version->owner_name,
                'owner_address' => $version->owner_address,
                'property_location' => $version->property_location,
                'property_type' => $version->property_type,
                'land_area' => $version->land_area,
                'building_area' => $version->building_area,
                'assessed_value' => $version->assessed_value,
                'market_value' => $version->market_value,
            ),
            array('id' => $property_id),
            array('%s', '%s', '%s', '%s', '%f', '%f', '%f', '%f'),
            array('%d')
        );
        
        if ($result === false) {
            return new WP_Error('restore_failed', 'Failed to restore version', array('status' => 500));
        }
        
        return array('success' => true, 'message' => 'Version restored successfully');
    }
    
    private function create_property_version($property_id, $property_data, $change_reason) {
        global $wpdb;
        
        $table_versions = $wpdb->prefix . 'assessor_property_versions';
        
        // Get next version number
        $current_version = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(version_number) FROM $table_versions WHERE property_id = %d",
            $property_id
        ));
        $next_version = ($current_version ? $current_version + 1 : 1);
        
        $wpdb->insert(
            $table_versions,
            array(
                'property_id' => $property_id,
                'version_number' => $next_version,
                'tax_declaration_number' => $property_data->tax_declaration_number,
                'previous_tax_declaration_number' => $property_data->previous_tax_declaration_number,
                'declarant_last_name' => $property_data->declarant_last_name,
                'declarant_first_name' => $property_data->declarant_first_name,
                'declarant_middle_initial' => $property_data->declarant_middle_initial,
                'location' => $property_data->location,
                'lot_number' => $property_data->lot_number,
                'unique_lot_number_identified' => $property_data->unique_lot_number_identified,
                'survey_number' => isset($property_data->survey_number) ? $property_data->survey_number : $property_data->unique_lot_number_identified,
                'area_hectare' => $property_data->area_hectare,
                'title_number' => $property_data->title_number,
                'assessed_value' => $property_data->assessed_value,
                'assessed_value_old' => isset($property_data->assessed_value_old) ? $property_data->assessed_value_old : '',
                'effectivity_date' => $property_data->effectivity_date,
                'pin' => $property_data->pin,
                'address' => $property_data->address,
                'assessment_date' => $property_data->assessment_date,
                'kind_of_property' => $property_data->kind_of_property,
                'gen_class' => $property_data->gen_class,
                'memoranda' => $property_data->memoranda,
                'supporting_documents' => $property_data->supporting_documents,
                'supporting_documents_old' => isset($property_data->supporting_documents_old) ? $property_data->supporting_documents_old : '',
                'change_reason' => $change_reason,
                'created_by' => $property_data->updated_by
            ),
            array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d')
        );
    }
}

