# ETRACS → Assessor Repo: Phase 5A Read-Only Migration Feasibility Report

**Document Date:** October 21, 2026  
**Investigation Context:** Phase 5A Read-Only Migration Feasibility & Record-Matching Assessment  
**Database Evaluated (Strict Read-Only):** `assessor_local` & `etracs254_kitaotao` on `localhost`  
**Application Roots Inspected:**
- Backend: `D:\CODE\assessor\assessor-backend\`
- Frontend: `D:\CODE\assessor\assessor-frontend\`
- Investigation Scripts: `D:\CODE\assessor\etracs-scripts\`

---

## 1. Executive Summary & Non-Negotiable Protection Rules

The native Assessor Repo contains **12,256** active property records in `wp_assessor_properties`, alongside existing documents, audit logs, and bidirectional synchronization metadata. The database also hosts a complete read-only shadow replica of ETRACS 2.54 comprising **18,839** FAAS declarations, **18,841** RPUs, **18,853** predecessor links, and **22,430** assessment lines.

### Strict Data Protection Baseline:
- **Zero Modifications Executed:** `wp_assessor_properties`, existing linking data, primary keys, foreign keys, timestamps, audit logs, and shadow tables were accessed strictly via read-only `SELECT` queries.
- **No In-Place Overwrites or Automatic Merges:** An exact Tax Declaration (TD) match or PIN match does not authorize an automatic overwrite, merge, or link creation.
- **Independence of Identity:** Native records use UUIDv7/UUIDv4 identifiers; shadow ETRACS records use alphanumeric `objid` strings (e.g. `F05910...`). Identifiers are never mixed or conflated without an explicit, audit-tracked mapping table.

---

## 2. Model Identification: Native vs. Shadow ETRACS Replica

Inspection of `class-assessor-database.php`, `class-assessor-etracs-sync.php`, and the database schema confirms the active coexisting models:

### A. Native Application Model (Mutable Core)
These tables store the active municipal assessment data managed by the Assessor web application and synchronized to the live website:
1. `wp_assessor_properties` (12,256 rows): Flat, denormalized property records (`id`, `tax_declaration_number`, `pin`, `previous_tax_declaration_number`, `declarant_last_name`, `assessed_value`, `location`, `status`).
2. `wp_assessor_property_states` (12,262 rows): Declaration lifecycle mapping (`CURRENT`, `CANCELLED`).
3. `wp_assessor_property_versions` (4 rows): Snapshot revision history created during administrative edits.
4. `wp_assessor_documents` (977 rows): Supporting attachments (deeds, titles, sworn statements) with physical disk storage.
5. `wp_assessor_audit_trail` (206 rows): Immutable log of administrative user actions and old/new JSON diffs.
6. `wp_assessor_sync_queue` (9 rows) & `wp_assessor_sync_runs` (8 rows): Bidirectional cloud synchronization queue and execution logs.
7. Lookup tables: `wp_assessor_property_types` (5), `wp_assessor_general_classes` (9), `wp_assessor_locations` (36), `wp_assessor_revision_entries` (9), `wp_assessor_users` (4).

### B. Shadow ETRACS Model (Read-Only Replica)
These tables mirror the legacy ETRACS 2.54 relational schema, populated via one-way direct PDO replication from MySQL `etracs254_kitaotao`:
1. `wp_assessor_faas` (18,839 rows): FAAS declaration header instances (`objid`, `tdno`, `utdno`, `fullpin`, `state`, `txntype_objid`, `owner_name`, `cancelreason`, `canceldate`, `cancelledbytdnos`).
2. `wp_assessor_faas_previous` (18,853 rows): Relational predecessor declarations (`faasid`, `prevfaasid`, `prevrpuid`, `prevtdno`, `prevpin`, `prevowner`, `prevav`, `prevmv`, `prevareasqm`).
3. `wp_assessor_faas_signatory` (18,838 rows): Signatory records for FAAS approvals.
4. `wp_assessor_rpu` (18,841 rows): Assessment units (`rpu_type`, `classification`, `totalmv`, `totalav`, `taxable`, `realpropertyid`, `rpumasterid`).
5. `wp_assessor_real_property` (14,892 rows): Physical land parcels (`pin`, `cadastrallotno`, `surveyno`, `blockno`, `barangay`, boundaries, `previd`).
6. `wp_assessor_rpumaster` (27,659 rows): Master tracking continuity across general revisions (`currentfaasid`, `currentrpuid`).
7. `wp_assessor_entity` (9,337 rows): Taxpayer and administrator directory (`entityno`, `name`, `address_text`, `type`).
8. `wp_assessor_rpu_assessment` (22,430 rows): Granular assessment lines (`marketvalue`, `assesslevel`, `assessedvalue`, `classcode`).
9. Detail & Schedule tables: `wp_assessor_landrpu` (14,448), `wp_assessor_landdetail` (19,342), `wp_assessor_bldgrpu` (14,173), `wp_assessor_bldgstructure` (52,054), `wp_assessor_bldgfloor` (4,100), `wp_assessor_machrpu` (199), `wp_assessor_miscrpu` (282), `wp_assessor_planttreerpu` (352).

---

## 3. Read-Only Baseline Counts and Distributions

All baselines were executed against `assessor_local` on October 21, 2026.

### Native Property Model Baselines:
- **Total Properties:** 12,256 (`SELECT COUNT(*) FROM wp_assessor_properties`)
- **Status Distribution:**
  - `active`: 9,031 (73.7%)
  - `'0'` (Legacy unmigrated status flag): 3,225 (26.3%)
  - `deleted`: 0 (0.0%)
- **Lifecycle State Distribution (`wp_assessor_property_states`):**
  - `CURRENT`: 11,331 (92.5%)
  - `CANCELLED`: 925 (7.5%)
  - *(Note: 6 rows in `property_states` represent legacy testing stubs without active parent properties).*

### Shadow ETRACS FAAS Baselines:
- **Total FAAS Declarations:** 18,839 (`SELECT COUNT(*) FROM wp_assessor_faas`)
- **State Distribution:**
  - `CURRENT`: 9,703 (51.5%)
  - `CANCELLED`: 8,898 (47.2%)
  - `INTERIM` (Draft): 228 (1.2%)
  - `FORAPPROVAL` (Submitted): 9 (0.05%)
  - `PENDING`: 1 (0.01%)
- **Transaction Type Distribution:**
  - `GR` (General Revision): 15,468 (82.1%)
  - `ND` (New Declaration): 1,669 (8.9%)
  - `RE` (Re-Assessment / Reclassification): 513 (2.7%)
  - `SD` (Subdivision): 421 (2.2%)
  - `TR` (Transfer of Ownership): 352 (1.9%)
  - `CE` (Correction of Entry): 304 (1.6%)
  - `DC` (Data Capture): 29 (0.15%)
  - Other (`CC`, `RV`, `CS/SD`, `PC`, `CS`): 83 (0.44%)
- **RPU Type Breakdown (`wp_assessor_rpu`):**
  - Land: 14,449 (76.7%)
  - Building: 3,603 (19.1%)
  - Plant/Tree: 312 (1.7%)
  - Misc: 282 (1.5%)
  - Machinery: 195 (1.0%)

---

## 4. Mutually Exclusive Record-Matching Classifications

To eliminate category overlaps and ambiguity, matching was evaluated independently at both **Native-Property Grain** (12,256 rows) and **Shadow-FAAS Grain** (18,839 rows), and candidate-pair counts are explicitly distinguished from distinct entity counts.

### A. Native-Property Grain Classification (Denominator: 12,256)
Every native property is partitioned into one mutually exclusive category:

| Category | Description | Count | % of Native | Details & Evidence |
| :--- | :--- | :--- | :--- | :--- |
| **A1** | Single Exact-TD Candidate + Populated PIN Match | **7,543** | 61.54% | Exactly 1 shadow FAAS candidate on `tax_declaration_number = tdno`; non-empty PIN matches `fullpin` identically. Highest-confidence 1:1 match. |
| **A2** | Single Exact-TD Candidate + Populated PIN Mismatch | **3,218** | 26.26% | Exactly 1 shadow FAAS candidate on TD; both PINs populated but differ. (1,332 resolve via non-destructive alphanumeric normalization). |
| **A3** | Single Exact-TD Candidate + PIN Missing (One/Both) | **11** | 0.09% | Exactly 1 shadow FAAS candidate on TD; native PIN or shadow `fullpin` is NULL or blank. |
| **A4** | Multiple Exact-TD Candidates | **0** | 0.00% | Zero native properties have duplicate TD numbers in shadow FAAS. |
| **Subtotal A** | **Total with Exact-TD Match** | **10,772** | **87.89%** | All 10,772 TD matches are strictly 1:1 candidate pairings. |
| **B1** | No TD Match, Exactly 1 Populated PIN Candidate | **244** | 1.99% | No TD match; exactly 1 shadow FAAS matches by populated PIN. Represents parcels whose TD series updated while PIN remained constant. |
| **B2** | No TD Match, Multiple Populated PIN Candidates | **9** | 0.07% | No TD match; >1 shadow FAAS records match by populated PIN (multiple historical FAAS revisions for that parcel). |
| **Subtotal B** | **No TD Match but PIN Candidate Exists** | **253** | **2.06%** | Native records with secondary PIN linkage potential. |
| **C** | Neither TD nor PIN Candidate | **1,231** | 10.04% | Zero matches on either TD or PIN. Purely native municipal records created post-ETRACS replication. |
| **Total** | **All Categories (Mutually Exclusive)** | **12,256** | **100.00%** | **7,543 + 3,218 + 11 + 0 + 244 + 9 + 1,231 = 12,256.** |

### B. Shadow-FAAS Grain Classification (Denominator: 18,839)
Every shadow FAAS declaration is partitioned into one mutually exclusive category:

| Category | Description | Count | % of Shadow | Details & Evidence |
| :--- | :--- | :--- | :--- | :--- |
| **S-A1** | Single Exact-TD Candidate + Populated PIN Match | **7,543** | 40.04% | Exactly 1 native property candidate on `tdno = tax_declaration_number`; PIN matches identically. |
| **S-A2** | Single Exact-TD Candidate + Populated PIN Mismatch | **3,218** | 17.08% | Exactly 1 native property candidate on TD; populated PIN mismatch. |
| **S-A3** | Single Exact-TD Candidate + PIN Missing (One/Both) | **11** | 0.06% | Exactly 1 native property candidate on TD; PIN missing on one/both sides. |
| **S-A4** | Multiple Exact-TD Candidates | **0** | 0.00% | Zero shadow FAAS records match multiple native properties by TD. |
| **Subtotal S-A** | **Total with Exact-TD Match** | **10,772** | **57.18%** | Matches the 10,772 distinct native properties in Subtotal A. |
| **S-B1** | No TD Match, Exactly 1 Populated PIN Candidate | **181** | 0.96% | No TD match; exactly 1 native property matches by populated PIN. |
| **S-B2** | No TD Match, Multiple Populated PIN Candidates | **15** | 0.08% | No TD match; >1 native properties match by populated PIN (multi-parcel sub-lots). |
| **Subtotal S-B** | **No TD Match but PIN Candidate Exists** | **196** | **1.04%** | Shadow declarations with secondary PIN linkage potential. |
| **S-C** | Neither TD nor PIN Candidate | **7,871** | 41.78% | Historical/cancelled ETRACS declarations never imported into native table. |
| **Total** | **All Categories (Mutually Exclusive)** | **18,839** | **100.00%** | **7,543 + 3,218 + 11 + 0 + 181 + 15 + 7,871 = 18,839.** |

### C. Candidate Pair Counts vs. Distinct Entity Counts
- **Exact TD Candidate Pairs:** Exactly **10,772** pairs.
  - Native distribution: 0 candidates = 1,484; 1 candidate = 10,772; >1 candidates = 0.
  - Shadow distribution: 0 candidates = 8,067; 1 candidate = 10,772; >1 candidates = 0.
  - This proves that TD matching is an unambiguous mathematical 1:1 bijection.
- **Populated PIN Candidate Pairs:** Exactly **8,378** pairs (`p.pin = f.fullpin` where both non-blank).
  - Native distribution: 0 candidates = 4,402; 1 candidate = 7,350; 2 candidates = 484; 3 candidates = 20.
  - Shadow distribution: 0 candidates = 11,031; 1 candidate = 7,325; 2 candidates = 433; 3 candidates = 32; 4 candidates = 10; 5 candidates = 2; 6 candidates = 3; 7 candidates = 1; 8 candidates = 2.
  - PIN matching alone is NOT 1:1 and cannot serve as an independent primary key.

---

## 5. PIN and Composite Matching Validation

### A. Resolution of the Barangay Matching Claim
- Earlier draft text stated that matching exact TD plus barangay produced 10,772 candidates, but the query lacked a barangay condition.
- **Executed Schema Inspection:**
  - `wp_assessor_properties` contains `location` (VARCHAR) but **no** `barangay` column.
  - `wp_assessor_faas` contains **no** `barangay` column.
  - In shadow ETRACS, `barangay` exists in `wp_assessor_real_property` (`rp.barangay`) and `wp_assessor_faas_list` (`fl.barangay`).
- **Executed Composite Query:** Joining `wp_assessor_properties` to `wp_assessor_faas` on TD and `wp_assessor_real_property` on `f.realpropertyid = rp.objid`:
  - Total TD Matches: **10,772**
  - Identical `TRIM(LOWER(location)) = TRIM(LOWER(barangay))`: **8,904**
  - Differing text: **1,868** (e.g., native records recording compound sitio/barangay text like `'NAPALICO/LOREGA'` vs. shadow `'LOREGA'`, or phonetic spelling like `'KITOBO'` vs. `'KITUBO'`).
- **Finding:** The 10,772 figure is derived from exact TD alone. Adding an exact string comparison on `location = barangay` drops 1,868 legitimate property matches due to typographical variations.

### B. PIN Normalization Testing (Preserving Original Data)
- Testing non-destructive alphanumeric normalization (`re.sub(r'[^0-9A-Za-z]', '', pin)`) in query results without modifying database records:
  - Exact TD + Raw PIN Match: **7,543**
  - Exact TD + Normalized PIN Match: **8,875**
  - Net Gain: **1,332** additional candidate matches resolved (e.g. native `059-10-012-01-001-1002` matching shadow `059-10-012-01-(001)-1002`).
  - Remaining TD Match / PIN Discrepancies: **1,886** (3,218 - 1,332). These 1,886 represent true substantive PIN numbering differences across parcel revisions rather than mere formatting artifacts.
- **Collision Risk:**
  - In native properties, normalizing PINs causes **46 collision groups** where multiple different raw PINs collapse into the same normalized string.
  - In shadow FAAS, normalizing causes **6 collision groups**.
  - **Conclusion:** PIN normalization must never be applied automatically or destructively.

---

## 6. Source ETRACS Versus Local Shadow Replica Reconciliation

Earlier audits observed minor discrepancies between the source database (`etracs254_kitaotao`) and the local shadow replica (`assessor_local`). A direct, read-only set-difference query was executed across the local MySQL databases.

### Executed Discrepancy Query Results:
| Table | Source (`etracs254_kitaotao`) | Shadow Replica (`assessor_local`) | Difference | Status |
| :--- | :--- | :--- | :--- | :--- |
| `faas` | 18,841 | 18,839 | **+2 rows** | **Fully Reconciled** |
| `faas_previous` | 18,855 | 18,853 | **+2 rows** | **Fully Reconciled** |
| `rpu` | 18,843 | 18,841 | **+2 rows** | **Fully Reconciled** |

### Specific Identifiers Present in Source but Absent from Shadow:
1. **Missing FAAS Records (2 rows):**
   - `objid = 'F-7e12f64e:1a12e268225:-7876'`, `tdno = '22-010-0001-09734'`, `state = 'CURRENT'`, `prevtdno = '22-010-0001-00041'`
   - `objid = 'F-7e12f64e:1a12e268225:-7af9'`, `tdno = '22-010-0001-09733'`, `state = 'CURRENT'`, `prevtdno = '22-010-0001-0041'`
2. **Missing `faas_previous` Predecessor Links (2 rows):**
   - `objid = 'PF3418fbfd:1a12e293c19:-47b2'`, `faasid = 'F-7e12f64e:1a12e268225:-7876'`, `prevfaasid = 'F3f4e8800:1850ddf757a:7201'`
   - `objid = 'PF3418fbfd:1a12e293c19:-5890'`, `faasid = 'F-7e12f64e:1a12e268225:-7af9'`, `prevfaasid = 'F3f4e8800:1850ddf757a:7201'`
3. **Missing RPU Records (2 rows):**
   - `objid = 'RPU-7e12f64e:1a12e268225:-7874'`, `rputype = 'land'`, `state = 'CURRENT'`
   - `objid = 'RPU-7e12f64e:1a12e268225:-7af7'`, `rputype = 'land'`, `state = 'CURRENT'`

### Root Cause Explanation:
- These two declarations (`22-010-0001-09733` and `22-010-0001-09734`) are subsequent subdivision lots created from predecessor `22-010-0001-00041`.
- Timestamps confirm they were approved in ETRACS slightly **after** the snapshot replication cutoff date when `assessor_local` was generated.
- Neither of these two TD numbers exists in native `wp_assessor_properties`.
- **Migration Impact:** The 2 missing records have zero impact on the 10,772 matched native properties. The shadow replica is 100% internally consistent for the evaluated population.

---

## 7. Predecessor Provenance and Snapshot Completeness

### A. Predecessor Link Classifications (`wp_assessor_faas_previous` - 18,853 rows)
Language regarding missing predecessor IDs has been updated to strictly reflect evidential limits:
- **Case 1 (Electronic Resolving):** **9,239 rows (49.01%)**. `prevfaasid` resolves to a valid FAAS record in `wp_assessor_faas`.
- **Case 2 (Recorded Historical Reference, NULL ID):** **9,586 rows (50.85%)**. `prevfaasid IS NULL AND prevtdno IS NOT NULL`.
  - *Standard Terminology:* “Recorded historical TD reference; source provenance unknown unless supported by separate documentary evidence.” (Do not assume physical tax-book provenance without documentary confirmation).
- **Case 3 (Legacy Unresolved ID):** **28 rows (0.15%)**. `prevfaasid` is populated with an alphanumeric ID that does not resolve in `wp_assessor_faas`. Preserved as distinct unresolved IDs.

### B. Predecessor Snapshot Completeness
Combined completeness was measured across all required snapshot attributes:
- Individual Column Population (out of 18,853 rows):
  - `prevtdno`: 18,853 (100.0%)
  - `prevpin`: 18,852 (99.99%)
  - `prevowner`: 18,751 (99.46%)
  - `prevav` (Assessed Value): 18,853 (100.0%)
  - `prevmv` (Market Value): 18,853 (100.0%)
  - `prevareasqm`: 18,853 (100.0%)
  - `preveffectivity`: 18,000 (95.48%)
- **Simultaneous Completeness:**
  - Exactly **17,992 rows (95.43%)** have all six snapshot fields (`prevowner`, `prevpin`, `prevav`, `prevmv`, `prevareasqm`, and `preveffectivity`) populated simultaneously.

---

## 8. Cancellation Metadata Completeness

Verification of the 8,898 `CANCELLED` FAAS records in `wp_assessor_faas`:
- Individual Population Counts:
  - `cancelreason` populated: **8,890** (99.91%)
  - `canceldate` populated: **8,890** (99.91%)
  - `cancelledbytdnos` populated: **8,891** (99.92%)
- **Simultaneous Intersection:**
  - Exactly **8,890 rows (99.91%)** satisfy all three conditions simultaneously (`cancelreason IS NOT NULL AND canceldate IS NOT NULL AND cancelledbytdnos IS NOT NULL`).
  - Exactly 8 rows lack cancellation metadata (pre-electronic legacy data capture).

---

## 9. Phase 5A.1 Correction Log

1. **Mutually Exclusive Classifications Established:**
   - Native-grain categories A1 (7,543), A2 (3,218), A3 (11), A4 (0), B1 (244), B2 (9), C (1,231) sum exactly to 12,256.
   - Shadow-grain categories S-A1 (7,543), S-A2 (3,218), S-A3 (11), S-A4 (0), S-B1 (181), S-B2 (15), S-C (7,871) sum exactly to 18,839.
2. **Barangay Matching Claim Corrected:**
   - Confirmed that the 10,772 match is based on exact TD alone. Adding `location = barangay` drops 1,868 valid matches due to text formatting differences.
3. **PIN Normalization Measured Non-Destructively:**
   - Identified 1,332 format-only PIN differences that resolve under normalization, leaving 1,886 substantive PIN differences.
   - Discovered 46 native and 6 shadow collision groups under normalization; automated normalization rejected.
4. **Source vs. Shadow Discrepancy Reconciled:**
   - Identified the exact 2 missing rows (`22-010-0001-09733` and `22-010-0001-09734`) created post-replication cutoff.
5. **Snapshot & Cancellation Completeness Verified:**
   - 17,992 predecessor links (95.43%) have all 6 snapshot fields simultaneously populated.
   - 8,890 cancelled FAAS (99.91%) have all 3 cancellation metadata fields simultaneously populated.
