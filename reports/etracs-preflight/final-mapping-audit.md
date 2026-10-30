# Final Mapping Audit & Pre-Import Verification Report
## ETRACS Replica → Native Assessor Property Records Importer
**Date**: October 30, 2026  
**Local Workspace**: `d:\CODE\assessor`  
**Database**: `assessor_local` | Table Prefix: `wp_`  
**Mode**: READ-ONLY VERIFICATION (0 Imports Executed, 0 Database Writes)

---

## 1. Executive Summary & Verification Outcome

This audit confirms that all agreed source-to-native property mapping rules are fully verified in the local codebase and database schema:

1. **Deprecated `unique_lot_number_identified` Removed**:
   - The native column `wp_assessor_properties.unique_lot_number_identified` has been classified as `LEGACY_DEPRECATED`.
   - In both the server mapper (`map_candidate_record()`) and the database insertion pipeline (`import_batch()`), `unique_lot_number_identified` is explicitly mapped to `NULL`.
   - The field is completely omitted from imported business values, preview displays, and CSV exports.
2. **`lot_number` and `survey_number` Completely Independent**:
   - `wp_assessor_properties.lot_number` $\leftarrow$ `wp_assessor_real_property.cadastrallotno` (verbatim string, max len 41).
   - `wp_assessor_properties.survey_number` $\leftarrow$ `wp_assessor_real_property.surveyno` (verbatim string, max len 39).
   - Example verified: `cadastrallotno = '72-D'`, `surveyno = 'PSD-10-025360(AR)'` $\rightarrow$ `lot_number = '72-D'`, `survey_number = 'PSD-10-025360(AR)'`, `unique_lot_number_identified = NULL`.
3. **Effectivity Year & Revision Rules Preserved**:
   - User decision to ignore previously reported malformed effectivity year `'202'` (TD `22-010-0001-01387`) is respected.
   - Preserves existing approved mapping (`f.effectivityyear` $\rightarrow$ `effectivity_date`).
   - Existing revision resolution safely leaves `revision_id = NULL` with non-blocking warning `NO_MATCHING_REVISION`. No fabricated revisions or remediation blockers were introduced.
4. **Current Candidate Population & Readiness**:
   - Total Candidate Cohort: **7,841**
   - **`READY`**: **7,179** (91.56%)
   - **`READY_WITH_WARNINGS`**: **379** (4.83%)
   - **`BLOCKED`**: **281** (3.58%) — Unapproved location name discrepancy in Barangay Lorega
   - **`SKIPPED_NO_RPU`**: **2** (0.03%)
   - Total Potentially Importable: **7,558** (96.39%)

### Final Recommendation:
### **`NOT READY — BLOCKING ISSUES REMAIN`**

> **Reason**: The codebase and mappings are 100% prepared and verified for the first import. However, **281 records in Barangay Lorega remain strictly BLOCKED** pending administrator approval of the location crosswalk (`059-10-024 LOREGA` $\rightarrow$ `63 LOREGA / NAPALICO`). Once the administrator reviews and approves this crosswalk in the Crosswalk Audit UI, all 7,839 RPU-backed properties will be importable.

---

## 2. All 39 Native Columns & Final Dispositions

