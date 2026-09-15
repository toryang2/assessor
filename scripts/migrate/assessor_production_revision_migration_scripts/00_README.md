# Production Revision Migration Scripts

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
