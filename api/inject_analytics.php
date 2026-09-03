<?php
/**
 * Inject analytics snippets into static HTML pages.
 * Usage: php api/inject_analytics.php
 */
declare(strict_types=1);

$config = require __DIR__ . '/../config.php';
$ga = $config['google_analytics_id'] ?? '';
$clarity = $config['microsoft_clarity_id'] ?? '';

if (!$ga && !$clarity) {
    echo "[inject_analytics] No analytics IDs configured in config.php\n";
    return;
}

$analyticsHtml = '';
if ($ga) {
    $analyticsHtml .= "  <!-- Google Analytics -->\n";
    $analyticsHtml .= "  <script async src=\"https://www.googletagmanager.com/gtag/js?id=" . htmlspecialchars($ga) . "\"></script>\n";
    $analyticsHtml .= "  <script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','" . htmlspecialchars($ga) . "');</script>\n";
}
if ($clarity) {
    $analyticsHtml .= "  <!-- Microsoft Clarity -->\n";
    $analyticsHtml .= "  <script>(function(c,l,a,r,i,t,y){c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};t=l.createElement(r);t.async=1;t.src=\"https://www.clarity.ms/tag/\"+i;y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y)})(window,document,\"clarity\",\"script\",\"" . htmlspecialchars($clarity) . "\");</script>\n";
}

$staticFiles = [
    __DIR__ . '/../privacy.html',
    __DIR__ . '/../terms.html',
    __DIR__ . '/../about.html',
    __DIR__ . '/../contact.html',
];

$updated = 0;
foreach ($staticFiles as $file) {
    if (!file_exists($file)) continue;
    $content = file_get_contents($file);
    
    // Replace placeholder comment
    if (strpos($content, 'ANALYTICS_PLACEHOLDER') !== false) {
        $content = str_replace('  <!-- ANALYTICS_PLACEHOLDER: Run build_index.php to inject analytics -->', $analyticsHtml, $content);
        file_put_contents($file, $content);
        $updated++;
        echo "  Updated: " . basename($file) . "\n";
    }
}

echo "[inject_analytics] Updated {$updated} static pages\n";
