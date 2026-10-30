<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Assessor ETRACS Native Importer
 *
 * Implements:
 * - Dynamic Candidate Identification & Metrics computation
 * - Read-only Location Crosswalk Audit with persistent approval tracking
 * - Side-by-side Source and Target Preview with server-side pagination & filtering
 * - Transactional, idempotent Batch Importing into native property tables with UUID v7,
 *   lifecycle states, and dedicated provenance sidecar (wp_assessor_etracs_import_map)
 * - Assessor_Sync::enqueue_property post-commit queueing
 */
class Assessor_Etracs_Native_Importer {

    /**
     * Approved General Class mappings: ETRACS code -> Native general class code
     */
    const CLASS_MAPPING = array(
        'AGR'  => 'AGRICULTURAL',
        'COM'  => 'COMMERCIAL',
        'IND'  => 'INDUSTRIAL',
        'MIN'  => 'MINERAL',
        'RES'  => 'RESIDENTIAL',
        'SPC'  => 'SPECIAL',
        'IMP'  => 'IMPROVEMENTS',
        'CL'   => 'CL',
        'EDU'  => 'EDU',
        'GOCC' => 'GOCC',
        'GOV'  => 'GOV',
        'REL'  => 'REL',
        'RROW' => 'RROW',
        'T'    => 'TIMBERLAND_FORESTAL',
        'TIM'  => 'TIMBERLAND_FORESTAL',
    );

    /**
     * Approved Property Type mappings: ETRACS type -> Native property type code
     */
    const TYPE_MAPPING = array(
        'LAND'      => 'LAND',
        'BLDG'      => 'BUILDING',
        'MACH'      => 'MACHINERY',
        'PLANTTREE' => 'PLANT_TREES',
        'MISC'      => 'IMPROVEMENTS',
    );

    /**
     * Normalize name for comparison only.
     */
    public static function normalize_name($name) {
        if (!$name) return '';
        $n = strtoupper(trim($name));
        $n = str_replace(array('BARANGAY', 'BRGY.', 'BRGY', '/', '-', '.', ','), ' ', $n);
        $n = preg_replace('/\s+/', ' ', $n);
        return trim($n);
    }

    /**
     * Retrieve canonical assessor and verifier signatory settings.
     */
    public static function get_canonical_assessor_settings() {
        if (class_exists('Assessor_Settings')) {
            $settings_obj = new Assessor_Settings();
            $s = $settings_obj->get_settings();
        } else {
            global $wpdb;
            $table = $wpdb->prefix . 'assessor_settings';
            $s = $wpdb->get_row("SELECT * FROM $table ORDER BY id DESC LIMIT 1", ARRAY_A);
        }

        return array(
            'verifier_signatory_name'   => trim((string)($s['verifier_signatory_name'] ?? '')),
            'verifier_signatory_title'  => trim((string)($s['verifier_signatory_title'] ?? '')),
            'municipal_assessor_name'   => trim((string)($s['municipal_assessor_name'] ?? '')),
            'municipal_assessor_suffix' => trim((string)($s['municipal_assessor_suffix'] ?? '')),
            'municipal_assessor_title'  => trim((string)($s['municipal_assessor_title'] ?? '')),
            'municipal_assessor_license'=> trim((string)($s['municipal_assessor_license'] ?? '')),
        );
    }

