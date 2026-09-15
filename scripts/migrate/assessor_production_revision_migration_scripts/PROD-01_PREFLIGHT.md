# PROD-01 - LIVE PREFLIGHT

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
