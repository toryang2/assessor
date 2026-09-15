# PROD-04 - LIVE PROPERTY -> REVISION UUID LINK

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
