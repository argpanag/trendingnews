<?php
/**
 * Regenerate all article HTML pages from JSON data
 * Usage: php api/rebuild_articles.php
 */
declare(strict_types=1);

const SITE_URL = 'https://trends-online.com';
const SEO_DIR = __DIR__ . '/../articles';
const DATA_DIR = __DIR__ . '/data';

function esc(string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$config = require __DIR__ . '/../config.php';

function analyticsHead(): string {
    global $config;
    $ga = $config['google_analytics_id'] ?? '';
    $clarity = $config['microsoft_clarity_id'] ?? '';
    $html = '';
    if ($ga) {
        $html .= "  <!-- Google Analytics -->\n";
        $html .= "  <script async src=\"https://www.googletagmanager.com/gtag/js?id=" . esc($ga) . "\"></script>\n";
        $html .= "  <script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','" . esc($ga) . "');</script>\n";
    }
    if ($clarity) {
        $html .= "  <!-- Microsoft Clarity -->\n";
        $html .= "  <script>(function(c,l,a,r,i,t,y){c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};t=l.createElement(r);t.async=1;t.src=\"https://www.clarity.ms/tag/\"+i;y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y)})(window,document,\"clarity\",\"script\",\"" . esc($clarity) . "\");</script>\n";
    }
    return $html;
}

function footerLinks(): string {
    return "<a href=\"../../privacy.html\">Privacy Policy</a> · <a href=\"../../terms.html\">Terms</a> · <a href=\"../../about.html\">About</a> · <a href=\"../../contact.html\">Contact</a>";
}

function loadAllArticles(): array {
    $articles = [];
    
    // Load from primary day-split
    $idx = DATA_DIR . '/index.json';
    if (file_exists($idx)) {
        $j = json_decode(file_get_contents($idx), true);
        foreach ($j['days'] ?? [] as $d) {
            $p = DATA_DIR . '/' . ($d['file'] ?? ($d['date'] . '.json'));
            if (file_exists($p)) {
                $day = json_decode(file_get_contents($p), true);
                foreach ($day['articles'] ?? [] as $a) $articles[] = $a;
            }
        }
    }
    
    // Load from trends (all countries)
    $trendsBase = DATA_DIR . '/trends';
    if (is_dir($trendsBase)) {
        foreach (scandir($trendsBase) as $country) {
            if ($country === '.' || $country === '..' || !is_dir($trendsBase . '/' . $country)) continue;
            $countryIdx = $trendsBase . '/' . $country . '/index.json';
            if (!file_exists($countryIdx)) continue;
            $j = json_decode(file_get_contents($countryIdx), true);
            foreach ($j['days'] ?? [] as $d) {
                $p = $trendsBase . '/' . $country . '/' . ($d['file'] ?? ($d['date'] . '.json'));
                if (file_exists($p)) {
                    $day = json_decode(file_get_contents($p), true);
                    foreach ($day['articles'] ?? [] as $a) {
                        $a['country'] = $country;
                        $articles[] = $a;
                    }
                }
            }
        }
    }
    
    // Dedupe by source_url
    $byUrl = [];
    foreach ($articles as $a) {
        $key = $a['source_url'] ?? $a['slug'] ?? uniqid();
        if (!isset($byUrl[$key])) $byUrl[$key] = $a;
    }
    
    return array_values($byUrl);
}

function buildArticleHtml(array $a): string {
    $title = esc($a['title'] ?? 'Untitled');
    $excerpt = esc($a['excerpt'] ?? '');
    $slug = $a['slug'] ?? 'unknown';
    $fullUrl = rtrim(SITE_URL, '/') . '/articles/' . $slug . '/';
    $img = esc($a['image_url'] ?? '');
    $author = esc($a['author'] ?? 'TheTools');
    $published = $a['published_at'] ?? date('c');
    try {
        $iso = (new DateTime($published))->format('c');
    } catch (Throwable $e) {
        $iso = date('c');
    }
    $humanDate = esc(date('d.m.Y H:i', strtotime($published) ?: time()));
    $content = $a['content'] ?? '';
    $category = esc($a['category'] ?? 'general');
    $sourceUrl = esc($a['source_url'] ?? '#');
    $country = esc($a['country'] ?? '');
    $footer = footerLinks();
    $analytics = analyticsHead();
    
    $jsonLd = [
        '@context' => 'https://schema.org',
        '@type' => 'Article',
        'headline' => $a['title'] ?? 'Untitled',
        'description' => $a['excerpt'] ?? '',
        'image' => $a['image_url'] ? [$a['image_url']] : [],
        'datePublished' => $iso,
        'dateModified' => $iso,
        'author' => ['@type' => 'Person', 'name' => $author],
        'publisher' => [
            '@type' => 'Organization',
            'name' => 'trends-online.com',
            'logo' => ['@type' => 'ImageObject', 'url' => SITE_URL . '/css/style.css']
        ],
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $fullUrl],
    ];
    $jsonLdStr = json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
    $countryBadge = $country ? "<span class=\"badge\">{$country}</span>" : '';
    
    return <<<HTML
<!DOCTYPE html>
<html lang="el">
<head>
  <meta charset="UTF-8" />
  <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
  <meta http-equiv="Pragma" content="no-cache" />
  <meta http-equiv="Expires" content="0" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>{$title} | trends-online.com</title>
  <meta name="description" content="{$excerpt}" />
  <link rel="canonical" href="{$fullUrl}" />
  <meta property="og:type" content="article" />
  <meta property="og:locale" content="el_GR" />
  <meta property="og:title" content="{$title}" />
  <meta property="og:description" content="{$excerpt}" />
  <meta property="og:url" content="{$fullUrl}" />
  <meta property="og:site_name" content="trends-online.com" />
  <meta property="og:image" content="{$img}" />
  <meta property="article:published_time" content="{$iso}" />
  <meta property="article:author" content="{$author}" />
  <meta name="twitter:card" content="summary_large_image" />
  <meta name="twitter:title" content="{$title}" />
  <meta name="twitter:description" content="{$excerpt}" />
  <meta name="twitter:image" content="{$img}" />
  <link rel="stylesheet" href="../../css/style.css" />
  {$analytics}
  <script type=\"application/ld+json\">{$jsonLdStr}</script>
</head>
<body>
  <header class="site-header">
    <div class="wrap">
      <a class="logo" href="../../">trends-online<span>.com</span></a>
      <nav class="nav">
        <a href="../../" class="filter-btn">← Home</a>
        <span class="badge">{$category}</span>
        {$countryBadge}
      </nav>
    </div>
  </header>

  <main class="wrap">
    <article class="detail">
      <a href="../../">← Back</a>
      <img src="{$img}" alt="" loading="lazy" onerror="this.style.display='none'" style="width:100%;max-height:420px;object-fit:cover;border-radius:10px;margin-top:12px" />
      <div class="badge">{$category}</div>
      <h1>{$title}</h1>
      <div style="color:#6b7280;font-size:.9rem">{$author} · {$humanDate} · <a href="{$sourceUrl}" target="_blank" rel="noopener">source</a> · <a href="{$fullUrl}">permalink</a></div>
      <div class="content">{$content}</div>
      <hr style="margin:24px 0;border:none;border-top:1px solid #e5e7eb" />
      <p style="color:#6b7280;font-size:.85rem">Source: <a href="{$sourceUrl}" target="_blank" rel="noopener">original</a> · archived on trends-online.com</p>
    </article>
  </main>

  <footer class="site-footer">
    <div class="wrap">
      <p><a href="../../">trends-online.com</a> · <a href="../../archive/">Archive</a></p>
      <p>{$footer}</p>
    </div>
  </footer>
</body>
</html>
HTML;
}

function main(): void {
    $articles = loadAllArticles();
    $count = 0;
    
    @mkdir(SEO_DIR, 0777, true);
    
    foreach ($articles as $a) {
        $slug = $a['slug'] ?? 'unknown';
        $dir = SEO_DIR . '/' . $slug;
        @mkdir($dir, 0777, true);
        
        $html = buildArticleHtml($a);
        file_put_contents($dir . '/index.html', $html);
        $count++;
        
        if (php_sapi_name() === 'cli') {
            echo "  + {$slug}\n";
        }
    }
    
    if (php_sapi_name() === 'cli') {
        echo "[rebuild_articles] wrote {$count} article pages\n";
    } else {
        echo json_encode(['ok' => true, 'count' => $count]);
    }
}

main();
