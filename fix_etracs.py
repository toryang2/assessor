with open(r'c:\xampp\htdocs\wp-content\plugins\assessor-api\includes\class-assessor-etracs.php', 'r') as f:
    content = f.read()

# Fix JOIN keys
content = content.replace('f.rpuid = r.id', 'f.rpuid = r.objid')
content = content.replace('f.realpropertyid = rp.id', 'f.realpropertyid = rp.objid')

# Fix WHERE clauses
content = content.replace('rp.cadastral_lot_no LIKE', 'rp.cadastrallotno LIKE')

# Fix SELECT aliases
content = content.replace('rp.cadastral_lot_no,', 'COALESCE(rp.cadastrallotno, rp.cadastral_lot_no, \'\') AS cadastral_lot_no,')
content = content.replace('rp.survey_no,', 'COALESCE(rp.surveyno, rp.survey_no, \'\') AS survey_no,')
content = content.replace('rp.block_no,', 'COALESCE(rp.blockno, rp.block_no, \'\') AS block_no,')

# Fix Insert/Update fields
content = content.replace(\"'cadastral_lot_no' => sanitize_text_field\", \"'cadastrallotno' => sanitize_text_field\")
content = content.replace(\"'survey_no'        => sanitize_text_field\", \"'surveyno'        => sanitize_text_field\")
content = content.replace(\"'block_no'         => sanitize_text_field\", \"'blockno'         => sanitize_text_field\")
content = content.replace(\"array('pin', 'cadastral_lot_no', 'survey_no', 'block_no', 'barangay'\", \"array('pin', 'cadastrallotno', 'surveyno', 'blockno', 'barangay'\")
content = content.replace(\"if ($f === 'cadastral_lot_no' && !isset($params[$f])\", \"if ($f === 'cadastrallotno' && !isset($params[$f])\")

with open(r'c:\xampp\htdocs\wp-content\plugins\assessor-api\includes\class-assessor-etracs.php', 'w') as f:
    f.write(content)
