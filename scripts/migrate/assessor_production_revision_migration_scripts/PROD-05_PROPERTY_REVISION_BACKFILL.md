# PROD-05 - LIVE PROPERTY REVISION BACKFILL

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
