# WP-10 — Automatic Shadow ETRACS Schema Upgrades Specification & Lifecycle (Hardened)

## 1. Overview & Objective

To eliminate manual execution of schema migrations across deployment environments (development, staging, new server activations, and production upgrades), **WP-10** introduces an automated, deployment-safe schema upgrade lifecycle within the Assessor plugin.

When a fresh installation is activated or an existing server receives a plugin update, approved Shadow ETRACS schema upgrades execute automatically without manual CLI intervention, without rerunning native table installations, and without altering native property data.

---

## 2. Architecture & Lifecycle Hooks

### A. Lifecycle Touchpoints in [`assessor-api.php`](file:///d:/CODE/assessor/assessor-backend/wp-content/plugins/assessor-api/assessor-api.php)

1. **Fresh Plugin Activation (`register_activation_hook` -> `assessor_api_activate`):**
   - Calls `Assessor_Database::create_tables()` to initialize base schema.
   - Triggers `Assessor_Migration_Runner::run_automatic_schema_upgrades()`.
   - Ensures Shadow ETRACS schema dependencies (`wp_assessor_machine`, `wp_assessor_planttree`, `wp_assessor_planttreedetail`) exist from the first moment the plugin is active.

2. **Decoupled Plugin Upgrade / Runtime Boot (`plugins_loaded` -> `assessor_api_check_version`):**
   - **Step 1 (WP-10 Execution):** `Assessor_Migration_Runner::run_automatic_schema_upgrades()` is invoked directly. This checks `wp_assessor_migrations` and physically validates schema artifacts. If Migration 008 is pending or in a partial state, it executes independently. **It does NOT call `create_tables()` on existing installations.**
   - **Step 2 (Legacy Plugin Version Check):** If `get_option('assessor_db_version') !== ASSESSOR_API_VERSION`, core legacy database routines run, and `assessor_db_version` is updated.
   - Existing installations can upgrade their Shadow ETRACS schema cleanly without running unrelated native database table scans or data mutations.

---

## 3. Migration Runner Safeguards (`Assessor_Migration_Runner`)

The hardened automatic upgrade pipeline incorporates the following safeguards:

1. **Selective Execution:**
   - Only approved schema upgrades (Migration 008) are included in `run_automatic_schema_upgrades()`.
   - Migrations 001–007 (data transformations, UUID backfills) are **not** rerun automatically.
2. **Atomic Concurrency Guarding & Stale Lock Recovery:**
   - Checks `wp_assessor_migrations` using atomic row updates (`UPDATE ... WHERE status != 'running' OR started_at < (NOW - 5 minutes)`).
   - If another worker or request is currently executing the migration within the last 5 minutes, subsequent requests gracefully skip execution with status `in_progress` or `concurrency_lock_held`.
   - If a previous process crashed or hung over 5 minutes ago, the stale lock is safely reclaimed, error state cleared, and migration retried.
3. **Idempotent Skip:**
   - If `status = 'completed'` and physical schema validation passes, returns `already_completed` with `skipped = true` without modifying database state or updating timestamps.
4. **Comprehensive Physical Verification Before Completion:**
   - Before marking a migration completed in `wp_assessor_migrations`, the runner calls `validate_migration_008_schema()`, physically inspecting:
     - `wp_assessor_machine`: table existence, columns (`objid`, `state`, `code`, `name`), and indexes (`ux_machine_code`, `ux_machine_name`, `ix_machine_state`).
     - `wp_assessor_planttree`: table existence, columns (`objid`, `state`, `code`, `name`), and indexes (`ux_planttree_code`, `ux_planttree_name`, `ix_planttree_state`).
     - `wp_assessor_planttreedetail`: table existence, columns (`objid`, `planttreerpuid`, `landrpuid`, `planttree_objid`, `unitvalue`, `basemarketvalue`, `marketvalue`, `assessedvalue`, `origin_type`), and indexes (`ix_planttreedetail_landrpuid`, `ix_planttreedetail_planttreerpuid`, `ix_planttreedetail_planttree_objid`, `idx_origin_type`).
   - If any requirement is missing, the migration is marked `failed` with diagnostic details in the database and PHP error log.
5. **Safe Partial Schema Healing & Data Preservation:**
   - If tables pre-exist in a partial state (e.g. created by earlier manual scripts without all columns or indexes), `migrate_property_assessed_descriptions_schema()` inspects `INFORMATION_SCHEMA` and adds only the missing columns and indexes via `ALTER TABLE` without dropping tables or corrupting pre-existing records.
   - Pre-existing rows in `planttreedetail` are backfilled with `origin_type = 'REPLICA'` without overwriting values.
6. **Separation of Schema and Data:**
   - Schema creation does **not** trigger an automatic or uncontrolled ETRACS pull.
   - Lookup data population is handled separately via the approved, collision-guarded `Assessor_Etracs_Sync` process.

---

## 4. Test Matrix & Verification

A dedicated test suite was executed against an isolated disposable database (`test_wp10_disposable`). No active or live database was accessed.

| Test Suite | Scenario Tested | Result |
| :--- | :--- | :--- |
| **Suite 1** | Fresh Installation (table creation, all columns and indexes verification, registry completion) | **PASS** (3/3 assertions) |
| **Suite 2** | Existing Installation with Expected Schema (idempotent skip, timestamp preservation) | **PASS** (4/4 assertions) |
| **Suite 3** | Existing Installation Missing Migration 008 (pending migration execution and schema validation) | **PASS** (2/2 assertions) |
| **Suite 4** | Partial Schema State & Data Preservation (column/index healing across all tables, pre-existing row preservation) | **PASS** (6/6 assertions) |
| **Suite 5** | Concurrency Guarding & Stale Lock Recovery (active lock blocked, >5 min stale lock reclaimed) | **PASS** (4/4 assertions) |
| **Suite 6** | Failure Diagnostics & Retry (error logging, fail status recorded, retry recovery after resolution) | **PASS** (7/7 assertions) |
| **Suite 7** | Decoupled Lifecycle & Native Assessor Protection (WP-10 runs without calling `create_tables()`, native property data untouched) | **PASS** (4/4 assertions) |
| **Total** | **All Scenarios Tested** | **PASS (30/30 assertions)** |

In addition, all 21 regression tests in [`test-property-assessed-descriptions.php`](file:///d:/CODE/assessor/assessor-backend/scripts/test-property-assessed-descriptions.php) passed with 0 failures.

---

## 5. Adding Future Automatic Schema Migrations

To add a new automatic schema migration:
1. Implement the schema creation/alteration method in `Assessor_Database` with `INFORMATION_SCHEMA` column/index checks.
2. Register the migration in `Assessor_Migration_Runner::$registered_migrations`.
3. Add a dedicated `run_automatic_migration_XXX()` method in `Assessor_Migration_Runner` adhering to the lock -> execute -> physical validate -> mark complete lifecycle.
4. Call it from `run_automatic_schema_upgrades()`.
