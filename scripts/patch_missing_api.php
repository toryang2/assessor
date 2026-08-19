<?php
// Fix for class-assessor-etracs.php

$etracs_file = 'd:/CODE/assessor/assessor-backend/wp-content/plugins/assessor-api/includes/class-assessor-etracs.php';
$etracs_code = file_get_contents($etracs_file);

if (strpos($etracs_code, 'get_building_lookups') === false) {
    $add_etracs = <<<EOD

    public function get_barangays() {
        global \$wpdb;
        \$table = \$wpdb->prefix . 'assessor_barangay';
        return \$wpdb->get_results("SELECT objid, name FROM \$table ORDER BY name ASC");
    }

    public function get_exemption_types() {
        global \$wpdb;
        \$table = \$wpdb->prefix . 'assessor_exemptiontype';
        return \$wpdb->get_results("SELECT objid, name FROM \$table ORDER BY name ASC");
    }

    public function get_classifications() {
        global \$wpdb;
        \$table = \$wpdb->prefix . 'assessor_propertyclassification';
        return \$wpdb->get_results("SELECT objid, name FROM \$table ORDER BY name ASC");
    }

    public function get_faas_signatory(\$id) {
        global \$wpdb;
        \$table = \$wpdb->prefix . 'assessor_faas_signatory';
        \$record = \$wpdb->get_row(\$wpdb->prepare("SELECT * FROM \$table WHERE objid = %s", \$id));
        if (!\$record) {
            return array();
        }
        return \$record;
    }

    public function get_building_lookups() {
        global \$wpdb;
        \$results = array();
        
        \$t_kind = \$wpdb->prefix . 'assessor_bldgkind';
        \$t_type = \$wpdb->prefix . 'assessor_bldgtype';
        \$t_use = \$wpdb->prefix . 'assessor_bldguse';
        \$t_material = \$wpdb->prefix . 'assessor_material';
        
        \$results['kinds'] = \$wpdb->get_results("SELECT objid, name FROM \$t_kind ORDER BY name ASC");
        \$results['types'] = \$wpdb->get_results("SELECT objid, name FROM \$t_type ORDER BY name ASC");
        \$results['uses'] = \$wpdb->get_results("SELECT objid, name FROM \$t_use ORDER BY name ASC");
        \$results['materials'] = \$wpdb->get_results("SELECT objid, name FROM \$t_material ORDER BY name ASC");
        
        return \$results;
    }

    public function get_rpu_detail(\$request) {
        global \$wpdb;
        \$id = \$request['id'];

        \$t_rpu = \$wpdb->prefix . 'assessor_rpu';
        
        \$rpu = \$wpdb->get_row(\$wpdb->prepare("SELECT * FROM \$t_rpu WHERE objid = %s", \$id));
        if (!\$rpu) {
            return new WP_Error('not_found', 'RPU not found', array('status' => 404));
        }

        \$assessments = \$wpdb->get_results(\$wpdb->prepare("
            SELECT 
                objid AS id,
                classcode AS classification,
                areaha AS area,
                areasqm AS area_sqm,
                marketvalue AS market_value,
                actualuse AS actual_use,
                assesslevel AS assessment_level,
                assessedvalue AS assessed_value
            FROM {\$wpdb->prefix}assessor_rpu_assessment
            WHERE rpuid = %s
        ", \$id));

        \$rpu->assessments = \$assessments ? \$assessments : [];

        return array(
            'data' => \$rpu
        );
    }
EOD;

    // insert before last }
    $etracs_code = preg_replace('/}([\s\n]*)$/', $add_etracs . "\n}\$1", $etracs_code);
    file_put_contents($etracs_file, $etracs_code);
    echo "Added methods to class-assessor-etracs.php\n";
}

// Fix for class-assessor-api.php
$api_file = 'd:/CODE/assessor/assessor-backend/wp-content/plugins/assessor-api/includes/class-assessor-api.php';
$api_code = file_get_contents($api_file);

if (strpos($api_code, 'etracs_get_building_lookups') === false) {
    // Add routes inside register_routes()
    $add_routes = <<<EOD
        // Lookups
        register_rest_route('assessor/v1', '/etracs/barangay', array(
            'methods'  => 'GET',
            'callback' => array(\$this, 'etracs_get_barangays'),
            'permission_callback' => array(\$this, 'check_admin'),
        ));
        register_rest_route('assessor/v1', '/etracs/exemption-types', array(
            'methods'  => 'GET',
            'callback' => array(\$this, 'etracs_get_exemption_types'),
            'permission_callback' => array(\$this, 'check_admin'),
        ));
        register_rest_route('assessor/v1', '/etracs/classifications', array(
            'methods'  => 'GET',
            'callback' => array(\$this, 'etracs_get_classifications'),
            'permission_callback' => array(\$this, 'check_admin'),
        ));
        register_rest_route('assessor/v1', '/etracs/faas/(?P<id>[a-zA-Z0-9\-\:]+)/signatory', array(
            'methods'  => 'GET',
            'callback' => array(\$this, 'etracs_get_faas_signatory'),
            'permission_callback' => array(\$this, 'check_admin'),
        ));
        register_rest_route('assessor/v1', '/etracs/building/lookups', array(
            'methods'  => 'GET',
            'callback' => array(\$this, 'etracs_get_building_lookups'),
            'permission_callback' => array(\$this, 'check_admin'),
        ));

        // RPU
        register_rest_route('assessor/v1', '/etracs/rpu/(?P<id>[a-zA-Z0-9\-\:]+)/detail', array(
            'methods'  => 'GET',
            'callback' => array(\$this, 'etracs_get_rpu_detail'),
            'permission_callback' => array(\$this, 'check_admin'),
        ));
EOD;

    // We'll insert it right before check_auth
    $api_code = str_replace('public function check_auth($request) {', $add_routes . "\n    }\n    \n    public function check_auth(\$request) {", $api_code);

    $add_wrappers = <<<EOD

    public function etracs_get_barangays(\$request) {
        \$etracs = new Assessor_Etracs();
        return rest_ensure_response(\$etracs->get_barangays());
    }

    public function etracs_get_exemption_types(\$request) {
        \$etracs = new Assessor_Etracs();
        return rest_ensure_response(\$etracs->get_exemption_types());
    }

    public function etracs_get_classifications(\$request) {
        \$etracs = new Assessor_Etracs();
        return rest_ensure_response(\$etracs->get_classifications());
    }

    public function etracs_get_faas_signatory(\$request) {
        \$etracs = new Assessor_Etracs();
        \$result = \$etracs->get_faas_signatory(\$request['id']);
        if (is_wp_error(\$result)) return \$result;
        return rest_ensure_response(\$result);
    }

    public function etracs_get_building_lookups(\$request) {
        \$etracs = new Assessor_Etracs();
        return rest_ensure_response(\$etracs->get_building_lookups());
    }

    public function etracs_get_rpu_detail(\$request) {
        \$etracs = new Assessor_Etracs();
        \$result = \$etracs->get_rpu_detail(\$request);
        if (is_wp_error(\$result)) return \$result;
        return rest_ensure_response(\$result);
    }
EOD;

    // We'll insert wrappers right before etracs_pull_sync
    $api_code = str_replace('public function etracs_pull_sync($request) {', $add_wrappers . "\n\n    public function etracs_pull_sync(\$request) {", $api_code);
    file_put_contents($api_file, $api_code);
    echo "Added wrappers and routes to class-assessor-api.php\n";
}

echo "Done.\n";
