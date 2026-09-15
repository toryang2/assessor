# PROD-06 - LIVE FINAL VALIDATION

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
