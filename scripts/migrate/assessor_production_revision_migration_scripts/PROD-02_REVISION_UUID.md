# PROD-02 - LIVE REVISION UUID v7 MIGRATION

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
