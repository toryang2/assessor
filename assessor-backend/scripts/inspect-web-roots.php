<?php
$web_roots = [
    'C:/xampp/htdocs',
    'C:/xampp/htdocs/assessorkitaotao',
    'd:/CODE/assessor/assessor-backend'
];

foreach ($web_roots as $root) {
    echo "=== Checking: $root ===\n";
    if (is_dir($root)) {
        echo "  Directory exists.\n";
        $wp_config = $root . '/wp-config.php';
        if (file_exists($wp_config)) {
            $content = file_get_contents($wp_config);
            preg_match("/define\(\s*['\"]DB_NAME['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $content, $m_db);
            preg_match("/table_prefix\s*=\s*['\"]([^'\"]+)['\"]/", $content, $m_prefix);
            echo "  wp-config.php found!\n";
            echo "    DB_NAME: " . ($m_db[1] ?? 'NOT FOUND') . "\n";
            echo "    table_prefix: " . ($m_prefix[1] ?? 'NOT FOUND') . "\n";
        } else {
            echo "  wp-config.php NOT found in root.\n";
        }
        
        // Check if there are themes/plugins
        $theme_dir = $root . '/wp-content/themes';
        if (is_dir($theme_dir)) {
            $themes = scandir($theme_dir);
            echo "  Themes: " . implode(', ', array_diff($themes, ['.', '..'])) . "\n";
        }
    } else {
        echo "  Directory does NOT exist.\n";
    }
    echo "\n";
}

// Check Apache virtual hosts / httpd.conf / vhosts
$httpd_conf = 'C:/xampp/apache/conf/httpd.conf';
$vhosts_conf = 'C:/xampp/apache/conf/extra/httpd-vhosts.conf';

echo "=== Apache Configs ===\n";
if (file_exists($httpd_conf)) {
    $content = file_get_contents($httpd_conf);
    preg_match_all("/DocumentRoot\s+[\"']?([^\"'\r\n]+)[\"']?/", $content, $m_doc);
    echo "  httpd.conf DocumentRoot: " . implode(', ', $m_doc[1] ?? []) . "\n";
}
if (file_exists($vhosts_conf)) {
    $content = file_get_contents($vhosts_conf);
    echo "  httpd-vhosts.conf length: " . strlen($content) . " bytes\n";
    preg_match_all("/<VirtualHost[^>]*>(.*?)<\/VirtualHost>/s", $content, $vhosts);
    foreach ($vhosts[0] as $vh) {
        echo "  VHost block:\n" . $vh . "\n";
    }
}