| # | Native Column Name | Native Data Type & Length | Final Disposition | Source Origin & Mapping Logic |
| :---: | :--- | :--- | :---: | :--- |
| 1 | `id` | `varchar(36) NOT NULL` | `SYSTEM_GENERATED` | UUIDv7 generated per inserted record |
| 2 | `tax_declaration_number` | `varchar(100) NOT NULL` | `SOURCE_MAPPED` | `wp_assessor_faas.tdno` verbatim (max len: 17) |
| 3 | `previous_tax_declaration_number` | `varchar(100) NULL` | `SOURCE_MAPPED` | `wp_assessor_faas_previous.prevtdno` (fallback `f.prevtdno`, max len: 17) |
| 4 | `declarant_last_name` | `varchar(255) NOT NULL` | `SOURCE_MAPPED` | `wp_assessor_faas.owner_name` verbatim (max len: 214) |
| 5 | `declarant_first_name` | `varchar(255) NOT NULL` | `APPLICATION_DEFAULT` | Empty string `''` per established convention |
| 6 | `declarant_middle_initial` | `varchar(255) NULL` | `APPLICATION_DEFAULT` | Empty string `''` per established convention |
| 7 | `business` | `varchar(200) NULL` | `SOURCE_MAPPED` | `administrator_name` \| `beneficiary_name` without prefixes (max len: 80) |
| 8 | `location` | `text NOT NULL` | `SOURCE_MAPPED` | Resolved native location name from crosswalk |
| 9 | `lot_number` | `varchar(100) NULL` | `SOURCE_MAPPED` | `wp_assessor_real_property.cadastrallotno` verbatim (max len: 41) |
| 10 | `unique_lot_number_identified` | `varchar(100) NULL` | `LEGACY_DEPRECATED` | Deliberately unused; mapped to `NULL` |
| 11 | `area_hectare` | `decimal(10,4) NULL` | `SOURCE_MAPPED` | `r.total_area_hectare` for AGRICULTURAL (max: 10,155 ha) |
| 12 | `area_hectare_old` | `varchar(255) NULL` | `LEGACY_NULL` | Retained legacy column; deliberately kept `NULL` |
| 13 | `area_sqm` | `decimal(15,2) NULL` | `SOURCE_MAPPED` | `r.total_area_sqm` for non-agricultural (max: 68,810,000 sqm) |
| 14 | `title_number` | `varchar(100) NULL` | `SOURCE_MAPPED` | `wp_assessor_faas.titleno` (max len: 26) |
| 15 | `assessed_value` | `decimal(15,2) NULL` | `SOURCE_MAPPED` | `r.total_assessed_value` (max: ₱127,273,000.00; numeric 0 preserved) |
| 16 | `assessed_value_old` | `varchar(255) NULL` | `LEGACY_NULL` | Retained legacy column; deliberately kept `NULL` |
| 17 | `effectivity_date` | `varchar(20) NULL` | `SOURCE_MAPPED` | `wp_assessor_faas.effectivityyear` verbatim |
| 18 | `pin` | `varchar(100) NULL` | `SOURCE_MAPPED` | `r.fullpin` $\rightarrow$ `rp.pin` $\rightarrow$ `f.fullpin` (max len: 18) |
| 19 | `address` | `text NULL` | `SOURCE_MAPPED` | `wp_assessor_faas.owner_address` verbatim |
| 20 | `assessment_date` | `date NULL` | `SOURCE_MAPPED` | Date substring of `wp_assessor_faas.dtapproved` (`YYYY-MM-DD`) |
| 21 | `kind_of_property` | `varchar(50) NOT NULL` | `SOURCE_MAPPED` | Mapped from `r.rpu_type` (`LAND`, `BUILDING`, etc.) |
| 22 | `gen_class` | `varchar(50) NULL` | `SOURCE_MAPPED` | Mapped from `r.classification` (`AGRICULTURAL`, `RESIDENTIAL`, etc.) |
| 23 | `memoranda` | `text NULL` | `SOURCE_MAPPED` | `wp_assessor_faas.memoranda` verbatim (max len: 1,812 chars) |
| 24 | `supporting_documents` | `text NULL` | `APPLICATION_DEFAULT` | Empty string `''` per established convention |
| 25 | `supporting_documents_old` | `text NULL` | `APPLICATION_DEFAULT` | Empty string `''` per established convention |
| 26 | `verifier_signatory_name` | `varchar(255) NULL` | `SOURCE_MAPPED` | Canonical `Assessor_Settings` (`'REYNALDO D. AMPATIN, REA'`) |
| 27 | `verifier_signatory_title` | `varchar(255) NULL` | `SOURCE_MAPPED` | Canonical `Assessor_Settings` (`'LAOO - I'`) |
| 28 | `municipal_assessor_name` | `varchar(255) NULL` | `SOURCE_MAPPED` | Canonical `Assessor_Settings` (`'ROEL H. MOLO'`) |
| 29 | `municipal_assessor_suffix` | `varchar(255) NULL` | `SOURCE_MAPPED` | Canonical `Assessor_Settings` (`'MMREM, REA, REB, LPT'`) |
| 30 | `municipal_assessor_title` | `varchar(255) NULL` | `SOURCE_MAPPED` | Canonical `Assessor_Settings` (`'MUNICIPAL ASSESSOR'`) |
| 31 | `municipal_assessor_license`| `varchar(255) NULL` | `SOURCE_MAPPED` | Canonical `Assessor_Settings` (`'0029202'`) |
| 32 | `status` | `varchar(50) NOT NULL` | `APPLICATION_DEFAULT` | Set to `'active'` per native requirements |
| 33 | `revision_id` | `varchar(36) NULL` | `SOURCE_MAPPED` | Matched active revision record ID from `wp_assessor_revision_entries` |
| 34 | `created_by` | `varchar(50) NULL` | `SYSTEM_GENERATED` | Authenticated importing user ID |
| 35 | `updated_by` | `varchar(50) NULL` | `SYSTEM_GENERATED` | Authenticated importing user ID |
| 36 | `created_at` | `datetime NULL` | `SYSTEM_GENERATED` | Current MySQL timestamp (`CURRENT_TIMESTAMP`) |
| 37 | `updated_at` | `datetime NULL` | `SYSTEM_GENERATED` | Current MySQL timestamp (`CURRENT_TIMESTAMP`) |
| 38 | `survey_number` | `varchar(100) NULL` | `SOURCE_MAPPED` | `wp_assessor_real_property.surveyno` verbatim (max len: 39) |
| 39 | `effectivity_exempt` | `tinyint(1) NOT NULL` | `SOURCE_MAPPED` | Derived from authoritative `r.taxable` (`0` $\rightarrow$ 1, `1` $\rightarrow$ 0) |

