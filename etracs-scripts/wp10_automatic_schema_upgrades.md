# WP-10 — Canonical Database Creation & Scoped Compatibility Specification

## 1. Overview & Objective

The Assessor system requires a unified, authoritative schema creation workflow. Instead of treating Migration 008 as a recurring operational procedure executed on every deployment, the complete Shadow ETRACS schema is now incorporated directly into the standard `Assessor_Database::create_tables()` lifecycle.

For fresh installations, normal plugin activation creates all required tables—including `wp_assessor_machine`, `wp_assessor_planttree`, and `wp_assessor_planttreedetail` with full indexes and `origin_type` ownership protection—in a single standard operation.

For existing deployments created under older releases or containing partial schemas, a narrowly scoped compatibility path automatically verifies and repairs the Shadow ETRACS tables during activation or version transition without running broad native database setup and without recurring DDL checks on normal runtime requests.

---

## 2. Architecture & Lifecycle Touchpoints

### A. Authoritative Schema in [`class-assessor-database.php`](file:///d:/CODE/assessor/assessor-backend/wp-content/plugins/assessor-api/includes/class-assessor-database.php)

The canonical definitions for the three Shadow ETRACS tables reside directly within `Assessor_Database::create_tables()`:
- **`wp_assessor_machine`:** `objid` (PK), `state` (DEFAULT 'APPROVED'), `code` (UNIQUE), `name` (UNIQUE), `ix_machine_state` (INDEX).
- **`wp_assessor_planttree`:** `objid` (PK), `state` (DEFAULT 'APPROVED'), `code` (UNIQUE), `name` (UNIQUE), `ix_planttree_state` (INDEX).
- **`wp_assessor_planttreedetail`:** All 17 appraisal columns, `origin_type` (`ENUM('REPLICA', 'LOCAL') NOT NULL DEFAULT 'REPLICA'`), foreign keys, and `idx_origin_type` (INDEX).

When `dbDelta()` runs during fresh setup, all three tables are created in their final, hardened schema state.

### B. Decoupled Activation & Upgrade Lifecycle in [`assessor-api.php`](file:///d:/CODE/assessor/assessor-backend/wp-content/plugins/assessor-api/assessor-api.php)

1. **Normal Boot (`plugins_loaded` -> `assessor_api_check_version`):**
   - **Zero Recurring WP-10 Overhead:** When `assessor_db_version === ASSESSOR_API_VERSION`, no migration runners, DDL checks, or table scanners are executed.
   - When a plugin version update is detected (`assessor_db_version !== ASSESSOR_API_VERSION`), `create_tables()` executes followed by the scoped compatibility runner (`run_automatic_schema_upgrades()`) to ensure existing deployments receive any missing schema artifacts, updating `assessor_db_version` once complete.

2. **Fresh Plugin Activation (`register_activation_hook` -> `assessor_api_activate`):**
   - Calls `Assessor_Database::create_tables()` to install all canonical tables.
   - Calls [`Assessor_Migration_Runner::mark_migration_completed_if_valid()`](file:///d:/CODE/assessor/assessor-backend/wp-content/plugins/assessor-api/includes/class-assessor-migration-runner.php#L98): If physical schema validation confirms that `create_tables()` successfully created the tables, Migration 008 is recorded as `completed` directly in `wp_assessor_migrations` without invoking a separate migration workflow.
   - If validation fails (e.g. pre-existing partial schema), falls back to `run_automatic_schema_upgrades()` to heal missing columns or indexes.

---

## 3. Scoped Compatibility & Data Preservation Safeguards

- **Selective Execution:** Only approved schema repairs (Migration 008) are executed by the compatibility path. Migrations 001–007 (data backfills) are never rerun.
- **Physical Column & Index Validation:** Validates `INFORMATION_SCHEMA` across all 3 tables before marking completion.
- **Data Preservation:** Incomplete pre-existing tables are repaired via safe `ALTER TABLE ADD COLUMN` / `ADD KEY` without dropping tables or modifying existing rows.
- **Separation of Schema & Data:** Schema creation does not trigger any ETRACS synchronization or remote connections. Lookup rows remain empty until an approved, guarded pull is initiated.

---

## 4. Test Matrix & Verification

A dedicated test suite was executed against an isolated disposable database (`test_wp10_disposable`):

| Test Scenario | Condition Tested | Result |
| :--- | :--- | :--- |
| **Scenario 1 & 2** | Fresh Installation via standard `create_tables()` (all tables, columns, indexes, and Migration 008 recorded without separate procedure) | **PASS** (8/8 assertions) |
| **Scenario 3** | Existing Installation with Complete Schema (zero recurring repairs on normal boot) | **PASS** (5/5 assertions) |
| **Scenario 4** | Existing Installation Missing Shadow Tables (automatic scoped repair during version transition) | **PASS** (2/2 assertions) |
| **Scenario 5** | Partial Schema Repair (restores missing columns/indexes while preserving pre-existing records) | **PASS** (4/4 assertions) |
| **Scenario 6** | Native Assessor Protection (native `wp_assessor_properties` schemas and records remain completely untouched) | **PASS** (2/2 assertions) |
| **Scenario 7** | Data Synchronization Isolation (0 rows inserted in lookup tables; no ETRACS pull triggered) | **PASS** (3/3 assertions) |
| **Total** | **All Scenarios Tested** | **PASS (24/24 assertions)** |

In addition, all 21 property description regression tests in [`test-property-assessed-descriptions.php`](file:///d:/CODE/assessor/assessor-backend/scripts/test-property-assessed-descriptions.php) passed with 0 failures.
