# MASTER PRODUCTION PROMPT

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
