<?php
/**
 * Build static index.html + separate article pages
 * Reads all articles from api/data + api/data/trends and
 * regenerates:
 *  - index.html (static grid with links to articles/<slug>/)
 *  - sitemap.xml + robots.txt (all article URLs)
 *  - .htaccess + articles/.htaccess (no-cache headers)
 *
 * Usage: php api/build_index.php
 *        Called automatically by scraper.php and trends_scraper.php
 */
declare(strict_types=1);

const SITE_URL_BUILD = 'https://thetools.com';
const ROOT_DIR = __DIR__ . '/..';

function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }

function loadAllArticles(): array {
    $articles = [];
    // primary day-split
    $idx = __DIR__ . '/data/index.json';
    if (file_exists($idx)) {
        $j = json_decode(file_get_contents($idx), true);
        foreach ($j['days'] ?? [] as $d) {
            $p = __DIR__ . '/data/' . ($d['file'] ?? ($d['date'].'.json'));
            if (!file_exists($p)) continue;
            $day = json_decode(file_get_contents($p), true);
            foreach ($day['articles'] ?? [] as $a) $articles[] = $a;
        }
    }
    // fallback aggregated
    if (empty($articles)) {
        $agg = __DIR__ . '/data/articles.json';
        if (file_exists($agg)) {
            $j = json_decode(file_get_contents($agg), true);
            foreach ($j['articles'] ?? [] as $a) $articles[] = $a;
        }
    }
    // additional sources
    $tIdx = __DIR__ . '/data/trends/index.json';
    if (file_exists($tIdx)) {
        $j = json_decode(file_get_contents($tIdx), true);
        foreach ($j['days'] ?? [] as $d) {
            $p = __DIR__ . '/data/trends/' . ($d['file'] ?? ($d['date'].'.json'));
            if (!file_exists($p)) continue;
            $day = json_decode(file_get_contents($p), true);
            foreach ($day['articles'] ?? [] as $a) $articles[] = $a;
        }
    }
    // dedupe by source_url or slug, keep newest
    $byUrl = [];
    foreach ($articles as $a) {
        $key = $a['source_url'] ?? $a['slug'] ?? uniqid();
        if (!isset($byUrl[$key]) || strcmp($a['published_at'] ?? '', $byUrl[$key]['published_at'] ?? '') > 0) {
            $byUrl[$key] = $a;
        }
    }
    $articles = array_values($byUrl);
    usort($articles, fn($a,$b)=> strcmp($b['published_at'] ?? '', $a['published_at'] ?? ''));
    return $articles;
}

function normalizeAuthor(?string $author): string {
    $author = trim((string)$author);
    if ($author === '' || stripos($author, 'google') !== false || stripos($author, 'trends') !== false) {
        return 'TheTools';
    }
    return $author;
}

function cardHtml(array $a): string {
    $title = esc($a['title'] ?? '');
    $excerpt = esc($a['excerpt'] ?? '');
    $cat = esc($a['category'] ?? 'general');
    $author = esc(normalizeAuthor($a['author'] ?? ''));
    $img = esc($a['image_url'] ?? '');
    $slug = $a['slug'] ?? '';
    $href = "articles/{$slug}/";
    $date = '';
    try { $date = date('M j, Y', strtotime($a['published_at'] ?? 'now')); } catch(Throwable $e){ $date=''; }
    $imgTag = $img ? "<a href=\"{$href}\"><img src=\"{$img}\" alt=\"\" loading=\"lazy\" onerror=\"this.style.display='none'\"></a>" : '';
    return <<<HTML
  <article class="card">
    {$imgTag}
    <div class="card-body">
      <div class="badge">{$cat}</div>
      <h2><a href="{$href}">{$title}</a></h2>
      <p>{$excerpt}</p>
      <div class="card-meta"><span>{$author}</span><span>{$date}</span></div>
    </div>
  </article>
HTML;
}

function buildSitemap(array $articles): string {
    $base = rtrim(SITE_URL_BUILD, '/');
    $now = date('c');
    $urls = [];
    $urls[] = "  <url><loc>{$base}/</loc><lastmod>{$now}</lastmod><changefreq>hourly</changefreq><priority>1.0</priority></url>";
    foreach ($articles as $a) {
        $slug = $a['slug'] ?? '';
        if (!$slug) continue;
        $loc = $base . '/articles/' . $slug . '/';
        $lastmod = $a['published_at'] ?? $now;
        try { $lastmod = (new DateTime($lastmod))->format('c'); } catch (Throwable $e) {}
        $urls[] = "  <url><loc>" . esc($loc) . "</loc><lastmod>{$lastmod}</lastmod><changefreq>weekly</changefreq><priority>0.8</priority></url>";
    }
    $body = implode("\n", $urls);
    return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n{$body}\n</urlset>\n";
}

