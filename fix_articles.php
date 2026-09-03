<?php
/**
 * Fix article HTML files with incorrect template structure.
 * Extracts article data from JSON sources and regenerates HTML files.
 *
 * Usage: php fix_articles.php              (dry-run, show issues)
 *        php fix_articles.php --apply      (apply fixes)
 *        php fix_articles.php --slug=SLUG  (fix specific article only)
 */
declare(strict_types=1);

const SITE_URL = 'https://trends-online.com';
const ARTICLES_DIR = __DIR__ . '/articles';
const DATA_DIR = __DIR__ . '/api/data';
const HISTORY_DIR = __DIR__ . '/api/history_trends';

function esc(string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function isApply(): bool {
    global $argv;
    return in_array('--apply', $argv ?? []) || in_array('-a', $argv ?? []);
}

function getTargetSlug(): ?string {
    global $argv;
    foreach ($argv ?? [] as $arg) {
        if (str_starts_with($arg, '--slug=')) {
            return substr($arg, 7);
        }
    }
    return null;
}

function loadAllArticlesFromJson(): array {
    $articles = [];

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
                        $articles[$a['slug']] = $a;
                    }
                }
            }
        }
    }

    $histBase = HISTORY_DIR;
    if (is_dir($histBase)) {
        foreach (scandir($histBase) as $f) {
            if ($f === '.' || $f === '..' || !str_ends_with($f, '.json')) continue;
            $j = json_decode(file_get_contents($histBase . '/' . $f), true);
            if (isset($j['slug']) && !isset($articles[$j['slug']])) {
                $articles[$j['slug']] = $j;
            }
        }
        foreach (scandir($histBase) as $sub) {
            if ($sub === '.' || $sub === '..' || !is_dir($histBase . '/' . $sub)) continue;
            foreach (scandir($histBase . '/' . $sub) as $f) {
                if (!str_ends_with($f, '.json')) continue;
                $j = json_decode(file_get_contents($histBase . '/' . $sub . '/' . $f), true);
                if (isset($j['slug']) && !isset($articles[$j['slug']])) {
                    $articles[$j['slug']] = $j;
                }
            }
        }
    }

    return $articles;
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
  <script type="application/ld+json">{$jsonLdStr}</script>
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
      <p>Built with PHP scraper → JSON · SEO static HTML</p>
      <p><a href="../../">trends-online.com</a> · <a href="../../api/data/index.json">API index</a></p>
    </div>
  </footer>
</body>
</html>
HTML;
}

function detectIssues(string $path): array {
    $html = file_get_contents($path);
    $issues = [];

    if (!str_contains($html, 'lang="el"')) {
        $issues[] = 'lang="el" missing';
    }

    if (!str_contains($html, '← Back')) {
        $issues[] = 'Missing ← Back link';
    }

    if (!str_contains($html, '<footer')) {
        $issues[] = 'Missing <footer> section';
    }

    if (!str_contains($html, '<nav')) {
        $issues[] = 'Missing <nav> with badges';
    }

    if (str_contains($html, '← Back')) {
        $issues[] = 'Greek ← Πίσω instead of English ← Back';
    }

    if (str_contains($html, 'lang="en"')) {
        $issues[] = 'lang="en" instead of lang="el"';
    }

    $lines = explode("\n", $html);
    if (count($lines) < 10) {
        $issues[] = 'Minified/single-line HTML (should be formatted)';
    }

    return $issues;
}

function main(): void {
    $apply = isApply();
    $targetSlug = getTargetSlug();
    $jsonArticles = loadAllArticlesFromJson();

    echo "=== fix_articles.php ===\n";
    echo "Mode: " . ($apply ? "APPLY" : "DRY-RUN") . "\n\n";

    $articleDirs = [];
    if ($targetSlug) {
        $dir = ARTICLES_DIR . '/' . $targetSlug;
        if (is_dir($dir)) {
            $articleDirs[$targetSlug] = $dir;
        } else {
            echo "Article not found: {$targetSlug}\n";
            exit(1);
        }
    } else {
        foreach (scandir(ARTICLES_DIR) as $slug) {
            if ($slug === '.' || $slug === '..') continue;
            $dir = ARTICLES_DIR . '/' . $slug;
            if (is_dir($dir) && file_exists($dir . '/index.html')) {
                $articleDirs[$slug] = $dir;
            }
        }
    }

    $total = count($articleDirs);
    $fixed = 0;
    $skipped = 0;
    $noData = 0;

    echo "Scanning {$total} articles...\n\n";

    foreach ($articleDirs as $slug => $dir) {
        $htmlPath = $dir . '/index.html';
        $issues = detectIssues($htmlPath);

        if (empty($issues)) {
            continue;
        }

        $hasData = isset($jsonArticles[$slug]);

        if (!$hasData) {
            $noData++;
            echo "[SKIP] {$slug} - no JSON data found\n";
            foreach ($issues as $issue) {
                echo "       ⚠ {$issue}\n";
            }
            echo "\n";
            $skipped++;
            continue;
        }

        echo "[FIX] {$slug}\n";
        foreach ($issues as $issue) {
            echo "      → {$issue}\n";
        }

        if ($apply) {
            $newHtml = buildArticleHtml($jsonArticles[$slug]);
            file_put_contents($htmlPath, $newHtml);
            echo "      ✓ Fixed and written\n";
        } else {
            echo "      (dry-run, use --apply to fix)\n";
        }
        $fixed++;
        echo "\n";
    }

    echo "=== Summary ===\n";
    echo "Total articles: {$total}\n";
    echo "With issues: {$fixed}\n";
    echo "No JSON data: {$noData}\n";
    if (!$apply && $fixed > 0) {
        echo "\nRun with --apply to apply fixes.\n";
    }
}

main();