    /**
     * Cache and retrieve all active revision entries.
     */
    public static function get_active_revisions() {
        global $wpdb;
        static $revisions_cache = null;
        if ($revisions_cache !== null) {
            return $revisions_cache;
        }

        $table_revisions = $wpdb->prefix . 'assessor_revision_entries';
        $rows = $wpdb->get_results("
            SELECT id, revision_year, revision_code, from_year, to_year, status 
            FROM $table_revisions 
            WHERE status = 'active'
            ORDER BY CAST(from_year AS UNSIGNED) ASC
        ", ARRAY_A);

        $revisions_cache = $rows ?: array();
        return $revisions_cache;
    }

    /**
     * Deterministically resolve the active native revision record by effectivity year or ry.
     *
     * @param int|string|null $year
     * @return array Array containing resolved_id, revision_year, match_rule, status ('MATCHED'|'UNRESOLVED')
     */
    public static function resolve_revision_record($year) {
        if ($year === null || $year === '') {
            return array(
                'status'        => 'UNRESOLVED',
                'resolved_id'   => null,
                'revision_year' => null,
                'revision_code' => null,
                'match_rule'    => 'NO_YEAR_PROVIDED',
                'explanation'   => 'Effectivity year is missing or blank; unable to determine applicable revision.'
            );
        }

        $y_str = trim((string)$year);
        if (!preg_match('/^\d{4}$/', $y_str)) {
            return array(
                'status'        => 'UNRESOLVED',
                'resolved_id'   => null,
                'revision_year' => null,
                'revision_code' => null,
                'match_rule'    => 'MALFORMED_YEAR',
                'explanation'   => "Source year '$y_str' is not a valid 4-digit year."
            );
        }

        $y_int = intval($y_str);
        if ($y_int < 1800 || $y_int > 2100) {
            return array(
                'status'        => 'UNRESOLVED',
                'resolved_id'   => null,
                'revision_year' => null,
                'revision_code' => null,
                'match_rule'    => 'OUT_OF_RANGE',
                'explanation'   => "Year $y_int is out of valid range (1800-2100)."
            );
        }

        $revisions = self::get_active_revisions();
        $matches = array();

        foreach ($revisions as $rev) {
            $from = intval($rev['from_year']);
            $to = (strtolower(trim($rev['to_year'])) === 'present') ? 9999 : intval($rev['to_year']);
            if ($y_int >= $from && $y_int <= $to) {
                $matches[] = $rev;
            }
        }

        if (count($matches) === 0) {
            return array(
                'status'        => 'UNRESOLVED',
                'resolved_id'   => null,
                'revision_year' => null,
                'revision_code' => null,
                'match_rule'    => 'NO_MATCHING_REVISION',
                'explanation'   => "No active revision cycle covers effectivity year $y_int."
            );
        }

        if (count($matches) > 1) {
            return array(
                'status'        => 'UNRESOLVED',
                'resolved_id'   => null,
                'revision_year' => null,
                'revision_code' => null,
                'match_rule'    => 'MULTIPLE_MATCHING_REVISIONS',
                'explanation'   => "Ambiguous: multiple active revisions match year $y_int."
            );
        }

        $matched = $matches[0];
        return array(
            'status'        => 'MATCHED',
            'resolved_id'   => $matched['id'],
            'revision_year' => $matched['revision_year'],
            'revision_code' => $matched['revision_code'],
            'match_rule'    => "Matched active revision '{$matched['revision_year']}' ({$matched['from_year']}-{$matched['to_year']})",
            'explanation'   => "Resolved revision record ID '{$matched['id']}' based on effectivity year $y_int."
        );
    }

    /**
     * Normalize TD number for canonical comparison and indexing.
     */
    public static function canonical_td_key($tdno) {
        if (!$tdno) return '';
        return strtolower(trim($tdno));
    }

    /**
     * Get all active native locations indexed by numeric PIN and code.
     */
    public static function get_native_locations_cache() {
        global $wpdb;
        $table = $wpdb->prefix . 'assessor_locations';
        $rows = $wpdb->get_results("SELECT id, code, name, pin, status, deleted_at FROM $table WHERE deleted_at IS NULL", ARRAY_A);
        
        $by_pin = array();
        $by_id = array();
        foreach ($rows as $r) {
            $by_id[$r['id']] = $r;
            $pin_int = intval($r['pin']);
            if ($pin_int > 0) {
                if (!isset($by_pin[$pin_int])) {
                    $by_pin[$pin_int] = array();
                }
                $by_pin[$pin_int][] = $r;
            }
        }
        return array('all' => $rows, 'by_pin' => $by_pin, 'by_id' => $by_id);
    }

    /**
     * Get all persistent crosswalk approvals.
     */
    public static function get_crosswalk_approvals() {
        global $wpdb;
        $table = $wpdb->prefix . 'assessor_etracs_crosswalk_approvals';
        if (!$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table))) {
            return array();
        }
        $rows = $wpdb->get_results("SELECT * FROM $table", ARRAY_A);
        $indexed = array();
        foreach ($rows as $r) {
            $indexed[$r['source_barangay_objid']] = $r;
        }
        return $indexed;
    }

    /**
     * Audit single location relationship.
     */
    public static function evaluate_location_match($source_barangay, $locations_cache, $approvals) {
        $src_objid = $source_barangay['objid'] ?? '';
        $src_name = $source_barangay['name'] ?? '';
        $src_pin = $source_barangay['pin'] ?? '';

        if (!$src_objid || !$src_pin) {
            return array(
                'status' => 'INVALID_SOURCE_REFERENCE',
                'native_location' => null,
                'explanation' => 'Source barangay record is missing an identifier or PIN code.',
                'can_import' => false,
                'is_approved' => false
            );
        }

        // Check if an explicit approval already exists
        $approval = $approvals[$src_objid] ?? null;
        $approved_native = null;
        if ($approval && !empty($approval['native_location_id'])) {
            $approved_native = $locations_cache['by_id'][$approval['native_location_id']] ?? null;
        }

        // Extract numeric suffix from source PIN (e.g. 059-10-0024 -> 24)
        $parts = explode('-', $src_pin);
        $suffix = intval(end($parts));

        $matches = $locations_cache['by_pin'][$suffix] ?? array();

        if (empty($matches)) {
            if ($approved_native && $approved_native['status'] === 'active') {
                return array(
                    'status' => 'MATCH_CONFIRMED_BY_APPROVAL',
                    'native_location' => $approved_native,
                    'explanation' => 'Approved via persistent crosswalk resolution.',
                    'can_import' => true,
                    'is_approved' => true,
                    'approval' => $approval
                );
            }
            return array(
                'status' => 'MISSING_NATIVE_LOCATION',
                'native_location' => null,
                'explanation' => "No native location found with numeric PIN $suffix.",
                'can_import' => false,
                'is_approved' => false
            );
        }

        if (count($matches) > 1) {
            if ($approved_native && $approved_native['status'] === 'active') {
                return array(
                    'status' => 'MATCH_CONFIRMED_BY_APPROVAL',
                    'native_location' => $approved_native,
                    'explanation' => 'Ambiguity resolved via persistent crosswalk approval.',
                    'can_import' => true,
                    'is_approved' => true,
                    'approval' => $approval
                );
            }
            return array(
                'status' => 'AMBIGUOUS_NATIVE_LOCATION',
                'native_location' => null,
                'explanation' => "Multiple native locations share PIN $suffix.",
                'can_import' => false,
                'is_approved' => false
            );
        }

        $target_native = $matches[0];
        if ($target_native['status'] !== 'active') {
            return array(
                'status' => 'INACTIVE_NATIVE_LOCATION',
                'native_location' => $target_native,
                'explanation' => "Matching native location '{$target_native['name']}' is disabled/inactive.",
                'can_import' => false,
                'is_approved' => false
            );
        }

        $src_norm = self::normalize_name($src_name);
        $nat_norm = self::normalize_name($target_native['name']);

        if ($src_name === $target_native['name']) {
            return array(
                'status' => 'MATCH_CONFIRMED',
                'native_location' => $target_native,
                'explanation' => 'Exact PIN suffix and name match.',
                'can_import' => true,
                'is_approved' => false
            );
        } elseif ($src_norm === $nat_norm) {
            return array(
                'status' => 'MATCH_CONFIRMED_NAME_VARIATION',
                'native_location' => $target_native,
                'explanation' => 'Exact PIN suffix match with normalized name variation.',
                'can_import' => true,
                'is_approved' => false
            );
        } else {
            // Material name discrepancy (e.g. LOREGA vs LOREGA / NAPALICO)
            if ($approved_native && $approved_native['id'] === $target_native['id']) {
                return array(
                    'status' => 'MATCH_APPROVED_NAME_MISMATCH',
                    'native_location' => $target_native,
                    'explanation' => 'Name discrepancy explicitly reviewed and approved by administrator.',
                    'can_import' => true,
                    'is_approved' => true,
                    'approval' => $approval
                );
            }
            return array(
                'status' => 'REVIEW_NAME_MISMATCH',
                'native_location' => $target_native,
                'explanation' => "PIN matches ($suffix), but ETRACS name ('$src_name') differs from native name ('{$target_native['name']}'). Requires review.",
                'can_import' => false,
                'is_approved' => false
            );
        }
    }

    /**
     * Compute full location crosswalk audit.
     */
    public static function get_location_crosswalk_audit() {
        global $wpdb;
        $table_barangay = $wpdb->prefix . 'assessor_barangay';
        $table_faas = $wpdb->prefix . 'assessor_faas';
        $table_properties = $wpdb->prefix . 'assessor_properties';
        $table_rp = $wpdb->prefix . 'assessor_real_property';

        $locations_cache = self::get_native_locations_cache();
        $approvals = self::get_crosswalk_approvals();

        // Count candidate properties per barangay
        $candidate_counts_raw = $wpdb->get_results("
            SELECT rp.barangayid, COUNT(*) as cnt
            FROM $table_faas f
            LEFT JOIN $table_properties p ON LOWER(TRIM(f.tdno)) = LOWER(TRIM(p.tax_declaration_number))
            LEFT JOIN $table_rp rp ON f.realpropertyid = rp.objid
            WHERE f.origin_type = 'REPLICA' 
              AND f.state IN ('CURRENT', 'CANCELLED')
              AND TRIM(COALESCE(f.tdno, '')) != ''
              AND p.id IS NULL
            GROUP BY rp.barangayid
        ", ARRAY_A);

        $candidate_counts = array();
        foreach ($candidate_counts_raw as $c) {
            $candidate_counts[$c['barangayid']] = (int)$c['cnt'];
        }

        // Fetch all source barangays
        $source_barangays = $wpdb->get_results("SELECT objid, indexno, pin, name, fullname FROM $table_barangay ORDER BY pin, name", ARRAY_A);

        $audit_results = array();
        foreach ($source_barangays as $sb) {
            $eval = self::evaluate_location_match($sb, $locations_cache, $approvals);
            $pin_parts = explode('-', $sb['pin']);
            $suffix = intval(end($pin_parts));
            $cnt = $candidate_counts[$sb['objid']] ?? 0;

            $nat = $eval['native_location'];
            $audit_results[] = array(
                'source_barangay_objid' => $sb['objid'],
                'source_barangay_name'  => $sb['name'],
                'source_barangay_fullname' => $sb['fullname'] ?? '',
                'source_barangay_pin'   => $sb['pin'],
                'source_pin_suffix'     => $suffix,
                'affected_candidates'   => $cnt,
                'native_location_id'    => $nat['id'] ?? null,
                'native_location_code'  => $nat['code'] ?? null,
                'native_location_name'  => $nat['name'] ?? null,
                'native_location_pin'   => $nat['pin'] ?? null,
                'native_is_active'      => ($nat && $nat['status'] === 'active'),
                'match_status'          => $eval['status'],
                'can_import'            => $eval['can_import'],
                'is_approved'           => $eval['is_approved'],
                'explanation'           => $eval['explanation'],
                'approval'              => $eval['approval'] ?? null
            );
        }

        return $audit_results;
    }

    /**
     * Map a single candidate record from source joined fields to proposed native property payload.
     */
    public static function map_candidate_record($source_record, $location_eval) {
        $warnings = array();
        $warning_codes = array();
        $blocking_reasons = array();

        // 1. TD number
        $tdno = trim($source_record['tdno'] ?? '');
        if ($tdno === '') {
            $blocking_reasons[] = 'Missing source tax declaration number.';
        }

        // 2. Owner name
        $owner_name = $source_record['owner_name'] ?? '';
        if (trim($owner_name) === '') {
            $blocking_reasons[] = 'Missing declarant owner name.';
        }

        // 3. Admin & Beneficiary -> business (raw names without prefixes)
        $business_parts = array();
        $admin_name = trim($source_record['administrator_name'] ?? '');
        $beneficiary_name = trim($source_record['beneficiary_name'] ?? '');
        if ($admin_name !== '') {
            $business_parts[] = $admin_name;
        }
        if ($beneficiary_name !== '') {
            $business_parts[] = $beneficiary_name;
        }
        $proposed_business = implode(' | ', $business_parts);
        if (strlen($proposed_business) > 200) {
            $blocking_reasons[] = 'Business field exceeds 200 character limit (' . strlen($proposed_business) . ' chars).';
        }

        // 4. Address
        $proposed_address = $source_record['owner_address'] ?? '';

        // 5. Property type
        $src_type = strtoupper(trim($source_record['rpu_type'] ?? ''));
        $native_kind = self::TYPE_MAPPING[$src_type] ?? null;
        if (!$native_kind) {
            $blocking_reasons[] = "Unsupported or missing property type: '$src_type'.";
        }

        // 6. General class
        $src_class = strtoupper(trim($source_record['classification'] ?? ''));
        $native_gen_class = null;
        if ($src_class === 'RP&LGU') {
            $warnings[] = 'RP&LGU has no approved native class mapping; gen_class set to NULL.';
            $native_gen_class = null;
        } elseif (isset(self::CLASS_MAPPING[$src_class])) {
            $native_gen_class = self::CLASS_MAPPING[$src_class];
        } else {
            $blocking_reasons[] = "Unmapped general class: '$src_class'.";
        }

        // 7. Area
        $raw_ha = $source_record['total_area_hectare'] ?? null;
        $raw_sqm = $source_record['total_area_sqm'] ?? null;
        $total_ha = ($raw_ha !== null && is_numeric($raw_ha)) ? floatval($raw_ha) : null;
        $total_sqm = ($raw_sqm !== null && is_numeric($raw_sqm)) ? floatval($raw_sqm) : null;
        $area_ha = null;
        $area_sqm = null;

        if ($native_gen_class === 'AGRICULTURAL') {
            $area_ha = $total_ha;
            $area_sqm = null;
            if ($raw_ha === null) {
                $warnings[] = 'Agricultural property has missing hectare area.';
                $warning_codes[] = 'MISSING_APPLICABLE_AREA';
            } elseif ($area_ha <= 0.0) {
                $warnings[] = 'Agricultural property has zero hectare area (0.00).';
                $warning_codes[] = 'ZERO_APPLICABLE_AREA';
            }
        } elseif ($native_gen_class !== null) {
            $area_sqm = $total_sqm;
            $area_ha = null;
            if ($raw_sqm === null) {
                $warnings[] = 'Non-agricultural property has missing sqm area.';
                $warning_codes[] = 'MISSING_APPLICABLE_AREA';
            } elseif ($area_sqm <= 0.0) {
                $warnings[] = 'Non-agricultural property has zero sqm area (0.00).';
                $warning_codes[] = 'ZERO_APPLICABLE_AREA';
            }
        } else {
            // RP&LGU: leave both NULL
            $area_ha = null;
            $area_sqm = null;
            $warnings[] = 'Area left NULL due to unmapped RP&LGU class.';
            $warning_codes[] = 'UNMAPPED_GENERAL_CLASS';
        }

        // 8. Cadastral lot & survey
        $lot_number = $source_record['cadastrallotno'] ?? '';
        $survey_number = $source_record['surveyno'] ?? '';

        // 9. Title number
        $title_number = $source_record['titleno'] ?? '';

        // 10. PIN: RPU fullpin -> RP pin -> FAAS fullpin
        $pin = '';
        if (!empty($source_record['rpu_fullpin'])) {
            $pin = trim($source_record['rpu_fullpin']);
        } elseif (!empty($source_record['rp_pin'])) {
            $pin = trim($source_record['rp_pin']);
        } elseif (!empty($source_record['faas_fullpin'])) {
            $pin = trim($source_record['faas_fullpin']);
        }
        if ($pin === '') {
            $warnings[] = 'No PIN available from RPU, real property, or FAAS.';
            $warning_codes[] = 'MISSING_PIN';
        }

        // 11. Assessed value & Market value
        // Distinguish legitimate numeric 0 from NULL/missing
        $raw_av = $source_record['total_assessed_value'] ?? null;
        $raw_mv = $source_record['total_market_value'] ?? null;
        $market_value = ($raw_mv !== null && is_numeric($raw_mv)) ? floatval($raw_mv) : 0.00;

        $assessed_value = null;
        if ($raw_av === null) {
            $assessed_value = null;
            $warnings[] = 'Missing assessed value: no source assessed value available.';
            $warning_codes[] = 'MISSING_ASSESSED_VALUE';
        } elseif (!is_numeric($raw_av)) {
            $assessed_value = null;
            $warnings[] = 'Invalid assessed value: source value is non-numeric.';
            $warning_codes[] = 'INVALID_ASSESSED_VALUE';
        } else {
            $assessed_value = floatval($raw_av);
            if ($assessed_value == 0.0) {
                $warnings[] = 'Zero assessed value: source contains numeric 0.00.';
                $warning_codes[] = 'ZERO_ASSESSED_VALUE';
            }
        }

        // 12. Effectivity date & exemption
        $raw_eff = $source_record['effectivityyear'] ?? null;
        $effectivity_year = null;
        if ($raw_eff !== null && $raw_eff !== '' && (int)$raw_eff > 0) {
            $effectivity_year = (string)(int)$raw_eff;
        } else {
            $warnings[] = 'Missing effectivity year: no valid effectivity year found in source.';
            $warning_codes[] = 'MISSING_EFFECTIVITY_DATE';
        }

        // Exemption mapping: inspect RPU taxable status and exemption lookup
        $raw_taxable = $source_record['rpu_taxable'] ?? ($source_record['taxable'] ?? null);
        $src_exemption_objid = $source_record['exemptiontype_objid'] ?? null;
        $src_exemption_code = $source_record['exemption_code'] ?? null;
        $src_exemption_name = $source_record['exemption_name'] ?? null;

        $has_exemption_type_ref = (!empty($src_exemption_objid) || !empty($src_exemption_code));
        $is_unresolved_ex_ref = (!empty($src_exemption_objid) && empty($src_exemption_code));

        $effectivity_exempt = 0;
        $exemption_source_value = ($raw_taxable !== null) ? (string)$raw_taxable : null;
        $exemption_table = 'wp_assessor_rpu.taxable';
        $exemption_explanation = '';

        if ($raw_taxable === null) {
            // Missing RPU or taxable state
            $effectivity_exempt = 0;
            $warnings[] = 'Missing source exemption information: RPU taxable status is NULL/absent.';
            $warning_codes[] = 'MISSING_EXEMPTION_INFO';
            $exemption_explanation = 'Source taxable status is missing; defaulted to 0 (non-exempt) and flagged for review.';
        } elseif ((int)$raw_taxable === 0) {
            // Verified exempt
            $effectivity_exempt = 1;
            if ($is_unresolved_ex_ref) {
                $warnings[] = "Unresolved exemption type reference '$src_exemption_objid'.";
                $warning_codes[] = 'UNRESOLVED_EXEMPTION_TYPE';
                $exemption_explanation = "Source establishes property as tax-exempt (taxable = 0) with unmapped exemption reference '$src_exemption_objid'. Mapped to effectivity_exempt = 1.";
            } else {
                $exemption_desc = $src_exemption_name ? "$src_exemption_name ($src_exemption_code)" : 'Exempt Real Property';
                $exemption_explanation = "Source establishes property as tax-exempt (taxable = 0) [$exemption_desc]. Mapped to effectivity_exempt = 1.";
            }
        } elseif ((int)$raw_taxable === 1) {
            // Verified taxable (non-exempt)
            $effectivity_exempt = 0;
            if ($has_exemption_type_ref) {
                // Conflicting condition: taxable=1 but an exemption type is referenced
                $warnings[] = "Conflicting exemption information: RPU taxable=1 (taxable) but exemption type '$src_exemption_code' is referenced. Taxable flag takes precedence; flagged for review.";
                $warning_codes[] = 'CONFLICTING_EXEMPTION_DATA';
                $exemption_explanation = "Contradiction detected: taxable = 1 conflicts with exemption type '$src_exemption_code'. Taxable flag takes precedence (effectivity_exempt = 0); flagged for review.";
            } else {
                $exemption_explanation = 'Source establishes property as taxable (taxable = 1). Mapped to effectivity_exempt = 0.';
            }
        } else {
            // Unrecognized or ambiguous code
            $effectivity_exempt = 0;
            $warnings[] = "Ambiguous or unrecognized taxable code: '$raw_taxable'.";
            $warning_codes[] = 'AMBIGUOUS_EXEMPTION_CODE';
            $exemption_explanation = "Unrecognized taxable value '$raw_taxable'; defaulted to 0 (non-exempt) and flagged for review.";
        }

        // 13. Assessor & Verifier Signatory fields from canonical settings
        $settings = self::get_canonical_assessor_settings();
        $missing_settings = array();
        foreach ($settings as $s_key => $s_val) {
            if ($s_val === '') {
                $missing_settings[] = $s_key;
            }
        }
        if (!empty($missing_settings)) {
            $warnings[] = 'Missing canonical Assessor Settings: ' . implode(', ', $missing_settings) . '.';
            $warning_codes[] = 'MISSING_ASSESSOR_SETTINGS';
        }

        // 14. Revision year and revision_id resolution
        // Determine applicable year from effectivity_year (or fallback to RPU ry)
        $year_for_rev = $effectivity_year ?: ($source_record['ry'] ?? null);
        $rev_resolution = self::resolve_revision_record($year_for_rev);
        $resolved_revision_id = $rev_resolution['resolved_id'];

        if ($rev_resolution['status'] === 'UNRESOLVED') {
            $warnings[] = 'Unresolved revision cycle: ' . $rev_resolution['explanation'];
            $warning_codes[] = 'NO_MATCHING_REVISION';
        }

        // 15. Assessment date
        $assessment_date = null;
        if (!empty($source_record['dtapproved']) && $source_record['dtapproved'] !== '0000-00-00') {
            $assessment_date = substr($source_record['dtapproved'], 0, 10);
        }

        // 16. Memoranda
        $memoranda = $source_record['memoranda'] ?? '';

        // 17. Previous TD Lineage
        $prev_td = !empty($source_record['fp_prevtdno']) ? trim($source_record['fp_prevtdno']) : trim($source_record['prevtdno'] ?? '');

        // 18. Location resolution
        $native_loc = $location_eval['native_location'] ?? null;
        if (!$location_eval['can_import'] || !$native_loc) {
            $blocking_reasons[] = 'Location discrepancy unapproved or unresolved (' . ($location_eval['status'] ?? 'UNRESOLVED') . ').';
            $warning_codes[] = 'LOCATION_NAME_DISCREPANCY';
        }

        // Determine eligibility status
        $status = 'READY';
        if (!empty($blocking_reasons)) {
            $status = 'BLOCKED';
            if (!$location_eval['can_import']) {
                $status = 'BLOCKED_LOCATION_MISMATCH';
            }
        } elseif (!empty($warnings)) {
            $status = 'READY_WITH_WARNINGS';
        }

        return array(
            'status' => $status,
            'warnings' => $warnings,
            'warning_codes' => array_values(array_unique($warning_codes)),
            'blocking_reasons' => $blocking_reasons,
            'exemption_details' => array(
                'source_value'        => $exemption_source_value,
                'source_code'         => $src_exemption_code,
                'source_description'  => $src_exemption_name,
                'source_table'        => $exemption_table,
                'proposed_value'      => $effectivity_exempt,
                'explanation'         => $exemption_explanation,
            ),
            'revision_details' => array(
                'inferred_year'       => $year_for_rev,
                'revision_id'         => $resolved_revision_id,
                'revision_year'       => $rev_resolution['revision_year'],
                'revision_code'       => $rev_resolution['revision_code'],
                'status'              => $rev_resolution['status'],
                'match_rule'          => $rev_resolution['match_rule'],
                'explanation'         => $rev_resolution['explanation'],
            ),
            'proposed_native' => array(
                'tax_declaration_number'          => $tdno,
                'previous_tax_declaration_number' => $prev_td,
                'declarant_last_name'             => $owner_name,
                'declarant_first_name'            => '',
                'declarant_middle_initial'        => '',
                'business_name'                   => $proposed_business,
                'location'                        => $native_loc['name'] ?? '',
                'native_location_id'              => $native_loc['id'] ?? null,
                'native_location_code'            => $native_loc['code'] ?? null,
                'native_location_name'            => $native_loc['name'] ?? null,
                'native_location_pin'             => $native_loc['pin'] ?? null,
                'lot_number'                      => $lot_number,
                'survey_number'                   => $survey_number,
                'unique_lot_number_identified'    => null,
                'area_hectare'                    => $area_ha,
                'area_sqm'                        => $area_sqm,
                'title_number'                    => $title_number,
                'assessed_value'                  => $assessed_value,
                'effectivity_date'                => $effectivity_year,
                'effectivity_exempt'              => $effectivity_exempt,
                'pin'                             => $pin,
                'address'                         => $proposed_address,
                'assessment_date'                 => $assessment_date,
                'kind_of_property'                => $native_kind,
                'gen_class'                       => $native_gen_class,
                'memoranda'                       => $memoranda,
                'supporting_documents'            => '',
                'supporting_documents_old'        => '',
                'market_value'                    => $market_value,
                'verifier_signatory_name'         => $settings['verifier_signatory_name'],
                'verifier_signatory_title'        => $settings['verifier_signatory_title'],
                'municipal_assessor_name'         => $settings['municipal_assessor_name'],
                'municipal_assessor_suffix'       => $settings['municipal_assessor_suffix'],
                'municipal_assessor_title'        => $settings['municipal_assessor_title'],
                'municipal_assessor_license'      => $settings['municipal_assessor_license'],
                'revision_id'                     => $resolved_revision_id,
            )
        );
    }

    /**
     * Get Overview Summary Counts dynamically.
     */
    public static function get_overview_summary() {
        global $wpdb;
        $table_faas = $wpdb->prefix . 'assessor_faas';
        $table_rpu = $wpdb->prefix . 'assessor_rpu';
        $table_rp = $wpdb->prefix . 'assessor_real_property';
        $table_properties = $wpdb->prefix . 'assessor_properties';
        $table_import_map = $wpdb->prefix . 'assessor_etracs_import_map';
        $table_crosswalk = $wpdb->prefix . 'assessor_etracs_crosswalk_approvals';

        // 1. Total eligible source records before native check
        $total_source_eligible = (int)$wpdb->get_var("
            SELECT COUNT(*) FROM $table_faas 
            WHERE origin_type = 'REPLICA' 
              AND state IN ('CURRENT', 'CANCELLED') 
              AND TRIM(COALESCE(tdno, '')) != ''
        ");

        // 2. Already present in native properties
        $already_native = (int)$wpdb->get_var("
            SELECT COUNT(DISTINCT f.objid)
            FROM $table_faas f
            JOIN $table_properties p ON LOWER(TRIM(f.tdno)) = LOWER(TRIM(p.tax_declaration_number))
            WHERE f.origin_type = 'REPLICA' 
              AND f.state IN ('CURRENT', 'CANCELLED') 
              AND TRIM(COALESCE(f.tdno, '')) != ''
        ");

        // 3. Already recorded in provenance table
        $already_imported_provenance = 0;
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_import_map))) {
            $already_imported_provenance = (int)$wpdb->get_var("SELECT COUNT(*) FROM $table_import_map");
        }

        // 4. Candidate breakdown query (not in native properties and not in provenance)
        $candidates_stats = $wpdb->get_row("
            SELECT 
                COUNT(*) as total_candidates,
                SUM(CASE WHEN r.objid IS NOT NULL THEN 1 ELSE 0 END) as with_rpu,
                SUM(CASE WHEN r.objid IS NULL THEN 1 ELSE 0 END) as without_rpu,
                SUM(CASE WHEN f.state = 'CURRENT' AND r.objid IS NOT NULL THEN 1 ELSE 0 END) as current_with_rpu,
                SUM(CASE WHEN f.state = 'CANCELLED' AND r.objid IS NOT NULL THEN 1 ELSE 0 END) as cancelled_with_rpu,
                SUM(CASE WHEN r.classification = 'RP&LGU' THEN 1 ELSE 0 END) as unmapped_class_rplgu,
                SUM(CASE WHEN r.objid IS NOT NULL AND (
                    (r.classification = 'AGRICULTURAL' AND r.total_area_hectare = 0) OR
                    (r.classification != 'AGRICULTURAL' AND r.total_area_sqm = 0)
                ) THEN 1 ELSE 0 END) as zero_area,
                SUM(CASE WHEN r.objid IS NOT NULL AND (
                    (r.classification = 'AGRICULTURAL' AND r.total_area_hectare IS NULL) OR
                    (r.classification != 'AGRICULTURAL' AND r.total_area_sqm IS NULL)
                ) THEN 1 ELSE 0 END) as missing_area,
                SUM(CASE WHEN r.objid IS NOT NULL AND r.total_assessed_value = 0 THEN 1 ELSE 0 END) as zero_av,
                SUM(CASE WHEN r.objid IS NOT NULL AND r.total_assessed_value IS NULL THEN 1 ELSE 0 END) as missing_av,
                SUM(CASE WHEN r.objid IS NOT NULL AND r.taxable = 0 THEN 1 ELSE 0 END) as verified_exempt,
                SUM(CASE WHEN r.objid IS NOT NULL AND r.taxable = 1 THEN 1 ELSE 0 END) as verified_taxable,
                SUM(CASE WHEN r.objid IS NULL OR r.taxable IS NULL THEN 1 ELSE 0 END) as missing_exemption,
                SUM(CASE WHEN r.objid IS NOT NULL AND r.taxable NOT IN (0, 1) THEN 1 ELSE 0 END) as ambiguous_exemption
            FROM $table_faas f
            LEFT JOIN $table_properties p ON LOWER(TRIM(f.tdno)) = LOWER(TRIM(p.tax_declaration_number))
            " . ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_import_map)) ? "LEFT JOIN $table_import_map m ON f.objid = m.source_faas_objid" : "") . "
            LEFT JOIN $table_rpu r ON f.rpuid = r.objid
            WHERE f.origin_type = 'REPLICA' 
              AND f.state IN ('CURRENT', 'CANCELLED') 
              AND TRIM(COALESCE(f.tdno, '')) != ''
              AND p.id IS NULL
              " . ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_import_map)) ? "AND m.id IS NULL" : "") . "
        ", ARRAY_A);

        // Breakdown of zero assessed value records by general class and property type
        $zero_av_breakdown_raw = $wpdb->get_results("
            SELECT COALESCE(r.classification, 'UNKNOWN') as classification, COALESCE(r.rpu_type, 'UNKNOWN') as rpu_type, COUNT(*) as cnt
            FROM $table_faas f
            LEFT JOIN $table_properties p ON LOWER(TRIM(f.tdno)) = LOWER(TRIM(p.tax_declaration_number))
            " . ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_import_map)) ? "LEFT JOIN $table_import_map m ON f.objid = m.source_faas_objid" : "") . "
            LEFT JOIN $table_rpu r ON f.rpuid = r.objid
            WHERE f.origin_type = 'REPLICA' 
              AND f.state IN ('CURRENT', 'CANCELLED') 
              AND TRIM(COALESCE(f.tdno, '')) != ''
              AND p.id IS NULL
              " . ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_import_map)) ? "AND m.id IS NULL" : "") . "
              AND r.objid IS NOT NULL
              AND r.total_assessed_value = 0
            GROUP BY r.classification, r.rpu_type
            ORDER BY cnt DESC
        ", ARRAY_A);

        // 5. Duplicate source TD numbers among remaining candidates
        $duplicate_source_tds = (int)$wpdb->get_var("
            SELECT COUNT(*) FROM (
                SELECT LOWER(TRIM(f.tdno)) as c_td, COUNT(*) as cnt
                FROM $table_faas f
                LEFT JOIN $table_properties p ON LOWER(TRIM(f.tdno)) = LOWER(TRIM(p.tax_declaration_number))
                WHERE f.origin_type = 'REPLICA' 
                  AND f.state IN ('CURRENT', 'CANCELLED') 
                  AND TRIM(COALESCE(f.tdno, '')) != ''
                  AND p.id IS NULL
                GROUP BY c_td
                HAVING cnt > 1
            ) as d
        ");

        // 6. Location audit stats
        $audit = self::get_location_crosswalk_audit();
        $location_discrepancies_count = 0;
        $unapproved_location_candidates = 0;
        foreach ($audit as $a) {
            if (!$a['can_import']) {
                $location_discrepancies_count++;
                $unapproved_location_candidates += $a['affected_candidates'];
            }
        }

        $total_candidates = (int)($candidates_stats['total_candidates'] ?? 0);
        $missing_rpu = (int)($candidates_stats['without_rpu'] ?? 0);
        
        // Ready to import: candidates with RPU and approved location
        // Note: RP&LGU is eligible with warning, zero area/av are warnings
        $ready_to_import = max(0, $total_candidates - $missing_rpu - $unapproved_location_candidates);

        $verified_exempt = (int)($candidates_stats['verified_exempt'] ?? 0);
        $verified_taxable = (int)($candidates_stats['verified_taxable'] ?? 0);
        $missing_exemption = (int)($candidates_stats['missing_exemption'] ?? 0);
        $ambiguous_exemption = (int)($candidates_stats['ambiguous_exemption'] ?? 0);

        return array(
            'total_source_eligible'        => $total_source_eligible,
            'already_native'               => $already_native,
            'already_imported_provenance'  => $already_imported_provenance,
            'total_candidates'             => $total_candidates,
            'with_rpu'                     => (int)($candidates_stats['with_rpu'] ?? 0),
            'missing_rpu'                  => $missing_rpu,
            'current_with_rpu'             => (int)($candidates_stats['current_with_rpu'] ?? 0),
            'cancelled_with_rpu'           => (int)($candidates_stats['cancelled_with_rpu'] ?? 0),
            'duplicate_source_tds'         => $duplicate_source_tds,
            'unmapped_class_rplgu'         => (int)($candidates_stats['unmapped_class_rplgu'] ?? 0),
            'zero_area'                    => (int)($candidates_stats['zero_area'] ?? 0),
            'missing_area'                 => (int)($candidates_stats['missing_area'] ?? 0),
            'zero_av'                      => (int)($candidates_stats['zero_av'] ?? 0),
            'missing_av'                   => (int)($candidates_stats['missing_av'] ?? 0),
            'invalid_av'                   => 0,
            'verified_exempt'              => $verified_exempt,
            'verified_taxable'             => $verified_taxable,
            'missing_exemption'            => $missing_exemption,
            'ambiguous_exemption'          => $ambiguous_exemption,
            'differs_from_default_exempt'  => $verified_exempt, // cases where proposed effectivity_exempt (1) differs from default 0
            'zero_av_breakdown'            => $zero_av_breakdown_raw ?: array(),
            'location_discrepancies_barangays' => $location_discrepancies_count,
            'blocked_location_candidates'  => $unapproved_location_candidates,
            'ready_to_import'              => $ready_to_import,
        );
    }

    /**
     * Server-side paginated preview of source candidates.
     */
    public static function get_preview($params) {
        global $wpdb;
        $table_faas = $wpdb->prefix . 'assessor_faas';
        $table_rpu = $wpdb->prefix . 'assessor_rpu';
        $table_rp = $wpdb->prefix . 'assessor_real_property';
        $table_barangay = $wpdb->prefix . 'assessor_barangay';
        $table_properties = $wpdb->prefix . 'assessor_properties';
        $table_import_map = $wpdb->prefix . 'assessor_etracs_import_map';
        $table_crosswalk = $wpdb->prefix . 'assessor_etracs_crosswalk_approvals';
        $table_faas_prev = $wpdb->prefix . 'assessor_faas_previous';

        $page = max(1, intval($params['page'] ?? 1));
        $per_page = min(100, max(1, intval($params['per_page'] ?? 25)));
        $offset = ($page - 1) * $per_page;

        $has_import_map = (bool)$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_import_map));

        // Base where clauses for candidates
        $where = array(
            "f.origin_type = 'REPLICA'",
            "f.state IN ('CURRENT', 'CANCELLED')",
            "TRIM(COALESCE(f.tdno, '')) != ''"
        );

        // Filter: eligibility status (supports both legacy 'eligibility' and explicit 'eligibility_status')
        $elig_param = sanitize_text_field($params['eligibility_status'] ?? $params['eligibility'] ?? '');
        $elig_param = strtoupper(trim($elig_param));

        // Location discrepancy condition expression (Lorega unapproved)
        $lorega_blocked_expr = "((b.pin = '059-10-0024' OR rp.barangayid = '059-10-024') AND c.id IS NULL)";
        
        // Warnings expression (numeric zero AV, zero area, RP&LGU, missing/ambiguous exemption)
        $warning_expr = "(
            (r.total_assessed_value IS NOT NULL AND r.total_assessed_value = 0) OR
            (r.classification = 'AGRICULTURAL' AND (r.total_area_hectare IS NULL OR r.total_area_hectare <= 0)) OR
            (r.classification != 'AGRICULTURAL' AND (r.total_area_sqm IS NULL OR r.total_area_sqm <= 0)) OR
            (r.classification = 'RP&LGU') OR
            (r.objid IS NOT NULL AND r.taxable IS NULL) OR
            (r.objid IS NOT NULL AND r.taxable NOT IN (0, 1))
        )";

        switch ($elig_param) {
            case 'ALREADY_NATIVE':
            case 'SKIPPED_ALREADY_NATIVE':
                $where[] = "p.id IS NOT NULL";
                break;
            case 'ALREADY_IMPORTED':
            case 'SKIPPED_ALREADY_IMPORTED':
                if ($has_import_map) {
                    $where[] = "m.id IS NOT NULL";
                } else {
                    $where[] = "1 = 0";
                }
                break;
            case 'SKIPPED_NO_RPU':
                $where[] = "p.id IS NULL";
                if ($has_import_map) {
                    $where[] = "m.id IS NULL";
                }
                $where[] = "r.objid IS NULL";
                break;
            case 'BLOCKED_LOCATION_MISMATCH':
            case 'BLOCKED':
                $where[] = "p.id IS NULL";
                if ($has_import_map) {
                    $where[] = "m.id IS NULL";
                }
                $where[] = "r.objid IS NOT NULL";
                $where[] = $lorega_blocked_expr;
                break;
            case 'READY':
                $where[] = "p.id IS NULL";
                if ($has_import_map) {
                    $where[] = "m.id IS NULL";
                }
                $where[] = "r.objid IS NOT NULL";
                $where[] = "NOT $lorega_blocked_expr";
                $where[] = "NOT $warning_expr";
                break;
            case 'READY_WITH_WARNINGS':
                $where[] = "p.id IS NULL";
                if ($has_import_map) {
                    $where[] = "m.id IS NULL";
                }
                $where[] = "r.objid IS NOT NULL";
                $where[] = "NOT $lorega_blocked_expr";
                $where[] = $warning_expr;
                break;
            case 'CANDIDATE':
            case 'ALL_CANDIDATES':
            case '':
            case 'ALL':
                // Default candidate scope: not yet native and not yet recorded in provenance
                if ($elig_param !== 'ALL') {
                    $where[] = "p.id IS NULL";
                    if ($has_import_map) {
                        $where[] = "m.id IS NULL";
                    }
                }
                break;
            default:
                // If an unrecognized or future status is passed, maintain safe candidate filter
                $where[] = "p.id IS NULL";
                if ($has_import_map) {
                    $where[] = "m.id IS NULL";
                }
                break;
        }

        // Filter: warning_type
        $warn_param = sanitize_text_field($params['warning_type'] ?? '');
        $warn_param = strtoupper(trim($warn_param));

        switch ($warn_param) {
            case 'HAS_ANY_WARNING':
                $where[] = $warning_expr;
                break;
            case 'ZERO_ASSESSED_VALUE':
                $where[] = "r.objid IS NOT NULL AND r.total_assessed_value IS NOT NULL AND r.total_assessed_value = 0";
                break;
            case 'MISSING_ASSESSED_VALUE':
                $where[] = "r.objid IS NOT NULL AND r.total_assessed_value IS NULL";
                break;
            case 'ZERO_APPLICABLE_AREA':
                $where[] = "r.objid IS NOT NULL AND (
                    (r.classification = 'AGRICULTURAL' AND r.total_area_hectare = 0) OR
                    (r.classification != 'AGRICULTURAL' AND r.total_area_sqm = 0)
                )";
                break;
            case 'MISSING_APPLICABLE_AREA':
                $where[] = "r.objid IS NOT NULL AND (
                    (r.classification = 'AGRICULTURAL' AND r.total_area_hectare IS NULL) OR
                    (r.classification != 'AGRICULTURAL' AND r.total_area_sqm IS NULL)
                )";
                break;
            case 'UNMAPPED_GENERAL_CLASS':
                $where[] = "r.objid IS NOT NULL AND r.classification = 'RP&LGU'";
                break;
            case 'LOCATION_NAME_DISCREPANCY':
                $where[] = $lorega_blocked_expr;
                break;
            case 'ALL':
            case '':
                // No warning filter restriction
                break;
        }

        // Filter: TD number
        if (!empty($params['tdno'])) {
            $td_search = '%' . $wpdb->esc_like(trim($params['tdno'])) . '%';
            $where[] = $wpdb->prepare("f.tdno LIKE %s", $td_search);
        }

        // Filter: lifecycle state
        if (!empty($params['state'])) {
            $state_val = strtoupper(trim($params['state']));
            if (in_array($state_val, array('CURRENT', 'CANCELLED'), true)) {
                $where[] = $wpdb->prepare("f.state = %s", $state_val);
            }
        }

        // Filter: barangay
        if (!empty($params['barangay'])) {
            $b_val = trim($params['barangay']);
            $where[] = $wpdb->prepare("(rp.barangayid = %s OR b.name = %s)", $b_val, $b_val);
        }

        // Filter: general class
        if (!empty($params['gen_class'])) {
            $where[] = $wpdb->prepare("r.classification = %s", trim($params['gen_class']));
        }

        // Filter: property type
        if (!empty($params['property_type'])) {
            $where[] = $wpdb->prepare("r.rpu_type = %s", trim($params['property_type']));
        }

        $where_sql = implode(' AND ', $where);

        $table_exemptiontype = $wpdb->prefix . 'assessor_exemptiontype';
        $from_joins = "
            FROM $table_faas f
            LEFT JOIN $table_properties p ON LOWER(TRIM(f.tdno)) = LOWER(TRIM(p.tax_declaration_number))
            " . ($has_import_map ? "LEFT JOIN $table_import_map m ON f.objid = m.source_faas_objid" : "") . "
            LEFT JOIN $table_rpu r ON f.rpuid = r.objid
            LEFT JOIN $table_exemptiontype ex ON r.exemptiontype_objid = ex.objid
            LEFT JOIN $table_rp rp ON f.realpropertyid = rp.objid
            LEFT JOIN $table_barangay b ON rp.barangayid = b.objid
            LEFT JOIN $table_crosswalk c ON (b.objid = c.source_barangay_objid OR rp.barangayid = c.source_barangay_objid)
            LEFT JOIN (
                SELECT faasid, GROUP_CONCAT(DISTINCT prevtdno SEPARATOR ', ') as prevtdno
                FROM $table_faas_prev
                WHERE prevtdno IS NOT NULL AND TRIM(prevtdno) != ''
                GROUP BY faasid
            ) fp ON f.objid = fp.faasid
        ";

        // Count total matching
        $count_query = "SELECT COUNT(*) $from_joins WHERE $where_sql";
        $total_rows = (int)$wpdb->get_var($count_query);

        // Fetch paginated rows with security-safe specific columns
        $select_fields = "
            f.objid as faas_id, f.tdno, f.state as faas_state, f.effectivityyear, f.effectivityqtr,
            f.titleno, f.owner_name, f.owner_address, f.administrator_name, f.beneficiary_name,
            f.memoranda, f.prevtdno, f.fullpin as faas_fullpin, f.dtapproved, f.rpuid, f.realpropertyid,
            r.objid as rpu_objid, r.rpu_type, r.classification, r.total_market_value, r.total_assessed_value,
            r.total_area_hectare, r.total_area_sqm, r.fullpin as rpu_fullpin, r.taxable as rpu_taxable, r.ry,
            r.exemptiontype_objid, ex.code as exemption_code, ex.name as exemption_name,
            rp.objid as rp_objid, rp.pin as rp_pin, rp.cadastrallotno, rp.surveyno, rp.barangay as rp_barangay, rp.barangayid as rp_barangayid,
            b.objid as b_objid, b.name as b_name, b.pin as b_pin,
            fp.prevtdno as fp_prevtdno,
            p.id as native_property_id,
            " . ($has_import_map ? "m.id as provenance_id, m.imported_at as provenance_imported_at" : "NULL as provenance_id, NULL as provenance_imported_at") . "
        ";

        $data_query = "
            SELECT $select_fields
            $from_joins
            WHERE $where_sql
            ORDER BY f.tdno ASC
            LIMIT $offset, $per_page
        ";

        $rows = $wpdb->get_results($data_query, ARRAY_A);

        $locations_cache = self::get_native_locations_cache();
        $approvals = self::get_crosswalk_approvals();

        $items = array();
        foreach ($rows as $row) {
            $b_data = array(
                'objid' => $row['b_objid'] ?? $row['rp_barangayid'],
                'name'  => $row['b_name'] ?? $row['rp_barangay'],
                'pin'   => $row['b_pin'] ?? ''
            );
            $loc_eval = self::evaluate_location_match($b_data, $locations_cache, $approvals);
            $mapped = self::map_candidate_record($row, $loc_eval);

            // Check if native duplicate
            $is_already_native = !empty($row['native_property_id']);
            $is_already_imported = !empty($row['provenance_id']);

            $item_status = $mapped['status'];
            if ($is_already_native) {
                $item_status = 'SKIPPED_ALREADY_NATIVE';
            } elseif ($is_already_imported) {
                $item_status = 'SKIPPED_ALREADY_IMPORTED';
            } elseif (empty($row['rpu_objid'])) {
                $item_status = 'SKIPPED_NO_RPU';
            }

            $items[] = array(
                'source' => array(
                    'faas_id'              => $row['faas_id'],
                    'rpu_id'               => $row['rpu_objid'],
                    'real_property_id'     => $row['rp_objid'],
                    'tdno'                 => $row['tdno'],
                    'state'                => $row['faas_state'],
                    'owner_name'           => $row['owner_name'],
                    'owner_address'        => $row['owner_address'],
                    'administrator_name'   => $row['administrator_name'],
                    'beneficiary_name'     => $row['beneficiary_name'],
                    'barangay_id'          => $row['b_objid'] ?? $row['rp_barangayid'],
                    'barangay_name'        => $row['b_name'] ?? $row['rp_barangay'],
                    'barangay_pin'         => $row['b_pin'] ?? '',
                    'cadastrallotno'       => $row['cadastrallotno'],
                    'surveyno'             => $row['surveyno'],
                    'fullpin'              => !empty($row['rpu_fullpin']) ? $row['rpu_fullpin'] : (!empty($row['rp_pin']) ? $row['rp_pin'] : $row['faas_fullpin']),
                    'titleno'              => $row['titleno'],
                    'rpu_type'             => $row['rpu_type'],
                    'classification'       => $row['classification'],
                    'total_area_hectare'   => $row['total_area_hectare'],
                    'total_area_sqm'       => $row['total_area_sqm'],
                    'total_market_value'   => $row['total_market_value'],
                    'total_assessed_value' => $row['total_assessed_value'],
                    'taxable'              => $row['rpu_taxable'],
                    'ry'                   => $row['ry'],
                    'exemptiontype_objid'  => $row['exemptiontype_objid'],
                    'exemption_code'       => $row['exemption_code'],
                    'exemption_name'       => $row['exemption_name'],
                    'effectivityyear'      => $row['effectivityyear'],
                    'dtapproved'           => $row['dtapproved'],
                    'memoranda'            => $row['memoranda'],
                    'prevtdno'             => !empty($row['fp_prevtdno']) ? $row['fp_prevtdno'] : $row['prevtdno'],
                ),
                'proposed_native'       => $mapped['proposed_native'],
                'exemption_details'     => $mapped['exemption_details'],
                'revision_details'      => $mapped['revision_details'],
                'location_audit'        => $loc_eval,
                'eligibility_status'    => $item_status,
                'warnings'              => $mapped['warnings'],
                'warning_codes'         => $mapped['warning_codes'] ?? array(),
                'blocking_reasons'      => $mapped['blocking_reasons'],
                'is_already_native'     => $is_already_native,
                'is_already_imported'   => $is_already_imported,
            );
        }

        return array(
            'items'       => $items,
            'total'       => $total_rows,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_rows / $per_page),
        );
    }

    /**
     * Explicit crosswalk approval action.
     */
    public static function approve_location_crosswalk($params, $user_id) {
        global $wpdb;
        $table_crosswalk = $wpdb->prefix . 'assessor_etracs_crosswalk_approvals';

        $source_barangay_objid = sanitize_text_field($params['source_barangay_objid'] ?? '');
        $native_location_id = sanitize_text_field($params['native_location_id'] ?? '');
        $notes = sanitize_textarea_field($params['notes'] ?? '');

        if (!$source_barangay_objid || !$native_location_id) {
            return new WP_Error('missing_params', 'source_barangay_objid and native_location_id are required.', array('status' => 400));
        }

        // Verify source barangay
        $table_barangay = $wpdb->prefix . 'assessor_barangay';
        $sb = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_barangay WHERE objid = %s", $source_barangay_objid), ARRAY_A);
        if (!$sb) {
            return new WP_Error('not_found', 'Source barangay not found.', array('status' => 404));
        }

        // Verify native location
        $table_locations = $wpdb->prefix . 'assessor_locations';
        $nl = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_locations WHERE id = %s AND deleted_at IS NULL", $native_location_id), ARRAY_A);
        if (!$nl) {
            return new WP_Error('not_found', 'Native location not found or inactive.', array('status' => 404));
        }

        $approval_id = class_exists('Assessor_UUID') ? Assessor_UUID::v7() : wp_generate_uuid4();
        $now = Assessor_Timezone::now_mysql();

        $result = $wpdb->replace(
            $table_crosswalk,
            array(
                'id'                     => $approval_id,
                'source_barangay_objid'  => $sb['objid'],
                'source_barangay_name'   => $sb['name'],
                'source_barangay_pin'    => $sb['pin'],
                'native_location_id'     => $nl['id'],
                'native_location_name'   => $nl['name'],
                'resolution_type'        => 'EXPLICIT_APPROVAL',
                'notes'                  => $notes,
                'approved_by'            => $user_id,
                'approved_at'            => $now
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to save crosswalk approval: ' . $wpdb->last_error, array('status' => 500));
        }

        return array(
            'success'               => true,
            'source_barangay_objid' => $sb['objid'],
            'native_location_id'    => $nl['id'],
            'native_location_name'  => $nl['name'],
            'approved_at'           => $now
        );
    }

    /**
     * Import a bounded batch of source FAAS identifiers.
     */
    public static function import_batch($source_faas_ids, $user_id, $batch_id = null) {
        global $wpdb;

        if (empty($source_faas_ids) || !is_array($source_faas_ids)) {
            return new WP_Error('invalid_payload', 'source_faas_ids must be a non-empty array.', array('status' => 400));
        }

        if (count($source_faas_ids) > 100) {
            return new WP_Error('batch_too_large', 'Batch size exceeds maximum limit of 100 records.', array('status' => 400));
        }

        if (!$batch_id) {
            $batch_id = class_exists('Assessor_UUID') ? Assessor_UUID::v7() : wp_generate_uuid4();
        }

        $table_faas = $wpdb->prefix . 'assessor_faas';
        $table_rpu = $wpdb->prefix . 'assessor_rpu';
        $table_rp = $wpdb->prefix . 'assessor_real_property';
        $table_barangay = $wpdb->prefix . 'assessor_barangay';
        $table_properties = $wpdb->prefix . 'assessor_properties';
        $table_property_states = $wpdb->prefix . 'assessor_property_states';
        $table_import_map = $wpdb->prefix . 'assessor_etracs_import_map';
        $table_faas_prev = $wpdb->prefix . 'assessor_faas_previous';

        $locations_cache = self::get_native_locations_cache();
        $approvals = self::get_crosswalk_approvals();

        $results = array();

        foreach ($source_faas_ids as $faas_id) {
            $faas_id = sanitize_text_field($faas_id);
            if (!$faas_id) continue;

            // Re-read candidate fresh inside transactional context
            $table_exemptiontype = $wpdb->prefix . 'assessor_exemptiontype';
            $source_row = $wpdb->get_row($wpdb->prepare("
                SELECT 
                    f.objid as faas_id, f.tdno, f.state as faas_state, f.effectivityyear, f.effectivityqtr,
                    f.titleno, f.owner_name, f.owner_address, f.administrator_name, f.beneficiary_name,
                    f.memoranda, f.prevtdno, f.fullpin as faas_fullpin, f.dtapproved, f.rpuid, f.realpropertyid,
                    r.objid as rpu_objid, r.rpu_type, r.classification, r.total_market_value, r.total_assessed_value,
                    r.total_area_hectare, r.total_area_sqm, r.fullpin as rpu_fullpin, r.taxable as rpu_taxable, r.ry,
                    r.exemptiontype_objid, ex.code as exemption_code, ex.name as exemption_name,
                    rp.objid as rp_objid, rp.pin as rp_pin, rp.cadastrallotno, rp.surveyno, rp.barangay as rp_barangay, rp.barangayid as rp_barangayid,
                    b.objid as b_objid, b.name as b_name, b.pin as b_pin,
                    fp.prevtdno as fp_prevtdno
                FROM $table_faas f
                LEFT JOIN $table_rpu r ON f.rpuid = r.objid
                LEFT JOIN $table_exemptiontype ex ON r.exemptiontype_objid = ex.objid
                LEFT JOIN $table_rp rp ON f.realpropertyid = rp.objid
                LEFT JOIN $table_barangay b ON rp.barangayid = b.objid
                LEFT JOIN (
                    SELECT faasid, GROUP_CONCAT(DISTINCT prevtdno SEPARATOR ', ') as prevtdno
                    FROM $table_faas_prev
                    WHERE prevtdno IS NOT NULL AND TRIM(prevtdno) != ''
                    GROUP BY faasid
                ) fp ON f.objid = fp.faasid
                WHERE f.objid = %s
            ", $faas_id), ARRAY_A);

            if (!$source_row) {
                $results[] = array(
                    'source_faas_objid' => $faas_id,
                    'status'            => 'FAILED',
                    'error'             => 'Source FAAS row not found.',
                );
                continue;
            }

            $tdno = trim($source_row['tdno'] ?? '');
            $tdno_key = self::canonical_td_key($tdno);

            // Re-check 1: TD nonblank
            if ($tdno === '') {
                $results[] = array(
                    'source_faas_objid' => $faas_id,
                    'source_tdno'       => $tdno,
                    'status'            => 'BLOCKED',
                    'error'             => 'Source TD number is blank.',
                );
                continue;
            }

            // Re-check 2: State must be CURRENT or CANCELLED
            $state = strtoupper(trim($source_row['faas_state'] ?? ''));
            if (!in_array($state, array('CURRENT', 'CANCELLED'), true)) {
                $results[] = array(
                    'source_faas_objid' => $faas_id,
                    'source_tdno'       => $tdno,
                    'status'            => 'BLOCKED_UNSUPPORTED_STATE',
                    'error'             => "Source lifecycle state '$state' is not supported.",
                );
                continue;
            }

            // Re-check 3: Missing RPU
            if (empty($source_row['rpu_objid'])) {
                $results[] = array(
                    'source_faas_objid' => $faas_id,
                    'source_tdno'       => $tdno,
                    'status'            => 'SKIPPED_NO_RPU',
                    'error'             => 'Source FAAS is missing an associated RPU record.',
                );
                continue;
            }

            // Re-check 4: Already imported in provenance table
            $prov_exists = $wpdb->get_var($wpdb->prepare("
                SELECT id FROM $table_import_map 
                WHERE source_faas_objid = %s OR source_tdno_key = %s LIMIT 1
            ", $faas_id, $tdno_key));
            if ($prov_exists) {
                $results[] = array(
                    'source_faas_objid' => $faas_id,
                    'source_tdno'       => $tdno,
                    'status'            => 'SKIPPED_ALREADY_IMPORTED',
                    'error'             => 'Source record or canonical TD has already been imported into native properties.',
                );
                continue;
            }

            // Re-check 5: Already present in native properties table
            $native_exists = $wpdb->get_var($wpdb->prepare("
                SELECT id FROM $table_properties 
                WHERE LOWER(TRIM(tax_declaration_number)) = %s LIMIT 1
            ", $tdno_key));
            if ($native_exists) {
                $results[] = array(
                    'source_faas_objid' => $faas_id,
                    'source_tdno'       => $tdno,
                    'status'            => 'SKIPPED_ALREADY_NATIVE',
                    'error'             => 'A native property with this tax declaration number already exists.',
                );
                continue;
            }

            // Re-check 6: Location evaluation
            $b_data = array(
                'objid' => $source_row['b_objid'] ?? $source_row['rp_barangayid'],
                'name'  => $source_row['b_name'] ?? $source_row['rp_barangay'],
                'pin'   => $source_row['b_pin'] ?? ''
            );
            $loc_eval = self::evaluate_location_match($b_data, $locations_cache, $approvals);
            if (!$loc_eval['can_import'] || empty($loc_eval['native_location'])) {
                $results[] = array(
                    'source_faas_objid' => $faas_id,
                    'source_tdno'       => $tdno,
                    'status'            => 'BLOCKED_LOCATION_UNRESOLVED',
                    'error'             => 'Location mapping is not resolved or approved: ' . ($loc_eval['explanation'] ?? 'Discrepancy pending.'),
                );
                continue;
            }

            // Map target values
            $mapped = self::map_candidate_record($source_row, $loc_eval);
            if ($mapped['status'] === 'BLOCKED') {
                $results[] = array(
                    'source_faas_objid' => $faas_id,
                    'source_tdno'       => $tdno,
                    'status'            => 'BLOCKED',
                    'error'             => implode('; ', $mapped['blocking_reasons']),
                );
                continue;
            }

            $target = $mapped['proposed_native'];
            $native_prop_id = class_exists('Assessor_UUID') ? Assessor_UUID::v7() : wp_generate_uuid4();
            $provenance_id = class_exists('Assessor_UUID') ? Assessor_UUID::v7() : wp_generate_uuid4();
            $now = Assessor_Timezone::now_mysql();

            // ──────────────────────────────────────────────
            // TRANSACTION EXECUTION
            // ──────────────────────────────────────────────
            $wpdb->query('START TRANSACTION');

            // 1. Insert native property
            $ins_prop = $wpdb->insert(
                $table_properties,
                array(
                    'id'                              => $native_prop_id,
                    'tax_declaration_number'          => $target['tax_declaration_number'],
                    'previous_tax_declaration_number' => $target['previous_tax_declaration_number'],
                    'declarant_last_name'             => $target['declarant_last_name'],
                    'declarant_first_name'            => $target['declarant_first_name'],
                    'declarant_middle_initial'        => $target['declarant_middle_initial'],
                    'business'                        => $target['business_name'],
                    'location'                        => $target['location'],
                    'lot_number'                      => $target['lot_number'],
                    'unique_lot_number_identified'    => null,
                    'survey_number'                   => $target['survey_number'],
                    'area_hectare'                    => $target['area_hectare'],
                    'area_sqm'                        => $target['area_sqm'],
                    'title_number'                    => $target['title_number'],
                    'assessed_value'                  => $target['assessed_value'],
                    'effectivity_date'                => $target['effectivity_date'],
                    'effectivity_exempt'              => $target['effectivity_exempt'],
                    'pin'                             => $target['pin'],
                    'address'                         => $target['address'],
                    'assessment_date'                 => $target['assessment_date'],
                    'kind_of_property'                => $target['kind_of_property'],
                    'gen_class'                       => $target['gen_class'],
                    'memoranda'                       => $target['memoranda'],
                    'supporting_documents'            => '',
                    'supporting_documents_old'        => '',
                    'verifier_signatory_name'         => $target['verifier_signatory_name'],
                    'verifier_signatory_title'        => $target['verifier_signatory_title'],
                    'municipal_assessor_name'         => $target['municipal_assessor_name'],
                    'municipal_assessor_suffix'       => $target['municipal_assessor_suffix'],
                    'municipal_assessor_title'        => $target['municipal_assessor_title'],
                    'municipal_assessor_license'      => $target['municipal_assessor_license'],
                    'revision_id'                     => $target['revision_id'],
                    'status'                          => 'active',
                    'created_by'                      => $user_id,
                    'updated_by'                      => $user_id,
                    'created_at'                      => $now,
                    'updated_at'                      => $now
                )
            );

            if ($ins_prop === false) {
                $err = $wpdb->last_error;
                $wpdb->query('ROLLBACK');
                $results[] = array(
                    'source_faas_objid' => $faas_id,
                    'source_tdno'       => $tdno,
                    'status'            => 'FAILED',
                    'error'             => "Failed to insert native property: $err",
                );
                continue;
            }

            // 2. Insert property state
            $ins_state = $wpdb->insert(
                $table_property_states,
                array(
                    'property_id' => $native_prop_id,
                    'state'       => $state,
                    'updated_by'  => $user_id,
                    'updated_at'  => $now
                ),
                array('%s', '%s', '%s', '%s')
            );

            if ($ins_state === false) {
                $err = $wpdb->last_error;
                $wpdb->query('ROLLBACK');
                $results[] = array(
                    'source_faas_objid' => $faas_id,
                    'source_tdno'       => $tdno,
                    'status'            => 'FAILED',
                    'error'             => "Failed to insert property state: $err",
                );
                continue;
            }

            // 3. Insert provenance sidecar record
            $payload_hash = hash('sha256', json_encode($target));
            $ins_prov = $wpdb->insert(
                $table_import_map,
                array(
                    'id'                     => $provenance_id,
                    'property_id'            => $native_prop_id,
                    'source_faas_objid'      => $faas_id,
                    'source_rpu_objid'       => $source_row['rpu_objid'],
                    'source_tdno'            => $tdno,
                    'source_tdno_key'        => $tdno_key,
                    'source_state'           => $state,
                    'source_barangay_objid'  => $b_data['objid'],
                    'source_barangay_pin'    => $b_data['pin'],
                    'native_location_id'     => $target['native_location_id'],
                    'native_location_name'   => $target['native_location_name'],
                    'import_batch_id'        => $batch_id,
                    'mapping_version'        => '1.0',
                    'imported_by'            => $user_id,
                    'imported_at'            => $now,
                    'source_payload_hash'    => $payload_hash
                ),
                array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
            );

            if ($ins_prov === false) {
                $err = $wpdb->last_error;
                $wpdb->query('ROLLBACK');
                $results[] = array(
                    'source_faas_objid' => $faas_id,
                    'source_tdno'       => $tdno,
                    'status'            => 'FAILED',
                    'error'             => "Failed to write provenance map: $err",
                );
                continue;
            }

            // Commit atomic writes
            $wpdb->query('COMMIT');

            // 4. Post-commit: Local Sync queueing
            $sync_queued = false;
            $sync_error = null;
            if (class_exists('Assessor_Sync')) {
                try {
                    Assessor_Sync::enqueue_property($native_prop_id, 'upsert');
                    $sync_queued = true;
                } catch (Exception $e) {
                    $sync_queued = false;
                    $sync_error = $e->getMessage();
                }
            }

            $results[] = array(
                'source_faas_objid'     => $faas_id,
                'source_rpu_objid'      => $source_row['rpu_objid'],
                'source_tdno'           => $tdno,
                'source_state'          => $state,
                'source_owner_name'     => $source_row['owner_name'],
                'source_owner_address'  => $source_row['owner_address'],
                'source_titleno'        => $source_row['titleno'],
                'source_effectivityyear'=> $source_row['effectivityyear'],
                'source_administrator_name'=> $source_row['administrator_name'],
                'source_beneficiary_name'  => $source_row['beneficiary_name'],
                'source_cadastrallotno' => $source_row['cadastrallotno'],
                'source_surveyno'       => $source_row['surveyno'],
                'source_memoranda'      => $source_row['memoranda'],
                'source_taxable'        => $source_row['rpu_taxable'],
                'source_exemption_code' => $source_row['exemption_code'] ?? null,
                'native_property_id'    => $native_prop_id,
                'native_location_id'    => $target['native_location_id'],
                'native_location_name'  => $target['native_location_name'],
                'target_address'        => $target['address'],
                'target_title_number'   => $target['title_number'],
                'target_effectivity_date'=> $target['effectivity_date'],
                'target_effectivity_exempt'=> $target['effectivity_exempt'],
                'target_lot_number'     => $target['lot_number'],
                'target_survey_number'  => $target['survey_number'],
                'target_business'       => $target['business_name'],
                'target_memoranda'      => $target['memoranda'],
                'target_verifier_name'  => $target['verifier_signatory_name'],
                'target_verifier_title' => $target['verifier_signatory_title'],
                'target_assessor_name'  => $target['municipal_assessor_name'],
                'target_assessor_suffix'=> $target['municipal_assessor_suffix'],
                'target_assessor_title' => $target['municipal_assessor_title'],
                'target_assessor_license'=> $target['municipal_assessor_license'],
                'target_revision_id'    => $target['revision_id'],
                'status'                => 'IMPORTED',
                'state_written'         => true,
                'provenance_written'    => true,
                'sync_queued'           => $sync_queued,
                'sync_error'            => $sync_error,
                'import_batch_id'       => $batch_id,
                'timestamp'             => $now,
                'warnings'              => $mapped['warnings']
            );
        }

        return array(
            'batch_id' => $batch_id,
            'total_attempted' => count($source_faas_ids),
            'results'  => $results
        );
    }
}
