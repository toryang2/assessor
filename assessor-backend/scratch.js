const fs = require('fs'); 
const file = 'wp-content/plugins/assessor-api/includes/class-assessor-auth.php'; 
let content = fs.readFileSync(file, 'utf8'); 
content = content.replace(/id = %d/g, 'id = %s'); 
content = content.replace(/id != %d/g, 'id != %s'); 
content = content.replace(/array\('%d'\)/g, "array('%s')"); 
content = content.replace(/array\('%s'\), array\('%d'\)/g, "array('%s'), array('%s')"); 
content = content.replace(/LIMIT %d OFFSET %d"/g, "LIMIT %d OFFSET %d\""); // Wait, LIMIT %d OFFSET %d should remain %d!
// Wait, I shouldn't touch `LIMIT %d OFFSET %d`, but my regexes above only touched `id = %d` and `array('%d')`. 
fs.writeFileSync(file, content);
console.log('done');
