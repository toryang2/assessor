# PROD-07 - FINALIZE LIVE REVISION MIGRATION

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
