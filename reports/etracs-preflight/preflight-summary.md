# Preflight Summary Audit Report
## ETRACS Replica → Native Assessor Property Records Pre-Import Audit
**Date**: October 30, 2026  
**Environment**: Local Workspace (`d:\CODE\assessor`) | Database: `assessor_local` | Table Prefix: `wp_`  
**Execution Mode**: READ-ONLY PREFLIGHT (0 Imports Executed, 0 Modifications)

---

## 1. Executive Summary & Recommendation

- **Total Source FAAS Records Examined**: **18,841**
- **Eligible Before Native Duplicate Check**: **18,603** (CURRENT: 9,704 | CANCELLED: 8,899)
- **Excluded Native Duplicates**: **10,762**
- **Remaining Candidate Population**: **7,841**
  - **With Valid RPU**: **7,839**
  - **Missing RPU**: **2**
- **Final Readiness Classification**:
  - **`READY`**: **7,179** (91.56%)
  - **`READY_WITH_WARNINGS`**: **379** (4.83%)
  - **`BLOCKED`**: **281** (3.58%) — Unapproved location name discrepancy in Barangay Lorega
  - **`SKIPPED_NO_RPU`**: **2** (0.03%)
- **Importable Population (Ready + Ready With Warnings)**: **7,558** (96.39%)
- **Blocked / Skipped Population**: **283** (3.61%)

### Preflight Final Recommendation:
> **`NOT READY — BLOCKING ISSUES REMAIN`**
>
> While **7,558** properties are technically ready for safe insertion without data truncation or schema violations, **281 records in Barangay Lorega remain strictly BLOCKED** due to an unapproved location discrepancy against native location ID `63` (*"LOREGA / NAPALICO"*), and **2 records are SKIPPED** due to missing RPU records. An administrator must explicitly review and approve the Lorega location crosswalk via the Crosswalk Audit UI before those 281 records can become eligible for import.

---

## 2. Source Candidate Population Reconciliation

| Population Stage | Count | Discrepancy vs Historical Baseline | Technical Explanation |
| :--- | :---: | :---: | :--- |
| **All Source FAAS Rows** | **18,841** | 0 | Unchanged replica count |
| **All Source REPLICA Origin Rows** | **18,841** | 0 | All source FAAS rows are marked origin_type = 'REPLICA' |
| **Eligible CURRENT with Non-blank TD** | **9,704** | 0 | Matches baseline |
| **Eligible CANCELLED with Non-blank TD**| **8,899** | 0 | Matches baseline |
| **Eligible Before Native Duplicate Check**| **18,603** | 0 | $9,704 + 8,899 = 18,603$ |
| **Existing Native TD Duplicates** | **10,762** | 0 | 10,762 source TDs already match `wp_assessor_properties.tax_declaration_number` |
| **Candidates After Native Exclusion** | **7,841** | 0 | $18,603 - 10,762 = 7,841$ |
| **Candidates Backed by Valid RPU** | **7,839** | 0 | $7,841 - 2 = 7,839$ |
| **Candidates Missing RPU** | **2** | 0 | FAAS IDs `FAAS02cbb3e9:1738fb9b951:-7d30` and `FAAS02cbb3e9:1738fb9b951:-7d2e` |
| **Duplicate Source TD Groups** | **0** | 0 | No conflicting duplicate TDs within remaining candidate cohort |
| **Already-Imported Provenance Rows** | **0** | 0 | `wp_assessor_etracs_import_map` is clean (0 records) |

---

## 3. Location Crosswalk Audit

- **Total Source Barangays**: 35
- **Exact Matches (PIN & Name)**: 34
- **Discrepancies**: 1
  - **Source Barangay**: `059-10-024` — **LOREGA** (PIN suffix `24`)
  - **Native Location ID**: `63` — **LOREGA / NAPALICO** (PIN `024`)
  - **Match Status**: `REVIEW_NAME_MISMATCH`
  - **Approval Status**: **UNAPPROVED** (`wp_assessor_etracs_crosswalk_approvals` count = 0)
  - **Affected Candidates**: **281**
  - **Enforcement Action**: **100% of these 281 records are marked BLOCKED** in the preflight audit.

---

## 4. General Class and Property Type Lookups

### General Class Mapping (Native `wp_assessor_general_classes`)
- **Total Mapped Classes**: 15 ETRACS codes mapped to 14 active native general classes.
- **Candidate Distribution**:
  - `AGR` $\rightarrow$ `AGRICULTURAL`: 4,536
  - `RES` $\rightarrow$ `RESIDENTIAL`: 2,128
  - `COM` $\rightarrow$ `COMMERCIAL`: 538
  - `RROW` $\rightarrow$ `RROW`: 283
  - `EDU` $\rightarrow$ `EDU`: 140
  - `GOV` $\rightarrow$ `GOV`: 66
  - `T` / `TIM` $\rightarrow$ `TIMBERLAND_FORESTAL`: 66
  - `SPC` $\rightarrow$ `SPECIAL`: 39
  - `MIN` $\rightarrow$ `MINERAL`: 16
  - `REL` $\rightarrow$ `REL`: 15
  - `GOCC` $\rightarrow$ `GOCC`: 4
  - `IND` $\rightarrow$ `INDUSTRIAL`: 4
  - `CL` $\rightarrow$ `CL`: 2
  - `IMP` $\rightarrow$ `IMPROVEMENTS`: 1
  - `RP&LGU` (Unmapped): **1** (TD `22-010-0015-00055`) $\rightarrow$ Mapped to `NULL` with warning `UNMAPPED_GENERAL_CLASS`
  - Missing RPU (NULL classification): **2**

