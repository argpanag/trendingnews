<?php
/**
 * Trending News Scraper → JSON + SEO
 * Fetches Google Trends RSS by country, scrapes articles, cleans content,
 * saves to api/data/trends/{country}/YYYY-MM-DD.json + articles/<slug>/
 *
 * Usage: php api/trends_scraper.php --country=US  (default: US)
 *        php api/trends_scraper.php --country=GR  (Greece)
 *        php api/trends_scraper.php --country=GB  (UK)
 *        php api/trends_scraper.php --force        (reload even if exists)
 *        GET /api/trends_scraper.php?country=US&force=1  (HTTP)
 */
declare(strict_types=1);

const DEFAULT_COUNTRY = 'US';
const TRENDS_DATA_DIR = __DIR__ . '/data/trends';
const TRENDS_HISTORY_DIR = __DIR__ . '/history_trends';
const TRENDS_SEO_DIR = __DIR__ . '/../articles';
const SITE_URL = 'https://trends-online.com';
const MAX_TRENDS = 15;
const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';

function getCountry(): string {
    if (php_sapi_name() === 'cli') {
        global $argv;
        foreach ($argv ?? [] as $arg) {
            if (str_starts_with($arg, '--country=')) {
                return strtoupper(substr($arg, 10));
            }
        }
    } else {
        if (isset($_GET['country']) && preg_match('/^[A-Z]{2}$/i', $_GET['country'])) {
            return strtoupper($_GET['country']);
        }
    }
    return DEFAULT_COUNTRY;
}

function getTrendsRssUrl(string $country): string {
    return "https://trends.google.com/trending/rss?geo=" . strtoupper($country);
}

function isForce(): bool {
    if (php_sapi_name() === 'cli') {
        global $argv;
        return in_array('--force', $argv ?? []) || in_array('--reload', $argv ?? []) || in_array('-f', $argv ?? []);
    }
    return isset($_GET['force']) || isset($_GET['reload']) || isset($_GET['refresh']);
}

function fetchUrl(string $url): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_USERAGENT => USER_AGENT,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9'
        ]
    ]);
    $html = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($html === false || $err) throw new RuntimeException("fetch $url: $err");
    if ($code >= 400) throw new RuntimeException("HTTP $code $url");
    return $html;
}

function slugify(string $title, string $url): string {
    $s = mb_strtolower($title, 'UTF-8');
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim($s, '-');
    $s = substr($s, 0, 60);
    if (strlen($s) < 3) {
        $p = parse_url($url, PHP_URL_PATH);
        $b = basename(rtrim($p ?? '', '/'));
        $b = preg_replace('/[^a-z0-9]+/', '-', strtolower($b));
        $b = trim(substr($b, 0, 40), '-');
        $s = $b ?: substr(md5($url), 0, 12);
    }
    return $s;
}

