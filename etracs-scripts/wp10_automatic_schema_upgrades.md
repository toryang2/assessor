# WP-10 — Canonical Database Creation & Scoped Compatibility Specification (Hardened)

## 1. Overview & Objective

The Assessor system requires a conventional, authoritative database lifecycle:
- **Fresh installations:** `Assessor_Database::create_tables()` creates the canonical Shadow ETRACS tables and their complete schema.
- **Existing installations:** Automatically detect whether the Shadow ETRACS schema needs a one-time compatibility repair, without relying exclusively on `ASSESSOR_API_VERSION` (which remains `1.1.17`).
- **Normal boots and deployments:** Do not repeatedly run Migration 008 or execute unnecessary DDL.

---

## 2. Dedicated Shadow Schema Marker Lifecycle

A dedicated WordPress option and schema constant govern the Shadow ETRACS compatibility lifecycle:
- Constant: `define('ASSESSOR_SHADOW_SCHEMA_VERSION', '1.0.0');` in [`assessor-api.php`](file:///d:/CODE/assessor/assessor-backend/wp-content/plugins/assessor-api/assessor-api.php)
- WordPress Option: `assessor_shadow_schema_version`

### Lifecycle Touchpoints

1. **Normal Boot (`plugins_loaded` -> `assessor_api_check_version`):**
   - Check marker: `get_option('assessor_shadow_schema_version') === ASSESSOR_SHADOW_SCHEMA_VERSION`.
   - **Marker Current:** Skip all schema checks and migration logic entirely. Zero recurring DDL overhead.
   - **Marker Missing or Outdated:**
     1. Run deep schema validation (`validate_migration_008_schema()`).
     2. If valid, reconcile migration status (`mark_migration_completed_if_valid()`) and update `assessor_shadow_schema_version` to `1.0.0`.
     3. If invalid or partial, run scoped compatibility repair once (`run_automatic_schema_upgrades()`).
     4. Re-validate schema. Update marker only if post-repair validation succeeds. If validation or repair fails, retain the outdated marker and log the diagnostic error for subsequent retry.

2. **Fresh Plugin Activation (`register_activation_hook` -> `assessor_api_activate`):**
   - Runs `Assessor_Database::create_tables()` to install all canonical tables.
   - Reconciles Migration 008 status via `mark_migration_completed_if_valid()`, falling back to scoped repair if needed.
   - Updates `assessor_shadow_schema_version` to `1.0.0` and `assessor_db_version` to `1.1.17`.

---

## 3. Deep Schema Validation & Safe Repair

### A. Deep Validation Matrix ([`validate_migration_008_schema`](file:///d:/CODE/assessor/assessor-backend/wp-content/plugins/assessor-api/includes/class-assessor-migration-runner.php))

Schema validation inspects `INFORMATION_SCHEMA` against canonical definitions:
- **`wp_assessor_machine`:**
  - Columns: `objid`, `state`, `code`, `name`.
  - Primary Key: `PRIMARY (objid)`.
  - Indexes: `ux_machine_code` (UNIQUE on `code`), `ux_machine_name` (UNIQUE on `name`), `ix_machine_state` (KEY on `state`).
- **`wp_assessor_planttree`:**
  - Columns: `objid`, `state`, `code`, `name`.
  - Primary Key: `PRIMARY (objid)`.
  - Indexes: `ux_planttree_code` (UNIQUE on `code`), `ux_planttree_name` (UNIQUE on `name`), `ix_planttree_state` (KEY on `state`).
- **`wp_assessor_planttreedetail`:**
  - Columns: All 18 canonical columns (`objid`, `planttreerpuid`, `landrpuid`, `planttreeunitvalue_objid`, `planttree_objid`, `actualuse_objid`, `productive`, `nonproductive`, `nonproductiveage`, `unitvalue`, `basemarketvalue`, `adjustment`, `adjustmentrate`, `marketvalue`, `assesslevel`, `assessedvalue`, `areacovered`, `origin_type`).
  - Primary Key: `PRIMARY (objid)`.
  - Indexes: `ix_planttreedetail_landrpuid` (on `landrpuid`), `ix_planttreedetail_planttreerpuid` (on `planttreerpuid`), `ix_planttreedetail_planttree_objid` (on `planttree_objid`), `idx_origin_type` (on `origin_type`).
- Verifies column ordering in composite indexes and matches `NON_UNIQUE = 0` for uniqueness.

### B. Safe Compatibility Repair ([`migrate_property_assessed_descriptions_schema`](file:///d:/CODE/assessor/assessor-backend/wp-content/plugins/assessor-api/includes/class-assessor-database.php))

- Missing primary keys are safely added with null/duplicate pre-checks.
- Duplicate data is checked prior to adding unique constraints. If duplicates exist, the repair aborts safely with an informative error rather than dropping or corrupting data.
- Never drops or recreates existing tables.
- Portable paths: Removed all hard-coded XAMPP paths (`C:/xampp/htdocs`). Uses `ABSPATH`, `WP_CONTENT_DIR`, or `WP_ADMIN_DIR`, reporting explicit errors if `dbDelta()` cannot be loaded.

---

## 4. Test Matrix & Verification

Executed against isolated disposable test database `test_wp10_disposable`:

| Scenario | Tested Lifecycle / State | Result |
| :--- | :--- | :--- |
| **Scenario 1 & 2** | Fresh installation via `assessor_api_activate()`: canonical tables created, migration recorded completed, schema marker updated to `1.0.0` | **PASS** (9 assertions) |
| **Scenario 3** | Existing installation with complete schema: subsequent boots skip repairs, timestamps unchanged, zero DDL executed | **PASS** (5 assertions) |
| **Scenario 4** | Existing installation at plugin version `1.1.17` with missing marker and missing shadow tables: automatically detected and repaired on boot | **PASS** (4 assertions) |
| **Scenario 4B** | Existing installation with valid schema & missing marker: marked current without running DDL repair, status reconciled | **PASS** (2 assertions) |
| **Scenario 5** | Partial schema repair: restores missing columns/indexes while preserving pre-existing rows and backfilling `origin_type` | **PASS** (4 assertions) |
| **Scenario 6** | Native Assessor schemas and records remain completely untouched | **PASS** (2 assertions) |
| **Scenario 7** | Data pull isolation: Shadow lookup tables have 0 rows; no ETRACS contact triggered | **PASS** (3 assertions) |
| **Scenario 8** | Failure handling & retry: duplicate data safely halts repair without data loss, retains outdated marker, succeeds upon retry | **PASS** (4 assertions) |
| **Total** | **All 8 Lifecycle Scenarios Verified** | **PASS (33/33 assertions)** |