---

## 3. Location Crosswalk & Lorega Status

- **Total Source Barangays**: 35
- **Exact Matches (PIN & Name)**: 34
- **Discrepancy**: Exactly 1
  - **Source Barangay**: `059-10-024` — **LOREGA** (PIN suffix `24`)
  - **Native Location ID**: `63` — **LOREGA / NAPALICO** (PIN `024`)
  - **Status**: `REVIEW_NAME_MISMATCH`
  - **Persistent Approvals**: 0 (`wp_assessor_etracs_crosswalk_approvals` count is 0)
  - **Affected Candidates**: **281** properties
  - **Enforcement**: All 281 records remain strictly **`BLOCKED`**.

---

## 4. Warnings and Blocking Reasons Register

| Code / Reason | Severity | Occurrences | Distinct Records | Impact on Import Eligibility |
| :--- | :---: | :---: | :---: | :--- |
| `LOCATION_NAME_DISCREPANCY` | BLOCKER | 281 | 281 | **Blocks import** until crosswalk approval |
| `Missing or unlinked RPU` | BLOCKER | 2 | 2 | **Skipped** (`SKIPPED_NO_RPU`) |
| `ZERO_APPLICABLE_AREA` | WARNING | 291 | 291 | Non-blocking (0.00 area preserved) |
| `ZERO_ASSESSED_VALUE` | WARNING | 144 | 144 | Non-blocking (₱0.00 AV preserved) |
| `UNMAPPED_GENERAL_CLASS` | WARNING | 1 | 1 | Non-blocking (`RP&LGU` mapped to `NULL`) |
| `NO_MATCHING_REVISION` | WARNING | 1 | 1 | Non-blocking (`revision_id` left `NULL`) |

---

## 5. Exact Files Modified & Verification Results

### Modified Files:
- [`assessor-backend/wp-content/plugins/assessor-api/includes/class-assessor-etracs-native-importer.php`](file:///d:/CODE/assessor/assessor-backend/wp-content/plugins/assessor-api/includes/class-assessor-etracs-native-importer.php):
  - Line 716: Set `'unique_lot_number_identified' => null` in `map_candidate_record()`.
  - Line 1461: Explicitly set `'unique_lot_number_identified' => null` in native property `INSERT` statement within `import_batch()`.

### Test Execution:
- **PHP Syntax Lint**:
  ```bash
  php -l assessor-backend/wp-content/plugins/assessor-api/includes/class-assessor-etracs-native-importer.php
  # Result: No syntax errors detected.
  ```
- **Frontend Build**:
  ```bash
  npm run build
  # Result: Exited with code 0. Both local and production theme bundles published successfully.
  ```

---

## 6. Safety Reconciliation: Database Immutability

Database row counts before and after the audit confirm 100% immutability:
- `wp_assessor_properties`: **12,261** (Unmodified)
- `wp_assessor_faas`: **18,841** (Unmodified)
- `wp_assessor_rpu`: **18,841** (Unmodified)
- `wp_assessor_real_property`: **14,894** (Unmodified)
- `wp_assessor_property_states`: **12,264** (Unmodified)
- `wp_assessor_etracs_import_map`: **0** (Unmodified; zero imports executed)
- `wp_assessor_etracs_crosswalk_approvals`: **0** (Unmodified; zero crosswalk writes)
- `wp_assessor_revision_entries`: **9** (Unmodified; zero revision writes)
