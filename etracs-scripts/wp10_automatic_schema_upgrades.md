# WP-10 — Automatic Shadow ETRACS Schema Upgrades Specification & Lifecycle

## 1. Overview & Objective

To eliminate manual execution of schema migrations across deployment environments (development, staging, new server activations, and production upgrades), **WP-10** introduces an automated, deployment-safe schema upgrade lifecycle within the Assessor plugin.

When a fresh installation is activated or an existing server receives a plugin update, approved Shadow ETRACS schema upgrades execute automatically without manual CLI intervention, without rerunning native table installations, and without altering native property data.

---

## 2. Architecture & Lifecycle Hooks

### A. Lifecycle Touchpoints in [`assessor-api.php`](file:///d:/CODE/assessor/assessor-backend/wp-content/plugins/assessor-api/assessor-api.php)

1. **Fresh Plugin Activation (`register_activation_hook` -> `assessor_api_activate`):**
   - Calls `Assessor_Database::create_tables()`.
   - Triggers `Assessor_Migration_Runner::run_automatic_schema_upgrades()`.
   - Ensures Shadow ETRACS schema dependencies (`wp_assessor_machine`, `wp_assessor_planttree`, `wp_assessor_planttreedetail`) exist from the first moment the plugin is active.

2. **Plugin Upgrade / Version Change (`plugins_loaded` -> `assessor_api_check_version`):**
   - Detects when `get_option('assessor_db_version') !== ASSESSOR_API_VERSION`.
   - Calls `Assessor_Database::create_tables()`.
   - Automatically executes `Assessor_Migration_Runner::run_automatic_schema_upgrades()`.
   - Updates `assessor_db_version` in options.

3. **Active Installation Schema Validation Safeguard:**
   - Even when `assessor_db_version` matches the plugin version (e.g. database restored or copied between environments), `assessor_api_check_version()` performs a lightweight schema validation check via `validate_migration_008_schema()`.
   - If physical tables or columns are missing, `run_automatic_schema_upgrades()` is invoked automatically to heal the schema.
   - Does **not** rerun `create_tables()` if the version already matches, preventing unnecessary table scans or native schema touches.

---

## 3. Migration Runner Safeguards (`Assessor_Migration_Runner`)

The automatic upgrade pipeline incorporates the following safeguards:

1. **Selective Execution:**
   - Only approved schema upgrades (Migration 008) are included in `run_automatic_schema_upgrades()`.
   - Migrations 001–007 (data transformations, UUID backfills) are **not** rerun automatically.
2. **Atomic Concurrency Guarding:**
   - Checks `wp_assessor_migrations` using atomic row updates (`UPDATE ... WHERE status != 'running' OR started_at < 5 minutes ago`).
   - If another worker or request is currently executing the migration, subsequent requests gracefully skip execution with status `in_progress` or `concurrency_lock_held`.
3. **Idempotent Skip:**
   - If `status = 'completed'` and physical schema validation passes, returns `already_completed` with `skipped = true` without modifying database state or updating timestamps.
4. **Physical Verification Before Completion:**
   - Before marking a migration completed in `wp_assessor_migrations`, the runner calls `validate_migration_008_schema()`, physically inspecting:
     - `wp_assessor_machine` table exists.
     - `wp_assessor_planttree` table exists.
     - `wp_assessor_planttreedetail` table exists.
     - `origin_type` column exists on `wp_assessor_planttreedetail`.
     - `idx_origin_type` index exists on `wp_assessor_planttreedetail`.
   - If any requirement fails, the migration is marked `failed` with diagnostic details in the database and PHP error log.
5. **Separation of Schema and Data:**
   - Schema creation does **not** trigger an automatic or uncontrolled ETRACS pull.
   - Lookup data population is handled separately via the approved, collision-guarded `Assessor_Etracs_Sync` process.

---

## 4. Test Matrix & Verification

A dedicated test suite was implemented in [`assessor-backend/scripts/test-wp10-automatic-schema-upgrades.php`](file:///d:/CODE/assessor/assessor-backend/scripts/test-wp10-automatic-schema-upgrades.php) against an isolated disposable database (`test_wp10_disposable`).

| Test Suite | Scenario Tested | Result |
| :--- | :--- | :--- |
| **Suite 1** | Fresh Installation (table creation, column/index verification, registry completion) | **PASS** (6/6 assertions) |
| **Suite 2** | Already-Completed Migration (idempotent skip, timestamp preservation) | **PASS** (4/4 assertions) |
| **Suite 3** | Active Plugin Upgrade (pending migration detection and execution) | **PASS** (3/3 assertions) |
| **Suite 4** | Partial Schema State (healing missing column on pre-existing table) | **PASS** (2/2 assertions) |
| **Suite 5** | Concurrency Guarding (detection and blocking of concurrent execution) | **PASS** (2/2 assertions) |
| **Suite 6** | Failure Diagnostics (error logging, fail status recorded in registry, error message persisted) | **PASS** (5/5 assertions) |
| **Total** | **All Scenarios** | **PASS (22/22 assertions)** |

---

## 5. Adding Future Automatic Schema Migrations

To add a new automatic schema migration:
1. Implement the schema creation/alteration method in `Assessor_Database`.
2. Register the migration in `Assessor_Migration_Runner::$registered_migrations`.
3. Add a dedicated `run_automatic_migration_XXX()` method in `Assessor_Migration_Runner` adhering to the lock -> execute -> physical validate -> mark complete lifecycle.
4. Call it from `run_automatic_schema_upgrades()`.
