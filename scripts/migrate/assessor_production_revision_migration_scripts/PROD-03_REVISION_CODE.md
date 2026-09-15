# PROD-03 - LIVE REVISION CODE

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
