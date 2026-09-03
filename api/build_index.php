<?php
/**
 * Build static index.html + separate article pages
 * Reads all articles from api/data + api/data/trends and
 * regenerates:
 *  - index.html (static grid with links to articles/<slug>/)
 *  - sitemap.xml + robots.txt (all article URLs)
 *  - archive/index.html (list of all days)
 *  - archive/YYYY-MM-DD/index.html (daily article lists)
 *  - index-{country}.html (country-specific pages)
 *  - .htaccess + articles/.htaccess (no-cache headers)
 *
 * Usage: php api/build_index.php
 *        Called automatically by scraper.php and trends_scraper.php
 */
declare(strict_types=1);

$config = require __DIR__ . '/../config.php';
const ROOT_DIR = __DIR__ . '/..';

function getSiteUrl(): string { return $GLOBALS['config']['site_url']; }
function getPerPage(): int { return $GLOBALS['config']['articles_per_page']; }

function escBuild(string $s): string { return htmlspecialchars($s, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }

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
    // additional sources - scan all country subdirectories
    $trendsBase = __DIR__ . '/data/trends';
    if (is_dir($trendsBase)) {
        $countries = array_filter(scandir($trendsBase), fn($d) => $d !== '.' && $d !== '..' && is_dir($trendsBase . '/' . $d));
        foreach ($countries as $country) {
            $countryIdx = $trendsBase . '/' . $country . '/index.json';
            if (!file_exists($countryIdx)) continue;
            $j = json_decode(file_get_contents($countryIdx), true);
            foreach ($j['days'] ?? [] as $d) {
                $p = $trendsBase . '/' . $country . '/' . ($d['file'] ?? ($d['date'] . '.json'));
                if (!file_exists($p)) continue;
                $day = json_decode(file_get_contents($p), true);
                foreach ($day['articles'] ?? [] as $a) $articles[] = $a;
            }
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
    $title = escBuild($a['title'] ?? '');
    $excerpt = escBuild($a['excerpt'] ?? '');
    $cat = escBuild($a['category'] ?? 'general');
    $author = escBuild(normalizeAuthor($a['author'] ?? ''));
    $img = escBuild($a['image_url'] ?? '');
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
       <div class="card-meta"><span>{$date}</span></div>
     </div>
   </article>
HTML;
}

function buildSitemap(array $articles): string {
    $base = rtrim(getSiteUrl(), '/');
    $now = date('c');
    $urls = [];
    $urls[] = "  <url><loc>{$base}/</loc><lastmod>{$now}</lastmod><changefreq>hourly</changefreq><priority>1.0</priority></url>";
    $urls[] = "  <url><loc>{$base}/archive/</loc><lastmod>{$now}</lastmod><changefreq>daily</changefreq><priority>0.9</priority></url>";
    foreach ($articles as $a) {
        $slug = $a['slug'] ?? '';
        if (!$slug) continue;
        $loc = $base . '/articles/' . $slug . '/';
        $lastmod = $a['published_at'] ?? $now;
        try { $lastmod = (new DateTime($lastmod))->format('c'); } catch (Throwable $e) {}
        $urls[] = "  <url><loc>" . escBuild($loc) . "</loc><lastmod>{$lastmod}</lastmod><changefreq>weekly</changefreq><priority>0.8</priority></url>";
    }
    $byDay = groupArticlesByDay($articles);
    foreach ($byDay as $date => $dayArticles) {
        $loc = $base . '/archive/' . $date . '/';
        $lastmod = max(array_map(fn($a) => $a['published_at'] ?? '', $dayArticles));
        try { $lastmod = (new DateTime($lastmod))->format('c'); } catch (Throwable $e) { $lastmod = $now; }
        $urls[] = "  <url><loc>" . escBuild($loc) . "</loc><lastmod>{$lastmod}</lastmod><changefreq>weekly</changefreq><priority>0.7</priority></url>";
    }
    $body = implode("\n", $urls);
    return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n{$body}\n</urlset>\n";
}

function groupArticlesByDay(array $articles): array {
    $byDay = [];
    foreach ($articles as $a) {
        $pub = $a['published_at'] ?? '';
        $date = preg_match('/^(\d{4}-\d{2}-\d{2})/', $pub, $m) ? $m[1] : date('Y-m-d');
        $byDay[$date][] = $a;
    }
    krsort($byDay);
    return $byDay;
}

function buildDailyArchiveHtml(string $date, array $dayArticles, array $allDates): string {
    $siteUrl = rtrim(getSiteUrl(), '/');
    $totalArticles = count($dayArticles);
    $humanDate = date('d M Y', strtotime($date));
    $generated = date('c');

    $cards = implode("\n", array_map('cardHtml', $dayArticles));

    $dateIndex = array_search($date, $allDates);
    $prevDate = $dateIndex > 0 ? $allDates[$dateIndex - 1] : null;
    $nextDate = $dateIndex < count($allDates) - 1 ? $allDates[$dateIndex + 1] : null;

    $nav = '<div class="pagination">';
    if ($prevDate) {
        $nav .= "<a href=\"../{$prevDate}/\" class=\"pag-btn\">← {$prevDate}</a>";
    }
    $nav .= "<a class=\"pag-btn active\">{$date}</a>";
    if ($nextDate) {
        $nav .= "<a href=\"../{$nextDate}/\" class=\"pag-btn\">{$nextDate} →</a>";
    }
    $nav .= '</div>';

    return <<<HTML
<!DOCTYPE html>
<html lang="el">
<head>
  <meta charset="UTF-8" />
  <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
  <meta http-equiv="Pragma" content="no-cache" />
  <meta http-equiv="Expires" content="0" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Archive: {$humanDate} — {$totalArticles} articles | trends-online.com</title>
  <meta name="description" content="Trending news articles from {$humanDate}. {$totalArticles} articles indexed." />
  <link rel="canonical" href="{$siteUrl}/archive/{$date}/" />
  <meta property="og:type" content="website" />
  <meta property="og:title" content="Archive: {$humanDate} | trends-online.com" />
  <meta property="og:description" content="{$totalArticles} articles from {$humanDate}" />
  <link rel="stylesheet" href="../../css/style.css" />
</head>
<body>
  <header class="site-header">
    <div class="wrap">
      <a class="logo" href="../../">trends-online<span>.com</span></a>
      <nav class="nav">
        <a href="../../" class="filter-btn">← Home</a>
        <a href="../" class="filter-btn active">Archive</a>
        <span class="badge">{$date}</span>
      </nav>
    </div>
  </header>
  <main class="wrap">
    <h1 style="margin:18px 0 8px">{$humanDate}</h1>
    <p class="meta">{$totalArticles} articles published on this day</p>
    {$nav}
    <div class="grid">
{$cards}
    </div>
    {$nav}
  </main>
  <footer class="site-footer">
    <div class="wrap">
      <p><a href="../../">trends-online.com</a> · <a href="../">All archives</a> · <span>{$generated}</span></p>
    </div>
  </footer>
</body>
</html>
HTML;
}

function buildArchiveIndexHtml(array $allDates, array $byDay): string {
    $siteUrl = rtrim(getSiteUrl(), '/');
    $totalDays = count($allDates);
    $totalArticles = array_sum(array_map('count', $byDay));
    $generated = date('c');

    $list = '';
    foreach ($allDates as $date) {
        $count = count($byDay[$date]);
        $humanDate = date('d M Y', strtotime($date));
        $list .= "      <a href=\"{$date}/\" class=\"archive-day\"><span class=\"archive-date\">{$humanDate}</span><span class=\"archive-count\">{$count} articles</span></a>\n";
    }

    return <<<HTML
<!DOCTYPE html>
<html lang="el">
<head>
  <meta charset="UTF-8" />
  <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
  <meta http-equiv="Pragma" content="no-cache" />
  <meta http-equiv="Expires" content="0" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Archive — {$totalDays} days, {$totalArticles} articles | trends-online.com</title>
  <meta name="description" content="Browse all trending news by date. {$totalDays} days archived with {$totalArticles} total articles." />
  <link rel="canonical" href="{$siteUrl}/archive/" />
  <meta property="og:type" content="website" />
  <meta property="og:title" content="Archive | trends-online.com" />
  <meta property="og:description" content="{$totalDays} days, {$totalArticles} articles" />
  <link rel="stylesheet" href="../css/style.css" />
  <style>
    .archive-list { display:flex; flex-direction:column; gap:8px; margin:18px 0; }
    .archive-day { display:flex; justify-content:space-between; align-items:center; padding:14px 18px; background:var(--card); border:1px solid var(--border); border-radius:var(--radius); color:var(--text); transition:transform .08s; }
    .archive-day:hover { transform:translateY(-2px); text-decoration:none; }
    .archive-date { font-weight:600; font-size:1.05rem; }
    .archive-count { color:var(--muted); font-size:.9rem; }
  </style>
</head>
<body>
  <header class="site-header">
    <div class="wrap">
      <a class="logo" href="../">trends-online<span>.com</span></a>
      <nav class="nav">
        <a href="../" class="filter-btn">← Home</a>
        <a href="./" class="filter-btn active">Archive</a>
      </nav>
    </div>
  </header>
  <main class="wrap">
    <h1 style="margin:18px 0 8px">Archive</h1>
    <p class="meta">{$totalDays} days · {$totalArticles} total articles</p>
    <div class="archive-list">
{$list}
    </div>
  </main>
  <footer class="site-footer">
    <div class="wrap">
      <p><a href="../">trends-online.com</a> · <span>{$generated}</span></p>
    </div>
  </footer>
</body>
</html>
HTML;
}

function buildArchives(array $articles): void {
    $archiveDir = ROOT_DIR . '/archive';
    @mkdir($archiveDir, 0777, true);

    $byDay = groupArticlesByDay($articles);
    $allDates = array_keys($byDay);

    file_put_contents($archiveDir . '/index.html', buildArchiveIndexHtml($allDates, $byDay));

    foreach ($byDay as $date => $dayArticles) {
        $dayDir = $archiveDir . '/' . $date;
        @mkdir($dayDir, 0777, true);
        file_put_contents($dayDir . '/index.html', buildDailyArchiveHtml($date, $dayArticles, $allDates));
    }

    if (php_sapi_name() === 'cli') {
        echo "[build_index] archive: " . count($allDates) . " daily pages\n";
    }
}

function groupArticlesByCountry(array $articles): array {
    $byCountry = [];
    foreach ($articles as $a) {
        $country = strtoupper($a['country'] ?? '');
        if (!$country) continue;
        $byCountry[$country][] = $a;
    }
    ksort($byCountry);
    return $byCountry;
}

const COUNTRY_NAMES = [
    'US'=>'United States','GB'=>'United Kingdom','DE'=>'Germany','FR'=>'France','JP'=>'Japan',
    'AU'=>'Australia','CA'=>'Canada','IT'=>'Italy','ES'=>'Spain','BR'=>'Brazil',
    'IN'=>'India','KR'=>'South Korea','MX'=>'Mexico','NL'=>'Netherlands','SE'=>'Sweden',
    'PL'=>'Poland','PT'=>'Portugal','CH'=>'Switzerland','AT'=>'Austria','BE'=>'Belgium',
    'DK'=>'Denmark','NO'=>'Norway','FI'=>'Finland','IE'=>'Ireland','NZ'=>'New Zealand',
    'SG'=>'Singapore','HK'=>'Hong Kong','TW'=>'Taiwan','TH'=>'Thailand','VN'=>'Vietnam',
    'ID'=>'Indonesia','MY'=>'Malaysia','PH'=>'Philippines','CZ'=>'Czech Republic','RO'=>'Romania',
    'HU'=>'Hungary','BG'=>'Bulgaria','HR'=>'Croatia','SK'=>'Slovakia','SI'=>'Slovenia',
    'LT'=>'Lithuania','LV'=>'Latvia','EE'=>'Estonia','GR'=>'Greece',
];

function buildCountryIndexHtml(string $country, array $countryArticles, array $allCountries): string {
    $siteUrl = rtrim(getSiteUrl(), '/');
    $totalArticles = count($countryArticles);
    $countryLower = strtolower($country);
    $countryName = COUNTRY_NAMES[$country] ?? $country;
    $generated = date('c');
    $humanGen = date('d.m.Y H:i');

    $cards = implode("\n", array_map('cardHtml', $countryArticles));

    $countryLinks = '';
    foreach ($allCountries as $c) {
        $cLower = strtolower($c);
        $cName = COUNTRY_NAMES[$c] ?? $c;
        $active = $c === $country ? ' active' : '';
        $countryLinks .= "        <a href=\"index-{$cLower}.html\" class=\"filter-btn{$active}\">{$cName}</a>\n";
    }

    return <<<HTML
<!DOCTYPE html>
<html lang="el">
<head>
  <meta charset="UTF-8" />
  <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
  <meta http-equiv="Pragma" content="no-cache" />
  <meta http-equiv="Expires" content="0" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>{$countryName} — {$totalArticles} articles | trends-online.com</title>
  <meta name="description" content="Trending news from {$countryName}. {$totalArticles} articles indexed." />
  <link rel="canonical" href="{$siteUrl}/index-{$countryLower}.html" />
  <meta property="og:type" content="website" />
  <meta property="og:title" content="{$countryName} — trends-online.com" />
  <meta property="og:description" content="{$totalArticles} articles from {$countryName}" />
  <link rel="stylesheet" href="css/style.css" />
</head>
<body>
  <header class="site-header">
    <div class="wrap">
      <a class="logo" href="./">trends-online<span>.com</span></a>
      <nav class="nav">
        <a href="./" class="filter-btn">All</a>
        <a href="archive/" class="filter-btn">Archive</a>
{$countryLinks}      </nav>
    </div>
  </header>
  <main class="wrap">
    <h1 style="margin:18px 0 8px">{$countryName}</h1>
    <p class="meta">{$totalArticles} articles · Updated: {$humanGen}</p>
    <div class="grid">
{$cards}
    </div>
  </main>
  <footer class="site-footer">
    <div class="wrap">
      <p><a href="./">trends-online.com</a> · <a href="archive/">Archive</a> · <span>{$generated}</span></p>
    </div>
  </footer>
</body>
</html>
HTML;
}

function buildCountryPages(array $articles): int {
    $byCountry = groupArticlesByCountry($articles);
    if (empty($byCountry)) return 0;

    $allCountries = array_keys($byCountry);

    foreach ($byCountry as $country => $countryArticles) {
        $countryLower = strtolower($country);
        $html = buildCountryIndexHtml($country, $countryArticles, $allCountries);
        file_put_contents(ROOT_DIR . "/index-{$countryLower}.html", $html);
    }

    if (php_sapi_name() === 'cli') {
        echo "[build_index] country pages: " . count($byCountry) . " countries\n";
    }

    return count($byCountry);
}

function buildIndexHtml(array $articles, int $page = 1): string {
    $totalArticles = count($articles);
    $perPage = getPerPage();
    $totalPages = max(1, (int)ceil($totalArticles / $perPage));
    $page = max(1, min($page, $totalPages));
    $offset = ($page - 1) * $perPage;
    $pageArticles = array_slice($articles, $offset, $perPage);
    $count = count($pageArticles);
    $generated = date('c');
    $humanGen = date('d.m.Y H:i');

    $cards = implode("\n", array_map('cardHtml', $pageArticles));
    $siteUrl = rtrim(getSiteUrl(),'/');

    $pageLink = $page === 1 ? './' : "./index-{$page}.html";

    $byCountry = groupArticlesByCountry($articles);
    $countryNav = '';
    foreach ($byCountry as $country => $list) {
        $countryLower = strtolower($country);
        $countryNav .= "<a href=\"index-{$countryLower}.html\" class=\"filter-btn\">" . (COUNTRY_NAMES[$country] ?? $country) . " (" . count($list) . ")</a>\n";
    }

    $pagination = '';
    if ($totalPages > 1) {
        $pagination .= '<div class="pagination">';
        if ($page > 1) {
            $prevPage = $page - 1;
            $prevLink = $prevPage === 1 ? './' : "./index-{$prevPage}.html";
            $pagination .= "<a href=\"{$prevLink}\" class=\"pag-btn\">← Newer</a>";
        }
        for ($i = 1; $i <= $totalPages; $i++) {
            $iLink = $i === 1 ? './' : "./index-{$i}.html";
            $active = $i === $page ? ' class="pag-btn active"' : ' class="pag-btn"';
            $pagination .= "<a href=\"{$iLink}\"{$active}>{$i}</a>";
        }
        if ($page < $totalPages) {
            $nextPage = $page + 1;
            $nextLink = "./index-{$nextPage}.html";
            $pagination .= "<a href=\"{$nextLink}\" class=\"pag-btn\">Older →</a>";
        }
        $pagination .= '</div>';
    }

    return <<<HTML
<!DOCTYPE html>
<html lang="el">
<head>
  <meta charset="UTF-8" />
  <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
  <meta http-equiv="Pragma" content="no-cache" />
  <meta http-equiv="Expires" content="0" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>trends-online.com — News</title>
  <meta name="description" content="All articles — static HTML with full content for indexing. {$totalArticles} articles." />
  <link rel="canonical" href="{$siteUrl}/" />
  <meta property="og:type" content="website" />
  <meta property="og:title" content="trends-online.com — News" />
  <meta property="og:description" content="{$totalArticles} articles — static HTML for fast indexing" />
  <link rel="stylesheet" href="css/style.css" />
</head>
<body>
  <header class="site-header">
    <div class="wrap">
      <a class="logo" href="./">trends-online<span>.com</span></a>
      <nav class="nav">
        <a href="./" class="filter-btn active">All ({$totalArticles})</a>
        <a href="archive/" class="filter-btn">Archive</a>
{$countryNav}      </nav>
    </div>
  </header>

  <main class="wrap">
    <div id="meta" class="meta">{$totalArticles} articles · static HTML · <span id="generated">Updated: {$humanGen}</span></div>
    <div id="articles" class="grid">
{$cards}
    </div>
    {$pagination}
  </main>

  <footer class="site-footer">
    <div class="wrap">
      <p>Built with PHP scraper → JSON · split by day · SEO static HTML per article · {$totalArticles} pages</p>
      <p><a href="sitemap.xml">Sitemap</a> · <a href="robots.txt">Robots</a> · <span id="generated2">{$generated}</span></p>
    </div>
  </footer>
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
    $total = count($articles);
    $perPage = getPerPage();
    $totalPages = max(1, (int)ceil($total / $perPage));

    // Write index.html (page 1)
    file_put_contents(ROOT_DIR . '/index.html', buildIndexHtml($articles, 1));

    // Write paginated pages
    for ($p = 2; $p <= $totalPages; $p++) {
        $filename = ROOT_DIR . "/index-{$p}.html";
        file_put_contents($filename, buildIndexHtml($articles, $p));
    }

    // Remove old paginated pages that no longer exist
    for ($p = $totalPages + 1; $p <= 100; $p++) {
        $filename = ROOT_DIR . "/index-{$p}.html";
        if (is_file($filename) && preg_match('/^index-\d+\.html$/', basename($filename))) {
            unlink($filename);
        } elseif (!is_file($filename)) {
            break;
        }
    }

    $sitemap = buildSitemap($articles);
    file_put_contents(ROOT_DIR . '/sitemap.xml', $sitemap);
    $robots = "User-agent: *\nAllow: /\nSitemap: " . rtrim(getSiteUrl(),'/') . "/sitemap.xml\n";
    if (!file_exists(ROOT_DIR . '/robots.txt') || strpos(file_get_contents(ROOT_DIR . '/robots.txt'), 'Sitemap:') === false) {
        file_put_contents(ROOT_DIR . '/robots.txt', $robots);
    }
    ensureNoCacheHtaccess();
    buildArchives($articles);
    buildCountryPages($articles);
    return $total;
}

if (count(get_included_files()) === 1) {
    $cnt = buildIndex();
    if (php_sapi_name() === 'cli') echo "[build_index] wrote {$cnt} articles → index.html + sitemap.xml\n";
    else echo json_encode(['ok'=>true,'count'=>$cnt,'file'=>'index.html']);
}
