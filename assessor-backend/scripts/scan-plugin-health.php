<?php
$dir = "d:/CODE/assessor/assessor-backend/wp-content/plugins/assessor-api";
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === "php") {
        $path = $file->getRealPath();
        $content = file_get_contents($path);
        
        // Check BOM
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            echo "BOM found: $path\n";
        }
        // Check leading characters before <?php
        if (!preg_match("/^\s*<\?php/s", $content)) {
            echo "Non-php start: $path\n";
        }
        // Check trailing output after ?>
        if (preg_match("/\?>[\s\S]+$/", $content)) {
            echo "Trailing output after ?>: $path\n";
        }
        // Check if there are echo/print outside functions or unexpected output
        // Run php -l
        $cmd = 'php -l "' . addslashes($path) . '" 2>&1';
        exec($cmd, $out, $ret);
        if ($ret !== 0) {
            echo "Lint error in: $path\n";
        }
    }
}
echo "Check completed.\n";