function buildIndexHtml(array $articles): string {
    $count = count($articles);
    $generated = date('c');
    $humanGen = date('d.m.Y H:i');
    $cards = implode("\n", array_map('cardHtml', $articles));
    $siteUrl = rtrim(SITE_URL_BUILD,'/');
    return <<<HTML
<!DOCTYPE html>
<html lang="el">
<head>
  <meta charset="UTF-8" />
  <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
  <meta http-equiv="Pragma" content="no-cache" />
  <meta http-equiv="Expires" content="0" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>thetools.com — News</title>
  <meta name="description" content="All articles — static HTML with full content for indexing. {$count} articles." />
  <link rel="canonical" href="{$siteUrl}/" />
  <meta property="og:type" content="website" />
  <meta property="og:title" content="thetools.com — News" />
  <meta property="og:description" content="{$count} articles — static HTML for fast indexing" />
  <link rel="stylesheet" href="css/style.css" />
</head>
<body>
  <header class="site-header">
    <div class="wrap">
      <a class="logo" href="./">thetools<span>.com</span></a>
      <nav class="nav">
        <a href="./" class="filter-btn active">All ({$count})</a>
        <a href="sitemap.xml" class="filter-btn">Sitemap</a>
        <a href="api/data/index.json" class="filter-btn">API</a>
      </nav>
      <input id="search" type="search" placeholder="Search articles…" autocomplete="off" />
    </div>
  </header>

  <main class="wrap">
    <div id="meta" class="meta">{$count} articles · static HTML · <span id="generated">Updated: {$humanGen}</span> · <a href="api/scraper.php?force=1">refresh</a> · <a href="api/trends_scraper.php?force=1">refresh more</a></div>
    <div id="articles" class="grid">
{$cards}
    </div>
    <article id="detail" class="detail hidden"></article>
    <noscript><p style="padding:16px;background:#fff;border:1px solid #e5e7eb;border-radius:10px">JavaScript disabled — all articles are linked as static HTML below. Each card links to <code>articles/&lt;slug&gt;/index.html</code> with full content for indexing.</p></noscript>
  </main>

  <footer class="site-footer">
    <div class="wrap">
      <p>Built with PHP scraper → JSON · split by day · SEO static HTML per article · {$count} pages</p>
      <p><a href="sitemap.xml">Sitemap</a> · <a href="robots.txt">Robots</a> · <span id="generated2">{$generated}</span></p>
    </div>
  </footer>

  <script type="module" src="js/app.js"></script>
</body>
</html>
HTML;
}

function ensureNoCacheHtaccess(): void {
    $content = <<<HT
# No-cache for all HTML and JSON (prevent stale)
<IfModule mod_headers.c>
  Header set Cache-Control "no-store, no-cache, must-revalidate, max-age=0"
  Header set Pragma "no-cache"
  Header set Expires "0"
</IfModule>
# Also for articles subfolders
<FilesMatch "\.(html|json)$">
  Header set Cache-Control "no-store, no-cache, must-revalidate, max-age=0"
</FilesMatch>
HT;
    $ht = ROOT_DIR . '/.htaccess';
    if (!file_exists($ht) || strpos(file_get_contents($ht), 'no-store') === false) {
        file_put_contents($ht, $content);
    }
    $aHt = ROOT_DIR . '/articles/.htaccess';
    @mkdir(dirname($aHt), 0777, true);
    if (!file_exists($aHt) || strpos(file_get_contents($aHt), 'no-store') === false) {
        file_put_contents($aHt, $content);
    }
}

function buildIndex(): int {
    $articles = loadAllArticles();
    file_put_contents(ROOT_DIR . '/index.html', buildIndexHtml($articles));
    $sitemap = buildSitemap($articles);
    file_put_contents(ROOT_DIR . '/sitemap.xml', $sitemap);
    $robots = "User-agent: *\nAllow: /\nSitemap: " . rtrim(SITE_URL_BUILD,'/') . "/sitemap.xml\n";
    if (!file_exists(ROOT_DIR . '/robots.txt') || strpos(file_get_contents(ROOT_DIR . '/robots.txt'), 'Sitemap:') === false) {
        file_put_contents(ROOT_DIR . '/robots.txt', $robots);
    }
    ensureNoCacheHtaccess();
    return count($articles);
}

if (count(get_included_files()) === 1) {
    $cnt = buildIndex();
    if (php_sapi_name() === 'cli') echo "[build_index] wrote {$cnt} articles → index.html + sitemap.xml\n";
    else echo json_encode(['ok'=>true,'count'=>$cnt,'file'=>'index.html']);
}