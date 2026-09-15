from pathlib import Path
import zipfile

base = Path("assessor_production_revision_migration_scripts")
base.mkdir(parents=True, exist_ok=True)

scripts = {
"00_README.md": """# Production Revision Migration Scripts

Purpose:
Apply the already-coded Revision UUID v7 / Property Revision changes to the LIVE WordPress database.

Run in order:
PROD-01 -> PROD-02 -> PROD-03 -> PROD-04 -> PROD-05 -> PROD-06 -> PROD-07

Rules:
- Deploy the completed code to LIVE first.
- PROD-01 is read-only.
- Back up the live database before PROD-02.
- Run one production migration at a time.
- Never skip validation.
- Do not change Tax Declaration Number behavior in this package.
- Do not modify ETRACS business data.
""",

"PROD-01_PREFLIGHT.md": """# PROD-01 - LIVE PREFLIGHT

READ-ONLY. Do not run ALTER, INSERT, UPDATE, DELETE, REPLACE, DROP, TRUNCATE, or repair commands.

Inspect the actual LIVE WordPress environment and database.

Verify:
- intended production WordPress site
- active Assessor API plugin/version
- actual database prefix
- `assessor_revision_entries` exists
- `assessor_properties` exists
- current revision schema
- current property schema
- revision row count
- property row count
- current revision ID datatype
- whether revision_code exists
- whether property -> revision field exists
- current TDN UNIQUE index
- existing migration-state records
- `Assessor_UUID::v7()` exists in deployed code
- revision UUID/property revision support exists

Inspect all live tables for current references to revision IDs.

Return:
1. live environment confirmed
2. revision schema
3. property schema
4. revision count
5. property count
6. migration state
7. revision references
8. backup requirement
9. exact next production migration

Do not change anything.
""",

"PROD-02_REVISION_UUID.md": """# PROD-02 - LIVE REVISION UUID v7 MIGRATION

Run only after PROD-01 passes and a restorable LIVE database backup exists.

Goal:
Convert `assessor_revision_entries.id` to UUID v7 using the already-deployed migration design and existing `Assessor_UUID::v7()`.

Before writing:
- verify current schema matches PROD-01
- verify this migration is not already complete
- verify backup exists

Requirements:
- one UUID per existing revision
- never regenerate an existing UUID
- preserve revision row count
- preserve revision_year/from_year/to_year/status/sort_order/timestamps
- preserve all existing relationships
- idempotent migration
- no deletion or duplication

If production tables reference old numeric revision IDs:
- preserve the relationship
- map old numeric ID -> new UUID
- validate every mapping
- keep compatibility until final validation

After migration validate:
- row count unchanged
- UUID count equals row count
- UUIDs unique
- UUIDs valid UUID v7
- revision fields unchanged
- references still point to the same revision
- filter configuration is unchanged

Do NOT modify:
- assessor_properties
- TDN behavior
- ETRACS business data

Mark complete only after validation passes.
""",

"PROD-03_REVISION_CODE.md": """# PROD-03 - LIVE REVISION CODE

Run only after PROD-02 passes.

Ensure the live revisions have the stable `revision_code` expected by the deployed application.

Inspect current production data first.

Rules:
- preserve an existing valid revision_code
- never create random codes
- do not overwrite existing user-facing meaning
- if missing, derive deterministically from existing revision data according to the deployed design
- detect collisions before writing
- stop on ambiguity

If revision_code already exists and is valid:
- leave it unchanged
- validate it

If missing and required by deployed code:
- add/populate it using the existing migration convention
- validate all values

Do not modify properties, TDNs, ETRACS, or effectivity-date filtering.

Validate:
- required revision codes present
- no unexpected collisions
- revision count unchanged
""",

"PROD-04_PROPERTY_REVISION_LINK.md": """# PROD-04 - LIVE PROPERTY -> REVISION UUID LINK

Run only after PROD-02 and PROD-03 pass.

Apply the property -> revision UUID relationship already implemented in the deployed code.

First inspect the deployed code and live schema for the canonical field name. It may be `revision_id`, `revision_uuid`, or another already-established name.

Do NOT create a duplicate field.

Requirements:
- UUID reference to `assessor_revision_entries.id`
- indexed
- safe for existing data
- `effectivity_date` unchanged
- property UUID unchanged
- TDN uniqueness unchanged

If the deployed design includes a DB foreign key, only create/validate it after all existing values resolve.

After migration:
- property count unchanged
- all populated revision references resolve
- no orphan references
- effectivity_date unchanged
""",

"PROD-05_PROPERTY_REVISION_BACKFILL.md": """# PROD-05 - LIVE PROPERTY REVISION BACKFILL

Run only after PROD-04 passes.

Populate the property -> revision UUID field for existing production properties.

For each property:
1. read `effectivity_date`
2. determine the year
3. identify the active revision whose range contains that year
4. assign the revision UUID

Rule:
`from_year <= property_year <= to_year`

Treat `to_year = present` as open-ended.

Before writing, count:
- malformed effectivity dates
- no matching revision
- multiple matching active revisions

HARD STOP:
Do not guess.
Do not use created_at, sort_order, or latest ID to resolve ambiguity.
Do not silently assign a revision to an unresolved property.

After backfill validate:
- every eligible property has one revision UUID
- every revision UUID resolves
- no orphan references
- property count unchanged
- property UUIDs unchanged
- effectivity_date unchanged
- other property data unchanged

Do NOT modify TDN duplicate handling.
""",

"PROD-06_FINAL_VALIDATION.md": """# PROD-06 - LIVE FINAL VALIDATION

READ-ONLY.

Validate the completed production revision migration.

REVISION:
- UUID v7
- unique
- count unchanged
- revision_code valid
- revision_year/from_year/to_year unchanged
- status/sort_order unchanged

PROPERTY:
- property IDs remain UUIDs
- property count unchanged
- property revision references resolve
- no orphan references
- effectivity_date unchanged

FILTER:
Verify:
selected revision -> resolve revision -> from_year/to_year -> effectivity_date filtering

Test:
- first year
- middle year
- last year
- present
- outside range

TDN:
Do NOT modify.
Only report:
- global UNIQUE status
- duplicate TDN count
- same-TDN/different-revision count
- same-TDN/same-revision count

RELATIONSHIPS:
Check:
- property versions
- documents
- property states
- audit trail
- lineage/superseded
- ETRACS
- taxpayer/property relationships
- requests
- sync

CODE:
Report remaining revision ID integer assumptions such as:
- parseInt
- Number
- integer validators
- SQL `%d` for UUID revision IDs

Do not auto-fix.

Return:
PASS / PASS WITH WARNINGS / FAIL
with exact discrepancies.
""",

"PROD-07_FINALIZE.md": """# PROD-07 - FINALIZE LIVE REVISION MIGRATION

Run only after PROD-06 returns PASS.

Finalize the production migration state.

Verify:
- PROD-01 through PROD-06 recorded correctly
- no failed migration remains
- no pending migration remains
- revision UUIDs stable
- property revision references resolve
- revision filters work
- no data loss

Do NOT:
- change TDN uniqueness
- change ETRACS logic
- delete required compatibility mappings
- perform unrelated cleanup

Record:
- completion timestamp
- migration/schema version
- application/plugin version
- revision count
- property count
- property-revision link count
- unresolved count

Return a final production migration report.

The next separate task is the revision-aware Tax Declaration Number duplicate rule. Do not implement it here.
""",

"MASTER_PRODUCTION_PROMPT.md": """# MASTER PRODUCTION PROMPT

The Assessor Revision UUID/property-revision coding work has already been completed in the repository through Script 15.

The remaining work is applying the database changes to the LIVE WordPress database.

Run one production prompt at a time:
PROD-01 -> PROD-02 -> PROD-03 -> PROD-04 -> PROD-05 -> PROD-06 -> PROD-07

Rules:
- deploy completed code to LIVE before writes
- PROD-01 is read-only
- backup before PROD-02
- use existing WordPress `$wpdb`
- use existing `Assessor_UUID::v7()`
- use the deployed migration framework
- migrations must be idempotent
- never regenerate existing UUIDs
- never merge records
- never guess ambiguous revisions
- preserve effectivity-date filter behavior
- do not modify TDN uniqueness
- do not modify ETRACS business data
- stop on unexplained discrepancies

The LIVE database is the source of truth. Never assume production data equals development data.
"""
}

for name, text in scripts.items():
    (base / name).write_text(text.rstrip() + "\n", encoding="utf-8")

zip_path = Path("assessor_production_revision_migration_scripts.zip")
with zipfile.ZipFile(zip_path, "w", compression=zipfile.ZIP_DEFLATED) as z:
    for p in sorted(base.glob("*.md")):
        z.write(p, p.name)

print(str(zip_path))
print("Included:", len(scripts), "files")