function esc(string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function isLatinText(string $text): bool {
    $text = trim($text);
    if (strlen($text) === 0) return false;
    $cleaned = preg_replace('/[\s\d\p{P}\p{S}\p{Sc}]+/u', '', $text);
    if (strlen($cleaned) === 0) return true;
    $latin = preg_match_all('/[\p{Latin}\p{Greek}\p{Common}]/u', $cleaned);
    $total = preg_match_all('/./u', $cleaned);
    if ($total === 0) return true;
    return ($latin / $total) > 0.6;
}

function cleanContent(string $html): string {
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    @$dom->loadHTML(mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8'));
    $xp = new DOMXPath($dom);

    $cands = $xp->query('//article | //div[contains(@class,"entry-content")] | //div[contains(@class,"post-content")] | //div[contains(@class,"article-body")] | //div[contains(@class,"story-body")] | //div[contains(@class,"article-content")] | //main');
    $best = null;
    $maxLen = 0;
    foreach ($cands as $c) {
        $len = strlen(trim($c->textContent));
        if ($c->nodeName === 'article') $len += 500;
        if ($len > $maxLen) {
            $maxLen = $len;
            $best = $c;
        }
    }

    $inner = '';
    if ($best) {
        foreach ($best->childNodes as $ch) {
            $inner .= $dom->saveHTML($ch);
        }
    } else {
        $ps = $xp->query('//p');
        foreach ($ps as $p) {
            $inner .= $dom->saveHTML($p);
        }
    }

    $dom2 = new DOMDocument();
    $wrapped = '<div id="root">' . $inner . '</div>';
    @$dom2->loadHTML(mb_encode_numericentity($wrapped, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $xp2 = new DOMXPath($dom2);
    $root = $dom2->getElementById('root');

    $removeSelectors = [
        '//script', '//style', '//noscript',
        '//iframe[contains(@src,"doubleclick") or contains(@src,"googlesyndication") or contains(@src,"googletag")]',
        '//aside', '//nav', '//form', '//button',
        '//*[contains(@class,"share")]', '//*[contains(@class,"social-share")]', '//*[contains(@class,"addthis")]',
        '//*[contains(@class,"social")]', '//*[contains(@class,"breadcrumb")]', '//*[contains(@class,"byline")]',
        '//*[contains(@class,"author-info")]', '//*[contains(@class,"posted-on")]',
        '//*[contains(@class,"comment")]', '//*[contains(@class,"openweb")]',
        '//*[contains(@class,"recommended")]', '//*[contains(@class,"related")]', '//*[contains(@class,"zergnet")]',
        '//*[contains(@class,"outbrain")]', '//*[contains(@class,"taboola")]',
        '//*[contains(@class,"google-ad")]', '//*[contains(@class,"ad-")]', '//*[contains(@id,"div-ad")]',
        '//*[contains(@class,"sidebar")]', '//*[contains(@class,"newsletter")]', '//*[contains(@class,"subscribe")]',
    ];

    foreach ($removeSelectors as $q) {
        $nodes = $xp2->query($q);
        if (!$nodes) continue;
        for ($i = $nodes->length - 1; $i >= 0; $i--) {
            $n = $nodes->item($i);
            if ($n && $n->parentNode) {
                $n->parentNode->removeChild($n);
            }
        }
    }

    foreach ($xp2->query('//div[not(*) and normalize-space(text())=""] | //p[not(*) and normalize-space(text())=""]') as $n) {
        if ($n->parentNode) $n->parentNode->removeChild($n);
    }

    $cleanRoot = $dom2->createElement('div');
    $cleanRoot->setAttribute('id', 'clean');
    $allowed = $xp2->query('.//p[normalize-space()!=""] | .//h2 | .//h3 | .//h4 | .//ul | .//ol | .//blockquote | .//figure', $root);

    foreach ($allowed as $node) {
        $clone = $node->cloneNode(true);
        foreach ((new DOMXPath($dom2))->query('.//*', $clone) as $el) {
            $toRemove = [];
            foreach ($el->attributes ?? [] as $attr) {
                if (!in_array($attr->nodeName, ['href', 'src', 'alt', 'title'])) {
                    $toRemove[] = $attr->nodeName;
                }
            }
            foreach ($toRemove as $r) $el->removeAttribute($r);
            if ($el->nodeName === 'a' && $el->hasAttribute('href')) {
                $el->setAttribute('target', '_blank');
                $el->setAttribute('rel', 'noopener noreferrer');
            }
        }
        $cleanRoot->appendChild($clone);
    }

    if ($cleanRoot->childNodes->length === 0) {
        $out = '';
        foreach ($root->childNodes as $ch) $out .= $dom2->saveHTML($ch);
        $out = trim($out);
        if (!str_contains($out, '<p') && strlen(strip_tags($out)) > 50) {
            $out = '<p>' . esc(strip_tags($out)) . '</p>';
        }
        return $out;
    }

    $out = '';
    foreach ($cleanRoot->childNodes as $ch) $out .= $dom2->saveHTML($ch);
    $out = trim($out);
    $out = preg_replace('/<!--.*?-->/s', '', $out);
    if (!str_contains($out, '<p') && strlen(strip_tags($out)) > 50) {
        $out = '<p>' . esc(strip_tags($out)) . '</p>';
    }
    return $out;
}

function extractTrends(string $rssUrl): array {
    $xml = fetchUrl($rssUrl);
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadXML($xml);
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('ht', 'https://trends.google.com/trending/rss');

    $items = $xp->query('//item');
    $trends = [];

    foreach ($items as $it) {
        $title = $xp->evaluate('string(title)', $it);
        if (!isLatinText($title)) continue;
        $traffic = $xp->evaluate('string(ht:approx_traffic)', $it);
        $pub = $xp->evaluate('string(pubDate)', $it);
        $pic = $xp->evaluate('string(ht:picture)', $it);
        $news = [];

        foreach ($xp->query('ht:news_item', $it) as $ni) {
            $news[] = [
                'title' => $xp->evaluate('string(ht:news_item_title)', $ni),
                'url' => $xp->evaluate('string(ht:news_item_url)', $ni),
                'pic' => $xp->evaluate('string(ht:news_item_picture)', $ni),
                'source' => $xp->evaluate('string(ht:news_item_source)', $ni),
            ];
        }

        $trends[] = compact('title', 'traffic', 'pub', 'pic', 'news');
        if (count($trends) >= MAX_TRENDS) break;
    }

    return $trends;
}

function extractArticle(string $html, string $url): ?array {
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    @$dom->loadHTML(mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8'));
    $xp = new DOMXPath($dom);

    $t = $xp->query('//meta[@property="og:title"]/@content')->item(0) ?? $xp->query('//title')->item(0);
    $title = $t ? trim($t->nodeValue ?? $t->textContent) : '';
    if (!$title) return null;
    if (!isLatinText($title)) return null;

    $ogImg = $xp->query('//meta[@property="og:image"]/@content')->item(0);
    $img = $ogImg ? trim($ogImg->nodeValue) : '';
    if (!$img) {
        $n = $xp->query('//article//img | //figure//img')->item(0);
        if ($n) $img = $n->getAttribute('src') ?: $n->getAttribute('data-src');
    }

    $pub = $xp->query('//meta[@property="article:published_time"]/@content')->item(0)
        ?? $xp->query('//meta[@name="publish-date"]/@content')->item(0);
    $published = $pub ? trim($pub->nodeValue) : date('c');

    $descN = $xp->query('//meta[@name="description"]/@content')->item(0)
        ?? $xp->query('//meta[@property="og:description"]/@content')->item(0);
    $excerpt = $descN ? trim($descN->nodeValue) : '';

    $content = cleanContent($html);
    if (mb_strlen(strip_tags($content), 'UTF-8') < 80) return null;

    if (!$excerpt) {
        $excerpt = mb_substr(trim(strip_tags($content)), 0, 180, 'UTF-8') . '…';
    } else {
        $excerpt = mb_substr($excerpt, 0, 220, 'UTF-8');
    }

    $slug = slugify($title, $url);

    return [
        'title' => $title,
        'slug' => $slug,
        'excerpt' => $excerpt,
        'content' => $content,
        'category' => 'general',
        'image_url' => $img,
        'source_url' => $url,
        'author' => 'TheTools',
        'published_at' => $published,
        'trend' => true,
    ];
}

function buildSeoHtml(array $a, string $country): string {
    $title = esc($a['title'] ?? 'Untitled');
    $excerpt = esc($a['excerpt'] ?? '');
    $slug = $a['slug'] ?? 'unknown';
    $fullUrl = rtrim(SITE_URL, '/') . '/articles/' . $slug . '/';
    $img = esc($a['image_url'] ?? '');
    $author = esc($a['author'] ?? 'TheTools');
    $published = $a['published_at'] ?? date('c');
    $iso = $published;
    try {
        $iso = (new DateTime($published))->format('c');
    } catch (Throwable $e) {
        $iso = date('c');
    }
    $humanDate = esc(date('d.m.Y H:i', strtotime($published) ?: time()));
    $content = $a['content'] ?? '';
    $category = esc($a['category'] ?? 'general');
    $sourceUrl = esc($a['source_url'] ?? '#');

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
            'logo' => ['@type' => 'ImageObject', 'url' => rtrim(SITE_URL, '/') . '/css/style.css']
        ],
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $fullUrl],
    ];
    $jsonLdStr = json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

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
        <span class="badge">{$country}</span>
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
      <p style="color:#6b7280;font-size:.85rem">Source: <a href="{$sourceUrl}" target="_blank" rel="noopener">original</a> · archived on trends-online.com · Trends: {$country}</p>
    </article>
  </main>
  <footer class="site-footer">
    <div class="wrap">
      <p>Built with PHP scraper → JSON · SEO static HTML · Trends: {$country}</p>
      <p><a href="../../">trends-online.com</a> · <a href="../../api/data/index.json">API index</a></p>
    </div>
  </footer>
</body>
</html>
HTML;
}

function main(): void {
    $isCli = php_sapi_name() === 'cli';
    if (!$isCli) header('Content-Type: application/json; charset=utf-8');

    $country = getCountry();
    $rssUrl = getTrendsRssUrl($country);

    try {
        echo $isCli ? "[trends:{$country}] fetching {$rssUrl} ...\n" : '';
        $trends = extractTrends($rssUrl);
        echo $isCli ? "[trends:{$country}] found " . count($trends) . " trends\n" : '';

        $toScrape = [];
        foreach ($trends as $t) {
            foreach ($t['news'] as $n) {
                if (filter_var($n['url'], FILTER_VALIDATE_URL)) {
                    $toScrape[] = $n;
                }
            }
        }
        $toScrape = array_slice($toScrape, 0, MAX_TRENDS);
        echo $isCli ? "[trends:{$country}] scraping " . count($toScrape) . " news urls\n" : '';

        $dataDir = TRENDS_DATA_DIR . '/' . $country;
        $historyDir = TRENDS_HISTORY_DIR . '/' . $country;
        @mkdir($dataDir, 0777, true);
        @mkdir($historyDir, 0777, true);

        $existing = [];
        foreach (glob($dataDir . '/20*.json') as $f) {
            if (basename($f) === 'index.json') continue;
            $j = json_decode(file_get_contents($f), true);
            foreach (($j['articles'] ?? []) as $a) {
                if (isset($a['source_url'])) $existing[$a['source_url']] = $a;
            }
        }
        foreach (glob($historyDir . '/*.json') as $f) {
            $j = json_decode(file_get_contents($f), true);
            if (isset($j['source_url']) && !isset($existing[$j['source_url']])) {
                $existing[$j['source_url']] = $j;
            }
        }

        $force = isForce();
        if ($force && $isCli) echo "[trends:{$country}] force reload enabled\n";

        $new = 0;
        $skipped = 0;
        $updated = 0;

        foreach ($toScrape as $item) {
            $url = $item['url'];
            if (isset($existing[$url]) && !$force) {
                $skipped++;
                continue;
            }
            $isUpdate = isset($existing[$url]) && $force;
            try {
                $html = fetchUrl($url);
                $data = extractArticle($html, $url);
                if (!$data) {
                    $skipped++;
                    continue;
                }
                $data['trend_title'] = $item['title'] ?? '';
                $data['country'] = $country;
                $existing[$url] = $data;
                if ($isUpdate) $updated++;
                else $new++;
                if ($isCli) {
                    $mark = $isUpdate ? '~' : '+';
                    echo "  {$mark} {$data['slug']} | " . substr($data['title'], 0, 50) . ($isUpdate ? ' (reloaded)' : '') . "\n";
                }
                usleep(400000);
            } catch (Throwable $e) {
                if ($isCli) echo "  ! {$url}: " . $e->getMessage() . "\n";
                $skipped++;
            }
        }

        uasort($existing, fn($a, $b) => strcmp($b['published_at'] ?? '', $a['published_at'] ?? ''));
        $articles = array_values($existing);

        $byDay = [];
        foreach ($articles as $a) {
            $d = preg_match('/^(\d{4}-\d{2}-\d{2})/', $a['published_at'] ?? '', $m) ? $m[1] : date('Y-m-d');
            $byDay[$d][] = $a;
        }
        krsort($byDay);

        foreach ($byDay as $date => $list) {
            $p = [
                'generated_at' => date('c'),
                'date' => $date,
                'country' => $country,
                'count' => count($list),
                'articles' => array_values($list)
            ];
            file_put_contents($dataDir . "/{$date}.json", json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        }

        $idx = [
            'generated_at' => date('c'),
            'country' => $country,
            'total' => count($articles),
            'days' => array_map(fn($d) => [
                'date' => $d,
                'count' => count($byDay[$d]),
                'file' => "{$d}.json"
            ], array_keys($byDay))
        ];
        file_put_contents($dataDir . '/index.json', json_encode($idx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        @mkdir(TRENDS_SEO_DIR, 0777, true);
        foreach ($articles as $a) {
            $slug = $a['slug'];
            $dir = TRENDS_SEO_DIR . "/{$slug}";
            @mkdir($dir, 0777, true);
            $html = buildSeoHtml($a, $country);
            file_put_contents("{$dir}/index.html", $html);

            $path = $historyDir . "/{$slug}.json";
            $shouldWrite = !file_exists($path) || $force;
            if (!$shouldWrite && file_exists($path)) {
                $old = json_decode(file_get_contents($path), true);
                if (($old['content'] ?? '') !== ($a['content'] ?? '')) {
                    $shouldWrite = true;
                }
            }
            if ($shouldWrite) {
                file_put_contents($path, json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            }
        }

        if (file_exists(__DIR__ . '/build_index.php')) {
            require_once __DIR__ . '/build_index.php';
            if (function_exists('buildIndex')) buildIndex();
        }

        $result = [
            'ok' => true,
            'country' => $country,
            'trends' => count($trends),
            'scraped' => count($toScrape),
            'new' => $new,
            'updated' => $updated,
            'skipped' => $skipped,
            'total' => count($articles)
        ];

        echo $isCli ? json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n" : json_encode($result, JSON_UNESCAPED_UNICODE);

    } catch (Throwable $e) {
        $err = ['ok' => false, 'error' => $e->getMessage()];
        if ($isCli) {
            fwrite(STDERR, json_encode($err) . "\n");
        } else {
            http_response_code(500);
            echo json_encode($err);
        }
        exit(1);
    }
}

main();