### Property Type Mapping (Native `wp_assessor_property_types`)
- All 5 active property types exist in `wp_assessor_property_types`:
  - `land` $\rightarrow$ `LAND`: 6,305
  - `bldg` $\rightarrow$ `BUILDING`: 1,248
  - `planttree` $\rightarrow$ `PLANT_TREES`: 111
  - `misc` $\rightarrow$ `IMPROVEMENTS`: 110
  - `mach` $\rightarrow$ `MACHINERY`: 65
  - Missing RPU: 2

---

## 5. Effectivity Exemption Verification (#39)

- **Authoritative Column**: `wp_assessor_rpu.taxable`
- **Exempt Candidates (`taxable = 0` $\rightarrow$ `effectivity_exempt = 1`)**: **1,531**
- **Taxable Candidates (`taxable = 1` $\rightarrow$ `effectivity_exempt = 0`)**: **6,308**
- **Missing Exemption Flags**: **0**
- **Ambiguous Exemption Codes**: **0**
- **Conflicting Combinations**: **0**

---

## 6. Revision Year & revision_id Resolution (#33)

- **Native Revision Records Checked**: All 9 active revisions in `wp_assessor_revision_entries` (`status = 'active'`).
- **Successfully Matched to Active Revision ID**: **7,840** (99.99%)
- **Unresolved Revision Year**: **1** candidate
  - **TD Number**: `22-010-0001-01387`
  - **Source Value**: `effectivityyear = '202'` (malformed 3-digit year)
  - **Resolution**: `revision_id = NULL`, flagged with non-blocking warning `NO_MATCHING_REVISION`.

---

## 7. Assessor & Signatory Configuration (#26–#31)

- **Source**: Canonical settings in `wp_assessor_settings`.
- **Configured Values**:
  - `verifier_signatory_name`: `REYNALDO D. AMPATIN, REA` (len 24 / limit 255)
  - `verifier_signatory_title`: `LAOO - I` (len 8 / limit 255)
  - `municipal_assessor_name`: `ROEL H. MOLO` (len 12 / limit 255)
  - `municipal_assessor_suffix`: `MMREM, REA, REB, LPT` (len 20 / limit 255)
  - `municipal_assessor_title`: `MUNICIPAL ASSESSOR` (len 18 / limit 255)
  - `municipal_assessor_license`: `0029202` (len 7 / limit 255)
- **Status**: 100% configured, fits within native schema limits.

---

## 8. Field-Length & Data Constraints Audit

| Field Checked | Native Limit | Max Candidate Length | Overlength Records | Evaluation |
| :--- | :---: | :---: | :---: | :--- |
| `tax_declaration_number` | varchar(100) | 17 | 0 | PASS |
| `previous_tax_declaration_number` | varchar(100) | 17 | 0 | PASS |
| `declarant_last_name` | varchar(255) | 214 | 0 | PASS |
| `business` | varchar(200) | 80 | 0 | PASS |
| `lot_number` | varchar(100) | 41 | 0 | PASS |
| `survey_number` | varchar(100) | 39 | 0 | PASS |
| `title_number` | varchar(100) | 26 | 0 | PASS |
| `pin` | varchar(100) | 18 | 0 | PASS |
| `memoranda` | text (65,535 bytes)| 1,812 chars | 0 | PASS |

### Numeric and Decimal Validations
- **Numeric Zero Assessed Value (`0.00`)**: **144** records (valid numeric zero preserved; flagged with warning).
- **NULL Assessed Value**: **2** records (candidates missing RPU).
- **Negative Assessed Values**: **0** records.
- **Max Assessed Value**: ₱127,273,000.00 (fits in `decimal(15,2)` limit of 9,999,999,999,999.99).
- **Numeric Zero Area**: **291** records (flagged with warning).
- **Negative Area**: **0** records.

---

## 9. Generated Diagnostic Files

All detailed audit outputs have been generated in `reports/etracs-preflight/`:
1. `reports/etracs-preflight/preflight-summary.md` (this report)
2. `reports/etracs-preflight/candidate-readiness.csv` (7,841 candidate rows with final status, warnings, and blockers)
3. `reports/etracs-preflight/source-to-native-field-audit.csv` (Detailed field-by-field mapping trace)
4. `reports/etracs-preflight/location-crosswalk-audit.csv` (All 35 barangays, matching status, and affected candidate counts)
5. `reports/etracs-preflight/warning-register.csv` (Complete register of non-blocking warnings)
6. `reports/etracs-preflight/blocking-records.csv` (Complete register of all 283 blocked/skipped candidates with exact reasons)
